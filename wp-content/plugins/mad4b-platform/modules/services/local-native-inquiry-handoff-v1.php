<?php
/**
 * Local QA: original saved Fluent Form #6 -> native Request Review -> FluentCRM.
 * No Woo Order, booking, supplier inventory, campaign or payment authority.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type()!=='local' ||
    !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

final class Local_Native_Inquiry_Handoff_V1 {
    private const FORM_ID=6;
    private const OPTION='local_native_inquiry_handoff_v1_enabled';
    public static function bootstrap():void {
        add_action('fluentform/submission_inserted',static function($id,$data,$form){
            if((int)($form->id??0)!==self::FORM_ID || !self::enabled())return;
            $result=self::stage((int)$id);
            self::maybeRetry((int)$id,$result);
        },120,3);
        add_action('local_native_inquiry_handoff_retry_v1',
          static function($id){
            if(!self::enabled())return;
            $result=self::stage((int)$id);
            self::maybeRetry((int)$id,$result);
          },10,1);
        add_action('rest_api_init',[self::class,'registerRoutes']);
    }
    private static function enabled():bool {
        return in_array(get_option(self::OPTION,false),[true,1,'1'],true);
    }
    private static function maybeRetry(int $id,$result):void {
        $reason=is_wp_error($result)?$result->get_error_code():
            (($result['crm']??null)==='pending'?($result['reason']??'crm_pending'):
            (($result['case']??null)==='pending'?($result['reason']??'case_pending'):null));
        if($reason===null){delete_transient('qa_inquiry_retry_'.$id);return;}
        $eligible=[
          'native_inquiry_components_unavailable','request_transaction_not_started',
          'request_claim_insert_failed','request_batch_insert_failed',
          'native_crm_contact_creation_failed','native_crm_contact_link_failed',
          'native_crm_verified_source_unavailable','native_case_schema_not_ready',
          'native_case_persist_failed'
        ];
        if(!in_array($reason,$eligible,true))return;
        $key='qa_inquiry_retry_'.$id;
        $count=(int)get_transient($key);
        if($count>=3)return;
        set_transient($key,$count+1,DAY_IN_SECONDS);
        if(!wp_next_scheduled('local_native_inquiry_handoff_retry_v1',[$id]))
            wp_schedule_single_event(time()+5*MINUTE_IN_SECONDS,
             'local_native_inquiry_handoff_retry_v1',[$id]);
    }
    public static function registerRoutes():void {
        register_rest_route('shared-product/v1',
          '/inquiry-handoff/(?P<submission_id>[1-9][0-9]*)',[
          'methods'=>WP_REST_Server::CREATABLE,
          'permission_callback'=>static fn()=>current_user_can('manage_options'),
          'callback'=>static function(WP_REST_Request $request){
            if(!self::enabled())
              return new WP_Error('inquiry_handoff_disabled','Not enabled',['status'=>409]);
            $out=self::stage((int)$request['submission_id']);
            if(is_wp_error($out))
              return new WP_Error($out->get_error_code(),
                'Submission could not be verified for inquiry handoff',['status'=>422]);
            $response=rest_ensure_response(['schema'=>1,'result'=>$out]);
            $response->header('Cache-Control','private,no-store');
            return $response;
          }
        ]);
    }
    private static function ready():bool {
        return class_exists('\Commerce\Composition\RequestReview') &&
          \Commerce\Composition\RequestCheckout::ready() &&
          \Commerce\Composition\ClaimLedger::ready() &&
          \Commerce\Composition\RequestReview::ready() &&
          \Commerce\Composition\ContactBridge::ready() &&
          function_exists('FluentCrmApi');
    }
    /** Validate customer transport preferences against the current native provider.
     * These are request-review preferences, never a priced/available booking.
     */
    private static function verifiedTransports(int $tour,int $rate,$raw):array|\WP_Error {
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>30)
            return new WP_Error('original_transport_list_invalid');
        if(!$raw)return [];
        if($rate<1||!class_exists('Shared_Product_Presentation_Transport_Context_v1'))
            return new WP_Error('original_transport_rate_required');
        $categories=Shared_Product_Presentation_Transport_Context_v1::categories();
        $seen=[];$valid=[];
        foreach($raw as $row){
            if(!is_array($row)||
               array_diff(array_keys($row),['transport_id','group','review_required']))
                return new WP_Error('original_transport_fields_invalid');
            $id=$row['transport_id']??null;$group=$row['group']??null;
            if(!is_int($id)||$id<1||isset($seen[$id])||!is_string($group)||
               !str_starts_with($group,'transport-')||
               ($row['review_required']??null)!==true)
                return new WP_Error('original_transport_selection_invalid');
            $key=substr($group,strlen('transport-'));
            $cat=$categories[$key]??null;
            if(!is_array($cat)||
               (string)get_post_meta($tour,(string)$cat['enabled'],true)!=='1')
                return new WP_Error('original_transport_group_disabled');
            $links=get_post_meta($tour,(string)$cat['source'],true);
            if(!in_array($id,array_map('absint',is_array($links)?$links:[]),true))
                return new WP_Error('original_transport_not_linked');
            $candidate=Shared_Product_Presentation_Transport_Context_v1::candidate($id,$key);
            if(empty($candidate['ok']))
                return new WP_Error('original_transport_not_eligible');
            $seen[$id]=true;
            $valid[]=['transport_id'=>$id,'group'=>$group,'review_required'=>true];
        }
        return $valid;
    }
    private static function verified(int $sid):array|\WP_Error {
        if($sid<1)return new WP_Error('original_submission_id_invalid');
        $proof=\Commerce\Composition\SubmissionProvenance::verify($sid,self::FORM_ID);
        if(is_wp_error($proof))return $proof;
        global $wpdb;
        $tbl=$wpdb->prefix.'fluentform_submissions';
        $row=$wpdb->get_row($wpdb->prepare(
          "SELECT form_id,status,response FROM $tbl WHERE id=%d AND form_id=%d",
          $sid,self::FORM_ID),ARRAY_A);
        if(!is_array($row)||in_array($row['status'],['spam','trashed','deleted'],true))
            return new WP_Error('original_form_not_active');
        $payload=json_decode((string)$row['response'],true);
        if(!is_array($payload))return new WP_Error('original_form_payload_invalid');
        $intent=$payload['_shared_tour_booking_intent_v2']??null;
        if(!is_array($intent)||(int)($intent['version']??0)!==2||
           ($intent['checkout_ready']??true)!==false ||
           ($intent['order_created']??true)!==false||
           ($intent['availability']??'')!=='not_checked')
            return new WP_Error('nonfinancial_review_intent_not_verified');
        $tour=(int)($intent['tour_id']??0);
        $rate=(int)($intent['rate_id']??0);
        $mode=(string)($intent['source']??'');
        if(!in_array($mode,['direct','rate'],true)||$tour<1||
           get_post_type($tour)!=='tours-and-activities'||
           get_post_status($tour)!=='publish')
            return new WP_Error('original_tour_source_invalid');
        // A read-only QA fixture may be displayed in the Local Lab, but
        // must NEVER create a real request/CRM case or financial order.
        $tourPost=get_post($tour);
        if($tourPost->post_password!==''||
           (string)get_post_meta($tour,'_booking_experience_qa_pricing_fixture',true)!=='')
            return new WP_Error('original_qa_fixture_write_protected');
        $siblings=class_exists('ETG_Tour_Rate_Display')
            ?ETG_Tour_Rate_Display::language_ids($tour):[$tour];
        if($mode==='rate' && ($rate<1||
           get_post_type($rate)!=='tour-rates'||
           get_post_status($rate)!=='publish'||
           !in_array((int)get_post_meta($rate,'related_tour_id',true),
              array_map('intval',$siblings),true)))
            return new WP_Error('original_tour_rate_ownership_mismatch');
        if($mode==='direct' && $rate!==0)
            return new WP_Error('original_direct_source_must_not_infer_rate');
        if($mode==='rate'){
            $ratePost=get_post($rate);
            if($ratePost->post_password!==''||
               (string)get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true)!=='')
                return new WP_Error('original_qa_rate_write_protected');
        }
        $date=(string)($intent['travel_date']??'');
        $formDate=(string)($payload['datetime']??'');
        $dates=[];
        foreach(['Y-m-d','d-M-Y','d/m/Y','m/d/Y'] as $fmt){
            $d=DateTimeImmutable::createFromFormat('!'.$fmt,trim($formDate));
            if($d && $d->format($fmt)===trim($formDate))
                $dates[]=$d->format('Y-m-d');
        }
        if(!in_array($date,$dates,true))
            return new WP_Error('original_form_date_mismatch');
        $adults=(int)($payload['no_of_adults']??0);
        if($adults<1||$adults>100)
            return new WP_Error('original_guest_count_invalid');
        $stays=$intent['stay_stages']??[];
        if(!is_array($stays)||!array_is_list($stays)||count($stays)>20)
            return new WP_Error('original_stay_selection_invalid');
        if($mode==='direct' && $stays)
            return new WP_Error('original_direct_stays_need_a_verified_rate');
        foreach($stays as $s){
            if(!is_array($s)||!is_string($s['stage_key']??null)||
               (int)($s['property_id']??0)<1 ||
               !in_array($s['price_policy']??'',
                    ['included','additional','inquiry'],true))
                return new WP_Error('original_stay_line_invalid');
            $source=shared_form6_stay_stage($tour,$rate,
                $s['stage_key'],(int)$s['property_id']);
            if(is_wp_error($source)||
               ($source['price_policy']??'')!==$s['price_policy'])
                return new WP_Error('original_stay_policy_mismatch');
            if(isset($s['unit_id'],$s['board_id'])){
                $catalog=shared_form6_stay_catalog($tour,$rate,
                    $s['stage_key'],(int)$s['property_id']);
                if(is_wp_error($catalog))return $catalog;
                $valid=false;
                foreach($catalog['units'] as $unit){
                  if($unit['id']!==(int)$s['unit_id'])continue;
                  foreach($unit['boards'] as $board)
                    if($board['id']===(int)$s['board_id'])$valid=true;
                }
                if(!$valid)return new WP_Error('original_room_board_unlinked');
            }
        }
        $email=sanitize_email((string)($payload['email']??''));
        if(!is_email($email) || mb_strtolower($email)!==
           mb_strtolower((string)($payload['email']??'')))
           return new WP_Error('original_contact_email_invalid');
        $first=sanitize_text_field((string)($payload['names']['first_name']??$payload['first_name']??''));
        $last=sanitize_text_field((string)($payload['names']['last_name']??$payload['last_name']??''));
        $transportPreferences=self::verifiedTransports(
            $tour,$rate,$intent['transport_options']??[]);
        if(is_wp_error($transportPreferences))return $transportPreferences;
        $scope=[
          'v'=>1,'form_id'=>self::FORM_ID,'submission_id'=>$sid,
          'tour_id'=>$tour,'rate_id'=>$rate,'travel_date'=>$date,
          'adults'=>$adults,'stays'=>$stays,
          'extras'=>$intent['extras']??[],
        ];
        if($transportPreferences)$scope['transport_options']=$transportPreferences;
        if($mode==='direct')$scope['source_mode']='direct';
        $extras=$intent['extras']??[];
        if(!is_array($extras)||!array_is_list($extras)||count($extras)>15)
            return new WP_Error('original_extra_selection_invalid');
        $allowed=array_map('intval',(array)get_post_meta($tour,'tour_optional_extras',true));
        foreach($extras as $extra){
            if(!is_array($extra)||!is_int($extra['tour_id']??null)||
               !is_int($extra['persons']??null)||
               !in_array($extra['tour_id'],$allowed,true)||
               get_post_type($extra['tour_id'])!=='tours-and-activities'||
               get_post_status($extra['tour_id'])!=='publish'||
               $extra['persons']<1||
               $extra['persons']>$adults+(int)($payload['no_of_children']??0))
               return new WP_Error('original_extra_source_invalid');
        }
        return ['source'=>$scope,'email'=>$email,
            'first'=>$first,'last'=>$last];
    }
    public static function stage(int $sid):array|\WP_Error {
        if(!self::ready())return new WP_Error('native_inquiry_components_unavailable');
        $original=self::verified($sid);
        if(is_wp_error($original))return $original;
        $request=self::ensureRegistry($original['source']);
        if(is_wp_error($request))return $request;
        $crm=self::ensureServiceContact($original,$request['request_id']);
        if(is_wp_error($crm)){
            do_action('local_native_inquiry_contact_link_pending',
                $request['request_id'],$crm->get_error_code());
            return ['request'=>$request,'crm'=>'pending',
                'reason'=>$crm->get_error_code()];
        }
        $case=self::ensureCase($request['request_id'],$crm['contact_id'],
                              $original['source']);
        if(is_wp_error($case))
           return ['request'=>$request,'crm'=>$crm,'case'=>'pending',
                   'reason'=>$case->get_error_code()];
        return ['request'=>$request,'crm'=>$crm,'case'=>$case];
    }
    private static function ensureRegistry(array $src):array|\WP_Error {
        global $wpdb;
        $sid=(int)$src['submission_id'];$fid=(int)$src['form_id'];
        $digest=hash('sha256',wp_json_encode($src,JSON_UNESCAPED_SLASHES));
        $identity='original-form:v1:'.$fid.':'.$sid;
        $fingerprint=hash_hmac('sha256',$identity,wp_salt('secure_auth'));
        $owner=hash_hmac('sha256','background:'.$identity,wp_salt('auth'));
        $snapshot=[[
          'source_id'=>(int)$src['tour_id'],
          'rate_id'=>$src['rate_id']>0?(int)$src['rate_id']:null,'form_id'=>$fid,
          'source_submission_id'=>$sid,'source_digest'=>$digest,
          // Nonfinancial request selections remain review evidence, not
          // booking/price-authoritative components of the basket.
          'components'=>[],
          'source_preferences'=>[
            'stays'=>$src['stays']??[],
            'extras'=>$src['extras']??[],
            'transports'=>$src['transport_options']??[]
          ],
          'review_requirements'=>['source_stays','supplier_availability','price_approval'],
        ]];
        $json=wp_json_encode($snapshot,JSON_UNESCAPED_SLASHES);
        if(!is_string($json)||strlen($json)>65536)
            return new WP_Error('request_snapshot_invalid');
        $claims=$wpdb->prefix.'commerce_request_claims';
        $batch=$wpdb->prefix.'commerce_request_batches';
        $now=gmdate('Y-m-d H:i:s');
        if($wpdb->query('START TRANSACTION')===false)
            return new WP_Error('request_transaction_not_started');
        $committed=false;
        try {
            $insert=$wpdb->query($wpdb->prepare(
              "INSERT IGNORE INTO $claims
              (form_id,submission_id,selection_sha256,session_sha256,state,
               created_utc,updated_utc)
              VALUES (%d,%d,%s,%s,'intake',%s,%s)",
              $fid,$sid,$digest,$owner,$now,$now));
            if($insert===false)return new WP_Error('request_claim_insert_failed');
            $claim=$wpdb->get_row($wpdb->prepare(
              "SELECT selection_sha256,session_sha256,state
                FROM $claims WHERE form_id=%d AND submission_id=%d FOR UPDATE",
              $fid,$sid),ARRAY_A);
            if(!is_array($claim)||
               !hash_equals($claim['selection_sha256'],$digest)||
               !hash_equals($claim['session_sha256'],$owner)||
               $claim['state']!=='intake')
                return new WP_Error('request_original_claim_conflict');
            $insert=$wpdb->query($wpdb->prepare(
              "INSERT IGNORE INTO $batch
              (basket_sha256,session_sha256,sales_order_id,state,
               pricing_state,snapshot_json,created_utc)
              VALUES (%s,%s,NULL,'pending_review','not_quoted',%s,%s)",
              $fingerprint,$owner,$json,$now));
            if($insert===false)return new WP_Error('request_batch_insert_failed');
            $row=$wpdb->get_row($wpdb->prepare(
              "SELECT id,session_sha256,state,pricing_state,sales_order_id,snapshot_json
                FROM $batch WHERE basket_sha256=%s FOR UPDATE",
              $fingerprint),ARRAY_A);
            if(!is_array($row)||!hash_equals((string)$row['session_sha256'],$owner)||
                $row['state']!=='pending_review'||
                $row['pricing_state']!=='not_quoted'||
                $row['sales_order_id']!==null||
                !hash_equals((string)$row['snapshot_json'],$json))
                return new WP_Error('request_batch_source_conflict');
            if($wpdb->query('COMMIT')===false)
                return new WP_Error('request_commit_failed');
            $committed=true;
            $result=['status'=>$insert===1?'created':'already_exists',
              'request_id'=>(int)$row['id'],'state'=>'pending_review',
              'pricing_state'=>'not_quoted','woo_order_id'=>null,
              'checkout_authority'=>false];
            if($insert===1)
                do_action('commerce_composition_request_submitted',$result,$snapshot);
            return $result;
        }finally {
            if(!$committed)$wpdb->query('ROLLBACK');
        }
    }
    private static function ensureServiceContact(array $original,int $requestId):array|\WP_Error {
        global $wpdb;
        $email=$original['email'];
        $contacts=$wpdb->prefix.'fc_subscribers';
        $query=$wpdb->prepare("SELECT id,status FROM $contacts WHERE email=%s LIMIT 2",$email);
        $rows=$wpdb->get_results($query,ARRAY_A);
        if(count($rows)>1)return new WP_Error('native_contact_email_ambiguous');
        if($rows){
            $contactId=(int)$rows[0]['id'];
            $status=(string)$rows[0]['status']; // preserve opt-outs
        }else{
            $c=FluentCrmApi('contacts')->createOrUpdate([
              'email'=>$email,'first_name'=>$original['first'],
              'last_name'=>$original['last'],'status'=>'transactional'
            ],false,false);
            if(!is_object($c)||!is_numeric($c->id??null))
                return new WP_Error('native_crm_contact_creation_failed');
            $contactId=(int)$c->id;
            $status=(string)($c->status??'');
        }
        if($contactId<1 || !in_array($status,[
            'pending','subscribed','transactional','unsubscribed',
            'bounced','complained','spammed','suppressed'
        ],true))return new WP_Error('native_crm_contact_status_invalid');
        $sources=[
          ['fluent_form','tour_form6_inquiry',$original['source']['submission_id']],
          ['request','tour_request_review',$requestId],
        ];
        $table=$wpdb->prefix.'commerce_contact_links';
        foreach($sources as [$domain,$key,$sourceId]){
            $source=\Commerce\Composition\ContactBridge::sourceEvidence($key,(int)$sourceId);
            if(is_wp_error($source)||$source['domain']!==$domain)
                return new WP_Error('native_crm_verified_source_unavailable');
            $prior=$wpdb->get_col($wpdb->prepare(
              "SELECT DISTINCT contact_id FROM $table WHERE
               domain=%s AND source_key=%s AND source_id=%d AND role='requester'",
              $domain,$key,(int)$sourceId));
            if($prior && (count($prior)!==1||(int)$prior[0]!==$contactId))
                return new WP_Error('native_crm_source_contact_conflict');
            $n=$wpdb->query($wpdb->prepare(
              "INSERT IGNORE INTO $table
               (contact_id,domain,source_key,source_id,role,actor_id,created_utc)
               VALUES (%d,%s,%s,%d,'requester',0,%s)",
               $contactId,$domain,$key,(int)$sourceId,gmdate('Y-m-d H:i:s')));
            if($n===false)return new WP_Error('native_crm_contact_link_failed');
        }
        return ['contact_id'=>$contactId,'status'=>$status,
           'source_link_count'=>2,'marketing_opt_in_granted'=>false];
    }
    private static function ensureCase(int $requestId,int $contactId,array $src):array|\WP_Error {
        global $wpdb;
        if($requestId<1||$contactId<1||
           !\Commerce\Composition\IntakeCaseRegistry::ready())
            return new WP_Error('native_case_schema_not_ready');
        $route=\Commerce\Composition\IntakeModePolicy::resolve(
          'web',['tours-and-activities']);
        if(is_wp_error($route))return $route;
        if(($route['mode']??'')!=='crm_first')
            return new WP_Error('native_case_not_crm_first');
        $batches=$wpdb->prefix.'commerce_request_batches';
        $rows=$wpdb->get_row($wpdb->prepare(
          "SELECT snapshot_json,state,pricing_state,sales_order_id FROM $batches
            WHERE id=%d",$requestId),ARRAY_A);
        if(!is_array($rows)||$rows['state']!=='pending_review'||
           $rows['pricing_state']!=='not_quoted'||
           $rows['sales_order_id']!==null)
            return new WP_Error('native_case_original_request_invalid');
        $saved=json_decode((string)$rows['snapshot_json'],true);
        if(!is_array($saved)||count($saved)!==1||
           (int)($saved[0]['source_submission_id']??0)!==$src['submission_id']||
           (int)($saved[0]['source_id']??0)!==$src['tour_id']||
           (int)($saved[0]['rate_id']??0)!==$src['rate_id'])
           return new WP_Error('native_case_source_mismatch');
        $links=$wpdb->prefix.'commerce_contact_links';
        foreach([['fluent_form','tour_form6_inquiry',$src['submission_id']],
                 ['request','tour_request_review',$requestId]] as [$domain,$key,$id]){
            $actual=$wpdb->get_col($wpdb->prepare(
              "SELECT DISTINCT contact_id FROM $links WHERE domain=%s
              AND source_key=%s AND source_id=%d AND role='requester'",
              $domain,$key,(int)$id));
            if(count($actual)!==1||(int)$actual[0]!==$contactId)
                return new WP_Error('native_case_crm_relation_not_verified');
        }
        $t=$wpdb->prefix.'commerce_intake_cases';
        $sourceHash=hash('sha256',(string)$rows['snapshot_json']);
        $policyHash=(string)$route['policy_digest'];
        $insert=$wpdb->query($wpdb->prepare(
          "INSERT IGNORE INTO $t
           (request_id,source_sha256,policy_sha256,route_mode,
            source_channel,contact_id,woo_order_id,stage,actor_id,created_utc)
           VALUES (%d,%s,%s,'crm_first','web',%d,NULL,
             'awaiting_approved_business_board',0,%s)",
           $requestId,$sourceHash,$policyHash,$contactId,
           gmdate('Y-m-d H:i:s')));
        if($insert===false)return new WP_Error('native_case_persist_failed');
        $savedCase=$wpdb->get_row($wpdb->prepare(
          "SELECT source_sha256,policy_sha256,route_mode,contact_id,
            stage,woo_order_id FROM $t WHERE request_id=%d",
          $requestId),ARRAY_A);
        if(!is_array($savedCase)||
           !hash_equals($savedCase['source_sha256'],$sourceHash)||
           !hash_equals($savedCase['policy_sha256'],$policyHash)||
           $savedCase['route_mode']!=='crm_first'||
           (int)$savedCase['contact_id']!==$contactId||
           $savedCase['woo_order_id']!==null)
            return new WP_Error('native_case_existing_source_conflict');
        return ['request_id'=>$requestId,'status'=>$insert===1?'created':'already_exists',
          'stage'=>$savedCase['stage'],'mode'=>'crm_first',
          'woo_order_id'=>null,'supplier_booking_authorized'=>false];
    }
}
add_action('plugins_loaded',[Local_Native_Inquiry_Handoff_V1::class,'bootstrap'],90);
