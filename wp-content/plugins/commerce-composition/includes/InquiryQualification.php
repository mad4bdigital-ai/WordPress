<?php
declare(strict_types=1);
namespace Commerce\Composition;

/** Operator qualification of original Fluent Forms 7/8. No alternate Request or Quote pricing engine. */
final class InquiryQualification
{
    private static function table():string {global $wpdb;return $wpdb->prefix.'commerce_inquiry_qualifications';}
    public static function enabled():bool {
        return wp_get_environment_type()==='local'
            && defined('SHARED_PRODUCT_LAB_ONLY') && SHARED_PRODUCT_LAB_ONLY===true
            && get_option('commerce_inquiry_qualification_enabled',false)==='1';
    }
    public static function install():true|\WP_Error {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=self::table();
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL,
            submission_id bigint(20) unsigned NOT NULL,
            source_sha256 char(64) NOT NULL,
            tour_id bigint(20) unsigned NOT NULL,
            rate_id bigint(20) unsigned NULL DEFAULT NULL,
            audience varchar(24) NOT NULL,
            occupancy varchar(24) NOT NULL,
            travel_date char(10) NOT NULL,
            persons int(10) unsigned NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            state varchar(32) NOT NULL,
            created_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY original_submission (form_id,submission_id)
        ) {$wpdb->get_charset_collate()};");
        return self::ready()?true:new \WP_Error('qualification_schema_not_ready');
    }
    public static function ready():bool {
        global $wpdb;$t=self::table();
        return $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t))==='InnoDB';
    }
    private static function allowed(int $form):bool {
        $ids=get_option('commerce_unqualified_inquiry_form_ids',[]);
        return is_array($ids)&&in_array($form,array_map('intval',$ids),true);
    }
    private static function original(int $form,int $id):array|\WP_Error {
        if(!self::allowed($form)||$id<1)return new \WP_Error('qualification_form_disallowed');
        $proof=SubmissionProvenance::verify($id,$form);
        if(is_wp_error($proof))return $proof;
        global $wpdb;$t=$wpdb->prefix.'fluentform_submissions';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT response,status FROM $t WHERE id=%d AND form_id=%d",$id,$form),ARRAY_A);
        if(!is_array($row)||in_array($row['status'],['spam','trashed','deleted'],true))
            return new \WP_Error('qualification_source_inactive');
        $body=(string)$row['response'];
        if(strlen($body)>100000)return new \WP_Error('qualification_source_too_large');
        $data=json_decode($body,true);
        if(!is_array($data))return new \WP_Error('qualification_source_invalid');
        $raw=(string)($data['datetime']??'');
        $date=null;
        foreach(['Y-m-d','d-M-Y','d-M-y','d/m/Y','m/d/Y'] as $fmt){
            $d=\DateTimeImmutable::createFromFormat('!'.$fmt,trim($raw));
            if($d&&$d->format($fmt)===trim($raw)){$date=$d->format('Y-m-d');break;}
        }
        $adults=(int)($data['no_of_adults']??0);
        $children=(int)($data['no_of_children']??0);
        $infants=(int)($data['no_of_infants']??0);
        $persons=$adults+$children;
        if($adults<1||$persons>100||min($children,$infants)<0||$infants>100)
            return new \WP_Error('qualification_guest_count_unavailable');
        if($date===null)return new \WP_Error('qualification_travel_date_unavailable');
        return [
            'form_id'=>$form,'submission_id'=>$id,'date'=>$date,'persons'=>$persons,
            'adults'=>$adults,'children'=>$children,'infants'=>$infants,
            'source_sha256'=>hash_hmac('sha256',$body,wp_salt('secure_auth'))
        ];
    }
    private static function source(int $tour,?int $rate,?string $travelDate):true|\WP_Error {
        if($tour<1||get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')
            return new \WP_Error('qualification_tour_unavailable');
        if(get_post_meta($tour,'_booking_experience_qa_pricing_fixture',true)!=='')
            return new \WP_Error('qualification_test_fixture_forbidden');
        if((string)get_post_meta($tour,'tour_public_status',true)!=='1')
            return new \WP_Error('qualification_service_not_activated');
        if($rate!==null){
            if($rate<1||get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish'
                ||(int)get_post_meta($rate,'related_tour_id',true)!==$tour)
                return new \WP_Error('qualification_rate_not_owned');
            if(get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true)!=='')
                return new \WP_Error('qualification_test_rate_forbidden');
            if((int)get_post_meta($rate,'_etg_last_synced_tour_id',true)!==$tour)
                return new \WP_Error('qualification_rate_mirror_mismatch');
            if(in_array(get_post_meta($rate,'stop_sale',true),['1',1,true],true))
                return new \WP_Error('qualification_rate_stop_sale');
            if(in_array(get_post_meta($rate,'avilabile_status',true),['0',0,false],true))
                return new \WP_Error('qualification_rate_unavailable');
            if(!preg_match('/^[A-Z]{3}$/D',(string)get_post_meta($rate,'base_currency',true)))
                return new \WP_Error('qualification_rate_currency_invalid');
            $parsed=is_string($travelDate)
                ?\DateTimeImmutable::createFromFormat('!Y-m-d',$travelDate):false;
            if(!is_string($travelDate)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$travelDate)
                ||!$parsed||$parsed->format('Y-m-d')!==$travelDate)
                return new \WP_Error('qualification_travel_date_invalid');
            $start=get_post_meta($rate,'tour_rate_start_date',true);
            $end=get_post_meta($rate,'tour_rate_end_date',true);
            if(!is_scalar($start)||!is_scalar($end)||!ctype_digit((string)$start)
                ||!ctype_digit((string)$end)||(int)$start<946684800||(int)$end>4102444799
                ||(int)$start>(int)$end)
                return new \WP_Error('qualification_rate_window_invalid');
            if(!TourRateEligibility::withinWindow($travelDate,(int)$start,(int)$end))
                return new \WP_Error('qualification_rate_outside_window');
            $seasons=wp_get_object_terms($rate,'season',['fields'=>'ids']);
            if(is_wp_error($seasons)||!is_array($seasons)||count($seasons)!==1)
                return new \WP_Error('qualification_rate_season_not_unique');
            $season=(int)$seasons[0];
            if(get_term_meta($season,'season_enabled',true)!=='1'
                ||!TourRateEligibility::withinSeason($travelDate,$season))
                return new \WP_Error('qualification_rate_outside_season');
        }
        return true;
    }
    /**
     * Reuse the same server-side qualification policy for dropdown discovery.
     * No source form answers returned and no Rate selection is persisted.
     */
    public static function eligibleRateOptions(int $form,int $submission,array $byTour):array|\WP_Error
    {
        if(!self::enabled()||!RequestReview::authorized())
            return new \WP_Error('qualification_forbidden');
        $original=self::original($form,$submission);
        if(is_wp_error($original))return $original;
        $eligible=[];
        foreach(array_slice($byTour,0,500,true) as $tour=>$rates){
            $tourId=(int)$tour;
            if($tourId<1||!is_array($rates))continue;
            foreach(array_slice($rates,0,100) as $rate){
                $rateId=(int)$rate;
                if($rateId<1)continue;
                if(self::source($tourId,$rateId,$original['date'])===true)
                    $eligible[$tourId][]=$rateId;
            }
        }
        return $eligible;
    }
    public static function qualify(int $form,int $id,int $tour,?int $rate,
        string $audience,string $occupancy):array|\WP_Error
    {
        global $wpdb;
        if(!self::enabled()||!self::ready()||!RequestReview::authorized())
            return new \WP_Error('qualification_forbidden');
        if(!in_array($audience,['general','egyptians'],true)
            ||!in_array($occupancy,['single','double','triple'],true))
            return new \WP_Error('qualification_context_invalid');
        $original=self::original($form,$id);
        if(is_wp_error($original))return $original;
        $source=self::source($tour,$rate,$original['date']);
        if(is_wp_error($source))return $source;
        $t=self::table();
        $sql=$wpdb->prepare(
            "INSERT IGNORE INTO $t (form_id,submission_id,source_sha256,tour_id,rate_id,
             audience,occupancy,travel_date,persons,actor_id,state,created_utc)
             VALUES (%d,%d,%s,%d,".($rate===null?'NULL':'%d').",%s,%s,%s,%d,%d,%s,%s)",
            ...array_merge([$form,$id,$original['source_sha256'],$tour],$rate===null?[]:[$rate],
             [$audience,$occupancy,$original['date'],$original['persons'],
              get_current_user_id(),'quote_draft',gmdate('Y-m-d H:i:s')])
        );
        $insert=$wpdb->query($sql);
        if($insert===false)return new \WP_Error('qualification_claim_failed');
        $saved=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE form_id=%d AND submission_id=%d",$form,$id),ARRAY_A);
        if(!is_array($saved)||!hash_equals($saved['source_sha256'],$original['source_sha256'])
            ||(int)$saved['tour_id']!==$tour
            ||(int)($saved['rate_id']??0)!==($rate??0)
            ||$saved['travel_date']!==$original['date']
            ||(int)$saved['persons']!==$original['persons']
            ||$saved['audience']!==$audience||$saved['occupancy']!==$occupancy)
            return new \WP_Error('qualification_conflict_requires_review');
        return ['qualification_id'=>(int)$saved['id'],'reused'=>$insert===0,
            'status'=>'quote_draft','source_owner'=>'fluentform_submissions',
            'request_id'=>null,'sales_order_id'=>null,'supplier_booking_id'=>null];
    }
    public static function preview(int $form,int $id):array|\WP_Error
    {
        global $wpdb;
        if(!self::enabled()||!self::ready()||!RequestReview::authorized())
            return new \WP_Error('qualification_forbidden');
        $t=self::table();
        $saved=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE form_id=%d AND submission_id=%d",$form,$id),ARRAY_A);
        if(!is_array($saved))return new \WP_Error('qualification_not_found');
        $original=self::original($form,$id);
        if(is_wp_error($original)||!hash_equals($saved['source_sha256'],$original['source_sha256'])
           ||$saved['travel_date']!==$original['date']
           ||(int)$saved['persons']!==$original['persons'])
            return new \WP_Error('qualification_source_changed');
        $tour=(int)$saved['tour_id'];
        $rate=$saved['rate_id']===null?null:(int)$saved['rate_id'];
        $verified=self::source($tour,$rate,$original['date']);
        if(is_wp_error($verified))return $verified;
        $result=['qualification_id'=>(int)$saved['id'],
            'form_id'=>$form,'submission_id'=>$id,'tour_id'=>$tour,'rate_id'=>$rate,
            'travel_date'=>$original['date'],'persons'=>$original['persons'],
            'audience'=>$saved['audience'],'occupancy'=>$saved['occupancy'],
            'status'=>'requires_review','quote_state'=>'requires_review',
            'preview_amount'=>null,'currency'=>null,'reason'=>'price_not_certified',
            'manager_approved'=>false,'traveler_accepted'=>false,
            'checkout_authorized'=>false,'supplier_booking_authorized'=>false];
        // This single-service preview cannot price child/infant ages,
        // and cannot certify the multi-service plan in Form 7.
        if($original['children']>0||$original['infants']>0){
            $result['reason']='age_pricing_requires_review';return $result;
        }
        if($form===7){
            $result['reason']='custom_multi_service_scope_requires_review';return $result;
        }
        if(!function_exists('shared_product_pricing_quote_contract_v2')){
            $result['reason']='pricing_provider_unavailable';return $result;
        }
        $configuration=[
            'schema_version'=>2,'tour_id'=>$tour,
            'source'=>$rate===null?'direct':'rate','rate_id'=>$rate,
            'travel_date'=>$original['date'],'audience'=>$saved['audience'],
            'occupancy'=>$saved['occupancy'],'quantities'=>['persons'=>$original['persons']],
            'booking_intent'=>'quote','extras'=>[],'selections'=>[]
        ];
        $quote=shared_product_pricing_quote_contract_v2($configuration);
        if(is_wp_error($quote)){$result['reason']=$quote->get_error_code();return $result;}
        if(!is_array($quote)||!is_numeric($quote['total']??null)||
            (float)$quote['total']<=0||!preg_match('/^[A-Z]{3}$/D',(string)($quote['currency']??''))){
            $result['reason']='quote_not_verified';return $result;
        }
        $result['quote_state']='preview_only';
        $result['status']='quote_draft';
        $result['preview_amount']=(float)$quote['total'];
        $result['currency']=$quote['currency'];
        $result['reason']='awaiting_manager_quote_policy';
        return $result;
    }
}
