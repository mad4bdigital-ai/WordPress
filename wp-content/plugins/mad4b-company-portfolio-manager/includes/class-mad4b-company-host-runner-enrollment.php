<?php
/**
 * Original Company Manager Host Runner Enrollment, read-only issuer preflight.
 *
 * PERMANENT CONTRACT:
 * - Dynamically discover the original Host Ledger and installed Runner releases.
 * - An installed package and an enrolled host are NOT an enrolled node.
 * - Never issue a fabricated signing key, node ID, cluster, receipt or grant.
 * - Original Host-side enrollment authority consumes an existing single-use
 *   envelope; this WordPress inspection cannot mint an envelope.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Host_Runner_Enrollment_V1 {
    const CONTRACT='mad4b.company-host-runner-enrollment-preflight.v1';

    public static function installed_releases():array {
        if(!defined('WP_CONTENT_DIR'))return [];
        $base=realpath(WP_CONTENT_DIR);
        if(!$base)return [];
        $root=$base.'/mad4b-runner/bin';
        if(!is_dir($root)||is_link($root))return [];
        $list=[];
        foreach((array)glob($root.'/*/RUNNER-INSTALL-MANIFEST.json') as $filename){
            if(is_link($filename)||!is_file($filename)||filesize($filename)>65536)
                continue;
            $dir=dirname($filename);
            if(is_link($dir))continue;
            $real=realpath($dir);
            if(!$real||strpos($real,$root.'/')!==0)continue;
            $release=basename($real);
            if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/D',$release))continue;
            $manifest=json_decode((string)file_get_contents($filename),true);
            if(!is_array($manifest)
              ||($manifest['contract']??'')!=='mad4b.runner-install-manifest.v1')continue;
            $package=$manifest['package']??null;
            if(!is_array($package)||($package['contract']??'')!=='mad4b.runner-package.v1'
              ||($package['version']??'')!==$release)continue;
            $source=$real.'/mad4b_host_runner.py';
            if(is_link($source)||!is_file($source))continue;
            $sha=hash_file('sha256',$source);
            if(!is_string($sha)||!hash_equals((string)($package['entrypoint_sha256']??''),$sha)
              ||!hash_equals((string)($manifest['entrypoint_sha256']??''),$sha))continue;
            $scheduler=$real.'/RUNNER-SCHEDULER.json';
            $sch=is_file($scheduler)&&!is_link($scheduler)
              ?json_decode((string)file_get_contents($scheduler),true):null;
            $supports=in_array('mad4b.host-node-inventory-observation.v1',
              (array)($package['supported_operation_contracts']??[]),true);
            if(!$supports)continue;
            $list[$release]=[
                'version'=>$release,'entrypoint_sha256'=>$sha,
                'package_sha256'=>(string)($package['package_sha256']??''),
                'original_manifest_verified'=>true,
                'scheduler_registration_applied'=>(bool)($sch['registration_applied']??false),
                'enrollment_proven'=>false,
            ];
        }
        ksort($list,SORT_NATURAL);
        return $list;
    }

    public static function status(string $company,string $environment):array {
        $base=['contract'=>self::CONTRACT,'company'=>$company,
          'environment'=>$environment,'state'=>'ORIGINAL_COMPANY_SITE_NOT_VERIFIED',
          'site_uuid'=>null,'host_id'=>null,
          'enrolled_host_verified'=>false,'node_enrollment_verified'=>false,
          'supported_installed_runner_releases'=>[],
          'host_issuer_available'=>false,'enrollment_issued'=>false,
          'scheduler_registered'=>false,'authorizing'=>false,
          'mutation_performed'=>false,'production_authorized'=>false];
        if(!class_exists('MAD4B_Company_Host_Topology_Registry_V1',false)
          ||!class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
          ||!MAD4B_Company_Portfolio_Manager_V1::staging()
          ||$environment!=='staging')return $base;
        $target=MAD4B_Company_Host_Topology_Registry_V1::target($company,$environment);
        if(!isset($target['site_uuid'])||!isset($target['canonical_origin']))
            return $base;
        $base['site_uuid']=$target['site_uuid'];
        // A remote site's installed files must be inspected on its OWN Host,
        // not inferred from the central WordPress installation.
        $self=($target['role']??'')==='self_hosted_demo_target'
          &&untrailingslashit((string)$target['canonical_origin'])===untrailingslashit(home_url('/'));
        if(!$self){
            $base['state']='REMOTE_HOST_ORIGINAL_NATIVE_REGISTRATION_REQUIRED';
            return $base;
        }
        if(!class_exists('MAD4B_Native_VPS_Principal_V1',false))
            return $base;
        $scope=MAD4B_Native_VPS_Principal_V1::scope();
        $principal=MAD4B_Native_VPS_Principal_V1::existing();
        if(!is_array($scope)||!is_array($principal)
          ||($scope['state']??'')!=='CENTRAL_HOST_IDENTITY_VERIFIED'
          ||($scope['site_uuid']??'')!==$target['site_uuid']
          ||($scope['workload']??'')!==$company
          ||($scope['environment']??'')!==$environment
          ||!MAD4B_Native_VPS_Principal_V1::ledger($principal,$scope)){
            $base['state']='ORIGINAL_CENTRAL_HOST_LEDGER_NOT_VERIFIED';
            return $base;
        }
        $base['host_id']=(string)($scope['host_id']??'');
        $base['enrolled_host_verified']=true;
        $base['supported_installed_runner_releases']=self::installed_releases();
        $base['state']=$base['supported_installed_runner_releases']
          ?'ORIGINAL_HOST_VERIFIED_NODE_ENROLLMENT_ISSUER_PENDING'
          :'ORIGINAL_HOST_VERIFIED_RUNNER_PACKAGE_MISSING';
        // This does not infer a node identity, an HMAC issuer or a scheduler.
        return $base;
    }
    public static function rest():void {
        if(!class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
          ||!MAD4B_Company_Portfolio_Manager_V1::staging())return;
        register_rest_route('mad4b/v1',
          '/company-management/companies/(?P<company>[a-z][a-z0-9-]{2,47})/environments/(?P<environment>staging)/host-runner-enrollment-preflight',[
          'methods'=>'GET','permission_callback'=>static function(){
              return current_user_can('manage_options');
          },
          'callback'=>static function($request){
              $r=rest_ensure_response(self::status((string)$request['company'],(string)$request['environment']));
              $r->header('Cache-Control','private, no-store');
              return $r;
          }]);
    }
    public static function cli($args,$assoc):void {
        $company=(string)($assoc['company']??'');
        $env=(string)($assoc['environment']??'');
        if(($args[0]??'status')!=='status'||!current_user_can('manage_options'))
            WP_CLI::error('EXACT_ORIGINAL_OWNER_READ_REQUIRED');
        WP_CLI::line(wp_json_encode(self::status($company,$env),JSON_UNESCAPED_SLASHES));
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'rest'],90);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b company-host-runner-enrollment',[__CLASS__,'cli']);
    }
}
MAD4B_Company_Host_Runner_Enrollment_V1::boot();
