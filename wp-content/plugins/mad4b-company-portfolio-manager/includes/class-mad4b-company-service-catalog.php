<?php
/**
 * Central Company Management: independent company-specific catalog plans.
 * Explicitly not a remote provider activation or production write grant.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Service_Catalog_V1 {
    public const CONTRACT='mad4b.company-service-catalog.v1';
    public const STORE='mad4b_company_service_catalog_modes_v1';
    public const MODES=['tour_only','full_catalog'];
    public const OWNERS=['tours','properties','transportations','visas'];

    public static function staging(): bool {
        return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            && MAD4B_Company_Portfolio_Manager_V1::staging();
    }
    private static function error(string $code,string $message,int $http=409): WP_Error {
        return new WP_Error('mad4b_catalog_'.$code,$message,['status'=>$http]);
    }
    private static function admin(): bool {
        return self::staging() && current_user_can('manage_options') && current_user_can('activate_plugins');
    }
    public static function permission() {
        return self::admin() ? true:self::error('admin_required','Central Staging administrator required',403);
    }
    private static function binding(string $code) {
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))
            return self::error('company_invalid','Invalid company code',422);
        $b=MAD4B_Company_Portfolio_Manager_V1::company_binding($code);
        if(is_wp_error($b))return $b;
        if(!is_array($b)||($b['company_code']??'')!==$code
            ||!wp_is_uuid((string)($b['company_site_uuid']??''))
            ||!preg_match('/^[a-f0-9]{64}$/D',(string)($b['binding_sha256']??'')))
            return self::error('binding_invalid','Verified company site binding required',403);
        return $b;
    }
    public static function matrix(string $mode): array {
        if(!in_array($mode,self::MODES,true))return [];
        $result=[];
        foreach(self::OWNERS as $service)
            $result[$service]=($service==='tours'||$mode==='full_catalog')
                ? 'standalone_and_tour':'tour_only';
        return $result;
    }
    private static function records() {
        $x=get_option(self::STORE,[]);
        return is_array($x)?$x:self::error('store_invalid','Company catalog registry invalid',503);
    }
    public static function companies(): array {
        if(!self::staging())return [];
        $x=get_option(MAD4B_Company_Portfolio_Manager_V1::REGISTRY,[]);
        $codes=is_array($x)?array_keys($x):[];
        if(class_exists('MAD4B_Native_VPS_Principal_V1',false)) {
            $scope=MAD4B_Native_VPS_Principal_V1::scope();
            if(is_array($scope)&&($scope['scope_kind']??'')==='central_native_host'
                &&($scope['company_target']??true)===false) {
                $candidate=$scope['workload']??null;
                if(is_string($candidate)&&preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$candidate)
                    &&!is_wp_error(self::binding($candidate)))$codes[]=$candidate;
            }
        }
        $codes=array_values(array_unique($codes));
        sort($codes,SORT_STRING);
        return $codes;
    }
    public static function current(string $code) {
        if(!self::staging())return self::error('environment','Central Staging only',403);
        $b=self::binding($code);
        if(is_wp_error($b))return $b;
        $items=self::records();
        if(is_wp_error($items))return $items;
        $row=$items[$code]??[
            'contract'=>self::CONTRACT,'company_code'=>$code,
            'company_site_uuid'=>$b['company_site_uuid'],
            'binding_sha256'=>$b['binding_sha256'],
            'mode'=>'tour_only','revision'=>0,'source'=>'safe_default',
            'updated_at'=>null,'updated_by'=>null,'history'=>[]
        ];
        if(!is_array($row)||($row['contract']??'')!==self::CONTRACT
            ||($row['company_code']??'')!==$code
            ||($row['company_site_uuid']??'')!==$b['company_site_uuid']
            ||!is_string($row['binding_sha256']??null)
            ||!hash_equals($b['binding_sha256'],$row['binding_sha256'])
            ||!in_array($row['mode']??null,self::MODES,true)
            ||!is_int($row['revision']??null)||$row['revision']<0)
            return self::error('binding_drift','Company policy binding changed; reconciliation required',409);
        $row['target_channels']=self::matrix($row['mode']);
        $row['site_activation_applied']=false;
        $row['provider_activation_granted']=false;
        $row['tenant_write_authorized']=false;
        return $row;
    }
    public static function save(string $code,int $revision,string $mode) {
        $p=self::permission();if(is_wp_error($p))return $p;
        if($revision<0||!in_array($mode,self::MODES,true))
            return self::error('invalid_mode','Expected revision and catalog mode required',422);
        $b=self::binding($code);if(is_wp_error($b))return $b;
        global $wpdb;
        $lock='mad4b:company:catalog:'.substr(hash('sha256',$code),0,25);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return self::error('busy','Catalog update busy',423);
        try {
            $before=self::current($code);if(is_wp_error($before))return $before;
            if($before['revision']!==$revision)
                return self::error('revision_stale','Company catalog changed; reload',409);
            $items=self::records();if(is_wp_error($items))return $items;
            // Explicit Save must persist the company's own settings even
            // when the selected mode equals the inherited safe default.
            if($before['mode']===$mode && array_key_exists($code,$items))return $before;
            $history=(array)($before['history']??[]);
            $history[]=['revision'=>$revision+1,'from'=>$before['mode'],
                'to'=>$mode,'actor_id'=>get_current_user_id(),'at'=>gmdate('c')];
            $items[$code]=[
                'contract'=>self::CONTRACT,'company_code'=>$code,
                'company_site_uuid'=>$b['company_site_uuid'],
                'binding_sha256'=>$b['binding_sha256'],
                'mode'=>$mode,'revision'=>$revision+1,
                'source'=>'company_management',
                'updated_at'=>gmdate('c'),'updated_by'=>get_current_user_id(),
                'history'=>array_slice($history,-20)
            ];
            if(!update_option(self::STORE,$items,false))
                return self::error('write_failed','Catalog settings not saved',503);
            $check=self::current($code);
            if(is_wp_error($check)||$check['revision']!==$revision+1||$check['mode']!==$mode)
                return self::error('readback_failed','Catalog readback mismatch',503);
            do_action('mad4b_company_service_catalog_plan_saved',$code,$check);
            return $check;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    public static function rest_routes():void {
        if(!self::staging())return;
        register_rest_route('mad4b/v1',
            '/company-management/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})/service-catalog',[
              ['methods'=>'GET','permission_callback'=>[__CLASS__,'permission'],
               'callback'=>static fn($r)=>self::response(self::current((string)$r['company_code']))],
              ['methods'=>'POST',
               'permission_callback'=>[MAD4B_Company_Portfolio_Manager_V1::class,'rest_write_permission'],
               'callback'=>static function($r){
                   $v=$r->get_json_params();
                   if(!is_array($v)||!is_int($v['expected_revision']??null)
                       ||!is_string($v['mode']??null))
                       return self::response(self::error('payload','Expected revision and mode required',422));
                   return self::response(self::save((string)$r['company_code'],
                       $v['expected_revision'],$v['mode']));
               }]
            ]);
    }
    private static function response($result) {
        $response=rest_ensure_response($result);
        if($response instanceof WP_REST_Response)
            $response->header('Cache-Control','no-store,private');
        return $response;
    }
    public static function admin_menu():void {
        if(!self::staging())return;
        add_submenu_page('mad4b-company-brand-readiness','Company Service Catalog',
            'Service Catalog','manage_options','mad4b-company-service-catalog',
            [__CLASS__,'screen']);
    }
    public static function screen():void {
        $p=self::permission();
        if(is_wp_error($p))wp_die(esc_html($p->get_error_message()));
        echo '<div class="wrap"><h1>Company Management · Service Catalog</h1>';
        echo '<p>Each verified company has one catalog mode. <strong>Tour Only</strong> keeps Tours public and places enabled Property, Transportation and other services inside verified Tour context. <strong>Full Catalog</strong> is only a company plan until separate provider readiness and tenant deployment.</p>';
        echo '<p>Changing this plan does not alter Service Activation, WordPress product visibility, payments, prices or any remote company site.</p>';
        $status=sanitize_key((string)($_GET['catalog_status']??''));
        if($status==='saved')echo '<div class="notice notice-success"><p>Company plan saved. No tenant activation was performed.</p></div>';
        if($status==='error')echo '<div class="notice notice-error"><p>Save failed or revision changed. Check binding and reload.</p></div>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Site ID</th><th>Catalog mode</th><th>Readback</th></tr></thead><tbody>';
        foreach(self::companies() as $code) {
            $v=self::current($code);
            echo '<tr><td><strong>'.esc_html($code).'</strong></td>';
            if(is_wp_error($v)){
                echo '<td colspan="3">'.esc_html($v->get_error_message()).'</td></tr>';
                continue;
            }
            echo '<td><code>'.esc_html(substr($v['company_site_uuid'],0,8)).'…</code></td>';
            echo '<td><form action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
            wp_nonce_field('mad4b_catalog_'.$code);
            echo '<input type="hidden" name="action" value="mad4b_company_service_catalog_save">';
            echo '<input type="hidden" name="company_code" value="'.esc_attr($code).'">';
            echo '<input type="hidden" name="expected_revision" value="'.esc_attr((string)$v['revision']).'">';
            echo '<select name="catalog_mode" aria-label="'.esc_attr('Catalog mode for '.$code).'">';
            foreach(['tour_only'=>'Tour Only','full_catalog'=>'Full Catalog (plan only)'] as $mode=>$label)
                echo '<option value="'.esc_attr($mode).'"'.selected($mode,$v['mode'],false).'>'.esc_html($label).'</option>';
            echo '</select> ';
            submit_button('Save','secondary','submit',false);
            echo '</form></td><td>Rev '.esc_html((string)$v['revision']).' · Not deployed</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>Inherited services include Rooms/Property Rates, Transport Rates/Vehicle Lines/Trains, Tour Rates, and Visas. Disabled native providers stay disabled.</p></div>';
    }
    public static function admin_save():void {
        if(!self::admin())wp_die('Central Staging administrator required',403);
        $code=sanitize_key((string)($_POST['company_code']??''));
        check_admin_referer('mad4b_catalog_'.$code);
        $raw=wp_unslash((string)($_POST['expected_revision']??''));
        $mode=wp_unslash((string)($_POST['catalog_mode']??''));
        $result=ctype_digit($raw)&&strlen($raw)<10
            ? self::save($code,(int)$raw,$mode)
            : self::error('revision_invalid','Invalid revision',422);
        $status=is_wp_error($result)?'error':'saved';
        wp_safe_redirect(add_query_arg('catalog_status',$status,
            admin_url('admin.php?page=mad4b-company-service-catalog')));
        exit;
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'rest_routes'],76);
        add_action('admin_menu',[__CLASS__,'admin_menu'],94);
        add_action('admin_post_mad4b_company_service_catalog_save',[__CLASS__,'admin_save']);
    }
}
MAD4B_Company_Service_Catalog_V1::boot();
