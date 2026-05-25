# software-updater-sdk — Architecture Plan

**Package**: `ashleyfae/software-updater-sdk`  
**Namespace**: `AshleyFae\SoftwareUpdater`  
**PHP minimum**: 8.0  
**Base API URL**: `https://software.nosegraze.com`

---

## Purpose

A Composer package included in WordPress plugins to replace the old EDD (Easy Digital Downloads) licensing + update system. Integrates with the public API of the `software` Laravel app (`software.nosegraze.com`).

API endpoints consumed:
- `POST   /api/licenses/status` — bulk status check (keyed by license key)
- `POST   /api/licenses/{key}/activations` — activate a license for a URL
- `DELETE /api/licenses/{key}/activations` — deactivate a license for a URL
- `GET    /api/products/releases/latest?license_keys[]=...` — bulk update info

---

## Versioning Conflict Prevention

Multiple plugins may bundle different versions of this SDK. Only one version should actually run. We use the same pattern as `ashleyfae/contextwp-sdk`:

1. `src/init.php` is declared in Composer's `autoload.files` — it runs whenever any plugin includes its `vendor/autoload.php`
2. Every `init.php` calls `Loader::instance()->registerSdk('x.y.z', __DIR__.'/SDK.php')`
3. `Loader` is a **globally shared singleton** — whichever plugin's autoloader happens to define the PHP class first "wins"; subsequent plugins call `registerSdk()` on that same instance
4. On `after_setup_theme` (priority `PHP_INT_MAX`), the Loader compares all registered versions and `require_once`s only the latest `SDK.php`
5. **`Loader.php` must never change its public API** (`instance()`, `registerSdk()`). It is a stable outer shell. All version-specific logic lives in `SDK.php` and below.

---

## File Structure

```
src/
├── init.php                                # autoloaded; calls Loader::instance()->registerSdk(...)
├── Loader.php                              # STABLE — version-conflict resolution singleton
├── SDK.php                                 # main facade singleton; loaded only for winning version
│
├── DataTransferObjects/
│   ├── LicenseConfig.php                   # INPUT DTO — what a plugin registers with the SDK
│   ├── LicenseStatusResponse.php           # OUTPUT DTO — from bulkStatus / show endpoints
│   ├── ActivationResponse.php              # OUTPUT DTO — from activate endpoint
│   └── ReleaseResponse.php                 # OUTPUT DTO — from releases/latest endpoint
│
├── Enums/
│   └── LicenseStatus.php                   # active | expired | disabled (mirrors server enum)
│
├── Exceptions/
│   ├── ApiException.php
│   └── InvalidLicenseConfigException.php
│
├── Http/
│   └── ApiClient.php                       # all wp_remote_* calls; always returns DTOs, never arrays
│
├── License/
│   └── LicenseManager.php                  # activate(), deactivate(), getStatus() for one config
│
├── Registries/
│   └── LicenseRegistry.php                 # singleton; holds all registered LicenseConfig instances
│
├── Scheduler/
│   └── WeeklyLicenseChecker.php            # WP cron; ONE bulkStatus request for all registered keys
│
└── Updater/
    └── UpdateChecker.php                   # WP update hooks; ONE releases/latest request for all keys
```

The SDK has **no WordPress admin UI classes**. Settings fields, form submission handlers, admin notices, and capability checks are entirely the consuming plugin's responsibility. The SDK only provides the API + infrastructure layer.

---

## DTOs

### LicenseConfig (input)

```php
new LicenseConfig(
    optionName: 'novelist_review_excerpts_license_key',  // WP option holding the license key
    productId:  'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',  // product UUID from software app
    pluginFile: __FILE__,                                // for plugin_basename()
    version:    NOVELIST_REVIEW_EXCERPTS_VERSION,        // current plugin version
)
```

The SDK reads the actual license key from `get_option($config->optionName)` at call time. The raw key is never passed at registration. `productId` is sent as `product_id` in the activate request body — required by the server to validate that the license key belongs to the stated product.

### LicenseStatusResponse (output)

Properties: `licenseKey`, `status` (LicenseStatus enum), `expiresAt` (?DateTimeInterface), `bundleId` (?int), `siteActivations` (ActivationResponse[])

### ActivationResponse (output)

Properties: `domain`, `createdAt`, `updatedAt`

### ReleaseResponse (output)

Properties: `licenseKey`, `licenseStatus` (LicenseStatus), `expiresAt`, `productSlug`, `version`, `description`, `preRelease`, `requires` (?array), `requirementsMet` (bool), `unmetRequirements` (?array), `updatedAt`, `downloadUrl` (?string — only present when requirementsMet)

---

## Registration & Usage

```php
// In plugin boot (plugins_loaded or software_updater_sdk_loaded action):
\AshleyFae\SoftwareUpdater\SDK::instance()->register(
    new LicenseConfig(
        optionName: 'novelist_review_excerpts_license_key',
        pluginFile: __FILE__,
        version:    NOVELIST_REVIEW_EXCERPTS_VERSION,
    )
);
```

Activate / deactivate (plugin still owns WP nonce + permission checks, then delegates):
```php
// No URL parameter — SDK calls home_url() internally
$result = SDK::instance()->license('novelist_review_excerpts_license_key')->activate();
// $result is ActivationResponse

SDK::instance()->license('novelist_review_excerpts_license_key')->deactivate();
```

`SDK::instance()->license($optionName)` returns a `LicenseManager` bound to the config registered under that option name.

---

## Backwards Compatibility — Mixed Old/New Novelist Add-ons

A user may run addon #1 (old EDD) alongside addon #2 (new SDK) simultaneously.

