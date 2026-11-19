<?php
/**
 * MAD4B Strategy Schema Bridge v1
 *
 * Discovers the existing JetEngine/WPML taxonomy, glossary, CPT field and
 * post Meta Box schema. Applies targeting in memory to published tour IDs.
 * It never publishes campaigns, edits prices or registers new pricing types.
 */
defined('ABSPATH') || exit;

final class MAD4B_JetEngine_Strategy_Schema_Bridge_V1 {
    private const TYPE = 'tours-and-activities';
    private const MAX_RULES = 12;
    private const MAX_VALUES = 24;
    private const MAX_IDS = 6000;

    private static function valid_key(string $name): bool {
        return (bool)preg_match('/^[a-z][a-z0-9_-]{1,79}$/D', $name)
            && !preg_match('/(password|secret|token|api_?key|auth|private_key|personal|email|phone|mobile|billing|address|lat|lng|geo|form_|cookie|session|checkout|credit|card_number|passport|customer|webhook|raw_html|html|shortcode|url|file|caption|description)/i', $name);
    }

    /** Existing JetEngine glossary records, not a parallel configuration table. */
    public static function glossaries(): array {
        global $wpdb;
        $table = $wpdb->prefix.'jet_post_types';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,labels,meta_fields FROM {$table} WHERE status=%s ORDER BY id DESC LIMIT 80",
            'glossary'
        ), ARRAY_A);
        $out = [];
        foreach ((array)$rows as $row) {
            $id = absint($row['id'] ?? 0);
            $labels = maybe_unserialize($row['labels'] ?? '');
            $name = sanitize_text_field((string)($labels['name'] ?? ''));
            if (!$id || $name === '') continue;
            $choices = [];
            foreach (array_slice((array)maybe_unserialize($row['meta_fields'] ?? ''),0,100) as $entry) {
                if (!is_array($entry)) continue;
                $value = (string)($entry['value'] ?? '');
                $label = sanitize_text_field((string)($entry['label'] ?? ''));
                if ($value === '' || $label === '' || strlen($value) > 60) continue;
                $choices[$value] = $label;
            }
            $out[$id] = [
                'id'=>$id, 'label'=>$name,
                'options'=>$choices, 'linked_fields'=>[], 'filterable'=>false,
            ];
        }
        return $out;
    }

    /**
     * Get only JetEngine Meta Boxes explicitly assigned to Tours.
     * Other meta boxes (users, offers, rate periods) must not enter tour
     * targeting merely because they exist on the same WordPress instance.
     */
    public static function tour_meta_boxes(): array {
        $boxes = get_option('jet_engine_meta_boxes',[]);
        $out = [];
        if (!is_array($boxes)) return [];
        foreach (array_slice($boxes,0,60,true) as $id=>$box) {
            if (!is_array($box)) continue;
            $postTypes = $box['args']['allowed_post_type'] ?? $box['allowed_post_type'] ?? [];
            if (!is_array($postTypes) || !in_array(self::TYPE,$postTypes,true)) continue;
            $label = sanitize_text_field((string)($box['args']['name'] ?? $box['name'] ?? 'Tour metadata'));
            foreach (array_slice((array)($box['meta_fields'] ?? []),0,90) as $field) {
                if (!is_array($field)) continue;
                $key = (string)($field['name'] ?? '');
                $type = sanitize_key((string)($field['type'] ?? ''));
                if (!self::valid_key($key)
                    || !in_array($type,['select','radio','switcher','checkbox','number'],true)) continue;
                $out[$key] = [
                    'key'=>$key,
                    'label'=>sanitize_text_field((string)($field['title'] ?? $key)),
                    'type'=>$type,
                    'meta_box'=>$label,
                    'options_source'=>sanitize_key((string)($field['options_source'] ?? '')),
                    'glossary_id'=>absint($field['glossary_id'] ?? 0),
                    'options'=>is_array($field['options'] ?? null) ? $field['options'] : [],
                ];
            }
        }
        return array_slice($out,0,90,true);
    }

    public static function catalog(array $languages=[], bool $respectSelection=true): array {
        $source='MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
        $languages=$source::selected_languages($languages);
        $dimensions=[];
        $glossaries=self::glossaries();
        foreach ($source::taxonomy_facets($languages) as $facet) {
            $options=[];
            foreach (array_slice((array)($facet['terms'] ?? []),0,100) as $term) {
                $id=(string)($term['id'] ?? '');
                if ($id==='') continue;
                $options[]=['id'=>$id,'label'=>sanitize_text_field((string)$term['name']),
                    'count'=>(int)($term['count'] ?? 0)];
            }
            if (!$options)continue;
            $dimensions[]=[
                'id'=>'taxonomy:'.$facet['id'],'source'=>'taxonomy',
                'key'=>$facet['id'],'label'=>$facet['label'],
                'kind'=>'multi','options'=>$options,
                'group'=>self::category($facet['id']),
                'meta_box'=>null,'glossary_id'=>null,
            ];
        }
        $meta=$source::meta_fields();
        foreach ($source::meta_facets($languages) as $facet) {
            $key=(string)($facet['key'] ?? '');
            if (!isset($meta[$key]))continue;
            $definition=$meta[$key];
            $options=[];
            foreach (array_slice((array)($facet['terms'] ?? []),0,100) as $option) {
                $options[]=[
                    'id'=>(string)$option['id'],
                    'label'=>sanitize_text_field((string)$option['name']),
                    'count'=>(int)($option['count'] ?? 0)
                ];
            }
            $glossary=absint($definition['glossary_id'] ?? 0);
            if ($glossary && isset($glossaries[$glossary])) {
                $glossaries[$glossary]['linked_fields'][]=$key;
                $glossaries[$glossary]['filterable']=true;
            }
            $entry=[
                'id'=>'meta:'.$key,'source'=>'meta','key'=>$key,
                'label'=>$facet['label'],'kind'=>$facet['kind'],
                'options'=>$options,
                'group'=>self::category($key),
                'glossary_id'=>$glossary?:null, 'meta_box'=>null,
            ];
            if ($facet['kind']==='range') {
                $entry['min']=(float)$facet['min'];
                $entry['max']=(float)$facet['max'];
            }
            if ($options || $facet['kind']==='range')$dimensions[]=$entry;
        }
        $boxes=self::tour_meta_boxes();
        $observed=self::observed_meta_box_values(array_keys($boxes),$languages);
        foreach ($boxes as $key=>$definition) {
            if(isset($meta[$key]))continue; // Native CPT definition owns the key.
            $options=$observed[$key]??[];
            if(!$options)continue;
            // JetEngine manual select options store key/value pairs. Use the
            // visible configured label, never show its raw key as the caption.
            $manual=[];
            foreach ((array)$definition['options'] as $keyOrIndex=>$option) {
                if(is_array($option)){
                    $id=(string)($option['key']??$option['value']??$keyOrIndex);
                    $label=(string)($option['label']??$option['value']??'');
                }else{
                    $id=(string)$keyOrIndex;
                    $label=is_scalar($option)?(string)$option:'';
                }
                if($id!==''&&trim($label)!=='')
                    $manual[$id]=sanitize_text_field($label);
            }
            foreach($options as &$choice) {
                if(isset($manual[$choice['id']]))$choice['label']=$manual[$choice['id']];
            }
            unset($choice);
            $glossary=absint($definition['glossary_id'] ?? 0);
            if($glossary && isset($glossaries[$glossary])) {
                $glossaries[$glossary]['linked_fields'][]=$key;
                $glossaries[$glossary]['filterable']=true;
                foreach($options as &$choice) {
                    $value=$choice['id'];
                    if(isset($glossaries[$glossary]['options'][$value]))
                        $choice['label']=$glossaries[$glossary]['options'][$value];
                }
                unset($choice);
            }
            $numeric=$definition['type']==='number'
                && count($options)>=2
                && count(array_filter($options,static fn($x)=>is_numeric($x['id'])))
                    ===count($options);
            $dimension=[
                'id'=>'metabox:'.$key,'source'=>'meta_box','key'=>$key,
                'label'=>$definition['label'],
                'kind'=>$numeric?'range':'multi',
                'options'=>$numeric?[]:$options,
                'group'=>self::category($key),
                'glossary_id'=>$glossary?:null,'meta_box'=>$definition['meta_box']
            ];
            if($numeric) {
                $numbers=array_map(static fn($x)=>(float)$x['id'],$options);
                $dimension['min']=min($numbers);
                $dimension['max']=max($numbers);
            }
            $dimensions[]=$dimension;
        }
        $pricingTypes=function_exists('shared_product_strategy_types')
            ? shared_product_strategy_types() : [];
        $types=[];
        foreach ((array)$pricingTypes as $key=>$label) {
            if (!self::valid_key((string)$key))continue;
            $types[]=['id'=>(string)$key,'label'=>sanitize_text_field((string)$label),
                'authority'=>'shared_product_core_existing_evaluator'];
        }
        $types[]=['id'=>'last_minute','label'=>'Last Minute',
            'authority'=>'existing_campaign_event_controller'];
        $types[]=['id'=>'segment_targeting','label'=>'Audience / Market / Schedule Targeting',
            'authority'=>'read_only_rules_preview'];
        // Hooks are strictly catalog discovery; actual matching is validated
        // against the discovered safe dimensions, not executable callbacks.
        return [
            'schema'=>1,'languages'=>$languages,
            'dimensions'=>$respectSelection
                ? MAD4B_JetEngine_Pricing_Dimensions_V1::filter_catalog(
                    array_slice($dimensions,0,120))
                : array_slice($dimensions,0,120),
            'glossaries'=>array_values($glossaries),
            // The Meta Box configuration is discoverable even when no
            // published Tour currently stores a value. Such empty fields
            // are not offered as active predicates until data exists.
            'meta_box_fields'=>array_values(array_map(
                static fn($field)=>[
                    'key'=>$field['key'],'label'=>$field['label'],
                    'meta_box'=>$field['meta_box'],'type'=>$field['type'],
                    'filterable'=>isset($observed[$field['key']])
                        && count($observed[$field['key']])>0,
                ],
                $boxes
            )),
            'strategy_types'=>$types,
            'market_countries'=>$source::market_facets(),
            'targeting_modes'=>['inherit','override'],
            'combine_modes'=>['and','or'],
            'semantics'=>'read_only_published_tours_no_pricing_authority',
            'write_allowed'=>false,
        ];
    }

    private static function category(string $key): string {
        $map=[
            'good-for'=>'audience','tour_categories'=>'booking_model',
            'group-size-tiers'=>'group_size','schudle-days-of-weak'=>'schedule',
            'day-times'=>'schedule','destinations'=>'destination',
            'tour-styles'=>'style','tour-types'=>'style',
            'duration-options'=>'duration','package_levels'=>'package',
            'tour_mode'=>'booking_model','season'=>'season',
        ];
        return $map[$key] ?? 'other';
    }

    /** Data values, not UI configuration values, become eligible Meta Box choices. */
    private static function observed_meta_box_values(array $keys,array $languages): array {
        if (!$keys)return [];
        global $wpdb;
        $source='MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
        $ids=$source::language_post_ids($languages);
        if (!$ids)return [];
        $idSql=implode(',',array_map('absint',array_slice($ids,0,self::MAX_IDS)));
        $keys=array_slice(array_values(array_filter($keys,[__CLASS__,'valid_key'])),0,90);
        if(!$keys)return [];
        $placeholders=implode(',',array_fill(0,count($keys),'%s'));
        $sql=$wpdb->prepare(
            "SELECT pm.meta_key field,pm.meta_value v,COUNT(DISTINCT pm.post_id) n
             FROM {$wpdb->postmeta} pm
             WHERE pm.post_id IN ({$idSql}) AND pm.meta_key IN ({$placeholders})
               AND CHAR_LENGTH(pm.meta_value) BETWEEN 1 AND 64
             GROUP BY pm.meta_key,pm.meta_value ORDER BY n DESC LIMIT 1500",$keys
        );
        $out=[];
        foreach ((array)$wpdb->get_results($sql,ARRAY_A) as $entry) {
            $key=(string)$entry['field'];$value=(string)$entry['v'];
            if(preg_match('/^[aOsibd]:\d+:/',$value)
                || preg_match('/[<>\r\n]/',$value)
                || count($out[$key] ?? [])>=35)continue;
            $out[$key][]=[
                'id'=>$value,'label'=>$value,'count'=>(int)$entry['n'],
            ];
        }
        return $out;
    }

    /** Validate incoming criteria against current, language-scoped JetEngine catalog. */
    public static function rules($submitted,array $catalog) {
        if (!is_array($submitted) || count($submitted)>self::MAX_RULES)
            return new WP_Error('strategy_rules_invalid','Maximum 12 strategy conditions');
        $allowed=[];
        foreach ($catalog['dimensions'] as $dimension)$allowed[$dimension['id']]=$dimension;
        $validated=[];
        foreach ($submitted as $rule) {
            if(!is_array($rule))return new WP_Error('strategy_rule_invalid');
            $id=(string)($rule['id']??'');
            $dimension=$allowed[$id]??null;
            if(!$dimension)return new WP_Error('strategy_unknown_field','Unknown strategy source or field');
            $match=(string)($rule['match']??'any');
            if(!in_array($match,['any','all'],true))
                return new WP_Error('strategy_match_invalid');
            if($dimension['kind']==='range') {
                $min=$rule['min']??null;$max=$rule['max']??null;
                if(!is_numeric($min)||!is_numeric($max)
                    || !is_finite((float)$min)||!is_finite((float)$max)
                    || (float)$min>(float)$max
                    || (float)$min<$dimension['min']
                    || (float)$max>$dimension['max'])
                    return new WP_Error('strategy_range_invalid');
                $validated[]=['id'=>$id,'key'=>$dimension['key'],
                    'source'=>$dimension['source'],'kind'=>'range',
                    'min'=>(float)$min,'max'=>(float)$max,'match'=>'any'];
            } else {
                $requested=$rule['values']??[];
                if(!is_array($requested)||!count($requested)
                    || count($requested)>self::MAX_VALUES)
                    return new WP_Error('strategy_values_invalid');
                $validOptions=array_fill_keys(array_map(
                    static fn($opt)=>(string)$opt['id'],$dimension['options']
                ),true);
                $values=[];
                foreach($requested as $value) {
                    if(!is_scalar($value))return new WP_Error('strategy_option_invalid');
                    $string=(string)$value;
                    if(!isset($validOptions[$string]))
                        return new WP_Error('strategy_option_not_in_schema');
                    $values[$string]=$string;
                }
                $validated[]=['id'=>$id,'key'=>$dimension['key'],
                    'source'=>$dimension['source'],'kind'=>'multi',
                    'values'=>array_values($values),'match'=>$match];
            }
        }
        return $validated;
    }

    public static function matching_ids($submitted,string $operator,array $languages,
                                        array $markets,?string $canonicalLanguage=null) {
        if(!in_array($operator,['and','or'],true))
            return new WP_Error('strategy_combine_invalid');
        $source='MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
        if($canonicalLanguage!==null){
            $canonical=sanitize_key($canonicalLanguage);
            if(!isset($source::languages()[$canonical]))
                return new WP_Error('strategy_canonical_language_invalid');
            $targets=$source::selected_languages($languages);
            // All JetEngine dimensions and Taxonomy Terms are evaluated
            // against the canonical WPML Tour, not a translated copy that
            // might carry incomplete term/meta relationships. This is the
            // policy inheritance contract for display languages.
            $matches=self::matching_ids($submitted,$operator,[$canonical],[]);
            if(is_wp_error($matches))return $matches;
            $sourceIds=array_values(array_unique(array_map('absint',$matches['ids'])));
            $expanded=[];
            $unmapped=[];
            if($sourceIds) {
                global $wpdb;
                $table=$wpdb->prefix.'icl_translations';
                if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)
                    return new WP_Error('strategy_wpml_translations_missing');
                $in=implode(',',array_map('absint',$sourceIds));
                $languagesSql=implode(',',array_fill(0,count($targets),'%s'));
                if(!$targets)return new WP_Error('strategy_language_scope_empty');
                $sql=$wpdb->prepare(
                    "SELECT DISTINCT dst.element_id AS target,src.element_id AS original,
                             dst.language_code AS language
                     FROM {$table} src
                     INNER JOIN {$table} dst ON dst.trid=src.trid
                         AND dst.element_type=src.element_type
                     INNER JOIN {$wpdb->posts} p ON p.ID=dst.element_id
                     WHERE src.element_type=%s AND src.element_id IN ({$in})
                         AND dst.language_code IN ({$languagesSql})
                         AND p.post_type=%s AND p.post_status='publish'
                     ORDER BY target DESC LIMIT 6000",
                    array_merge(['post_'.self::TYPE],$targets,[self::TYPE])
                );
                $rows=$wpdb->get_results($sql,ARRAY_A);
                foreach((array)$rows as $row)
                    $expanded[(int)$row['target']]=(int)$row['target'];
                // Report language coverage without expanding scope to
                // unrelated translated Tours or substituting a locale.
                $covered=[];
                foreach((array)$rows as $row)
                    $covered[(int)$row['original']][(string)$row['language']]=true;
                foreach($sourceIds as $original)
                    foreach($targets as $language)
                        if(empty($covered[$original][$language]))
                            $unmapped[]=['source_tour_id'=>$original,'language'=>$language];
            }
            $markets=$source::select_markets($markets);
            $ids=$source::country_visible_ids(array_values($expanded),
                $targets,$markets);
            return [
                'ids'=>$ids,'matched'=>count($ids),
                'languages'=>$targets,'markets'=>$markets,
                'canonical_language'=>$canonical,
                'canonical_tours_count'=>count($sourceIds),
                'unmapped_taxonomy_translations'=>[],
                'missing_published_tour_translations'=>$unmapped,
                'conditions'=>(int)($matches['conditions']??0),
                'logic'=>$operator,
                'language_mode'=>'canonical_tour_family_wpml_inheritance',
            ];
        }
        $lang=$source::selected_languages($languages);
        $ids=$source::language_post_ids($lang);
        $markets=$source::select_markets($markets);
        $ids=$source::country_visible_ids($ids,$lang,$markets);
        $canonicalLanguage=$canonicalLanguage!==null
            ?sanitize_key($canonicalLanguage):null;
        if($canonicalLanguage!==null&&!in_array($canonicalLanguage,$lang,true))
            return new WP_Error('strategy_canonical_language_out_of_scope');
        $catalog=self::catalog($canonicalLanguage!==null?[$canonicalLanguage]:$lang);
        $rules=self::rules($submitted,$catalog);
        if(is_wp_error($rules))return $rules;
        $taxTranslations=[];
        $translatedCoverage=[];
        $tourLanguages=[];
        if($canonicalLanguage!==null){
            // Translate canonical taxonomy term IDs to each content language.
            // Do NOT silently fall back to English if a translation is missing.
            foreach($rules as $i=>$rule) {
                if($rule['source']!=='taxonomy')continue;
                foreach($lang as $code){
                    $mapped=[];$unmapped=[];
                    foreach($rule['values'] as $value){
                        if($code===$canonicalLanguage)$translated=(int)$value;
                        else $translated=apply_filters('wpml_object_id',
                            (int)$value,$rule['key'],false,$code);
                        if($translated && term_exists((int)$translated,$rule['key']))
                            $mapped[(string)$value]=(string)(int)$translated;
                        else $unmapped[]=(string)$value;
                    }
                    $taxTranslations[$i][$code]=$mapped;
                    if($unmapped)$translatedCoverage[]=[
                        'dimension'=>$rule['id'],'language'=>$code,
                        'missing_canonical_values'=>$unmapped
                    ];
                }
            }
            // Bulk language lookup avoids per-Tour WPML calls.
            global $wpdb;
            $table=$wpdb->prefix.'icl_translations';
            if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table
                &&$ids){
                $quoted=implode(',',array_map('absint',$ids));
                $rows=$wpdb->get_results($wpdb->prepare(
                    "SELECT element_id,language_code FROM {$table}
                     WHERE element_type=%s AND element_id IN ({$quoted})",
                    'post_'.self::TYPE),ARRAY_A);
                foreach((array)$rows as $record)
                    $tourLanguages[(int)$record['element_id']]=(string)$record['language_code'];
            }
        }
        if(!$ids)return ['ids'=>[],'matched'=>0,'languages'=>$lang,
            'markets'=>$markets,'conditions'=>count($rules)];
        if(!$rules)return ['ids'=>$ids,'matched'=>count($ids),
            'languages'=>$lang,'markets'=>$markets,'conditions'=>0];
        // Populate native caches once; no per-tour query and no independent
        // pricing evaluation. Language/country scope never enters OR branches.
        update_meta_cache('post',$ids);
        update_object_term_cache($ids,self::TYPE);
        $result=[];
        foreach($ids as $tour) {
            $outcomes=[];
            foreach($rules as $i=>$rule) {
                $expected=$rule['values']??[];
                if($canonicalLanguage!==null && $rule['source']==='taxonomy'){
                    $tourLang=$tourLanguages[$tour]??'';
                    $translated=$taxTranslations[$i][$tourLang]??[];
                    $expected=array_values($translated);
                }
                if($rule['source']==='taxonomy') {
                    $actual=(array)wp_get_object_terms($tour,$rule['key'],
                        ['fields'=>'ids']);
                    $actual=array_map('strval',array_filter($actual,'is_numeric'));
                } else {
                    $value=get_post_meta($tour,$rule['key'],true);
                    $actual=is_scalar($value)?[(string)$value]:[];
                }
                if($rule['kind']==='range') {
                    $value=$actual[0]??null;
                    $passes=$value!==null && is_numeric($value)
                        && (float)$value >=$rule['min']&&(float)$value <=$rule['max'];
                }elseif(!$expected) {
                    $passes=false; // WPML term translation not available.
                }elseif($rule['match']==='all') {
                    // ALL means all canonical terms must have translations;
                    // never turn partial translations into a broader match.
                    $passes=$canonicalLanguage===null
                        ||$rule['source']!=='taxonomy'
                        ||count($expected)===count($rule['values']);
                    $passes=$passes&&!array_diff($expected,$actual);
                }else{
                    $passes=(bool)array_intersect($expected,$actual);
                }
                $outcomes[]=$passes;
            }
            $hit=$operator==='and'
                ?!in_array(false,$outcomes,true)
                :in_array(true,$outcomes,true);
            if($hit)$result[]=(int)$tour;
        }
        return [
            'ids'=>$result,'matched'=>count($result),
            'languages'=>$lang,'markets'=>$markets,
            'conditions'=>count($rules),
            'logic'=>$operator,
            'canonical_language'=>$canonicalLanguage,
            'unmapped_taxonomy_translations'=>$translatedCoverage,
            'language_mode'=>$canonicalLanguage!==null
                ?'inherit_default':'explicit_content_languages'
        ];
    }
}
