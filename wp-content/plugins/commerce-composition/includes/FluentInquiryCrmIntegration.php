<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Fluent Forms -> FluentCRM operational Lead integration, no marketing opt-in.
 * It never sends email, subscribes a contact, assigns marketing lists/tags,
 * invokes a gateway or makes ChatbotX remote requests.
 */
final class FluentInquiryCrmIntegration
{
    private const OPTION='commerce_operational_crm_intake_v1';

    public static function config():array
    {
        $c=get_option(self::OPTION,[]);
        return is_array($c)?$c:[];
    }

    public static function allowed(int $formId):bool
    {
        $c=self::config();
        if(($c['enabled']??null)!==true ||
           ($c['site_url']??null)!==home_url())return false;
        $ids=$c['form_ids']??null;
        if(!is_array($ids)||count($ids)>30)return false;
        return in_array($formId,array_map('intval',$ids),true);
    }

    private static function actor():int
    {
        $id=self::config()['actor_id']??0;
        return is_int($id)&&$id>0&&user_can($id,'manage_options')?$id:0;
    }

    private static function sourceKey(int $formId):string|\WP_Error
    {
        $result=[];
        foreach(ContactBridge::sources() as $key=>$info){
            if(($info['domain']??null)==='fluent_form'
                &&in_array($formId,array_map('intval',(array)($info['form_ids']??[])),true)){
                $result[]=$key;
            }
        }
        return count($result)===1?$result[0]:new \WP_Error('crm_form_source_not_unique');
    }

