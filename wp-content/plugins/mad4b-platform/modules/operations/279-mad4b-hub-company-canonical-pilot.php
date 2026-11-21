<?php
/**
 * Plugin Name: MAD4B Company Canonical Catalog Pilot (Staging)
 * Description: Private tenant-scoped selection drafts from reviewed source registry, no publishing or delivery.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Company_Canonical_Pilot_V1 {
    const OPTION='mad4b_hub_company_canonical_drafts_v1';
    const SOURCE='tourism-almallahgroup';
    public static function admin(): bool {
        return class_exists('MAD4B_Hub_Canonical_Registry_V1') &&
            MAD4B_Hub_Canonical_Registry_V1::site() && current_user_can('manage_options');
    }
    private static function error($code,$status=409) {
        return new WP_Error('company_catalog_'.$code,'Company canonical catalog operation refused',['status'=>$status]);
    }
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix.'mad4b_hub_canonical_entities';
    }
    private static function bound_company($code) {
        if (!is_string($code)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))return self::error('invalid_company');
        $all=get_option('mad4b_tourism_company_site_registry_v1',[]);
        $c=is_array($all)?($all[$code]??null):null;
        if (!is_array($c)||($c['company_code']??'')!==$code ||
            empty($c['host_binding_verified']) ||
            !wp_is_uuid((string)($c['company_site_uuid']??'')) ||
            !wp_is_uuid((string)($c['native_site_profile_uuid']??'')) ||
            !preg_match('/^[a-f0-9]{64}$/D',(string)($c['native_profile_digest']??'')))
            return self::error('company_not_verified',403);
        return $c;
    }
    private static function eligible($row): bool {
        if (!is_array($row)||($row['state']??'')!=='private_preview'||($row['company_id']??'')!==self::SOURCE) return false;
        $s=json_decode($row['snapshot_json']??'',true);
        if (!is_array($s)||($s['company_id']??'')!==self::SOURCE ||
            ($s['contract']??'')!=='mad4b.hub.private-canonical-source.v1' ||
            empty($s['rights']['reference_only'])||
            !empty($s['rights']['may_distribute'])||!empty($s['rights']['may_publish'])||
            !empty($s['rights']['may_checkout'])||!empty($s['commerce_eligible'])||
            !empty($s['identity_review_hold']))return false;
        $sourceCompany=(string)get_post_meta((int)$row['source_hub_id'],'_mad4b_hub_import_owner',true);
        return $sourceCompany===self::SOURCE;
    }
    public static function candidates($company='wayfatours',$type='tours-and-activities',$language='en',$offset=0,$limit=25) {
        if (!self::admin())return self::error('permission',403);
        $bound=self::bound_company($company);
        if (is_wp_error($bound))return $bound;
        if (!in_array($type,MAD4B_Hub_Canonical_Registry_V1::ALLOWED_TYPES,true) ||
            !preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',$language)||
            !is_int($offset)||$offset<0||!is_int($limit)||$limit<1||$limit>50)
            return self::error('invalid_filter');
        global $wpdb;
        $table=self::table();
        $total=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE company_id=%s AND source_type=%s AND language=%s AND state='private_preview'",
            self::SOURCE,$type,$language));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE company_id=%s AND source_type=%s AND language=%s AND state='private_preview' ORDER BY id ASC LIMIT %d OFFSET %d",
            self::SOURCE,$type,$language,$limit,$offset),ARRAY_A);
        $items=[];
        foreach($rows as $r){
            if(!self::eligible($r))return self::error('source_rights_or_identity_changed');
            $s=json_decode($r['snapshot_json'],true);
            $items[]=[
                'canonical_id'=>(int)$r['id'],'source_hub_id'=>(int)$r['source_hub_id'],
                'language'=>$r['language'],'type'=>$r['source_type'],
                'title'=>(string)($s['fields']['title']??''),
                'snapshot_sha256'=>$r['snapshot_hash'],
                'source_company'=>self::SOURCE,'status'=>'PRIVATE_PREVIEW_ONLY',
                'may_publish'=>false,'may_checkout'=>false,
            ];
        }
        return [
            'contract'=>'mad4b.hub.company-canonical-candidates.v1',
            'company_code'=>$company,'company_site_uuid'=>$bound['company_site_uuid'],
            'source_company'=>self::SOURCE,'type'=>$type,'language'=>$language,
            'total'=>$total,'offset'=>$offset,'limit'=>$limit,'items'=>$items,
            'commercial_distribution_eligible'=>false,'uses_canonical_review_holds'=>true,
        ];
    }
    public static function selection($company='wayfatours') {
        if (!self::admin())return self::error('permission',403);
        $bound=self::bound_company($company);
        if(is_wp_error($bound))return $bound;
        $a=get_option(self::OPTION,[]);
        $s=is_array($a)?($a[$company]??[]):[];
        return [
            'company_code'=>$company,'site_uuid'=>$bound['company_site_uuid'],
            'revision'=>(int)($s['revision']??0),
            'status'=>(string)($s['status']??'not_selected'),
            'items'=>is_array($s['items']??null)?$s['items']:[],
            'receipt_sha256'=>$s['receipt_sha256']??null,
            'may_distribute'=>false,'may_publish'=>false,'may_checkout'=>false
        ];
    }
    public static function save_draft($company,array $canonicalIds,$expectedRevision,$note='') {
        if(!self::admin())return self::error('permission',403);
        $bound=self::bound_company($company);
        if(is_wp_error($bound))return $bound;
        if(!is_int($expectedRevision)||$expectedRevision<0 ||
            !is_string($note)||mb_strlen($note)>500||count($canonicalIds)>30)
            return self::error('invalid_draft');
        $ids=[];
        foreach($canonicalIds as $id){
            if(!is_int($id)||$id<1||isset($ids[$id]))return self::error('duplicate_or_invalid_id');
            $ids[$id]=true;
        }
        $a=get_option(self::OPTION,[]);
        if(!is_array($a))$a=[];
        $old=$a[$company]??[];
        if((int)($old['revision']??0)!==$expectedRevision) return self::error('revision_conflict');
        global $wpdb;
        $table=self::table();
        $items=[];
        foreach(array_keys($ids) as $id){
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id),ARRAY_A);
            if(!self::eligible($row))return self::error('unverified_candidate');
            $s=json_decode($row['snapshot_json'],true);
            $items[]=[
                'canonical_id'=>$id,'snapshot_sha256'=>$row['snapshot_hash'],
                'source_hub_id'=>(int)$row['source_hub_id'],
                'source_id'=>(int)($s['source_id']??0),
                'source_type'=>$row['source_type'],'language'=>$row['language']
            ];
        }
        usort($items,static function($x,$y){return $x['canonical_id']<=>$y['canonical_id'];});
        $rev=$expectedRevision+1;
        $receipt=hash('sha256',wp_json_encode([
            'company'=>$company,'site_uuid'=>$bound['company_site_uuid'],
            'revision'=>$rev,'items'=>$items],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $a[$company]=[
            'revision'=>$rev,'status'=>'draft_private_preview',
            'company_site_uuid'=>$bound['company_site_uuid'],
            'source_company'=>self::SOURCE,'items'=>$items,
            'note'=>sanitize_textarea_field($note),'receipt_sha256'=>$receipt,
            'updated_at'=>gmdate('c'),'actor_id'=>get_current_user_id()
        ];
        if(!update_option(self::OPTION,$a,false)) {
            $saved=get_option(self::OPTION,[]);
            if(($saved[$company]['receipt_sha256']??'')!==$receipt)return self::error('persist_failed');
        }
        $s=self::selection($company);
        return is_wp_error($s)?$s:$s+['readback_verified'=>hash_equals($receipt,(string)$s['receipt_sha256'])];
    }
    public static function plan($company='wayfatours') {
        $s=self::selection($company);
        if(is_wp_error($s))return $s;
        global $wpdb;
        $table=self::table();
        $changed=[];$confirmed=[];
        foreach($s['items'] as $item){
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",(int)($item['canonical_id']??0)),ARRAY_A);
            if(!self::eligible($row) ||
                !hash_equals((string)($item['snapshot_sha256']??''),(string)($row['snapshot_hash']??''))){
                $changed[]=$item['canonical_id']??null;continue;
            }
            $confirmed[]=$item['canonical_id'];
        }
        // Resolve price and rate references against the same canonical IDs.
        // A calendar-valid source rate is NOT confirmed inventory or a payable quote.
        $priceEvidence=[];
        $referenceRates=0;$onDate=gmdate('Y-m-d');
        foreach($confirmed as $id){
            $pricing=class_exists('MAD4B_Hub_Source_Price_Evidence_V1')?
                MAD4B_Hub_Source_Price_Evidence_V1::quote_preview((int)$id,$onDate):null;
            if(is_wp_error($pricing)||!is_array($pricing)){
                $priceEvidence[]=['canonical_id'=>(int)$id,'pricing_source'=>'not_available',
                    'commercial_quote'=>false];
                continue;
            }
            $rateCount=count($pricing['source_rates']??[]);
            $referenceRates+=$rateCount;
            $inDate=0;$invalidDate=0;
            foreach(($pricing['source_rates']??[]) as $rate){
                if(!empty($rate['valid_on_date']) && empty($rate['stop_sale']) &&
                    empty($rate['invalid_source_date_range']))$inDate++;
                if(!empty($rate['invalid_source_date_range']))$invalidDate++;
            }
            $priceEvidence[]=[
                'canonical_id'=>(int)$id,'starting_price_present'=>!empty($pricing['starting_prices']),
                'source_rate_count'=>$rateCount,'rates_in_date_window'=>$inDate,
                'invalid_date_ranges'=>$invalidDate,'availability_confirmed'=>false,
                'commercial_quote'=>false
            ];
        }
        return [
            'contract'=>'mad4b.hub.company-canonical-plan.v1',
            'company'=>$company,'revision'=>$s['revision'],'selected'=>count($s['items']),
            'unchanged_verified'=>count($confirmed),'changed_or_blocked'=>$changed,
            'selection_valid'=>count($changed)===0 && $s['status']==='draft_private_preview',
            'execution'=>'BLOCKED_PENDING_RIGHTS_PRICING_TENANT_READINESS',
            'blockers'=>['content_usage_rights','company_rates_and_availability',
                'tenant_dynamic_template_readiness','wpml_and_media_mapping','commercial_checkout_readback'],
            'price_as_of'=>$onDate,'source_rate_references'=>$referenceRates,
            'pricing_evidence'=>$priceEvidence,'company_price_override_applied'=>false,
            'preview_plan_only'=>true,'may_deliver'=>false,'may_publish'=>false,'may_checkout'=>false,
            'receipt_sha256'=>$s['receipt_sha256']
        ];
    }
    public static function readiness($company='wayfatours') {
        if(!self::admin())return self::error('permission',403);
        $bound=self::bound_company($company);
        if(is_wp_error($bound))return $bound;
        $r=get_option('mad4b_company_readiness_workflow_status_v1',[]);
        $report=is_array($r)?($r[$company]??null):null;
        if(!is_array($report) || ($report['site_uuid']??'')!==$bound['company_site_uuid'])
            return self::error('readiness_report_missing');
        $steps=[];foreach(($report['stages']??[]) as $step){
            $state=(string)($step['state']??'UNKNOWN');
            $steps[$state]=($steps[$state]??0)+1;
        }
        $canonical=MAD4B_Hub_Canonical_Registry_V1::status();
        $pricing=class_exists('MAD4B_Hub_Source_Price_Evidence_V1')?
            MAD4B_Hub_Source_Price_Evidence_V1::status():[];
        $plan=self::plan($company);
        if(is_wp_error($canonical)||is_wp_error($pricing)||is_wp_error($plan))
            return self::error('dependency_unavailable');
        $states=[];foreach($canonical['entity_states']??[] as $x)$states[$x['state']]=(int)$x['n'];
        $reviews=[];foreach($canonical['review_states']??[] as $x)$reviews[$x['decision']]=(int)$x['n'];
        $gaps=class_exists('MAD4B_Hub_Canonical_Gaps_V1')?
            MAD4B_Hub_Canonical_Gaps_V1::status():[];
        return ['contract'=>'mad4b.hub.wayfatours-progress-readback.v1',
            'company_code'=>$company,'site_uuid'=>$bound['company_site_uuid'],
            'staging_canonical'=>['records'=>array_sum($states),'private_preview'=>$states['private_preview']??0,
                'review_hold'=>$states['review_hold']??0,'reviews'=>$reviews],
            'source_data_gaps'=>[
                'open_tasks'=>is_array($gaps)?($gaps['count']??null):null,
                'by_code'=>is_array($gaps)?($gaps['by_code']??[]):[],
                'source_first_research_pending'=>true,
            ],
            'source_pricing'=>['records'=>$pricing['source_records']??0,
                'rates'=>$pricing['rate_rows']??0,
                'invalid_date_ranges'=>$pricing['last_receipt']['invalid_date_range_rates']??null,
                'verified_checkout'=>false],
            'tenant_selection'=>['draft_revision'=>$plan['revision'],'selected'=>$plan['selected'],
                'verified'=>$plan['unchanged_verified'],'source_rate_refs'=>$plan['source_rate_references'],
                'delivery_authorized'=>false],
            'original_company_readiness'=>['result'=>$report['result']??'UNKNOWN',
                'checked_utc'=>$report['generated_utc']??null,'total_stages'=>count($report['stages']??[]),
                'stage_states'=>$steps],
            'blocking_dependencies'=>$plan['blockers'],
            'commercial_distribution_ready'=>false,'production_authorized'=>false,
            'source_data_not_shared_across_tenants'=>true];
    }
    public static function rest() {
        if(!self::admin() && !MAD4B_Hub_Canonical_Registry_V1::site())return;
        register_rest_route('mad4b-central/v1','/company-canonical/candidates',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'admin'],
            'callback'=>static function($r){
                $v=self::candidates((string)($r->get_param('company')??'wayfatours'),
                    (string)($r->get_param('type')??'tours-and-activities'),
                    (string)($r->get_param('language')??'en'),
                    max(0,(int)($r->get_param('offset')??0)),
                    min(50,max(1,(int)($r->get_param('limit')??25))));
                return is_wp_error($v)?$v:rest_ensure_response($v);
            }
        ]);
        register_rest_route('mad4b-central/v1','/company-canonical/readiness',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'admin'],
            'callback'=>static function($r){
                $v=self::readiness((string)($r->get_param('company')??'wayfatours'));
                return is_wp_error($v)?$v:rest_ensure_response($v);
            }
        ]);
        register_rest_route('mad4b-central/v1','/company-canonical/plan',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'admin'],
            'callback'=>static function($r){
                $v=self::plan((string)($r->get_param('company')??'wayfatours'));
                return is_wp_error($v)?$v:rest_ensure_response($v);
            }
        ]);
        register_rest_route('mad4b-central/v1','/company-canonical/draft',[
            'methods'=>'POST','permission_callback'=>[__CLASS__,'admin'],
            'callback'=>static function($r){
                $p=(array)$r->get_json_params();
                $v=self::save_draft((string)($p['company']??''),(array)($p['canonical_ids']??[]),
                    (int)($p['expected_revision']??-1),(string)($p['note']??''));
                return is_wp_error($v)?$v:rest_ensure_response($v);
            }
        ]);
    }
    public static function menu() {
        if(!MAD4B_Hub_Canonical_Registry_V1::site())return;
        add_submenu_page('edit.php?post_type=mad4b_hub_nile','Company Canonical Pilot',
            'Company Canonical Pilot','manage_options','mad4b-company-canonical-pilot',[__CLASS__,'screen']);
    }
    public static function screen() {
        if(!self::admin())return;
        $s=self::plan('wayfatours');$c=self::candidates('wayfatours','tours-and-activities','en',0,10);
        echo '<div class="wrap"><h1>Company Canonical Pilot — Wayfatours</h1>';
        echo '<p>Selection drafts only. No remote delivery, public publishing or checkout.</p>';
        $ready=self::readiness('wayfatours');
        if(!is_wp_error($ready)){
            echo '<h2>Readiness and Canonical Progress</h2><pre>'.
                esc_html(wp_json_encode($ready,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
        }
        echo '<h2>Current Plan</h2><pre>'.esc_html(wp_json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
        echo '<h2>Canonical Private Candidates</h2><pre>'.esc_html(wp_json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></div>';
    }
    public static function boot() {
        add_action('rest_api_init',[__CLASS__,'rest']);
        add_action('admin_menu',[__CLASS__,'menu']);
    }
}
MAD4B_Hub_Company_Canonical_Pilot_V1::boot();

