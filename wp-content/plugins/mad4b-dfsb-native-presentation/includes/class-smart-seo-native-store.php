<?php
namespace MAD4B\DFSB\NativePresentation;

if (!defined('ABSPATH')) { exit; }

/**
 * Optimistic, single-statement CAS for existing JetSmartFilters option.
 * Refuses when native SEO is enabled or another administrator changed settings.
 */
final class SmartSeoNativeStore {
    /** Atomic, byte-exact option CAS. Returns false on concurrent mutation. */
    public static function compareAndSwapOption(string $key,string $oldRaw,string $newRaw): bool {
        global $wpdb;
        $n=$wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s",
            $newRaw,$key,$oldRaw));
        if ($n!==1) return false;
        wp_cache_delete($key,'options');
        wp_cache_delete('alloptions','options');
        return true;
    }

    public static function appendDisabled(array $rule) {
        global $wpdb;
        $key='jet-smart-filters-settings';
        $oldRaw=$wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));
        if (!is_string($oldRaw))
            return new \WP_Error('native_settings_missing','Native settings absent',array('status'=>409));
        $previous=maybe_unserialize($oldRaw);
        if (!is_array($previous) || filter_var($previous['use_seo_sitemap']??false,FILTER_VALIDATE_BOOLEAN))
            return new \WP_Error('native_seo_already_enabled','Native SEO must remain off',array('status'=>409));
        $rules=$previous['seo_sitemap_rules']??array();
        if (!is_array($rules))
            return new \WP_Error('native_rule_schema_invalid','Native rules not an array',array('status'=>409));
        foreach ($rules as $old) {
            if (!is_array($old)) return new \WP_Error('native_rule_schema_invalid','Invalid existing rule',array('status'=>409));
            if (($old['url']??'')===($rule['url']??'')
                && ($old['provider']??'')===($rule['provider']??'')
                && ($old['query_id']??'')===($rule['query_id']??''))
                return new \WP_Error('native_rule_conflict','Rule for this provider/query already present',array('status'=>409));
        }
        if (count($rules)>=50)
            return new \WP_Error('native_rule_limit','Native rule staging limit 50',array('status'=>409));
        $rules[]=$rule;
        $next=$previous;
        $next['seo_sitemap_rules']=$rules;
        $nextRaw=maybe_serialize($next);
        // Byte-exact compare via BINARY. A concurrent settings update makes this fail.
        if (!self::compareAndSwapOption($key,$oldRaw,$nextRaw))
            return new \WP_Error('native_settings_conflict',
                'Native settings changed; retry after reviewing current rules',array('status'=>409));
        if (function_exists('jet_smart_filters')) {
            $jsf=jet_smart_filters();
            $jsf->settings->all_settings=null;
        }
        $readback=get_option($key,array());
        if (!is_array($readback) || ($readback['seo_sitemap_rules']??null)!==$rules
            || filter_var($readback['use_seo_sitemap']??false,FILTER_VALIDATE_BOOLEAN)) {
            // Do not blind rollback: another admin may already have written new data.
            return new \WP_Error('native_readback_conflict',
                'Native write completed but readback differs; requires inspection',array('status'=>409));
        }
        return array('rule_count'=>count($rules),'native_seo_enabled'=>false,
            'prior_rules_preserved'=>count($previous['seo_sitemap_rules']??array()));
    }
    /**
     * Exact, disabled-only replacement of one native rule, preserving all other
     * rules and rejecting source or concurrent administrator changes.
     */
    public static function reconcileDisabled(array $before,array $after) {
        global $wpdb;
        $key='jet-smart-filters-settings';
        $raw=$wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));
        if (!is_string($raw)) return new \WP_Error(
            'native_settings_missing','JetSmartFilters settings missing',array('status'=>409));
        $settings=maybe_unserialize($raw);
        if (!is_array($settings)
            || filter_var($settings['use_seo_sitemap']??false,FILTER_VALIDATE_BOOLEAN))
            return new \WP_Error('native_seo_enabled',
                'Reconciliation requires native SEO disabled',array('status'=>409));
        $rules=$settings['seo_sitemap_rules']??array();
        if (!is_array($rules)) return new \WP_Error(
            'native_rule_schema_invalid','Rules are not an array',array('status'=>409));
        $keys=array('url','provider','query_id');
        foreach ($keys as $name) {
            if (!isset($before[$name],$after[$name])
                || $before[$name]!==$after[$name])
                return new \WP_Error('rule_identity_changed',
                    'Reconciliation cannot change page/provider/query identity',array('status'=>400));
        }
        $found=array();
        foreach ($rules as $i=>$item) {
            if (!is_array($item)) continue;
            if (($item['url']??'')===$before['url']
                && ($item['provider']??'')===$before['provider']
                && ($item['query_id']??'')===$before['query_id']) $found[]=$i;
        }
        if (count($found)!==1) return new \WP_Error(
            'native_rule_not_unique','Exact original rule not unique or missing',array('status'=>409));
        $i=$found[0];
        if ($rules[$i]!==$before) return new \WP_Error(
            'native_rule_changed','Existing rule edited since draft was staged',array('status'=>409));
        if ($before===$after) return array('status'=>'unchanged','rule_count'=>count($rules));
        $rules[$i]=$after;
        $next=$settings; $next['seo_sitemap_rules']=$rules;
        if (!self::compareAndSwapOption($key,$raw,maybe_serialize($next)))
            return new \WP_Error('native_settings_conflict',
                'JetSmartFilters changed concurrently; refresh before retry',array('status'=>409));
        if (function_exists('jet_smart_filters'))
            jet_smart_filters()->settings->all_settings=null;
        $readback=get_option($key,array());
        if (!is_array($readback) || ($readback['seo_sitemap_rules']??null)!==$rules
            || filter_var($readback['use_seo_sitemap']??false,FILTER_VALIDATE_BOOLEAN))
            return new \WP_Error('native_readback_conflict',
                'Readback mismatch: manual inspection required',array('status'=>409));
        return array('status'=>'reconciled_disabled','rule_count'=>count($rules),
            'unrelated_rules_preserved'=>count($rules)-1,
            'seo_enabled'=>false);
    }

}
