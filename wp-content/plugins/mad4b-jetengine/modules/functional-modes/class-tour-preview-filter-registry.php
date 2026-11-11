<?php
defined('ABSPATH') || exit;

/**
 * Read-only filter discovery for the MAD4B JetEngine published-tour inspector.
 * Data sources: registered CPT taxonomies, JetEngine CPT meta field definitions,
 * WPML translation rows and MAD4B approved site-bound Market Profiles.
 */
final class MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1 {
    public const POST_TYPE = 'tours-and-activities';
    private const MAX_SELECTED_LANGUAGES = 8;
    private const MAX_IDS = 6000;

    private static function safe_key(string $key): bool {
        return preg_match('/^[a-z][a-z0-9_\-]{1,79}$/D', $key)
            && !preg_match('/(password|secret|token|api_?key|auth|private|personal|email|phone|mobile|billing|address|lat|lng|geo|form_|cookie|session|checkout|credit|card_number|passport|customer|webhook|raw_html|html|shortcode|video|url|file|caption|description)/i', $key);
    }

    public static function taxonomies(): array {
        $out = [];
        foreach (get_object_taxonomies(self::POST_TYPE, 'objects') as $slug => $tax) {
            if (!is_object($tax) || (!$tax->public && !$tax->show_ui)
                || !self::safe_key((string)$slug)) continue;
            $out[$slug] = $tax;
        }
        return $out;
    }

