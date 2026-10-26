<?php
if (!defined('ABSPATH'))exit;

/**
 * Canonical content-QA receipt written to the EXISTING append-only MAD4B
 * audit chain and reread against the ORIGINAL owner source.
 *
 * A QA receipt is NEVER a publish, product price, checkout, translation-link,
 * SEO provider deployment, browser PASS or privileged-grant authorization.
 */
final class MAD4B_SCP_AI_Native_Owner_Review {
    const CONTRACT='mad4b.ai-native-owner-review.v1';
    private static function owners() {
        return array(
          'content_job'=>'MAD4B_SCP_Content_Jobs',
          'product_catalog'=>'MAD4B_SCP_Catalog_Object_Store',
          'seo_metadata'=>'MAD4B_SCP_G5_SEO_Provider_Families',
          'localization'=>'MAD4B_SCP_Translation_Bridge_Adapter',
          'design_template'=>'MAD4B_SCP_Browser_Acceptance_Core',
        );
    }
    public static function owner($domain) { $o=self::owners();return $o[$domain]??''; }

    public static function local_ability_allowed($ability) {
        if(!class_exists('MAD4B_SCP_AI_Only_Local_Activation')
            || !MAD4B_SCP_AI_Only_Local_Activation::site_bound()) return false;
        foreach(array_keys(self::owners()) as $d)
            if($ability==='mad4b/ai-'.str_replace('_','-',$d).'-review')return true;
        return false;
    }
    /** Non-executable, non-MCP entitlement markers. The ONLY actual write
     * path is the authenticated original local Agent REST review route.
     */
    public static function register_abilities() {
        if(!function_exists('wp_register_ability') || !class_exists('MAD4B_SCP_AI_Only_Local_Activation')
            || !MAD4B_SCP_AI_Only_Local_Activation::site_bound())return;
        foreach(array_keys(self::owners()) as $d) {
            $ability='mad4b/ai-'.str_replace('_','-',$d).'-review';
            if(function_exists('wp_has_ability')&&wp_has_ability($ability))continue;
            wp_register_ability($ability,array(
                'label'=>'AI source QA: '.$d,
                'description'=>'Exact local source-bound content QA entitlement; cannot publish or mutate provider.',
                'category'=>'mad4b-admin',
                'execute_callback'=>array(__CLASS__,'reject_direct_ability_execution'),
                'permission_callback'=>'__return_false',
                'input_schema'=>array('type'=>'object','additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'none'),
                    'annotations'=>array('readonly'=>false,'destructive'=>false,'idempotent'=>false))
            ));
        }
    }
    public static function reject_direct_ability_execution() {
        return new WP_Error('mad4b_ai_native_owner_rest_only',
            'Only authenticated original Local Agent REST review may issue QA receipts.');
    }
    public static function boot() {
        add_action('wp_abilities_api_init',array(__CLASS__,'register_abilities'),19);
    }

