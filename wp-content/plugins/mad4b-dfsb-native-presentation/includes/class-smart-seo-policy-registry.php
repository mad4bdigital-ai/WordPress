<?php
namespace MAD4B\DFSB\NativePresentation;

if (!defined('ABSPATH')) { exit; }

/**
 * Company-scoped, data-driven SEO policy. This never replaces MU service access,
 * Shared Product Core source registries, or native JetSmartFilters publication.
 */
final class SmartSeoPolicyRegistry {
    const OPTION = 'mad4b_smart_seo_policy_registry_v1';
    const CONTRACT = 'mad4b.smart-seo.policy-registry.v1';
    const LANGUAGES = array('en','ar','fr','de','it','es');

    public static function defaults(): array {
        return array(
            'contract'=>self::CONTRACT,
            'global'=>array(
                'max_filters'=>12, 'max_combinations'=>200,
                'require_result_evidence'=>true,
                'require_localized_copy'=>true,
                'min_result_count'=>1,
                'allow_auto_indexing'=>false,
                'require_mu_source_binding'=>true,
            ),
            'sources'=>array(),
        );
    }

    public static function stored(): array {
        $raw=get_option(self::OPTION,array());
        return is_array($raw) ? $raw : array();
    }

    public static function revision(): string {
        return hash('sha256', wp_json_encode(self::stored()));
    }

    public static function effective(string $provider,string $query): array {
        $defaults=self::defaults();
        $stored=self::stored();
        $global=array_merge($defaults['global'],is_array($stored['global']??null)?$stored['global']:array());
        $key=sanitize_key($provider).'/'.sanitize_key($query);
        $per=is_array($stored['sources'][$key]??null)?$stored['sources'][$key]:array();
        return array(
            'source'=>$key,
            'enabled'=>$per['enabled']??true,
            'global'=>$global,
            'max_filters'=>$per['max_filters']??$global['max_filters'],
            'max_combinations'=>$per['max_combinations']??$global['max_combinations'],
            'allowed_filter_ids'=>$per['allowed_filter_ids']??array(),
            'excluded_filter_ids'=>$per['excluded_filter_ids']??array(),
            'weight_overrides'=>$per['weight_overrides']??array(),
            'templates'=>$per['templates']??array(),
            'require_mu_source_binding'=>$global['require_mu_source_binding'],
            'require_localized_copy'=>$global['require_localized_copy'],
            'require_result_evidence'=>$global['require_result_evidence'],
            'min_result_count'=>$global['min_result_count'],
            'allow_auto_indexing'=>false, // never overwritten by site customizations
        );
    }

    public static function discovered(): array {
        $report=DynamicQualification::scan(false);
        $sources=array();
        foreach ($report['groups'] as $group) {
            $key=$group['provider'].'/'.$group['query_id'];
            $registry=(array)($group['mu_presentation_registry']??array());
            $company=(array)($group['company_policy']??array());
            $sources[$key]=array(
                'provider'=>$group['provider'],'query_id'=>$group['query_id'],
                'status'=>$group['status'],'route'=>$group['route'],
                'company'=>$company,'mu_binding'=>$registry,
                'filter_ids'=>array_values(array_unique(array_map('intval',
                    array_filter(array_column($group['filters'],'id'))))),
                'languages'=>$group['languages'],
                'effective_policy'=>self::effective($group['provider'],$group['query_id']),
            );
        }
        ksort($sources);
        return array(
            'contract'=>self::CONTRACT, 'site'=>home_url('/'),
            'blog_id'=>get_current_blog_id(), 'revision'=>self::revision(),
            'mode'=>'registry_read_only',
            'sources'=>$sources,
        );
    }

