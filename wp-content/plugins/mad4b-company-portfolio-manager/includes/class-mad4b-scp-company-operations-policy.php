<?php
/** Company policy editing via JetEngine Options; no global WordPress settings. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Company_Operations_Policy {
    const OPTION='company-operations-policies';
    const VERSION='mad4b_company_operations_policy_revision_v1';
    const AUDIT='mad4b_company_operations_policy_audit_v1';
    private static function defaults():array {return [
        'hard_delete_policy'=>'disabled','price_edit_policy'=>'allowed',
        'content_publication_policy'=>'direct','task_offer_ttl_hours'=>'72','max_staff_accounts'=>'60'];}
    private static function whitelist():array {return [
        'hard_delete_policy'=>['disabled','guarded'],
        'price_edit_policy'=>['allowed','blocked'],
        'content_publication_policy'=>['direct','review'],
        'task_offer_ttl_hours'=>['24','48','72','168'],
        'max_staff_accounts'=>['20','60','120']];}
    public static function get():array {
        $raw=get_option(self::OPTION,[]);if(!is_array($raw))$raw=[];
        $out=self::defaults();foreach(self::whitelist() as$k=>$allowed)
            if(isset($raw[$k])&&is_scalar($raw[$k])&&in_array((string)$raw[$k],$allowed,true))$out[$k]=(string)$raw[$k];
        return $out;
    }
    public static function revise($new,$old){
        if(!is_array($new))return $old;
        foreach(self::whitelist()as$k=>$values){
            if(isset($new[$k])&&!in_array((string)$new[$k],$values,true))return $old;
        }
        // Unknown properties, including credentials or system permissions, rejected.
        if(array_diff(array_keys($new),array_keys(self::whitelist())))return $old;
        return $new;
    }
    public static function updated($option,$old,$new):void {
        if($option!==self::OPTION)return;
        update_option(self::VERSION,absint(get_option(self::VERSION,0))+1,false);
        $changed=[];foreach(self::whitelist() as$k=>$v)
            if(($old[$k]??null)!==($new[$k]??null))$changed[]=$k;
        $audit=get_option(self::AUDIT,[]);if(!is_array($audit))$audit=[];
        $audit[]=['at'=>current_time('mysql',true),'actor'=>get_current_user_id(),'changed_keys'=>$changed];
        update_option(self::AUDIT,array_slice($audit,-100),false);
    }
    public static function read($r){
        $response=rest_ensure_response(['contract'=>'mad4b.company.operational-policy.v1',
            'source'=>'JetEngine Options Page #384','settings_url'=>admin_url('admin.php?page='.self::OPTION),
            'revision'=>absint(get_option(self::VERSION,0)),'values'=>self::get(),
            'source_coverage'=>['wp_roles','wp_postmeta_rate_prices','native_wp_content_publication',
                'staff_creation','staff_deletion','task_offer_ttl'],
            'not_covered'=>['external_cct_sql_rate_writes','external_erp_rates','global_wp_settings',
                'central_control_plane_release_policy'],
            'system_gates_unchanged'=>true]);
        $response->header('Cache-Control','private, no-store');return $response;
    }
    public static function update($r){
        global $wpdb;
        $key='mad4b:company-policy:'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)',$key))!==1)
            return new WP_Error('company_policy_busy','Concurrent policy edit; retry after refresh.',['status'=>423]);
        try{return self::updateLocked($r);}
        finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
    }
    private static function updateLocked($r){
        $rev=$r->get_param('expected_revision');
        if($rev===null||(int)$rev!==absint(get_option(self::VERSION,0)))
            return new WP_Error('company_policy_revision_conflict','Policy has changed; reload latest revision.', ['status'=>409]);
        $data=$r->get_json_params();$patch=$data['values']??null;
        if(!is_array($patch)||array_diff(array_keys($patch),array_keys(self::whitelist())))
            return new WP_Error('company_policy_keys_invalid','Only company operational policy keys may change.',['status'=>422]);
        foreach($patch as$k=>$v)if(!is_scalar($v)||!in_array((string)$v,self::whitelist()[$k],true))
            return new WP_Error('company_policy_value_invalid','Invalid company policy value.',['status'=>422]);
        $merged=array_merge(self::get(),$patch);
        update_option(self::OPTION,$merged,false);
        if(self::get()!==$merged)return new WP_Error('company_policy_write_unconfirmed','Policy source readback mismatch.',['status'=>502]);
        return self::read($r);
    }
    public static function added($option,$value):void {
        if($option===self::OPTION)self::updated($option,[], $value);
    }
    public static function denyPriceMeta($check,$postId,$key,$value){
        if($check!==null)return $check;
        if(!in_array('master_admin',(array)wp_get_current_user()->roles,true))return $check;
        if(self::get()['price_edit_policy']!=='blocked')return $check;
        $postType=get_post_type($postId);
        if(!in_array($postType,['product','tour-rates','property-rates','transport-rates'],true))return $check;
        if(!in_array((string)$key,['base_price','sale_price','base_price_egyptian','sale_price_egyptian',
            'price_for_egyptian','_price','_regular_price','_sale_price','pricing_type','base_currency'],true))return $check;
        return false;
    }
    public static function contentPostData($data,$postarr){
        if(!in_array('master_admin',(array)wp_get_current_user()->roles,true))return $data;
        if(self::get()['content_publication_policy']!=='review')return $data;
        if(!in_array($data['post_type']??'', ['post','page','tours-and-activities','properties','transportations'],true))return $data;
        if(($data['post_status']??'')!=='publish')return $data;
        $old=isset($postarr['ID'])&&$postarr['ID']?get_post_status((int)$postarr['ID']):false;
        if($old==='publish')return $data;
        $data['post_status']='pending';
        return $data;
    }
    public static function routes():void {
        $cap=static fn()=>current_user_can('mad4b_manage_company_operations');
        register_rest_route('mad4b/v1','/admin/company-policies',[
            ['methods'=>'GET','callback'=>[__CLASS__,'read'],'permission_callback'=>$cap],
            ['methods'=>'PATCH','callback'=>[__CLASS__,'update'],'permission_callback'=>$cap]]);
    }
    public static function boot():void {
        add_filter('pre_update_option_'.self::OPTION,[__CLASS__,'revise'],10,2);
        add_action('updated_option',[__CLASS__,'updated'],10,3);
        add_action('added_option',[__CLASS__,'added'],10,2);
        add_filter('update_post_metadata',[__CLASS__,'denyPriceMeta'],30,4);
        add_filter('add_post_metadata',[__CLASS__,'denyPriceMeta'],30,4);
        add_filter('wp_insert_post_data',[__CLASS__,'contentPostData'],30,2);
        add_action('rest_api_init',[__CLASS__,'routes'],30);
    }
}
MAD4B_SCP_Company_Operations_Policy::boot();
