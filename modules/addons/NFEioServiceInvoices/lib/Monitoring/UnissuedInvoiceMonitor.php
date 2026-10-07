<?php

namespace NFEioServiceInvoices\Monitoring;

use WHMCS\Database\Capsule;

class UnissuedInvoiceMonitor
{
    const ALERTS_TABLE = 'mod_nfeio_si_monitor_alerts';
    const INVOICES_TABLE = 'mod_nfeio_si_serviceinvoices';
    const CLIENT_CONFIG_TABLE = 'mod_nfeio_si_custom_configs';
    const MONITOR_ENABLED_SETTING = 'telegram_monitor_enabled';
    const MONITOR_STARTED_AT_SETTING = 'telegram_monitor_started_at';
    const TELEGRAM_TOKEN_INPUT_SETTING = 'telegram_bot_token';
    const TELEGRAM_TOKEN_ENCRYPTED_SETTING = 'telegram_bot_token_encrypted';

    /**
     * @var ModuleSettings
     */
    private $settings;

    /**
     * @var TelegramNotifier
     */
    private $notifier;

    /**
     * @var \NFEioServiceInvoices\NFEio\Nfe
     */
    private $nfe;

    public function __construct()
    {
        $configuration = new \NFEioServiceInvoices\Configuration();
        $this->settings = new ModuleSettings($configuration->getStorageKey());
        $this->nfe = new \NFEioServiceInvoices\NFEio\Nfe();
    }

    public function runDailyScan()
    {
        if (!$this->settings->isEnabled(self::MONITOR_ENABLED_SETTING)) {
            return ['status' => 'disabled', 'candidates' => 0, 'alerts_created' => 0];
        }

        if (!$this->notifier()->isConfigured()) {
            return ['status' => 'invalid_configuration', 'candidates' => 0, 'alerts_created' => 0];
        }

        \NFEioServiceInvoices\Migrations\Migrations::createInvoiceMonitorAlertsTable();

        return $this->withLock('daily_scan', function () {
            $startedAt = $this->monitorStartedAt();
            $cutoff = date('Y-m-d H:i:s', time() - 86400);
            $moduleRule = $this->settings->get('issue_note_default_cond', '');
            $issueAfterDays = (int) $this->settings->get('issue_note_after', 0);

            $lastInvoiceId = 0;
            $scannedCount = 0;
            $candidateCount = 0;
            $createdCount = 0;

            do {
                $invoices = $this->candidateInvoicesPage($startedAt, $cutoff, $lastInvoiceId);
                if ($invoices->isEmpty()) {
                    break;
                }

                $scannedCount += $invoices->count();
                $lastInvoice = $invoices->last();
                $lastInvoiceId = (int) $lastInvoice->invoice_id;
                $clientIds = $invoices->pluck('client_id')->unique()->values()->all();
                $clientRules = Capsule::table(self::CLIENT_CONFIG_TABLE)
                    ->where('key', 'issue_nfe_cond')
                    ->whereIn('client_id', $clientIds)
                    ->pluck('value', 'client_id');

                foreach ($invoices as $invoice) {
                    $clientRule = $clientRules->get($invoice->client_id, '');
                    if (!MonitorPolicy::isPaymentTriggered($clientRule, $moduleRule, $issueAfterDays)) {
                        continue;
                    }

                    ++$candidateCount;
                    if ($this->createAlertIfMissing($invoice)) {
                        ++$createdCount;
                    }
                }
            } while ($invoices->count() === 500);

            return [
                'status' => 'success',
                'scanned' => $scannedCount,
                'candidates' => $candidateCount,
                'alerts_created' => $createdCount,
            ];
        });
    }

    private function candidateInvoicesPage($startedAt, $cutoff, $lastInvoiceId)
    {
        return Capsule::table('tblinvoices as invoice')
            ->join('tblclients as client', 'client.id', '=', 'invoice.userid')
            ->where('invoice.id', '>', (int) $lastInvoiceId)
            ->where('invoice.status', 'Paid')
            ->where('invoice.total', '>', 0)
            ->whereNotNull('invoice.datepaid')
            ->where('invoice.datepaid', '>=', $startedAt)
            ->where('invoice.datepaid', '<=', $cutoff)
            ->whereNotExists(function ($query) {
                $query->select(Capsule::raw(1))
                    ->from(self::INVOICES_TABLE . ' as issued')
                    ->whereRaw('issued.invoice_id = invoice.id')
                    ->whereRaw('LOWER(issued.status) = ?', ['issued'])
                    ->whereNotNull('issued.nfe_id')
                    ->whereNotIn('issued.nfe_id', ['', 'waiting'])
                    ->where('issued.services_amount', '>', 0);
            })
            ->whereNotExists(function ($query) {
                $query->select(Capsule::raw(1))
                    ->from(self::ALERTS_TABLE . ' as alerted')
                    ->whereRaw('alerted.invoice_id = invoice.id');
            })
            ->select([
                'invoice.id as invoice_id',
                'invoice.userid as client_id',
                'invoice.datepaid',
                'invoice.total',
                'client.firstname',
                'client.lastname',
                'client.companyname',
            ])
            ->orderBy('invoice.id', 'asc')
            ->limit(500)
            ->get();
    }

