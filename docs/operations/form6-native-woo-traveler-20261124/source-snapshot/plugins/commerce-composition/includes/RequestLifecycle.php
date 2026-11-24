<?php
declare(strict_types=1);
namespace Commerce\Composition;

/** Source-owned lifecycle projections. Queue payloads contain IDs, never customer answers. */
final class RequestLifecycle
{
    const OPTION='commerce_request_lifecycle_v1';
    const ACTION='sync_request_lifecycle';

    /** Classify mail transport errors without retaining customer content. */
    public static function deliveryFailureCategory(string $code,string $message):string {
        $reason=strtolower($code.' '.$message);
        if(str_contains($reason,'data not accepted'))return 'smtp_data_rejected';
        if(str_contains($reason,'authenticat'))return 'smtp_auth_rejected';
        if(str_contains($reason,'recipient') && str_contains($reason,'reject'))return 'smtp_recipient_rejected';
        if(str_contains($reason,'timed out') || str_contains($reason,'timeout'))return 'smtp_timeout';
        if(str_contains($reason,'connect'))return 'smtp_connect_failed';
        return 'mail_transport_unclassified';
    }

    public static function config():array {return array_replace(['enabled'=>true,'actor_id'=>0,'crm'=>true,'boards'=>true,'mail'=>true,'accounting'=>true],(array)get_option(self::OPTION,[]));}
    public static function bootstrap():void {
        add_action('fluentform/submission_inserted',static function($sid,$data,$form){if(is_object($form))self::enqueue((int)$form->id,(int)$sid,0,'request_received');},1010,3);
        add_action('commerce_composition_request_advance_created',static function($id,$fid,$sid){self::enqueue((int)$fid,(int)$sid,(int)$id,'advance_requested');},30,3);
        add_action('commerce_composition_request_advance_received',static function($id){self::orderEvent((int)$id,'advance_received');},30,1);
        add_action('woocommerce_order_refunded',static function($id,$refund){self::orderEvent((int)$id,'refund_recorded',(int)$refund);},50,2);
        add_action('woocommerce_order_status_changed',static function($id,$from,$to,$o){if(in_array($to,['failed','cancelled'],true))self::orderEvent((int)$id,'payment_'.$to);},50,4);
        add_action('fluentform/post_integration_success',[self::class,'postCreated'],100,5);
        add_action('commerce_composition_contact_linked',static function($link,$source){if(($source['domain']??'')==='cpt')self::projectCpt((int)$link['contact_id'],(int)$link['source_id']);},50,2);
        add_action('save_post',static function($id,$post,$update){$map=get_option('commerce_crm_metadata_projection_v1',[]);if(!wp_is_post_revision($id)&&$update&&isset($map[$post->post_type]))self::refreshCpt((int)$id);},100,3);
        $metaChanged=static function($mid,$id,$key,$value){$map=get_option('commerce_crm_metadata_projection_v1',[]);foreach(($map[get_post_type($id)]??[]) as $rule)if(($rule['meta_key']??null)===$key){self::refreshCpt((int)$id);break;}};
        foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)add_action($hook,$metaChanged,100,4);
        add_action('commerce_composition_retry_lifecycle_jobs',[self::class,'retryDue']);
        add_filter('cron_schedules',static function($s){$s['commerce_lifecycle_five_minutes']=['interval'=>300,'display'=>'Commerce lifecycle retry'];return $s;});
        if(!wp_next_scheduled('commerce_composition_retry_lifecycle_jobs'))wp_schedule_event(time()+300,'commerce_lifecycle_five_minutes','commerce_composition_retry_lifecycle_jobs');
        add_filter('woocommerce_settings_tabs_array',static function($s){$s['request_lifecycle']='Request lifecycle';return $s;},61);
        add_action('woocommerce_settings_tabs_request_lifecycle',[self::class,'settings']);
        add_action('woocommerce_update_options_request_lifecycle',[self::class,'saveSettings']);
        add_action('rest_api_init',static function(){register_rest_route('commerce-composition/v1','/request-lifecycle/status',['methods'=>'GET','permission_callback'=>static fn()=>\MAD4B_Lifecycle_Access::can('commerce_view_lifecycle'),'callback'=>static fn()=>rest_ensure_response(self::status())]);});
        add_action('wp_abilities_api_init',static function(){if(function_exists('wp_register_ability'))wp_register_ability('mad4b/request-lifecycle-status',['label'=>'Request lifecycle integrations','description'=>'Source-owned CRM fields, mail transport separation, Boards and ERP advance-accounting readiness. No customer data.','category'=>'mad4b-read','input_schema'=>['type'=>'object','additionalProperties'=>false],'output_schema'=>['type'=>'object','additionalProperties'=>true],'permission_callback'=>static fn()=>\MAD4B_Lifecycle_Access::can('commerce_view_lifecycle'),'execute_callback'=>static fn($input=[])=>self::status(),'meta'=>['show_in_rest'=>true,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]]);},61);
    }
    public static function validPayload(array $p):bool {
        $keys=['form_id','submission_id','order_id','event','component','refund_id'];$actual=array_keys($p);sort($actual);sort($keys);
        if($keys!==$actual)return false;
        foreach(['form_id','submission_id','order_id','refund_id'] as $k)if(!is_int($p[$k])||$p[$k]<($k==='form_id'||$k==='submission_id'?1:0))return false;
        return in_array($p['component'],['crm','boards','mail','accounting'],true)&&in_array($p['event'],['request_received','advance_requested','advance_received','refund_recorded','payment_failed','payment_cancelled'],true);
    }
    /** Only the acknowledgement may fall back to the original saved Form 6 entry.
     * No unverified source, price or payment authority is inferred from submitted fields. */
    public static function jobContext(array $p):array|\WP_Error {
        $ctx=RequestAdvancePayments::submissionContext((int)$p['form_id'],(int)$p['submission_id']);
        if(!is_wp_error($ctx) || (int)$p['form_id']!==6 || $p['event']!=='request_received'
            || $p['component']!=='mail' || (int)$p['order_id']!==0 || (int)$p['refund_id']!==0)return $ctx;
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT form_id,status,response,source_url,created_at FROM {$wpdb->prefix}fluentform_submissions WHERE id=%d",(int)$p['submission_id']),ARRAY_A);
        if(!$row || (int)$row['form_id']!==6 || in_array($row['status'],['spam','trashed','deleted'],true))return $ctx;
        $data=json_decode($row['response'],true);
        if(!is_array($data)||!is_string($data['email']??null)||!is_email($data['email']))return $ctx;
        return ['source_id'=>0,'rate_id'=>0,'intent'=>null,'data'=>$data,'row'=>$row];
    }
    public static function travelerAllowed():bool {
        return class_exists('MAD4B_SCP_Company_Communication')
            && (\MAD4B_SCP_Company_Communication::config()['notify_traveler']??'disabled')==='enabled';
    }
    public static function enqueue(int $fid,int $sid,int $order,string $event,int $refund=0):array {
        $config=self::config();if(!$config['enabled']||!\MAD4B_Lifecycle_Access::can('commerce_run_workflows',(int)$config['actor_id']))return ['status'=>'operator_unconfigured'];
        $ctx=RequestAdvancePayments::submissionContext($fid,$sid);
        $out=[];
        foreach(['crm','boards','mail','accounting'] as $component){
            if(!$config[$component])continue;
            $p=['form_id'=>$fid,'submission_id'=>$sid,'order_id'=>$order,'event'=>$event,'component'=>$component,'refund_id'=>$refund];
            if($component==='mail'&&!self::travelerAllowed())continue;
            if(is_wp_error($ctx)&&is_wp_error(self::jobContext($p)))continue;
            $key='request-life:v1:'.$component.':'.$fid.':'.$sid.':'.$order.':'.$event.':'.$refund;
            $out[$component]=WorkflowJobs::enqueue(self::ACTION,$p,$key,(int)$config['actor_id'],'event');
        }
        return $out;
    }
    public static function orderEvent(int $id,string $event,int $refund=0):void {
        $o=wc_get_order($id);if(!RequestAdvancePayments::isAdvance($o))return;
        self::enqueue((int)$o->get_meta('_request_form_id'),(int)$o->get_meta('_request_submission_id'),$id,$event,$refund);
    }
    public static function execute(array $p):array|\WP_Error {
        if(!\MAD4B_Lifecycle_Access::can('commerce_run_workflows')||!self::validPayload($p))return new \WP_Error('lifecycle_payload_or_permission_invalid');
        $cfg=self::config();if(!$cfg['enabled']||!$cfg[$p['component']])return ['status'=>'component_disabled'];
        $cap=['crm'=>'commerce_manage_crm','boards'=>'commerce_manage_request_tasks','mail'=>'commerce_run_workflows','accounting'=>'commerce_manage_accounting'][$p['component']];if(!\MAD4B_Lifecycle_Access::can($cap))return new \WP_Error('lifecycle_component_permission_denied');
        $ctx=self::jobContext($p);if(is_wp_error($ctx))return $ctx;
        $o=$p['order_id']?wc_get_order($p['order_id']):null;
        if($p['order_id']&&(!RequestAdvancePayments::isAdvance($o)||(int)$o->get_meta('_request_submission_id')!==$p['submission_id']||(int)$o->get_meta('_request_form_id')!==$p['form_id']))return new \WP_Error('lifecycle_order_source_mismatch');
        if(!$o && !($p['form_id']===6&&$p['component']==='mail'&&$p['event']==='request_received')){$currentOrders=RequestAdvancePayments::findBySource((int)$p['form_id'],(int)$p['submission_id'],2);if(count($currentOrders)===1)$o=$currentOrders[0];}
        if($p['refund_id']){$r=wc_get_order($p['refund_id']);if(!$r||$r->get_type()!=='shop_order_refund'||$r->get_parent_id()!==$p['order_id'])return new \WP_Error('lifecycle_refund_source_mismatch');}
        if($p['component']==='accounting')return RequestAdvanceAccounting::post($p,$o);
        if($p['component']==='mail')return self::sendMail($p,$ctx,$o);
        $contact=self::crm($p,$ctx,$o);if(is_wp_error($contact))return $contact;
        if($p['component']==='boards')return FluentBoardsTaskBridge::ensureSubmission($p,$ctx,$o,$contact['contact_id']);
        return $contact;
    }
    public static function registerFields():void {
        if(!class_exists('FluentCrm\\App\\Models\\CustomContactField'))return;
        $fields=fluentcrm_get_option('contact_custom_fields',[]);$slugs=array_column($fields,'slug');
        $definitions=['request_id'=>'Latest request submission','tour_id'=>'Requested tour ID','rate_id'=>'Requested tour rate ID','tour_order_id'=>'Legacy tour order ID','woo_advance_id'=>'Woo advance receipt ID','request_state'=>'Request operation state','collection_state'=>'Advance collection state','advance_paid'=>'Advance received','advance_refunded'=>'Advance refunded','advance_currency'=>'Advance currency','payment_schedule'=>'Payment schedule','payment_kind'=>'Payment kind',
            'payment_scope'=>'Payment scope','price_basis'=>'Price basis',
            'booking_status'=>'Booking status','final_total_state'=>'Final trip total state','travel_date'=>'Travel date','adults'=>'Adults','children'=>'Children','infants'=>'Infants','rate_currency'=>'Rate source currency','rate_start'=>'Rate valid from','rate_end'=>'Rate valid to','rate_stop_sale'=>'Rate stop sale'];
        $definitions+=['policy_revision'=>'Accepted customer policy fingerprint','policy_mode'=>'Advance policy mode','policy_text_source'=>'Policy text source','balance_due_rule'=>'Balance due rule'];
        foreach($definitions as $slug=>$label){$key='mad4b_'.$slug;if(!in_array($key,$slugs,true))$fields[]=['slug'=>$key,'label'=>$label,'type'=>'text','group'=>'MAD4B Requests'];}
        $slugs=array_column($fields,'slug');
        foreach((array)get_option('commerce_crm_metadata_projection_v1',[]) as $rules)foreach((array)$rules as $target=>$rule)if(is_string($target)&&preg_match('/^mad4b_[a-z0-9_]{1,60}$/D',$target)&&!in_array($target,$slugs,true)){$fields[]=['slug'=>$target,'label'=>is_string($rule['label']??null)?sanitize_text_field($rule['label']):$target,'type'=>'text','group'=>'MAD4B Requests'];$slugs[]=$target;}
        (new \FluentCrm\App\Models\CustomContactField())->saveGlobalFields($fields);
    }
    private static function formSource(int $fid):string {
        $raw=get_option('commerce_contact_bridge_sources_v1',[]);$sources=$raw['sources']??[];
        foreach($sources as $key=>$s)if(($s['domain']??'')==='fluent_form'&&in_array($fid,array_map('intval',$s['form_ids']??[]),true))return $key;
        $key='request_form_'.$fid;$sources[$key]=['domain'=>'fluent_form','form_ids'=>[$fid]];$raw['sources']=$sources;update_option('commerce_contact_bridge_sources_v1',$raw,false);return $key;
    }
    public static function crm(array $p,array $ctx,$o=null):array|\WP_Error {
        if(!function_exists('FluentCrmApi'))return new \WP_Error('lifecycle_crm_missing');
        if(!$o){$linkedOrders=RequestAdvancePayments::findBySource((int)$p['form_id'],(int)$p['submission_id'],2);if($linkedOrders)$o=$linkedOrders[0];}
        $policy=RequestAdvancePayments::policy($ctx['source_id'],$ctx['rate_id']);$email=$ctx['data'][$policy['email_field']]??null;
        if(!is_string($email)||!is_email($email))return new \WP_Error('lifecycle_contact_email_invalid');
        $contact=FluentCrmApi('contacts')->getContact(strtolower($email));
        if(!$contact){$name=$ctx['data'][$policy['name_field']]??[];$contact=FluentCrmApi('contacts')->createOrUpdate(['email'=>strtolower($email),'status'=>'transactional','source'=>'MAD4B request','first_name'=>is_array($name)?sanitize_text_field($name['first_name']??''):'','last_name'=>is_array($name)?sanitize_text_field($name['last_name']??''):''],false,false);}
        if(!$contact)return new \WP_Error('lifecycle_contact_write_failed');
        $key=self::formSource($p['form_id']);
        global $wpdb;$prior=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT contact_id FROM {$wpdb->prefix}commerce_contact_links WHERE domain='fluent_form' AND source_id=%d AND role='requester'",$p['submission_id']));
        if($prior&&(count($prior)!==1||(int)$prior[0]!== (int)$contact->id))return new \WP_Error('lifecycle_requester_conflict');
        $lock='life_contact_'.(int)$contact->id;
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return new \WP_Error('lifecycle_contact_busy');
        try {
        $linked=ContactBridge::link((int)$contact->id,$key,$p['submission_id'],'requester');if(is_wp_error($linked))return $linked;
        $v=['mad4b_request_id'=>(string)$p['submission_id'],'mad4b_tour_id'=>(string)$ctx['source_id'],'mad4b_rate_id'=>(string)$ctx['rate_id'],'mad4b_request_state'=>'under_agreement','mad4b_final_total_state'=>'not_agreed',
            'mad4b_travel_date'=>sanitize_text_field($ctx['intent']['travel_date']??($ctx['data']['datetime']??'')),'mad4b_adults'=>sanitize_text_field((string)($ctx['data']['no_of_adults']??'')),'mad4b_children'=>sanitize_text_field((string)($ctx['data']['no_of_children']??'')),'mad4b_infants'=>sanitize_text_field((string)($ctx['data']['no_of_infants']??''))];
        if($o){$v+=['mad4b_woo_advance_id'=>(string)$o->get_id(),'mad4b_collection_state'=>$o->get_meta('_request_collection_state'),'mad4b_advance_paid'=>(string)$o->get_meta('_request_paid_amount'),'mad4b_advance_refunded'=>(string)$o->get_meta('_request_refunded_amount'),'mad4b_advance_currency'=>$o->get_currency(),
                'mad4b_payment_schedule'=>(string)$o->get_meta('_request_payment_schedule'),
                'mad4b_payment_kind'=>RequestAdvancePayments::isFullDueNow($o)?'full_request_payment':'advance',
                'mad4b_payment_scope'=>$o->get_meta('_request_payment_scope')
                     ?: (RequestAdvancePayments::isFullDueNow($o)?'full':'partial'),
                'mad4b_price_basis'=>$o->get_meta('_request_price_basis')?:'estimated',
                'mad4b_booking_status'=>$o->get_meta('_request_booking_status')?:'awaiting_confirmation'];
            $snap=$o->get_meta('_request_customer_policy_snapshot');
            if(is_array($snap))$v+=['mad4b_policy_revision'=>$snap['fingerprint'],'mad4b_policy_mode'=>$snap['rules']['mode']??'request_only','mad4b_policy_text_source'=>$snap['narrative_sources']['booking']??'unconfigured','mad4b_balance_due_rule'=>$snap['rules']['balance_due_mode']??'manual'];
            ContactBridge::link((int)$contact->id,'request_advance',$o->get_id(),'requester');$o->update_meta_data('_request_crm_contact_id',(int)$contact->id);$o->save();}
        else $v+=['mad4b_woo_advance_id'=>'','mad4b_collection_state'=>'not_requested','mad4b_advance_paid'=>'0','mad4b_advance_refunded'=>'0','mad4b_advance_currency'=>'',
            'mad4b_payment_schedule'=>'','mad4b_payment_kind'=>'not_requested',
            'mad4b_payment_scope'=>'not_requested','mad4b_price_basis'=>'estimated',
            'mad4b_booking_status'=>'awaiting_confirmation'];
        if($ctx['rate_id']){$projection=self::cptValues($ctx['rate_id']);$v=array_replace($v,$projection);ContactBridge::link((int)$contact->id,'tour_rates',$ctx['rate_id'],'requested_rate');}
        $snapshot=['schema'=>1,'form_id'=>$p['form_id'],'submission_id'=>$p['submission_id'],'source_id'=>$ctx['source_id'],'rate_id'=>$ctx['rate_id'],'values'=>$v,'updated_utc'=>gmdate('c')];
        $contact->updateMeta('mad4b_request_'.$p['form_id'].'_'.$p['submission_id'],$snapshot,'commerce_request');
        $latest=(int)$contact->getMeta('mad4b_latest_request_id','commerce_request');
        if($p['submission_id']>=$latest){$contact->syncCustomFieldValues($v,true);$contact->updateMeta('mad4b_latest_request_id',$p['submission_id'],'commerce_request');}
        return ['status'=>'crm_projected','contact_id'=>(int)$contact->id,'subscription_status'=>$contact->status];
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public static function cptValues(int $id):array {
        $cfg=get_option('commerce_crm_metadata_projection_v1',[]);$type=get_post_type($id);$rules=$cfg[$type]??[];$out=[];
        foreach($rules as $target=>$rule){
            if(!is_string($target)||!str_starts_with($target,'mad4b_')||!is_array($rule)||!is_string($rule['meta_key']??null))continue;
            $value=get_post_meta($id,$rule['meta_key'],true);if(is_scalar($value))$out[$target]=mb_substr(sanitize_text_field((string)$value),0,2000);
        }
        if($type==='tour_orders')$out['mad4b_tour_order_id']=(string)$id;
        if($type==='tour-rates')$out['mad4b_rate_id']=(string)$id;
        return $out;
    }
    public static function projectCpt(int $contactId,int $postId):array|\WP_Error {
        if(!\MAD4B_Lifecycle_Access::can('commerce_manage_crm')||!current_user_can('edit_post',$postId))return new \WP_Error('lifecycle_cpt_permission_denied');
        $post=get_post($postId);if(!$post||in_array($post->post_status,['trash','auto-draft'],true))return new \WP_Error('lifecycle_cpt_inactive');
        $contact=FluentCrmApi('contacts')->getContact($contactId);if(!$contact)return new \WP_Error('lifecycle_contact_missing');
        $v=self::cptValues($postId);$contact->updateMeta('mad4b_cpt_'.$post->post_type.'_'.$postId,['source_id'=>$postId,'source_type'=>$post->post_type,'values'=>$v,'updated_utc'=>gmdate('c')],'commerce_request');
        if($post->post_type==='tour_orders'){
            global $wpdb;$sid=(int)$wpdb->get_var($wpdb->prepare("SELECT response_id FROM {$wpdb->prefix}fluentform_submission_meta WHERE meta_key='__postFeeds_created_id' AND value=%s ORDER BY response_id DESC LIMIT 1",(string)$postId));
            if($sid&&(int)$contact->getMeta('mad4b_latest_request_id','commerce_request')===$sid)$contact->syncCustomFieldValues($v,false);
        }elseif($post->post_type==='tour-rates'){
            $current=$contact->custom_fields();if((int)($current['mad4b_rate_id']??0)===$postId)$contact->syncCustomFieldValues($v,false);
        }
        return ['status'=>'cpt_projected','contact_id'=>$contactId,'fields'=>count($v)];
    }
    public static function refreshCpt(int $id):void {
        global $wpdb;$previous=get_current_user_id();$actor=(int)self::config()['actor_id'];
        if(!user_can($actor,'manage_options'))return;
        try {wp_set_current_user($actor);foreach($wpdb->get_col($wpdb->prepare("SELECT DISTINCT contact_id FROM {$wpdb->prefix}commerce_contact_links WHERE domain='cpt' AND source_id=%d",$id)) as $cid)self::projectCpt((int)$cid,$id);}
        finally{wp_set_current_user($previous);}
    }
    public static function postCreated($post,$postData,$sid,$form,$feed):void {
        if(get_post_type($post)!=='tour_orders'||!is_object($form))return;
        global $wpdb;$saved=$wpdb->get_var($wpdb->prepare("SELECT value FROM {$wpdb->prefix}fluentform_submission_meta WHERE response_id=%d AND form_id=%d AND meta_key='__postFeeds_created_id' AND value=%s",(int)$sid,(int)$form->id,(string)$post));
        if((int)$saved!==(int)$post)return;
        $previous=get_current_user_id();$actor=(int)self::config()['actor_id'];if(!user_can($actor,'manage_options'))return;
        try {wp_set_current_user($actor);$ctx=RequestAdvancePayments::submissionContext((int)$form->id,(int)$sid);if(is_wp_error($ctx))return;
            $r=self::crm(['form_id'=>(int)$form->id,'submission_id'=>(int)$sid],$ctx);if(!is_wp_error($r))ContactBridge::link($r['contact_id'],'tour_orders',(int)$post,'requester');
        }finally{wp_set_current_user($previous);}
    }
    private static function receiptMeta(int $fid,int $sid,string $key,?array $value=null):?array {
        $m=\FluentForm\App\Models\SubmissionMeta::where('response_id',$sid)->where('form_id',$fid)->where('meta_key',$key)->first();
        if($value===null)return $m?json_decode((string)$m->value,true):null;
        if(!$m)$m=new \FluentForm\App\Models\SubmissionMeta();$m->fill(['response_id'=>$sid,'form_id'=>$fid,'meta_key'=>$key,'value'=>wp_json_encode($value),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')])->save();return $value;
    }
    /** The saved Fluent submission URL can identify the traveler's original language.
     * It is advisory for copy/links only, never for site, identity or payment authority.
     */
    public static function requestLanguage(array $ctx):string {
        $source=(int)($ctx['source_id']??0);
        $sourceLang=$source>0?(string)apply_filters('wpml_element_language_code','',[
            'element_id'=>$source,'element_type'=>'post_'.get_post_type($source)]):'';
        $supported=['en','ar','fr','de','es','it'];
        $default=(string)apply_filters('wpml_default_language','en');
        if(!in_array($default,$supported,true))$default='en';
        $fallback=in_array($sourceLang,$supported,true)?$sourceLang:$default;
        $url=(string)($ctx['row']['source_url']??'');
        if($url==='' || wp_parse_url($url,PHP_URL_SCHEME)!=='https'
            || strtolower((string)wp_parse_url($url,PHP_URL_HOST))
                !==strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))
            || wp_parse_url($url,PHP_URL_USER)
            || wp_parse_url($url,PHP_URL_PASS))return $fallback;
        $settings=get_option('icl_sitepress_settings',[]);
        if(!is_array($settings))return $fallback;
        $available=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
        if(!is_array($available))return $fallback;
        $mode=(int)($settings['language_negotiation_type']??0);
        if($mode===1){
            $basePath=trim((string)wp_parse_url(home_url('/'),PHP_URL_PATH),'/');
            $path=trim((string)wp_parse_url($url,PHP_URL_PATH),'/');
            if($basePath!=='') {
                if($path===$basePath)$path='';
                elseif(str_starts_with($path,$basePath.'/'))
                    $path=substr($path,strlen($basePath)+1);
                else return $fallback;
            }
            $first=explode('/',$path)[0];
            return in_array($first,$supported,true) && isset($available[$first])
                ?$first:$default;
        }
        if($mode===3){
            parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$query);
            $value=(string)($query['lang']??'');
            return in_array($value,$supported,true)&&isset($available[$value])
                ?$value:$default;
        }
        return $fallback;
    }
    public static function composeMail(array $p,array $ctx,$o=null):array {
        $lang=self::requestLanguage($ctx);
        $copy=[
            'en'=>['request_received'=>'Travel request received','advance_requested'=>'Your request advance payment','advance_received'=>'Request advance received','refund_recorded'=>'Request advance refund recorded','payment_failed'=>'Request payment was not completed','payment_cancelled'=>'Request payment cancelled','notice'=>'The final trip price and availability remain subject to agreement. This is not a booking confirmation.'],
            'ar'=>['request_received'=>'تم استلام طلب الرحلة','advance_requested'=>'دفع مقدم طلب الرحلة','advance_received'=>'تم استلام مقدم الطلب','refund_recorded'=>'تم تسجيل استرداد مقدم الطلب','payment_failed'=>'لم يكتمل دفع مقدم الطلب','payment_cancelled'=>'تم إلغاء طلب الدفع','notice'=>'السعر النهائي للرحلة والتوفر يخضعان للاتفاق. هذه الرسالة ليست تأكيد حجز.'],
            'fr'=>['request_received'=>'Demande de voyage reçue','advance_requested'=>'Paiement de votre avance','advance_received'=>'Avance reçue','refund_recorded'=>'Remboursement de votre avance enregistré','payment_failed'=>'Paiement non effectué','payment_cancelled'=>'Paiement annulé','notice'=>'Le prix final et la disponibilité restent à convenir. Ceci ne confirme pas une réservation.'],
            'de'=>['request_received'=>'Reiseanfrage erhalten','advance_requested'=>'Anzahlung für Ihre Anfrage','advance_received'=>'Anzahlung erhalten','refund_recorded'=>'Erstattung der Anzahlung erfasst','payment_failed'=>'Zahlung nicht abgeschlossen','payment_cancelled'=>'Zahlung storniert','notice'=>'Endpreis und Verfügbarkeit bleiben zu vereinbaren. Dies ist keine Buchungsbestätigung.'],
            'es'=>['request_received'=>'Solicitud de viaje recibida','advance_requested'=>'Pago del anticipo de su solicitud','advance_received'=>'Anticipo recibido','refund_recorded'=>'Reembolso del anticipo registrado','payment_failed'=>'Pago no completado','payment_cancelled'=>'Pago cancelado','notice'=>'El precio final y la disponibilidad están sujetos a acuerdo. Esto no confirma una reserva.'],
            'it'=>['request_received'=>'Richiesta di viaggio ricevuta','advance_requested'=>'Pagamento dell’anticipo','advance_received'=>'Anticipo ricevuto','refund_recorded'=>'Rimborso dell’anticipo registrato','payment_failed'=>'Pagamento non completato','payment_cancelled'=>'Pagamento annullato','notice'=>'Il prezzo finale e la disponibilità restano da concordare. Questa non è una conferma di prenotazione.']
        ];$c=$copy[$lang]??$copy['en'];
        // Event ID and the original receipt-backed queue stay unchanged.
        // Only customer-facing wording follows the immutable Woo order
        // schedule, never the current mutable 50% company policy.
        $isFullRequest=$o instanceof \WC_Order
            && RequestAdvancePayments::isFullDueNow($o);
        if($isFullRequest) {
            $labels=[
              'en'=>['advance_requested'=>'Your full estimated payment (100%)','advance_received'=>'Full Estimated Payment Received — Booking Awaiting Confirmation','refund_recorded'=>'Full estimated payment refund recorded','payment_failed'=>'Full estimated payment not completed','payment_cancelled'=>'Full estimated payment cancelled'],
              'ar'=>['advance_requested'=>'دفع كامل القيمة التقديرية (100%)','advance_received'=>'تم استلام كامل القيمة التقديرية — الحجز بانتظار التأكيد','refund_recorded'=>'تم تسجيل استرداد المبلغ الكامل','payment_failed'=>'لم يكتمل دفع المبلغ الكامل','payment_cancelled'=>'تم إلغاء دفع المبلغ الكامل'],
              'es'=>['advance_requested'=>'Pago total estimado de la solicitud (100%)','advance_received'=>'Pago total estimado recibido — Reserva pendiente de confirmación','refund_recorded'=>'Reembolso del pago total registrado','payment_failed'=>'Pago total no completado','payment_cancelled'=>'Pago total cancelado'],
              'fr'=>['advance_requested'=>'Paiement intégral estimé (100 %)','advance_received'=>'Paiement intégral estimé reçu — Réservation en attente de confirmation','refund_recorded'=>'Remboursement du paiement intégral enregistré','payment_failed'=>'Paiement intégral non effectué','payment_cancelled'=>'Paiement intégral annulé'],
              'de'=>['advance_requested'=>'Vollzahlung des geschätzten Betrags (100 %)','advance_received'=>'Geschätzte Vollzahlung erhalten — Buchung wartet auf Bestätigung','refund_recorded'=>'Erstattung der Vollzahlung erfasst','payment_failed'=>'Vollzahlung nicht abgeschlossen','payment_cancelled'=>'Vollzahlung storniert'],
              'it'=>['advance_requested'=>'Pagamento integrale stimato (100%)','advance_received'=>'Pagamento integrale stimato ricevuto — Prenotazione in attesa di conferma','refund_recorded'=>'Rimborso del pagamento completo registrato','payment_failed'=>'Pagamento completo non eseguito','payment_cancelled'=>'Pagamento completo annullato']
            ];
            $c=array_replace($c,$labels[$lang]??$labels['en']);
        }
        // Identity comes only from the enrolled site, never a submitted form field.
        $identity=class_exists('MAD4B_SCP_Company_Communication',false)
            ? \MAD4B_SCP_Company_Communication::brandIdentity():[];
        $brand=!empty($identity['valid'])
            ?sanitize_text_field((string)$identity['brand_name']):'';
        $subject=$c[$p['event']].' #'.$p['submission_id'];
        if($brand!=='')$subject.=' — '.$brand;
        $body=$subject."\n\n".$c['notice'];
        if((int)$p['form_id']===6 && $p['event']==='request_received') {
            $labels=[
              'en'=>['Your request has been received successfully. Our Reservations team will review your request.','Travel date','Travelers (adults / children / infants)','Estimated price (subject to review)','Price to be confirmed by Reservations.'],
              'es'=>['Hemos recibido tu solicitud. Nuestro equipo de Reservas la revisará.','Fecha de viaje','Viajeros (adultos / niños / bebés)','Precio estimado (sujeto a revisión)','Reservas confirmará el precio.'],
              'ar'=>['تم استلام طلبك بنجاح. سيراجعه فريق الحجوزات.','تاريخ السفر','المسافرون (بالغون / أطفال / رضع)','السعر التقديري (قابل للمراجعة)','سيؤكد فريق الحجوزات السعر.'],
              'fr'=>['Votre demande a été reçue. Notre équipe Réservations va l’examiner.','Date de voyage','Voyageurs (adultes / enfants / bébés)','Prix estimé (à vérifier)','Prix à confirmer par notre équipe.'],
              'de'=>['Ihre Anfrage ist eingegangen. Unser Reservierungsteam prüft sie.','Reisedatum','Reisende (Erwachsene / Kinder / Kleinkinder)','Geschätzter Preis (vorbehaltlich Prüfung)','Preis wird vom Reservierungsteam bestätigt.'],
              'it'=>['Abbiamo ricevuto la richiesta. Il nostro team Prenotazioni la esaminerà.','Data di viaggio','Viaggiatori (adulti / bambini / neonati)','Prezzo stimato (da verificare)','Prezzo da confermare dal team Prenotazioni.']
            ];$l=$labels[$lang]??$labels['en'];$d=$ctx['data'];
            $body=$l[0]."\n\n".$subject;
            $date=$d['datetime']??null;
            if(is_string($date)&&strlen($date)<=32&&preg_match('/^[0-9 .:\/-]+$/D',$date))$body.="\n".$l[1].': '.$date;
            $counts=[];foreach(['no_of_adults','no_of_children','no_of_infants'] as $k){
                $v=$d[$k]??0;$counts[]=is_scalar($v)&&preg_match('/^[0-9]{1,3}$/D',(string)$v)?(int)$v:null;
            }
            if(!in_array(null,$counts,true))$body.="\n".$l[2].': '.array_sum($counts).' ('.implode(' / ',$counts).')';
            $i=$ctx['intent']??null;
            $complete=is_array($i)&&($i['state']??'')==='server_price_preview'&&!isset($i['reason'])
                &&empty($i['stay_review_required'])&&empty($i['transport_review_required'])&&empty($i['extras_review_required'])&&empty($i['minor_price_state'])
                &&is_numeric($i['total']??null)&&is_finite((float)$i['total'])&&(float)$i['total']>0
                &&is_string($i['currency']??null)&&array_key_exists($i['currency'],get_woocommerce_currencies());
            $body.="\n".($complete?$l[3].': '.wp_strip_all_tags(wc_price((float)$i['total'],['currency'=>$i['currency']])):$l[4]);
            $body.="\n\n".$c['notice'];
        }
        $receiptCopy=[
          'en'=>['receipt'=>'Advance receipt','received'=>'Amount received','refunded'=>'Amount refunded'],
          'ar'=>['receipt'=>'إيصال العربون','received'=>'المبلغ المستلم','refunded'=>'المبلغ المسترد'],
          'es'=>['receipt'=>'Recibo del anticipo','received'=>'Importe recibido','refunded'=>'Importe reembolsado'],
          'fr'=>['receipt'=>'Reçu de l’acompte','received'=>'Montant reçu','refunded'=>'Montant remboursé'],
          'de'=>['receipt'=>'Anzahlungsbeleg','received'=>'Erhaltener Betrag','refunded'=>'Erstatteter Betrag'],
          'it'=>['receipt'=>'Ricevuta di acconto','received'=>'Importo ricevuto','refunded'=>'Importo rimborsato']
        ];
        if($o&&in_array($p['event'],['advance_received','refund_recorded'],true)) {
            $r=$receiptCopy[$lang]??$receiptCopy['en'];
            if($isFullRequest) {
                $fullReceipt=[
                  'en'=>'Full estimated payment receipt','ar'=>'إيصال الدفع الكامل',
                  'es'=>'Recibo del pago total','fr'=>'Reçu du paiement intégral',
                  'de'=>'Vollzahlungsbeleg','it'=>'Ricevuta del pagamento completo'
                ];
                $r['receipt']=$fullReceipt[$lang]??$fullReceipt['en'];
            }
            $body.="\n\n".$r['receipt'].' #'.$o->get_id()
                ."\n".$r['received'].': '.$o->get_meta('_request_paid_amount').' '.$o->get_currency()
                ."\n".$r['refunded'].': '.$o->get_meta('_request_refunded_amount').' '.$o->get_currency();
            if($p['event']==='advance_received' && ($pct=RequestAdvancePayments::receiptPercentage($o))!==null)$body.="\n".wc_format_decimal($pct,2).'%';
        }
        // The Woo email template owns the only conditional payment CTA and
        // verifies a payable advance + an eligible gateway at rendering time.
        // Never embed a raw payment URL in stored/replayed request-body copy.
        $policy=RequestAdvancePayments::policy($ctx['source_id'],$ctx['rate_id']);
        return ['subject'=>$subject,'body'=>$body,'to'=>$ctx['data'][(int)$p['form_id']===6&&$p['event']==='request_received'?'email':$policy['email_field']]??'','language'=>$lang?:'en','source_id'=>(int)$ctx['source_id']];
    }
    public static function sendMail(array $p,array $ctx,$o=null):array|\WP_Error {
        if(!self::travelerAllowed())return ['status'=>'traveler_notifications_disabled'];
        $ack=(int)$p['form_id']===6&&$p['event']==='request_received';
        if($ack)$o=null; // independent from a Woo order and its payment CTA
        if(!$ack&&$p['event']==='request_received'){
            $advances=RequestAdvancePayments::findBySource((int)$p['form_id'],(int)$p['submission_id'],2);
            if($advances)return ['status'=>'request_acknowledgement_combined_with_advance'];
        }
        // Initial acknowledgements are owned here only for verified routed requests.
        if(in_array($p['event'],['advance_received','refund_recorded'],true)&&(!$o||!$o->get_meta('_request_received_utc')))return new \WP_Error('lifecycle_payment_receipt_missing');
        $key='_commerce_mail_'.substr(hash('sha256',$p['order_id'].':'.$p['event'].':'.$p['refund_id']),0,32);
        $prior=self::receiptMeta($p['form_id'],$p['submission_id'],$key);
        if(($prior['state']??'')==='accepted_by_transport')return ['status'=>'mail_already_accepted'];
        if(($prior['state']??'')==='sending')return new \WP_Error('lifecycle_mail_outcome_uncertain');
        $msg=self::composeMail($p,$ctx,$o);if(!is_string($msg['to'])||!is_email($msg['to']))return new \WP_Error('lifecycle_mail_recipient_invalid');
        $contact=function_exists('FluentCrmApi')?FluentCrmApi('contacts')->getContact($msg['to']):null;
        if($contact&&in_array($contact->status,['bounced','complained','spammed','suppressed'],true))return new \WP_Error('lifecycle_recipient_suppressed');
        if(!class_exists('MAD4B_SCP_Company_Communication')||!\MAD4B_SCP_Company_Communication::canSend()){
            self::receiptMeta($p['form_id'],$p['submission_id'],$key,['state'=>'blocked_sender_policy','event'=>$p['event'],'utc'=>gmdate('c')]);return new \WP_Error('lifecycle_company_sender_unconfigured');
        }
        // Reuse the existing asynchronous workflow and one per-event receipt.
        // WooCommerce owns the HTML template, while the company sender policy
        // and wp_mail transport remain the original trusted delivery path.
        if(!function_exists('WC'))return new \WP_Error('lifecycle_woocommerce_email_unavailable');
        $emailClasses=WC()->mailer()->get_emails();
        $email=$emailClasses[$ack?'MAD4B_WC_Email_Request_Received':'MAD4B_WC_Email_Request_Lifecycle']??null;
        if(!$email instanceof \WC_Email || !method_exists($email,'render_lifecycle'))
            return new \WP_Error('lifecycle_woocommerce_email_unregistered');
        if(!$email->is_enabled())return ['status'=>'woocommerce_request_email_disabled'];
        try {$html=$email->render_lifecycle($msg,$p,$o);}
        catch(\Throwable $e){return new \WP_Error('lifecycle_woocommerce_email_render_failed');}
        if(!is_string($html)||$html==='')return new \WP_Error('lifecycle_woocommerce_email_render_failed');
        $cfg=\MAD4B_SCP_Company_Communication::config();
        $headers=['Content-Type: text/html; charset=UTF-8','X-MAD4B-Message-Scope: company','X-MAD4B-Event: '.$p['event'],'From: '.sanitize_text_field($cfg['company_display_name']).' <'.$cfg['transactional_from_email'].'>'];
        if(is_email($cfg['traveler_reply_to']))$headers[]='Reply-To: '.$cfg['traveler_reply_to'];
        self::receiptMeta($p['form_id'],$p['submission_id'],$key,['state'=>'sending','event'=>$p['event'],'utc'=>gmdate('c')]);
        $failureCategory='transport_false_unclassified';
        $captureFailure=static function($error)use(&$failureCategory):void {
            if($error instanceof \WP_Error)
                $failureCategory=self::deliveryFailureCategory(
                    (string)$error->get_error_code(),(string)$error->get_error_message());
        };
        add_action('wp_mail_failed',$captureFailure,8,1);
        // Send through the EXISTING registered WC_Email native pipeline.
        // RequestLifecycle still owns the same original queue, per-event receipt
        // and uncertain-outcome handling. Woo owns mail filters, styling and
        // FluentSMTP transport via wp_mail, without a second dispatcher.
        try {$sent=$email->send($msg['to'],$msg['subject'],$html,$headers,[]);}
        catch(\Throwable $e){return new \WP_Error('lifecycle_mail_outcome_uncertain');}
        finally {remove_action('wp_mail_failed',$captureFailure,8);}
        $receipt=['state'=>$sent?'accepted_by_transport':'failed','event'=>$p['event'],'utc'=>gmdate('c')];
        if(!$sent)$receipt['failure_category']=$failureCategory;
        self::receiptMeta($p['form_id'],$p['submission_id'],$key,$receipt);
        return $sent?['status'=>'mail_accepted_by_transport']:new \WP_Error('lifecycle_mail_send_failed');
    }
    public static function retryDue():void {
        global $wpdb;$table=$wpdb->prefix.'commerce_workflow_jobs';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,error_code FROM $table WHERE action_key=%s AND state='failed' AND attempts<3 AND updated_utc<%s LIMIT 20",self::ACTION,gmdate('Y-m-d H:i:s',time()-300)),ARRAY_A);
        foreach($rows as $r){if(in_array($r['error_code'],['lifecycle_mail_outcome_uncertain','accounting_journal_readback_uncertain'],true))continue;
            if($r['error_code']==='lifecycle_company_sender_unconfigured'&&(!class_exists('MAD4B_SCP_Company_Communication')||!\MAD4B_SCP_Company_Communication::canSend()))continue;
            $n=$wpdb->update($table,['state'=>'queued'],['id'=>(int)$r['id'],'state'=>'failed']);if($n===1&&function_exists('as_enqueue_async_action'))as_enqueue_async_action('commerce_composition_process_workflow_job',[(int)$r['id']],'commerce-composition',true);
        }
    }
    public static function status():array {
        $smtp=function_exists('fluentMailGetSettings')?fluentMailGetSettings():[];
        return ['contract'=>'commerce.request-lifecycle.v1','config'=>self::config(),'crm'=>['available'=>function_exists('FluentCrmApi'),'fields'=>function_exists('fluentcrm_get_option')?count(fluentcrm_get_option('contact_custom_fields',[])):0],
            'smtp'=>['available'=>function_exists('fluentMailGetSettings'),'connections'=>count($smtp['connections']??[]),'core_mail_independent'=>true,'core_connection_configured'=>(bool)get_option('mad4b_wordpress_core_mail_connection',''),'company_mail_ready'=>class_exists('MAD4B_SCP_Company_Communication')&&\MAD4B_SCP_Company_Communication::canSend(),'company_mail_blockers'=>class_exists('MAD4B_SCP_Company_Communication')?\MAD4B_SCP_Company_Communication::problems():['company_sender_unconfigured']],
            'boards'=>get_option('commerce_request_review_board',[]),'accounting'=>RequestAdvanceAccounting::status(),'cpt_projection'=>get_option('commerce_crm_metadata_projection_v1',[])];
    }
    public static function settings():void {
        echo '<h2>Request lifecycle</h2><p>WordPress account and security email uses the site transport independently from company and internal-team routing.</p>';
        foreach(['crm','boards','mail','accounting'] as $part)echo '<p><label><input type="checkbox" name="request_lifecycle_'.$part.'" value="1" '.checked(self::config()[$part],true,false).'> '.esc_html(ucfirst($part)).'</label></p>';
        echo '<p><label>CRM metadata projection (source CPT → CRM field → meta_key)<br><textarea name="request_lifecycle_crm_mapping" rows="14" cols="100">'.esc_textarea(wp_json_encode(get_option('commerce_crm_metadata_projection_v1',[]),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</textarea></label></p>';
        if(function_exists('erp_acct_get_ledgers')){global $wpdb;$ledgers=$wpdb->get_results("SELECT l.id,l.name,c.slug FROM {$wpdb->prefix}erp_acct_ledgers l JOIN {$wpdb->prefix}erp_acct_chart_of_accounts c ON c.id=l.chart_id",ARRAY_A);$ac=RequestAdvanceAccounting::config();foreach(['clearing_ledger_id'=>['asset','bank'],'advance_ledger_id'=>['liability']] as $key=>$charts){echo '<p><label>'.esc_html($key).' <select name="request_lifecycle_'.$key.'">';foreach($ledgers as $l)if(in_array($l['slug'],$charts,true))echo '<option value="'.(int)$l['id'].'" '.selected((int)$ac[$key],(int)$l['id'],false).'>'.esc_html($l['name']).'</option>';echo '</select></label></p>';}}
        echo '<p><a href="'.esc_url(admin_url('options-general.php?page=fluent-mail')).'">FluentSMTP transport</a> · <a href="'.esc_url(admin_url('admin.php?page=fluentcrm-admin')).'">FluentCRM</a> · <a href="'.esc_url(admin_url('admin.php?page=fluent-boards')).'">Fluent Boards</a> · <a href="'.esc_url(admin_url('admin.php?page=erp-accounting')).'">WP ERP Accounting</a></p><pre>'.esc_html(wp_json_encode(self::status(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
    }
    public static function saveSettings():void {
        if(!\MAD4B_Lifecycle_Access::can('commerce_manage_operations'))return;check_admin_referer('woocommerce-settings');
        if(\MAD4B_Lifecycle_Access::can('commerce_manage_crm')&&isset($_POST['request_lifecycle_crm_mapping'])){
            $map=json_decode(wp_unslash($_POST['request_lifecycle_crm_mapping']),true);$valid=is_array($map);
            foreach((array)$map as $type=>$rules){if(!post_type_exists($type)||!is_array($rules)){$valid=false;break;}foreach($rules as $target=>$rule)if(!preg_match('/^mad4b_[a-z0-9_]{1,60}$/D',(string)$target)||!is_array($rule)||!preg_match('/^_?[a-zA-Z][a-zA-Z0-9_-]{0,119}$/D',(string)($rule['meta_key']??''))){$valid=false;break;}}
            if(!$valid){\WC_Admin_Settings::add_error('Invalid CRM source mapping.');return;}update_option('commerce_crm_metadata_projection_v1',$map,false);self::registerFields();
        }
        if(\MAD4B_Lifecycle_Access::can('commerce_manage_accounting')){$ac=RequestAdvanceAccounting::config();foreach(['clearing_ledger_id','advance_ledger_id'] as $k)if(isset($_POST['request_lifecycle_'.$k]))$ac[$k]=absint($_POST['request_lifecycle_'.$k]);update_option(RequestAdvanceAccounting::OPTION,$ac,false);}
        $cfg=self::config();foreach(['crm'=>'commerce_manage_crm','boards'=>'commerce_manage_request_tasks','mail'=>'commerce_run_workflows','accounting'=>'commerce_manage_accounting'] as $part=>$cap)if(\MAD4B_Lifecycle_Access::can($cap))$cfg[$part]=isset($_POST['request_lifecycle_'.$part]);/* Preserve the configured worker; saving UI settings never impersonates the operator as a service actor. */update_option(self::OPTION,$cfg,false);
    }
}
