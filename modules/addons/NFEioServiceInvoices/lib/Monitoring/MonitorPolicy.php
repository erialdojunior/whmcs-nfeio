<?php

namespace NFEioServiceInvoices\Monitoring;

class MonitorPolicy
{
    const PAYMENT_RULE = 'quando a fatura é paga';
    const FOLLOW_MODULE_RULE = 'seguir configuração do módulo nfe.io';
    const MAX_RETRIES = 3;
    const MAX_ATTEMPTS = 4;
    const RETRY_INTERVAL_SECONDS = 3600;

    public static function normalizeRule($value)
    {
        $value = trim((string) $value);

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    public static function isPaymentTriggered($clientRule, $moduleDefaultRule, $issueAfterDays)
    {
        if ((int) $issueAfterDays !== 0) {
            return false;
        }

        $clientRule = self::normalizeRule($clientRule);
        $moduleDefaultRule = self::normalizeRule($moduleDefaultRule);
        $effectiveRule = ($clientRule === '' || $clientRule === self::FOLLOW_MODULE_RULE)
            ? $moduleDefaultRule
            : $clientRule;

        return $effectiveRule === self::PAYMENT_RULE;
    }

    /**
     * $attempts is the number of completed attempts, including the initial one.
     */
    public static function shouldScheduleRetry($attempts)
    {
        return (int) $attempts > 0 && (int) $attempts <= self::MAX_RETRIES;
    }

    public static function nextRetryTimestamp($timestamp)
    {
        return (int) $timestamp + self::RETRY_INTERVAL_SECONDS;
    }
}
