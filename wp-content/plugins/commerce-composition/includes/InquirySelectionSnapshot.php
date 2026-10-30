<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Immutable, privacy-minimal Product/Form inquiry evidence: selections and
 * contemporaneous catalogue price only, not a financial quote.
 */
final class InquirySelectionSnapshot
{
    private const OPTION='commerce_inquiry_snapshot_policy_v1';
    private static function table():string
    {
        global $wpdb;
        return $wpdb->prefix.'commerce_inquiry_snapshots';
    }
    public static function install():true|\WP_Error
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=self::table();
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL,
            submission_id bigint(20) unsigned NOT NULL,
            snapshot_json longtext NOT NULL,
            seal_sha256 char(64) NOT NULL,
            created_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY form_submission (form_id,submission_id)
        ) {$wpdb->get_charset_collate()};");
        return self::ready()?true:new \WP_Error('inquiry_snapshot_schema_unavailable');
    }
    public static function ready():bool
    {
        global $wpdb;
        $t=self::table();
        return $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t
        ))==='InnoDB';
    }
    private static function enabled(int $formId):bool
    {
        $c=get_option(self::OPTION,[]);
        return is_array($c)&&($c['enabled']??null)===true
            &&($c['site_url']??null)===home_url()
            &&is_array($c['form_ids']??null)
            &&count($c['form_ids'])<=30
            &&in_array($formId,array_map('intval',$c['form_ids']),true);
    }
    private static function seal(int $formId,int $submissionId,string $json):string
    {
        return hash_hmac('sha256','inquiry_selection_snapshot:v1:'.$formId.':'.
            $submissionId.':'.$json,wp_salt('secure_auth'));
    }
    private static function stored(int $formId,int $submissionId):array|\WP_Error
    {
        if(!self::ready())return new \WP_Error('inquiry_snapshot_schema_unavailable');
        global $wpdb;$t=self::table();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT snapshot_json,seal_sha256 FROM $t WHERE form_id=%d AND submission_id=%d",
            $formId,$submissionId),ARRAY_A);
        if(!is_array($row))return new \WP_Error('inquiry_snapshot_not_found');
        $json=(string)$row['snapshot_json'];
        if(!hash_equals((string)$row['seal_sha256'],self::seal($formId,$submissionId,$json)))
            return new \WP_Error('inquiry_snapshot_integrity_failed');
        $data=json_decode($json,true);
        return is_array($data)?$data:new \WP_Error('inquiry_snapshot_corrupt');
    }
    /** Administrator-only read; no customer contact fields in the snapshot. */
    public static function read(int $formId,int $submissionId):array|\WP_Error
    {
        if(!current_user_can('manage_options'))
            return new \WP_Error('inquiry_snapshot_access_denied');
        if(is_wp_error($v=SubmissionProvenance::verify($submissionId,$formId)))return $v;
        return self::stored($formId,$submissionId);
    }
    private static function selections(array $definition,array $answers):array
    {
        $output=[];$names=[];
        $typeSet=['select','input_radio','input_checkbox','cpt_selection',
            'select_country','input_date','input_number','tabular_grid'];
        $walk=function($nodes)use(&$walk,&$output,&$names,$answers,$typeSet):void{
            if(!is_array($nodes))return;
            foreach($nodes as $node){
                if(!is_array($node))continue;
                $type=$node['element']??null;
                $name=$node['attributes']['name']??null;
                if(is_string($type)&&in_array($type,$typeSet,true)
                    &&is_string($name)&&preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,119}$/D',$name)
                    &&!isset($names[$name])&&count($output)<60){
                    $names[$name]=true;
                    $raw=$answers[$name]??null;
                    if($raw!==null&&$raw!==''&&$raw!==[]){
                        $values=is_array($raw)?array_values($raw):[$raw];
                        if(count($values)>25)continue;
                        $choices=(array)($node['settings']['advanced_options']??[]);
                        $allowed=[];
                        foreach($choices as $choice){
                            if(!is_array($choice)||!is_scalar($choice['value']??null))continue;
                            $label=$choice['label']??'';
                            $allowed[(string)$choice['value']]=is_scalar($label)
                                ?mb_substr(sanitize_text_field((string)$label),0,150):'';
                        }
                        $items=[];$valid=true;
                        foreach($values as $value){
                            if(!is_scalar($value)||is_bool($value)){ $valid=false;break; }
                            $v=trim((string)$value);
                            if($v===''||mb_strlen($v)>170
                                ||preg_match('/[<>\\x00-\\x1F]/',$v)){ $valid=false;break; }
                            if($type==='input_number' && (!is_numeric($v) ||
                                (float)$v<0 || (float)$v>100000)){ $valid=false;break; }
                            if($type==='input_date' && !preg_match('/^[A-Za-z0-9 .,\\/-]{4,60}$/D',$v)){
                                $valid=false;break;
                            }
                            $items[]=[
                                'submitted_value'=>$v,
                                'label'=>array_key_exists($v,$allowed)?$allowed[$v]:null,
                                // Dynamic field option definitions can change. A
                                // captured browser value is not provider authority.
                                'option_in_published_form'=>array_key_exists($v,$allowed),
                            ];
                        }
                        if($valid&&$items) $output[]=[
                            'field_name'=>$name,
                            'field_type'=>$type,
                            'field_label'=>is_scalar($node['settings']['label']??null)
                                ?mb_substr(sanitize_text_field((string)$node['settings']['label']),0,110)
                                :$name,
                            'values'=>$items,
                            'verification'=>'submitted_selection_only',
                        ];
                    }
                }
                foreach(['fields','columns'] as $k)if(isset($node[$k]))$walk($node[$k]);
            }
        };
        $walk($definition['fields']??[]);
        return $output;
    }
    private static function productReference(int $formId,int $submissionId,array $answers):array
    {
        $ctx=SubmissionContext::read($formId,$submissionId);
        if(is_wp_error($ctx))return [
            'state'=>'unverified_product_context',
            'catalog_price'=>['status'=>'price_on_request',
                'final_quote'=>false,'payment_authorized'=>false],
        ];
        $parent=(int)$ctx['parent_id'];
        $rate=$ctx['rate_id']===null?null:(int)$ctx['rate_id'];
        $reference=['state'=>'verified_server_context',
            'parent_id'=>$parent,'rate_id'=>$rate,
            'product_title'=>mb_substr(sanitize_text_field(get_the_title($parent)),0,140)];
        if($rate===null)return $reference+[
            'catalog_price'=>['status'=>'price_on_request',
                'final_quote'=>false,'payment_authorized'=>false]];
        $identity=NativeOrderItemIdentityResolver::source($rate);
        if(is_wp_error($identity)||($identity['parent']??0)!==$parent)
            return ['state'=>'rate_parent_not_verified',
                'catalog_price'=>['status'=>'price_on_request',
                    'final_quote'=>false,'payment_authorized'=>false]];
        $product=wc_get_product($rate);
        $amount=$product instanceof \WC_Product?$product->get_price():'';
        $currency=get_woocommerce_currency();
        $reference['catalog_price']=[
            'status'=>'price_on_request','currency'=>$currency,
            'unit'=>'not_verified','includes_extra_selections'=>false,
            'customer_display_currency_verified'=>false,
            'taxes_and_fees'=>'not_finalized',
            'final_quote'=>false,'payment_authorized'=>false
        ];
        if(is_numeric($amount)&&is_finite((float)$amount)&&(float)$amount>=0
            &&is_string($currency)&&preg_match('/^[A-Z]{3}$/D',$currency)){
            $reference['catalog_price']['status']='catalog_rate_unit_reference_at_submission';
            $reference['catalog_price']['amount']=(float)$amount;
        }
        // A policy-derived verified composition binds dynamic CPT option IDs
        // to their real product/Rate, without turning those options into money.
        $intent=FluentSubmissionAdapter::prepareSubmissionIntent($submissionId,
            $answers,(object)['id'=>$formId]);
        $reference['policy_selections_verified']=is_array($intent)
            &&($intent['mode']??null)==='request_only'
            &&($intent['source_parent_id']??null)===$parent
            &&($intent['source_rate_id']??null)===$rate;
        if($reference['policy_selections_verified']){
            $reference['selection_digest']=$intent['selection_digest'];
            $reference['policy_revision']=$intent['policy_revision'];
        }
        return $reference;
    }
    /** At a native saved-submission hook, after SubmissionContext::capture(80). */
    public static function capture($submissionId,$formData,$form):void
    {
        if(!is_object($form)||!is_numeric($form->id??null)
            ||!is_numeric($submissionId))return;
        $f=(int)$form->id;$s=(int)$submissionId;
        if($s<1||!self::enabled($f))return;
        $result=self::save($f,$s);
        if(is_wp_error($result))
            do_action('commerce_composition_inquiry_snapshot_failed',
                $result->get_error_code(),$f,$s);
    }
    public static function save(int $formId,int $submissionId):array|\WP_Error
    {
        if(!self::enabled($formId)||!self::ready())
            return new \WP_Error('inquiry_snapshot_not_enabled');
        $proof=SubmissionProvenance::verify($submissionId,$formId);
        if(is_wp_error($proof))return $proof;
        global $wpdb;
        $submissions=$wpdb->prefix.'fluentform_submissions';
        $forms=$wpdb->prefix.'fluentform_forms';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT response,status,created_at FROM $submissions
            WHERE form_id=%d AND id=%d",$formId,$submissionId),ARRAY_A);
        if(!is_array($row)||in_array($row['status'],['spam','trashed','deleted'],true)
            ||strlen((string)$row['response'])>120000)
            return new \WP_Error('inquiry_snapshot_submission_unavailable');
        $original=$wpdb->get_row($wpdb->prepare(
            "SELECT form_fields,status FROM $forms WHERE id=%d",
            $formId),ARRAY_A);
        if(!is_array($original)||$original['status']!=='published')
            return new \WP_Error('inquiry_snapshot_form_unavailable');
        $definition=json_decode((string)$original['form_fields'],true);
        $answers=json_decode((string)$row['response'],true);
        if(!is_array($definition)||!is_array($answers)||count($answers)>250)
            return new \WP_Error('inquiry_snapshot_source_invalid');
        // No response, free-text notes, email, phone, name,
        // IP, consent flags or other customer identifiers are stored here.
        $data=[
            'schema'=>'commerce.inquiry_selection_snapshot.v1',
            'form_id'=>$formId,'submission_id'=>$submissionId,
            'submitted_at_site_time'=>(string)$row['created_at'],
            'source'=>'original_saved_fluent_submission',
            'selected_fields'=>self::selections($definition,$answers),
            'product_reference'=>self::productReference($formId,$submissionId,$answers),
            'all_options_finally_priced'=>false,
            'customer_view_estimate_certified'=>false,
            'booking_confirmed'=>false,'payment_authorized'=>false
        ];
        $json=wp_json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($json)||strlen($json)>24000)
            return new \WP_Error('inquiry_snapshot_too_large');
        $t=self::table();
        $wrote=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $t (form_id,submission_id,snapshot_json,seal_sha256,created_utc)
            VALUES (%d,%d,%s,%s,%s)",
            $formId,$submissionId,$json,
            self::seal($formId,$submissionId,$json),gmdate('Y-m-d H:i:s')
        ));
        if($wrote===false)return new \WP_Error('inquiry_snapshot_write_failed');
        $saved=self::stored($formId,$submissionId);
        if(is_wp_error($saved))return $saved;
        // A replay cannot change or silently overwrite earlier prices/options.
        if($saved!==$data)return new \WP_Error('inquiry_snapshot_immutable_conflict');
        return ['status'=>$wrote===1?'stored':'already_stored',
            'form_id'=>$formId,'submission_id'=>$submissionId,
            'selection_count'=>count($data['selected_fields']),
            'catalog_price_status'=>$data['product_reference']['catalog_price']['status']
                ??'price_on_request'];
    }
    public static function bootstrap():void
    {
        add_action('fluentform/submission_inserted',[self::class,'capture'],110,3);
        add_action('rest_api_init',static function(){
            register_rest_route('commerce-composition/v1',
                '/management/inquiry-snapshot/(?P<form_id>[1-9][0-9]*)/(?P<submission_id>[1-9][0-9]*)',[
                    'methods'=>\WP_REST_Server::READABLE,
                    'permission_callback'=>static fn()=>current_user_can('manage_options'),
                    'callback'=>static function(\WP_REST_Request $request){
                        $result=self::read((int)$request['form_id'],(int)$request['submission_id']);
                        if(is_wp_error($result))return $result;
                        $response=new \WP_REST_Response($result,200);
                        $response->header('Cache-Control','private,no-store');
                        return $response;
                    }
                ]);
        });
    }
}
