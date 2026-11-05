<?php
// A WHMCS dependency loader must retain precedence over addon dependencies.
$hostLoader = function ($class) {
    if ($class === 'Psr\\Http\\Message\\StreamInterface') {
        eval('namespace Psr\\Http\\Message; interface StreamInterface { const HOST_SENTINEL = true; }');
    }
};
spl_autoload_register($hostLoader);
$module = dirname(__DIR__) . '/modules/addons/NFEioServiceInvoices';
require $module . '/Loader.php';
if (!defined('Psr\\Http\\Message\\StreamInterface::HOST_SENTINEL')) {
    throw new RuntimeException('Addon shadowed the host PSR interface');
}
require $module . '/lib/Monitoring/MonitorPolicy.php';
require $module . '/lib/Monitoring/UnissuedInvoiceMonitor.php';
use NFEioServiceInvoices\Monitoring\MonitorPolicy as Policy;
function check($condition, $name) {
    if (!$condition) { throw new RuntimeException($name); }
    echo 'PASS: ' . $name . PHP_EOL;
}
check(true, 'host dependency loader has precedence');
check(class_exists('NFe_io'), 'addon API dependency remains available');
check(Policy::isPaymentTriggered('', Policy::PAYMENT_RULE, 0), 'module payment rule');
check(!Policy::isPaymentTriggered('manualmente', Policy::PAYMENT_RULE, 0), 'manual clients excluded');
check(!Policy::isPaymentTriggered('', Policy::PAYMENT_RULE, 2), 'scheduled clients excluded');
foreach ([0 => false, 1 => true, 2 => true, 3 => true, 4 => false] as $attempt => $expected) {
    check(Policy::shouldScheduleRetry($attempt) === $expected, 'retry limit at attempt ' . $attempt);
}
check(Policy::nextRetryTimestamp(1000) === 4600, 'retry interval remains one hour');
$reflection = new ReflectionClass('NFEioServiceInvoices\\Monitoring\\UnissuedInvoiceMonitor');
$monitor = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('buildMessage');
$method->setAccessible(true);
$message = $method->invoke($monitor, (object) [
    'paid_at' => '2026-11-04 12:00:00', 'client_name' => 'Test <client>',
    'client_id' => 1, 'invoice_id' => 123,
], (object) ['status' => 'Error', 'flow_status' => 'WaitingReturn']);
check(strpos($message, 'até o dia anterior') !== false, 'previous-day alert wording preserved');
check(strpos($message, 'Test &lt;client&gt;') !== false, 'Telegram client name escaped');
check(strpos($message, 'Error / WaitingReturn') !== false, 'pending remote state visible');
