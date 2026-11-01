<?php
if (!defined('ABSPATH')) { exit; }
/** A policy is a review rule, not an Authorization grant. */
final class MAD4B_SCP_Unified_Policy {
    const CONTRACT = 'mad4b.unified-policy.v1';
    const OPTION = 'mad4b_unified_review_policies_v1';
    const LOCK = 'mad4b_unified_review_policies_lock_v1';
    const MODULES = ['brand_context','content_job','product_catalog','seo_metadata','localization','design_template','booking','pricing','provider_configuration','plugin_lifecycle','deployment','developer','breakglass','media','generic'];
    const CONTENT_QA = ['brand_context','content_job','product_catalog','seo_metadata','localization','design_template'];
    const MODES = ['human_only','human_and_ai','ai_only'];
    const RULES = ['human_final','ai_final','all_required','ai_then_human','risk_adaptive'];
    const PROTECTED = ['booking','pricing','provider_configuration','plugin_lifecycle','deployment','developer','breakglass'];

    public static function registry() {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw) || ($raw['contract'] ?? '') !== self::CONTRACT || !is_array($raw['rules'] ?? null))
            return ['contract'=>self::CONTRACT,'revision'=>0,'rules'=>[]];
        // Read-time compatibility only: do not mutate a saved policy without
        // the owner's original policy-change action. Any retired human-first
        // or either-first policy is projected as the stricter AI-first hybrid.
        foreach ($raw['rules'] as &$rule) {
            if (is_array($rule) && ($rule['mode']??'')==='human_and_ai'
                && in_array(($rule['decision_rule']??''),['human_then_ai','either_authorized'],true))
                $rule['decision_rule']='ai_then_human';
        }
        unset($rule);
        return $raw;
    }
    public static function environment() {
        return class_exists('MAD4B_SCP_Site_Profile') ? MAD4B_SCP_Site_Profile::current_environment() : wp_get_environment_type();
    }
    public static function original_mode() {
        if (!class_exists('MAD4B_SCP_Context_Authority')) return 'human_only';
        $p=MAD4B_SCP_Context_Authority::review_policy();
        return in_array($p['mode']??'', self::MODES, true) ? $p['mode'] : 'human_only';
    }
    public static function validate_rule($raw) {
        if (!is_array($raw) || array_diff(array_keys($raw),['environment','module','operation','resource','mode','decision_rule','min_confidence','ttl_seconds'])) {
            return new WP_Error('mad4b_policy_unknown_fields','Unknown review policy fields.');
        }
        $env=(string)($raw['environment']??'*');
        $module=(string)($raw['module']??'*');
        $operation=(string)($raw['operation']??'*');
        $resource=(string)($raw['resource']??'*');
        $mode=(string)($raw['mode']??'');
        $decision=(string)($raw['decision_rule']??'');
        if (!in_array($env, ['*','local','development','staging','production'], true)
            || !in_array($module,array_merge(['*'],self::MODULES),true)
            || !preg_match('/^(?:\*|[a-z0-9_-]{3,80})$/D', $operation)
            || !preg_match('/^(?:\*|[a-z0-9._:-]{1,128})$/D', $resource)
            || !in_array($mode,self::MODES,true) || !in_array($decision,self::RULES,true)) {
            return new WP_Error('mad4b_policy_invalid_rule','Invalid mode, scope, or decision rule.');
        }
        if (($mode==='human_only' && $decision!=='human_final')
            || ($mode==='ai_only' && $decision!=='ai_final')
            || ($mode==='human_and_ai' && in_array($decision,['human_final','ai_final'],true))) {
            return new WP_Error('mad4b_policy_conflicting_rule','Mode and finality conflict.');
        }
        if ((isset($raw['min_confidence'])&&!is_numeric($raw['min_confidence']))
            ||(isset($raw['ttl_seconds'])&&!is_numeric($raw['ttl_seconds'])))
            return new WP_Error('mad4b_policy_limits_format','Numeric confidence and TTL required.');
        $confidence=(float)($raw['min_confidence']??0.90);
        $ttl=(int)($raw['ttl_seconds']??3600);
        if ($confidence<0 || $confidence>1 || $ttl<300 || $ttl>86400) {
            return new WP_Error('mad4b_policy_invalid_bounds','Invalid confidence or TTL.');
        }
        return ['environment'=>$env,'module'=>$module,'operation'=>$operation,'resource'=>$resource,'mode'=>$mode,'decision'=>$decision];
    }
    private static function rank($rule,$selector) {
        $result=0;
        foreach (['environment'=>1,'module'=>2,'operation'=>4,'resource'=>8] as $field=>$score) {
            $v=(string)($rule[$field]??'*');
            if ($v!=='*' && $v!==$selector[$field]) return -1;
            if ($v!=='*')$result+=$score;
        }
        return $result;
    }
    public static function resolve($selector) {
        if (!is_array($selector))return new WP_Error('mad4b_policy_selector_invalid','Selector must be an object.');
        $module=(string)($selector['module']??'generic');
        $operation=(string)($selector['operation']??'content_review');
        $resource=(string)($selector['resource']??'');
        if (!in_array($module,self::MODULES,true) || !preg_match('/^[a-z0-9_-]{3,80}$/D',$operation)
            || !preg_match('/^[a-z0-9._:-]{0,128}$/D',$resource)) {
            return new WP_Error('mad4b_policy_unknown_target','Unknown operation or resource.');
        }
        $q=['environment'=>self::environment(),'module'=>$module,'operation'=>$operation,'resource'=>$resource];
        $registry=self::registry();
        $original=self::original_mode();
        $qa=in_array($module,self::CONTENT_QA,true) && $operation==='content_review';
        $mode=$qa?$original:'human_and_ai';
        $decision=$mode==='ai_only'?'ai_final':($mode==='human_only'?'human_final':'ai_then_human');
        $match=null;$best=-1;
        foreach ($registry['rules'] as $rule) {
            $score=self::rank($rule,$q);
            if ($score>$best) { $best=$score;$match=$rule; }
        }
        if ($match) {$mode=$match['mode'];$decision=$match['decision_rule'];}
        // Compatibility with previously saved experimental policies: both
        // human-first and either-first must now require AI -> Human. Reject
        // new writes with those retired rules in validate_rule().
        if ($mode==='human_and_ai' && in_array($decision,['human_then_ai','either_authorized'],true))
            $decision='ai_then_human';
        // Never weaken a high-risk original gate. Brand policy only applies to quality reviews.
        $protected=!$qa || in_array($module,self::PROTECTED,true);
        if ($protected && in_array($decision,['ai_final','either_authorized'],true)) {
            $mode='human_and_ai';$decision='ai_then_human';
        }
        if (in_array($module,['developer','breakglass','deployment'],true)) {
            $mode='human_only';$decision='human_final';
        }
        if ($qa && $original==='human_only') { $mode='human_only';$decision='human_final'; }
        if ($qa && $original==='human_and_ai' && $mode==='ai_only') { $mode='human_and_ai';$decision='ai_then_human'; }
        $result=['contract'=>self::CONTRACT,'selector'=>$q,'mode'=>$mode,'decision_rule'=>$decision,
          'min_confidence'=>(float)($match['min_confidence']??0.90),
          'ttl_seconds'=>(int)($match['ttl_seconds']??3600),
          'policy_revision'=>(int)$registry['revision'],
          'source'=>$match?'configured_registry':'original_safe_default',
          'protected_operation'=>$protected,'original_owner_required'=>true,
          'publication_authorized'=>false,'mutation_authorized'=>false];
        $result['policy_sha256']=hash('sha256',wp_json_encode($result,JSON_UNESCAPED_SLASHES));
        return $result;
    }
    public static function save($rules,$expected_revision,$nonce) {
        if (self::environment()!=='local' || PHP_SAPI==='cli' || !current_user_can('manage_options')
            || !MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id())
            || !wp_verify_nonce((string)$nonce,'mad4b_unified_policy_update')) {
            return new WP_Error('mad4b_policy_owner_required','Interactive enrolled owner required to change policy.');
        }
        if(!add_option(self::LOCK,['at'=>time()],'',false))
            return new WP_Error('mad4b_policy_write_locked','Concurrent policy write detected.');
        try {
        $old=self::registry();
        if ((int)$expected_revision !== (int)$old['revision'])return new WP_Error('mad4b_policy_revision_stale','Policy revision changed.');
        if (!is_array($rules) || count($rules)>75)return new WP_Error('mad4b_policy_too_many_rules','Policy rules bounded to 75.');
        $valid=[];$keys=[];
        foreach($rules as $raw){
            $rule=self::validate_rule($raw); if (is_wp_error($rule)) return $rule;
            $rule['decision_rule']=$rule['decision'];unset($rule['decision']);
            $rule['min_confidence']=(float)($raw['min_confidence']??0.90);
            $rule['ttl_seconds']=(int)($raw['ttl_seconds']??3600);
            $key=implode('|',[$rule['environment'],$rule['module'],$rule['operation'],$rule['resource']]);
            if (isset($keys[$key]))return new WP_Error('mad4b_policy_duplicate_scope','Duplicate policy scope.');
            $keys[$key]=true;$valid[]=$rule;
        }
        $state=['contract'=>self::CONTRACT,'revision'=>$old['revision']+1,'rules'=>$valid,
           'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid()];
        $audit=MAD4B_SCP_Audit::record('mad4b/unified-policy-change-intent',[
           'revision'=>$state['revision'],'rules_sha256'=>hash('sha256',wp_json_encode($valid)),
           'owner_id'=>get_current_user_id(),'publishing_authorized'=>false],'ok');
        if (is_wp_error($audit))return $audit;
        if (!update_option(self::OPTION,$state,false) && self::registry()!==$state)
            return new WP_Error('mad4b_policy_save_failed','Policy state persistence failed.');
        $confirmed=MAD4B_SCP_Audit::record('mad4b/unified-policy-change-committed',[
           'revision'=>$state['revision'],'rules_sha256'=>hash('sha256',wp_json_encode($valid)),
           'owner_id'=>get_current_user_id(),'publication_authorized'=>false],'ok');
        if(is_wp_error($confirmed)){
            update_option(self::OPTION,$old,false);
            return new WP_Error('mad4b_policy_audit_failed_rollback','Final Audit receipt failed; policy rolled back.');
        }
        return $state;
        }finally{delete_option(self::LOCK);}
    }
}
