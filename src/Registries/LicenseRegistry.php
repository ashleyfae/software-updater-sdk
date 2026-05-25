<?php

namespace AshleyFae\SoftwareUpdater\Registries;

use AshleyFae\SoftwareUpdater\DataTransferObjects\LicenseConfig;
use AshleyFae\SoftwareUpdater\Exceptions\InvalidLicenseConfigException;

class LicenseRegistry
{
    private static ?LicenseRegistry $instance = null;

    /** @var array<string, LicenseConfig> keyed by optionName */
    private array $configs = [];

    private function __construct() {}

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * @throws InvalidLicenseConfigException
     */
    public function add(LicenseConfig $config): static
    {
        if (empty($config->optionName)) {
            throw new InvalidLicenseConfigException('LicenseConfig optionName cannot be empty.');
        }

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $config->productId)) {
            throw new InvalidLicenseConfigException('LicenseConfig productId must be a valid UUID.');
        }

        $this->configs[$config->optionName] = $config;

        return $this;
    }

    public function get(string $optionName): ?LicenseConfig
    {
        return $this->configs[$optionName] ?? null;
    }

    /**
     * @return array<string, LicenseConfig>
     */
    public function all(): array
    {
        return $this->configs;
    }
}