    public static function original(int $formId,int $submissionId):array|\WP_Error
    {
        if(!self::allowed($formId)||$submissionId<1||!self::actor())
            return new \WP_Error('crm_operational_intake_not_authorized');
        $source=SubmissionProvenance::verify($submissionId,$formId);
        if(is_wp_error($source))return $source;
        $key=self::sourceKey($formId);
        if(is_wp_error($key))return $key;
        global $wpdb;
        $forms=$wpdb->prefix.'fluentform_forms';
        $form=$wpdb->get_row($wpdb->prepare(
            "SELECT status,form_fields FROM $forms WHERE id=%d",$formId),ARRAY_A);
        if(!is_array($form)||$form['status']!=='published')
            return new \WP_Error('crm_original_form_not_published');
        $fields=json_decode((string)$form['form_fields'],true);
        $declared=false;
        $walk=function($nodes)use(&$walk,&$declared):void {
            if(!is_array($nodes))return;
            foreach($nodes as $node){
                if(!is_array($node))continue;
                if(($node['element']??null)==='input_email'
                    &&($node['attributes']['name']??null)==='email')
                    $declared=true;
                foreach(['fields','columns'] as $child)
                    if(isset($node[$child]))$walk($node[$child]);
            }
        };
        $walk($fields['fields']??[]);
        if(!$declared)return new \WP_Error('crm_form_has_no_declared_email');
        $submissions=$wpdb->prefix.'fluentform_submissions';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT form_id,status,response FROM $submissions WHERE id=%d",
            $submissionId),ARRAY_A);
        if(!is_array($row)||(int)$row['form_id']!==$formId
            ||in_array($row['status'],['spam','trashed','deleted'],true))
            return new \WP_Error('crm_submitted_source_invalid');
        if(strlen((string)$row['response'])>160000)
            return new \WP_Error('crm_submission_too_large');
        $answers=json_decode((string)$row['response'],true);
        if(!is_array($answers))return new \WP_Error('crm_answers_invalid');
        $email=is_string($answers['email']??null)
            ?strtolower(trim($answers['email'])):'';
        if(!is_email($email)||strlen($email)>190)
            return new \WP_Error('crm_saved_email_invalid');
        $first=$answers['first_name']??($answers['names']['first_name']??'');
        $last=$answers['last_name']??($answers['names']['last_name']??'');
        $first=is_scalar($first)?mb_substr(sanitize_text_field((string)$first),0,80):'';
        $last=is_scalar($last)?mb_substr(sanitize_text_field((string)$last),0,80):'';
        return ['form_id'=>$formId,'submission_id'=>$submissionId,
            'email'=>$email,'first_name'=>$first,'last_name'=>$last,
            'source_key'=>$key];
    }

    /**
     * Source-side idempotency: never change suppression or marketing consent.
     * An existing contact is linked as-is; only NEW email creates transactional Lead.
     */
    public static function sync(int $formId,int $submissionId):array|\WP_Error
    {
        if(!function_exists('FluentCrmApi')||!WorkflowJobs::ready()
           ||!ContactBridge::ready())return new \WP_Error('crm_provider_unavailable');
        $proof=self::original($formId,$submissionId);
        if(is_wp_error($proof))return $proof;
        global $wpdb;
        $links=$wpdb->prefix.'commerce_contact_links';
        $prior=$wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT contact_id FROM $links WHERE domain=%s
            AND source_key=%s AND source_id=%d AND role=%s LIMIT 3",
            'fluent_form',$proof['source_key'],$submissionId,'requester'));
        if(count($prior)>1)return new \WP_Error('crm_source_contact_conflict');
        $subscriber=\FluentCrm\App\Models\Subscriber::where('email',$proof['email'])->first();
        if(count($prior)===1){
            if(!$subscriber||(int)$subscriber->id!==(int)$prior[0])
                return new \WP_Error('crm_existing_source_email_conflict');
        }
        $created=false;
        if(!$subscriber){
            $record=['email'=>$proof['email'],'status'=>'transactional',
                'contact_type'=>'lead','source'=>'FluentForms'];
            if($proof['first_name']!=='')$record['first_name']=$proof['first_name'];
            if($proof['last_name']!=='')$record['last_name']=$proof['last_name'];
            $subscriber=FluentCrmApi('contacts')->createOrUpdate($record,false);
            if(!$subscriber)return new \WP_Error('crm_contact_creation_failed');
            $created=true;
        }
        // Never promote an existing unsubscribed, suppressed, bounced or
        // complained contact. No lists/tags or outbound welcome email.
        $contactId=(int)$subscriber->id;
        $job=WorkflowJobs::enqueue('link_contact_record',[
            'contact_id'=>$contactId,'source_key'=>$proof['source_key'],
            'source_id'=>$submissionId,'role'=>'requester'
        ],'crm-operational:v1:'.$proof['source_key'].':'.$submissionId,
          self::actor(),'event');
        if(is_wp_error($job))return $job;
        return ['state'=>$created?'created_transactional':'existing_unchanged',
            'contact_id'=>$contactId,'form_id'=>$formId,'submission_id'=>$submissionId,
            'job_state'=>$job['state']??'queued',
            'marketing_opt_in_granted'=>false,'outbound_message_sent'=>false,
            'chatbotx_contact_synced'=>false,'woo_order_created'=>false];
    }

    public static function onSubmission($submissionId,$formData,$form):void
    {
        $formId=is_object($form)&&is_numeric($form->id??null)?(int)$form->id:0;
        if(!$formId||!is_numeric($submissionId)||!self::allowed($formId))return;
        $result=self::sync($formId,(int)$submissionId);
        if(is_wp_error($result))
            do_action('commerce_composition_operational_crm_intake_failed',
                $result->get_error_code(),$formId,(int)$submissionId);
        else
            do_action('commerce_composition_operational_crm_intake_succeeded',
                $result);
    }

    public static function readiness():array
    {
        $c=self::config();
        $forms=array_values(array_map('intval',(array)($c['form_ids']??[])));
        $keys=[];
        foreach($forms as $id){$key=self::sourceKey($id);
            $keys[(string)$id]=is_wp_error($key)?$key->get_error_code():'configured';}
        $chat=class_exists('CMB_Business_Context')?
            \CMB_Business_Context::capabilities():[];
        return [
            'contract'=>'commerce.operational-fluent-crm.v1',
            'enabled'=>($c['enabled']??null)===true
                &&($c['site_url']??null)===home_url(),
            'forms'=>$keys,'fluentcrm_installed'=>function_exists('FluentCrmApi'),
            'contact_links_ready'=>ContactBridge::ready(),
            'task_queue_ready'=>WorkflowJobs::ready(),
            'new_contact_status'=>'transactional',
            'marketing_opt_in_inferred'=>false,
            'chatbotx_local_form_observation'=>
                $chat['fluent_forms']['observation_enabled']??false,
            'chatbotx_external_contact_sync'=>false
        ];
    }

    public static function bootstrap():void
    {
        add_action('fluentform/submission_inserted',[self::class,'onSubmission'],35,3);
    }
}
