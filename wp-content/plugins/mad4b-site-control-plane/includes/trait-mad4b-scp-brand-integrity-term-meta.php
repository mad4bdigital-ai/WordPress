<?php
/**
 * Read-only Term Meta and destination taxonomy integrity.
 * Exact extracted methods from MAD4B_SCP_Brand_Integrity; no behavioral changes.
 * This is a Trait owned by the original final class, not a new global writer.
 */
defined('ABSPATH') || exit;

trait MAD4B_SCP_Brand_Integrity_Term_Meta_Trait {
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



    /**
     * Enumerate stored term-media origins separately from external hyperlinks.
     * This is an immutable audit only: a foreign hostname is not evidence
     * that an attachment is missing, or that replacing it is authorized.
     */
    public static function scan_term_media_origin_report($term_id,$brand=null) {
        $term_id=absint($term_id);
        $term=get_term($term_id);
        if(!($term instanceof WP_Term) || is_wp_error($term))
            return array('complete'=>false,'coverage_reason'=>'term_not_found',
                'mutation_performed'=>false);
        if(!is_array($brand))
            $brand=class_exists('MAD4B_SCP_Brand_Experience_Quality',false)
                ? MAD4B_SCP_Brand_Experience_Quality::brand_context():array();
        if(empty($brand['scope']['site_uuid']) || empty($brand['scope']['brand_name']))
            return array('complete'=>false,'coverage_reason'=>'site_identity_missing',
                'mutation_performed'=>false);
        $meta=get_term_meta($term_id); // aggregate raw values, not the key-specific display override
        if(!is_array($meta))
            return array('complete'=>false,'coverage_reason'=>'term_meta_unavailable',
                'mutation_performed'=>false);
        $site_host=strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST));
        $site_host=(string)preg_replace('/^www\./i','',$site_host);
        if($site_host==='')return array('complete'=>false,
            'coverage_reason'=>'site_host_unavailable','mutation_performed'=>false);
        $uploads=wp_get_upload_dir();
        $base=!empty($uploads['error']) ? false : realpath((string)($uploads['basedir']??''));
        $fields=array('featured_image','image_gellery','image_gallery','gallery_images');
        $total=0;$foreign=0;$local=0;$missing=0;$unchecked=0;
        $samples=array();$gaps=0;
        foreach($fields as $key) {
            foreach((array)($meta[$key]??array()) as $entry) {
                $values=array(maybe_unserialize($entry));
                for($step=0;$step<count($values) && $step<300;$step++) {
                    $value=$values[$step];
                    if(is_array($value)) {
                        foreach($value as $part)$values[]=$part;
                        if(count($values)>300){$gaps++;break;}
                        continue;
                    }
                    if(!is_string($value) || $value==='')continue;
                    if(strlen($value)>self::MAX_FIELD_BYTES){$gaps++;continue;}
                    $json=json_decode($value,true);
                    if(is_array($json)){foreach($json as $part)$values[]=$part;continue;}
                    // A JetEngine gallery may store CSV URLs; a comma within
                    // a filename is not a delimiter unless the next token is http(s).
                    $tokens=preg_split('~,\s*(?=https?://)~i',$value);
                    foreach((array)$tokens as $token) {
                        $url=trim((string)$token);
                        if(!preg_match('~^https?://~i',$url))continue;
                        $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
                        if($host==='')continue;
                        if($total>=300){$gaps++;break 3;}
                        $total++;
                        $host=(string)preg_replace('/^www\./i','',$host);
                        $is_foreign=$host!==$site_host;
                        $foreign+=(int)$is_foreign;
                        $path=(string)wp_parse_url($url,PHP_URL_PATH);
                        $prefix='/wp-content/uploads/';
                        $relative=str_starts_with($path,$prefix)
                            ? rawurldecode(substr($path,strlen($prefix))):'';
                        $parts=explode('/',$relative);
                        $safe=$relative!==''&&!str_contains($relative,"\0")
                            &&!in_array('..',$parts,true)&&!in_array('.',$parts,true);
                        $candidate=($safe && $base)
                            ? realpath(rtrim($base,'/').'/'.$relative):false;
                        $present=boolval($base && $candidate && is_file($candidate)
                            && str_starts_with($candidate,rtrim($base,'/').DIRECTORY_SEPARATOR));
                        if(!$base)$unchecked++;
                        elseif($present)$local++;
                        else $missing++;
                        if(($is_foreign || !$present) && count($samples)<20) {
                            $samples[]=array('term_id'=>$term_id,
                                'field'=>$key,'stored_host'=>$host,
                                'url_sha256'=>hash('sha256',$url),
                                'origin_mismatch'=>$is_foreign,
                                'local_file'=>!$base?'unchecked':($present?'present':'missing'),
                                'auto_replacement_authorized'=>false);
                        }
                    }
                }
            }
        }
        return array('contract'=>self::CONTRACT.'.term-media-origins.v1',
            'complete'=>$gaps===0,'gap_count'=>$gaps,
            'term_id'=>$term_id,'taxonomy'=>(string)$term->taxonomy,
            'site_uuid'=>(string)$brand['scope']['site_uuid'],
            'media_urls_scanned'=>$total,'foreign_origin_count'=>$foreign,
            'local_file_present_count'=>$local,'local_file_missing_count'=>$missing,
            'local_file_unchecked_count'=>$unchecked,
            'samples'=>$samples,'read_only'=>true,
            'mutation_performed'=>false);
    }

    /** Bounded, source-only analysis of one JetEngine/WordPress term meta value. */
    private static function term_value_report($term_id,$key,$raw,array $brand) {
        $coverage=array('complete'=>true,'gap_count'=>0,'gap_samples'=>array());
        $fields=array();
        self::append_leaf($fields,'termmeta.'.$key.'.0',maybe_unserialize($raw),0,$coverage);
        $issues=array();
        foreach($fields as $path=>$leaf) {
            foreach(self::analyze_links($leaf['html'],$path,(array)$brand['scope']) as $link) {
                $id=hash('sha256',get_current_blog_id().'|term|'.$term_id.'|'.$path.'|'.$link['url_sha256']);
                $issues[$id]=array('id'=>$id,
                    'severity'=>$link['confidence']==='high'?'high':'medium',
                    'source_evidence'=>array('field_path'=>$path,
                        'matched_entity'=>$link['host'],
                        'source_sha256'=>hash('sha256',$leaf['html'].'|'.$link['url_sha256'])));
                if(count($issues)>=self::MAX_ISSUES_PER_POST) {
                    self::coverage_gap($coverage,$path,'issue_limit_reached');
                    break 2;
                }
            }
        }
        return array('complete'=>$coverage['complete'],
            'gap_count'=>$coverage['gap_count'],'gap_samples'=>$coverage['gap_samples'],
            'fields_scanned'=>count($fields),'issues'=>array_values($issues));
    }


    /**
     * Real WordPress wp_kses_post() shortens root-relative links with unencoded
     * colon path segments (JetSmartFilters archive examples collapse to
     * "cairo/"). Audit new candidate anchors BEFORE the editor applies KSES.
     * We fingerprint only the offending href and field, not private content.
     * This is a governed term write preflight, never a global save hook.
     */
    private static function unstable_relative_anchor_hrefs($value, $meta_key) {
        $fields=array();
        $coverage=array('complete'=>true,'gap_count'=>0,'gap_samples'=>array());
        self::append_leaf($fields,'termmeta.'.$meta_key.'.0',maybe_unserialize($value),0,$coverage);
        $found=array();
        foreach($fields as $field_path=>$leaf) {
            if(!preg_match_all('~<a\b[^>]*\bhref\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))~iu',
                    (string)$leaf['html'],$links,PREG_SET_ORDER))continue;
            foreach(array_slice($links,0,80) as $link) {
                $href=html_entity_decode((string)($link[1]?:($link[2]?:($link[3]??''))),
                    ENT_QUOTES|ENT_HTML5,'UTF-8');
                if(!str_starts_with($href,'/') || str_starts_with($href,'//'))continue;
                $path=explode('?',explode('#',$href,2)[0],2)[0];
                if(!preg_match('~/(?:[^/?#]+:[^/?#]+)~',$path))continue;
                $fingerprint=hash('sha256',$field_path.'|'.$href);
                $found[$fingerprint]=array('field_path'=>$field_path,
                    'href_sha256'=>hash('sha256',$href));
            }
        }
        return $found;
    }

    /**
     * Governed Content Job / JetEngine adapters can call this BEFORE writing.
     * No global metadata filter: WPML and editor saves remain untouched.
     * Historical unchanged findings are allowed so content can be repaired.
     */
    public static function term_meta_candidate_preflight($term_id,$key,$candidate,$brand=null) {
        $term_id=absint($term_id);
        $term=get_term($term_id);
        if(!($term instanceof WP_Term) || is_wp_error($term))
            return new WP_Error('mad4b_term_link_invalid_term','Taxonomy term unavailable.');
        if(!is_string($key) || $key==='' || strlen($key)>191)
            return new WP_Error('mad4b_term_link_invalid_field','Invalid term meta key.');
        if((str_starts_with($key,'_') && $key!=='_description') ||
            preg_match('/(?:password|secret|token|private_key|api_key|nonce|license|cache|session)/i',$key))
            return array('contract'=>self::CONTRACT,'allowed'=>true,
                'skipped_operational_field'=>true,'mutation_performed'=>false);
        if(!is_array($brand))
            $brand=class_exists('MAD4B_SCP_Brand_Experience_Quality',false)
                ? MAD4B_SCP_Brand_Experience_Quality::brand_context():array();
        if(empty($brand['scope']['brand_name']) || empty($brand['scope']['site_uuid']))
            return new WP_Error('mad4b_term_link_identity_missing','Site brand context missing.');
        $before=self::term_value_report($term_id,$key,get_term_meta($term_id,$key,true),$brand);
        $after=self::term_value_report($term_id,$key,$candidate,$brand);
        if(!$before['complete'] || !$after['complete'])
            return new WP_Error('mad4b_term_link_coverage_incomplete',
                'Term field coverage incomplete.',array('term_id'=>$term_id,'meta_key'=>$key));

        // Compare with the current value so an unchanged legacy link never
        // prevents an operator from repairing that term. New KSES-unstable
        // relative anchors must fail before any governed metadata write.
        $existing_unsafe=self::unstable_relative_anchor_hrefs(
            get_term_meta($term_id,$key,true),$key);
        $candidate_unsafe=self::unstable_relative_anchor_hrefs($candidate,$key);
        $introduced_unsafe=array_diff_key($candidate_unsafe,$existing_unsafe);
        if($introduced_unsafe)
            return new WP_Error('mad4b_term_link_relative_href_unstable',
                'The proposed root-relative link changes when WordPress sanitizes HTML. Use a canonical first-party destination URL.',
                array('term_id'=>$term_id,'taxonomy'=>(string)$term->taxonomy,
                    'meta_key'=>$key,'unsafe_links'=>array_values($introduced_unsafe),
                    'auto_replacement_authorized'=>false));
        $new=self::newly_introduced_high($before,$after);
        if($new)
            return new WP_Error('mad4b_term_link_external_promotional_blocked',
                'Proposed term content includes a new external promotional link.',
                array('term_id'=>$term_id,'taxonomy'=>(string)$term->taxonomy,
                    'new_high_confidence_issues'=>count($new),
                    'hosts'=>array_values(array_unique(array_map(
                        static fn($x)=>(string)$x['source_evidence']['matched_entity'],$new))),
                    'auto_replacement_authorized'=>false));
        return array('contract'=>self::CONTRACT,'allowed'=>true,
            'term_id'=>$term_id,'taxonomy'=>(string)$term->taxonomy,
            'candidate_fields_checked'=>$after['fields_scanned'],
            'historic_issues'=>count($before['issues']),
            'review_issues'=>count(array_filter($after['issues'],
                static fn($i)=>$i['severity']==='medium')),
            'mutation_performed'=>false);
    }

    /**
     * Read-only, paginated taxonomy census. Enumerate explicit PUBLIC taxonomies;
     * return next_offset to resume. suppress_filter requests all language IDs,
     * never assuming the current WPML language is the complete inventory.
     */
    public static function scan_term_batch(array $taxonomies,$offset=0,$limit=100,$brand=null) {
        $requested=array_values(array_unique(array_filter(array_map('sanitize_key',$taxonomies))));
        $valid=array();
        foreach($requested as $tax) {
            if(!taxonomy_exists($tax)) continue;
            $obj=get_taxonomy($tax);
            if($obj && !empty($obj->public)) $valid[]=$tax;
        }
        if(!$valid || count($valid)!==count($requested))
            return array('contract'=>self::CONTRACT,'complete'=>false,'scanned'=>0,
                'issues'=>array(),'coverage_reason'=>'invalid_or_private_taxonomy',
                'mutation_performed'=>false);
        if(!is_array($brand))
            $brand=class_exists('MAD4B_SCP_Brand_Experience_Quality',false)
                ? MAD4B_SCP_Brand_Experience_Quality::brand_context():array();
        if(empty($brand['scope']['brand_name']) || empty($brand['scope']['site_uuid']))
            return array('contract'=>self::CONTRACT,'complete'=>false,'scanned'=>0,
                'issues'=>array(),'coverage_reason'=>'brand_context_missing',
                'mutation_performed'=>false);
        $offset=max(0,(int)$offset);
        $limit=min(self::MAX_BATCH,max(1,(int)$limit));
        $ids=get_terms(array('taxonomy'=>$valid,'fields'=>'ids','hide_empty'=>false,
            'number'=>$limit+1,'offset'=>$offset,'orderby'=>'term_id',
            'order'=>'ASC','suppress_filter'=>true));
        if(is_wp_error($ids) || !is_array($ids))
            return array('contract'=>self::CONTRACT,'complete'=>false,'scanned'=>0,
                'issues'=>array(),'coverage_reason'=>'term_query_failed',
                'mutation_performed'=>false);
        $more=count($ids)>$limit;
        if($more)$ids=array_slice($ids,0,$limit);
        $issues=array();$gaps=0;$incomplete=array();
        foreach($ids as $entry) {
            $id=absint($entry instanceof WP_Term?$entry->term_id:$entry);
            $r=self::scan_term_report($id,$brand);
            foreach((array)($r['issues']??array()) as $issue)$issues[]=$issue;
            if(empty($r['complete'])) {
                $gaps+=(int)($r['gap_count']??1);
                if(count($incomplete)<20)$incomplete[]=array('term_id'=>$id,
                    'gap_samples'=>$r['gap_samples']??array());
            }
        }
        return array('contract'=>self::CONTRACT,'complete'=>!$more && $gaps===0,
            'pagination_complete'=>!$more,'coverage_complete'=>$gaps===0,
            'coverage_gap_count'=>$gaps,'incomplete_terms'=>$incomplete,
            'scanned'=>count($ids),'next_offset'=>$offset+count($ids),
            'taxonomies'=>$valid,'issues'=>$issues,
            'site_uuid'=>(string)$brand['scope']['site_uuid'],
            'mutation_performed'=>false);
    }

}
