<?php

namespace NFEioServiceInvoices\Hooks;

use NFEioServiceInvoices\Helpers\Timestamp;
use WHMCS\Database\Capsule;

/**
 * Classe com execução das rotinas para o gatilho aftercronjob
 *
 * @see     https://developers.whmcs.com/hooks-reference/cron/#aftercronjob
 * @author  Andre Bellafronte
 * @version 2.1.0
 */
class AfterCronJob
{
    /**
     * @var \NFEioServiceInvoices\Configuration
     */
    private $config;
    /**
     * @var \NFEioServiceInvoices\Models\ServiceInvoices\Repository
     */
    private $serviceInvoicesRepo;
    /**
     * @var \NFEioServiceInvoices\NFEio\Nfe
     */
    private $nf;

    public function __construct()
    {
        $this->config = new \NFEioServiceInvoices\Configuration();
        $this->serviceInvoicesRepo = new \NFEioServiceInvoices\Models\ServiceInvoices\Repository();
        $this->nf = new \NFEioServiceInvoices\NFEio\Nfe();
    }

    public function run()
    {
        $storageKey = $this->config->getStorageKey();
        $serviceInvoicesTable = $this->serviceInvoicesRepo->tableName();
        $storage = new \WHMCSExpert\Addon\Storage($storageKey);
        $dataAtual = Timestamp::currentTimestamp();
        // caso não exista valor para initial_date inicia define data que garanta a execução da rotina
        $initialDate = (!empty($storage->get('initial_date'))) ? $storage->get('initial_date') : '1970-01-01 00:00:00';

        // atualiza a data da ultima cron
        $storage->set('last_cron', $dataAtual);

        // DailyCronJob is not dispatched reliably in every WHMCS cron layout.
        // Use the always-running AfterCronJob as a guarded fallback so the
        // unissued-invoice scan still runs exactly once per calendar day.
        $monitorScanDate = date('Y-m-d');
        $lastMonitorScanDate = (string) $storage->get('telegram_monitor_last_scan_date');
        if ($lastMonitorScanDate !== $monitorScanDate) {
            try {
                $monitor = new \NFEioServiceInvoices\Monitoring\UnissuedInvoiceMonitor();
                $scanSummary = $monitor->runDailyScan();
                $scanStatus = isset($scanSummary['status']) ? (string) $scanSummary['status'] : '';

                // A lock means another process owns this scan. Let a later cron
                // retry instead of incorrectly marking the day as completed.
                if ($scanStatus !== 'locked') {
                    $storage->set('telegram_monitor_last_scan_date', $monitorScanDate);
                }

                logModuleCall(
                    'nfeio_serviceinvoices',
                    'telegram_monitor_daily_scan_fallback',
                    ['schedule' => 'AfterCronJob', 'date' => $monitorScanDate],
                    $scanSummary
                );
            } catch (\Throwable $exception) {
                logModuleCall(
                    'nfeio_serviceinvoices',
                    'telegram_monitor_daily_scan_fallback_error',
                    ['schedule' => 'AfterCronJob', 'date' => $monitorScanDate],
                    ['error' => get_class($exception) . ': ' . $exception->getMessage()]
                );
            }
        }

        try {
            $monitor = new \NFEioServiceInvoices\Monitoring\UnissuedInvoiceMonitor();
            $retrySummary = $monitor->runDueRetries();
            if (!empty($retrySummary['processed'])) {
                logModuleCall(
                    'nfeio_serviceinvoices',
                    'telegram_monitor_retries',
                    ['schedule' => 'AfterCronJob'],
                    $retrySummary
                );
            }
        } catch (\Throwable $exception) {
            logModuleCall(
                'nfeio_serviceinvoices',
                'telegram_monitor_retries_error',
                ['schedule' => 'AfterCronJob'],
                ['error' => get_class($exception) . ': ' . $exception->getMessage()]
            );
        }

        $hasNfWaiting = Capsule::table($serviceInvoicesTable)->whereBetween('created_at', [$initialDate, $dataAtual])->where('status', '=', 'Waiting')->count();
        logModuleCall(
            'nfeio_serviceinvoices',
            'hook_aftercronjob_1',
            "{$hasNfWaiting} notas a serem geradas",
            array(
                [
                    'total de notas' => $hasNfWaiting,
                    'data atual' => $dataAtual,
                    'data inicial' => $initialDate,
                ]
            )
        );

        if ($hasNfWaiting) {
            $queryNf = Capsule::table($serviceInvoicesTable)
                ->orderBy('id', 'desc')
                ->whereBetween('created_at', [$initialDate, $dataAtual])
                ->where('status', '=', 'Waiting')
                ->get();

            logModuleCall('nfeio_serviceinvoices', 'hook_aftercronjob_2', "{$hasNfWaiting} notas a serem geradas", $queryNf);


            foreach ($queryNf as $invoice) {

                $this->nf->emit($invoice);

            }

        }
    }
}
