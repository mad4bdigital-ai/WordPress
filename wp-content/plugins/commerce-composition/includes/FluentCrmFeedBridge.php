<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Native FluentCRM Feed completion -> original verified Form relation.
 *
 * NEVER calls createOrUpdate, sends mail, changes subscription statuses,
 * guesses the contact from the email, or claims a source based on posted ID.
 * FluentCRM's own feed is the identity provider, while Fluent Forms is the
 * original saved submission source.
 */
final class FluentCrmFeedBridge
{
    public static function bootstrap():void
    {
        add_action('fluent_crm/contact_added_by_fluentform',
            [self::class,'onFeed'],25,4);
        add_action('fluent_crm/contact_updated_by_fluentform',
            [self::class,'onFeed'],25,4);
    }
    public static function onFeed($subscriber,$entry,$form,$feed):void
    {
        $source=self::verify($subscriber,$entry,$form,$feed);
        if(is_wp_error($source)) {
            do_action('commerce_composition_crm_feed_link_skipped',
                $source->get_error_code());
            return;
        }
        $actor=apply_filters('commerce_composition_native_crm_feed_actor_id',
            null,$source['form_id'],$source['submission_id'],
            $source['contact_id']);
        if(!is_int($actor)||$actor<1||!user_can($actor,'manage_options'))
            return;
        // The only side effect: enqueue a unique contact-source reference.
        // No opt-in, marketing or contact creation is performed here.
        WorkflowJobs::enqueue('link_contact_record',[
            'contact_id'=>$source['contact_id'],
            'source_key'=>$source['source_key'],
            'source_id'=>$source['submission_id'],
            'role'=>'requester'
        ],
        'native-crm-feed:v1:'.$source['source_key'].':'.
        $source['submission_id'].':'.$source['contact_id'],
        $actor,'event');
    }
    public static function verify($subscriber,$entry,$form,$feed):array|\WP_Error
    {
        if(!is_object($subscriber)||!is_numeric($subscriber->id??null)||
            !is_object($entry)||!is_numeric($entry->id??null)||
            !is_object($form)||!is_numeric($form->id??null)||
            !is_array($feed)||!is_numeric($feed['id']??null))
            return new \WP_Error('native_crm_feed_identity_missing');
        $contactId=(int)$subscriber->id;
        $submissionId=(int)$entry->id;
        $formId=(int)$form->id;
        $feedId=(int)$feed['id'];
        if(min($contactId,$submissionId,$formId,$feedId)<1)
            return new \WP_Error('native_crm_feed_invalid_identity');
        if((int)($entry->form_id??0)!==$formId ||
            (isset($feed['form_id'])&&(int)$feed['form_id']!==$formId))
            return new \WP_Error('native_crm_feed_original_form_mismatch');
        $source=SubmissionProvenance::verify($submissionId,$formId);
        if(is_wp_error($source))return $source;
        global $wpdb;
        $t=$wpdb->prefix.'fluentform_form_meta';
        $meta=$wpdb->get_row($wpdb->prepare(
            "SELECT value FROM $t WHERE id=%d AND form_id=%d
             AND meta_key=%s LIMIT 1",
             $feedId,$formId,'fluentcrm_feeds'),ARRAY_A);
        if(!is_array($meta))
            return new \WP_Error('native_crm_feed_not_registered');
        $settings=json_decode((string)$meta['value'],true);
        if(!is_array($settings))$settings=maybe_unserialize($meta['value']);
        // Fluent Forms persists the integration settings FLAT inside
        // form_meta.value JSON. The notifier constructs feed.settings later.
        if(!is_array($settings)||
            !in_array($settings['enabled']??null,[true,1,'1','yes','true'],true)||
            ($feed['meta_key']??null)!=='fluentcrm_feeds')
            return new \WP_Error('native_crm_feed_not_active');
        $configured=ContactBridge::sources();
        $key=null;
        foreach($configured as $sourceKey=>$config) {
            if(($config['domain']??null)!=='fluent_form')continue;
            if(!in_array($formId,array_map('intval',
                (array)($config['form_ids']??[])),true))continue;
            if($key!==null)return new \WP_Error('native_crm_feed_source_ambiguous');
            $key=$sourceKey;
        }
        if($key===null)return new \WP_Error('native_crm_feed_source_unconfigured');
        // Query only the ID and state, never contact email or submitted answers.
        $contacts=$wpdb->prefix.'fc_subscribers';
        $c=$wpdb->get_row($wpdb->prepare(
            "SELECT id,status FROM $contacts WHERE id=%d",$contactId
        ),ARRAY_A);
        if(!is_array($c)||
            !in_array($c['status'],[
                'pending','subscribed','transactional','unsubscribed','bounced',
                'complained','spammed','suppressed'
            ],true))
            return new \WP_Error('native_crm_feed_contact_invalid');
        // A native feed result cannot silently reassign an original
        // requester's Form source to a different CRM contact.
        $relations=$wpdb->prefix.'commerce_contact_links';
        $prior=$wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT contact_id FROM $relations
             WHERE domain=%s AND source_key=%s AND source_id=%d
               AND role=%s LIMIT 3",
            'fluent_form',$key,$submissionId,'requester'
        ));
        if($prior && (count($prior)!==1||(int)$prior[0]!==$contactId))
            return new \WP_Error('native_crm_feed_requester_conflict');
        $submissions=$wpdb->prefix.'fluentform_submissions';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT status FROM $submissions WHERE id=%d AND form_id=%d",
            $submissionId,$formId
        ),ARRAY_A);
        if(!is_array($row)||
            in_array($row['status'],['trashed','spam','deleted'],true))
            return new \WP_Error('native_crm_feed_submission_inactive');
        return [
            'source_key'=>$key,'contact_id'=>$contactId,
            'form_id'=>$formId,'submission_id'=>$submissionId,
            'feed_id'=>$feedId,'contact_status'=>$c['status']
        ];
    }
}
