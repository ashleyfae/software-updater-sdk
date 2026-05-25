<?php

namespace AshleyFae\SoftwareUpdater;

/**
 * Resolves version conflicts when multiple plugins bundle different versions of this SDK.
 *
 * This class must never change its public API (instance(), registerSdk()). It is a stable
 * outer shell — whichever plugin's autoloader defines it first wins, but all plugins call
 * registerSdk() on that same singleton. The latest registered version is then loaded.
 */
class Loader
{
    protected static ?Loader $instance = null;

    protected array $registeredSdks = [];

    protected array $latestSdk = [];

    public static function instance(): Loader
    {
        if (static::$instance instanceof Loader) {
            return static::$instance;
        }

        static::$instance = new static();
        static::$instance->init();

        return static::$instance;
    }

    protected function init(): void
    {
        add_action('after_setup_theme', [$this, 'setAndLoadLatest'], PHP_INT_MAX);
    }

    public function setAndLoadLatest(): void
    {
        foreach ($this->registeredSdks as $sdk) {
            if ($this->isLaterVersion($sdk)) {
                $this->latestSdk = $sdk;
            }
        }

        $this->loadLatestSdk();
    }

    protected function loadLatestSdk(): void
    {
        if (empty($this->latestSdk['path']) || ! file_exists($this->latestSdk['path'])) {
            return;
        }

        require_once $this->latestSdk['path'];

        if (class_exists(SDK::class) && ! did_action('software_updater_sdk_loaded')) {
            do_action('software_updater_sdk_loaded', SDK::instance());
        }
    }

    protected function isLaterVersion(array $sdk): bool
    {
        if (empty($sdk['version']) || empty($sdk['path'])) {
            return false;
        }

        if (empty($this->latestSdk)) {
            return true;
        }

        return version_compare($sdk['version'], $this->latestSdk['version'], '>');
    }

    public function registerSdk(string $version, string $pathToSdk): Loader
    {
        $this->registeredSdks[] = [
            'version' => $version,
            'path'    => $pathToSdk,
        ];

        return $this;
    }
}