    public function runDueRetries()
    {
        if (!$this->settings->isEnabled(self::MONITOR_ENABLED_SETTING) || !$this->notifier()->isConfigured()) {
            return ['status' => 'disabled_or_invalid', 'processed' => 0];
        }

        if (!Capsule::schema()->hasTable(self::ALERTS_TABLE)) {
            return ['status' => 'table_absent', 'processed' => 0];
        }

        return $this->withLock('retries', function () {
            $alerts = Capsule::table(self::ALERTS_TABLE)
                ->where('status', 'retry_pending')
                ->where('attempts', '<', MonitorPolicy::MAX_ATTEMPTS)
                ->whereNotNull('next_retry_at')
                ->where('next_retry_at', '<=', date('Y-m-d H:i:s'))
                ->orderBy('next_retry_at', 'asc')
                ->limit(50)
                ->get();

            $processed = 0;
            foreach ($alerts as $alert) {
                $this->attemptNotification($alert->id);
                ++$processed;
            }

            return ['status' => 'success', 'processed' => $processed];
        });
    }

    private function createAlertIfMissing($invoice)
    {
        if (Capsule::table(self::ALERTS_TABLE)->where('invoice_id', $invoice->invoice_id)->exists()) {
            return false;
        }

        $latestNote = $this->refreshRemoteState($this->latestNote($invoice->invoice_id));
        if ($this->isValidIssuedNote($latestNote)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $clientName = trim((string) $invoice->companyname);
        if ($clientName === '') {
            $clientName = trim((string) $invoice->firstname . ' ' . (string) $invoice->lastname);
        }

        try {
            $alertId = Capsule::table(self::ALERTS_TABLE)->insertGetId([
                'invoice_id' => (int) $invoice->invoice_id,
                'client_id' => (int) $invoice->client_id,
                'client_name' => $clientName !== '' ? $clientName : 'Cliente sem nome',
                'paid_at' => $invoice->datepaid,
                'last_nf_status' => $latestNote ? $latestNote->status : null,
                'last_flow_status' => $latestNote ? $latestNote->flow_status : null,
                'status' => 'pending',
                'attempts' => 0,
                'last_attempt_at' => null,
                'next_retry_at' => null,
                'notified_at' => null,
                'resolved_at' => null,
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            if (Capsule::table(self::ALERTS_TABLE)->where('invoice_id', $invoice->invoice_id)->exists()) {
                return false;
            }
            throw $exception;
        }

        $this->attemptNotification($alertId);
        return true;
    }

    private function attemptNotification($alertId)
    {
        $alert = Capsule::table(self::ALERTS_TABLE)->where('id', $alertId)->first();
        if (!$alert) {
            return;
        }

        if ($this->isResolved($alert->invoice_id)) {
            Capsule::table(self::ALERTS_TABLE)->where('id', $alertId)->update([
                'status' => 'resolved',
                'next_retry_at' => null,
                'resolved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        $latestNote = $this->latestNote($alert->invoice_id);
        $attempts = (int) $alert->attempts + 1;
        $now = date('Y-m-d H:i:s');
        try {
            $result = $this->notifier()->send($this->buildMessage($alert, $latestNote));
        } catch (\Throwable $exception) {
            $result = [
                'success' => false,
                'http_code' => 0,
                'error' => 'notifier_exception:' . get_class($exception),
            ];
        }

        $updates = [
            'attempts' => $attempts,
            'last_attempt_at' => $now,
            'last_nf_status' => $latestNote ? $latestNote->status : null,
            'last_flow_status' => $latestNote ? $latestNote->flow_status : null,
            'updated_at' => $now,
        ];

        if ($result['success']) {
            $updates['status'] = 'sent';
            $updates['notified_at'] = $now;
            $updates['next_retry_at'] = null;
            $updates['last_error'] = null;
            Capsule::table(self::ALERTS_TABLE)->where('id', $alertId)->update($updates);
            $this->safeModuleLog('telegram_monitor_sent', $alert->invoice_id, [
                'attempt' => $attempts,
                'http_code' => $result['http_code'],
            ]);
            return;
        }

        $updates['last_error'] = (string) $result['error'];
        if (MonitorPolicy::shouldScheduleRetry($attempts)) {
            $updates['status'] = 'retry_pending';
            $updates['next_retry_at'] = date(
                'Y-m-d H:i:s',
                MonitorPolicy::nextRetryTimestamp(time())
            );
        } else {
            $updates['status'] = 'failed';
            $updates['next_retry_at'] = null;
        }

        Capsule::table(self::ALERTS_TABLE)->where('id', $alertId)->update($updates);
        $this->safeModuleLog('telegram_monitor_failed', $alert->invoice_id, [
            'attempt' => $attempts,
            'max_attempts' => MonitorPolicy::MAX_ATTEMPTS,
            'http_code' => $result['http_code'],
            'error' => $result['error'],
            'retry_scheduled' => $updates['status'] === 'retry_pending',
        ]);

        if ($updates['status'] === 'failed' && function_exists('logActivity')) {
            logActivity(
                'NFE.io: alerta Telegram da fatura #' . (int) $alert->invoice_id
                . ' falhou após ' . MonitorPolicy::MAX_ATTEMPTS . ' tentativas.'
            );
        }
    }

    private function isResolved($invoiceId)
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['status']);
        if (!$invoice || $invoice->status !== 'Paid') {
            return true;
        }

        $latestNote = $this->refreshRemoteState($this->latestNote($invoiceId));
        return $this->isValidIssuedNote($latestNote);
    }

    private function latestNote($invoiceId)
    {
        return Capsule::table(self::INVOICES_TABLE)
            ->where('invoice_id', $invoiceId)
            ->orderBy('id', 'desc')
            ->first([
                'id',
                'invoice_id',
                'nfe_id',
                'nfe_external_id',
                'status',
                'flow_status',
                'services_amount',
                'company_id',
            ]);
    }

    private function refreshRemoteState($note)
    {
        if (
            !$note
            || empty($note->company_id)
            || empty($note->nfe_id)
            || $note->nfe_id === 'waiting'
        ) {
            return $note;
        }

        try {
            $remote = $this->nfe->fetchServiceInvoice($note->company_id, $note->nfe_id);
            if (empty($remote->status)) {
                return $note;
            }

            if (!empty($note->nfe_external_id)) {
                $repository = new \NFEioServiceInvoices\Models\ServiceInvoices\Repository();
                $repository->updateServiceInvoice($note->nfe_external_id, $remote);
                return $this->latestNote($note->invoice_id);
            }
        } catch (\Throwable $exception) {
            $this->safeModuleLog('telegram_monitor_remote_lookup_failed', $note->invoice_id, [
                'error' => get_class($exception),
            ]);
        }

        return $note;
    }

    private function isValidIssuedNote($note)
    {
        return $note
            && strtolower((string) $note->status) === 'issued'
            && !empty($note->nfe_id)
            && $note->nfe_id !== 'waiting'
            && (float) $note->services_amount > 0;
    }

    private function buildMessage($alert, $latestNote)
    {
        $paidAt = (string) $alert->paid_at;
        try {
            $paidAt = (new \DateTimeImmutable($paidAt))->format('d/m/Y H:i');
        } catch (\Throwable $exception) {
            // Preserve the database value if it cannot be parsed.
        }

        $situation = 'Sem registro local de emissão';
        if ($latestNote) {
            $situation = 'Status ' . (string) $latestNote->status;
            if (!empty($latestNote->flow_status)) {
                $situation .= ' / ' . (string) $latestNote->flow_status;
            }
        }

        return '<b>🚨 NFS-e não emitida</b>' . "\n"
            . '<b>Cliente:</b> ' . $this->escape($alert->client_name)
            . ' (#' . (int) $alert->client_id . ')' . "\n"
            . '<b>Fatura:</b> #' . (int) $alert->invoice_id . "\n"
            . '<b>Pagamento:</b> ' . $this->escape($paidAt) . "\n"
            . '<b>Situação:</b> ' . $this->escape($situation) . "\n"
            . 'Mais de 24 horas após o pagamento sem emissão confirmada.';
    }

    private function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function monitorStartedAt()
    {
        $startedAt = trim((string) $this->settings->get(self::MONITOR_STARTED_AT_SETTING, ''));
        if ($startedAt === '') {
            $startedAt = date('Y-m-d H:i:s');
            $this->settings->setInternal(self::MONITOR_STARTED_AT_SETTING, $startedAt);
        }

        return $startedAt;
    }

    private function withLock($suffix, $callback)
    {
        $lockName = 'nfeio_unissued_invoice_monitor_' . $suffix;
        $connection = Capsule::connection();
        $lock = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if (!$lock || (int) $lock->acquired !== 1) {
            return ['status' => 'locked'];
        }

        try {
            return $callback();
        } finally {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    private function safeModuleLog($action, $invoiceId, array $result)
    {
        if (function_exists('logModuleCall')) {
            logModuleCall(
                'nfeio_serviceinvoices',
                $action,
                ['invoice_id' => (int) $invoiceId],
                $result
            );
        }
    }

    private function notifier()
    {
        if (!$this->notifier) {
            $this->notifier = new TelegramNotifier(
                $this->settings->getEncryptedSecret(
                    self::TELEGRAM_TOKEN_ENCRYPTED_SETTING,
                    self::TELEGRAM_TOKEN_INPUT_SETTING
                ),
                $this->settings->get('telegram_chat_id', '')
            );
        }

        return $this->notifier;
    }
}
