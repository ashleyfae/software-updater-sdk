<?php

namespace AshleyFae\SoftwareUpdater;

use AshleyFae\SoftwareUpdater\DataTransferObjects\LicenseConfig;
use AshleyFae\SoftwareUpdater\Exceptions\InvalidLicenseConfigException;
use AshleyFae\SoftwareUpdater\License\LicenseManager;
use AshleyFae\SoftwareUpdater\Registries\LicenseRegistry;
use AshleyFae\SoftwareUpdater\Scheduler\WeeklyLicenseChecker;
use AshleyFae\SoftwareUpdater\Updater\UpdateChecker;

class SDK
{
    protected static ?SDK $instance = null;

    protected static string $version = '1.0.0';

    public static function instance(): SDK
    {
        if (static::$instance instanceof SDK) {
            return static::$instance;
        }

        static::$instance = new static();
        static::$instance->init();

        return static::$instance;
    }

    protected function init(): void
    {
        (new WeeklyLicenseChecker())->load();
        (new UpdateChecker())->load();
    }

    public static function getVersion(): string
    {
        return static::$version;
    }

    /**
     * Register a plugin's license config with the SDK.
     *
     * Call this via the `software_updater_sdk_loaded` action to guarantee the SDK
     * is fully initialized before registering:
     *
     *   add_action('software_updater_sdk_loaded', fn($sdk) => $sdk->register($config));
     *
     * @throws InvalidLicenseConfigException
     */
    public function register(LicenseConfig $config): static
    {
        LicenseRegistry::getInstance()->add($config);

        return $this;
    }

    /**
     * Returns a LicenseManager for the config registered under the given option name.
     *
     * @throws InvalidLicenseConfigException if no config is registered for $optionName
     */
    public function license(string $optionName): LicenseManager
    {
        $config = LicenseRegistry::getInstance()->get($optionName);

        if ($config === null) {
            throw new InvalidLicenseConfigException(
                "No license config registered for option: {$optionName}"
            );
        }

        return new LicenseManager($config);
    }
}