    private static function source($domain,$item) {
        if(!isset(self::owners()[$domain]) || !is_array($item)
           || !preg_match('/^[a-z0-9._:-]{1,128}$/D',(string)($item['item_id']??'')))
            return new WP_Error('mad4b_owner_review_unknown_source','Original owner source unavailable.');
        if($domain==='content_job') {
            if(!preg_match('/^job:([a-f0-9-]{36})$/D',$item['item_id'],$m))
                return new WP_Error('mad4b_owner_review_job_invalid','Canonical job ID required.');
            $original=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$m[1]));
            if(is_wp_error($original))return $original;
            $job=$original['job']??null;
            if(!is_array($job) || ($job['state']??'')!=='WAITING_REVIEW')
                return new WP_Error('mad4b_owner_review_job_not_waiting','Job must be in original WAITING_REVIEW state.');
            $rev=(int)($job['job_revision']??0);
            $title=(string)($job['subject']??'');
            $content=(string)($job['primary_keyword']??'');
            $meta=array('language'=>(string)($job['language']??''),
                'country'=>(string)($job['country']??''),
                'stage'=>(string)($job['stage']??''),
                'artifact'=>(string)($job['current_artifact_id']??''));
        } else {
            if(!preg_match('/^post:([1-9][0-9]{0,9})$/D',$item['item_id'],$m))
                return new WP_Error('mad4b_owner_review_post_invalid','Canonical WP source post required.');
            $id=(int)$m[1];$post=get_post($id);
            if(!($post instanceof WP_Post) || $post->post_status!=='draft')
                return new WP_Error('mad4b_owner_review_draft_required','Only saved WordPress drafts can enter content QA.');
            if($domain==='product_catalog') {
                if($post->post_type!=='product'||!function_exists('wc_get_product')||!wc_get_product($id))
                    return new WP_Error('mad4b_owner_review_product_invalid','Native WooCommerce draft product required.');
                $meta=array('catalog_content_only'=>true,'source_type'=>'woocommerce-product');
            } elseif($domain==='seo_metadata') {
                $catalog=MAD4B_SCP_G5_SEO_Provider_Families::catalog();
                if(empty($catalog['field_ids']) || $post->post_type!=='post')
                    return new WP_Error('mad4b_owner_review_seo_owner_unavailable','Native SEO owner catalog and a draft post required.');
                $meta=array('seo_title'=>(string)get_post_meta($id,'rank_math_title',true),
                    'seo_description'=>(string)get_post_meta($id,'rank_math_description',true),
                    'provider_native_apply_approved'=>false);
            } elseif($domain==='localization') {
                if(!in_array($post->post_type,array('post','page'),true))
                    return new WP_Error('mad4b_owner_review_translation_source_invalid','Translatable draft post/page required.');
                if(!class_exists('MAD4B_SCP_Translation_Bridge_Adapter'))
                    return new WP_Error('mad4b_owner_review_translation_owner_unavailable','Original translation provider absent.');
                $provider=new MAD4B_SCP_Translation_Bridge_Adapter();
                if(!$provider->is_available())
                    return new WP_Error('mad4b_owner_review_translation_provider_missing',
                        'WPML or Polylang original provider required.');
                $native=$provider->translation_get_post(array('post_id'=>$id,'provider'=>'auto'));
                if(is_wp_error($native))return $native;
                if(!is_array($native)||empty($native['language'])||empty($native['provider']))
                    return new WP_Error('mad4b_owner_review_translation_language_unverified',
                        'Canonical translation language missing.');
                $meta=array('language'=>(string)$native['language'],
                   'native_provider'=>(string)$native['provider'],
                   'translation_group'=>(string)($native['group']??''),
                   'wpml_link_approved'=>false);
            } else {
                if($post->post_type!=='elementor_library')
                    return new WP_Error('mad4b_owner_review_design_source_invalid','Original Elementor draft template required.');
                $native=get_post_meta($id,'_elementor_data',true);
                if(!is_string($native) || strlen($native)<2)
                    return new WP_Error('mad4b_owner_review_elementor_data_missing','Native Elementor document missing.');
                $meta=array('elementor_data_sha256'=>hash('sha256',$native),
                    'browser_runtime_acceptance'=>false);
            }
            $rev=max(1,(int)strtotime(($post->post_modified_gmt?:$post->post_date_gmt).' UTC'));
            $title=(string)$post->post_title;
            $content=(string)$post->post_content;
        }
        if($rev<1 || strlen($title)<2)
            return new WP_Error('mad4b_owner_review_empty_source','Original source is incomplete.');
        $text="Title: ".$title."\nContent: ".$content."\nOwner: ".self::owner($domain).
            "\nNative source metadata: ".wp_json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(strlen($text)<35 ||strlen($text)>60000)
            return new WP_Error('mad4b_owner_review_evidence_bound','Original owner evidence not within limits.');
        return array('item_id'=>$item['item_id'],'owner_revision'=>$rev,
            'content_hash'=>hash('sha256',$text),'title'=>substr($title,0,191),
            'normalized_text'=>$text);
    }
    public static function evidence($domain,$item) {return self::source($domain,$item);}
    private static function check_binding($domain,$item,$binding) {
        $live=self::source($domain,$item);
        if(is_wp_error($live))return $live;
        if(!is_array($binding) || !is_string($binding['content_hash']??null)
           || !preg_match('/^[a-f0-9]{64}$/D',$binding['content_hash'])
           || !hash_equals($live['content_hash'],$binding['content_hash'])
           ||($binding['owner_revision']??null)!==$live['owner_revision']
           || !hash_equals((string)($item['content_hash']??''),$live['content_hash'])
           ||($item['owner_revision']??null)!==$live['owner_revision'])
            return new WP_Error('mad4b_owner_review_cas_mismatch','Original source changed since review.');
        return $live;
    }
    private static function authorized_agent($domain) {
        if(PHP_SAPI==='cli' || !class_exists('MAD4B_SCP_AI_Only_Local_Activation')
            || !MAD4B_SCP_AI_Only_Local_Activation::site_bound())
            return new WP_Error('mad4b_owner_review_agent_http_required','Original delegated Agent REST authentication required.');
        $authenticated=MAD4B_SCP_AI_Only_Local_Activation::review_permission(null);
        if($authenticated!==true)return new WP_Error('mad4b_owner_review_agent_denied','Original Agent authorization missing.');
        $policy=MAD4B_SCP_Context_Authority::review_policy();
        if(($policy['mode']??'')!=='ai_only')
            return new WP_Error('mad4b_owner_review_policy_denied','Original review policy not AI only.');
        $agent=MAD4B_SCP_Agent_Registry::get_agent_by_public_id((string)$policy['ai_agent_public_id']);
        if(!is_array($agent)||($agent['status']??'')!=='enabled')
            return new WP_Error('mad4b_owner_review_agent_inactive','Original Agent is not enabled.');
        // Context's existing ability does not extend to unrelated modules.
        // Every source owner requires its own explicit original exact grant.
        $ability='mad4b/ai-'.str_replace('_','-',$domain).'-review';
        $grant=MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'],
            'mad4b-write',$ability,'core');
        if(!is_array($grant)||($grant['effect']??'')!=='allow'
            || !in_array(($grant['environment']??''),array(wp_get_environment_type(),'all'),true))
            return new WP_Error('mad4b_owner_review_exact_grant_required',
                'Owner-approved exact AI domain review grant is required.');
        return (string)$policy['ai_agent_public_id'];
    }
    public static function submit($domain,$item,$decision,$note,$binding) {
        if(!isset(self::owners()[$domain]) ||!in_array($decision,array('approve','needs_changes'),true)
           ||!is_string($note)||strlen($note)<35||strlen($note)>1600)
            return new WP_Error('mad4b_owner_review_verdict_invalid','Bounded content-only verdict required.');
        $agent=self::authorized_agent($domain);
        if(is_wp_error($agent))return $agent;
        $current=self::check_binding($domain,$item,$binding);
        if(is_wp_error($current))return $current;
        if(!class_exists('MAD4B_SCP_Audit') ||empty(MAD4B_SCP_Audit::storage_status()['ready']))
            return new WP_Error('mad4b_owner_review_audit_unavailable','Original append-only audit authority required.');
        $record=array('contract'=>self::CONTRACT,'domain'=>$domain,
           'owner_class'=>self::owner($domain),'item_id'=>$item['item_id'],
           'content_hash'=>$current['content_hash'],
           'owner_revision'=>$current['owner_revision'],
           'decision'=>$decision,'review_note'=>$note,
           'actor_type'=>'ai_agent','agent_public_id'=>$agent,
           'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
           'publication_authorized'=>false,'privileged_grants_issued'=>false);
        $entry=MAD4B_SCP_Audit::record('mad4b/ai-native-owner-review-'.$domain,$record,'ok');
        if(is_wp_error($entry))return $entry;
        if(!is_array($entry) ||!isset($entry['event_id'],$entry['entry_hash']))
            return new WP_Error('mad4b_owner_review_audit_receipt_missing','Durable event identity missing.');
        return array('contract'=>self::CONTRACT,'domain'=>$domain,
            'event_id'=>$entry['event_id'],'entry_hash'=>$entry['entry_hash'],
            'content_hash'=>$current['content_hash'],'owner_revision'=>$current['owner_revision'],
            'decision'=>$decision,'publication_authorized'=>false);
    }
    public static function verify($domain,$item,$receipt,$binding) {
        if(!isset(self::owners()[$domain]) ||!is_array($receipt)
          ||($receipt['domain']??'')!==$domain
          ||!in_array(($receipt['decision']??''),array('approve','needs_changes'),true)
          ||!preg_match('/^[a-f0-9-]{36}$/D',(string)($receipt['event_id']??''))
          ||!preg_match('/^[a-f0-9]{64}$/D',(string)($receipt['entry_hash']??'')))
            return false;
        $current=self::check_binding($domain,$item,$binding);
        if(is_wp_error($current))return false;
        // Independent durable readback: adapter's synthetic return is never
        // authoritative; every byte must be in the original audit chain.
        if(!class_exists('MAD4B_SCP_Audit')
           ||!method_exists('MAD4B_SCP_Audit','native_owner_review_events'))return false;
        $events=MAD4B_SCP_Audit::native_owner_review_events(array(
            'domain'=>$domain,'event_id'=>$receipt['event_id'],'item_id'=>$item['item_id']));
        if(is_wp_error($events) ||empty($events['chain_valid'])||empty($events['head_consistent']))return false;
        foreach(($events['events']??array()) as $e) {
            $s=$e['summary']??array();
            if(($e['event_id']??'')===$receipt['event_id']
               &&hash_equals((string)$e['entry_hash'],(string)$receipt['entry_hash'])
               &&($e['ability']??'')==='mad4b/ai-native-owner-review-'.$domain
               &&($s['owner_class']??'')===self::owner($domain)
               &&($s['actor_type']??'')==='ai_agent'
               &&($s['contract']??'')===self::CONTRACT
               &&($s['item_id']??'')===$item['item_id']
               &&($s['owner_revision']??null)===$current['owner_revision']
               &&hash_equals((string)($s['content_hash']??''),$current['content_hash'])
               &&($s['decision']??'')===$receipt['decision']
               &&($s['site_uuid']??'')===(string)MAD4B_SCP_Site_Profile::site_uuid()
               &&empty($s['publication_authorized'])&&empty($s['privileged_grants_issued']))
                return true;
        }
        return false;
    }
}
MAD4B_SCP_AI_Native_Owner_Review::boot();
