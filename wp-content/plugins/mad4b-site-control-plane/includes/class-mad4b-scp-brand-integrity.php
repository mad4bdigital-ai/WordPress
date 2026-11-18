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
    const MAX_TEXT_FIELDS_PER_POST = 1000;
    const MAX_NESTING_DEPTH = 24;
    const MAX_DISCOVERY_BATCHES = 20;
    const MAX_SCANNED_TEXT_CHARS = 24000;

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
        // Source-field intent is authoritative, not the generated URL.
        // Destination further reading and related content link internally by default.
        $promotional = preg_match('/(?:^meta\.tour_internal_linking|(?:^|[._])(?:promotional|cta|campaign_links|internal_linking|further_reading|related_tours?|related_destinations?|related_articles?|related_links?|destination_links)(?:[._]|$))/i',$field_path)===1;
        $allowed = self::allowed_hosts($scope);
        preg_match_all('~<a\b[^>]*\bhref\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))[^>]*>(.*?)</a>~isu',$html,$raw,PREG_SET_ORDER);
        // JetEngine term repeaters can store title and URL in separate leaves.
        // Previously plain URLs never reached the HTML-only parser.
        $url_only = trim($html);
        if (preg_match('~^(?:https?:)?//[^\s<>]+$~i',$url_only)) {
            $raw[] = array('', $url_only, '', '', '');
        }
        $out = array();
        foreach (array_slice($raw,0,80) as $entry) {
            $href=html_entity_decode((string)($entry[1]?:($entry[2]?:($entry[3]??''))),ENT_QUOTES|ENT_HTML5,'UTF-8');
            if (str_starts_with($href,'//')) $href='https:'.$href;
            if (!preg_match('~^https?://~i',$href)) continue; // same-page and relative URLs
            $host=strtolower((string)wp_parse_url($href,PHP_URL_HOST));
            $host=(string)preg_replace('/^www\./i','',$host);
            if ($host === '' || isset($allowed[$host])) continue;
            $anchor=mb_substr(self::clean($entry[4]??''),0,160);
            // An ordinary source citation or partner link is NOT a brand
            // violation. A conversion CTA in editorial copy is advisory;
            // a company-owned internal-linking field is a hard mismatch.
            $cta=preg_match('/\b(?:book\s+(?:our|your)\s+(?:tour|trip)|reserve\s+your\s+tour|explore\s+our\s+(?:tours|trips)|reserva\s+tu\s+tour|réservez\s+votre\s+voyage)\b/iu',$anchor)===1;
            // External link leaves in public term metadata require review even
            // without booking language; editorial post citations remain unchanged.
            $term_link = str_starts_with($field_path,'termmeta.')
                && preg_match('/(?:^|[._])(?:url|href|links?|further_reading|related_resources)(?:[._]|$)/i',$field_path)===1;
            if(!$promotional && !$cta && !$term_link)continue;
            $out[hash('sha256',$href)]=array('host'=>$host,
                'anchor_text'=>$anchor,
                'url_sha256'=>hash('sha256',$href),
                'rule'=>$promotional?'external_promotional_destination':'external_editorial_destination',
                'confidence'=>$promotional?'high':'needs_review');
        }
        return array_values($out);
    }

    private static function coverage_gap(array &$coverage, $path, $reason) {
        $coverage['complete'] = false;
        $coverage['gap_count'] = (int)($coverage['gap_count']??0)+1;
        if (count($coverage['gap_samples'])<12)
            $coverage['gap_samples'][]=array('field_path'=>$path,'reason'=>$reason);
    }

    private static function append_leaf(array &$fields, $path, $value, $depth=0, array &$coverage=array()) {
        if ($depth > self::MAX_NESTING_DEPTH) {
            self::coverage_gap($coverage,$path,'nesting_limit');
            return;
        }
        if (count($fields) >= self::MAX_TEXT_FIELDS_PER_POST) {
            self::coverage_gap($coverage,$path,'text_field_budget');
            return;
        }
        if (is_array($value)) {
            foreach ($value as $key=>$entry) {
                if (is_string($key) && preg_match('/(?:password|secret|token|private_key|api_key|nonce|license)/i',$key))
                    continue;
                self::append_leaf($fields, $path.'.'.(string)$key, $entry, $depth+1,$coverage);
            }
            return;
        }
        if (!is_string($value) || $value === '') return;
        // Source-path exclusions precede length checks: non-editorial CSS
        // must not turn a complete publication/scan into a false gap.
        if(preg_match('/(?:^|[._])(?:custom_css|custom_js|javascript|stylesheet|script_code)(?:$|[._])/i',$path))
            return;
        if (strlen($value)>self::MAX_FIELD_BYTES) {
            self::coverage_gap($coverage,$path,'field_bytes_exceeded');
            return;
        }
        if ($depth <= 2 && ($value[0] === '[' || $value[0] === '{')) {
            $json = json_decode($value, true, 26);
            if (is_array($json)) {
                self::append_leaf($fields,$path,$json,$depth+1,$coverage);
                return;
            }
        }
        // Elementor and third-party widgets may register arbitrary dynamic
        // textual setting names. Scan every eligible source leaf rather than
        // maintaining a fixed widget-property allowlist; styling/script fields
        // were excluded above by their source type.
        // Include standalone URLs in structured meta, not only long prose.
        if (preg_match('~^(?:https?:)?//\S+$~i',trim($value))
            && preg_match('/(?:^|[._])(?:url|href|links?|further_reading|related_tours?|related_destinations?)(?:[._]|$)/i',$path)) {
            $fields[$path] = array('text'=>'','html'=>trim($value));
            return;
        }
        $text = self::clean($value);
        if ($text !== '' && mb_strlen($text) >= 12 && !preg_match('~^(?:https?://|//)\S+$~i',$text)) {
            if (mb_strlen($text)>self::MAX_SCANNED_TEXT_CHARS)
                self::coverage_gap($coverage,$path,'scanned_text_excerpt_limit');
            if (substr_count(strtolower($value),'<a ')>80)
                self::coverage_gap($coverage,$path,'link_count_limit');
            $fields[$path] = array(
                'text'=>mb_substr($text,0,self::MAX_SCANNED_TEXT_CHARS),
                'html'=>$value);
        }
    }

    private static function fields($post_id, array &$coverage) {
        $coverage = array('complete'=>true,'gap_count'=>0,'gap_samples'=>array());
        $post = get_post($post_id);
        if (!($post instanceof WP_Post)) return array();
        $fields = array();
        $elementor_data = (string)get_post_meta($post_id,'_elementor_data',true);
        foreach (array('post_title','post_excerpt','post_content') as $column) {
            if ($post->post_type==='elementor_library' &&
                $column==='post_content' && $elementor_data!=='') continue;
            self::append_leaf($fields,$column,(string)$post->$column,0,$coverage);
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
            // Every non-private public field may contain a customer-visible
            // sentence, regardless of a plugin's field naming convention.
            // Candidate detection remains contextual; data/media-only scalar
            // values cannot independently produce an attribution finding.
            foreach ((array)$values as $index=>$value)
                self::append_leaf($fields,'meta.'.$key.'.'.$index,maybe_unserialize($value),0,$coverage);
        }
        return $fields;
    }

    /** Extract named candidate after a *marketing attribution* phrase. */
    public static function analyze_text($text, array $identity) {
        $matches = array();
        $text = self::clean($text);
        if ($text === '') return $matches;
        // Only explicit corporate attribution is a first-party signal.
        // "Welcome to X" alone also introduces software, cities and articles,
        // so it needs contextual-provider evidence rather than an issue.
        // Sentence punctuation must never extend a candidate into the next
        // sentence; dotted legal abbreviations remain provider-reviewable.
        $proper = '([\p{Lu}][\p{L}\p{M}\p{N}\x{2019}\x{0027}&\-]*(?:\s+(?:[\p{Lu}][\p{L}\p{M}\p{N}\x{2019}\x{0027}&\-]*|&)){0,5})';
        $patterns = array(
            'promotional_attribution_en' =>
                '/(?i:\b(?:choosing|why\s+(?:\p{L}+\s+){0,3}choose|trusting|booking\s+with|travel(?:ling)?\s+with|we\s+at|here\s+at))\s+'.$proper.'/u',
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
                    'su','sus','tu','tus','il','la','le','les','die','der','das',
                    'ihre','ihren','ihr','ihrem','ihres','unsere','unseren','unserer'),true)) continue;
                if ($rule === 'promotional_brand_with' &&
                    count(preg_split('/\s+/u',$candidate)) < 2) continue;
                if ($rule !== 'promotional_attribution_ar' && !preg_match('/^\p{Lu}/u',$candidate)) continue;
                if ($name === '' || mb_strlen($name) < 3 || isset($non_brand[$name])
                    || self::candidate_is_expected($candidate,$identity)) continue;
                $offset = (int)$hit[0][1];
                $excerpt = mb_substr($text,max(0,mb_strlen(substr($text,0,$offset))-65),200);
                $matches[] = array('candidate'=>$candidate,'rule'=>$rule,
                    'excerpt'=>$excerpt,'confidence'=>in_array($rule,
                        array('promotional_attribution_ar','promotional_brand_experience',
                            'promotional_brand_experience_es'),true)
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
        $report=self::scan_post_report($post_id,$brand,$include_unpublished);
        return $report['issues'];
    }

    public static function scan_post_report($post_id, $brand=null, $include_unpublished=false) {
        $post_id = absint($post_id);
        $post = get_post($post_id);
        if (!$post || (!$include_unpublished && $post->post_status!=='publish'))
            return array('complete'=>true,'issues'=>array(),'gap_count'=>0,'gap_samples'=>array(),'field_count'=>0);
        if (!is_array($brand)) {
            $brand = class_exists('MAD4B_SCP_Brand_Experience_Quality',false)
                ? MAD4B_SCP_Brand_Experience_Quality::brand_context() : array();
        }
        $identity = self::identity($brand);
        // Missing site identity fails closed into an operator review, not a
        // guessed brand replacement or a false publication decision.
        if (empty($identity['scope']['brand_name']))
            return array('complete'=>false,'issues'=>array(),'gap_count'=>1,
                'gap_samples'=>array(array('reason'=>'site_identity_missing')),'field_count'=>0);
        $issues = array();
        $coverage=array();
        $fields=self::fields($post_id,$coverage);
        foreach ($fields as $path=>$value) {
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
                if (count($issues)>=self::MAX_ISSUES_PER_POST) {
                    self::coverage_gap($coverage,$path,'issue_limit_reached');
                    break 2;
                }
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
                if (count($issues) >= self::MAX_ISSUES_PER_POST) {
                    self::coverage_gap($coverage,$path,'issue_limit_reached');
                    break 2;
                }
            }
        }
        return array(
            'complete'=>!empty($coverage['complete']),
            'gap_count'=>(int)$coverage['gap_count'],
            'gap_samples'=>$coverage['gap_samples'],
            'field_count'=>count($fields),
            'issues'=>array_values($issues)
        );
    }

    /**
     * A published tour may already contain historical issues. A governed edit
     * must be allowed to repair them, while never introducing a high-confidence
     * issue or silently changing the source while keeping the same issue ID.
     */
    public static function newly_introduced_high(array $before, array $after) {
        $known=array();
        foreach((array)($before['issues']??array()) as $row) {
            if (!is_array($row) || empty($row['id'])) continue;
            $known[(string)$row['id']]=(string)($row['source_evidence']['source_sha256']??'');
        }
        $added=array();
        foreach((array)($after['issues']??array()) as $row) {
            if(!is_array($row) || ($row['severity']??'')!=='high') continue;
            $key=(string)($row['id']??'');
            $current=(string)($row['source_evidence']['source_sha256']??'');
            if($key==='' || !isset($known[$key]) || !hash_equals($known[$key],$current))
                $added[]=$row;
        }
        return $added;
    }

    private static function blockers($post_id, array $issues, $reason) {
        $evidence=array();
        foreach(array_slice($issues,0,12) as $row)$evidence[]=array(
            'issue_id'=>(string)($row['id']??''),
            'field_path'=>(string)($row['source_evidence']['field_path']??''),
            'candidate'=>(string)($row['source_evidence']['matched_entity']??'')
        );
        return new WP_Error('mad4b_brand_integrity_publication_blocked',
            $reason,array('contract'=>self::CONTRACT,'post_id'=>absint($post_id),
                'issue_count'=>count($issues),'evidence'=>$evidence,
                'auto_replacement_authorized'=>false));
    }

    public static function live_update_preflight($post_id,array $baseline) {
        if(empty($baseline['complete']))
            return new WP_Error('mad4b_brand_integrity_baseline_incomplete',
                'Live update baseline coverage was not complete.');
        $report=self::scan_post_report($post_id,null,true);
        if(empty($report['complete']))
            return new WP_Error('mad4b_brand_integrity_coverage_incomplete',
                'Live update coverage was incomplete.',
                array('post_id'=>absint($post_id),'gap_count'=>$report['gap_count'],
                    'gap_samples'=>$report['gap_samples']));
        $added=self::newly_introduced_high($baseline,$report);
        if($added)return self::blockers($post_id,$added,
            'Live content update would introduce or alter a high-confidence brand mismatch.');
        return array('contract'=>self::CONTRACT,'allowed'=>true,
            'historic_issue_count'=>count($report['issues']),'new_high_confidence_issues'=>0);
    }

    /**
     * Review changed payload BEFORE touching an already-public document.
     * The final source scan after helpers/media protects against changes
     * introduced by integrations outside this normalized input.
     */
    public static function payload_preflight(array $input) {
        if (!class_exists('MAD4B_SCP_Brand_Experience_Quality',false))
            return new WP_Error('mad4b_brand_integrity_context_missing','Brand provider unavailable.');
        $brand=MAD4B_SCP_Brand_Experience_Quality::brand_context();
        $identity=self::identity($brand);
        if(empty($identity['scope']['brand_name']))
            return new WP_Error('mad4b_brand_integrity_identity_missing','Site brand identity unavailable.');
        $fields=array();$coverage=array('complete'=>true,'gap_count'=>0,'gap_samples'=>array());
        foreach(array('post_title','post_content','post_excerpt') as $key) {
            if(array_key_exists($key,$input) && $input[$key]!==null)
                self::append_leaf($fields,$key,$input[$key],0,$coverage);
        }
        foreach((array)($input['meta']??array()) as $key=>$value) {
            if(!is_string($key) || preg_match('/(?:password|secret|token|private_key|api_key|nonce|license|_oembed|cache|session)/i',$key))
                continue;
            self::append_leaf($fields,'meta.'.$key.'.0',$value,0,$coverage);
        }
        if(!$coverage['complete'])return new WP_Error('mad4b_brand_integrity_payload_incomplete',
            'The planned content payload could not be inspected completely.',
            array('gap_count'=>$coverage['gap_count'],'samples'=>$coverage['gap_samples']));
        $blockers=array();
        foreach($fields as $path=>$value) {
            foreach(self::analyze_text($value['text'],$identity) as $found)
                if(($found['confidence']??'')==='high')$blockers[]=array(
                    'id'=>hash('sha256',$path.'|'.$found['candidate']),
                    'source_evidence'=>array('field_path'=>$path,'matched_entity'=>$found['candidate']));
            foreach(self::analyze_links($value['html'],$path,$identity['scope']) as $link)
                if(($link['confidence']??'')==='high')$blockers[]=array(
                    'id'=>hash('sha256',$path.'|'.$link['host']),
                    'source_evidence'=>array('field_path'=>$path,'matched_entity'=>$link['host']));
        }
        if($blockers)return self::blockers(0,$blockers,
            'Proposed content payload contains a high-confidence brand mismatch.');
        return array('contract'=>self::CONTRACT,'allowed'=>true,
            'candidate_fields_checked'=>count($fields));
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
        $report = self::scan_post_report($post_id, $brand, true);
        if (!$report['complete'])
            return new WP_Error('mad4b_brand_integrity_coverage_incomplete',
                'Brand Integrity could not inspect all candidate source fields.',
                array('post_id'=>absint($post_id),'gap_count'=>$report['gap_count'],
                    'gap_samples'=>$report['gap_samples']));
        $issues = $report['issues'];
        $high = array_values(array_filter($issues,
            static fn($issue)=>(string)($issue['severity']??'')==='high'));
        if($high)return self::blockers($post_id,$high,
            'Potential attribution to another company was found in proposed content.');
        return array('contract'=>self::CONTRACT,'allowed'=>true,
            'review_issues'=>count($issues),'post_id'=>absint($post_id));
    }


    /**
     * Read-only term-meta audit for public destination/editorial taxonomies.
     * No auto replacement, publishing, WordPress writes or external fetching.
     */
    public static function scan_term_report($term_id, $brand=null) {
        $term_id=absint($term_id);
        $term=get_term($term_id);
        if (!$term instanceof WP_Term || is_wp_error($term))
            return array('complete'=>false,'issues'=>array(),'field_count'=>0,
                'gap_count'=>1,'gap_samples'=>array(array('reason'=>'term_not_found')));
        $coverage=array('complete'=>true,'gap_count'=>0,'gap_samples'=>array());
        $fields=array();
        self::append_leaf($fields,'term.name',(string)$term->name,0,$coverage);
        self::append_leaf($fields,'term.description',(string)$term->description,0,$coverage);
        foreach ((array)get_term_meta($term_id) as $key=>$values) {
            if (!is_string($key) ||
                preg_match('/(?:password|secret|token|private_key|api_key|nonce|license|cache|session)/i',$key))
                continue;
            if (str_starts_with($key,'_') && $key!=='_description') continue;
            foreach ((array)$values as $index=>$value)
                self::append_leaf($fields,'termmeta.'.$key.'.'.$index,maybe_unserialize($value),0,$coverage);
        }
        if (!is_array($brand)) {
            $brand=class_exists('MAD4B_SCP_Brand_Experience_Quality',false)
                ? MAD4B_SCP_Brand_Experience_Quality::brand_context() : array();
        }
        $identity=self::identity($brand);
        if (empty($identity['scope']['brand_name']))
            self::coverage_gap($coverage,'term','site_identity_missing');
        $issues=array();
        if (!empty($identity['scope']['brand_name'])) {
            foreach ($fields as $path=>$value) {
                foreach (self::analyze_links($value['html'],$path,$identity['scope']) as $link) {
                    $code='brand_external_link_attribution_mismatch';
                    $key=hash('sha256',get_current_blog_id().'|term|'.$term_id.'|'
                        .$code.'|'.$path.'|'.$link['host'].'|'.$link['url_sha256']);
                    $issues[$key]=array(
                        'id'=>$key,'object_type'=>'term','taxonomy'=>(string)$term->taxonomy,
                        'object_id'=>$term_id,'code'=>$code,
                        'severity'=>$link['confidence']==='high'?'high':'medium',
                        'label'=>'External destination term link requires company-domain verification',
                        'source'=>'mad4b-brand-experience',
                        'source_evidence'=>array(
                            'contract'=>self::CONTRACT,
                            'field_path'=>$path,'matched_entity'=>$link['host'],
                            'detection_rule'=>$link['rule'],
                            'evidence_excerpt'=>$link['anchor_text'],
                            'url_sha256'=>$link['url_sha256'],
                            'source_sha256'=>hash('sha256',$value['html'].'|'.$link['url_sha256']),
                            'review_only'=>true,'publication_authorized'=>false,
                            'auto_replacement_authorized'=>false));
                    if (count($issues)>=self::MAX_ISSUES_PER_POST) {
                        self::coverage_gap($coverage,$path,'issue_limit_reached');
                        break 2;
                    }
                }
            }
        }
        return array('contract'=>self::CONTRACT,'complete'=>$coverage['complete'],
            'gap_count'=>$coverage['gap_count'],'gap_samples'=>$coverage['gap_samples'],
            'field_count'=>count($fields),'issues'=>array_values($issues),
            'object_type'=>'term','taxonomy'=>(string)$term->taxonomy,
            'object_id'=>$term_id,'mutation_performed'=>false);
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
        $incomplete = array();
        $gaps=0;
        foreach ($ids as $id) {
            $report=self::scan_post_report($id,$brand);
            foreach($report['issues'] as $issue)$issues[]=$issue;
            if (!$report['complete']) {
                $gaps+=(int)$report['gap_count'];
                if(count($incomplete)<20)$incomplete[]=array(
                    'post_id'=>$id,'gap_count'=>$report['gap_count'],
                    'samples'=>$report['gap_samples']);
            }
        }
        return array('contract'=>self::CONTRACT,
            'complete'=>!$more && $gaps===0,'pagination_complete'=>!$more,
            'coverage_complete'=>$gaps===0,'coverage_gap_count'=>$gaps,
            'incomplete_posts'=>$incomplete,
            'scanned'=>count($ids),'last_id'=>$ids?end($ids):absint($after_id),
            'issues'=>$issues,'site_uuid'=>(string)($brand['scope']['site_uuid']??''));
    }

    public static function discover($cfg=null) {
        // The scan is owned by the existing Content Readiness scheduler.
        // Additional approved post types may register via the site-scoped
        // source provider. A new post beyond the first 500 must never escape
        // detection solely because its ID is later in the inventory.
        // Baseline is company-neutral WordPress editorial content. Additional
        // publicly queryable customer content types come from the site's
        // existing Content Readiness policy and registered CPT definitions,
        // not a tourism-specific type or a global hardcoded business model.
        // Forms, bookings and operational CPTs excluded from search are
        // deliberately not auto-read; an authorized provider may explicitly
        // add other safe display surfaces.
        $base=array('post','page','product','elementor_library');
        $policy=is_array($cfg)?(array)($cfg['post_types']??array()):array();
        if(!$policy && class_exists('MAD4B_SCP_Content_Readiness_Alerts',false))
            $policy=(array)(MAD4B_SCP_Content_Readiness_Alerts::rules()['post_types']??array());
        $visible=array();
        foreach($policy as $entry){
            if(!is_string($entry))continue;
            $type=sanitize_key($entry);
            $obj=post_type_exists($type)?get_post_type_object($type):null;
            if($obj && !empty($obj->public) && !empty($obj->publicly_queryable)
                && empty($obj->exclude_from_search))
                $visible[]=$type;
        }
        $extensions=apply_filters('mad4b/brand_integrity/scannable_post_types',
            array(),$cfg);
        if(!is_array($extensions))$extensions=array();
        $types=array_values(array_unique(array_filter(array_map('sanitize_key',
            array_merge($base,$visible,$extensions)),
            static fn($type)=>post_type_exists($type))));
        $brand=class_exists('MAD4B_SCP_Brand_Experience_Quality',false)
            ? MAD4B_SCP_Brand_Experience_Quality::brand_context():array();
        if(empty($brand['scope']['brand_name']) || empty($brand['scope']['site_uuid']))
            return array('contract'=>self::CONTRACT,'complete'=>false,
                'scanned'=>0,'issues'=>array(),'coverage_reason'=>'brand_context_missing',
                'site_uuid'=>(string)($brand['scope']['site_uuid']??''));
        if(!$types)return array('contract'=>self::CONTRACT,'complete'=>false,
            'scanned'=>0,'issues'=>array(),'coverage_reason'=>'post_types_not_registered',
            'site_uuid'=>(string)$brand['scope']['site_uuid']);
        $after=0;$scanned=0;$issues=array();$gaps=0;$incomplete=array();
        $covered=false;
        for($page=0;$page<self::MAX_DISCOVERY_BATCHES;$page++) {
            $batch=self::scan_batch($types,$after,self::MAX_BATCH);
            $scanned+=(int)$batch['scanned'];
            foreach($batch['issues'] as $issue)$issues[]=$issue;
            $gaps+=(int)($batch['coverage_gap_count']??0);
            foreach((array)($batch['incomplete_posts']??array()) as $record)
                if(count($incomplete)<20)$incomplete[]=$record;
            if(!empty($batch['pagination_complete'])){$covered=true;break;}
            if((int)$batch['last_id']<=$after)break;
            $after=(int)$batch['last_id'];
        }
        return array('contract'=>self::CONTRACT,'complete'=>$covered&&$gaps===0,
            'pagination_complete'=>$covered,'coverage_complete'=>$gaps===0,
            'coverage_gap_count'=>$gaps,'incomplete_posts'=>$incomplete,
            'scanned'=>$scanned,'issues'=>$issues,
            'site_uuid'=>(string)$brand['scope']['site_uuid'],
            'post_types'=>$types,'coverage_reason'=>$covered?'':'pagination_budget_exhausted');
    }
}
