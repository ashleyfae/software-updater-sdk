<?php

namespace AshleyFae\SoftwareUpdater\Updater;

use AshleyFae\SoftwareUpdater\DataTransferObjects\LicenseConfig;
use AshleyFae\SoftwareUpdater\DataTransferObjects\ReleaseResponse;
use AshleyFae\SoftwareUpdater\Http\ApiClient;
use AshleyFae\SoftwareUpdater\Registries\LicenseRegistry;
use stdClass;

class UpdateChecker
{
    private const TRANSIENT_KEY = 'software_updater_releases';
    private const TRANSIENT_TTL = 43200; // 12 hours

    public function load(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForUpdates']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 10, 3);
        add_action('upgrader_process_complete', [$this, 'clearReleaseCache'], 10, 2);
    }

    public function checkForUpdates(mixed $transient): mixed
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $releases = $this->getReleases();

        foreach ($releases as $licenseKey => $release) {
            if ($release === null) {
                continue;
            }

            $config = $this->getConfigByLicenseKey($licenseKey);
            if ($config === null) {
                continue;
            }

            $basename = plugin_basename($config->pluginFile);

            if (version_compare($release->version, $config->version, '>') && ! empty($release->downloadUrl)) {
                $transient->response[$basename] = $this->buildUpdateObject($release, $config);
            } else {
                $transient->no_update[$basename] = $this->buildUpdateObject($release, $config);
            }
        }

        return $transient;
    }

    public function pluginInfo(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $config = $this->getConfigBySlug($args->slug ?? '');
        if ($config === null) {
            return $result;
        }

        $licenseKey = (string) get_option($config->optionName, '');
        if (empty($licenseKey)) {
            return $result;
        }

        $releases = $this->getReleases();
        $release  = $releases[$licenseKey] ?? null;

        if ($release === null) {
            return $result;
        }

        return $this->buildPluginInfoObject($release, $config);
    }

    public function clearReleaseCache(mixed $upgrader, array $options): void
    {
        if (($options['action'] ?? '') === 'update' && ($options['type'] ?? '') === 'plugin') {
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

        $releases = (new ApiClient())->latestReleases($licenseKeys);

        set_transient(
            self::TRANSIENT_KEY,
            array_map(fn($r) => $r !== null ? $r->toArray() : null, $releases),
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

    private function getConfigBySlug(string $slug): ?LicenseConfig
    {
        foreach (LicenseRegistry::getInstance()->all() as $config) {
            if ($this->pluginSlug($config) === $slug) {
                return $config;
            }
        }

        return null;
    }

    private function pluginSlug(LicenseConfig $config): string
    {
        return basename(dirname($config->pluginFile));
    }

    private function buildUpdateObject(ReleaseResponse $release, LicenseConfig $config): stdClass
    {
        $obj              = new stdClass();
        $obj->id          = 'w.org/plugins/' . $this->pluginSlug($config);
        $obj->slug        = $this->pluginSlug($config);
        $obj->plugin      = plugin_basename($config->pluginFile);
        $obj->new_version = $release->version;
        $obj->url         = '';
        $obj->package     = $release->downloadUrl ?? '';
        $obj->icons       = [];
        $obj->banners     = [];
        $obj->banners_rtl = [];
        $obj->requires    = $release->requires['php'] ?? '';
        $obj->requires_php = $release->requires['php'] ?? '';
        $obj->tested      = '';

        return $obj;
    }

    private function buildPluginInfoObject(ReleaseResponse $release, LicenseConfig $config): stdClass
    {
        $obj                = new stdClass();
        $obj->name          = $this->pluginSlug($config);
        $obj->slug          = $this->pluginSlug($config);
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
