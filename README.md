# Software Updater SDK

Drop-in SDK for WordPress plugins/themes that hooks into the native update system via `software.nosegraze.com`.

Handles license registration, update checks, and weekly license status polling. Bundled inside each consuming plugin -- a loader pattern ensures only the newest version of the SDK runs when multiple plugins include it.

## Usage

```php
protected function addHooks() : void
{
    // initializes with the licensing SDK
    add_action('software_updater_sdk_loaded', [$this, 'registerLicense']);
}

public function registerLicense(SDK $sdk) : void
{
    try {
        $sdk->register(
            new PluginLicenseConfig(
                optionName: $this->optionName,
                productId: $this->productUuid,
                pluginFile: $this->pluginFile,
                version: $this->currentPluginVersion
            )
        );
    } catch(Exception $e) {
        error_log($e->getMessage());
    }
}
```

- `optionName` -- the `wp_options` key where the license key is stored.
- `productId` -- UUID for the product on software.nosegraze.com.
- `pluginFile` -- `__FILE__` of the plugin entry point (used to derive the slug/basename).
- `version` -- current installed version; compared against the latest release to decide if an update exists.

`register()` validates and stores the config in a singleton `LicenseRegistry`. It throws `InvalidLicenseConfigException` if `optionName` is empty or `productId` isn't a valid UUID.

## How Version Updates Work

### Check frequency

- **Update checks**: cached for **12 hours** via the `software_updater_releases` transient. WordPress triggers the check on dashboard/plugin page loads; the SDK intercepts `pre_set_site_transient_update_plugins` (and the theme equivalent). All registered license keys are sent in a single batched API call.
- **License status checks**: run **weekly** via WP-Cron (`software_updater_weekly_check`). Calls `bulkStatus()` and stores each result in `{optionName}_status`.
- **Cache clear**: the release transient is deleted on `upgrader_process_complete` so the next page load fetches fresh data.

### Requirements to check for updates

1. A non-empty license key must exist in the option (`get_option($config->optionName)`). No key = no API call, no update shown.
2. At least one config must be registered in `LicenseRegistry`.
3. The SDK classes must still be loadable (`isSdkAvailable()` check -- guards against the edge case where a new plugin version ships without the SDK).

### Expired / disabled licenses

The **server** controls the `downloadUrl` field in the API response. For active licenses it returns a signed URL; for expired or disabled licenses it returns `null`.

On the client side, the SDK adds an update to WordPress's `$transient->response` (i.e. shows the "update available" row) whenever the remote version is newer than the installed version -- regardless of license status.

So with an expired license:
- The user **will** see the update notice in wp-admin ("There is a new version available").
- But `package` will be empty (no download URL), so WordPress won't show the "Update Now" button.
- They need to renew to actually download the update.
