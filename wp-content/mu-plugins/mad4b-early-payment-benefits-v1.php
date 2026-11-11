<?php
/**
 * Plugin Name: MAD4B Early Payment Benefits
 * Description: Optional company-owned early advance-payment loyalty policy, tour/rate overrides and two customer views.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Early_Payment_Benefits_V1', false)) {
final class MAD4B_Early_Payment_Benefits_V1 {
    public const OPTION = 'mad4b_early_payment_benefits_v1';
    public const META = '_mad4b_early_payment_benefits_v1';
    public const ORDER_META = '_mad4b_early_payment_benefits_snapshot_v1';
    public const STATE_META = '_mad4b_early_payment_benefits_state_v1';
    private const LANG = ['en'=>'English','ar'=>'Arabic','es'=>'Spanish','fr'=>'French','de'=>'German','it'=>'Italian'];

    /** Locale-independent tenant origin: never bind an offer to a WPML path. */
    private static function origin():string {
        return trailingslashit((string)get_option('siteurl'));
    }
    public static function defaults():array {
        $p=['enabled'=>'no','type'=>'service','benefit_id'=>'priority_review','window_hours'=>48];
        foreach (self::LANG as $code=>$name) {
            $p['short_'.$code]='';
            $p['details_'.$code]='';
        }
        return $p;
    }
    public static function validate(array $input):array|\WP_Error {
        $p=self::defaults();
        $p['enabled']=($input['enabled']??'no')==='yes'?'yes':'no';
        if (!in_array($input['type']??'service',['service'],true))
            return new \WP_Error('early_payment_type_not_supported');
        $n=$input['window_hours']??48;
        if (!is_scalar($n)||is_bool($n)||!preg_match('/^[0-9]{1,3}$/D',(string)$n)||
            (int)$n<1||(int)$n>720)return new \WP_Error('early_payment_invalid_window');
        $p['window_hours']=(int)$n;
        $benefitId=$input['benefit_id']??'priority_review';
        if(!is_string($benefitId)||!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$benefitId))
            return new \WP_Error('early_payment_invalid_benefit_id');
        $p['benefit_id']=$benefitId;
        foreach (self::LANG as $code=>$unused) {
            foreach(['short_','details_'] as $prefix) {
                $value=$input[$prefix.$code]??'';
                if(!is_string($value))return new \WP_Error('early_payment_copy_not_string');
                $p[$prefix.$code]=$prefix==='short_'?sanitize_text_field($value):
                    sanitize_textarea_field($value);
            }
            if (mb_strlen($p['short_'.$code])>180 || mb_strlen($p['details_'.$code])>1600)
                return new \WP_Error('early_payment_invalid_copy');
        }
        if ($p['enabled']==='yes'&&($p['short_en']===''||$p['details_en']===''))
            return new \WP_Error('early_payment_english_copy_required');
        return $p;
    }
    private static function originalPost(int $id):int {
        if ($id<1||!get_post_type($id))return 0;
        $translated=(int)apply_filters('wpml_object_id',$id,get_post_type($id),true,
            apply_filters('wpml_default_language',null));
        return $translated>0?$translated:$id;
    }
    /** Company site option -> tour -> selected rate, no cross-company store. */
    public static function policy(int $tour=0,int $rate=0):array {
        $stored=(array)get_option(self::OPTION,[]);
        $p=self::validate(array_replace(self::defaults(),$stored));
        if(is_wp_error($p))$p=self::defaults();
        // The company switch is a global kill switch. A tour/rate "on"
        // override may not silently re-enable a company-disabled benefit.
        $companyEnabled=$p['enabled']==='yes';
        foreach([$tour,$rate] as $id) {
            if(!$id)continue;
            foreach(array_unique(array_filter([self::originalPost($id),$id])) as $source) {
                $rule=get_post_meta($source,self::META,true);
                if(!is_array($rule))continue;
                if(($rule['mode']??'')==='off')$p['enabled']='no';
                if(($rule['mode']??'')==='on')$p['enabled']='yes';
                $hours=$rule['window_hours']??null;
                if(is_scalar($hours)&&!is_bool($hours)&&preg_match('/^[0-9]{1,3}$/D',(string)$hours)
                    &&(int)$hours>=1&&(int)$hours<=720)$p['window_hours']=(int)$hours;
            }
        }
        // An explicit override never publishes an empty/unsupported benefit.
        $p['active']=$companyEnabled && $p['enabled']==='yes'
            &&$p['type']==='service'
            &&$p['short_en']!==''&&$p['details_en']!=='';
        return $p;
    }
    public static function extendAdvancePolicy(array $advanced,int $tour,int $rate):array {
        $p=self::policy($tour,$rate);
        $advanced['early_payment_benefits']=[
            'active'=>(bool)$p['active'],'type'=>$p['type'],
            'window_hours'=>$p['window_hours'],
            'monetary_discount'=>false,
        ];
        return $advanced;
    }
    public static function snapshot($order):?array {
        if(!$order instanceof \WC_Order || !class_exists('\Commerce\Composition\RequestAdvancePayments') ||
            !\Commerce\Composition\RequestAdvancePayments::isAdvance($order))return null;
        $tour=(int)$order->get_meta('_request_source_id');
        $rate=(int)$order->get_meta('_request_rate_id');
        $p=self::policy($tour,$rate);
        if(!$p['active'])return null;
        $created=$order->get_date_created('edit');
        if(!$created)return null;
        $start=$created->getTimestamp();
        $copy=[];
        foreach(self::LANG as $lang=>$name) {
            $copy[$lang]=[
                'short'=>$p['short_'.$lang]?:$p['short_en'],
                'details'=>$p['details_'.$lang]?:$p['details_en'],
            ];
        }
        $s=['contract'=>'commerce.early-payment-benefits.v1','type'=>'service',
            'benefit_id'=>$p['benefit_id'],
            'company_origin'=>self::origin(),'source_id'=>$tour,'rate_id'=>$rate,
            'order_id'=>$order->get_id(),'issued_at'=>$start,
            'expires_at'=>$start+$p['window_hours']*3600,
            'window_hours'=>$p['window_hours'],'copy'=>$copy,
            'monetary_discount'=>false,'booking_confirmed'=>false,
            'state'=>'offered'];
        $s['fingerprint']=hash('sha256',wp_json_encode($s));
        return $s;
    }
    /** A new offer is frozen on the verified Woo advance order only. */
    public static function created($id,$fid,$sid):void {
        $o=wc_get_order(absint($id));
        if(!$o||!class_exists('\Commerce\Composition\RequestAdvancePayments')||
            !\Commerce\Composition\RequestAdvancePayments::isAdvance($o)||
            (int)$o->get_meta('_request_form_id')!==(int)$fid||
            (int)$o->get_meta('_request_submission_id')!==(int)$sid||
            $o->get_meta(self::ORDER_META))return;
        $snap=self::snapshot($o);
        if(!$snap)return;
        $o->update_meta_data(self::ORDER_META,$snap);
        $o->save();
    }
    public static function paymentReady($o):bool {
        if(!$o instanceof \WC_Order||!$o->needs_payment()||
            !class_exists('MAD4B_Tour_Form6_Submission_Mode_V1'))return false;
        $tour=(int)$o->get_meta('_request_source_id');
        return MAD4B_Tour_Form6_Submission_Mode_V1::inspect($tour)['mode']==='advance_checkout';
    }
    public static function offer($order,bool $requireGateway=true):?array {
        if(!$order instanceof \WC_Order||
            !class_exists('\Commerce\Composition\RequestAdvancePayments')||
            !\Commerce\Composition\RequestAdvancePayments::isAdvance($order))return null;
        $s=self::verifiedSnapshot($order);
        if(!$s)return null;
        $p=self::policy((int)$order->get_meta('_request_source_id'),(int)$order->get_meta('_request_rate_id'));
        if(!$p['active'])return null;
        if((int)($s['expires_at']??0)<=time() || ($s['state']??'offered')!=='offered')return null;
        $claim=self::claimState($order);
        if(($claim['state']??'offered')!=='offered')return null;
        if($requireGateway && !self::paymentReady($order))return null;
        return $s;
    }
    /**
     * Immutable terms are stored in ORDER_META with one fingerprint.
     * Payment, fulfilment and reversal events belong in a distinct journal.
     */
    public static function claimState($order):array {
        if(!$order instanceof \WC_Order)return ['state'=>'unavailable'];
        $raw=$order->get_meta(self::STATE_META);
        return is_array($raw)&&is_string($raw['state']??null)?$raw:['state'=>'offered'];
    }
    public static function decidePayment(array $offer,int $paidAt,bool $enabled):string {
        if($paidAt<1)return 'payment_unverified';
        if(!$enabled)return 'disabled_before_payment';
        return $paidAt<=(int)($offer['expires_at']??0)?'qualified_for_service':'expired';
    }
    /** Pure refund transition, so partial/full/duplicate events are testable without a gateway. */
    public static function refundState(array $claim,bool $fullyRefunded):string {
        $state=(string)($claim['state']??'offered');
        if(!$fullyRefunded)return $state;
        return in_array($state,['qualified_for_service','fulfilled'],true)
            ?'reversed_after_refund':$state;
    }
    private static function verifiedSnapshot($order):?array {
        if(!$order instanceof \WC_Order ||
            !class_exists('\\Commerce\\Composition\\RequestAdvancePayments')||
            !\Commerce\Composition\RequestAdvancePayments::isAdvance($order))return null;
        $offer=$order->get_meta(self::ORDER_META);
        if(!is_array($offer)||($offer['contract']??'')!=='commerce.early-payment-benefits.v1'
            ||($offer['company_origin']??'')!==self::origin()
            ||(int)($offer['order_id']??0)!==$order->get_id()
            ||(int)($offer['source_id']??0)!==(int)$order->get_meta('_request_source_id')
            ||(int)($offer['rate_id']??0)!==(int)$order->get_meta('_request_rate_id'))return null;
        $immutable=$offer;unset($immutable['fingerprint']);
        return is_string($offer['fingerprint']??null)&&
            hash_equals($offer['fingerprint'],hash('sha256',wp_json_encode($immutable)))
            ?$offer:null;
    }
    /** Link to the existing request's native Fluent Boards task, never duplicate it. */
    public static function boardLinked($order,int $taskId):void {
        if(!$order instanceof \WC_Order||$taskId<1)return;
        $claim=self::claimState($order);
        if(!in_array($claim['state']??'',['qualified_for_service','fulfilled','reversed_after_refund'],true))return;
        if((int)($claim['task_id']??0)===$taskId)return;
        $claim['task_id']=$taskId;
        $claim['updated_utc']=gmdate('c');
        $order->update_meta_data(self::STATE_META,$claim);
        $order->save();
    }
    /**
     * Refresh an existing, verified source task. No task creation or customer
     * notification: RequestLifecycle owns task creation and mail delivery.
     */
    private static function syncExistingBoardTask($order,array $claim):bool {
        $taskId=(int)($claim['task_id']??0);
        $fid=(int)$order->get_meta('_request_form_id');
        $sid=(int)$order->get_meta('_request_submission_id');
        if(!$taskId||!$fid||!$sid||
            !class_exists('\\Commerce\\Composition\\FluentBoardsTaskBridge')||
            !class_exists('\\FluentBoards\\App\\Models\\Task'))return false;
        try {
            if(\Commerce\Composition\FluentBoardsTaskBridge::taskForSubmission($fid,$sid)!==$taskId)
                return false;
            $cfg=(array)get_option('commerce_request_review_board',[]);
            $boardId=(int)($cfg['board_id']??0);
            $task=\FluentBoards\App\Models\Task::find($taskId);
            if(!$boardId||!$task||(int)$task->board_id!==$boardId)return false;
            $task->updateMeta('mad4b_early_payment_benefit_v1',[
                'order_id'=>$order->get_id(),
                'state'=>(string)($claim['state']??''),
                'snapshot_fingerprint'=>(string)($claim['offer_fingerprint']??''),
                'reversal_action'=>$claim['reversal_action']??null,
                'requires_separate_fulfilment'=>($claim['state']??'')==='qualified_for_service',
                'booking_confirmed'=>false,
            ]);
            return true;
        } catch (\Throwable $e) {
            // The irreversible order event is authoritative; failure to sync
            // a secondary task must not roll it back or spur duplicate email.
            return false;
        }
    }
    /** Manual service fulfilment is distinct from advance receipt and trip confirmation. */
    public static function fulfill(int $orderId,int $actor):array|\WP_Error {
        if($orderId<1||$actor<1||get_current_user_id()!==$actor||
            !current_user_can('commerce_manage_request_tasks'))
            return new \WP_Error('early_benefit_fulfilment_permission_denied');
        global $wpdb;
        $lock='mad4b_early_benefit_'.(int)$orderId;
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
            return new \WP_Error('early_benefit_fulfilment_busy');
        try {return self::fulfillLocked($orderId,$actor);}
        finally {$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    private static function fulfillLocked(int $orderId,int $actor):array|\WP_Error {
        $o=wc_get_order($orderId);
        if(!$o||!self::verifiedSnapshot($o))return new \WP_Error('early_benefit_order_invalid');
        $claim=self::claimState($o);
        if(($claim['state']??'')==='fulfilled')return ['status'=>'already_fulfilled'];
        if(($claim['state']??'')!=='qualified_for_service'||
           !$o->get_date_paid('edit')||
           (float)$o->get_total_refunded()+0.001>=(float)$o->get_total())
           return new \WP_Error('early_benefit_not_fulfillable');
        $claim['state']='fulfilled';
        $claim['fulfilled_by']=$actor;
        $claim['fulfilled_at']=gmdate('c');
        $claim['updated_utc']=$claim['fulfilled_at'];
        $o->update_meta_data(self::STATE_META,$claim);
        $o->save();
        $o->add_order_note('MAD4B loyalty service benefit fulfilled by authorized operations user #'.$actor.'. Booking confirmation remains separate.',false,false);
        self::syncExistingBoardTask($o,$claim);
        do_action('mad4b_early_payment_benefit_fulfilled',$orderId,$actor);
        return ['status'=>'fulfilled','order_id'=>$orderId];
    }
    private static function language():string {
        $lang=(string)apply_filters('wpml_current_language',substr(get_locale(),0,2));
        return isset(self::LANG[$lang])?$lang:'en';
    }
    private static function copy(array $s,string $lang=''):array {
        $lang=$lang!==''&&isset(self::LANG[$lang])?$lang:self::language();
        return (array)($s['copy'][$lang]??$s['copy']['en']??[]);
    }
    private static function headings():array {
        return ['en'=>'Early payment benefits','ar'=>'مزايا الدفع المبكر',
            'es'=>'Beneficios por pago anticipado','fr'=>'Avantages du paiement anticipé',
            'de'=>'Vorteile bei früher Zahlung','it'=>'Vantaggi del pagamento anticipato'];
    }
    /** Display helpers only accept a vetted immutable offer snapshot. */
    public static function renderShort(array $offer,string $locale=''):string {
        $lang=isset(self::LANG[$locale])?$locale:self::language();
        $copy=self::copy($offer,$lang);
        if(($copy['short']??'')==='')return '';
        $heading=self::headings();
        return '<aside class="mad4b-early-benefit mad4b-early-benefit--short">'.
            '<strong>'.esc_html($heading[$lang]).'</strong><p>'.
            esc_html($copy['short']).'</p></aside>';
    }
    public static function renderDetails(array $offer,string $locale=''):string {
        $lang=isset(self::LANG[$locale])?$locale:self::language();
        $copy=self::copy($offer,$lang);$heading=self::headings();
        if(($copy['details']??'')==='')return '';
        $note=[
          'en'=>'Available when this advance is paid before the deadline. It does not change the amount due or confirm the trip.',
          'ar'=>'تسري عند سداد العربون قبل الموعد المحدد. لا تغيّر مبلغ الدفع ولا تؤكد الحجز.',
          'es'=>'Disponible al pagar el anticipo antes del plazo. No cambia el importe ni confirma el viaje.',
          'fr'=>'Valable si l’acompte est payé avant la date limite, sans modifier le montant ni confirmer le voyage.',
          'de'=>'Gültig bei Anzahlung vor Ablauf der Frist. Ändert weder Betrag noch Reisebestätigung.',
          'it'=>'Valido pagando l’acconto entro la scadenza. Non modifica l’importo né conferma il viaggio.'
        ];
        $deadlineLabels=[
          'en'=>'Offer ends: %s','ar'=>'تنتهي الميزة في: %s',
          'es'=>'La oferta vence: %s','fr'=>'Fin de l’offre : %s',
          'de'=>'Angebot endet: %s','it'=>'L’offerta scade: %s'
        ];
        $deadline=wp_date(get_option('date_format').' '.get_option('time_format'),
          (int)($offer['expires_at']??0));
        return '<section class="mad4b-early-benefit mad4b-early-benefit--detail" aria-label="'.
          esc_attr($heading[$lang]).'"><h3>'.esc_html($heading[$lang]).'</h3>'.
          '<p><strong>'.esc_html($copy['short']??'').'</strong></p>'.
          '<p>'.nl2br(esc_html($copy['details'])).'</p>'.
          '<p class="mad4b-early-benefit-deadline">'.
          esc_html(sprintf($deadlineLabels[$lang],$deadline)).'</p>'.
          '<p class="mad4b-early-benefit-note">'.esc_html($note[$lang]).'</p></section>';
    }
    public static function confirmation($response,$form,$config=null,$sid=null,$data=null) {
        if(!is_array($response)||!is_object($form)||(int)($form->id??0)!==6||
           !is_numeric($sid)||(int)$sid<1||($response['success']??true)===false||
           !class_exists('\Commerce\Composition\RequestAdvancePayments')||
           !str_contains((string)($response['message']??''),'mad4b-request-confirmation'))
            return $response;
        $ctx=\Commerce\Composition\RequestAdvancePayments::submissionContext(6,(int)$sid);
        if(is_wp_error($ctx))return $response;
        $orders=\Commerce\Composition\RequestAdvancePayments::findBySource(6,(int)$sid,3);
        if(count($orders)!==1)return $response;
        $o=$orders[0];
        if((int)$o->get_meta('_request_submission_id')!==(int)$sid||
           (int)$o->get_meta('_request_form_id')!==6||
           (int)$o->get_meta('_request_source_id')!==(int)($ctx['source_id']??0))return $response;
        $s=self::offer($o);
        if(!$s)return $response;
        $locale=(string)apply_filters('wpml_element_language_code',null,[
          'element_id'=>(int)$ctx['source_id'],
          'element_type'=>'post_'.get_post_type((int)$ctx['source_id'])]);
        $response['message'].=self::renderShort($s,$locale);
        return $response;
    }
    public static function checkout():void {
        if(!function_exists('is_wc_endpoint_url')||!is_wc_endpoint_url('order-pay'))return;
        $id=absint(get_query_var('order-pay'));
        if(!$id)return;
        $o=wc_get_order($id);
        if(!$o||!isset($_GET['key'])||!is_string($_GET['key'])||
           !hash_equals((string)$o->get_order_key(),(string)wp_unslash($_GET['key'])))return;
        $s=self::offer($o);
        if(!$s)return;
        echo self::renderDetails($s,self::language());
    }
    public static function markReceived($orderId):void {
        $o=wc_get_order(absint($orderId));
        if(!$o||!$o->get_date_paid('edit'))return;
        $offer=self::verifiedSnapshot($o);
        if(!$offer)return;
        if(self::claimState($o)['state']!=='offered')return; // idempotent
        $live=self::policy((int)$o->get_meta('_request_source_id'),
            (int)$o->get_meta('_request_rate_id'));
        $paid=$o->get_date_paid('edit')->getTimestamp();
        $state=self::decidePayment($offer,$paid,(bool)$live['active']);
        $claim=[
            'contract'=>'commerce.early-benefit-claim.v1',
            'state'=>$state,
            'order_id'=>$o->get_id(),
            'offer_fingerprint'=>$offer['fingerprint'],
            'paid_at'=>$paid,
            'task_id'=>0,
            'updated_utc'=>gmdate('c'),
        ];
        $o->update_meta_data(self::STATE_META,$claim);
        $o->save();
        if($state==='qualified_for_service'){
            $o->add_order_note(
                'MAD4B Early Payment Benefits: qualified service benefit. Operations must fulfil and record completion; no trip confirmation or financial discount.',false,false);
            do_action('mad4b_early_payment_benefit_qualified',$orderId,$claim);
        }
    }
    public static function refunded($orderId):void {
        $o=wc_get_order(absint($orderId));
        if(!$o||!self::verifiedSnapshot($o))return;
        $claim=self::claimState($o);
        if(!in_array($claim['state']??'',['qualified_for_service','fulfilled'],true))return;
        $full=(float)$o->get_total_refunded()+0.001>=(float)$o->get_total();
        $state=self::refundState($claim,$full);
        if($state!=='reversed_after_refund')return;
        $alreadyFulfilled=$claim['state']==='fulfilled';
        $claim['state']=$state;
        $claim['reversal_action']=$alreadyFulfilled?'review_delivered_service':'cancel_pending_service';
        $claim['reversed_at']=gmdate('c');
        $claim['updated_utc']=$claim['reversed_at'];
        $o->update_meta_data(self::STATE_META,$claim);
        $o->save();
        $o->add_order_note($alreadyFulfilled
            ?'MAD4B loyalty: full advance refund after service fulfilment; manual service-reversal review required.'
            :'MAD4B loyalty: service benefit cancelled following full advance refund.',false,false);
        self::syncExistingBoardTask($o,$claim);
        do_action('mad4b_early_payment_benefit_reversed',$orderId,$claim);
    }
    private static function canManage():bool {
        return current_user_can('commerce_manage_policies') &&
           class_exists('MAD4B_Lifecycle_Access') &&
           MAD4B_Lifecycle_Access::can('commerce_manage_policies');
    }
    public static function menu():void {
        add_menu_page('Loyalty Policies — Early Payment Benefits',
            'Loyalty Policies','commerce_manage_policies','mad4b-early-payment-benefits',
            [self::class,'admin'],'dashicons-awards',58);
        add_menu_page('Loyalty Benefit Operations',
            'Loyalty Operations','commerce_manage_request_tasks',
            'mad4b-early-benefit-operations',[self::class,'operations'],'dashicons-yes-alt',59);
    }
    public static function admin():void {
        if(!self::canManage())wp_die('Policy manager permission required');
        $notice='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&isset($_POST['mad4b_early_benefit_save'])){
            check_admin_referer('mad4b_early_payment_policy');
            $raw=isset($_POST['early'])&&is_array($_POST['early'])?
               wp_unslash($_POST['early']):[];
            $v=self::validate($raw);
            if(is_wp_error($v))$notice=$v->get_error_code();
            else {update_option(self::OPTION,$v,false);$notice='Saved. Company policy updated.';}
        }
        $p=self::policy();
        echo '<div class="wrap"><h1>Loyalty Policies — Early Payment Benefits</h1>';
        echo '<p>Per-company policy. Default OFF. Tour and rate overrides inherit this policy. Initial release: service benefits only, no automatic monetary discount or booking confirmation.</p>';
        if($notice!=='')echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>';
        echo '<form method="post">';
        wp_nonce_field('mad4b_early_payment_policy');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Enable benefit</th><td><label><input type="checkbox" name="early[enabled]" value="yes" '.
          checked($p['enabled'],'yes',false).'> Show on eligible advance requests</label></td></tr>';
        echo '<tr><th>Benefit kind</th><td><select name="early[type]"><option value="service">Service benefit (no price adjustment)</option></select></td></tr>';
        echo '<tr><th>Benefit ID</th><td><input name="early[benefit_id]" pattern="[a-z][a-z0-9_]{2,63}" class="regular-text" value="'.
            esc_attr($p['benefit_id']).'"><p class="description">Stable service code. Example: priority_review. Changing it affects future offers only.</p></td></tr>';
        echo '<tr><th>Early payment window</th><td><input name="early[window_hours]" type="number" min="1" max="720" value="'.
          esc_attr($p['window_hours']).'"> hours after the WooCommerce request is created</td></tr>';
        foreach(self::LANG as $lang=>$name){
            echo '<tr><th>'.esc_html($name).'</th><td>';
            echo '<label>Short thank-you message<br><input type="text" class="regular-text" maxlength="180" name="early[short_'.$lang.']" value="'.
              esc_attr($p['short_'.$lang]).'"></label><br>';
            echo '<label>Detailed payment-page explanation<br><textarea class="large-text" rows="3" maxlength="1600" name="early[details_'.$lang.']">'.
              esc_textarea($p['details_'.$lang]).'</textarea></label></td></tr>';
        }
        echo '</tbody></table><p class="description">When OFF, nothing is rendered. A new advance order must be created while enabled to freeze its benefit policy. Existing orders are never backfilled automatically.</p>';
        submit_button('Save Early Payment Policy','primary','mad4b_early_benefit_save');
        echo '</form></div>';
    }
    public static function metabox($post):void {
        if(!self::canManage())return;
        $r=get_post_meta($post->ID,self::META,true);$r=is_array($r)?$r:[];
        wp_nonce_field('mad4b_early_payment_post','mad4b_early_payment_post_nonce');
        echo '<p>Override this company loyalty policy for the tour or rate.</p>';
        echo '<select name="mad4b_early[mode]">';
        foreach(['inherit'=>'Inherit company','on'=>'Enable','off'=>'Disable'] as $k=>$label)
           echo '<option value="'.esc_attr($k).'" '.selected($r['mode']??'inherit',$k,false).'>'.esc_html($label).'</option>';
        echo '</select><p><label>Override hours (blank = inherit)<br><input name="mad4b_early[window_hours]" type="number" min="1" max="720" value="'.
         esc_attr($r['window_hours']??'').'"></label></p>';
    }
    public static function savePost($id):void {
        if(!isset($_POST['mad4b_early_payment_post_nonce'])||
          !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_early_payment_post_nonce'])),'mad4b_early_payment_post')||
          !self::canManage()||!current_user_can('edit_post',$id)||
          wp_is_post_revision($id)||wp_is_post_autosave($id))return;
        $raw=(array)wp_unslash($_POST['mad4b_early']??[]);
        $mode=(string)($raw['mode']??'inherit');
        if(!in_array($mode,['inherit','on','off'],true))return;
        $hours=(string)($raw['window_hours']??'');
        if($hours!=='' && (!preg_match('/^[0-9]{1,3}$/D',$hours)||(int)$hours<1||(int)$hours>720))return;
        if($mode==='inherit'&&$hours==='')delete_post_meta($id,self::META);
        else update_post_meta($id,self::META,['mode'=>$mode,
             'window_hours'=>$hours===''?null:(int)$hours]);
    }
    public static function operations():void {
        if(!current_user_can('commerce_manage_request_tasks')||
            !class_exists('MAD4B_Lifecycle_Access')||
            !MAD4B_Lifecycle_Access::can('commerce_manage_request_tasks'))
                wp_die('Operations permission required');
        $page=max(1,min(10000,absint($_GET['paged']??1)));
        $query=[
          'limit'=>25,'page'=>$page,'paginate'=>true,'orderby'=>'date','order'=>'DESC',
          'status'=>array_keys(wc_get_order_statuses()),
        ];
        $hpos=class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil') &&
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        if($hpos)$query['meta_query']=[['key'=>self::STATE_META,'compare'=>'EXISTS']];
        else {$query['meta_key']=self::STATE_META;$query['meta_compare']='EXISTS';}
        $orders=wc_get_orders($query);
        echo '<div class="wrap"><h1>Early Payment Benefit Operations</h1><p>Service fulfilment is separate from advance payment and final booking confirmation. This list shows verified advance claims, not guest payment links.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Advance order</th><th>Benefit state</th><th>Board task</th><th>Action</th></tr></thead><tbody>';
        $count=0;
        foreach((array)($orders->orders??[]) as $o){
            if(!self::verifiedSnapshot($o))continue;
            $claim=self::claimState($o);
            $state=(string)($claim['state']??'');
            if(!in_array($state,['qualified_for_service','fulfilled','reversed_after_refund'],true))continue;
            $count++;
            echo '<tr><td>#'.(int)$o->get_id().'</td><td>'.esc_html($state).'</td><td>'.(int)($claim['task_id']??0).'</td><td>';
            if($state==='qualified_for_service'){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                echo '<input type="hidden" name="action" value="mad4b_early_benefit_fulfill">';
                echo '<input type="hidden" name="order_id" value="'.(int)$o->get_id().'">';
                wp_nonce_field('mad4b_early_fulfill_'.$o->get_id());
                submit_button('Mark service fulfilled','small','submit',false);
                echo '</form>';
            }elseif($state==='fulfilled')echo 'Completed';
            else echo esc_html(($claim['reversal_action']??'')==='review_delivered_service'
                ?'Review delivered service after refund':'Cancelled after full refund');
            echo '</td></tr>';
        }
        if(!$count)echo '<tr><td colspan="4">No eligible claims on this page.</td></tr>';
        $totalPages=max(1,(int)ceil((int)($orders->total??0)/25));
        echo '</tbody></table><p>Page '.(int)$page.' / '.$totalPages.'</p>';
        if($totalPages>1) {
            echo '<div class="tablenav-pages">'.
                wp_kses_post(paginate_links([
                    'base'=>add_query_arg(['page'=>'mad4b-early-benefit-operations','paged'=>'%#%'],admin_url('admin.php')),
                    'format'=>'','current'=>$page,'total'=>$totalPages,
                    'prev_text'=>'Previous','next_text'=>'Next',
                ])).'</div>';
        }
        echo '</div>';
    }
    public static function fulfillPost():void {
        if(!current_user_can('commerce_manage_request_tasks'))wp_die('Operations permission required',403);
        $id=absint($_POST['order_id']??0);
        check_admin_referer('mad4b_early_fulfill_'.$id);
        $out=self::fulfill($id,get_current_user_id());
        $status=is_wp_error($out)?$out->get_error_code():'fulfilled';
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-early-benefit-operations',
            'result'=>sanitize_key($status)],admin_url('admin.php')));
        exit;
    }
    public static function policyHubLink():void {
        if(!is_admin()||!self::canManage()||
            sanitize_key((string)($_GET['page']??''))!=='mad4b-company-policy-library')return;
        echo '<div class="notice notice-info"><p><strong>Loyalty policies:</strong> '.
          '<a href="'.esc_url(admin_url('admin.php?page=mad4b-early-payment-benefits')).'">'.
          'Early Payment Benefits</a> — optional company, tour and rate policy.</p></div>';
    }
    public static function assets():void {
        // The CSS is tiny and contains no data; a tour can inherit an enabled
        // rate-only override even when the generic tour policy is OFF.
        if(is_singular('tours-and-activities')){
            // Company master OFF means no rate can offer a benefit. With company
            // ON, a rate-specific ON may override a tour OFF.
            if(!self::policy()['active'])return;
        }elseif(function_exists('is_wc_endpoint_url')&&is_wc_endpoint_url('order-pay')){
            $order=wc_get_order(absint(get_query_var('order-pay')));
            if(!self::offer($order))return;
        }else return;
        $path=__DIR__.'/assets/mad4b-early-payment-benefits-v1.css';
        if(is_readable($path))wp_enqueue_style('mad4b-early-payment-benefits',
          content_url('mu-plugins/assets/mad4b-early-payment-benefits-v1.css'),[],(string)filemtime($path));
    }
    public static function boot():void {
        add_filter('commerce_composition_request_advance_policy',[self::class,'extendAdvancePolicy'],30,3);
        add_action('commerce_composition_request_advance_created',[self::class,'created'],8,3);
        add_action('commerce_composition_request_advance_received',[self::class,'markReceived'],15,1);
        add_action('woocommerce_order_refunded',[self::class,'refunded'],35,1);
        add_filter('fluentform/submission_confirmation',[self::class,'confirmation'],10070,5);
        add_action('woocommerce_pay_order_before_payment',[self::class,'checkout'],23);
        add_action('admin_menu',[self::class,'menu']);
        add_action('admin_post_mad4b_early_benefit_fulfill',[self::class,'fulfillPost']);
        add_action('admin_notices',[self::class,'policyHubLink']);
        add_action('add_meta_boxes',static function(){
            foreach(['tours-and-activities','tour-rates'] as $type)
                add_meta_box('mad4b-early-benefit','Early Payment Benefits',
                    [self::class,'metabox'],$type,'normal','default');
        });
        add_action('save_post',[self::class,'savePost'],25);
        add_action('wp_enqueue_scripts',[self::class,'assets'],85);
    }
}
MAD4B_Early_Payment_Benefits_V1::boot();
}