    private static function meta_schema(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'jet_post_types';
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_fields FROM {$table} WHERE slug=%s ORDER BY id DESC LIMIT 1",
            self::POST_TYPE
        ));
        $fields = maybe_unserialize($row);
        $out = [];
        foreach ((array)$fields as $field) {
            if (!is_array($field)) continue;
            $name = (string)($field['name'] ?? '');
            $type = sanitize_key((string)($field['type'] ?? ''));
            if (!self::safe_key($name) || preg_match('/(?:_id|_ids|_terms|_options|_services|_extras|_rates|_plans)$/i', $name)
                || !in_array($type, ['select', 'radio', 'switcher', 'number', 'checkbox'], true)
                || in_array($name, ['custom_faqs','tour_extras_status',
                    'tour_train_transport_status','tour_air_transport_status',
                    'tour_vehicles_transport_status'], true)) continue;
            // Repeater/relationship/serialized values are never enumerated as scalar facets.
            $out[$name] = [
                'key' => $name,
                'label' => sanitize_text_field(trim((string)($field['title'] ?? ''))
                    ?: ucwords(str_replace(['_','-'],' ',$name))),
                'type' => $type,
                'options_source'=>sanitize_key((string)($field['options_source']??'')),
                'glossary_id'=>absint($field['glossary_id']??0),
            ];
        }
        $registered = get_registered_meta_keys('post', self::POST_TYPE);
        foreach ((array)$registered as $name => $settings) {
            if (!is_string($name) || !self::safe_key($name) || isset($out[$name])
                || in_array($name,['custom_faqs','tour_extras_status',
                    'tour_train_transport_status','tour_air_transport_status',
                    'tour_vehicles_transport_status'],true)) continue;
            if (!is_array($settings) || empty($settings['single']) || empty($settings['show_in_rest'])) continue;
            if (preg_match('/^(rank_math_|_jet_|jet_engine_|ast-|image_|custom_extra_items_|_)/i', $name)) continue;
            if (!in_array((string)($settings['type'] ?? ''), ['string', 'number', 'integer', 'boolean'], true)) continue;
            $out[$name] = [
                'key'=>$name,
                'label'=>sanitize_text_field(trim((string)($settings['label']??''))
                    ?: ucwords(str_replace(['_','-'],' ',$name))),
                'type'=>in_array($settings['type'], ['number','integer'], true)?'number':'select',
            ];
        }
        return array_slice($out, 0, 90, true);
    }

    public static function meta_fields(): array {
        return self::meta_schema();
    }

    public static function languages(): array {
        $raw = class_exists('MAD4B_SCP_Market_Profiles')
            ? MAD4B_SCP_Market_Profiles::enabled_languages() : [];
        if (!is_array($raw)) $raw = [];
        if (!$raw && has_filter('wpml_active_languages')) {
            $wpml = apply_filters('wpml_active_languages', null, ['skip_missing'=>0]);
            foreach ((array)$wpml as $code=>$config) {
                if (!is_array($config)) continue;
                $raw[(string)$code] = (string)($config['native_name'] ?? $config['translated_name'] ?? $code);
            }
        }
        if (!$raw) {
            $code = sanitize_key((string)strtok(str_replace('_', '-', get_locale()), '-'));
            if ($code) $raw[$code] = strtoupper($code);
        }
        $out = [];
        foreach ($raw as $code=>$label) {
            $code = sanitize_key((string)$code);
            if (!preg_match('/^[a-z]{2,8}$/D', $code)) continue;
            $out[$code] = sanitize_text_field((string)$label);
        }
        return $out;
    }

    public static function selected_languages($raw): array {
        $allowed = self::languages();
        $requested = is_array($raw) ? $raw : [];
        $picked = [];
        foreach (array_slice($requested, 0, self::MAX_SELECTED_LANGUAGES) as $value) {
            if (!is_string($value)) continue;
            $code = sanitize_key($value);
            if (isset($allowed[$code])) $picked[$code] = $code;
        }
        if ($picked) return array_values($picked);
        // A missing language filter defaults to the current WPML language,
        // not to mixing all translations of the same tour.
        $current = sanitize_key((string)apply_filters('wpml_current_language', ''));
        if (isset($allowed[$current])) return [$current];
        return $allowed ? [array_key_first($allowed)] : [];
    }

    public static function language_facets(): array {
        $languages = self::languages();
        $counts = [];
        global $wpdb;
        $table = $wpdb->prefix . 'icl_translations';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT t.language_code AS code, COUNT(DISTINCT p.ID) AS n
                 FROM {$wpdb->posts} p INNER JOIN {$table} t
                   ON t.element_id=p.ID AND t.element_type=%s
                 WHERE p.post_type=%s AND p.post_status='publish'
                 GROUP BY t.language_code",
                'post_'.self::POST_TYPE, self::POST_TYPE
            ), ARRAY_A);
            foreach ((array)$rows as $row) {
                $counts[(string)$row['code']] = (int)$row['n'];
            }
        }
        $out = [];
        foreach ($languages as $code=>$label) {
            $out[] = ['id'=>$code,'name'=>$label.' ('.$code.')',
                'count'=>$counts[$code] ?? 0];
        }
        return $out;
    }

    public static function language_post_ids(array $codes): array {
        $languages = self::languages();
        $codes = array_values(array_filter($codes, static fn($code) =>
            is_string($code) && isset($languages[$code])));
        if (!$codes) return [];
        global $wpdb;
        $table = $wpdb->prefix . 'icl_translations';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            // No WPML table: WP itself owns the current language.
            return array_map('intval', get_posts([
                'post_type'=>self::POST_TYPE,'post_status'=>'publish',
                'posts_per_page'=>self::MAX_IDS,'fields'=>'ids',
                'suppress_filters'=>true,'no_found_rows'=>true,
            ]));
        }
        $in = implode(',', array_fill(0, count($codes), '%s'));
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$table} t ON t.element_id=p.ID AND t.element_type=%s
             WHERE p.post_type=%s AND p.post_status='publish'
               AND t.language_code IN ({$in})
             ORDER BY p.ID DESC LIMIT %d",
            array_merge(['post_'.self::POST_TYPE, self::POST_TYPE], $codes, [self::MAX_IDS])
        );
        return array_map('intval', (array)$wpdb->get_col($sql));
    }

    public static function approved_markets(): array {
        // Draft, imported, blueprint and old-env profiles MUST NOT gain authority here.
        $profiles = class_exists('MAD4B_SCP_Market_Profiles')
            ? MAD4B_SCP_Market_Profiles::approved_list() : [];
        $allowedLangs = self::languages();
        $out = [];
        foreach ((array)$profiles as $profile) {
            if (!is_array($profile)) continue;
            $country = strtoupper((string)($profile['country'] ?? ''));
            $language = sanitize_key((string)($profile['language'] ?? ''));
            if (!preg_match('/^[A-Z]{2}$/D', $country) || !isset($allowedLangs[$language])
                || empty($profile['profile_id'])) continue;
            $out[$country][] = [
                'id'=>sanitize_key((string)$profile['profile_id']),
                'language'=>$language,
                'country'=>$country,
            ];
        }
        return $out;
    }

    /**
     * WPML's installed language locale is an advisory language/country pairing.
     * It permits content inspection, but NEVER grants commercial market approval.
     * Site-bound approved profiles, when present, remain the only selling authority.
     */
    public static function market_contexts(): array {
        $contexts = [];
        foreach (self::approved_markets() as $country=>$profiles) {
            $contexts[$country] = [
                'country'=>$country, 'source'=>'mad4b_approved',
                'status'=>'approved_site_market_profile',
                'languages'=>array_values(array_unique(array_column($profiles, 'language'))),
                'profile_ids'=>array_values(array_unique(array_column($profiles, 'id'))),
            ];
        }
        $allowedLanguages=self::languages();
        $details=apply_filters('wpml_active_languages', null, ['skip_missing'=>0]);
        foreach ((array)$details as $language=>$record) {
            if (!is_array($record)) continue;
            $code=sanitize_key((string)($record['code']??$record['language_code']??$language));
            if (!isset($allowedLanguages[$code])) continue;
            $locale=(string)($record['default_locale']??'');
            if (!preg_match('/^[a-z]{2,8}[_-]([A-Z]{2})$/iD',$locale,$matches)) continue;
            $country=strtoupper($matches[1]);
            if (!preg_match('/^[A-Z]{2}$/D',$country)) continue;
            if (class_exists('MAD4B_SCP_Market_Profiles')
                && !MAD4B_SCP_Market_Profiles::is_targetable($country,$code)) continue;
            if (!isset($contexts[$country])) {
                $contexts[$country]=[
                    'country'=>$country, 'source'=>'wpml_default_locale',
                    'status'=>'language_country_preview_only',
                    'languages'=>[], 'profile_ids'=>[],
                ];
            }
            if (!in_array($code,$contexts[$country]['languages'],true)) {
                $contexts[$country]['languages'][]=$code;
            }
        }
        // Native market profile drafts can be previewed but not treated as approved.
        if (class_exists('MAD4B_SCP_Market_Profiles')) {
            foreach (MAD4B_SCP_Market_Profiles::records() as $record) {
                if (!is_array($record) || ($record['status']??'')!=='draft') continue;
                $country=strtoupper((string)($record['country']??''));
                $language=sanitize_key((string)($record['language']??''));
                if (!preg_match('/^[A-Z]{2}$/D',$country)
                    || !isset($allowedLanguages[$language])
                    || !MAD4B_SCP_Market_Profiles::is_targetable($country,$language)) continue;
                if (!isset($contexts[$country])) {
                    $contexts[$country]=[
                        'country'=>$country, 'source'=>'mad4b_market_draft',
                        'status'=>'draft_preview_only',
                        'languages'=>[], 'profile_ids'=>[],
                    ];
                }
                if (!in_array($language,$contexts[$country]['languages'],true)) {
                    $contexts[$country]['languages'][]=$language;
                }
            }
        }
        ksort($contexts);
        return $contexts;
    }

    public static function market_facets(): array {
        $market = self::market_contexts();
        $countries = function_exists('WC') && WC()->countries
            ? WC()->countries->get_countries() : [];
        $out = [];
        foreach ($market as $code=>$entry) {
            $type=$entry['status']==='approved_site_market_profile'?'Approved':
                ($entry['status']==='draft_preview_only'?'Draft':'WPML locale');
            $out[] = [
                'id'=>$code,
                'name'=>(isset($countries[$code])?(string)$countries[$code]:$code).' ('.$code.') · '.$type,
                'count'=>count($entry['languages']),
                'status'=>$entry['status'],
                'source'=>$entry['source'],
                'languages'=>$entry['languages'],
            ];
        }
        return $out;
    }

    public static function select_markets($raw): array {
        if (!is_array($raw)) return [];
        $allowed = self::approved_markets();
        $selected = [];
        foreach (array_slice($raw,0,20) as $v) {
            if (!is_string($v)) continue;
            $v = strtoupper(trim($v));
            if (!preg_match('/^[A-Z]{2}$/D',$v)) continue;
            // Include unapproved selections as an explicit impossible filter
            // rather than silently treating them as all countries.
            $selected[$v] = $v;
        }
        return array_values($selected);
    }

    public static function country_visible_ids(array $ids, array $languages, array $countries): array {
        if (!$countries) return $ids;
        $contexts=self::market_contexts();
        $permitted_languages=[];
        $profile_ids=[];
        foreach ($countries as $country) {
            if (!isset($contexts[$country])) continue;
            foreach ($contexts[$country]['languages'] as $language) {
                if (in_array($language,$languages,true)) $permitted_languages[$language]=true;
            }
            foreach ($contexts[$country]['profile_ids'] as $id) $profile_ids[$id]=true;
        }
        if (!$permitted_languages) return [];
        $result=[];
        $language_by_id=self::post_languages($ids);
        foreach ($ids as $id) {
            $id=(int)$id;
            if (empty($permitted_languages[$language_by_id[$id]??''])) continue;
            $raw=get_post_meta($id,'recommendation_market_profile_ids',true);
            if (!is_scalar($raw) || trim((string)$raw)==='') {
                $result[]=$id; // Unrestricted content only, not sales authorization.
                continue;
            }
            $parts=preg_split('/[,;|\s]+/',strtolower((string)$raw));
            foreach ($parts as $profile) {
                if (isset($profile_ids[sanitize_key($profile)])) {
                    $result[]=$id;
                    break;
                }
            }
        }
        return $result;
    }

    public static function post_languages(array $ids): array {
        if (!$ids) return [];
        global $wpdb;
        $ids=array_slice(array_map('absint',$ids),0,self::MAX_IDS);
        $table=$wpdb->prefix.'icl_translations';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) {
            $current=sanitize_key((string)apply_filters('wpml_current_language',''));
            return array_fill_keys($ids,$current);
        }
        $chunks=array_chunk($ids,500);
        $out=[];
        foreach ($chunks as $chunk) {
            $in=implode(',',array_map('absint',$chunk));
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT element_id,language_code FROM {$table} WHERE element_type=%s AND element_id IN ({$in})",
                'post_'.self::POST_TYPE
            ),ARRAY_A);
            foreach ((array)$rows as $row)$out[(int)$row['element_id']]=(string)$row['language_code'];
        }
        return $out;
    }

    public static function option_labels(array $definition, array $values): array {
        $labels=[];
        $source=$definition['options_source']??'';
        if ($source==='glossary' && !empty($definition['glossary_id'])) {
            global $wpdb;
            $table=$wpdb->prefix.'jet_post_types';
            $data=$wpdb->get_var($wpdb->prepare(
                "SELECT meta_fields FROM {$table} WHERE id=%d AND status=%s",
                $definition['glossary_id'],'glossary'
            ));
            foreach((array)maybe_unserialize($data) as $option) {
                if (!is_array($option)) continue;
                $value=(string)($option['value']??'');
                $label=(string)($option['label']??'');
                if ($value!=='' && $label!=='') $labels[$value]=sanitize_text_field($label);
            }
        }
        if ($source==='shared_tour_destinations') {
            $ids=array_values(array_filter(array_map('absint',$values)));
            if ($ids) {
                $terms=get_terms(['taxonomy'=>'destinations','include'=>$ids,
                    'hide_empty'=>false,'number'=>count($ids)]);
                if (is_array($terms) && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        if ($term instanceof WP_Term) {
                            $labels[(string)$term->term_id]=sanitize_text_field($term->name);
                        }
                    }
                }
            }
        }
        return $labels;
    }

    public static function meta_facets(array $languages=[]): array {
        global $wpdb;
        $fields=self::meta_schema();
        $ids=self::language_post_ids($languages?:self::selected_languages([]));
        if (!$fields || !$ids) return [];
        $postIds=implode(',',array_map('absint',$ids));
        // Query only declared scalar-type meta keys, avoiding expensive post-wide scans.
        $keys = array_keys($fields);
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_key AS k, pm.meta_value AS v, COUNT(DISTINCT p.ID) AS n
             FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE p.post_type=%s AND p.post_status='publish' AND p.ID IN ({$postIds})
               AND pm.meta_key IN ({$placeholders})
               AND CHAR_LENGTH(pm.meta_value) BETWEEN 1 AND 100
             GROUP BY pm.meta_key, pm.meta_value ORDER BY n DESC LIMIT 4500",
            array_merge([self::POST_TYPE], $keys)
        ), ARRAY_A);
        $options = [];
        foreach ((array)$rows as $row) {
            $k=(string)$row['k'];$v=trim((string)$row['v']);
            if (!isset($fields[$k]) || preg_match('/^[aOsibd]:\d+:/',$v)
                || preg_match('/[<>\r\n]/',$v) || count($options[$k] ?? [])>=36) continue;
            if (strlen($v)>56) continue;
            $options[$k][$v] = ['id'=>$v,'name'=>$v,'count'=>(int)$row['n']];
        }
        $facets = [];
        foreach ($fields as $key=>$definition) {
            $values = array_values($options[$key]??[]);
            $labels=self::option_labels($definition, array_column($values,'id'));
            foreach($values as &$value) {
                if(isset($labels[(string)$value['id']]))$value['name']=$labels[(string)$value['id']];
            }
            unset($value);
            $numeric = $definition['type']==='number';
            if (count($values)<2 || ($definition['type']!=='number' && count($values)>=30)) continue;
            if ($numeric && count($values)>=24) {
                $bounds = $wpdb->get_row($wpdb->prepare(
                    "SELECT MIN(CAST(pm.meta_value AS DECIMAL(18,4))) AS low,
                            MAX(CAST(pm.meta_value AS DECIMAL(18,4))) AS high
                     FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
                     WHERE p.post_type=%s AND p.post_status='publish' AND p.ID IN ({$postIds})
                       AND pm.meta_key=%s AND pm.meta_value REGEXP '^[0-9]+([.][0-9]+)?$'",
                    self::POST_TYPE, $key
                ), ARRAY_A);
                if (!$bounds || !is_numeric($bounds['low']??null) || !is_numeric($bounds['high']??null)) continue;
                $facets[]=[
                    'id'=>'meta:'.$key,'key'=>$key,'source'=>'meta','kind'=>'range',
                    'label'=>$definition['label'], 'min'=>(float)$bounds['low'],
                    'max'=>(float)$bounds['high'],'terms'=>[]
                ];
                continue;
            }
            $facets[]=[
                'id'=>'meta:'.$key,'key'=>$key,'source'=>'meta','kind'=>'multi',
                'label'=>$definition['label'],'terms'=>$values
            ];
        }
        return array_slice($facets,0,60);
    }

    public static function taxonomy_facets(array $languages=[]): array {
        $tax=self::taxonomies();
        $ids=self::language_post_ids($languages?:self::selected_languages([]));
        if (!$tax || !$ids) return [];
        global $wpdb;
        $in=implode(',',array_map('absint',$ids));
        $placeholders=implode(',',array_fill(0,count($tax),'%s'));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT tt.taxonomy AS tax, t.term_id AS term_id, t.name AS term_name,
                    COUNT(DISTINCT tr.object_id) AS total
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
             WHERE tr.object_id IN ({$in}) AND tt.taxonomy IN ({$placeholders})
             GROUP BY tt.taxonomy,t.term_id,t.name
             ORDER BY total DESC LIMIT 3500",
            array_keys($tax)
        ),ARRAY_A);
        $grouped=[];
        foreach((array)$rows as $row) {
            $slug=(string)$row['tax'];
            if(!isset($tax[$slug]) || count($grouped[$slug]??[])>=100)continue;
            $grouped[$slug][]=[
                'id'=>(int)$row['term_id'],
                'name'=>sanitize_text_field((string)$row['term_name']),
                'count'=>(int)$row['total'],
            ];
        }
        $out=[];
        foreach($tax as $key=>$object) {
            if (empty($grouped[$key]))continue;
            $out[]=[
                'id'=>$key, 'label'=>sanitize_text_field((string)$object->labels->name),
                'terms'=>$grouped[$key],'source'=>'taxonomy','kind'=>'multi',
            ];
        }
        return $out;
    }

    public static function meta_requested($raw): array {
        if(!is_array($raw))return [];
        $schema=self::meta_schema();
        $safe=[];
        foreach($raw as $filter=>$values) {
            if(!is_string($filter)||!str_starts_with($filter,'meta:'))continue;
            $key=substr($filter,5);
            if(!isset($schema[$key]))continue;
            if(is_array($values) && isset($values['min'], $values['max'])) {
                if($schema[$key]['type']!=='number')continue;
                $min=filter_var($values['min'],FILTER_VALIDATE_FLOAT);
                $max=filter_var($values['max'],FILTER_VALIDATE_FLOAT);
                if($min===false||$max===false||$min>$max)continue;
                $safe[$key]=['kind'=>'range','min'=>$min,'max'=>$max];
            }elseif(is_array($values)){
                $list=[];
                foreach(array_slice($values,0,20) as $value) {
                    if(!is_scalar($value))continue;
                    $v=trim((string)$value);
                    if(strlen($v)>56||$v==='')continue;
                    $list[$v]=$v;
                }
                if($list)$safe[$key]=['kind'=>'multi','values'=>array_values($list)];
            }
            if(count($safe)>=20)break;
        }
        return $safe;
    }
}
