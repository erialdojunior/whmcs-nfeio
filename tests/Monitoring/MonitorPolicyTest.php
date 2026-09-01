<?php

declare(strict_types=1);

require_once __DIR__ . '/../../modules/addons/NFEioServiceInvoices/lib/Monitoring/MonitorPolicy.php';
require_once __DIR__ . '/../../modules/addons/NFEioServiceInvoices/lib/Monitoring/TelegramNotifier.php';

use NFEioServiceInvoices\Monitoring\MonitorPolicy;
use NFEioServiceInvoices\Monitoring\TelegramNotifier;

$assert = static function ($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    MonitorPolicy::isPaymentTriggered('Quando a fatura é paga', 'Manualmente', 0),
    'Explicit payment rule must be monitored.'
);
$assert(
    MonitorPolicy::isPaymentTriggered(
        'Seguir configuração do módulo NFE.io',
        'Quando a fatura é paga',
        0
    ),
    'Clients following a payment-triggered module rule must be monitored.'
);
$assert(
    !MonitorPolicy::isPaymentTriggered('Seguir configuração do módulo NFE.io', 'Manualmente', 0),
    'Clients following a manual module rule must not be monitored.'
);
$assert(
    !MonitorPolicy::isPaymentTriggered('Quando a fatura é paga', 'Manualmente', 1),
    'Delayed issuance must not be treated as immediate payment issuance.'
);

$assert(MonitorPolicy::shouldScheduleRetry(1), 'First failure must schedule retry 1.');
$assert(MonitorPolicy::shouldScheduleRetry(2), 'Second failure must schedule retry 2.');
$assert(MonitorPolicy::shouldScheduleRetry(3), 'Third failure must schedule retry 3.');
$assert(!MonitorPolicy::shouldScheduleRetry(4), 'Fourth total attempt must be final.');
$assert(
    MonitorPolicy::nextRetryTimestamp(1000) === 4600,
    'Retries must be scheduled exactly one hour later.'
);

$configuredNotifier = new TelegramNotifier(
    '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdef',
    '-1001234567890'
);
$assert($configuredNotifier->isConfigured(), 'Valid Telegram credentials must pass local validation.');
$assert(
    !(new TelegramNotifier('invalid', '-1001234567890'))->isConfigured(),
    'Invalid bot token must be rejected before network access.'
);
$assert(
    !(new TelegramNotifier('123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdef', '@channel'))->isConfigured(),
    'Only numeric chat IDs are accepted.'
);

echo "MonitorPolicyTest: OK\n";
