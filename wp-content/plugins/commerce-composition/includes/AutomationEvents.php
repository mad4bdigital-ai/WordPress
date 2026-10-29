<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Conservative automatic linkage from documented source provenance.
 * No guessing by email, no marketing, no supplier booking or sale.
 * Runs on actual source events, including non-UI entrypoints.
 */
final class AutomationEvents
{
    public static function bootstrap():void
    {
        add_action('commerce_composition_contact_linked',
            [self::class,'onRelationLinked'],30,2);
        add_action('commerce_composition_request_submitted',
            [self::class,'onRequestSubmitted'],30,2);
        // Prepared for native FluentCRM feed integration. No source can
        // self-assert ownership from browser fields, email or a name.
        add_action('fluentform/submission_inserted',
            [self::class,'onFluentSubmission'],950,3);
        // Supplier plugins can emit an authoritative event for any configured
        // CPT/registry/order source without changing this engine.
        add_action('commerce_composition_source_record_verified',
            [self::class,'onVerifiedSource'],20,2);
    }
    public static function onFluentSubmission($submissionId,$formData,$form):void
    {
        if(!is_numeric($submissionId)||!is_object($form)||
            !is_numeric($form->id??null))return;
        $formId=(int)$form->id;
        $savedId=(int)$submissionId;
        // Server-managed switch and original actor only. No browser value
        // enables a business workflow or grants an actor's authority.
        $auto=apply_filters(
            'commerce_composition_enable_auto_form_request_intake',
            false,$formId,$savedId);
        if($auto===true){
            $actor=apply_filters(
                'commerce_composition_auto_form_request_actor_id',
                null,$formId,$savedId);
            if(is_int($actor)&&$actor>0&&user_can($actor,'manage_options')){
                WorkflowJobs::enqueue(
                    'create_request_from_saved_submission',
                    ['form_id'=>$formId,'submission_id'=>$savedId],
                    'verified-form-request:v1:'.$formId.':'.$savedId,
                    $actor,'event'
                );
            }
        }
        foreach(self::sourceKeys('fluent_form') as $key){
            $evidence=ContactBridge::sourceEvidence($key,(int)$submissionId);
            if(is_wp_error($evidence)||$evidence['source_type']!==(string)(int)$form->id)
                continue;
            self::onVerifiedSource([
                'source_key'=>$key,
                'source_id'=>(int)$submissionId,
                'domain'=>'fluent_form'
            ],['event'=>'stored_form_submission']);
        }
    }
    public static function onVerifiedSource($source,$context=[]):void
    {
        if(!is_array($source)||
            !is_string($source['source_key']??null)||
            !is_int($source['source_id']??null)||
            !is_string($source['domain']??null)||
            $source['source_id']<1)return;
        $keys=self::sourceKeys($source['domain']);
        if(!in_array($source['source_key'],$keys,true))return;
        $evidence=ContactBridge::sourceEvidence(
            $source['source_key'],$source['source_id']);
        if(is_wp_error($evidence)||$evidence['domain']!==$source['domain'])
            return;
        // A trusted CRM/provider extension must resolve an EXISTING contact.
        // This explicitly does not look up a contact by email or create one.
        $owner=apply_filters('commerce_composition_verified_event_contact',
            null,$source,$context);
        if(!is_array($owner)||
            !is_int($owner['contact_id']??null)||
            !is_int($owner['actor_id']??null)||
            !in_array($owner['role']??null,
                ['requester','traveler','representative','related_contact'],true))
            return;
        $intent='verified-source:v1:'.$source['source_key'].':'.
            $source['source_id'].':'.$owner['contact_id'].':'.$owner['role'];
        WorkflowJobs::enqueue('link_contact_record',[
            'contact_id'=>$owner['contact_id'],
            'source_key'=>$source['source_key'],
            'source_id'=>$source['source_id'],
            'role'=>$owner['role']
        ],$intent,$owner['actor_id'],'event');
    }

