<?php
defined('ABSPATH') || exit;
return [
    'id' => 'hub',
    'label' => 'Hub Integration (legacy capabilities)',
    'version' => '0.5.0-migrated',
    'default_enabled' => false,
    'dependencies' => [],
    'boot' => static function (): void {
        require_once __DIR__ . '/legacy/mad4b-jetengine-hub-addon.php';
    },
];
