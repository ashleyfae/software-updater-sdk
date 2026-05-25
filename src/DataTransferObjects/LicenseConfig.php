<?php

namespace AshleyFae\SoftwareUpdater\DataTransferObjects;

class LicenseConfig
{
    public function __construct(
        public string $optionName,
        public string $productId,
        public string $pluginFile,
        public string $version,
    ) {}
}