    private static function sourceKeys(string $domain):array
    {
        $keys=[];
        foreach(ContactBridge::sources() as $key=>$cfg)
            if(is_string($key)&&($cfg['domain']??null)===$domain)$keys[]=$key;
        return $keys;
    }
    private static function relatedForms(int $submissionId):array
    {
        global $wpdb;
        if($submissionId<1||!ContactBridge::ready())return [];
        $t=$wpdb->prefix.'commerce_contact_links';
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT contact_id,source_key,role,actor_id FROM $t
             WHERE domain='fluent_form' AND source_id=%d ORDER BY id ASC LIMIT 50",
            $submissionId
        ),ARRAY_A);
        // Check that every original form source key is STILL configured.
        $allowed=self::sourceKeys('fluent_form');
        return array_values(array_filter($rows,static function($row)use($allowed){
            return in_array($row['source_key'],$allowed,true)&&
                user_can((int)$row['actor_id'],'manage_options');
        }));
    }
    private static function submitLinks(int $requestId,array $sourceIds):array
    {
        if($requestId<1||!$sourceIds||!WorkflowJobs::ready())
            return ['enqueued'=>0,'reason'=>'insufficient_evidence'];
        $contactCandidates=[];
        $actors=[];
        foreach(array_unique(array_map('intval',$sourceIds)) as $submission){
            $perSubmission=[];
            foreach(self::relatedForms($submission) as $row) {
                // An original requester relation is required for EACH
                // source submission; a partial match could leak another
                // traveler's private request into the wrong CRM profile.
                if($row['role']!=='requester')continue;
                $perSubmission[(int)$row['contact_id']]=(int)$row['actor_id'];
            }
            if(count($perSubmission)!==1)
                return ['enqueued'=>0,'reason'=>'missing_or_ambiguous_requester'];
            $id=(int)array_key_first($perSubmission);
            $contactCandidates[$id]=true;
            $actors[$id]=$perSubmission[$id];
        }
        if(count($contactCandidates)!==1)
            return ['enqueued'=>0,'reason'=>'multiple_distinct_requesters'];
        $requestKeys=self::sourceKeys('request');
        if(!$requestKeys)return ['enqueued'=>0,'reason'=>'request_source_unconfigured'];
        $contactId=(int)array_key_first($contactCandidates);
        $actorId=$actors[$contactId]??0;
        $enqueued=0;
        foreach($requestKeys as $key){
            $intent='link-request:v1:'.$key.':'.$contactId.':'.$requestId;
            $job=WorkflowJobs::enqueue('link_contact_record',[
                'contact_id'=>$contactId,'source_key'=>$key,
                'source_id'=>$requestId,'role'=>'requester'
            ],$intent,$actorId,'event');
            if(!is_wp_error($job))$enqueued++;
        }
        return ['enqueued'=>$enqueued,'reason'=>$enqueued?'queued':'not_authorized'];
    }
    public static function onRequestSubmitted(array $result,array $sources):void
    {
        if(($result['status']??null)!=='request_submitted')return;
        $ids=[];
        foreach($sources as $source) {
            if(is_array($source)&&is_int($source['source_submission_id']??null))
                $ids[]=$source['source_submission_id'];
        }
        self::submitLinks((int)($result['request_id']??0),$ids);
    }
    public static function onRelationLinked(array $relation,array $evidence):void
    {
        if(($relation['domain']??null)!=='fluent_form'||
            ($relation['role']??null)!=='requester')return;
        global $wpdb;
        $t=$wpdb->prefix.'commerce_request_batches';
        $submission=(int)($relation['source_id']??0);
        if($submission<1||!RequestCheckout::ready())return;
        // Bounded discovery only, then exact JSON decoding & source-id equality.
        $like='%'.$wpdb->esc_like('"source_submission_id":'.$submission).'%';
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT id,snapshot_json FROM $t
             WHERE state='pending_review' AND snapshot_json LIKE %s ORDER BY id DESC LIMIT 100",
            $like
        ),ARRAY_A);
        foreach($rows as $row){
            $items=json_decode((string)$row['snapshot_json'],true);
            if(!is_array($items))continue;
            $ids=[];
            foreach($items as $item){
                if(is_array($item)&&isset($item['source_submission_id']))
                    $ids[]=(int)$item['source_submission_id'];
            }
            if(in_array($submission,$ids,true))
                self::submitLinks((int)$row['id'],$ids);
        }
    }
}
