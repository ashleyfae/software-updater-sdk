<?php

namespace AshleyFae\SoftwareUpdater\DataTransferObjects;

class ThemeLicenseConfig extends LicenseConfig
{
    public function __construct(
        string $optionName,
        string $productId,
        public string $themeDirectory,
        string $version,
    ) {
        parent::__construct($optionName, $productId, $version);
    }
}
