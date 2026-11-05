<?php
// Isolated regression checks: no WHMCS database or external API is contacted.
namespace NFEioServiceInvoices {
    class Configuration { public function getStorageKey() { return 'test'; } }
}
namespace WHMCSExpert\Addon {
    class Storage {
        public function __construct($key) {}
        public function get($key) { return $GLOBALS['settings'][$key]; }
    }
}
namespace WHMCS\Billing {
    class Invoice { public static function find($id) { return $GLOBALS['invoice']; } }
}
namespace NFEioServiceInvoices\Models\ClientConfiguration {
    class Repository {
        public function getClientIssueCondition($id) { return $GLOBALS['clientRule']; }
    }
}
namespace NFEioServiceInvoices\NFEio {
    class Nfe {
        public function queue($id) { $GLOBALS['queued'][] = $id; return true; }
    }
}
namespace {
    function logModuleCall($module, $action, $input, $output) {}
    require dirname(__DIR__) . '/modules/addons/NFEioServiceInvoices/lib/Hooks/InvoicePaid.php';
    $cases = [
        ['paid using credit', 'Paid', 100, 0, 0, 'seguir configuração do módulo nfe.io', 1],
        ['paid normally', 'Paid', 100, 100, 0, 'quando a fatura é paga', 1],
        ['unpaid', 'Unpaid', 100, 100, 0, 'quando a fatura é paga', 0],
        ['zero subtotal', 'Paid', 0, 0, 0, 'quando a fatura é paga', 0],
        ['scheduled issuance', 'Paid', 100, 100, 2, 'quando a fatura é paga', 0],
        ['manual issuance', 'Paid', 100, 100, 0, 'manualmente', 0],
    ];
    foreach ($cases as $case) {
        list($name, $status, $subtotal, $total, $delay, $clientRule, $expected) = $case;
        $settings = ['issue_note_default_cond' => 'quando a fatura é paga', 'issue_note_after' => $delay];
        $invoice = (object) ['status' => $status, 'subtotal' => $subtotal, 'total' => $total, 'userid' => 1];
        $queued = [];
        (new \NFEioServiceInvoices\Hooks\InvoicePaid(['invoiceid' => 123]))->run();
        if (count($queued) !== $expected || ($expected && $queued !== [123])) {
            throw new \RuntimeException('Failed: ' . $name);
        }
        echo 'PASS: ' . $name . PHP_EOL;
    }
}
