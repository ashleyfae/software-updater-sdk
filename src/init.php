<?php

namespace AshleyFae\SoftwareUpdater;

if (! function_exists('add_action')) {
    return;
}

Loader::instance()->registerSdk('1.0.0', __DIR__ . '/SDK.php');
