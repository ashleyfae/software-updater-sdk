<?php

namespace AshleyFae\SoftwareUpdater\Updater;

use AshleyFae\SoftwareUpdater\DataTransferObjects\LicenseConfig;
use AshleyFae\SoftwareUpdater\DataTransferObjects\PluginLicenseConfig;
use AshleyFae\SoftwareUpdater\DataTransferObjects\ReleaseResponse;
use AshleyFae\SoftwareUpdater\DataTransferObjects\ThemeLicenseConfig;
use AshleyFae\SoftwareUpdater\Exceptions\ApiRequestFailedException;
use AshleyFae\SoftwareUpdater\Http\ApiClient;
use AshleyFae\SoftwareUpdater\Registries\LicenseRegistry;
use stdClass;

class UpdateChecker
{
    private const TRANSIENT_KEY = 'software_updater_releases';
    private const TRANSIENT_TTL = 43200; // 12 hours

    public function load(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForPluginUpdates']);
        add_filter('pre_set_site_transient_update_themes', [$this, 'checkForThemeUpdates']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 10, 3);
        add_action('upgrader_process_complete', [$this, 'clearReleaseCache'], 10, 2);
    }

    /**
     * Checks if the SDK is definitely loaded.
     * This seems dumb but we have to account for an edge case where:
     * - Plugin is using SDK.
     * - Plugin checks for updates.
     * - Plugin downloads new update.
     * - New update does not include SDK code.
     */
    protected function isSdkAvailable() : bool
    {
        return class_exists(ApiClient::class);
    }

    public function checkForPluginUpdates(mixed $transient): mixed
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        if (! $this->isSdkAvailable()) {
            return $transient;
        }

        foreach ($this->getReleases() as $licenseKey => $release) {
            if ($release === null) {
                continue;
            }

            $config = $this->getConfigByLicenseKey($licenseKey);
            if (! ($config instanceof PluginLicenseConfig)) {
                continue;
            }

            $basename = plugin_basename($config->pluginFile);

            if (version_compare($release->version, $config->version, '>')) {
                $transient->response[$basename] = $this->buildPluginUpdateObject($release, $config);
            } else {
                $transient->no_update[$basename] = $this->buildPluginUpdateObject($release, $config);
            }
        }

        return $transient;
    }

    public function checkForThemeUpdates(mixed $transient): mixed
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        if (! $this->isSdkAvailable()) {
            return $transient;
        }

        foreach ($this->getReleases() as $licenseKey => $release) {
            if ($release === null) {
                continue;
            }

            $config = $this->getConfigByLicenseKey($licenseKey);
            if (! ($config instanceof ThemeLicenseConfig)) {
                continue;
            }

            $slug = $this->slug($config);

            if (version_compare($release->version, $config->version, '>')) {
                $transient->response[$slug] = $this->buildThemeUpdateArray($release, $config);
            } else {
                $transient->no_update[$slug] = $this->buildThemeUpdateArray($release, $config);
            }
        }

        return $transient;
    }

    public function pluginInfo(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $config = $this->getPluginConfigBySlug($args->slug ?? '');
        if ($config === null) {
            return $result;
        }

        $licenseKey = (string) get_option($config->optionName, '');
        if (empty($licenseKey)) {
            return $result;
        }

        $release = $this->getReleases()[$licenseKey] ?? null;
        if ($release === null) {
            return $result;
        }

        return $this->buildPluginInfoObject($release, $config);
    }

    public function clearReleaseCache(mixed $upgrader, array $options): void
    {
        $type = $options['type'] ?? '';
        if ($type === 'plugin' || $type === 'theme') {
            delete_transient(self::TRANSIENT_KEY);
        }
    }

    /**
     * @return array<string, ReleaseResponse|null>
     */
    private function getReleases(): array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            return array_map(
                fn($item) => $item !== null ? ReleaseResponse::fromArray($item) : null,
                $cached
            );
        }

        $configs = LicenseRegistry::getInstance()->all();

        $licenseKeys = [];
        foreach ($configs as $config) {
            $key = (string) get_option($config->optionName, '');
            if (! empty($key)) {
                $licenseKeys[] = $key;
            }
        }

        if (empty($licenseKeys)) {
            return [];
        }

        try {
            $releases = (new ApiClient())->latestReleases($licenseKeys);
        } catch (ApiRequestFailedException $e) {
            error_log('software-updater-sdk: update check failed — ' . $e->getMessage());
            return [];
        }

        set_transient(
            self::TRANSIENT_KEY,
            array_map(fn($release) => $release !== null ? $release->toArray() : null, $releases),
            self::TRANSIENT_TTL
        );

        return $releases;
    }

    private function getConfigByLicenseKey(string $licenseKey): ?LicenseConfig
    {
        foreach (LicenseRegistry::getInstance()->all() as $config) {
            if (get_option($config->optionName) === $licenseKey) {
                return $config;
            }
        }

        return null;
    }

    private function getPluginConfigBySlug(string $slug): ?PluginLicenseConfig
    {
        foreach (LicenseRegistry::getInstance()->all() as $config) {
            if ($config instanceof PluginLicenseConfig && $this->slug($config) === $slug) {
                return $config;
            }
        }

        return null;
    }

    private function slug(LicenseConfig $config): string
    {
        if ($config instanceof PluginLicenseConfig) {
            return basename(dirname($config->pluginFile));
        }

        return basename($config->themeDirectory);
    }

    private function buildPluginUpdateObject(ReleaseResponse $release, PluginLicenseConfig $config): stdClass
    {
        $obj               = new stdClass();
        $obj->id           = 'w.org/plugins/' . $this->slug($config);
        $obj->slug         = $this->slug($config);
        $obj->plugin       = plugin_basename($config->pluginFile);
        $obj->new_version  = $release->version;
        $obj->url          = '';
        $obj->package      = $release->downloadUrl ?? '';
        $obj->icons        = [];
        $obj->banners      = [];
        $obj->banners_rtl  = [];
        $obj->requires     = $release->requires['wp'] ?? '';
        $obj->requires_php = $release->requires['php'] ?? '';
        $obj->tested       = '';

        return $obj;
    }

    private function buildThemeUpdateArray(ReleaseResponse $release, ThemeLicenseConfig $config): array
    {
        return [
            'theme'        => $this->slug($config),
            'new_version'  => $release->version,
            'url'          => '',
            'package'      => $release->downloadUrl ?? '',
            'requires'     => $release->requires['wp'] ?? '',
            'requires_php' => $release->requires['php'] ?? '',
        ];
    }

    private function buildPluginInfoObject(ReleaseResponse $release, PluginLicenseConfig $config): stdClass
    {
        $obj                = new stdClass();
        $obj->name          = $this->slug($config);
        $obj->slug          = $this->slug($config);
        $obj->version       = $release->version;
        $obj->last_updated  = $release->updatedAt?->format('Y-m-d') ?? '';
        $obj->requires      = $release->requires['wp'] ?? '';
        $obj->requires_php  = $release->requires['php'] ?? '';
        $obj->tested        = '';
        $obj->download_link = $release->downloadUrl ?? '';
        $obj->sections      = [
            'description' => '',
            'changelog'   => $release->description ?? '',
        ];

        return $obj;
    }
}
