<?php
/** Canonical JetEngine policy library: localized narrative + explicit Woo advance controls. */
defined('ABSPATH')||exit;
require_once __DIR__.'/LifecycleAccess.php';
require_once __DIR__ . '/CompanyLegalRules.php';

final class MAD4B_Customer_Policy_Control {
    const OPTION='mad4b-company-policy-library';
    private static array $rendering=[];
    public static function types():array {return ['cancellation'=>'Cancellation & Refunds','children'=>'Children & Ages','booking'=>'Booking & Deposits','accommodation'=>'Accommodation','pricing'=>'Prices & Exclusions','tipping'=>'Tipping & Gratuities','force_majeure'=>'Force Majeure','general'=>'General Travel Terms'];}
    public static function locales():array {return ['en'=>'English','ar'=>'Arabic','fr'=>'French','de'=>'German','it'=>'Italian','es'=>'Spanish'];}
    public static function language():string {$v=apply_filters('wpml_current_language',null);return is_string($v)&&isset(self::locales()[$v])?$v:(isset(self::locales()[substr(get_locale(),0,2)])?substr(get_locale(),0,2):'en');}
    public static function source(int $id=0):int {
        if(!$id){$id=(int)get_the_ID();if(get_post_type($id)==='elementor_library')$id=(int)get_queried_object_id();}
        return $id&&get_post_status($id)==='publish'&&get_post_type($id)==='tours-and-activities'?$id:0;
    }
    public static function labels():array {
        $d=[
          'en'=>['Advance','By agreement — no online collection','of the verified reference','Fixed request advance','Final trip price and availability remain subject to agreement. Payment does not confirm a booking.','Balance','By agreement','On arrival','%d days before arrival','Refunds','Subject to review','Non-refundable advance','Up to %s%%, at least %d days before travel','Company-specific policy details have not yet been confirmed. Please verify terms before booking.'],
          'ar'=>['المقدم','حسب الاتفاق — لا يوجد تحصيل إلكتروني','من المبلغ المرجعي الموثوق','مقدم ثابت للطلب','السعر النهائي والتوفر يخضعان للاتفاق. الدفع لا يؤكد الحجز.','المتبقي','حسب الاتفاق','عند الوصول','قبل الوصول بـ %d يوم','الاسترداد','يخضع للمراجعة','مقدم غير قابل للاسترداد','حتى %s%% قبل السفر بـ %d يوم على الأقل','لم يتم تأكيد تفاصيل سياسة الشركة بعد. يُرجى التحقق من الشروط قبل الحجز.'],
          'es'=>['Anticipo','Según acuerdo — sin cobro en línea','de la referencia verificada','Anticipo fijo','El precio final y la disponibilidad están sujetos a acuerdo. El pago no confirma una reserva.','Saldo','Según acuerdo','A la llegada','%d días antes de la llegada','Reembolsos','Sujeto a revisión','Anticipo no reembolsable','Hasta %s%%, al menos %d días antes del viaje','Las condiciones específicas de la empresa aún no están confirmadas. Verifícalas antes de reservar.'],
          'fr'=>['Avance','Selon accord — sans encaissement en ligne','de la référence vérifiée','Avance fixe','Le prix final et la disponibilité restent à convenir. Le paiement ne confirme pas une réservation.','Solde','Selon accord','À l’arrivée','%d jours avant l’arrivée','Remboursements','Sous réserve de vérification','Avance non remboursable','Jusqu’à %s%%, au moins %d jours avant le voyage','Les conditions propres à cette société ne sont pas encore confirmées. Veuillez les vérifier avant de réserver.'],
          'de'=>['Anzahlung','Nach Vereinbarung — kein Onlineeinzug','des bestätigten Referenzbetrags','Feste Anzahlung','Endpreis und Verfügbarkeit bleiben zu vereinbaren. Die Zahlung bestätigt keine Buchung.','Restbetrag','Nach Vereinbarung','Bei Ankunft','%d Tage vor Ankunft','Erstattungen','Nach Prüfung','Nicht erstattungsfähige Anzahlung','Bis zu %s%%, mindestens %d Tage vor der Reise','Die unternehmensspezifischen Bedingungen sind noch nicht bestätigt. Bitte vor der Buchung prüfen.'],
          'it'=>['Anticipo','Da concordare — nessun incasso online','del riferimento verificato','Anticipo fisso','Prezzo finale e disponibilità restano da concordare. Il pagamento non conferma una prenotazione.','Saldo','Da concordare','All’arrivo','%d giorni prima dell’arrivo','Rimborsi','Soggetto a verifica','Anticipo non rimborsabile','Fino al %s%%, almeno %d giorni prima del viaggio','Le condizioni specifiche della società non sono ancora confermate. Verificale prima di prenotare.']
        ];return $d[self::language()];
    }
    public static function extraDefaults():array {return ['balance_due_mode'=>'manual','balance_due_days'=>'0','refund_mode'=>'manual_review','refund_percent'=>'0','refund_cutoff_days'=>'0'];}
    public static function globalRules(array $p):array {
        $o=(array)get_option(self::OPTION,[]);$p=array_replace(self::extraDefaults(),$p);
        $map=['mode'=>'advance_mode','fixed_amount'=>'advance_amount','percent'=>'advance_percent','currency'=>'advance_currency','balance_due_mode'=>'balance_due_mode','balance_due_days'=>'balance_due_days','refund_mode'=>'refund_mode','refund_percent'=>'refund_percent','refund_cutoff_days'=>'refund_cutoff_days'];
        foreach($map as $key=>$field)if(isset($o['commerce_'.$field])&&$o['commerce_'.$field]!=='')$p[$key]=$o['commerce_'.$field];
        return $p;
    }
    public static function validate(array $p,bool $partial=false):array|WP_Error {
        $schema=['mode'=>['inherit','request_only','fixed','percent','full'],'balance_due_mode'=>['inherit','manual','on_arrival','days_before_arrival'],'refund_mode'=>['inherit','manual_review','non_refundable','refundable']];$out=[];
        foreach($p as $k=>$v){
            if(isset($schema[$k])){if(!is_string($v)||!in_array($v,$schema[$k],true))return new WP_Error('customer_policy_invalid_'.$k);$out[$k]=$v;}
            elseif(in_array($k,['fixed_amount','percent','refund_percent','balance_due_days','refund_cutoff_days'],true)){
                if(!is_scalar($v)||is_bool($v)||!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>(in_array($k,['percent','refund_percent'],true)?100:(str_ends_with($k,'days')?36500:999999999)))return new WP_Error('customer_policy_invalid_'.$k);
                if(str_ends_with($k,'days')&&(float)$v!==(float)(int)$v)return new WP_Error('customer_policy_invalid_days');$out[$k]=(string)$v;
            }elseif($k==='currency') {if(!is_string($v)||!isset(get_woocommerce_currencies()[$v]))return new WP_Error('customer_policy_invalid_currency');$out[$k]=$v;}
        }
        if(!$partial&&($out['mode']??'')==='fixed'&&(float)($out['fixed_amount']??0)<=0)return new WP_Error('customer_policy_fixed_amount_required');
        if(!$partial&&($out['mode']??'')==='percent'&&(float)($out['percent']??0)<=0)return new WP_Error('customer_policy_percent_required');
        return $out;
    }
    public static function saveCompany(array $p):void {
        $v=self::validate($p);if(is_wp_error($v))return;
        $o=(array)get_option(self::OPTION,[]);$map=['mode'=>'advance_mode','fixed_amount'=>'advance_amount','percent'=>'advance_percent','currency'=>'advance_currency'];
        foreach($v as $key=>$value)$o['commerce_'.($map[$key]??$key)]=$value;
        update_option(self::OPTION,$o,false);
    }
    private static function nonempty($v):bool {return is_string($v)&&trim(str_replace(["\xc2\xa0",'&nbsp;'],' ',wp_strip_all_tags($v)))!=='';}
    public static function narrative(string $type,int $id=0):array {
        if(!isset(self::types()[$type]))return ['html'=>'','source'=>'unconfigured'];
        $id=$id?self::source($id):0;$meta=['booking'=>'booking_policy','cancellation'=>'cancelation_policy','pricing'=>'prices_policy','children'=>'children_policy','accommodation'=>'accomodation_policy'];
        if($id){$locale=apply_filters('wpml_element_language_code',null,['element_id'=>$id,'element_type'=>'post_tours-and-activities']);
            if(is_string($locale)&&$locale!==''&&$locale!==self::language()){$translated=(int)apply_filters('wpml_object_id',$id,'tours-and-activities',false,self::language());$id=$translated?self::source($translated):0;}}
        $key=$meta[$type]??null;
        if($id&&$key){$text=get_post_meta($id,$key,true);if(self::nonempty($text))return ['html'=>wp_kses_post($text),'source'=>'tour','source_id'=>$id];
            $template=get_post_meta($id,$key.'_template',true);
            if(is_string($template)&&preg_match('/^\[elementor-template\s+id=["\x27]?([1-9][0-9]*)["\x27]?\s*\]$/D',trim($template),$m)&&get_post_type((int)$m[1])==='elementor_library'&&get_post_status((int)$m[1])==='publish'){
                $guard=$id.':'.$type;if(empty(self::$rendering[$guard])){self::$rendering[$guard]=true;$old=$GLOBALS['post']??null;
                    try {$GLOBALS['post']=get_post($id);$html=do_shortcode($template);if(self::nonempty($html)&&!str_contains($html,'data-policy-source="unconfigured"'))return ['html'=>wp_kses_post($html),'source'=>'tour_template','source_id'=>(int)$m[1]];}
                    finally{$GLOBALS['post']=$old;unset(self::$rendering[$guard]);}
                }
            }
        }
        $o=(array)get_option(self::OPTION,[]);$text=$o[$type.'_'.self::language()]??'';
        if(($o[$type.'_approval']??'')==='approved'&&trim((string)($o[$type.'_revision']??''))!==''&&self::nonempty($text)){
            $name=function_exists('mad4b_company_template_public_name')?mad4b_company_template_public_name():get_bloginfo('name');
            return ['html'=>wp_kses_post(str_replace('{{company_name}}',esc_html($name),$text)),'source'=>'company','revision'=>(string)$o[$type.'_revision']];
        }
        return ['html'=>'','source'=>'unconfigured'];
    }
    public static function values(array $p):array {
        $l=self::labels();$mode=$p['mode']??'request_only';
        $advance=match($mode){'percent'=>(string)($p['percent']??0).'% '.$l[2],'full'=>'100% '.$l[2],'fixed'=>$l[3].': '.wp_strip_all_tags(wc_price((float)($p['fixed_amount']??0),['currency'=>$p['currency']??get_woocommerce_currency()])),default=>$l[1]};
        $balance=match($p['balance_due_mode']??'manual'){'on_arrival'=>$l[7],'days_before_arrival'=>sprintf($l[8],(int)($p['balance_due_days']??0)),default=>$l[6]};
        $refund=match($p['refund_mode']??'manual_review'){'non_refundable'=>$l[11],'refundable'=>sprintf($l[12],(string)($p['refund_percent']??0),(int)($p['refund_cutoff_days']??0)),default=>$l[10]};
        return ['advance'=>$advance,'balance'=>$balance,'refund'=>$refund,'currency'=>$p['currency']??get_woocommerce_currency(),'percent'=>(string)($p['percent']??0),'remaining_percent'=>($mode==='percent'?rtrim(rtrim(number_format(max(0,100-(float)($p['percent']??0)),2,'.',''),'0'),'.'):'pending agreement'),'fixed_amount'=>(string)($p['fixed_amount']??0),'notice'=>$l[4]];
    }
    public static function summary(array $p):string {
        $l=self::labels();$v=self::values($p);$html='<dl class="mad4b-policy-controls">';
        foreach([[$l[0],$v['advance']],[$l[5],$v['balance']],[$l[9],$v['refund']]] as [$name,$value])$html.='<dt>'.esc_html($name).'</dt><dd>'.esc_html($value).'</dd>';
        return $html.'</dl><p class="mad4b-policy-notice">'.esc_html($v['notice']).'</p>';
    }
    private static function expand(string $html,array $p,int $id=0,int $rate=0):string {
        $html=preg_replace_callback('/\[mad4b_accommodation(?:\s+([^\]]*))?\]/',static function($m)use($id,$rate){$a=shortcode_parse_atts($m[1]??'');$view=in_array($a['view']??'summary',['summary','units','full'],true)?($a['view']??'summary'):'summary';return MAD4B_Accommodation_Policy_Component::shortcode(['tour_id'=>$id,'rate_id'=>$rate,'view'=>$view]);},$html);
        $values=self::values($p);
        $html=preg_replace_callback('/\[mad4b_policy_value\s+([^\]]*)\]/',static function($m)use($values){$a=shortcode_parse_atts($m[1]);return esc_html($values[$a['field']??'']??'');},$html);
        return wp_kses_post(MAD4B_Company_Legal_Rules::expand(str_replace('[mad4b_policy_summary]',self::summary($p),$html)));
    }
    public static function complete(array $p,int $id,int $rate=0):array {
        $valid=self::validate($p);if(is_wp_error($valid)){$p['enabled']='no';$p['terms']='';$p['policy_validation_error']=$valid->get_error_code();return $p;}
        $booking=self::narrative('booking',$id);$cancel=self::narrative('cancellation',$id);
        // Narrative is never parsed into a price rule. Empty/unapproved company text cannot authorize collection.
        if($booking['html']!==''&&$cancel['html']!=='')$p['terms']=wp_strip_all_tags(self::expand($booking['html'],$p).' '.self::expand($cancel['html'],$p).' '.self::summary($p));
        else $p['terms']='';
        $p['policy_text_source']=$booking['source'];return $p;
    }
    public static function render(string $type='booking',int $id=0,bool $controls=true):string {
        if(!isset(self::types()[$type]))return '';$id=self::source($id);
        $p=\Commerce\Composition\RequestAdvancePayments::policy($id);
        $n=self::narrative($type,$id);$l=self::labels();$html=$n['html']!==''?self::expand($n['html'],$p,$id):'<p>'.esc_html($l[13]).'</p>';
        if($type==='accommodation'&&!str_contains($n['html'],'[mad4b_accommodation'))$html.=MAD4B_Accommodation_Policy_Component::shortcode(['tour_id'=>$id]);
        if($controls&&$type==='booking')$html=self::summary($p).$html;
        return '<div class="mad4b-customer-policy" data-policy-type="'.esc_attr($type).'" data-policy-source="'.esc_attr($n['source']).'">'.$html.'</div>';
    }
    public static function snapshot(int $id,int $rate=0):array {
        $p=\Commerce\Composition\RequestAdvancePayments::policy($id,$rate);$b=self::narrative('booking',$id);$c=self::narrative('cancellation',$id);
        $rules=array_intersect_key($p,array_flip(['mode','fixed_amount','percent','currency','balance_due_mode','balance_due_days','refund_mode','refund_percent','refund_cutoff_days']));
        $s=['contract'=>'commerce.customer-policy.v1','origin'=>home_url('/'),'source_id'=>$id,'rate_id'=>$rate,'language'=>self::language(),'rules'=>$rules,'narrative_sources'=>['booking'=>$b['source'],'cancellation'=>$c['source']],
            'html'=>self::summary($p).self::expand($b['html'],$p,$id,$rate).self::expand($c['html'],$p,$id,$rate),'final_trip_total'=>null,'booking_confirmed'=>false];
        $s['company_legal_rules']=MAD4B_Company_Legal_Rules::snapshot();$s['fingerprint']=hash('sha256',wp_json_encode($s));return $s;
    }
    public static function refundPlan($order,string $travelDate,?string $at=null):array|WP_Error {
        if(!\Commerce\Composition\RequestAdvancePayments::isAdvance($order))return new WP_Error('policy_not_advance');
        $s=$order->get_meta('_request_customer_policy_snapshot');if(!is_array($s))return new WP_Error('policy_snapshot_missing');
        $r=$s['rules'];$travel=DateTimeImmutable::createFromFormat('!Y-m-d',$travelDate,wp_timezone());$now=DateTimeImmutable::createFromFormat('!Y-m-d',$at??wp_date('Y-m-d'),wp_timezone());
        if(!$travel||!$now||$travel->format('Y-m-d')!==$travelDate||$now->format('Y-m-d')!==($at??wp_date('Y-m-d')))return new WP_Error('policy_refund_date_invalid');
        $days=(int)$now->diff($travel)->format('%r%a');$net=max(0,(float)$order->get_meta('_request_paid_amount')-(float)$order->get_total_refunded());$mode=$r['refund_mode']??'manual_review';
        $pct=$mode==='non_refundable'?0:($mode==='refundable'&&$days>=(int)($r['refund_cutoff_days']??0)?(float)($r['refund_percent']??0):null);
        $suggested=null;if($pct!==null){$m=\Commerce\Composition\RequestAdvancePayments::amount(['mode'=>'fixed','fixed_amount'=>$net*$pct/100,'currency'=>$order->get_currency()]);$suggested=is_wp_error($m)?0:(float)$m['amount'];}
        return ['status'=>'refund_review_required','policy_percent'=>$pct,'suggested_amount'=>$suggested,'currency'=>$order->get_currency(),'policy_fingerprint'=>$s['fingerprint'],'automatic_refund'=>false,'accounting_posted'=>false];
    }
    public static function registerPage():void {
        if(!function_exists('jet_engine'))return;$manager=jet_engine()->options_pages??null;if(!is_object($manager)||!method_exists($manager,'register_new_options_page'))return;
        $fields=[];$i=910000;
        foreach(self::types() as $type=>$label){
            $fields[]=['name'=>$type.'_approval','title'=>$label.' — publication','type'=>'select','options_source'=>'manual','options'=>[['key'=>'draft','value'=>'Draft'],['key'=>'approved','value'=>'Approved']]];
            $fields[]=['name'=>$type.'_revision','title'=>$label.' — revision','type'=>'text'];
            foreach(self::locales() as $lang=>$name)$fields[]=['name'=>$type.'_'.$lang,'title'=>$label.' — '.$name,'type'=>'wysiwyg','description'=>'HTML and [mad4b_policy_value field="advance"] / [mad4b_policy_summary] are supported. No number is inferred from text.'];
        }
        foreach(self::controlFields() as $key=>$spec){$f=['name'=>'commerce_'.$spec['jet'],'title'=>$spec['label'],'type'=>$spec['options']?'select':'text'];if($spec['options']){$f['options_source']='manual';$f['options']=array_map(static fn($k,$v)=>['key'=>$k,'value'=>$v],array_keys($spec['options']),array_values($spec['options']));}$fields[]=$f;}
        foreach($fields as &$field)$field+=['id'=>$i++,'object_type'=>'field','width'=>'100%'];unset($field);
        $fields=array_merge($fields,MAD4B_Company_Legal_Rules::fields());
        $manager->register_new_options_page(['id'=>910000,'slug'=>self::OPTION,'labels'=>['name'=>'Company Customer Policies','menu_name'=>'Customer Policies'],'parent'=>'woocommerce','position'=>86,'icon'=>'dashicons-media-text','capability'=>'commerce_manage_policies','storage_type'=>'default','option_prefix'=>true,'autoload_option'=>false,'hide_field_names'=>false,'fields'=>$fields]);
    }
    public static function controlFields():array {
        return [
          'mode'=>['jet'=>'advance_mode','label'=>'Advance collection','options'=>['inherit'=>'Inherit Woo/company policy','request_only'=>'Request only','fixed'=>'Fixed advance','percent'=>'Percentage of verified reference','full'=>'100% of verified reference']],
          'fixed_amount'=>['jet'=>'advance_amount','label'=>'Fixed advance amount','options'=>[]],
          'percent'=>['jet'=>'advance_percent','label'=>'Advance percentage (0–100)','options'=>[]],
          'currency'=>['jet'=>'advance_currency','label'=>'Fixed advance currency','options'=>function_exists('get_woocommerce_currencies')?get_woocommerce_currencies():[]],
          'balance_due_mode'=>['jet'=>'balance_due_mode','label'=>'Final balance due','options'=>['manual'=>'By agreement','on_arrival'=>'On arrival','days_before_arrival'=>'Days before arrival']],
          'balance_due_days'=>['jet'=>'balance_due_days','label'=>'Balance due days before arrival','options'=>[]],
          'refund_mode'=>['jet'=>'refund_mode','label'=>'Advance refund policy','options'=>['manual_review'=>'Manual review','non_refundable'=>'Non-refundable','refundable'=>'Refund percentage / cutoff']],
          'refund_percent'=>['jet'=>'refund_percent','label'=>'Refund percentage of net advance received','options'=>[]],
          'refund_cutoff_days'=>['jet'=>'refund_cutoff_days','label'=>'Refund cutoff days before travel','options'=>[]]
        ];
    }
    public static function metaBox($post):void {
        $raw=(array)get_post_meta($post->ID,'_commerce_request_advance_policy',true);wp_nonce_field('mad4b_customer_policy','mad4b_customer_policy_nonce');
        echo '<p>Empty / Inherit uses company controls. Existing JetEngine policy text fields remain the narrative owner. '.esc_html('[mad4b_company_policy type="booking"]').'</p>';
        foreach(self::controlFields() as $key=>$spec){$value=$raw[$key]??'';echo '<p><label>'.esc_html($spec['label']).'<br>';
            if($spec['options']){echo '<select name="mad4b_customer_policy['.esc_attr($key).']"><option value="">Inherit</option>';foreach($spec['options'] as $v=>$label)echo '<option value="'.esc_attr($v).'" '.selected((string)$value,(string)$v,false).'>'.esc_html($label).'</option>';echo '</select>';}
            else echo '<input type="number" min="0" step="'.(str_ends_with($key,'days')?'1':'0.01').'" name="mad4b_customer_policy['.esc_attr($key).']" value="'.esc_attr($value).'">';echo '</label></p>';
        }
    }
    public static function savePost(int $id):void {
        if(!isset($_POST['mad4b_customer_policy_nonce'])||!wp_verify_nonce($_POST['mad4b_customer_policy_nonce'],'mad4b_customer_policy')||!\Commerce\Composition\LifecycleAccess::can('commerce_manage_policies')||!current_user_can('edit_post',$id)||wp_is_post_revision($id)||wp_is_post_autosave($id))return;
        $raw=array_filter((array)wp_unslash($_POST['mad4b_customer_policy']??[]),static fn($v)=>$v!==''&&$v!=='inherit');$v=self::validate($raw,true);if(is_wp_error($v))return;
        $old=(array)get_post_meta($id,'_commerce_request_advance_policy',true);foreach(array_keys(self::controlFields()) as $key)unset($old[$key]);update_post_meta($id,'_commerce_request_advance_policy',array_replace($old,$v));
    }
    public static function boot():void {
        add_action('init',[self::class,'registerPage'],90);
        add_action('add_meta_boxes',static function(){add_meta_box('mad4b-customer-policy','Commerce customer policy',[self::class,'metaBox'],['tours-and-activities','tour-rates'],'normal','default');});
        add_action('save_post',[self::class,'savePost'],20);
        add_action('wp_enqueue_scripts',static function(){wp_register_style('mad4b-customer-policy',false,[],null);wp_enqueue_style('mad4b-customer-policy');wp_add_inline_style('mad4b-customer-policy','.mad4b-policy-controls{display:grid;grid-template-columns:minmax(7em,1fr) 3fr;gap:.4em 1em;margin:0 0 1em}.mad4b-policy-controls dt{font-weight:600}.mad4b-policy-controls dd{margin:0}.mad4b-policy-notice{font-size:.9em}.mad4b-customer-policy{color:inherit;overflow-wrap:anywhere}@media(max-width:600px){.mad4b-policy-controls{grid-template-columns:1fr;gap:.25em}.mad4b-policy-controls dd{margin-bottom:.5em}}');});
        add_shortcode('mad4b_company_policy',static function($atts){$a=shortcode_atts(['type'=>'general','tour_id'=>0,'controls'=>'yes'],$atts);return self::render(sanitize_key($a['type']),(int)$a['tour_id'],$a['controls']!=='no');});
        add_shortcode('mad4b_policy_summary',static fn($atts)=>self::summary(\Commerce\Composition\RequestAdvancePayments::policy(self::source())));
        add_shortcode('mad4b_policy_value',static function($atts){$a=shortcode_atts(['field'=>'advance'],$atts);return esc_html(self::values(\Commerce\Composition\RequestAdvancePayments::policy(self::source()))[$a['field']]??'');});
        add_action('elementor/dynamic_tags/register','mad4b_customer_policy_register_dynamic_tag');
        add_action('rest_api_init',static function(){register_rest_route('commerce-composition/v1','/customer-policy/(?P<source_id>[0-9]+)',['methods'=>'GET','permission_callback'=>static fn()=>\Commerce\Composition\LifecycleAccess::can('commerce_manage_policies'),'callback'=>static fn($r)=>rest_ensure_response(self::snapshot((int)$r['source_id'],(int)($r['rate_id']??0)))]);});
    }
}
function mad4b_customer_policy_register_dynamic_tag($manager){
            if(!class_exists('Elementor'.chr(92).'Core'.chr(92).'DynamicTags'.chr(92).'Tag'))return;
            if(!class_exists('MAD4B_Company_Policy_Dynamic_Tag',false)){
                class MAD4B_Company_Policy_Dynamic_Tag extends \Elementor\Core\DynamicTags\Tag {
                    public function get_name(){return 'mad4b-company-policy';}public function get_title(){return 'MAD4B Company Policy (JetEngine Options)';}public function get_group(){return 'site';}
                    public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
                    protected function register_controls(){$this->add_control('policy_key',['label'=>'Policy category','type'=>\Elementor\Controls_Manager::SELECT,'options'=>MAD4B_Customer_Policy_Control::types(),'default'=>'booking']);}
                    public function render(){echo MAD4B_Customer_Policy_Control::render($this->get_settings('policy_key'));}
                }
            }$manager->register(new MAD4B_Company_Policy_Dynamic_Tag());
}
if(!function_exists('mad4b_company_policy_types')){function mad4b_company_policy_types(){return MAD4B_Customer_Policy_Control::types();}}
if(!function_exists('mad4b_company_policy_locales')){function mad4b_company_policy_locales(){return MAD4B_Customer_Policy_Control::locales();}}
if(!function_exists('mad4b_company_policy_html')){function mad4b_company_policy_html($type){return MAD4B_Customer_Policy_Control::render((string)$type);}}
MAD4B_Customer_Policy_Control::boot();
