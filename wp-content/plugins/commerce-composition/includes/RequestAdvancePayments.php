<?php
declare(strict_types=1);
namespace Commerce\Composition;
require_once __DIR__.'/LifecycleAccess.php';

/** Woo owns gateways and receipts. A request advance never sells the travel service. */
final class RequestAdvancePayments
{
    const OPTION='commerce_request_advance_policy_v1';
    public const PENDING_ADVANCE='pending-advance';
    public const PENDING_FULL='pending-full';
    private static array $outcomes=[];
    public static function bootstrap():void {
        add_filter('woocommerce_settings_tabs_array',static function($tabs){$tabs['request_advances']=__('Request advances','commerce-composition');return $tabs;},60);
        add_action('woocommerce_settings_tabs_request_advances',[self::class,'settings']);
        add_action('woocommerce_update_options_request_advances',[self::class,'saveSettings']);
        add_action('init',[self::class,'registerPendingStatuses'],9);
        add_filter('woocommerce_valid_order_statuses_for_payment',[self::class,'validPaymentStatuses'],20,2);
        add_filter('woocommerce_valid_order_statuses_for_payment_complete',[self::class,'validPaymentStatuses'],20,2);
        add_filter('woocommerce_order_is_pending_statuses',[self::class,'pendingStatuses'],20);
        add_filter('woocommerce_get_order_status_labels',[self::class,'statusTooltips'],20,2);
        add_filter('manage_woocommerce_page_wc-orders_columns',[self::class,'advanceAdminColumn'],45);
        add_filter('manage_edit-shop_order_columns',[self::class,'advanceAdminColumn'],45);
        add_action('manage_woocommerce_page_wc-orders_custom_column',[self::class,'renderAdvanceAdminColumn'],45,2);
        add_action('manage_shop_order_posts_custom_column',[self::class,'renderLegacyAdvanceAdminColumn'],45,2);
        add_action('init',static function(){register_post_status('wc-advance-paid',['label'=>__('Advance received — under agreement','commerce-composition'),'public'=>true,'show_in_admin_status_list'=>true,'show_in_admin_all_list'=>true,'label_count'=>_n_noop('Advance received (%s)','Advances received (%s)','commerce-composition')]);});
        add_action('init',static function(){register_post_status('wc-full-paid',['label'=>__('Full Est. Paid — Awaiting Confirmation','commerce-composition'),'public'=>true,'show_in_admin_status_list'=>true,'show_in_admin_all_list'=>true,'label_count'=>_n_noop('Full estimated payments received (%s)','Full estimated payments received (%s)','commerce-composition')]);});
        add_filter('wc_order_statuses',static function($s){
            // Keep native checkout statuses intact and insert only the two
            // *unpaid* request-collection statuses after Woo "Pending".
            $out=[];
            foreach($s as $key=>$label) {
                $out[$key]=$label;
                if($key==='wc-pending'){
                    $out['wc-'.self::PENDING_ADVANCE]=__('Pending Advance','commerce-composition');
                    $out['wc-'.self::PENDING_FULL]=__('Pending Full Estimated Payment','commerce-composition');
                }
            }
            $s=$out;
            $s['wc-advance-paid']=__('Advance received — under agreement','commerce-composition');
            $s['wc-full-paid']=__('Full Est. Paid — Awaiting Confirmation','commerce-composition');
            return $s;
        });
        add_filter('woocommerce_order_is_paid_statuses',static function($s){
            return array_unique(array_merge((array)$s,['advance-paid','full-paid']));
        });
        add_filter('woocommerce_analytics_excluded_order_statuses',static function($s){
            return array_unique(array_merge((array)$s,['advance-paid','wc-advance-paid','full-paid','wc-full-paid',
                self::PENDING_ADVANCE,'wc-'.self::PENDING_ADVANCE,self::PENDING_FULL,'wc-'.self::PENDING_FULL]));
        });
        add_action('fluentform/submission_inserted',[self::class,'submitted'],110,3);
        add_filter('fluentform/filter_insert_data',[self::class,'captureContext'],35,1);
        add_action('fluentform/form_element_start',[self::class,'renderedFormContext'],20,1);
        add_filter('fluentform/submission_confirmation',[self::class,'confirmation'],10001,5);
        add_filter('woocommerce_payment_complete_order_status',static function($s,$id,$o=null){
            return self::paymentCompleteStatus($o?:wc_get_order($id),(string)$s);
        },999,3);
        add_filter('woocommerce_payment_complete_reduce_order_stock',static function($yes,$id){return self::isAdvance(wc_get_order($id))?false:$yes;},999,2);
        add_filter('woocommerce_can_reduce_order_stock',static function($yes,$o){return self::isAdvance($o)?false:$yes;},999,2);
        add_filter('woocommerce_available_payment_gateways',static function($gateways){if(self::isAdvance(self::payOrder()))foreach($gateways as $id=>$g)if(!self::gatewayEligible($id,$g))unset($gateways[$id]);return $gateways;},999);
        add_action('woocommerce_payment_complete',[self::class,'received'],20,1);
        // Trigger Woo's existing email pipeline for custom pending-state
        // transitions without inventing a second mailer.
        add_filter('woocommerce_email_actions',[self::class,'advanceEmailActions'],80);
        add_action('woocommerce_order_status_pending-advance_to_failed_notification',[self::class,'forwardAdvanceEmailFailed'],20,2);
        add_action('woocommerce_order_status_pending-full_to_failed_notification',[self::class,'forwardAdvanceEmailFailed'],20,2);
        add_action('woocommerce_order_status_pending-advance_to_cancelled_notification',[self::class,'forwardAdvanceEmailCancelled'],20,2);
        add_action('woocommerce_order_status_pending-full_to_cancelled_notification',[self::class,'forwardAdvanceEmailCancelled'],20,2);
        add_action('woocommerce_order_status_pending-advance_to_on-hold_notification',[self::class,'forwardAdvanceEmailOnHold'],20,2);
        add_action('woocommerce_order_status_pending-full_to_on-hold_notification',[self::class,'forwardAdvanceEmailOnHold'],20,2);
        add_action('woocommerce_order_refunded',static function($id){self::refund($id);},20,1);
        add_action('woocommerce_order_status_changed',static function($id,$from,$to,$o){if(self::isAdvance($o)&&in_array($to,['failed','cancelled'],true)){$o->update_meta_data('_request_collection_state',$to);$o->save();}},20,4);
        add_action('woocommerce_pay_order_before_submit',[self::class,'terms']);
        add_action('woocommerce_before_pay_action',[self::class,'validatePayment']);
        add_action('woocommerce_order_details_before_order_table',[self::class,'notice']);
        add_action('woocommerce_admin_order_data_after_order_details',[self::class,'notice']);
        add_filter('woocommerce_get_order_item_totals',[self::class,'totals'],999,3);
        add_filter('woocommerce_pay_order_button_text',static function($text){return self::isAdvance(self::payOrder())?__('Pay request advance','commerce-composition'):$text;});
        add_action('rest_api_init',[self::class,'routes']);
        add_action('wp_abilities_api_init',[self::class,'ability'],60);
        add_filter('commerce_composition_payment_capabilities',static fn($v=[])=>self::status());
        add_filter('fluentform/find_submission',static function($entry,$fid){
            if(!\Commerce\Composition\LifecycleAccess::can('commerce_manage_advances')||!is_object($entry))return $entry;
            $orders=self::findBySource((int)$fid,(int)$entry->id,2);
            if($orders)$entry->request_advance_receipt=self::result($orders[0]);return $entry;
        },30,2);
    }
    /**
     * A separate Woo order status is required: this is an advance collection,
     * NOT a general store order awaiting final purchase payment.
     */
    public static function registerPendingStatuses():void {
        foreach([
            self::PENDING_ADVANCE=>__('Pending Advance','commerce-composition'),
            self::PENDING_FULL=>__('Pending Full Estimated Payment','commerce-composition')
        ] as $key=>$label){
            register_post_status('wc-'.$key,[
                'label'=>$label,'public'=>true,'exclude_from_search'=>false,
                'show_in_admin_all_list'=>true,'show_in_admin_status_list'=>true,
                'label_count'=>_n_noop('Awaiting payment (%s)','Awaiting payments (%s)','commerce-composition')
            ]);
        }
    }
    public static function advanceEmailActions($actions):array {
        $actions=(array)$actions;
        foreach([self::PENDING_ADVANCE,self::PENDING_FULL] as $from)
            foreach(['failed','cancelled','on-hold'] as $to)
                $actions[]='woocommerce_order_status_'.$from.'_to_'.$to;
        return array_values(array_unique($actions));
    }
    public static function forwardAdvanceEmailFailed($id,$order):void {
        if(self::isAdvance($order))
            do_action('woocommerce_order_status_pending_to_failed_notification',$id,$order);
    }
    public static function forwardAdvanceEmailCancelled($id,$order):void {
        if(self::isAdvance($order))
            do_action('woocommerce_order_status_pending_to_cancelled_notification',$id,$order);
    }
    public static function forwardAdvanceEmailOnHold($id,$order):void {
        if(self::isAdvance($order))
            do_action('woocommerce_order_status_pending_to_on-hold_notification',$id,$order);
    }
    /** Native Woo checkout/Stripe status extensions, ONLY for request advances. */
    public static function validPaymentStatuses($statuses,$order):array {
        $statuses=(array)$statuses;
        if(self::isAdvance($order))
            return array_values(array_unique(array_merge($statuses,[
                self::PENDING_ADVANCE,self::PENDING_FULL
            ])));
        return $statuses;
    }
    /** Support Woo payment page stock-hold semantics for the two unpaid states. */
    public static function pendingStatuses($statuses):array {
        return array_values(array_unique(array_merge((array)$statuses,[
            self::PENDING_ADVANCE,self::PENDING_FULL
        ])));
    }
    /** Always select the correct status from the effective policy's payable mode. */
    public static function pendingStatus(array $money,string $schedule='standard'):string {
        return $schedule==='full_due_now'||($money['mode']??'')==='full'
            ?self::PENDING_FULL:self::PENDING_ADVANCE;
    }
    /** No hard-coded percentage; use the immutable *effective* receipt policy. */
    public static function advancePercentage(array $money,array $effective):?float {
        if(($money['mode']??'')==='full')return 100.0;
        if(($money['mode']??'')!=='percent')return null; // fixed amount, not a percent policy
        $v=$effective['percent']??null;
        return is_numeric($v)&&is_finite((float)$v)&&(float)$v>0&&(float)$v<=100
            ?round((float)$v,4):null;
    }
    /** Reconcile legacy receipts without reading today's potentially changed policy. */
    public static function receiptPercentage($order):?float {
        if(!self::isAdvance($order))return null;
        $stored=$order->get_meta('_request_advance_percentage_v1');
        if(is_numeric($stored)&&is_finite((float)$stored)&&(float)$stored>0
           &&(float)$stored<=100)return (float)$stored;
        $money=$order->get_meta('_request_advance_money');
        $policy=$order->get_meta('_request_advance_policy');
        return is_array($money)&&is_array($policy)
            ?self::advancePercentage($money,$policy):null;
    }
    public static function receiptLabel($order):string {
        if(!self::isAdvance($order))return '';
        $money=$order->get_meta('_request_advance_money');
        if(!is_array($money))return __('Advance pending review','commerce-composition');
        $pct=self::receiptPercentage($order);
        $received=$order->get_meta('_request_collection_state')==='received'
            ||$order->has_status(['advance-paid','full-paid']);
        $isFull=self::pendingStatus($money,
            (string)$order->get_meta('_request_payment_schedule'))===self::PENDING_FULL;
        if($isFull)return $received
            ?__('Full estimated payment received (100%)','commerce-composition')
            :__('Full estimated payment due (100%)','commerce-composition');
        if($pct!==null)return sprintf($received
                ?__('Advance received (%s%% of verified estimate)','commerce-composition')
                :__('Advance due (%s%% of verified estimate)','commerce-composition'),
            rtrim(rtrim(number_format($pct,2,'.',''),'0'),'.'));
        if(($money['mode']??'')==='fixed')
            return $received
                ?__('Fixed advance amount received','commerce-composition')
                :__('Fixed advance amount due','commerce-composition');
        return __('Advance awaiting policy review','commerce-composition');
    }
    public static function statusTooltips($labels,$order):array {
        $labels=(array)$labels;
        if(!self::isAdvance($order))return $labels;
        $text=self::receiptLabel($order).
            ' — '.__('Booking and final trip price are not confirmed','commerce-composition');
        $labels[self::PENDING_ADVANCE]=$text;
        $labels[self::PENDING_FULL]=$text;
        return $labels;
    }
    public static function advanceAdminColumn($columns):array {
        $columns=(array)$columns;$out=[];
        foreach($columns as $key=>$title){
            $out[$key]=$title;
            if($key==='order_status')$out['mad4b_advance_policy']=__('Advance Policy','commerce-composition');
        }
        return $out;
    }
    public static function renderAdvanceAdminColumn($key,$order):void {
        if($key!=='mad4b_advance_policy'||!self::isAdvance($order))return;
        echo '<strong>'.esc_html(self::receiptLabel($order)).'</strong>';
        $blocker=self::paymentLinkBlocker($order);
        echo '<div><small>'.esc_html(
            $blocker!==null && $order->get_meta('_request_collection_state')==='awaiting_payment'
                ?__('Payment link requires Reservations rate review','commerce-composition')
                :($order->get_meta('_request_collection_state')==='awaiting_payment'
                    ?__('Awaiting advance payment','commerce-composition')
                    :__('Collection status: ','commerce-composition').
                        (string)$order->get_meta('_request_collection_state'))).'</small></div>';
    }
    public static function renderLegacyAdvanceAdminColumn($key,$orderId):void {
        if($key!=='mad4b_advance_policy')return;
        $order=wc_get_order((int)$orderId);
        self::renderAdvanceAdminColumn($key,$order);
    }
    /** Distinguish payment progress without confirming delivery or booking. */
    public static function isFullDueNow($order):bool {
        if(!self::isAdvance($order))return false;
        $money=$order->get_meta('_request_advance_money');
        return (string)$order->get_meta('_request_payment_schedule')==='full_due_now'
            ||(is_array($money)&&($money['mode']??'')==='full');
    }
    /** Pure status selector; Woo and Stripe remain the sole payment authorities. */
    public static function paymentCompleteStatus($order,string $fallback):string {
        if(!self::isAdvance($order))return $fallback;
        return self::isFullDueNow($order)?'full-paid':'advance-paid';
    }
    public static function defaults():array {
        return ['enabled'=>'yes','mode'=>'inherit','fixed_amount'=>'0','percent'=>'0','currency'=>get_option('woocommerce_currency','USD'),'form_ids'=>[],
            'terms'=>'','email_field'=>'email','name_field'=>'names']+\MAD4B_Customer_Policy_Control::extraDefaults();
    }
    public static function ability():void {
        if(!function_exists('wp_register_ability'))return;
        wp_register_ability('mad4b/request-advance-payment-status',[
            'label'=>'Request advance payment capabilities','description'=>'Discover native WooCommerce gateways and request advance policy. Payment amount is not a final trip total. No credentials or payment processing.',
            'category'=>'mad4b-read','execute_callback'=>static fn($input=[])=>self::status(),'permission_callback'=>static fn()=>\Commerce\Composition\LifecycleAccess::can('commerce_manage_advances'),
            'input_schema'=>['type'=>'object','additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['public'=>false,'show_in_rest'=>true,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
        ]);
    }
    public static function policy(int $source=0,int $rate=0):array {
        $p=array_replace(self::defaults(),(array)get_option(self::OPTION,[]));
        $p=\MAD4B_Customer_Policy_Control::globalRules($p);
        // Source owners can inherit or override the same collection policy; never gateway credentials.
        foreach(array_filter([$source,$rate]) as $id){
            $original=(int)apply_filters('wpml_object_id',$id,get_post_type($id),true,apply_filters('wpml_default_language',null));
            foreach(array_unique(array_filter([$original,$id])) as $translated){$v=get_post_meta($translated,'_commerce_request_advance_policy',true);if(is_array($v)){foreach(['mode','balance_due_mode','refund_mode'] as $key)if(($v[$key]??null)==='inherit')unset($v[$key]);$p=array_replace($p,array_intersect_key($v,array_replace(self::defaults(),\MAD4B_Customer_Policy_Control::extraDefaults())));}}
        }
        if($p['mode']==='inherit'&&$source){
            $company=CompanyCommercePolicy::resolve($source,$rate?:null);
            if(!is_wp_error($company)){
                $e=$company['effective'];$p['mode']=match($e['deposit_mode']??'manual_review'){'full'=>'full','percent'=>'percent','fixed'=>'fixed',default=>'request_only'};
                if(isset($e['deposit_percent']))$p['percent']=$e['deposit_percent'];
                if(isset($e['deposit_amount']))$p['fixed_amount']=$e['deposit_amount'];
            }else $p['mode']='request_only';
        }
        $p=\MAD4B_Customer_Policy_Control::complete($p,$source,$rate);
        return apply_filters('commerce_composition_request_advance_policy',$p,$source,$rate);
    }
    public static function gateways():array {
        if(!function_exists('WC')||!WC()->payment_gateways())return [];
        $out=[];
        foreach(WC()->payment_gateways()->payment_gateways() as $id=>$g){
            $out[$id]=['id'=>$id,'title'=>wp_strip_all_tags((string)$g->get_title()),'enabled'=>$g->enabled==='yes','available_now'=>$g->is_available(),'advance_eligible'=>self::gatewayEligible($id,$g),
                'refunds'=>in_array('refunds',(array)$g->supports,true),'settings_url'=>admin_url('admin.php?page=wc-settings&tab=checkout&section='.rawurlencode($id))];
        }
        return $out;
    }
    private static function gatewayEligible($id,$gateway):bool {
        // Payment after delivery cannot collect a request advance. Other providers remain native and discoverable.
        return (bool)apply_filters('commerce_composition_request_advance_gateway_eligible',$id!=='cod',$id,$gateway);
    }
    public static function status():array {
        $p=self::policy();$g=self::gateways();
        return ['contract'=>'commerce.request-advance.v1','gateway_owner'=>'woocommerce','gateways'=>$g,'policy'=>$p,
            'configured'=>$p['enabled']==='yes'&&$p['mode']!=='request_only'&&$p['terms']!==''&&count(array_filter($g,static fn($x)=>$x['enabled']&&$x['available_now']&&$x['advance_eligible']))>0,
            'supported_modes'=>['inherit','request_only','fixed','percent','full'],'final_purchase_total'=>null,'booking_confirmed'=>false,
            'form_discovery'=>'Fluent published forms + verified submission context','policy_inheritance'=>['jetengine_company','source','rate'],'customer_policy_contract'=>'commerce.customer-policy.v1','policy_editor'=>admin_url('admin.php?page=mad4b-company-policy-library')];
    }
    private static function fields():array {
        global $wpdb;$forms=$wpdb->get_results("SELECT id,title FROM {$wpdb->prefix}fluentform_forms WHERE status='published' ORDER BY id",ARRAY_A);$options=[];
        foreach((array)$forms as $f)$options[(string)$f['id']]='#'.$f['id'].' '.$f['title'];
        return [
            ['type'=>'title','id'=>'request_advance_title','title'=>__('Request advances','commerce-composition'),'desc'=>__('Payment is an advance against a request. It does not confirm the trip or its final price. Configure all payment gateways once under Payments.','commerce-composition')],
            ['type'=>'checkbox','id'=>'request_advance_enabled','title'=>__('Automatic integration','commerce-composition'),'default'=>'yes'],
            ['type'=>'select','id'=>'request_advance_mode','title'=>__('Collection policy','commerce-composition'),'options'=>['inherit'=>'Inherit company commerce policy dynamically','request_only'=>'Request without payment','fixed'=>'Fixed request advance','percent'=>'Percentage of verified reference','full'=>'100% of verified reference — not final trip price']],
            ['type'=>'number','id'=>'request_advance_fixed_amount','title'=>__('Fixed amount','commerce-composition'),'custom_attributes'=>['min'=>'0','step'=>'0.01']],
            ['type'=>'number','id'=>'request_advance_percent','title'=>__('Percentage','commerce-composition'),'custom_attributes'=>['min'=>'0.01','max'=>'100','step'=>'0.01']],
            ['type'=>'select','id'=>'request_advance_currency','title'=>__('Advance currency','commerce-composition'),'options'=>get_woocommerce_currencies()],
            ['type'=>'multiselect','id'=>'request_advance_form_ids','title'=>__('Eligible Fluent Forms','commerce-composition'),'class'=>'wc-enhanced-select','options'=>$options,'desc'=>__('Empty selects all forms with verified service context. Contact forms never become payable automatically.','commerce-composition')],
            ['type'=>'text','id'=>'request_advance_email_field','title'=>__('Fluent email field name','commerce-composition')],
            ['type'=>'text','id'=>'request_advance_name_field','title'=>__('Fluent name field name','commerce-composition')],
            ['type'=>'textarea','id'=>'request_advance_terms','title'=>__('Advance and refund terms','commerce-composition'),'desc'=>__('Required before payment. Explain cancellation, refund handling and that availability and final trip price remain subject to agreement.','commerce-composition')],
            ['type'=>'sectionend','id'=>'request_advance_title']
        ];
    }
    public static function settings():void {
        $p=self::policy();$fields=self::fields();
        foreach($fields as &$f){$key=str_replace('request_advance_','',$f['id']);if(array_key_exists($key,$p))$f['value']=$p[$key];}unset($f);
        foreach($fields as &$field)if($field['id']==='request_advance_terms'){$field['custom_attributes']=['readonly'=>'readonly'];$field['desc']='Read from JetEngine Customer Policies / the current Tour policy. Edit the original text owner.';}unset($field);
        \WC_Admin_Settings::output_fields($fields);
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-company-policy-library')).'">Customer Policies — shared JetEngine controls and text</a></p>';
        echo '<h3>'.esc_html__('Payment gateways — shared with Fluent Forms','commerce-composition').'</h3><p><a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')).'">'.esc_html__('Add or configure payment methods','commerce-composition').'</a></p><ul>';
        foreach(self::gateways() as $g)echo '<li><a href="'.esc_url($g['settings_url']).'">'.esc_html($g['title']).'</a>: '.esc_html($g['enabled']?($g['available_now']?'available':'enabled, configuration required'):'disabled').'</li>';
        echo '</ul>';
    }
    public static function saveSettings():void {
        if(!\Commerce\Composition\LifecycleAccess::can('commerce_manage_policies'))return;
        check_admin_referer('woocommerce-settings');
        $p=self::defaults();$p['enabled']=isset($_POST['request_advance_enabled'])?'yes':'no';
        foreach(['mode','currency','email_field','name_field'] as $k)$p[$k]=sanitize_text_field(wp_unslash($_POST['request_advance_'.$k]??$p[$k]));
        if(!in_array($p['mode'],['inherit','request_only','fixed','percent','full'],true)||!array_key_exists($p['currency'],get_woocommerce_currencies())){\WC_Admin_Settings::add_error('Invalid advance policy.');return;}
        foreach(['fixed_amount','percent'] as $k){$raw=wp_unslash($_POST['request_advance_'.$k]??'0');if(!is_scalar($raw)||!is_numeric($raw)||!is_finite((float)$raw)||(float)$raw<0||($k==='percent'&&(float)$raw>100)){\WC_Admin_Settings::add_error('Invalid advance amount.');return;}$p[$k]=wc_format_decimal($raw);}
        $p['form_ids']=array_values(array_filter(array_map('absint',(array)($_POST['request_advance_form_ids']??[]))));
        $p['terms']=sanitize_textarea_field(wp_unslash($_POST['request_advance_terms']??''));
        \MAD4B_Customer_Policy_Control::saveCompany($p);
        $technical=array_intersect_key($p,array_flip(['enabled','form_ids','email_field','name_field']));
        update_option(self::OPTION,$technical,false);
        do_action('commerce_composition_request_advance_policy_updated',self::status());
    }
    /** Issued only by an original source adapter after server validation, never generic form fields. */
    public static function sealIntent(array $intent,int $form):array {
        unset($intent['_request_advance_seal']);
        $intent['_request_advance_seal']=hash_hmac('sha256',wp_json_encode([$form,$intent]),wp_salt('secure_auth'));
        return $intent;
    }
    private static function verifiedIntent(array $data,int $form):?array {
        $i=$data['_shared_tour_booking_intent_v2']??null;
        if(!is_array($i)||!is_string($i['_request_advance_seal']??null))return null;
        $seal=$i['_request_advance_seal'];unset($i['_request_advance_seal']);
        return hash_equals(self::sealIntent($i,$form)['_request_advance_seal'],$seal)?$i:null;
    }
    /** Native Fluent insertion hook, usable by every source/form routed by the existing domain owner. */
    /** Native form rendering attests placement independently from optional booking composition. */
    public static function renderedFormContext($form):void {
        if(!is_object($form)||!is_numeric($form->id??null))return;
        $source=(int)get_the_ID();$context='BookingExperience'.chr(92).'booking_experience_context';
        if(!function_exists($context)||is_wp_error($context($source,0,0)))return;
        $fid=(int)$form->id;$p=self::policy($source);$ids=array_map('intval',(array)$p['form_ids']);
        $route=FormRouter::templateIdentity($source,null);
        $mapped=!is_wp_error($route)&&in_array($fid,array_filter([$route['inquiry_form_id']??0,$route['booking_form_id']??0]),true);
        if(($ids&&!in_array($fid,$ids,true))||(!$ids&&!$mapped))return;
        $token=['form_id'=>$fid,'source_id'=>$source];
        $token['seal']=hash_hmac('sha256',wp_json_encode(['fluent-native-mount:v1',home_url('/'),$fid,$source]),wp_salt('secure_auth'));
        echo '<input type="hidden" name="commerce_request_mount" value="'.esc_attr(wp_json_encode($token)).'">';
    }
    private static function mountedSource($raw,int $fid):int {
        if(!is_string($raw)||strlen($raw)>1024)return 0;
        $m=json_decode($raw,true);if(!is_array($m)||array_diff(array_keys($m),['form_id','source_id','seal'])||(int)($m['form_id']??0)!==$fid||!is_int($m['source_id']??null)||!is_string($m['seal']??null))return 0;
        $id=$m['source_id'];$seal=hash_hmac('sha256',wp_json_encode(['fluent-native-mount:v1',home_url('/'),$fid,$id]),wp_salt('secure_auth'));
        if(!hash_equals($seal,$m['seal']))return 0;
        $context='BookingExperience'.chr(92).'booking_experience_context';
        if(!function_exists($context)||is_wp_error($context($id,0,0)))return 0;
        $ids=array_map('intval',(array)self::policy($id)['form_ids']);
        $route=FormRouter::templateIdentity($id,null);
        $mapped=!is_wp_error($route)&&in_array($fid,array_filter([$route['inquiry_form_id']??0,$route['booking_form_id']??0]),true);
        return ($ids?in_array($fid,$ids,true):$mapped)?$id:0;
    }

    public static function captureContext($insert){
        if(!is_array($insert))return $insert;
        $fid=(int)($insert['form_id']??0);$data=json_decode((string)($insert['response']??''),true);
        if(!is_array($data))return $insert;
        unset($data['_request_advance_context']);
        $fields=[];if(is_string($_POST['data']??null))parse_str(wp_unslash($_POST['data']),$fields);
        $source=absint($_POST['__fluent_form_embded_post_id']??($fields['__fluent_form_embded_post_id']??0));
        $mounted=self::mountedSource($fields['commerce_request_mount']??($_POST['commerce_request_mount']??null),$fid);
        if($mounted)$source=$mounted;
        $p=get_post($source);
        if($p&&$p->post_status==='publish'&&$p->post_password===''){
            $route=FormRouter::templateIdentity($source,null);
            if($mounted||(!is_wp_error($route)&&in_array($fid,array_filter([(int)($route['booking_form_id']??0),(int)($route['inquiry_form_id']??0)]),true))){
                $ctx=['source_id'=>$source,'form_id'=>$fid];
                $ctx['seal']=hash_hmac('sha256',wp_json_encode($ctx),wp_salt('secure_auth'));
                $data['_request_advance_context']=$ctx;
            }
        }
        $insert['response']=wp_json_encode($data,JSON_UNESCAPED_UNICODE);return $insert;
    }
    public static function submissionContext(int $fid,int $sid):array|\WP_Error {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT form_id,status,response,source_url,created_at FROM {$wpdb->prefix}fluentform_submissions WHERE id=%d",$sid),ARRAY_A);
        if(!$row||(int)$row['form_id']!==$fid||in_array($row['status'],['spam','trashed','deleted'],true))return new \WP_Error('lifecycle_original_submission_unavailable');
        $data=json_decode($row['response'],true);if(!is_array($data))return new \WP_Error('lifecycle_original_submission_invalid');
        $intent=self::verifiedIntent($data,$fid);$ctx=SubmissionContext::read($fid,$sid);$native=self::contextSource($data,$fid);
        if(is_wp_error($ctx)&&!$intent&&!$native)return new \WP_Error('lifecycle_verified_source_required');
        $source=is_array($ctx)?(int)$ctx['parent_id']:($intent?(int)$intent['tour_id']:$native);
        $rate=self::resolveVerifiedRate($ctx,$intent);
        if($intent&&((int)$intent['tour_id']!==$source||(int)($intent['rate_id']??0)!==$rate))return new \WP_Error('lifecycle_source_context_conflict');
        if(!get_post($source)||in_array(get_post_status($source),['trash','auto-draft'],true))return new \WP_Error('lifecycle_source_unavailable');
        return ['source_id'=>$source,'rate_id'=>$rate,'intent'=>$intent,'data'=>$data,'row'=>$row];
    }
    /** An immutable context with no rate must not erase a signed server-rate intent.
     * An explicit immutable rate always wins; the caller rejects any real mismatch.
     */
    private static function resolveVerifiedRate($context,?array $intent):int {
        $contextRate=is_array($context)?(int)($context['rate_id']??0):0;
        return $contextRate>0?$contextRate:(int)($intent['rate_id']??0);
    }
    private static function contextSource(array $data,int $fid):int {
        $ctx=$data['_request_advance_context']??null;
        if(!is_array($ctx)||($ctx['form_id']??null)!==$fid||!is_string($ctx['seal']??null))return 0;
        $seal=$ctx['seal'];unset($ctx['seal']);
        return hash_equals(hash_hmac('sha256',wp_json_encode($ctx),wp_salt('secure_auth')),$seal)?(int)$ctx['source_id']:0;
    }
    /**
     * Price and request-advance tax projection from LIVE WooCommerce settings.
     * Standard-class Woo tax rates, location, inclusive/exclusive price policy
     * and Woo's own tax math are the only tax authorities.
     * Never use a hardcoded percentage in MAD4B or client-side JavaScript.
     */
    public static function wooTaxSnapshot(float $amount,string $currency):array|\WP_Error {
        if(!is_finite($amount)||$amount<=0||!preg_match('/^[A-Z]{3}$/',$currency))
            return new \WP_Error('advance_tax_amount_invalid');
        $round=static fn(float $n):float=>round($n,wc_get_price_decimals());
        $amount=$round($amount);
        if(!wc_tax_enabled()){
            return ['applied'=>false,'subtotal'=>$amount,'tax_total'=>0.0,
                'total_with_tax'=>$amount,'currency'=>$currency,'label'=>'',
                'location'=>[],'rate_ids'=>[],'included'=>false];
        }
        // Public tour quotes do not carry a verified customer's tax address.
        // 'base' is the only deterministic Woo native policy in this context.
        if(get_option('woocommerce_tax_based_on','shipping')!=='base')
            return new \WP_Error('advance_tax_location_not_deterministic');
        $base=wc_get_base_location();
        $location=['country'=>(string)($base['country']??''),
            'state'=>(string)($base['state']??''),
            'postcode'=>(string)get_option('woocommerce_store_postcode',''),
            'city'=>(string)get_option('woocommerce_store_city','')];
        $rates=\WC_Tax::find_rates($location+['tax_class'=>'']);
        if(!$rates)return new \WP_Error('advance_tax_standard_rate_unavailable');
        $included=wc_prices_include_tax();
        $parts=\WC_Tax::calc_tax($amount,$rates,$included);
        $raw=(float)\WC_Tax::get_tax_total($parts);
        $tax=$round($raw);
        $net=$included?$round($amount-$tax):$amount;
        $gross=$included?$amount:$round($amount+$tax);
        $names=[];$ids=[];
        foreach($rates as $id=>$rate) {
            $ids[]=(int)$id;
            $label=trim((string)($rate['label']??'Tax'));
            $percentage=(string)($rate['rate']??'');
            if($percentage!==''){
                $suffix=rtrim(rtrim($percentage,'0'),'.').'%';
                if(strpos($label,$suffix)===false)$label.=' ('.$suffix.')';
            }
            $names[]=$label;
        }
        return ['applied'=>true,'subtotal'=>$net,'tax_total'=>$tax,
            'total_with_tax'=>$gross,'currency'=>$currency,
            'label'=>implode(' + ',$names),'location'=>$location,
            'rate_ids'=>$ids,'included'=>$included];
    }
    public static function amount(array $p,?array $reference=null):array|\WP_Error {
        if(!in_array($p['mode']??'', ['fixed','percent','full'],true))return new \WP_Error('advance_request_only');
        $currency=(string)($p['currency']??'');
        if(!array_key_exists($currency,get_woocommerce_currencies()))return new \WP_Error('advance_currency_invalid');
        $base=null;
        if($p['mode']==='fixed')$amount=$p['fixed_amount']??0;
        else {
            if(!is_array($reference)||($reference['complete']??false)!==true||($reference['currency']??'')!==$currency)return new \WP_Error('advance_complete_reference_required');
            $base=$reference['amount']??null;
            if(!is_numeric($base)||!is_finite((float)$base)||(float)$base<=0)return new \WP_Error('advance_reference_invalid');
            $percent=$p['mode']==='full'?100:($p['percent']??null);
            if(!is_numeric($percent)||!is_finite((float)$percent)||(float)$percent<=0||(float)$percent>100)return new \WP_Error('advance_percent_invalid');
            $amount=(float)$base*(float)$percent/100;
        }
        if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0|| (float)$amount>999999999)return new \WP_Error('advance_amount_invalid');
        // Use the currency minor unit, independently from the store display precision.
        $zero=['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'];
        $three=['BHD','JOD','KWD','OMR','TND'];$dp=in_array($currency,$zero,true)?0:(in_array($currency,$three,true)?3:2);
        $amount=round((float)$amount,$dp);if($amount<=0)return new \WP_Error('advance_below_minor_unit');
        return ['amount'=>number_format($amount,$dp,'.',''),'currency'=>$currency,'reference_amount'=>$base,'final_trip_total'=>null,'mode'=>$p['mode']];
    }
    private static function reference(?array $i):?array {
        if(!$i)return null;
        // A server price is a nonbinding reference, not a final purchase or child-inclusive estimate.
        $complete=($i['state']??'')==='server_price_preview'&&!isset($i['reason'])&&empty($i['stay_review_required'])&&empty($i['transport_review_required'])&&empty($i['extras_review_required'])&&empty($i['minor_price_state']);
        return ['amount'=>$i['total']??null,'currency'=>$i['currency']??null,'complete'=>$complete];
    }
    /** Woo datastore-neutral exact receipt identity. Legacy CPT orders silently ignore meta_query. */
    public static function findBySource(int $fid,int $sid,int $limit=2):array {
        if($fid<1||$sid<1||!function_exists('wc_get_orders'))return [];
        $target=$fid.':'.$sid;
        $hpos=class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $args=['limit'=>max(2,min(25,$limit)),'return'=>'objects'];
        if($hpos)$args['meta_query']=[['key'=>'_request_advance_source','value'=>$target,'compare'=>'=']];
        else {$args['meta_key']='_request_advance_source';$args['meta_value']=$target;}
        $candidates=wc_get_orders($args);
        $matched=[];
        foreach((array)$candidates as $o) {
            if(!self::isAdvance($o)||$o->get_meta('_request_advance_source')!==$target ||
                (int)$o->get_meta('_request_form_id')!==$fid ||
                (int)$o->get_meta('_request_submission_id')!==$sid)continue;
            $matched[]=$o;
        }
        return $matched;
    }
    public static function create(int $fid,int $sid,?array $approvedReference=null):array|\WP_Error {
        if(!LifecycleAccess::available())return new \WP_Error('advance_lifecycle_authority_unavailable');
        global $wpdb;
        if(!function_exists('wc_create_order'))return new \WP_Error('advance_woocommerce_missing');
        $row=$wpdb->get_row($wpdb->prepare("SELECT form_id,status,response FROM {$wpdb->prefix}fluentform_submissions WHERE id=%d",$sid),ARRAY_A);
        if(!$row||(int)$row['form_id']!==$fid||in_array($row['status'],['spam','trashed','deleted'],true))return new \WP_Error('advance_submission_invalid');
        $data=json_decode($row['response'],true);if(!is_array($data))return new \WP_Error('advance_submission_invalid');
        $intent=self::verifiedIntent($data,$fid);
        $ctx=SubmissionContext::read($fid,$sid);
        $nativeSource=self::contextSource($data,$fid);
        if(is_wp_error($ctx)&&!$intent&&!$nativeSource)return new \WP_Error('advance_verified_source_required');
        if((int)$wpdb->get_var($wpdb->prepare("SELECT has_payment FROM {$wpdb->prefix}fluentform_forms WHERE id=%d",$fid))===1)return new \WP_Error('advance_native_form_payment_conflict');
        $source=is_array($ctx)?(int)$ctx['parent_id']:($intent?(int)($intent['tour_id']??0):$nativeSource);
        $rate=self::resolveVerifiedRate($ctx,$intent);
        if($intent&&((int)($intent['tour_id']??0)!==$source||(int)($intent['rate_id']??0)!==$rate))return new \WP_Error('advance_source_context_conflict');
        $post=get_post($source);
        if(!$post||$post->post_status!=='publish'||$post->post_password!==''||get_post_meta($source,'_booking_experience_qa_pricing_fixture',true)!=='')return new \WP_Error('advance_source_unavailable');
        if(in_array(strtolower((string)get_post_meta($source,'stop_sale',true)),['1','yes','true'],true))return new \WP_Error('advance_stop_sale');
        $p=self::policy($source,$rate);
        if($p['enabled']!=='yes'||$p['mode']==='request_only')return new \WP_Error('advance_request_only');
        if($p['form_ids']&&!in_array($fid,array_map('intval',(array)$p['form_ids']),true))return new \WP_Error('advance_form_excluded');
        if(trim((string)$p['terms'])==='')return new \WP_Error('advance_terms_required');
        // A short-notice full amount is derived ONLY from the original
        // server-signed Fluent request intent and the live company/legal policy.
        // It never alters the company 50% standard-deposit setting.
        $effective=$p;
        $schedule='standard';
        if(is_array($intent)&&($intent['source_adapter']??'')==='mad4b_single_fluent_router_v1') {
            $due=SingleFluentPaymentRouter::paymentWindow(
                (string)($intent['travel_date']??''),$p);
            if(empty($due['allowed'])||!hash_equals((string)$due['schedule'],
                (string)($intent['payment_schedule']??'')))
                return new \WP_Error('advance_payment_schedule_changed');
            $schedule=(string)$due['schedule'];
            if($schedule==='full_due_now') {
                if(!in_array((string)$p['mode'],['percent','full'],true))
                    return new \WP_Error('advance_short_notice_full_reference_required');
                $effective['mode']='full';
            }
        }
        $available=array_filter(self::gateways(),static fn($g)=>$g['enabled']&&$g['available_now']&&$g['advance_eligible']);
        if(!$available)return new \WP_Error('advance_gateway_unavailable');
        $email=sanitize_email($data[$p['email_field']]??'');if(!is_email($email))return new \WP_Error('advance_customer_email_required');
        if($approvedReference!==null&&!\Commerce\Composition\LifecycleAccess::can('commerce_manage_advances'))return new \WP_Error('advance_reference_permission_denied');
        $money=self::amount($effective,$approvedReference??self::reference($intent));if(is_wp_error($money))return $money;
        $money['payment_schedule']=$schedule;
        // Connection-scoped lock serializes duplicate submits/retries. Woo metadata recovers a crash after save.
        $lock='advance_'.substr(hash('sha256',home_url().':'.$fid.':'.$sid),0,48);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return new \WP_Error('advance_request_busy');
        try {
            $key=$fid.':'.$sid;
            $existing=self::findBySource($fid,$sid,3);
            if(count($existing)>1)return new \WP_Error('advance_duplicate_receipts_require_review');
            if($existing){$o=$existing[0];return self::result($o);}
            $o=new \WC_Order();$o->set_created_via('fluentform-request-advance');$o->set_currency($money['currency']);$o->set_status(self::pendingStatus($money,$schedule));
            $o->set_billing_email($email);
            $name=$data[$p['name_field']]??[];if(is_array($name)){$o->set_billing_first_name(sanitize_text_field($name['first_name']??''));$o->set_billing_last_name(sanitize_text_field($name['last_name']??''));}
            $item=new \WC_Order_Item_Fee();
            $item->set_name($schedule==='full_due_now'
                ?__('Full estimated request payment — booking not confirmed','commerce-composition')
                :__('Request advance — final trip price pending agreement','commerce-composition'));
            $tax=self::wooTaxSnapshot((float)$money['amount'],(string)$money['currency']);
            if(is_wp_error($tax))return $tax;
            // The reference and deposit stay net-of-tax. Woo tax is a separate
            // order line and is never included twice in the remaining balance.
            $item->set_amount($tax['subtotal']);$item->set_total($tax['subtotal']);
            $item->set_tax_status($tax['applied']?'taxable':'none');
            // Woo's order calculate_taxes() may persist a new order. Validate
            // the original Woo fee tax BEFORE attaching/saving any order item.
            if($tax['applied']) {
                $calculated=$item->calculate_taxes($tax['location']);
                if(!$calculated || abs((float)$item->get_total_tax()-(float)$tax['tax_total'])>0.011)
                    return new \WP_Error('advance_tax_fee_mismatch_before_order_creation');
            }
            $o->add_item($item);
            foreach(['_request_advance_source'=>$key,'_request_form_id'=>$fid,'_request_submission_id'=>$sid,'_request_source_id'=>$source,'_request_rate_id'=>$rate,
                '_request_collection_kind'=>'advance','_request_collection_state'=>'awaiting_payment','_request_operational_state'=>'under_agreement','_request_booking_confirmed'=>'no',
                '_request_final_total_state'=>'not_agreed',
                '_request_advance_terms'=>$p['terms'].($schedule==='full_due_now'
                    ? "\nShort-notice travel request: 100% of the verified request price plus applicable WooCommerce taxes is due now. Payment is not final confirmation of travel or availability.":''),
                '_request_advance_policy'=>$p,'_request_advance_money'=>$money,
                '_request_advance_percentage_v1'=>self::advancePercentage($money,$effective),
                '_request_payment_schedule'=>$schedule,
                '_request_payment_scope'=>$schedule==='full_due_now'?'full':'partial',
                '_request_price_basis'=>'estimated',
                '_request_booking_status'=>'awaiting_confirmation',
                '_request_reference_authority'=>$approvedReference!==null?'manager_approved_reference':'source_adapter_reference',
                '_request_paid_amount'=>'0','_request_refunded_amount'=>'0','_request_net_received'=>'0'] as $k=>$v)$o->update_meta_data($k,$v);
            // Reuse the native Fluent #6 accommodation/pickup adapter's
            // validated saved response; travel logistics are not shipping.
            // The source identity was verified above, and this remains
            // attached to the Woo order even on a cartless order-pay URL.
            if($fid===6 && class_exists('MAD4B_Booking_Pickup_Fields',false)){
                $location=\MAD4B_Booking_Pickup_Fields::order_payload(
                    $data['_mad4b_booking_pickup_v1']??null,$source,$rate);
                if($location)$o->update_meta_data('_mad4b_booking_pickup_v1',$location);
            }
            $snapshot=\MAD4B_Customer_Policy_Control::snapshot($source,$rate);
            $o->update_meta_data('_request_customer_policy_snapshot',$snapshot);
            $o->update_meta_data('_request_customer_policy_fingerprint',$snapshot['fingerprint']);
            // Advance fee tax is computed with native Woo order-item taxes;
            // the rest of the final travel price is still not committed.
            if($tax['applied'])$o->calculate_taxes($tax['location']);
            $o->calculate_totals(false);
            if($tax['applied'] && abs((float)$o->get_total()-(float)$tax['total_with_tax'])>0.011)
                return new \WP_Error('advance_tax_order_mismatch');
            $o->update_meta_data('_request_advance_tax_snapshot',[
                'subtotal'=>$tax['subtotal'],'tax_total'=>$tax['tax_total'],
                'total_with_tax'=>$tax['total_with_tax'],'rate_ids'=>$tax['rate_ids'],
                'included'=>$tax['included']]);
            $o->save();
            $o->add_order_note('Request advance only. Final trip total and availability are not agreed. This payment must not confirm a booking.');
            self::syncFluent($o);
            do_action('commerce_composition_request_advance_created',$o->get_id(),$fid,$sid);
            return self::result($o);
        } catch(\Throwable $e){wc_get_logger()->error('Request advance creation failed',['source'=>'commerce-request-advance']);return new \WP_Error('advance_creation_failed');}
        finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    /**
     * Payment links are credentials; never expose them just because Woo says
     * "pending". The original rate must still be commercially published.
     * Also reject stale amount/tax receipts without mutating any order.
     */
    public static function paymentLinkBlocker($order):?string {
        if(!self::isAdvance($order))return 'advance_order_invalid';
        if(!$order->needs_payment()||
           $order->get_meta('_request_collection_state')!=='awaiting_payment')
            return 'advance_not_awaiting_payment';
        $source=(int)$order->get_meta('_request_source_id');
        if(!$source||get_post_status($source)!=='publish'||
           in_array(strtolower((string)get_post_meta($source,'stop_sale',true)),
               ['1','yes','true'],true))
            return 'advance_source_not_eligible';
        $rate=(int)$order->get_meta('_request_rate_id');
        if($rate&&(get_post_status($rate)!=='publish'||
           in_array(strtolower((string)get_post_meta($rate,'stop_sale',true)),
               ['1','yes','true'],true)))
            return 'advance_source_rate_not_published';
        if(!self::payableTotalMatches(
            $order->get_meta('_request_advance_money'),
            $order->get_meta('_request_advance_tax_snapshot'),
            $order->get_currency(),
            (float)$order->get_total(),(float)$order->get_total_tax()))
            return 'advance_saved_money_invalid';
        return null;
    }
    public static function safePaymentUrl($order):?string {
        if(self::paymentLinkBlocker($order)!==null)return null;
        $url=$order->get_checkout_payment_url();
        return is_string($url)
            &&wp_parse_url($url,PHP_URL_HOST)===wp_parse_url(home_url(),PHP_URL_HOST)
            ?$url:null;
    }
    private static function result($o):array {
        return ['order_id'=>$o->get_id(),'payment_url'=>self::safePaymentUrl($o),'collection_state'=>$o->get_meta('_request_collection_state'),
            'payment_state'=>$o->get_status(),'advance_percent'=>self::receiptPercentage($o),
            'advance_label'=>self::receiptLabel($o),
            'amount'=>$o->get_total(),'currency'=>$o->get_currency(),'final_trip_total'=>null,'remaining_trip_balance'=>null,'booking_confirmed'=>false];
    }
    public static function isAdvance($o):bool {return $o instanceof \WC_Order&&$o->get_meta('_request_collection_kind')==='advance';}
    public static function submitted($sid,$data,$form):void {
        if(!is_object($form)||!is_numeric($form->id??null))return;
        // Runs AFTER Fluent has committed the enquiry. A downstream Woo error
        // must never unwind that saved submission or block its thank-you page.
        try {
            $r=self::create((int)$form->id,(int)$sid);
        } catch(\Throwable $e) {
            if(function_exists('wc_get_logger'))
                wc_get_logger()->error('Advance preparation failed after Fluent submission was saved',
                    ['source'=>'commerce-request-advance']);
            $r=new \WP_Error('advance_preparation_failed_after_enquiry_saved');
        }
        self::$outcomes[(int)$form->id][(int)$sid]=$r;
        if(is_wp_error($r)&&!in_array($r->get_error_code(),[
            'advance_request_only','advance_verified_source_required','advance_form_excluded'],true)){
            try {
                do_action('commerce_composition_request_advance_blocked',
                    (int)$form->id,(int)$sid,$r->get_error_code());
            } catch(\Throwable $e) {
                if(function_exists('wc_get_logger'))
                    wc_get_logger()->error('Advance alert failed; Fluent enquiry remains saved',
                        ['source'=>'commerce-request-advance']);
            }
        }
    }
    public static function confirmation($response,$form,$confirmation=null,$sid=null,$data=null){
        if(!is_array($response)||!is_object($form)||!is_numeric($sid))return $response;
        $r=self::$outcomes[(int)$form->id][(int)$sid]??null;
        if(is_array($r)&&!empty($r['payment_url'])){$response['redirectUrl']=$r['payment_url'];$response['message']=__('Request received. Continue to pay your request advance. The final trip price and availability remain subject to agreement.','commerce-composition');}
        elseif(is_wp_error($r)&&!in_array($r->get_error_code(),['advance_request_only','advance_verified_source_required','advance_form_excluded'],true)){$response['message']=__('Request received. No payment was taken. Our team will arrange your advance payment after reviewing the request.','commerce-composition');unset($response['redirectUrl']);}
        return $response;
    }
    private static function payOrder(){global $wp;return function_exists('wc_get_order')?wc_get_order(absint($wp->query_vars['order-pay']??0)):false;}
    public static function terms():void {
        $o=self::payOrder();if(!self::isAdvance($o))return;
        $snapshot=$o->get_meta('_request_customer_policy_snapshot');
        if(is_array($snapshot))echo '<div class="mad4b-accepted-policy-snapshot">'.wp_kses_post($snapshot['html']??'').'</div>';
        echo '<div class="request-advance-terms"><p><strong>'.esc_html__('Request advance — not a final trip purchase or booking confirmation.','commerce-composition').'</strong></p><p>'.nl2br(esc_html($o->get_meta('_request_advance_terms'))).'</p><label><input type="checkbox" name="request_advance_accept" value="1" required> '.esc_html__('I accept the advance and refund terms. The final trip price and availability remain subject to agreement.','commerce-composition').'</label></div>';
    }
    /**
     * Pure gross-vs-net verification against the SAVED native Woo tax snapshot.
     * Reusable in dry-run QA; no order creation, gateway or DB side effects.
     */
    public static function payableTotalMatches($money,$snapshot,string $currency,
        float $orderTotal,float $orderTax):bool {
        if(!is_array($money)||($money['currency']??null)!==$currency
           ||!is_numeric($money['amount']??null)
           ||!is_finite($orderTotal)||!is_finite($orderTax)
           ||$orderTotal<=0||$orderTax<0)return false;
        $amount=(float)$money['amount'];
        if(!is_finite($amount)||$amount<=0)return false;
        if($snapshot==='')return abs($orderTotal-$amount)<=0.011
           &&abs($orderTax)<=0.011;
        if(!is_array($snapshot))return false;
        foreach(['subtotal','tax_total','total_with_tax'] as $key)
           if(!is_numeric($snapshot[$key]??null)
              ||!is_finite((float)$snapshot[$key]))return false;
        $net=(float)$snapshot['subtotal'];$vat=(float)$snapshot['tax_total'];
        $gross=(float)$snapshot['total_with_tax'];
        if($net<=0||$vat<0||$gross<=0)return false;
        $principal=!empty($snapshot['included'])?$gross:$net;
        return abs(($net+$vat)-$gross)<=0.011
            &&abs($amount-$principal)<=0.011
            &&abs($orderTotal-$gross)<=0.011
            &&abs($orderTax-$vat)<=0.011;
    }
    public static function validatePayment($o):void {
        if(!self::isAdvance($o))return;
        if(($_POST['request_advance_accept']??'')!=='1')throw new \Exception(__('Please accept the request advance terms.','commerce-composition'));
        $p=self::policy((int)$o->get_meta('_request_source_id'),(int)$o->get_meta('_request_rate_id'));
        if($p['enabled']!=='yes'||$p['mode']==='request_only')throw new \Exception(__('Advance collection is currently unavailable. Please contact us.','commerce-composition'));
        $money=$o->get_meta('_request_advance_money');
        if(!is_array($money)||$o->get_currency()!==($money['currency']??null))
            throw new \Exception(__('The advance receipt changed. Please contact us before payment.','commerce-composition'));
        // With native Woo VAT, amount() is net and the checkout total includes
        // actual order-item tax. Compare with the immutable order tax snapshot,
        // not the pre-tax amount; never ignore a mismatch or reprice an old order.
        if(!self::payableTotalMatches($money,$o->get_meta('_request_advance_tax_snapshot'),
           $o->get_currency(),(float)$o->get_total(),(float)$o->get_total_tax()))
            throw new \Exception(__('The advance tax or total changed. Please request an updated payment reference.','commerce-composition'));
        $source=(int)$o->get_meta('_request_source_id');
        if(get_post_status($source)!=='publish'||in_array(strtolower((string)get_post_meta($source,'stop_sale',true)),['1','yes','true'],true))throw new \Exception(__('This request is no longer available for payment.','commerce-composition'));
        $rate=(int)$o->get_meta('_request_rate_id');
        if($rate&&(get_post_status($rate)!=='publish'||in_array(strtolower((string)get_post_meta($rate,'stop_sale',true)),['1','yes','true'],true)))throw new \Exception(__('This request rate is no longer available for payment.','commerce-composition'));
        $o->update_meta_data('_request_advance_consent',['utc'=>gmdate('c'),'terms_sha256'=>hash('sha256',$o->get_meta('_request_advance_terms')),'policy_fingerprint'=>$o->get_meta('_request_customer_policy_fingerprint')]);$o->save();
    }
    public static function received($id):void {
        $o=wc_get_order($id);if(!self::isAdvance($o)||!$o->get_date_paid('edit'))return;
        $first=!$o->get_meta('_request_received_utc');
        $o->update_meta_data('_request_collection_state','received');$o->update_meta_data('_request_paid_amount',$o->get_total());
        if($first)$o->update_meta_data('_request_received_utc',gmdate('c'));
        $o->update_meta_data('_request_operational_state','under_agreement');$o->update_meta_data('_request_booking_confirmed','no');$o->save();self::refund($id);
        if($first)do_action('commerce_composition_request_advance_received',$id,(int)$o->get_meta('_request_submission_id'));
    }
    public static function refund($id):void {
        $o=wc_get_order($id);if(!self::isAdvance($o))return;
        $paid=(float)$o->get_meta('_request_paid_amount');$refund=(float)$o->get_total_refunded();
        $o->update_meta_data('_request_refunded_amount',wc_format_decimal($refund));$o->update_meta_data('_request_net_received',wc_format_decimal(max(0,$paid-$refund)));
        if($refund>0)$o->update_meta_data('_request_collection_state',$refund>=$paid?'refunded':'partially_refunded');$o->save();self::syncFluent($o);
    }
    private static function syncFluent($o):void {
        if(!class_exists('FluentForm\\App\\Models\\SubmissionMeta'))return;
        $fid=(int)$o->get_meta('_request_form_id');$sid=(int)$o->get_meta('_request_submission_id');
        if(!$fid||!$sid)return;
        $key='_request_advance_receipt_v1';
        $v=wp_json_encode(['order_id'=>$o->get_id(),'state'=>$o->get_meta('_request_collection_state'),
            'payment_kind'=>self::isFullDueNow($o)?'full_request_payment':'advance',
            'payment_schedule'=>$o->get_meta('_request_payment_schedule'),
            'payment_scope'=>$o->get_meta('_request_payment_scope')
               ?: (self::isFullDueNow($o)?'full':'partial'),
            'price_basis'=>$o->get_meta('_request_price_basis')?:'estimated',
            'booking_status'=>$o->get_meta('_request_booking_status')?:'awaiting_confirmation',
            'woocommerce_status'=>$o->get_status(),
            'paid'=>$o->get_meta('_request_paid_amount'),
            'refunded'=>$o->get_meta('_request_refunded_amount'),'net_received'=>$o->get_meta('_request_net_received'),'currency'=>$o->get_currency(),'final_trip_total'=>null,'booking_confirmed'=>false]);
        $meta=\FluentForm\App\Models\SubmissionMeta::where('response_id',$sid)->where('form_id',$fid)->where('meta_key',$key)->first();
        if($meta&&$meta->value===$v)return;
        if(!$meta)$meta=new \FluentForm\App\Models\SubmissionMeta();
        $meta->fill(['response_id'=>$sid,'form_id'=>$fid,'meta_key'=>$key,'value'=>$v,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')])->save();
        $note=new \FluentForm\App\Models\SubmissionMeta();
        $note->fill(['response_id'=>$sid,'form_id'=>$fid,'meta_key'=>'_notes','value'=>sprintf('WooCommerce request advance #%d: %s. Amount: %s %s. Received: %s. Refunded: %s. Final trip total: not agreed. Booking: not confirmed.',$o->get_id(),$o->get_meta('_request_collection_state'),$o->get_total(),$o->get_currency(),$o->get_meta('_request_paid_amount'),$o->get_meta('_request_refunded_amount')),
            'status'=>'note','user_id'=>0,'name'=>'WooCommerce request advances','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')])->save();
    }
    public static function notice($o):void {
        if(!self::isAdvance($o))return;
        $message=self::isFullDueNow($o)
            ? __('Full estimated payment receipt. Payment of the current estimate does not confirm availability, the final price or booking.','commerce-composition')
            : __('Request advance receipt. Final trip total: not agreed. Booking: not confirmed.','commerce-composition');
        echo '<p class="request-advance-notice"><strong>'.esc_html($message).'</strong></p>';
        echo '<p class="request-advance-policy-notice"><strong>'.esc_html(self::receiptLabel($o)).'</strong></p>';
    }
    public static function totals($rows,$o,$tax=''){
        if(!self::isAdvance($o))return $rows;
        if(isset($rows['order_total']))$rows['order_total']['label']=self::isFullDueNow($o)
            ?__('Full Est. Payment Due:','commerce-composition')
            :__('Request advance amount:','commerce-composition');
        $rows['request_advance_policy']=['label'=>__('Advance policy:','commerce-composition'),
            'value'=>esc_html(self::receiptLabel($o))];
        $rows['request_final_total']=['label'=>__('Final trip total:','commerce-composition'),'value'=>__('Pending agreement','commerce-composition')];
        $rows['request_balance']=['label'=>__('Remaining trip balance:','commerce-composition'),'value'=>__('Not calculated until final agreement','commerce-composition')];return $rows;
    }
    public static function routes():void {
        $auth=static fn()=>\Commerce\Composition\LifecycleAccess::can('commerce_manage_advances');
        register_rest_route('commerce-composition/v1','/request-advances/status',['methods'=>'GET','permission_callback'=>$auth,'callback'=>static function(){$r=rest_ensure_response(self::status());$r->header('Cache-Control','private,no-store');return $r;}]);
        register_rest_route('commerce-composition/v1','/request-advances/prepare',['methods'=>'POST','permission_callback'=>$auth,'callback'=>static function($r){
            $ref=$r->get_param('approved_reference');
            if($ref!==null&&(!is_array($ref)||array_diff(array_keys($ref),['amount','currency','complete'])||($ref['complete']??false)!==true))return new \WP_Error('advance_reference_invalid','Invalid reference',['status'=>400]);
            $v=self::create(absint($r['form_id']),absint($r['submission_id']),$ref);return is_wp_error($v)?new \WP_Error($v->get_error_code(),'Advance preparation unavailable',['status'=>409]):rest_ensure_response($v);
        }]);
        register_rest_route('commerce-composition/v1','/request-advances/policy',[
            ['methods'=>'GET','permission_callback'=>static fn()=>\Commerce\Composition\LifecycleAccess::can('commerce_manage_policies'),'callback'=>static fn()=>rest_ensure_response(self::policy())],
            ['methods'=>'POST','permission_callback'=>static fn()=>\Commerce\Composition\LifecycleAccess::can('commerce_manage_policies'),'callback'=>static fn($r)=>self::writePolicy($r,0)]
        ]);
        register_rest_route('commerce-composition/v1','/request-advances/sources/(?P<source_id>[1-9][0-9]*)',[
            ['methods'=>'GET','permission_callback'=>static fn()=>\Commerce\Composition\LifecycleAccess::can('commerce_manage_policies'),'callback'=>static fn($r)=>rest_ensure_response(self::policy((int)$r['source_id']))],
            ['methods'=>'POST','permission_callback'=>static fn()=>\Commerce\Composition\LifecycleAccess::can('commerce_manage_policies'),'callback'=>static fn($r)=>self::writePolicy($r,(int)$r['source_id'])]
        ]);
    }
    private static function writePolicy($r,int $source){
        if(!\Commerce\Composition\LifecycleAccess::can('commerce_manage_policies'))return new \WP_Error('advance_policy_permission_denied','Policy permission required',['status'=>403]);
        $v=$r->get_json_params();
        if(!is_array($v)||array_is_list($v)||array_diff(array_keys($v),array_keys(self::defaults())))return new \WP_Error('advance_policy_invalid','Invalid policy',['status'=>400]);
        foreach($v as $k=>$value){
            $valid=match($k){
                'enabled'=>in_array($value,['yes','no'],true),
                'balance_due_mode'=>in_array($value,['inherit','manual','on_arrival','days_before_arrival'],true),
                'refund_mode'=>in_array($value,['inherit','manual_review','non_refundable','refundable'],true),
                'balance_due_days','refund_cutoff_days'=>is_scalar($value)&&!is_bool($value)&&ctype_digit((string)$value)&&(int)$value>=0&&(int)$value<=36500,
                'refund_percent'=>is_scalar($value)&&!is_bool($value)&&is_numeric($value)&&is_finite((float)$value)&&(float)$value>=0&&(float)$value<=100,
                'mode'=>in_array($value,['inherit','request_only','fixed','percent','full'],true),
                'fixed_amount','percent'=>is_scalar($value)&&!is_bool($value)&&is_numeric($value)&&is_finite((float)$value)&&(float)$value>=0&&(float)$value<=($k==='percent'?100:999999999),
                'currency'=>is_string($value)&&array_key_exists($value,get_woocommerce_currencies()),
                'form_ids'=>is_array($value)&&array_is_list($value)&&count(array_filter($value,static fn($id)=>is_int($id)&&$id>0))===count($value),
                'email_field','name_field'=>is_string($value)&&preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,99}$/D',$value),
                'terms'=>is_string($value)&&strlen($value)<=12000,
                default=>false
            };
            if(!$valid)return new \WP_Error('advance_policy_invalid','Invalid policy field: '.$k,['status'=>400]);
        }
        if(isset($v['terms']))$v['terms']=sanitize_textarea_field($v['terms']);
        if($source){
            if(!current_user_can('edit_post',$source)||!get_post($source))return new \WP_Error('advance_source_permission_denied','Source not available',['status'=>403]);
            update_post_meta($source,'_commerce_request_advance_policy',$v);
        }else {
            $all=array_replace(self::policy(),$v);
            $valid=\MAD4B_Customer_Policy_Control::validate($all);
            if(is_wp_error($valid))return $valid;
            \MAD4B_Customer_Policy_Control::saveCompany($all);
            update_option(self::OPTION,array_intersect_key($all,array_flip(['enabled','form_ids','email_field','name_field'])),false);
        }
        do_action('commerce_composition_request_advance_policy_updated',self::status());
        return rest_ensure_response(self::policy($source));
    }
}
