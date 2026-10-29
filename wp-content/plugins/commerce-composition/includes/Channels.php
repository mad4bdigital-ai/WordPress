<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Pure channel policy. All service identifiers are data; there is no hardcoded CPT catalogue.
 * The existing options-page values are read by the WordPress integration, never copied or rewritten.
 */
final class Channels
{
    private const MODES = [
        'standalone_and_tour' => ['standalone' => true, 'package' => true, 'discovery' => true],
        'standalone_and_package' => ['standalone' => true, 'package' => true, 'discovery' => true],
        'tour_only' => ['standalone' => false, 'package' => true, 'discovery' => true],
        'package_only' => ['standalone' => false, 'package' => true, 'discovery' => true],
        'standalone_only' => ['standalone' => true, 'package' => false, 'discovery' => true],
        'inquiry_only' => ['standalone' => false, 'package' => false, 'discovery' => true],
        'disabled' => ['standalone' => false, 'package' => false, 'discovery' => false],
    ];
    public static function mode(array $options, string $service): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,79}$/D', $service)) return 'disabled';
        $direct = $options[$service . '_channel_mode'] ?? null;
        $indirect = $options['service_channel_modes'] ?? null;
        $value = $direct !== null && $direct !== '' ? $direct :
            (is_array($indirect) ? ($indirect[$service] ?? null) : null);
        if ($value !== null && $value !== '') {
            return is_string($value) && isset(self::MODES[$value]) ? $value : 'disabled';
        }
        // The legacy settings page describes active services, not what a package includes.
        $active = $options['active_services'] ?? [];
        return is_array($active) && in_array($service, $active, true) ? 'standalone_and_tour' : 'disabled';
    }
    public static function allowed(array $options, string $service, string $channel): bool
    {
        if (!in_array($channel, ['standalone', 'package', 'discovery'], true)) return false;
        return self::MODES[self::mode($options, $service)][$channel];
    }
    /**
     * Activation check for future catalogue expansion. Explicit channel mode overrides
     * legacy active_services. Warn rather than silently broadening permissions.
     */
    public static function diagnostics(array $options, array $serviceIds): array
    {
        $result = [];
        $active = $options['active_services'] ?? [];
        foreach (self::capabilities($options,$serviceIds) as $service=>$cap) {
            $legacy = is_array($active) && in_array($service,$active,true);
            $warnings = [];
            if ($legacy && !$cap['standalone']) $warnings[] = 'legacy_enabled_but_standalone_denied_by_explicit_mode';
            if (!$legacy && $cap['standalone']) $warnings[] = 'standalone_allowed_without_legacy_activation';
            $result[$service] = ['legacy_active'=>$legacy,'effective_mode'=>$cap['mode'],
                'standalone'=>$cap['standalone'],'package'=>$cap['package'],
                'discovery'=>$cap['discovery'],'warnings'=>$warnings];
        }
        return $result;
    }
    public static function capabilities(array $options, array $serviceIds): array
    {
        $result = [];
        foreach ($serviceIds as $service) {
            if (!is_string($service) || !preg_match('/^[a-z][a-z0-9_-]{0,79}$/D', $service)) continue;
            $mode = self::mode($options, $service);
            $result[$service] = ['mode'=>$mode] + self::MODES[$mode];
        }
        return $result;
    }
}
