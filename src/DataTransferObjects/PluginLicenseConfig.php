<?php

namespace AshleyFae\SoftwareUpdater\DataTransferObjects;

class PluginLicenseConfig extends LicenseConfig
{
    public function __construct(
        string $optionName,
        string $productId,
        public string $pluginFile,
        string $version,
    ) {
        parent::__construct($optionName, $productId, $version);
    }
}
