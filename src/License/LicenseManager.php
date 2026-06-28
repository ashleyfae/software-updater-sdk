<?php

namespace AshleyFae\SoftwareUpdater\License;

use AshleyFae\SoftwareUpdater\DataTransferObjects\ActivationResponse;
use AshleyFae\SoftwareUpdater\DataTransferObjects\LicenseConfig;
use AshleyFae\SoftwareUpdater\DataTransferObjects\LicenseStatusResponse;
use AshleyFae\SoftwareUpdater\Exceptions\ApiRequestFailedException;
use AshleyFae\SoftwareUpdater\Http\ApiClient;

class LicenseManager
{
    private ApiClient $client;

    public function __construct(
        private LicenseConfig $config,
        ?ApiClient $client = null,
    ) {
        $this->client = $client ?? new ApiClient();
    }

    /**
     * @throws ApiRequestFailedException
     */
    public function activate(): ?ActivationResponse
    {
        $licenseKey = $this->getLicenseKey();
        if (empty($licenseKey)) {
            return null;
        }

        $activation = $this->client->activate($licenseKey, $this->config->productId);

        $this->refreshStatus();

        return $activation;
    }

    /**
     * @throws ApiRequestFailedException
     */
    public function deactivate(): void
    {
        $licenseKey = $this->getLicenseKey();
        if (empty($licenseKey)) {
            return;
        }

        $this->client->deactivate($licenseKey);
        $this->refreshStatus();
    }

    /**
     * Returns the cached license status without making an API call.
     * Returns null if the status cache has not been populated yet.
     */
    public function getStatus(): ?LicenseStatusResponse
    {
        $cached = get_option($this->statusOptionName());
        if (empty($cached)) {
            return null;
        }

        $data = json_decode($cached, true);
        if (! is_array($data)) {
            return null;
        }

        return LicenseStatusResponse::fromArray($data);
    }

    /**
     * Makes a live API call and updates the cached status.
     * Returns null silently on API failure — this is intentionally best-effort.
     */
    public function refreshStatus(): ?LicenseStatusResponse
    {
        $licenseKey = $this->getLicenseKey();
        if (empty($licenseKey)) {
            return null;
        }

        try {
            $statuses = $this->client->bulkStatus([$licenseKey]);
        } catch (ApiRequestFailedException $e) {
            error_log('software-updater-sdk: refreshStatus failed — ' . $e->getMessage());
            return null;
        }

        $status = $statuses[$licenseKey] ?? null;

        if ($status !== null) {
            update_option($this->statusOptionName(), wp_json_encode($status->toArray()), false);
        }

        return $status;
    }

    public function getLicenseKey(): string
    {
        return (string) get_option($this->config->optionName, '');
    }

    public function setLicenseKey(string $licenseKey): void
    {
        update_option($this->config->optionName, $licenseKey);
    }

    private function statusOptionName(): string
    {
        return $this->config->optionName . '_status';
    }
}
