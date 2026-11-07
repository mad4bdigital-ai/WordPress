<?php
/** Guarded company-channel activation. No outbound sends without a real provider adapter. */
defined('ABSPATH') || exit;
final class MAD4B_Distribution_Activation_V1 {
    public const CONTRACT='mad4b.distribution-activation.v1';
    private const RECEIPT_AGE=86400;
    private static function hook(string $id,string $operation): string {
        return 'mad4b/distribution/adapter/'.sanitize_key($id).'/'.$operation;
    }
    public static function readiness(string $id): array {
        $rows=MAD4B_Distribution_Channel_Registry_V1::rows();
        $row=$rows[$id]??null;
        if(!is_array($row))return ['channel'=>$id,'state'=>'UNKNOWN','missing'=>['channel_missing']];
        $health=MAD4B_Distribution_Channel_Registry_V1::connection_health()[$id]??[];
        $missing=(array)($health['missing']??[]);
        $receipt=$row['verified_connection_receipt']??null;
        if(!is_array($receipt) || empty($receipt['provider_verified']) ||
           ($receipt['channel']??'')!==$id ||
           (int)($receipt['configuration_revision']??-1)!==(int)($row['configuration_revision']??0) ||
           empty($receipt['verified_at']) ||
           strtotime((string)$receipt['verified_at'])===false ||
           time()-strtotime((string)$receipt['verified_at'])>self::RECEIPT_AGE ||
           time()-strtotime((string)$receipt['verified_at'])<0)
            $missing[]='current_provider_verification_receipt_required';
        if(has_filter(self::hook($id,'send'))===false)
            $missing[]='provider_delivery_adapter_missing';
        if($id==='google_things_to_do'){
            $feedOk=false;
            if(class_exists('MAD4B_Catalog_Studio_V1') && function_exists('mcfm_get_feeds')){
                foreach(mcfm_get_feeds() as $feed){
                    if(!is_array($feed) || ($feed['object_key']??'')!=='tours-and-activities')continue;
                    if(MAD4B_Catalog_Studio_V1::get_state(mcfm_normalize_feed($feed))['status']==='READY_INTERNAL'){
                        $feedOk=true;break;
                    }
                }
            }
            if(!$feedOk)$missing[]='current_tour_feed_mapping_not_ready';
            // An internal catalog proof is NOT Google Things to do offer eligibility.
            if(empty($row['provider_offer_eligibility_receipt']))$missing[]='google_fees_occupancy_availability_checkout_unverified';
        }
        $missing=array_values(array_unique($missing));
        return [
            'channel'=>$id,
            'requested'=>!empty($row['activation_requested']),
            'eligible'=>count($missing)===0,
            'delivery_enabled'=>!empty($row['external_delivery_enabled']) && count($missing)===0,
            'missing'=>$missing,
            'state'=>count($missing)===0?'PROVIDER_VERIFIED_READY':
                (!empty($row['activation_requested'])?'WAITING_PROVIDER_READINESS':'NOT_REQUESTED'),
        ];
    }
    private static function save(string $id,callable $mutate): array|WP_Error {
        if(!current_user_can('manage_options'))return new WP_Error('channel_admin_required');
        $rows=MAD4B_Distribution_Channel_Registry_V1::rows();
        if(!isset($rows[$id]))return new WP_Error('channel_unknown');
        $next=$mutate($rows[$id]);
        if(is_wp_error($next))return $next;
        $rows[$id]=$next;
        if(!update_option(MAD4B_Distribution_Channel_Registry_V1::STORE,$rows,false) &&
            get_option(MAD4B_Distribution_Channel_Registry_V1::STORE,[])!==$rows)
                return new WP_Error('activation_state_save_failed');
        if((MAD4B_Distribution_Channel_Registry_V1::rows()[$id]??null)!==$next)
            return new WP_Error('activation_state_readback_failed');
        return ['channel'=>$id,'requested'=>!empty($next['activation_requested']),
            'delivery_enabled'=>!empty($next['external_delivery_enabled']),
            'state'=>$next['activation_status']??'UNKNOWN'];
    }
    /** An authorization to attempt activation; NOT an authorization to fake provider acceptance. */
    public static function request(string $id): array|WP_Error {
        return self::save($id,static function(array $row){
            $row['activation_requested']=true;
            $row['activation_requested_at']=gmdate('c');
            $row['activation_status']='WAITING_PROVIDER_READINESS';
            $row['external_delivery_enabled']=false;
            $row['publishing_enabled']=false;
            return $row;
        });
    }
    public static function disable(string $id): array|WP_Error {
        return self::save($id,static function(array $row){
            $row['activation_requested']=false;
            $row['activation_status']='DISABLED_BY_ADMIN';
            $row['external_delivery_enabled']=false;
            $row['publishing_enabled']=false;
            return $row;
        });
    }
    /** Verify through an installed authenticated adapter, never by guessing availability. */
    public static function verify(string $id): array|WP_Error {
        if(!current_user_can('manage_options'))return new WP_Error('channel_admin_required');
        $row=MAD4B_Distribution_Channel_Registry_V1::rows()[$id]??null;
        if(!is_array($row))return new WP_Error('channel_unknown');
        if(empty($row['account_alias']) || ($row['provider_approval']??'')!=='approved')
            return new WP_Error('partner_enrollment_not_completed');
        $hook=self::hook($id,'verify');
        if(has_filter($hook)===false)return new WP_Error('provider_verification_adapter_missing');
        $result=apply_filters($hook,null,$id,$row);
        if(!is_array($result) || ($result['provider_verified']??false)!==true ||
            !is_string($result['provider_account_id']??null) ||
            trim($result['provider_account_id'])==='')
                return new WP_Error('provider_connection_not_verified');
        return self::save($id,static function(array $current)use($id,$result){
            $current['verified_connection_receipt']=[
                'channel'=>$id,'provider_verified'=>true,
                'provider_account_hash'=>hash('sha256',$result['provider_account_id']),
                'verified_at'=>gmdate('c'),
                'configuration_revision'=>(int)($current['configuration_revision']??0),
                'contract'=>self::CONTRACT,
            ];
            $current['status']='connected_verified';
            $current['activation_status']='VERIFIED_NOT_YET_ENABLED';
            $current['external_delivery_enabled']=false;
            $current['publishing_enabled']=false;
            return $current;
        });
    }
    /** Toggle the delivery capability only after verification; actual send is separate. */
    public static function activate(string $id): array|WP_Error {
        if(!current_user_can('manage_options'))return new WP_Error('channel_admin_required');
        $status=self::readiness($id);
        if(empty($status['requested']))return new WP_Error('activation_not_requested');
        if(!$status['eligible'])return new WP_Error('activation_blocked',implode(',',$status['missing']));
        return self::save($id,static function(array $row){
            $row['activation_status']='ENABLED_VERIFIED';
            $row['external_delivery_enabled']=true;
            $row['publishing_enabled']=true;
            $row['activated_at']=gmdate('c');
            return $row;
        });
    }
    public static function enforce_gate(string $id): bool {
        return self::readiness($id)['delivery_enabled']??false;
    }
    public static function admin_post(): void {
        if(!current_user_can('manage_options'))wp_die('Not authorized',403);
        check_admin_referer('mad4b_distribution_channel_activation');
        $id=sanitize_key((string)($_POST['channel_id']??''));
        $operation=sanitize_key((string)($_POST['activation_action']??''));
        $result=match($operation){
            'request'=>self::request($id),
            'verify'=>self::verify($id),
            'activate'=>self::activate($id),
            'disable'=>self::disable($id),
            default=>new WP_Error('unknown_activation_action'),
        };
        $message=is_wp_error($result)?$result->get_error_code():strtolower((string)$result['state']);
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-distribution-channels',
            'channel'=>$id,'channel_notice'=>sanitize_key($message)],admin_url('tools.php')).'#mad4b-dc-channel-settings');
        exit;
    }
    public static function boot(): void {
        add_action('admin_post_mad4b_distribution_channel_activation',[__CLASS__,'admin_post']);
    }
}
MAD4B_Distribution_Activation_V1::boot();