    /** Strict config validation; rejects unknown keys instead of silently widening policy. */
    public static function validate($input) {
        if (!is_array($input) || array_diff(array_keys($input),array('contract','global','sources')))
            return new \WP_Error('policy_invalid_root','Only global and sources keys are accepted',array('status'=>400));
        $known=self::defaults()['global'];
        $rawGlobal=$input['global']??array();
        if (!is_array($rawGlobal) || array_diff(array_keys($rawGlobal),array_keys($known)))
            return new \WP_Error('policy_invalid_global','Unknown global policy field',array('status'=>400));
        foreach ($rawGlobal as $name=>$value) {
            if (in_array($name,array('max_filters','max_combinations','min_result_count'),true)) {
                $cap=array('max_filters'=>12,'max_combinations'=>1000,'min_result_count'=>20)[$name];
                if (!is_int($value) || $value<1 || $value>$cap)
                    return new \WP_Error('policy_invalid_limit','Invalid policy integer limit',array('status'=>400));
            } elseif (!is_bool($value) || ($name==='allow_auto_indexing' && $value===true)) {
                return new \WP_Error('policy_unsafe_toggle','Auto indexing cannot be enabled by policy',array('status'=>400));
            }
        }
        $sources=$input['sources']??array();
        if (!is_array($sources) || count($sources)>150)
            return new \WP_Error('policy_source_limit','Invalid number of source overrides',array('status'=>400));
        $knownSource=array('enabled','max_filters','max_combinations','allowed_filter_ids',
            'excluded_filter_ids','weight_overrides','templates');
        $clean=array();
        foreach($sources as $key=>$override) {
            if (!is_string($key) || !preg_match('~^[a-z0-9_-]+/[a-z0-9_-]+$~D',$key)
                || !is_array($override) || array_diff(array_keys($override),$knownSource))
                return new \WP_Error('policy_invalid_source','Invalid source identifier or override',array('status'=>400));
            $record=array();
            foreach($override as $field=>$v) {
                if ($field==='enabled') {
                    if (!is_bool($v)) return new \WP_Error('policy_invalid_enabled','Expected boolean',array('status'=>400));
                    $record[$field]=$v;
                } elseif ($field==='max_filters'||$field==='max_combinations') {
                    $limit=$field==='max_filters'?12:1000;
                    if (!is_int($v) || $v<1 || $v>$limit)
                        return new \WP_Error('policy_invalid_source_limit','Source limit out of range',array('status'=>400));
                    $record[$field]=$v;
                } elseif (in_array($field,array('allowed_filter_ids','excluded_filter_ids'),true)) {
                    if (!is_array($v) || count($v)>100) return new \WP_Error('policy_invalid_filters','Invalid filter ID array',array('status'=>400));
                    foreach($v as $id) if(!is_int($id)||$id<1)
                        return new \WP_Error('policy_invalid_filter_id','Filter IDs must be positive integers',array('status'=>400));
                    $record[$field]=array_values(array_unique($v));
                } elseif ($field==='weight_overrides') {
                    if(!is_array($v) || count($v)>100)
                        return new \WP_Error('policy_invalid_weights','Invalid weight overrides',array('status'=>400));
                    foreach($v as $id=>$weight) {
                        if(!ctype_digit((string)$id)||!is_int($weight)||$weight<0||$weight>100)
                            return new \WP_Error('policy_invalid_weight','Weight must be 0–100',array('status'=>400));
                    }
                    $record[$field]=$v;
                } elseif ($field==='templates') {
                    if(!is_array($v) || array_diff(array_keys($v),self::LANGUAGES))
                        return new \WP_Error('policy_invalid_language','Unsupported locale override',array('status'=>400));
                    foreach($v as $lang=>$pair) {
                        if(!is_array($pair) || array_diff(array_keys($pair),array('title','description')))
                            return new \WP_Error('policy_invalid_template','Template must contain title/description only',array('status'=>400));
                        foreach($pair as $type=>$template) {
                            if(!is_string($template) || $template==='' || mb_strlen($template)>($type==='title'?180:320)
                                || preg_match('/<|>|[\r\n]/u',$template)
                                || preg_match_all('/\{[^{}]*\}/u',$template,$matches)
                                && array_diff($matches[0],array('{primary}','{secondary}','{collection}','{site}')))
                                return new \WP_Error('policy_invalid_template','Unsafe or unknown template placeholders',array('status'=>400));
                        }
                    }
                    $record[$field]=$v;
                }
            }
            $clean[$key]=$record;
        }
        return array('contract'=>self::CONTRACT,
            'global'=>array_merge($known,$rawGlobal), 'sources'=>$clean);
    }

    public static function formatTemplate(string $source,array $labels,string $fallback): string {
        $tokens=array(
            '{primary}'=>$labels['primary']??'',
            '{secondary}'=>$labels['secondary']??'',
            '{collection}'=>$labels['collection']??'',
            '{site}'=>$labels['site']??'',
        );
        return trim(strtr($source!==''?$source:$fallback,$tokens));
    }
}
