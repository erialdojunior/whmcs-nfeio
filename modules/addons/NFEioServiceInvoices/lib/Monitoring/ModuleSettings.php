<?php

namespace NFEioServiceInvoices\Monitoring;

use WHMCS\Database\Capsule;

class ModuleSettings
{
    const ENCRYPTED_PREFIX = 'whmcsenc:v1:';

    /**
     * @var string
     */
    private $moduleName;

    /**
     * @var \WHMCSExpert\Addon\Storage
     */
    private $storage;

    public function __construct($moduleName)
    {
        $this->moduleName = $moduleName;
        $this->storage = new \WHMCSExpert\Addon\Storage($moduleName);
    }

    /**
     * Reads addon settings through WHMCS' model when available. Newer WHMCS
     * versions transparently decrypt native password settings; the legacy
     * storage remains as a compatibility fallback for older installations.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        try {
            if (class_exists('\\WHMCS\\Module\\Addon\\Setting')) {
                $setting = \WHMCS\Module\Addon\Setting::where('module', $this->moduleName)
                    ->where('setting', $key)
                    ->first();

                if ($setting && $setting->value !== null) {
                    return $setting->value;
                }
            }
        } catch (\Throwable $exception) {
            // Fall back for older WHMCS releases without the encrypted accessor.
        }

        $value = $this->storage->get($key);
        return $value !== null ? $value : $default;
    }

    /**
     * Moves a password field into an internal WHMCS-encrypted setting and then
     * clears the form field. An empty form field intentionally keeps the
     * previously stored secret.
     *
     * @return array<string, mixed>
     */
    public function captureEncryptedSecret($inputKey, $storedKey)
    {
        $plainValue = trim((string) $this->get($inputKey, ''));
        if ($plainValue === '') {
            return ['status' => 'unchanged'];
        }

        if (!function_exists('localAPI')) {
            return ['status' => 'error', 'error' => 'local_api_unavailable'];
        }

        $result = localAPI('EncryptPassword', ['password2' => $plainValue]);
        if (!is_array($result) || ($result['result'] ?? '') !== 'success' || empty($result['password'])) {
            return ['status' => 'error', 'error' => 'encryption_failed'];
        }

        $encryptedValue = self::ENCRYPTED_PREFIX . (string) $result['password'];
        Capsule::connection()->transaction(function () use ($inputKey, $storedKey, $encryptedValue) {
            $existing = Capsule::table('tbladdonmodules')
                ->where('module', $this->moduleName)
                ->where('setting', $storedKey)
                ->first(['id']);

            if ($existing) {
                Capsule::table('tbladdonmodules')->where('id', $existing->id)->update([
                    'value' => $encryptedValue,
                ]);
            } else {
                Capsule::table('tbladdonmodules')->insert([
                    'module' => $this->moduleName,
                    'setting' => $storedKey,
                    'value' => $encryptedValue,
                ]);
            }

            Capsule::table('tbladdonmodules')
                ->where('module', $this->moduleName)
                ->where('setting', $inputKey)
                ->update(['value' => '']);
        });

        return ['status' => 'stored'];
    }

    /**
     * Decrypts an internal secret through the WHMCS Local API. The input field
     * fallback permits a safe transition before the first configuration save.
     */
    public function getEncryptedSecret($storedKey, $inputKey)
    {
        $storedValue = trim((string) $this->storage->get($storedKey));
        if (strpos($storedValue, self::ENCRYPTED_PREFIX) === 0 && function_exists('localAPI')) {
            $cipherText = substr($storedValue, strlen(self::ENCRYPTED_PREFIX));
            $result = localAPI('DecryptPassword', ['password2' => $cipherText]);
            if (is_array($result) && ($result['result'] ?? '') === 'success') {
                return (string) ($result['password'] ?? '');
            }

            return '';
        }

        return (string) $this->get($inputKey, '');
    }

    /**
     * Persists only internal, non-secret monitor state.
     *
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public function setInternal($key, $value)
    {
        $this->storage->set($key, $value);
    }

    public function isEnabled($key)
    {
        return in_array(strtolower(trim((string) $this->get($key, ''))), ['1', 'on', 'yes', 'true'], true);
    }
}
