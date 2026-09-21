<?php
namespace ETG\DynamicFilterSEOBridge\Acceptance;

final class BrowserObserverAsset {
    const CONTRACT = 'etg.dfsb.browser-observer-asset.v1';
    const OBSERVER_CONTRACT = 'etg.dfsb.browser-acceptance-observer.v1';
    const PROVIDER_ID = 'etg-dfsb';
    const RELATIVE_PATH = 'assets/js/browser-acceptance-observer.js';

    public static function register(): void {
        if (!function_exists('add_filter')) return;
        add_filter('mad4b_browser_acceptance_providers', array(__CLASS__, 'decorateProviders'), 20, 1);
    }

    public static function decorateProviders($providers): array {
        $providers = is_array($providers) ? $providers : array();
        if (!isset($providers[self::PROVIDER_ID]) || !is_array($providers[self::PROVIDER_ID])) return $providers;

        $provider = $providers[self::PROVIDER_ID];
        $original = $provider['capabilities_callback'] ?? null;
        if (!is_callable($original)) return $providers;

        $provider['capabilities_callback'] = static function () use ($original): array {
            try {
                $capabilities = call_user_func($original);
            } catch (\Throwable $error) {
                $capabilities = array('error'=>'provider_capabilities_exception');
            }
            $capabilities = is_array($capabilities) ? $capabilities : array('error'=>'provider_capabilities_invalid');
            $capabilities['observer_asset'] = self::describe();
            return $capabilities;
        };
        $providers[self::PROVIDER_ID] = $provider;
        return $providers;
    }

    public static function describe(): array {
        $base = defined('ETG_DFSB_DIR') ? (string)ETG_DFSB_DIR : '';
        $file = '' !== $base ? $base . self::RELATIVE_PATH : '';
        $pluginFile = '' !== $base ? $base . 'etg-dynamic-filter-seo-bridge.php' : '';
        $identity = class_exists('ETG\\DynamicFilterSEOBridge\\Diagnostics\\BuildIdentity')
            ? \ETG\DynamicFilterSEOBridge\Diagnostics\BuildIdentity::collect()
            : array('valid'=>false);

        $reasons = array();
        if ('' === $file || !is_readable($file) || !is_file($file)) $reasons[] = 'observer_asset_unreadable';
        $sha = empty($reasons) ? @hash_file('sha256', $file) : false;
        if (!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/', $sha)) $reasons[] = 'observer_asset_hash_unavailable';
        $bytes = empty($reasons) ? @filesize($file) : false;
        if (!is_int($bytes) || $bytes < 1 || $bytes > 262144) $reasons[] = 'observer_asset_size_invalid';

        $url = '';
        if (function_exists('plugins_url') && '' !== $pluginFile) {
            $url = (string)plugins_url(self::RELATIVE_PATH, $pluginFile);
        }
        if ('' === $url) $reasons[] = 'observer_asset_url_unavailable';

        $sameOrigin = self::sameOrigin($url);
        if (!$sameOrigin) $reasons[] = 'observer_asset_not_same_origin';
        if (empty($identity['valid']) || empty($identity['git_sha']) || empty($identity['tree_sha'])) {
            $reasons[] = 'observer_asset_build_identity_unavailable';
        }

        $reasons = array_values(array_unique($reasons));
        return array(
            'contract'=>self::CONTRACT,
            'observer_contract'=>self::OBSERVER_CONTRACT,
            'provider_id'=>self::PROVIDER_ID,
            'available'=>empty($reasons),
            'same_origin'=>$sameOrigin,
            'authorizing'=>false,
            'arbitrary_javascript'=>false,
            'auto_enqueued'=>false,
            'load_mode'=>'external_browser_agent_same_origin_asset',
            'snapshot_method'=>'snapshot',
            'full_digest_snapshot_method'=>'snapshotAsync',
            'max_digest_ids'=>5000,
            'web_crypto_required_for_full_digest'=>true,
            'url'=>$url,
            'sha256'=>is_string($sha)?$sha:'',
            'bytes'=>is_int($bytes)?$bytes:0,
            'plugin_version'=>defined('ETG_DFSB_VERSION')?(string)ETG_DFSB_VERSION:'',
            'build_identity'=>array(
                'git_sha'=>(string)($identity['git_sha']??''),
                'tree_sha'=>(string)($identity['tree_sha']??''),
            ),
            'blocking_reasons'=>$reasons,
        );
    }

    private static function sameOrigin(string $url): bool {
        if ('' === trim($url) || !function_exists('home_url')) return false;
        $home = (string)home_url('/');
        $target = parse_url($url);
        $origin = parse_url($home);
        if (!is_array($target) || !is_array($origin)) return false;
        foreach (array('scheme','host') as $part) {
            if (strtolower((string)($target[$part]??'')) !== strtolower((string)($origin[$part]??''))) return false;
        }
        $targetPort = isset($target['port']) ? (int)$target['port'] : self::defaultPort((string)($target['scheme']??''));
        $originPort = isset($origin['port']) ? (int)$origin['port'] : self::defaultPort((string)($origin['scheme']??''));
        return $targetPort === $originPort;
    }

    private static function defaultPort(string $scheme): int {
        return 'https' === strtolower($scheme) ? 443 : ('http' === strtolower($scheme) ? 80 : 0);
    }
}
