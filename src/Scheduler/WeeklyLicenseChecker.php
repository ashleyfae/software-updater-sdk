<?php

namespace AshleyFae\SoftwareUpdater\Scheduler;

use AshleyFae\SoftwareUpdater\Exceptions\ApiRequestFailedException;
use AshleyFae\SoftwareUpdater\Http\ApiClient;
use AshleyFae\SoftwareUpdater\Registries\LicenseRegistry;

class WeeklyLicenseChecker
{
    private const CRON_HOOK     = 'software_updater_weekly_check';
    private const CRON_INTERVAL = 'software_updater_weekly';

    public function load(): void
    {
        add_filter('cron_schedules', [$this, 'registerSchedule']);
        add_action(self::CRON_HOOK, [$this, 'run']);

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    public function registerSchedule(array $schedules): array
    {
        $schedules[self::CRON_INTERVAL] = [
            'interval' => WEEK_IN_SECONDS,
            'display'  => 'Once Weekly',
        ];

        return $schedules;
    }

    public function run(): void
    {
        $configs = LicenseRegistry::getInstance()->all();
        if (empty($configs)) {
            return;
        }

        // Collect non-empty license keys, indexed by optionName for mapping results back.
        $keysByOption = [];
        foreach ($configs as $optionName => $config) {
            $key = (string) get_option($config->optionName, '');
            if (! empty($key)) {
                $keysByOption[$optionName] = $key;
            }
        }

        if (empty($keysByOption)) {
            return;
        }

        try {
            $statuses = (new ApiClient())->bulkStatus(array_values($keysByOption));
        } catch (ApiRequestFailedException $e) {
            error_log('software-updater-sdk: weekly status check failed — ' . $e->getMessage());
            return;
        }

        // Flip so we can look up optionName by licenseKey.
        $optionByKey = array_flip($keysByOption);

        foreach ($statuses as $licenseKey => $status) {
            $optionName = $optionByKey[$licenseKey] ?? null;
            if ($optionName !== null && $status !== null) {
                update_option($optionName . '_status', wp_json_encode($status->toArray()), false);
            }
        }
    }
}
