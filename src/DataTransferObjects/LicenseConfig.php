<?php

namespace AshleyFae\SoftwareUpdater\DataTransferObjects;

abstract class LicenseConfig
{
    public function __construct(
        public string $optionName,
        public string $productId,
        public string $version,
    ) {}
}
