<?php
/**
 * Plugin Name: MAD4B Transport Start Price — Dual Role and Source Weight Policy
 * Description: Admin-only direct-price role and price-source selection for operational package costing. Keeps native booking authorization unchanged.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Transport_Start_Price_Roles_V1',false)) {
final class MAD4B_Transport_Start_Price_Roles_V1 {
    const KEY='_mad4b_transport_start_price_usage_v1';
    const REV='_mad4b_transport_start_price_usage_revision_v1';
    const HISTORY='_mad4b_transport_start_price_usage_history_v1';
    const NONCE='mad4b_transport_start_price_role_v1';
    const ACTION='mad4b_transport_start_price_role_v1';
    const MODES=[
        'indicative_only'=>'Indicative selling reference (not a supplier cost)',
        'cost_reference_only'=>'Use direct price as planning cost reference (not a selling quote)',
        'both'=>'Indicative price and planning cost reference — separate roles'
    ];

    public static function canEdit(int $id):bool {
        return $id>0 && get_post_type($id)==='transportations'
           && current_user_can('edit_post',$id)
           && current_user_can('commerce_manage_pricing')
           && current_user_can('manage_woocommerce');
    }
    private static function unit(int $id):string {
        $x=(string)get_post_meta($id,'trans_unit_type',true);
        return in_array($x,['per_person','per_cabin','per_room',
          'per_vehicle','per_day','per_trip','table'],true)?$x:'';
    }
    public static function policy(int $id):array {
        $configured=(string)get_post_meta($id,self::KEY,true);
        return [
            'configured'=>$configured!=='',
            'mode'=>isset(self::MODES[$configured])?$configured:'indicative_only',
            'revision'=>(int)get_post_meta($id,self::REV,true),
            'cost_enabled'=>in_array($configured,['cost_reference_only','both'],true),
            'indicative_enabled'=>!in_array($configured,['cost_reference_only'],true),
        ];
    }
    private static function money($raw):?float {
        if(!is_scalar($raw)||is_bool($raw)||!is_numeric($raw)||
            !is_finite((float)$raw)|| (float)$raw<=0)return null;
        return round((float)$raw,4);
    }
    private static function currency($raw):string {
        $raw=strtoupper(trim((string)$raw));
        return preg_match('/^[A-Z]{3}$/D',$raw)?$raw:'';
    }
    public static function inspect(int $id):array {
        $out=[
            'contract'=>'mad4b.transport.start-price-roles-and-source-weight.v1',
            'transportation_id'=>$id,'mode'=>'indicative_only',
            'role_configured'=>false,'policy_revision'=>0,
            'global_priority'=>null,'direct'=>null,'rate_cost_candidates'=>[],
            'cost_price_choice'=>null,'planning_cost_source'=>null,
            'planning_cost_amount'=>null,'planning_cost_unit'=>null,
            'planning_cost_currency'=>null,
            'not_a_supplier_approved_cost'=>true,
            'source_weight_is_priority_not_percentage_blend'=>true,
            'tour_rate_generation_authorized'=>false,
            'commercial_price_or_checkout_authorized'=>false,
            'reasons'=>[]
        ];
        if($id<1||get_post_type($id)!=='transportations'){
            $out['reasons'][]='transportation_post_invalid';return $out;
        }
        $settings=self::policy($id);
        $out['mode']=$settings['mode'];
        $out['role_configured']=$settings['configured'];
        $out['policy_revision']=$settings['revision'];
        // A separately verified public tariff matrix never becomes a
        // generic starting price, supplier receipt, or native Rate.
        $reference=get_post_meta($id,'_mad4b_abela_public_tariff_evidence_v1',true);
        $out['published_public_tariff_evidence']=is_array($reference)&&
            ($reference['contract']??'')==='mad4b.abela.public-tariff-evidence.v1'
             ?$reference:null;
        $start=self::money(get_post_meta($id,'trans_start_price',true));
        $cur=self::currency(get_post_meta($id,'trans_base_currency',true));
        $unit=self::unit($id);
        $directValid=$start!==null&&$cur!==''&&$unit!==''
            && get_post_status($id)==='publish';
        $directApproved=$directValid &&
            function_exists('BookingExperience\\transport_price_approval_verified')
            && \BookingExperience\transport_price_approval_verified($id);
        $out['direct']=[
            'price_present'=>$start!==null,
            'amount'=>$start,'currency'=>$cur,'unit'=>$unit,
            'is_published'=>get_post_status($id)==='publish',
            'price_unit_approved'=>$directApproved,
            'show_as_indicative'=>$settings['indicative_enabled']&&$directValid,
            'offer_as_planning_cost'=>$settings['cost_enabled']&&$directValid,
            'supplier_cost_receipt_signed'=>false,
        ];
        $p=function_exists('shared_product_price_source_policy')
            ?shared_product_price_source_policy('transport')
            :new WP_Error('source_weight_policy_provider_unavailable');
        $out['global_priority']=is_wp_error($p)
            ?['error'=>$p->get_error_code()]:$p;
        $rates=function_exists('BookingExperience\\transport_rate_candidates')
            ?\BookingExperience\transport_rate_candidates($id):[];
        $rateCosts=[];
        foreach($rates as $r) {
            $rate=(int)($r->ID??0);
            if($rate<1 || get_post_status($rate)!=='publish' ||
                (int)get_post_meta($rate,'source_post_id',true)!==$id)continue;
            $approved=function_exists('BookingExperience\\transport_rate_approval_verified')
              &&\BookingExperience\transport_rate_approval_verified($rate);
            $rateRaw=self::money(get_post_meta($rate,'trans_cost_amount',true));
            $role=(string)get_post_meta($rate,'trans_price_role',true);
            $rc=self::currency(get_post_meta($rate,'currency',true));
            $ru=(string)get_post_meta($rate,'pricing_type',true);
            $costRole=in_array($role,['self_cost','actual_company_cost'],true);
            $valid=$rateRaw!==null&&$rc!==''&&$ru!==''&&$costRole&&$approved;
            $row=[
                'rate_id'=>$rate,'role'=>$role,'unit'=>$ru,'currency'=>$rc,
                'amount'=>$rateRaw,'native_rate_approval_verified'=>$approved,
                'has_documented_cost_role'=>$costRole,'planning_cost_candidate'=>$valid,
                // Native Rate approval signs the SELLING price, not arbitrary
                // supplier invoices. Separate supplier authority still required.
                'supplier_cost_receipt_signed'=>false,
                'sale_price_must_not_be_used_as_cost'=>true,
            ];
            $out['rate_cost_candidates'][]=$row;
            if($valid)$rateCosts[]=$row;
        }
        $directCost=$settings['cost_enabled']&&$directValid
            ?['amount'=>$start,'currency'=>$cur,'unit'=>$unit]:null;
        if(count($rateCosts)>1) {
            $out['reasons'][]='multiple_cost_rates_require_explicit_rate_and_date_selection';
            return $out;
        }
        $rateCost=$rateCosts[0]??null;
        if($directCost && $rateCost && $directCost['unit']!==$rateCost['unit']) {
            $out['reasons'][]='cost_source_unit_mismatch_requires_manual_unit_normalization';
            return $out;
        }
        if(is_wp_error($p)){
            $out['reasons'][]=$p->get_error_code();return $out;
        }
        $candidates=[];
        if($directCost)$candidates['direct']=[
            'amount'=>$directCost['amount'],'currency'=>$directCost['currency']];
        if($rateCost)$candidates['rate']=[
            'amount'=>$rateCost['amount'],'currency'=>$rateCost['currency']];
        if($candidates) {
            $chosen=function_exists('shared_product_resolve_price_sources')
                ?shared_product_resolve_price_sources($candidates,'transport')
                :new WP_Error('source_weight_resolver_unavailable');
            if(is_wp_error($chosen)) {
                $out['reasons'][]=$chosen->get_error_code();
            }else{
                $chosenName=$chosen['source'];
                $out['cost_price_choice']=[
                    'source'=>$chosenName,'weights'=>$chosen['policy'],
                    'reason'=>'priority_then_next_valid_not_blended',
                    'status'=>'planning_cost_reference_only'
                ];
                $out['planning_cost_source']=$chosenName;
                $out['planning_cost_amount']=$chosen['amount'];
                $out['planning_cost_currency']=$chosen['currency'];
                $out['planning_cost_unit']=$chosenName==='direct'
                    ?$directCost['unit']:$rateCost['unit'];
                $out['reasons'][]='supplier_cost_authority_separate_from_editor_price_usage';
            }
        }else{
            $out['reasons'][]='no_valid_cost_candidate_for_current_mode';
        }
        if(!$settings['configured'])
            $out['reasons'][]='default_indicative_no_cost_authority';
        return $out;
    }
    public static function save(int $id,string $mode,int $expected) {
        if(!self::canEdit($id))return new WP_Error('transport_price_role_write_forbidden');
        if(!isset(self::MODES[$mode]))
            return new WP_Error('transport_price_role_mode_invalid');
        if($expected<0)return new WP_Error('transport_price_role_revision_invalid');
        // Opting into a cost reference is an editorial role, not proof
        // of supplier cost. Refuse meaningless cost mode without amount.
        if($mode!=='indicative_only') {
            $value=self::money(get_post_meta($id,'trans_start_price',true));
            $currency=self::currency(get_post_meta($id,'trans_base_currency',true));
            if($value===null||$currency===''||self::unit($id)==='')
                return new WP_Error('transport_cost_source_requires_saved_direct_amount_currency_and_unit');
        }
        global $wpdb;
        $name='mad4b:transport-price-role:'.absint($id);
        if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$name))!=='1')
            return new WP_Error('transport_price_role_lock_busy');
        try {
            $before=self::policy($id);
            if($before['revision']!==$expected)
                return new WP_Error('transport_price_role_stale_editor_reload');
            if($before['mode']===$mode && $before['configured'])
                return ['status'=>'unchanged','revision'=>$before['revision'],
                    'mode'=>$mode,'checkout_authorized'=>false];
            update_post_meta($id,self::KEY,$mode);
            update_post_meta($id,self::REV,$expected+1);
            $history=get_post_meta($id,self::HISTORY,true);
            if(!is_array($history))$history=[];
            $history[]=[
                'revision'=>$expected+1,'from'=>$before['mode'],
                'to'=>$mode,'by'=>get_current_user_id(),
                'at'=>gmdate('c'),'company'=>home_url(),
                'price_fields_changed'=>false,'supplier_cost_approved'=>false
            ];
            update_post_meta($id,self::HISTORY,array_slice($history,-30));
            $after=self::policy($id);
            if($after['revision']!==$expected+1 || $after['mode']!==$mode)
                return new WP_Error('transport_price_role_readback_failed');
            return ['status'=>'saved','revision'=>$after['revision'],
                'mode'=>$after['mode'],'decision'=>self::inspect($id),
                'supplier_cost_approved'=>false,'checkout_authorized'=>false];
        }finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));
        }
    }
    public static function ajax():void {
        $id=absint($_POST['post_id']??0);
        if(!self::canEdit($id))wp_send_json_error(['code'=>'forbidden'],403);
        check_ajax_referer(self::NONCE,'nonce');
        $mode=sanitize_key((string)($_POST['mode']??''));
        $revision=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT,[
          'options'=>['min_range'=>0]]);
        if($revision===false)wp_send_json_error(['code'=>'revision_invalid'],422);
        $result=self::save($id,$mode,$revision);
        if(is_wp_error($result))
            wp_send_json_error(['code'=>$result->get_error_code()],422);
        wp_send_json_success($result);
    }
    public static function metaBox():void {
        add_meta_box('mad4b-transport-price-source-roles-v1',
            __('MAD4B · Direct Price Role and Rate Priority','mad4b'),
            [self::class,'render'],'transportations','normal','high');
    }
    public static function render(WP_Post $post):void {
        if(!self::canEdit($post->ID))return;
        $d=self::inspect($post->ID);
        $p=self::policy($post->ID);
        $nonce=wp_create_nonce(self::NONCE);
        echo '<section class="mad4b-transport-price-role-panel" data-post-id="'.
            (int)$post->ID.'" data-revision="'.(int)$p['revision'].'" data-nonce="'.
            esc_attr($nonce).'" data-endpoint="'.esc_url(admin_url('admin-ajax.php')).'">';
        echo '<p><strong>Source role:</strong> the starting amount may be an indicative price, a planning cost reference, or both. This does not approve supplier costs or checkout.</p>';
        echo '<label for="mad4b-transport-price-role-select"><strong>Use Starting Price As</strong></label> ';
        echo '<select id="mad4b-transport-price-role-select">';
        foreach(self::MODES as $key=>$label)
            echo '<option value="'.esc_attr($key).'" '.selected($p['mode'],$key,false).'>'.
              esc_html($label).'</option>';
        echo '</select> <button type="button" class="button button-primary" id="mad4b-transport-price-role-save">Save Source Role</button>';
        echo '<p id="mad4b-transport-price-role-result" role="status" aria-live="polite"></p>';
        $direct=$d['direct']??[];
        echo '<p><strong>Current direct:</strong> '.esc_html(
            ($direct['amount']??null)===null?'No saved direct price':
            ((string)$direct['amount'].' '.($direct['currency']?:'currency missing').
            ' / '.($direct['unit']?:'unit missing'))).
            '. <strong>Direct price approval:</strong> '.
            (!empty($direct['price_unit_approved'])?'verified':'not verified').'.</p>';
        $source=$d['global_priority'];
        if(is_array($source) && !isset($source['error'])){
          echo '<p><strong>Source priority:</strong> Rate '.
             esc_html((string)$source['rate_weight']).' / Direct '.
             esc_html((string)$source['direct_weight']).' · fallback '.
             esc_html((string)$source['fallback']).
             '. Weights rank eligible sources; they never blend amounts.</p>';
        }
        echo '<p><strong>Planning cost candidate:</strong> '.
          esc_html($d['planning_cost_source']?:'none').
          (($d['planning_cost_amount']??null)!==null
            ?' — '.esc_html((string)$d['planning_cost_amount'].' '.
             $d['planning_cost_currency'].' / '.$d['planning_cost_unit']):'').
          '. <strong>Supplier-cost / date / cabin authority:</strong> not certified.</p>';
        $evidence=$d['published_public_tariff_evidence']??null;
        if(is_array($evidence)){
            $published=$evidence['money']['foreign_usd_one_way_per_person']??[];
            echo '<div class="mad4b-abela-public-fare-evidence" data-customer-segment="foreign" data-commercial-authorization="false">';
            echo '<h3>Abela published fare evidence · 2026</h3>';
            echo '<p>Per foreign adult, one-way journey, USD. This is NOT a price for Egyptians/Arabs, a supplier trade cost, or a dated ticket quote.</p>';
            echo '<table class="widefat striped"><thead><tr><th>Season</th><th>Cabin</th><th>Sharing, per person</th><th>Single sole use, per person</th></tr></thead><tbody>';
            foreach(['off_peak'=>'Outside peak','peak'=>'Peak dates'] as $season=>$title){
              foreach(['standard'=>'Standard','elite'=>'Elite'] as $class=>$label){
                $fare=$published[$season][$class]??[];
                if(!isset($fare['shared_double'],$fare['single_sole_use']))continue;
                echo '<tr><td>'.esc_html($title).'</td><td>'.esc_html($label).'</td><td>USD '.esc_html(number_format((float)$fare['shared_double'],2)).'</td><td>USD '.esc_html(number_format((float)$fare['single_sole_use'],2)).'</td></tr>';
              }
            }
            echo '</tbody></table>';
            echo '<p><strong>Children:</strong> evidence differs between sources; prices are deliberately NOT approved for automatic calculations.</p>';
            echo '<p><strong>Season limits:</strong> 2026 dated public calendar only. No inferred 2027 dates, inventory or bank fee.</p>';
            $links=$evidence['sources']??[];
            foreach(['official_2026_current_link'=>'Operator latest displayed tariff link',
                  'seat61_2026'=>'Independent rail fare guide',
                  'this_is_egypt_oct_2026_abella_booking'=>'October 2026 booking observation'] as $key=>$label){
              if(!empty($links[$key]))echo '<p><a target="_blank" rel="noopener noreferrer" href="'.esc_url($links[$key]).'">'.esc_html($label).'</a></p>';
            }
            echo '<p><strong>Operational status:</strong> verified public reference data only; no generic Starting Price, direct Cost Source, Rate or Checkout was changed.</p>';
            echo '</div>';
        }
        echo '<p><small>Save the native start price, currency and unit in this Transportation first. Selecting Cost or Both changes planning source eligibility only. Commercial Tour Rates still require signed supplier cost, dates, capacity, occupancy and authorization.</small></p>';
        echo '</section>';
    }
    public static function adminScript($hook):void {
        if(!in_array($hook,['post.php'],true))return;
        $screen=function_exists('get_current_screen')?get_current_screen():null;
        if(!$screen||$screen->post_type!=='transportations')return;
        $id=absint($_GET['post']??0);
        if(!self::canEdit($id))return;
        $path=__DIR__.'/assets/mad4b-transport-start-price-roles-v1.js';
        if(!is_readable($path))return;
        wp_enqueue_script('mad4b-transport-start-price-roles-v1',
          set_url_scheme(plugins_url('assets/mad4b-transport-start-price-roles-v1.js',__FILE__),'https'),
          [],substr(hash_file('sha256',$path),0,12),true);
        wp_add_inline_script('mad4b-transport-start-price-roles-v1',
          'window.MAD4BTransportSourceRoleV1='.wp_json_encode([
            'action'=>self::ACTION,'post_id'=>$id,
            'nonce'=>wp_create_nonce(self::NONCE),
            'ajax_url'=>set_url_scheme(admin_url('admin-ajax.php'),'https'),
            'revision'=>(int)get_post_meta($id,self::REV,true),
          ],JSON_UNESCAPED_SLASHES).';','before');
    }
}
add_action('add_meta_boxes_transportations',
  [MAD4B_Transport_Start_Price_Roles_V1::class,'metaBox']);
add_action('wp_ajax_'.MAD4B_Transport_Start_Price_Roles_V1::ACTION,
  [MAD4B_Transport_Start_Price_Roles_V1::class,'ajax']);
add_action('admin_enqueue_scripts',
  [MAD4B_Transport_Start_Price_Roles_V1::class,'adminScript'],75);
}

