<?php
/** Synthetic authority fixtures: unit behavior only; never a real release certificate. */
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
    $guard='DynamicFilterSEOBridge\Runtime\BootGuard';
    // A legitimate local build with a valid installed manifest may boot
    // without a detached release receipt or an independent provider certificate.
    define('DFSB_FORCE_FULL_BOOT', true);
    $r=$guard::releaseReadiness();
    check($r['ready'] && !$r['certification_required'] && !$r['authorizing'],
        'optional certification incorrectly blocked a valid installed tree');
    check(!$guard::shouldHold(), 'valid development build was held despite runtime override');
    $executed=false;
    check($guard::run(static function () use (&$executed) { $executed=true; }) && $executed,
        'valid installed tree could not boot');
    // Tampering or identity inconsistency must still prevent a forced boot.
    $GLOBALS['identity']['installed_content_valid']=false;
    check(!$guard::releaseReadiness()['ready'] && $guard::shouldHold(),
        'invalid installed content was allowed');
    $executed=false;
    check(!$guard::run(static function () use (&$executed) { $executed=true; }) && !$executed,
        'invalid installed content executed bootstrap');
    $GLOBALS['identity']['installed_content_valid']=true;
    $GLOBALS['identity']['valid']=false;
    check(!$guard::releaseReadiness()['ready'], 'invalid embedded identity was allowed');
    $GLOBALS['identity']['valid']=true;
    $GLOBALS['identity']['provenance_complete']=false;
    check(!$guard::releaseReadiness()['ready'], 'invalid explicit receipt was allowed');
    $GLOBALS['identity']['provenance_complete']=true;
    // Opt-in strict certification preserves all historic provider/receipt gates.
    define('DFSB_REQUIRE_RELEASE_CERTIFICATION', true);
    $r=$guard::releaseReadiness();
    check(!$r['ready'] && $r['certification_required'] &&
        $r['reason']==='certified_release_receipt_required',
        'strict mode permitted missing detached release receipt');
    $GLOBALS['identity']['package_provenance_valid']=true;
    check($guard::releaseReadiness()['ready'], 'matching strict certificate was denied');
    $GLOBALS['provider']['archive_sha256']=str_repeat('c',64);
    check(!$guard::releaseReadiness()['ready'], 'strict mode ignored provider digest mismatch');
    $GLOBALS['provider']['archive_sha256']=$digest;
    $GLOBALS['sources']['dynamic-filter-seo-bridge']['source_commit_sha']=str_repeat('c',40);
    check(!$guard::releaseReadiness()['ready'], 'strict mode ignored source mismatch');
    $GLOBALS['sources']['dynamic-filter-seo-bridge']['source_commit_sha']=$git;
    $GLOBALS['sources']['dynamic-filter-seo-bridge']['package_certification']='pending';
    check(!$guard::releaseReadiness()['ready'], 'strict mode ignored provider certification');
    $GLOBALS['sources']['dynamic-filter-seo-bridge']['package_certification']='certified';
    $GLOBALS['identity']['plugin_version']='0.4.0-alpha.14-local.1';
    check(!$guard::releaseReadiness()['ready'], 'strict mode ignored version mismatch');
    echo "DFSB_OPTIONAL_CERTIFICATION_AND_STRICT_OPT_IN: PASS\n";
}
