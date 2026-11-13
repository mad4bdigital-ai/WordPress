<?php
/**
 * MAD4B Brand Integrity: read-only, site-scoped attribution checks.
 * A semantic provider may enrich observations via a namespaced filter, but no
 * names of other companies or competitors are defined in the detector.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Brand_Integrity {
    const CONTRACT = 'mad4b.brand-integrity.v1';
    const MAX_BATCH = 500;
    const MAX_FIELD_BYTES = 524288;
    const MAX_ISSUES_PER_POST = 25;

    private static function clean($text) {
        $text = html_entity_decode(wp_strip_all_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $text)));
    }

    private static function normal($text) {
        $text = mb_strtolower(remove_accents(self::clean($text)), 'UTF-8');
        return trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }

    private static function identity(array $brand) {
        $scope = (array)($brand['scope'] ?? array());
        $names = array_filter(array(
            (string)($scope['brand_name'] ?? ''),
            (string)get_bloginfo('name'),
        ));
        $host = (string)wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ($host !== '') $names[] = preg_replace('/^www\./i', '', $host);
        // Approved, site-bound aliases and group relationships can be added by
        // an actual authority provider; no manual competitor blacklist exists.
        $aliases = apply_filters('mad4b/brand_integrity/approved_aliases', array(), $scope);
        if (is_array($aliases)) foreach ($aliases as $alias) {
            if (is_string($alias) && strlen($alias) <= 160) $names[] = $alias;
        }
        $normalized = array();
        foreach ($names as $name) {
            $n = self::normal($name);
            if ($n !== '') {
                $normalized[$n] = true;
                // An orthographic no-space brand form is a derived identity
                // alias, not a manually registered blocked name.
                if (str_contains($n,' ')) $normalized[str_replace(' ','',$n)] = true;
            }
            // A distinctive first word of an approved brand may be used as its
            // short form. This is derived from the live identity, never a label.
            $parts = explode(' ', $n);
            if (count($parts) >= 2 && mb_strlen($parts[0]) >= 5)
                $normalized[$parts[0]] = true;
        }
        return array('normalized' => $normalized, 'scope' => $scope,
            'identity_evidence' => hash('sha256', wp_json_encode(array($scope,$normalized))));
    }

    private static function candidate_is_expected($candidate, array $identity) {
        $name = self::normal($candidate);
        if ($name === '') return true;
        foreach (array_keys($identity['normalized']) as $expected) {
            if ($name === $expected || str_starts_with($name, $expected.' ')
                || str_starts_with($expected, $name.' ')) return true;
        }
        return false;
    }

    private static function non_brand_entities() {
        static $names = null;
        if ($names !== null) return $names;
        $names = array();
        // Exempt exact known taxonomy terms (destinations, activities, places)
        // discovered from WP instead of maintaining a hardcoded city list.
        foreach (get_taxonomies(array('public' => true), 'names') as $tax) {
            $terms = get_terms(array('taxonomy'=>$tax,'hide_empty'=>false,
                'fields'=>'names','number'=>1000));
            if (is_wp_error($terms) || !is_array($terms)) continue;
            foreach ($terms as $term) {
                $n = self::normal($term);
                if ($n !== '') $names[$n] = true;
            }
        }
        return $names;
    }

    private static function allowed_hosts(array $scope) {
        $hosts = array();
        $home = (string)wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ($home !== '') $hosts[] = $home;
        // External hosts are approved by a site-specific provider binding.
        // The detector never assumes another company is an approved sibling.
        $additional = apply_filters('mad4b/brand_integrity/approved_domains',array(),$scope);
        if (is_array($additional)) foreach ($additional as $entry) {
            if (is_string($entry) && strlen($entry)<255) $hosts[]=$entry;
        }
        $normalized=array();
        foreach ($hosts as $host) {
            $host = strtolower(trim($host));
            if (str_contains($host,'://')) $host = (string)wp_parse_url($host,PHP_URL_HOST);
            $host = trim((string)preg_replace('/^www\./i','',$host),'.');
            if ($host !== '' && preg_match('/^[a-z0-9.-]+$/D',$host)) $normalized[$host]=true;
        }
        return $normalized;
    }

    public static function analyze_links($html, $field_path, array $scope=array()) {
        if (!is_string($html) || $html === '' || strlen($html)>self::MAX_FIELD_BYTES) return array();
        // Links in editorial material are review-only; navigation and
        // internal-linking promotions must point to a bound company domain.
        $promotional = preg_match('/(?:^meta\.tour_internal_linking|(?:^|[._])(?:promotional|cta|campaign_links|internal_linking)(?:[._]|$))/i',$field_path)===1;
        $allowed = self::allowed_hosts($scope);
        preg_match_all('~<a\b[^>]*\bhref\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))[^>]*>(.*?)</a>~isu',$html,$raw,PREG_SET_ORDER);
        $out = array();
        foreach (array_slice($raw,0,80) as $entry) {
            $href=html_entity_decode((string)($entry[1]?:($entry[2]?:($entry[3]??''))),ENT_QUOTES|ENT_HTML5,'UTF-8');
            if (str_starts_with($href,'//')) $href='https:'.$href;
            if (!preg_match('~^https?://~i',$href)) continue; // same-page and relative URLs
            $host=strtolower((string)wp_parse_url($href,PHP_URL_HOST));
            $host=(string)preg_replace('/^www\./i','',$host);
            if ($host === '' || isset($allowed[$host])) continue;
            $anchor=mb_substr(self::clean($entry[4]??''),0,160);
            $out[hash('sha256',$href)]=array('host'=>$host,
                'anchor_text'=>$anchor,
                'url_sha256'=>hash('sha256',$href),
                'rule'=>$promotional?'external_promotional_destination':'external_editorial_destination',
                'confidence'=>$promotional?'high':'needs_review');
        }
        return array_values($out);
    }

    private static function append_leaf(array &$fields, $path, $value, $depth=0) {
        if ($depth > 8 || count($fields) > 250) return;
        if (is_array($value)) {
            foreach ($value as $key=>$entry) {
                if (is_string($key) && preg_match('/(?:password|secret|token|private_key|api_key|nonce|license)/i',$key))
                    continue;
                self::append_leaf($fields, $path.'.'.(string)$key, $entry, $depth+1);
            }
            return;
        }
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_FIELD_BYTES) return;
        if ($depth <= 2 && ($value[0] === '[' || $value[0] === '{')) {
            $json = json_decode($value, true, 9);
            if (is_array($json)) {
                self::append_leaf($fields,$path,$json,$depth+1);
                return;
            }
        }
        // Only scan customer-visible textual Elementor settings. Selectors,
        // placeholders, filters and serialized option choices are not brands.
        if (str_contains($path,'meta._elementor_data.') &&
            !preg_match('/\.(?:editor|item_editor_content|html|text_content|text|title|subtitle|heading|description|content|item_title|item_text)$/D',$path)) return;
        $text = self::clean($value);
        if ($text !== '' && mb_strlen($text) >= 12) $fields[$path] = array(
            'text' => mb_substr($text,0,24000), 'html' => $value);
    }

    private static function fields($post_id) {
        $post = get_post($post_id);
        if (!($post instanceof WP_Post)) return array();
        $fields = array();
        $elementor_data = (string)get_post_meta($post_id,'_elementor_data',true);
        foreach (array('post_title','post_excerpt','post_content') as $column) {
            if ($post->post_type==='elementor_library' &&
                $column==='post_content' && $elementor_data!=='') continue;
            self::append_leaf($fields,$column,(string)$post->$column);
        }
        $meta = get_post_meta($post_id);
        foreach ((array)$meta as $key=>$values) {
            if (!is_string($key)
                || preg_match('/(?:password|secret|token|private_key|api_key|nonce|license|_oembed|cache|session)/i',$key))
                continue;
            // Public content fields, Rank Math, Elementor, and JetEngine data.
            // Other private operational meta is not inspected or exposed.
            if (str_starts_with($key,'_') &&
                !in_array($key,array('_elementor_data','_elementor_page_settings','_description'),true)) continue;
            // JetEngine repeater values and the Woo/CPT textual description
            // are actual guest-facing content in tour templates. Field names,
            // unlike third-party brand names, are documented source types.
            if (!preg_match('/^(?:tour_|rank_math_|_elementor_|_description$|itinerary_item$|includes$|excludes$|.*(?:content|description|summary|intro|headline|heading|title|subtitle|message|copy|blurb|tagline|html|text|faq|link|cta|why_book|editor).*)/i',$key)) continue;
            foreach ((array)$values as $index=>$value)
                self::append_leaf($fields,'meta.'.$key.'.'.$index,maybe_unserialize($value));
        }
        return $fields;
    }

    /** Extract named candidate after a *marketing attribution* phrase. */
    public static function analyze_text($text, array $identity) {
        $matches = array();
        $text = self::clean($text);
        if ($text === '') return $matches;
        $proper = '([\p{Lu}][\p{L}\p{M}\p{N}\x{2019}\x{0027}&.\-]*(?:\s+(?:[\p{Lu}][\p{L}\p{M}\p{N}\x{2019}\x{0027}&.\-]*|&)){0,5})';
        $patterns = array(
            'promotional_attribution_en' =>
                '/(?i:\b(?:choosing|why\s+(?:\p{L}+\s+){0,3}choose|trusting|booking\s+with|travel(?:ling)?\s+with|we\s+at|here\s+at|welcome\s+to))\s+'.$proper.'/u',
            'promotional_attribution_europe' =>
                '/(?i:\b(?:elige|elegir|gracias\s+por\s+elegir|reserva\s+con|viaja\s+con|choisir|voyagez\s+avec|réservez\s+avec|merci\s+d[\x{2019}\x{0027}]avoir\s+choisi|scegli(?:ere)?|grazie\s+per\s+aver\s+scelto|prenota\s+con|viaggia\s+con|wählen\s+sie|vielen\s+dank,?\s+dass\s+sie\s+sich\s+für|buchen\s+mit))\s+'.$proper.'/u',
            'promotional_brand_with' =>
                '/(?i:\b(?:with|con|avec))\s+'.$proper.'(?=\s*,\s*(?i:(?:you|we|your|our|tu|tienes|puedes|usted|puoi|voi|vous|nous))\b)/u',
            'promotional_brand_book_with' =>
                '/(?i:\b(?:book\s+now\s+with|reserva\s+ahora\s+con))\s+'.$proper.'/u',
            'promotional_brand_experience' =>
                '/(?i:\bthis)\s+'.$proper.'\s+(?i:experience)\b/u',
            'promotional_brand_experience_es' =>
                '/(?i:\b(?:esta|la)\s+experiencia\s+de)\s+'.$proper.'/u',
            'promotional_attribution_ar' =>
                '/(?:احجز\s+مع\s+(?:شركة|وكالة)|سافر\s+مع\s+(?:شركة|وكالة)|لماذا\s+تختار|مع\s+شركة)\s+([\p{Arabic}][\p{Arabic}\p{N}\s]{1,48})/u',
        );
        $non_brand = self::non_brand_entities();
        foreach ($patterns as $rule=>$regex) {
            if (!preg_match_all($regex,$text,$found,PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
            foreach ($found as $hit) {
                $candidate = trim((string)$hit[1][0], " \t\n\r\0\x0B.,!?;:()[]{}");
                $candidate = mb_substr($candidate,0,100);
                $name = self::normal($candidate);
                // Grammatical determiners/pronouns in title-case headings are
                // not organizational identity (e.g. "Choose Your Way").
                $first = explode(' ', $name)[0];
                if (in_array($first,array('your','our','my','the','this','these','those',
                    'their','his','her','its','a','an','you','we','us','vos','votre',
                    'su','sus','tu','tus','il','la','le','les','die','der','das'),true)) continue;
                if ($rule === 'promotional_brand_with' &&
                    count(preg_split('/\s+/u',$candidate)) < 2) continue;
                if ($rule !== 'promotional_attribution_ar' && !preg_match('/^\p{Lu}/u',$candidate)) continue;
                if ($name === '' || mb_strlen($name) < 3 || isset($non_brand[$name])
                    || self::candidate_is_expected($candidate,$identity)) continue;
                $offset = (int)$hit[0][1];
                $excerpt = mb_substr($text,max(0,mb_strlen(substr($text,0,$offset))-65),200);
                $matches[] = array('candidate'=>$candidate,'rule'=>$rule,
                    'excerpt'=>$excerpt,'confidence'=>in_array($rule,
                        array('promotional_attribution_ar','promotional_brand_experience','promotional_brand_experience_es'),true)
                        ?'needs_review':'high');
            }
        }
        // Supports optional, provider-certified contextual entity recognition.
        // A provider can discover names never registered before; untrusted
        // responses are revalidated against the same site-bound identity.
        $external = apply_filters('mad4b/brand_integrity/contextual_candidates',array(),$text,$identity['scope']);
        if (is_array($external)) foreach (array_slice($external,0,20) as $entry) {
            if (!is_array($entry) || !isset($entry['candidate']) || !is_string($entry['candidate'])) continue;
            $candidate = mb_substr(self::clean($entry['candidate']),0,100);
            if (self::candidate_is_expected($candidate,$identity)
                || isset($non_brand[self::normal($candidate)])) continue;
            $matches[] = array('candidate'=>$candidate,'rule'=>'contextual_provider',
                'excerpt'=>mb_substr($text,0,200),'confidence'=>'needs_review');
        }
        $unique = array();
        foreach ($matches as $row) $unique[$row['rule'].'|'.self::normal($row['candidate'])] = $row;
        return array_values($unique);
    }

    public static function scan_post($post_id, $brand=null, $include_unpublished=false) {
        $post_id = absint($post_id);
        $post = get_post($post_id);
        if (!$post || (!$include_unpublished && $post->post_status!=='publish')) return array();
        if (!is_array($brand)) {
            $brand = class_exists('MAD4B_SCP_Brand_Experience_Quality',false)
                ? MAD4B_SCP_Brand_Experience_Quality::brand_context() : array();
        }
        $identity = self::identity($brand);
        // Missing site identity fails closed into an operator review, not a
        // guessed brand replacement or a false publication decision.
        if (empty($identity['scope']['brand_name'])) return array();
        $issues = array();
        foreach (self::fields($post_id) as $path=>$value) {
            foreach (self::analyze_links($value['html'],$path,$identity['scope']) as $link) {
                $code = 'brand_external_link_attribution_mismatch';
                $key = hash('sha256',get_current_blog_id().'|'.$post->post_type.'|'.$post_id.'|'
                    .$code.'|'.$path.'|'.$link['host'].'|'.$link['url_sha256']);
                $issues[$key]=array(
                    'id'=>$key,'post_type'=>$post->post_type,'object_id'=>$post_id,
                    'code'=>$code,'severity'=>$link['confidence']==='high'?'high':'medium',
                    'label'=>'External domain in a site-brand content link requires verification',
                    'source'=>'mad4b-brand-experience',
                    'source_evidence'=>array(
                        'contract'=>self::CONTRACT,'brand_scope'=>$identity['scope'],
                        'brand_evidence_sha256'=>(string)($brand['brand_evidence_sha256']??$identity['identity_evidence']),
                        'field_path'=>$path,'matched_entity'=>$link['host'],
                        'detection_rule'=>$link['rule'],'evidence_excerpt'=>$link['anchor_text'],
                        'url_sha256'=>$link['url_sha256'],
                        'source_sha256'=>hash('sha256',$value['text'].'|'.$link['url_sha256']),
                        'authority_level'=>(string)($brand['authority_level']??'provisional'),
                        'confidence'=>$link['confidence'],
                        'review_guidance'=>'Check domain ownership and site authorization for this link. Replace with an approved site-specific destination in an authorized content job.',
                        'review_only'=>true,'publication_authorized'=>false,'auto_replacement_authorized'=>false));
                if (count($issues)>=self::MAX_ISSUES_PER_POST) break 2;
            }
            foreach (self::analyze_text($value['text'],$identity) as $match) {
                $code = 'brand_identity_attribution_mismatch';
                $source_sha = hash('sha256', $value['text']);
                $key = hash('sha256', get_current_blog_id().'|'.$post->post_type.'|'.$post_id.'|'
                    .$code.'|'.$path.'|'.self::normal($match['candidate']));
                $issues[$key] = array(
                    'id'=>$key,'post_type'=>$post->post_type,'object_id'=>$post_id,
                    'code'=>$code,'severity'=>$match['confidence']==='high'?'high':'medium',
                    'label'=>'Potential third-party brand attribution in published content',
                    'source'=>'mad4b-brand-experience',
                    'source_evidence'=>array(
                        'contract'=>self::CONTRACT,
                        'brand_scope'=>$identity['scope'],
                        'brand_evidence_sha256'=>(string)($brand['brand_evidence_sha256']??$identity['identity_evidence']),
                        'field_path'=>$path,'matched_entity'=>$match['candidate'],
                        'detection_rule'=>$match['rule'],'evidence_excerpt'=>$match['excerpt'],
                        'source_sha256'=>$source_sha,
                        'authority_level'=>(string)($brand['authority_level']??'provisional'),
                        'confidence'=>$match['confidence'],
                        'review_guidance'=>'Verify the attributed organization against this site Brand Core, then edit the source field in an approved Content Job; inspect all language variants and rendered links.',
                        'review_only'=>true,'publication_authorized'=>false,
                        'auto_replacement_authorized'=>false,
                    ),
                );
                if (count($issues) >= self::MAX_ISSUES_PER_POST) break 2;
            }
        }
        return array_values($issues);
    }

    /**
     * Existing MAD4B Content Experience publish transition can call this
     * *after* its exact meta writes but *before* exposing the post. No direct
     * WordPress hooks, post mutations or generic WooCommerce interference.
     */
    public static function publication_preflight($post_id) {
        if (!class_exists('MAD4B_SCP_Brand_Experience_Quality',false))
            return new WP_Error('mad4b_brand_integrity_context_missing',
                'Site Brand Core identity provider is unavailable.');
        $brand = MAD4B_SCP_Brand_Experience_Quality::brand_context();
        if (empty($brand['scope']['brand_name']))
            return new WP_Error('mad4b_brand_integrity_identity_missing',
                'Site Brand Core has no authoritative brand name.');
        $issues = self::scan_post($post_id, $brand, true);
        $high = array_values(array_filter($issues,
            static fn($issue)=>(string)($issue['severity']??'')==='high'));
        if ($high) {
            $evidence = array();
            foreach (array_slice($high,0,12) as $row) $evidence[] = array(
                'issue_id'=>$row['id'],
                'field_path'=>(string)($row['source_evidence']['field_path']??''),
                'candidate'=>(string)($row['source_evidence']['matched_entity']??''),
            );
            return new WP_Error('mad4b_brand_integrity_publication_blocked',
                'Potential attribution to another company was found in the proposed content; editorial review is required before publication.',
                array('contract'=>self::CONTRACT,'post_id'=>absint($post_id),
                    'issue_count'=>count($high),'evidence'=>$evidence,
                    'auto_replacement_authorized'=>false));
        }
        return array('contract'=>self::CONTRACT,'allowed'=>true,
            'review_issues'=>count($issues),'post_id'=>absint($post_id));
    }

    public static function scan_batch(array $types, $after_id=0, $limit=100) {
        global $wpdb;
        $types = array_values(array_unique(array_filter(array_map('sanitize_key',$types),
            static fn($type)=>post_type_exists($type))));
        $limit = min(self::MAX_BATCH,max(1,absint($limit)));
        if (!$types) return array('contract'=>self::CONTRACT,'complete'=>true,
            'scanned'=>0,'last_id'=>absint($after_id),'issues'=>array());
        $placeholders = implode(',',array_fill(0,count($types),'%s'));
        $params = array_merge(array(absint($after_id)),$types,array($limit+1));
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type IN ($placeholders) ORDER BY ID ASC LIMIT %d";
        $ids = array_map('intval',(array)$wpdb->get_col($wpdb->prepare($sql,$params)));
        $more = count($ids)>$limit;
        if ($more) $ids = array_slice($ids,0,$limit);
        $brand = class_exists('MAD4B_SCP_Brand_Experience_Quality',false)
            ? MAD4B_SCP_Brand_Experience_Quality::brand_context() : array();
        $issues = array();
        foreach ($ids as $id) foreach (self::scan_post($id,$brand) as $issue) $issues[] = $issue;
        return array('contract'=>self::CONTRACT,'complete'=>!$more,
            'scanned'=>count($ids),'last_id'=>$ids?end($ids):absint($after_id),
            'issues'=>$issues,'site_uuid'=>(string)($brand['scope']['site_uuid']??''));
    }

    public static function discover($cfg=null) {
        return self::scan_batch(array('tours-and-activities'),0,self::MAX_BATCH);
    }
}
