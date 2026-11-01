<?php
/**
 * Company Management: local WP-CLI read adapter through the original MAD4B Core.
 * No SSH, arbitrary CLI text, approval minting, file modification or SQL writes.
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_SCP_Company_Local_WPCLI {
    const OPERATION = 'company.code-reconciliation.plan';
    const CONTRACT = 'mad4b.company.local-wpcli-readback.v1';
    public static function boot() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('mad4b company-local-readback',array(__CLASS__,'cli_command'));
        }
    }
    private static function error($reason) {
        return new WP_Error('mad4b_company_local_wpcli_'.$reason,'Local governed WP-CLI inventory refused.');
    }
    public static function inspect($kind) {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI') ||
            !in_array($kind,array('plugins','themes'),true))
            return self::error('unsupported_operation');
        if (!class_exists('MAD4B_SCP_Policy') || !MAD4B_SCP_Policy::can_read())
            return self::error('read_permission_denied');
        if (!class_exists('MAD4B_SCP_Operational_Integrity') ||
            !class_exists('MAD4B_SCP_Operation_Registry') ||
            !class_exists('MAD4B_SCP_Operation_Pipeline'))
            return self::error('original_core_missing');
        $before=MAD4B_SCP_Operational_Integrity::capture();
        if (is_wp_error($before)) return $before;
        $scope=$before['scope']??array();
        // This adapter belongs only to the official central Staging control
        // plane, not to temporary company Staging sites. The central binding
        // is deliberately exact, even when company sites have valid profiles.
        if (($scope['environment']??'')!=='staging' ||
            ($scope['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a' ||
            ($scope['deployment_mode']??'')!=='wordpress_dedicated' ||
            home_url()!=='https://tours.dev.mad4b.com' ||
            !class_exists('MAD4B_SCP_Site_Profile') ||
            !MAD4B_SCP_Site_Profile::configured() ||
            !MAD4B_SCP_Site_Profile::origin_enrolled() ||
            !function_exists('wp_get_environment_type') ||
            wp_get_environment_type()!=='staging')
            return self::error('central_staging_scope_mismatch');
        // WP-CLI --user must resolve to the actor sealed in the Core
        // capture. wp_set_current_user() alone is not authentication.
        $selected=(string) WP_CLI::get_config('user');
        $actor_id=(int)($before['actor_user_id']??0);
        if (''===$selected || $actor_id<1 || !function_exists('get_user_by'))
            return self::error('cli_actor_unverified');
        $actor_user=ctype_digit($selected)
            ? get_user_by('id',(int)$selected)
            : get_user_by(strpos($selected,'@')!==false?'email':'login',$selected);
        if (!$actor_user || (int)$actor_user->ID!==$actor_id)
            return self::error('cli_actor_mismatch');
        $op=MAD4B_SCP_Operation_Registry::operation(self::OPERATION);
        if (is_wp_error($op) || !is_array($op) ||
            ($op['effect']??null)!=='read_only' ||
            ($op['pipeline_profile']??null)!=='governed_read_only_v1' ||
            ($op['generic_mutation_dispatch']??true)!==false)
            return self::error('operation_not_registered_or_unsafe');
        $compiled=MAD4B_SCP_Operation_Pipeline::compile(array(
            'operation'=>self::OPERATION,'target_kind'=>'company_site'));
        if (is_wp_error($compiled) || ($compiled['ready']??false)!==true ||
            ($compiled['executor']??null)!=='mad4b/company-code-reconciliation-compare' ||
            ($compiled['authority_created']??true)!==false ||
            ($compiled['mutation_performed']??true)!==false)
            return self::error('core_pipeline_not_ready');
        // Only these two exact fixed commands are allowed. No caller string is
        // interpolated into the WP-CLI invocation.
        $command=$kind==='plugins'
            ? 'plugin list --format=json --fields=name,status,version'
            : 'theme list --format=json --fields=name,status,version';
        try {
            $raw=WP_CLI::runcommand($command,array('return'=>true,'exit_error'=>false,'launch'=>false));
        } catch (Throwable $e) {
            return self::error('local_cli_failed');
        }
        if (!is_string($raw) || strlen($raw)>262144) return self::error('output_unbounded');
        $rows=json_decode($raw,true);
        if (!is_array($rows) || count($rows)>1024) return self::error('schema_invalid');
        $normalized=array();
        foreach($rows as $row) {
            if (!is_array($row) || !is_string($row['name']??null) ||
                !is_string($row['status']??null) || !is_string($row['version']??null) ||
                strlen($row['name'])>160 || strlen($row['version'])>96)
                return self::error('row_invalid');
            $normalized[]=array('name'=>$row['name'],'status'=>$row['status'],'version'=>$row['version']);
        }
        $after=MAD4B_SCP_Operational_Integrity::assert_unchanged($before,false);
        if (is_wp_error($after)) return $after;
        $encoded=wp_json_encode($normalized,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) return self::error('digest_failed');
        return array(
            'contract'=>self::CONTRACT,'operation'=>self::OPERATION,
            'origin'=>home_url(),'site_uuid'=>$scope['site_uuid'],
            'environment'=>'staging','kind'=>$kind,
            'items_count'=>count($normalized),'items_sha256'=>hash('sha256',$encoded),
            'core_scope_fingerprint'=>$before['fingerprint'],
            'core_read_permission_verified'=>true,
            'original_core_pipeline_verified'=>true,
            'post_read_scope_verified'=>true,
            'transport'=>'local_wp_cli_only',
            'remote_ssh_executed'=>false,'signed_inventory_issued'=>false,
            'complete_inventory_coverage_verified'=>false,
            'authorizing'=>false,'mutation_performed'=>false,
        );
    }
    public static function cli_command($args,$assoc_args) {
        if ($args || !is_array($assoc_args) || array_diff(array_keys($assoc_args),array('kind'))) {
            WP_CLI::error('Only --kind=plugins or --kind=themes is supported.');
        }
        $value=self::inspect($assoc_args['kind']??'');
        if (is_wp_error($value)) WP_CLI::error($value->get_error_code());
        WP_CLI::line(wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
}