**Problem**: All old Novelist add-ons include `Novelist_License` with an `if (!class_exists('Novelist_License'))` guard. Whichever add-on loads first "wins" the class definition, and the other gets the wrong implementation.

**Solution**: New SDK-based add-ons **never define `Novelist_License`** at all. Each updated plugin has its own uniquely-named admin class (e.g. `Novelist_Review_Excerpts_License`) that handles its own WP hooks and calls SDK methods directly. Result:

- Un-updated add-on loads → defines `Novelist_License` (old EDD version) → works as before
- Updated add-on loads → uses its own uniquely-named class, calls SDK → works with new API
- Both active simultaneously → no class conflict whatsoever

The old `novelist_{shortname}_license_active` WP option may contain stale EDD data but is never read by the new code. It is left in place — harmless.

---

## WP Options Strategy

| Data | Option name | Owner |
|------|-------------|-------|
| License key | `{optionName}` e.g. `novelist_review_excerpts_license_key` | Plugin (same as EDD — no migration) |
| License status cache | `{optionName}_status` e.g. `novelist_review_excerpts_license_key_status` | SDK |
| Old EDD status | `novelist_{shortname}_license_active` | Left alone, ignored by new code |

The status option stores a serialized `LicenseStatusResponse`. The SDK derives the status option name from the config's `optionName` by appending `_status` — consistent and predictable without any separate configuration.

---

## Batching

### Weekly License Check

- SDK registers a custom WP cron interval `software_updater_weekly` (7 days) and event `software_updater_weekly_check`
- On fire: `WeeklyLicenseChecker` reads all configs from `LicenseRegistry`, collects non-empty license keys, sends **one** `POST /api/licenses/status` request, writes each result to the corresponding `{optionName}_status` WP option
- No dependency on any parent plugin's cron events

### Plugin Update Check

- `UpdateChecker` hooks `pre_set_site_transient_update_plugins`
- Collects all license keys from `LicenseRegistry`, sends **one** `GET /api/products/releases/latest?license_keys[]=...` request with current `php_version` + `wp_version`
- Stores results in a WP transient keyed by `software_updater_releases`
- Also hooks `plugins_api` to serve the detailed update dialog from the cached data

---

## Consuming Plugin Changes (novelist-review-excerpts)

### Remove
- `includes/updater/class-novelist-license.php`
- `includes/updater/EDD_SL_Plugin_Updater.php`
- The `includes/updater/` directory entirely

### Add
- `composer.json` requiring `ashleyfae/software-updater-sdk`
- `vendor/` (gitignored, added to deploy artifact)

### Add `includes/updater/class-novelist-review-excerpts-license.php`

A thin plugin-specific class (NOT named `Novelist_License`) that owns all WP admin hooks and delegates to the SDK. Example structure:

```php
class Novelist_Review_Excerpts_License {
    private string $optionName = 'novelist_review_excerpts_license_key';

    public function __construct() {
        add_filter( 'novelist/settings/licenses', [ $this, 'settings' ] );
        add_action( 'admin_init', [ $this, 'handleActivation' ] );
        add_action( 'admin_init', [ $this, 'handleDeactivation' ] );
        add_action( 'admin_notices', [ $this, 'notices' ] );
        add_action( 'in_plugin_update_message-' . plugin_basename( __FILE__ ), [ $this, 'updateMessage' ], 10, 2 );
    }

    public function handleActivation(): void {
        // nonce + capability checks, then:
        $result = \AshleyFae\SoftwareUpdater\SDK::instance()
            ->license( $this->optionName )
            ->activate();
        // $result is ActivationResponse or null on failure
    }

    public function handleDeactivation(): void {
        // nonce + capability checks, then:
        \AshleyFae\SoftwareUpdater\SDK::instance()
            ->license( $this->optionName )
            ->deactivate();
    }

    public function notices(): void {
        $status = \AshleyFae\SoftwareUpdater\SDK::instance()
            ->license( $this->optionName )
            ->getStatus(); // reads from cached WP option, no API call
        // render notice if $status->status !== LicenseStatus::Active
    }
}
```

### Modify `novelist-review-excerpts.php` `hooks()` method

Replace:
```php
if ( ! class_exists( 'Novelist_License' ) ) {
    require_once NOVELIST_REVIEW_EXCERPTS_PLUGIN_DIR . 'includes/updater/class-novelist-license.php';
}
$novelist_license = new Novelist_License( __FILE__, 'Review Excerpts', NOVELIST_REVIEW_EXCERPTS_VERSION, 'Ashley Gibson', 'novelist_review_excerpts_license_key' );
```

With:
```php
$config = new \AshleyFae\SoftwareUpdater\DataTransferObjects\LicenseConfig(
    optionName: 'novelist_review_excerpts_license_key',
    productId:  'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    pluginFile: __FILE__,
    version:    NOVELIST_REVIEW_EXCERPTS_VERSION,
);

\AshleyFae\SoftwareUpdater\SDK::instance()->register( $config );

require_once NOVELIST_REVIEW_EXCERPTS_PLUGIN_DIR . 'includes/updater/class-novelist-review-excerpts-license.php';
new Novelist_Review_Excerpts_License();
```

---

## Open Questions (resolved)

- `activate()` takes no parameters — SDK calls `home_url()` internally ✓
- `Novelist_License` is not redefined in new add-ons — each updated plugin uses its own uniquely-named class ✓
- No `LicenseAdminHandler` in the SDK — admin UI is entirely the consuming plugin's responsibility ✓
- PHP minimum: 8.0 (named arguments, enums, etc.) ✓
- Namespace: `AshleyFae\SoftwareUpdater` ✓
- Status option: new `{optionName}_status` — old `_license_active` option left untouched ✓
