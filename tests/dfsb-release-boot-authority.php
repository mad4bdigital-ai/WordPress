<?php
/** Synthetic authority fixtures: unit behavior only; NEVER a real release certificate. */
namespace DynamicFilterSEOBridge\Diagnostics {
    final class BuildIdentity {
        public static function collect(): array { return $GLOBALS['identity']; }
    }
}
namespace {
    final class MAD4B_SCP_Provider_Contracts {
        public static function get_for_version($provider, $version) {
            return $GLOBALS['provider'] ?? array();
        }
    }
    final class MAD4B_SCP_Cross_Plugin_Deployment {
        public static function sources() {
            return $GLOBALS['sources'] ?? array();
        }
    }
    require_once dirname(__DIR__) . '/wp-content/plugins/dynamic-filter-seo-bridge/includes/Runtime/BootGuard.php';
    function check($valid, $msg) {
        if (!$valid) { throw new \RuntimeException($msg); }
    }
    $digest = str_repeat('b',64);
    $git = str_repeat('a',40);
    $version = '0.4.0-alpha.15-rc.1';
    $GLOBALS['identity'] = [
        'git_sha'=>$git, 'plugin_version'=>$version, 'package_sha256'=>$digest,
        'valid'=>true,'installed_content_valid'=>true,
        'provenance_complete'=>true, 'package_provenance_valid'=>false,
        'exact_build_reason'=>'ok',
    ];
    $GLOBALS['provider'] = [
        'plugin_file'=>'dynamic-filter-seo-bridge/dynamic-filter-seo-bridge.php',
        'version'=>$version, 'archive_sha256'=>$digest, 'require_runtime_integrity'=>true,
    ];
    $GLOBALS['sources'] = ['dynamic-filter-seo-bridge'=>[
        'id'=>'dynamic-filter-seo-bridge',
        'provider_id'=>'dynamic-filter-seo-bridge',
        'plugin_file'=>'dynamic-filter-seo-bridge/dynamic-filter-seo-bridge.php',
        'candidate_source_commit_sha'=>$git, 'source_commit_sha'=>$git,
        'candidate_artifact_version'=>$version, 'artifact_version'=>$version,
        'artifact_sha256'=>$digest,
        'review_state'=>'source_reviewed_artifact_certified',
        'package_certification'=>'certified',
    ]];
    $guard='DynamicFilterSEOBridge\\Runtime\\BootGuard';
    // A legacy environment override may not bypass a missing certificate.
    define('DFSB_FORCE_FULL_BOOT', true);
    check($guard::shouldHold(), 'unsafe forced boot was permitted without receipt');
    $executed = false;
    check(!$guard::run(static function () use (&$executed) { $executed = true; }) && !$executed,
        'uncertified release executed boot callback');
    $r=$guard::releaseReadiness();
    check(!$r['ready'] && $r['reason']==='certified_release_receipt_required',
        'missing receipt must deny even with synthetic matching provider');
    $GLOBALS['identity']['package_provenance_valid']=true;
    $r=$guard::releaseReadiness();
    check($r['ready'] && !$r['authorizing'], 'matching in-memory mock fixture must be eligible for read-only result');
    check(!$guard::shouldHold(), 'synthetic matching certificate could not pass guarded override');
    $GLOBALS['provider']['archive_sha256']=str_repeat('c',64);
    check(!$guard::releaseReadiness()['ready'], 'provider SHA mismatch allowed');
    $GLOBALS['provider']['archive_sha256']=$digest;
    $GLOBALS['sources']['dynamic-filter-seo-bridge']['source_commit_sha']=str_repeat('c',40);
    check(!$guard::releaseReadiness()['ready'], 'source SHA mismatch allowed');
    $GLOBALS['sources']['dynamic-filter-seo-bridge']['source_commit_sha']=$git;
    $GLOBALS['sources']['dynamic-filter-seo-bridge']['package_certification']='pending';
    check(!$guard::releaseReadiness()['ready'], 'unapproved source was allowed');
    $GLOBALS['sources']['dynamic-filter-seo-bridge']['package_certification']='certified';
    $GLOBALS['identity']['plugin_version']='0.4.0-alpha.14-local.1';
    check(!$guard::releaseReadiness()['ready'], 'version mismatch was allowed');
    echo "DFSB_RELEASE_AUTHORITY_MOCK_GATES: PASS\n";
}
