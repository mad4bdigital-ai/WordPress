<?php
/**
 * Plugin Name: MAD4B Scoped Native Listing Layout Epoch Continuity
 * Version: 1.2.2
 * Description: Self-reenroll original browser layout proofs across unrelated CSS epochs only if every tracked style and Elementor dependency remains byte-identical; otherwise fail closed to SSR.
 */
defined('ABSPATH') || exit;

final class MAD4B_Native_Layout_Epoch_Continuity_V1 {
    const BASELINE='mad4b_secondary_grid_lazy_style_identity_v1';
    const BASELINE_V2='mad4b_secondary_grid_lazy_geometry_identity_v2';
    const CONTRACT_V2='mad4b.native-listing.geometry-dependency-identity.v2';
    const RECEIPT='mad4b_secondary_grid_lazy_last_epoch_renewal_v1';
    const PROOF='mad4b_secondary_grid_lazy_layout_proofs_v1';
    const CONTRACT='mad4b.native-listing.scoped-css-identity-epoch-renewal.v1';
    public static function proof_digest(array $registry):string {
        unset($registry['css_epoch']);
        return hash('sha256',serialize($registry));
    }
    // Preserve original browser geometry and every unknown field. Query
    // attestations have their own native runtime gate and must not rotate
    // the independent layout identity.
    public static function geometry_digest(array $registry):string {
        unset($registry['css_epoch'],$registry['last_epoch_transition']);
        if(isset($registry['entries']) && is_array($registry['entries'])){
            foreach($registry['entries'] as &$entry){
                if(is_array($entry))unset($entry['ajax_parity_by_market']);
            }
            unset($entry);
            ksort($registry['entries']);
        }
        return hash('sha256',serialize($registry));
    }
    private static function known_parent_sources(array $registry,array $baseline):bool {
        $docs=$baseline['elementor_docs']??[];
        if(!is_array($docs))return false;
        foreach(($registry['entries']??[]) as $entry){
            if(!is_array($entry))return false;
            foreach(($entry['ajax_parity_by_market']??[]) as $proof){
                $id=(int)($proof['document_id']??0);
                if(!$id || !isset($docs[$id]) ||
                    !hash_equals((string)$docs[$id],(string)($proof['document_sha256']??'')))return false;
            }
        }
        return true;
    }
    public static function matches(array $registry,array $baseline,?int $new_epoch=null):bool {
        $v2=($baseline['contract']??'')===self::CONTRACT_V2;
        if((!$v2 && ($baseline['contract']??'')!==self::CONTRACT)
            || ($registry['contract']??'')!=='mad4b.native-listing.layout-proof.v1'
            || ($baseline['site_host']??'')!==(string)wp_parse_url(home_url('/'),PHP_URL_HOST)
            || ($registry['site_host']??'')!==$baseline['site_host']
            || !isset($baseline['enrolled_css_epoch'],$baseline['registry_sha256'])
            || (int)$baseline['enrolled_css_epoch']<=0
            || (int)$baseline['enrolled_css_epoch']>(int)($registry['css_epoch']??0)
            || (!$v2 && !hash_equals((string)$baseline['registry_sha256'],self::proof_digest($registry)))
            || ($v2 && (!hash_equals((string)($baseline['geometry_sha256']??''),self::geometry_digest($registry))
                || !self::known_parent_sources($registry,$baseline)))
            || (int)($registry['mobile_breakpoint']??0)!==(int)($baseline['mobile_breakpoint']??-1))return false;
        $current=$new_epoch??(int)get_option('mad4b_css_ai_epoch_v1',0);
        if($current<(int)$baseline['enrolled_css_epoch'] || $current<(int)($registry['css_epoch']??0))return false;
        if((string)get_stylesheet()!==(string)($baseline['theme_stylesheet']??'')
            || (string)get_template()!==(string)($baseline['theme_template']??'')
            || (int)get_option('elementor_active_kit',0)!==(int)($baseline['kit_id']??0))return false;
        $settings=get_post_meta((int)$baseline['kit_id'],'_elementor_page_settings',true);
        if(!hash_equals((string)($baseline['kit_settings_sha256']??''),hash('sha256',maybe_serialize($settings))))return false;
        if(!hash_equals((string)($baseline['wp_custom_css_sha256']??''),hash('sha256',(string)wp_get_custom_css())))return false;
        if(!hash_equals((string)($baseline['theme_mods_sha256']??''),hash('sha256',maybe_serialize(get_theme_mods()))))return false;
        if(!hash_equals((string)($baseline['astra_options_sha256']??''),hash('sha256',maybe_serialize(get_option('astra-settings',[])))))return false;
        $docs=$baseline['elementor_docs']??null;
        if(!is_array($docs) || count($docs)<6 || count($docs)>32)return false;
        foreach($docs as $document=>$expected) {
            $id=absint($document);
            if(!$id || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D',$expected)
                || !hash_equals($expected,hash('sha256',(string)get_post_meta($id,'_elementor_data',true))))return false;
        }
        $files=$baseline['css_dependencies']??null;
        if(!is_array($files) || count($files)<14 || count($files)>55)return false;
        foreach($files as $relative=>$expected){
            if(!is_string($relative) || strlen($relative)>180 || str_contains($relative,'..')
                || !preg_match('~\A(?:litespeed/css/|uploads/elementor/css/|mu-plugins/(?:assets/)?|themes/)[a-zA-Z0-9_./-]+\.(?:css|php)\z~D',$relative)
                || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D',$expected))return false;
            $full=WP_CONTENT_DIR.'/'.$relative;
            if(is_link($full) || !is_file($full) || @filesize($full)<100
                || @filesize($full)>8388608 || !hash_equals($expected,(string)@hash_file('sha256',$full)))return false;
        }
        return true;
    }
    // V2 is derived only from an existing valid site-local V1 baseline.
    // Never enroll from a stale proof plus today's unobserved dependencies.
    public static function derive_geometry_baseline(array $registry,array $legacy,?int $epoch=null):array {
        if(($legacy['contract']??'')!==self::CONTRACT ||
            !self::matches($registry,$legacy,$epoch) ||
            !self::known_parent_sources($registry,$legacy))return [];
        $next=$legacy;
        $next['contract']=self::CONTRACT_V2;
        $next['geometry_sha256']=self::geometry_digest($registry);
        $next['legacy_baseline_sha256']=hash('sha256',serialize($legacy));
        return $next;
    }
    // Each family is a site-local listing, locale and original parent document.
    // A source-only record is NOT a visual certificate and cannot admit lazy AJAX.
    const FAMILY_PREFIX='mad4b_layout_family_v1_';
    public static function family_key(int $listing,string $locale,int $document):string {
        return self::FAMILY_PREFIX.substr(hash('sha256',(string)wp_parse_url(home_url('/'),PHP_URL_HOST).'|'.$listing.'|'.$locale.'|'.$document),0,40);
    }
    public static function family_source(array $cohort):array {
        $listing=absint($cohort['listing_id']??0);
        $doc=absint($cohort['document_id']??0);
        $page=absint($cohort['page_id']??0);
        $locale=(string)($cohort['locale']??'');
        $kit=absint(get_option('elementor_active_kit',0));
        if(!$listing||!$doc||!$page||!$kit||!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',$locale))return [];
        $documents=[];$files=[];$visited=[];
        $scan=static function(int $id,int $depth=0)use(&$scan,&$documents,&$files,&$visited):bool {
            if(!$id || $depth>8 || count($visited)>64)return false;
            if(isset($visited[$id]))return true;
            $visited[$id]=true;
            $raw=get_post_meta($id,'_elementor_data',true);
            if(!is_string($raw)||strlen($raw)<2||strlen($raw)>3000000)return false;
            $nodes=json_decode($raw,true);
            if(!is_array($nodes))return false;
            $documents[$id]=hash('sha256',$raw);
            $path='uploads/elementor/css/post-'.$id.'.css';
            $full=WP_CONTENT_DIR.'/'.$path;
            if(is_link($full))return false;
            if(is_file($full)){
                if(filesize($full)>8388608)return false;
                $h=hash_file('sha256',$full);if(!$h)return false;
                $files[$path]=$h;
            }
            $walk=static function(array $nodes)use(&$walk,&$scan,$depth):bool {
                foreach($nodes as $node){
                    if(!is_array($node))continue;
                    $settings=(array)($node['settings']??[]);
                    if(($node['widgetType']??'')==='template'){
                        $ref=absint($settings['template_id']??0);
                        if(!$ref||!$scan($ref,$depth+1))return false;
                    }
                    if(isset($node['elements'])&&is_array($node['elements'])&&!$walk($node['elements']))return false;
                }
                return true;
            };
            return $walk($nodes);
        };
        foreach(array_unique([$page,$doc,$listing]) as $id)if(!$scan($id))return [];
        $listingCSS='uploads/elementor/css/post-'.$listing.'.css';
        if(!isset($files[$listingCSS]))return [];
        foreach(['themes/'.get_stylesheet().'/style.css','themes/'.get_template().'/style.css',
            'mu-plugins/mad4b-secondary-listing-lazy.php'] as $rel){
            $full=WP_CONTENT_DIR.'/'.$rel;
            if(is_link($full)||!is_file($full)||filesize($full)>8388608)return [];
            $h=hash_file('sha256',$full);if(!$h)return [];
            $files[$rel]=$h;
        }
        ksort($documents);ksort($files);
        $plugins=(array)get_option('active_plugins',[]);sort($plugins);
        $settings=get_post_meta($kit,'_elementor_page_settings',true);
        return ['site_host'=>(string)wp_parse_url(home_url('/'),PHP_URL_HOST),'locale'=>$locale,
            'page_id'=>$page,'document_id'=>$doc,'listing_id'=>$listing,'kit_id'=>$kit,
            'kit_settings_sha256'=>hash('sha256',maybe_serialize($settings)),
            'mobile_breakpoint'=>(int)($settings['viewport_mobile']??0),
            'theme_mods_sha256'=>hash('sha256',maybe_serialize(get_theme_mods())),
            'astra_options_sha256'=>hash('sha256',maybe_serialize(get_option('astra-settings',[]))),
            'wp_custom_css_sha256'=>hash('sha256',(string)wp_get_custom_css()),
            'active_plugins_sha256'=>hash('sha256',serialize($plugins)),
            'documents'=>$documents,'files'=>$files];
    }
    private static function family_geometry(array $registry,int $listing,string $locale):array {
        $result=[];
        foreach(['mobile','wide'] as $band){
            $entry=$registry['entries'][$listing.'|'.$locale.'|'.$band]??null;
            if(!is_array($entry)||($entry['status']??'')!=='pass')return [];
            unset($entry['ajax_parity_by_market']);
            $result[$band]=$entry;
        }
        return $result;
    }
    // JSON transports may reorder associative keys without changing source.
    // Preserve list ordering and scalar types; never equate changed values.
    public static function canonical_source(array $source):array {
        if(!array_is_list($source))ksort($source);
        foreach($source as &$v)if(is_array($v))$v=self::canonical_source($v);
        unset($v);
        return $source;
    }
    // Called only after the original issuer's successful native readback.
    // The pre/post source digest must agree; incomplete source inventories
    // are observations, not authority to copy a CSS epoch or a signed proof.
    public static function record_native_admission(array $cohort,array $before,array $registry):bool {
        $listing=absint($cohort['listing_id']??0);$doc=absint($cohort['document_id']??0);
        $locale=(string)($cohort['locale']??'');$market=(string)($cohort['market']??'');
        $generation=(string)($cohort['query_generation']??'');$query=absint($cohort['query_id']??0);
        $now=self::family_source($cohort);
        if(!$before||!$now||self::canonical_source($before)!==self::canonical_source($now)||($registry['site_host']??'')!==$now['site_host'])return false;
        $geo=self::family_geometry($registry,$listing,$locale);if(!$geo)return false;
        $evidence=[];
        foreach(['mobile','wide'] as $band){
            $p=$registry['entries'][$listing.'|'.$locale.'|'.$band]['ajax_parity_by_market'][$market]??[];
            if(($p['status']??'')!=='pass'||($p['query_generation']??'')!==$generation||
                (int)($p['query_id']??0)!==$query||(int)($p['document_id']??0)!==$doc||
                !preg_match('/^[a-f0-9]{64}$/D',(string)($p['browser_evidence_sha256']??'')))return false;
            $evidence[$band]=$p['browser_evidence_sha256'];
        }
        $key=self::family_key($listing,$locale,$doc);
        $row=['contract'=>'mad4b.native-listing.family-source-receipt.v1',
            'source'=>$now,'source_sha256'=>hash('sha256',serialize($now)),
            'geometry_sha256'=>hash('sha256',serialize($geo)),'browser_evidence'=>$evidence,
            'observed_epoch'=>(int)($registry['css_epoch']??0),'observed_at'=>time(),
            'visual_dependency_complete'=>false,'decision'=>'original_native_admission_source_recorded'];
        update_option($key,$row,false);
        return get_option($key,[])===$row;
    }
    public static function assess_family(array $cohort):array {
        $listing=absint($cohort['listing_id']??0);$doc=absint($cohort['document_id']??0);
        $locale=(string)($cohort['locale']??'');
        $row=get_option(self::family_key($listing,$locale,$doc),[]);
        $current=self::family_source($cohort);
        $match=!empty($current)&&is_array($row)&&isset($row['source'])&&$row['source']===$current;
        return ['family_key'=>self::family_key($listing,$locale,$doc),'source_available'=>!empty($current),
            'source_same'=>$match,'source_documents'=>count($current['documents']??[]),'source_files'=>count($current['files']??[]),
            'decision'=>$match?'source_unchanged_reuse_ssr':(empty($row)?'initial_source_observation_required':'family_source_changed'),
            'native_lazy_qualification'=>'independent_original_gate',
            'requires_chromium_for_ssr'=>false];
    }
    private static function family_registry(array $registry,int $listing,string $locale):array {
        $subset=$registry;
        $subset['entries']=[];
        foreach(['mobile','wide'] as $band){
            $key=$listing.'|'.$locale.'|'.$band;
            if(!isset($registry['entries'][$key]))return [];
            $subset['entries'][$key]=$registry['entries'][$key];
        }
        return $subset;
    }
    // Enroll only from a site-local, already complete verified baseline.
    // A new browser certificate without a complete historical dependency
    // manifest is tracked separately and does not inherit this authority.
    public static function enroll_verified_family(array $cohort,array $registry,array $manifest):bool {
        $listing=absint($cohort['listing_id']??0);$doc=absint($cohort['document_id']??0);
        $locale=(string)($cohort['locale']??'');
        $now=self::family_source($cohort);
        if(!$now||!self::matches($registry,$manifest)||!self::known_parent_sources($registry,$manifest))return false;
        $subset=self::family_registry($registry,$listing,$locale);
        if(!$subset||!self::family_geometry($subset,$listing,$locale))return false;
        $next=$manifest;
        $next['contract']=self::CONTRACT_V2;
        $next['geometry_sha256']=self::geometry_digest($subset);
        $next['parent_manifest_sha256']=hash('sha256',serialize($manifest));
        $next['family_source_sha256']=hash('sha256',serialize($now));
        if(!self::matches($subset,$next))return false;
        $key=self::family_key($listing,$locale,$doc).'_verified';
        $row=['contract'=>'mad4b.native-listing.verified-family.v1','source'=>$now,'manifest'=>$next];
        update_option($key,$row,false);
        return get_option($key,[])===$row;
    }
    // On a new global epoch, keep only each independently verified family.
    // Market and signed query evidence are never rewritten by this decision.
    public static function can_retain_family(array $registry,string $entryKey,int $epoch):bool {
        $entry=$registry['entries'][$entryKey]??null;
        if(!is_array($entry)||($entry['status']??'')!=='pass')return false;
        $listing=absint($entry['listing_id']??0);$locale=(string)($entry['locale']??'');
        $subset=self::family_registry($registry,$listing,$locale);
        if(!$subset||!self::family_geometry($subset,$listing,$locale))return false;
        $parents=[];
        foreach($subset['entries'] as $e)foreach(($e['ajax_parity_by_market']??[]) as $proof){
            $id=absint($proof['document_id']??0);if(!$id)return false;$parents[$id]=true;
        }
        if(!$parents)return false;
        foreach(array_keys($parents) as $doc){
            $row=get_option(self::family_key($listing,$locale,$doc).'_verified',[]);
            if(($row['contract']??'')!=='mad4b.native-listing.verified-family.v1'||
                !is_array($row['source']??null)||!is_array($row['manifest']??null))return false;
            $now=self::family_source($row['source']);
            if(!$now||$now!==$row['source']||!self::matches($subset,$row['manifest'],$epoch))return false;
        }
        return true;
    }
    public static function selective_registry(array $original,int $epoch):array {
        if($epoch<=(int)($original['css_epoch']??0))return [];
        $retained=[];$held=[];$next=$original;
        foreach(($original['entries']??[]) as $key=>$entry){
            if(!is_array($entry))return [];
            if(self::can_retain_family($original,(string)$key,$epoch))$retained[]=$key;
            else{
                $held[]=$key;
                $next['entries'][$key]['status']='hold_css_epoch_rollover';
                foreach(($entry['ajax_parity_by_market']??[]) as $market=>$proof)
                    if(is_array($proof))$next['entries'][$key]['ajax_parity_by_market'][$market]['status']='hold_css_epoch_rollover';
            }
        }
        if(!$retained)return [];
        $next['css_epoch']=$epoch;
        $next['last_epoch_transition']=['from'=>(int)$original['css_epoch'],'to'=>$epoch,
            'decision'=>'retain_verified_families_hold_changed','retained'=>$retained,'held'=>$held,'at'=>gmdate('c')];
        return $next;
    }
    // Functional receipts are read from their own source-bound store.
    // They may trigger the ORIGINAL continuity mechanism; they never
    // supply a visual certificate, market attestation or a new CLS value.
    public static function http_requalification_status(array $cohort):array {
        $host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
        $locale=(string)($cohort['locale']??'');
        $listing=absint($cohort['listing_id']??0);
        $doc=absint($cohort['document_id']??0);
        $page=absint($cohort['page_id']??0);
        $query=absint($cohort['query_id']??0);
        $out=['functional_current'=>false,'native_gate'=>false,'visual_identity_current'=>false,
              'decision'=>'functional_observation_missing','native_certificate_written'=>false];
        if(!$listing||!$doc||!$page||!$query||$locale!==(string)apply_filters('wpml_current_language',''))return $out;
        $key='mad4b_layout_http_observation_v1_'.substr(hash('sha256',$host.'|'.$locale.'|'.$listing.'|'.$doc.'|'.$query.'|'.$page),0,40);
        $receipt=get_option($key,[]);
        $http=$receipt['http']??[];
        $at=(int)($receipt['observed_at']??0);
        if(($receipt['contract']??'')!=='mad4b.native-listing.functional-observation.v1'||
            ($receipt['authority']??'')!=='functional_only_no_native_qualification'||
            ($receipt['site_host']??'')!==$host||($receipt['source']['locale']??'')!==$locale||
            ($http['signed_request_accepted']??false)!==true||($http['ordered_semantics_match']??false)!==true||
            (int)($http['ssr_count']??0)<1||(int)($http['ssr_count']??0)!==(int)($http['ajax_count']??-1)||
            ($http['ssr_sha256']??null)!==($http['ajax_sha256']??null)||
            !preg_match('/^[a-f0-9]{64}$/D',(string)($http['ssr_sha256']??''))||
            $at<time()-DAY_IN_SECONDS||$at>time()+120)return $out;
        $generation=function_exists('mad4b_render_query_generation_for_id')?mad4b_render_query_generation_for_id($query):'';
        $source=self::family_source($cohort);
        $epoch=(int)get_option('mad4b_css_ai_epoch_v1',0);
        if(!$source||$generation===''||$generation!==($receipt['query_generation']??null)||
           !hash_equals((string)($receipt['source_sha256']??''),hash('sha256',serialize(self::canonical_source($source))))||
           (string)get_option('mad4b_render_query_data_generation','initial')!==($receipt['global_data_revision']??null)){
            $out['decision']='functional_source_or_generation_changed';return $out;
        }
        $out['functional_current']=true;
        $out['native_gate']=function_exists('mad4b_secondary_grid_lazy_ajax_parity_proven')&&
           mad4b_secondary_grid_lazy_ajax_parity_proven($query,$listing,$doc);
        $registry=get_option(self::PROOF,[]);
        $manifest=get_option(self::BASELINE_V2,[]);
        if(empty($manifest))$manifest=get_option(self::BASELINE,[]);
        $out['visual_identity_current']=is_array($registry)&&is_array($manifest)&&(
            self::matches($registry,$manifest,$epoch)||
            self::can_retain_family($registry,$listing.'|'.$locale.'|mobile',$epoch));
        if($out['native_gate'])$out['decision']='native_admitted_functional_current';
        elseif($out['visual_identity_current'])$out['decision']='original_continuity_eligible';
        else $out['decision']='functional_current_visual_authority_pending';
        return $out;
    }
    public static function inspect():array {
        $registry=get_option(self::PROOF,[]);
        $legacy=get_option(self::BASELINE,[]);
        $v2=get_option(self::BASELINE_V2,[]);
        $epoch=(int)get_option('mad4b_css_ai_epoch_v1',0);
        $manifest=$v2?:$legacy;
        $rows=[];
        foreach(($registry['entries']??[]) as $key=>$entry){
            $id=absint($entry['listing_id']??0);
            $f=WP_CONTENT_DIR.'/uploads/elementor/css/post-'.$id.'.css';
            $rows[$key]=[
                'listing_source_same'=>$id>0 && hash_equals((string)($entry['listing_source_sha256']??''),hash('sha256',(string)get_post_meta($id,'_elementor_data',true))),
                'listing_css_same'=>$id>0 && !is_link($f) && is_file($f) && hash_equals((string)($entry['listing_css_sha256']??''),(string)@hash_file('sha256',$f)),
                'historical_layout_status'=>$entry['status']??'unknown',
            ];
        }
        $same=!empty($manifest) && is_array($registry) && is_array($manifest) && self::matches($registry,$manifest,$epoch);
        return [
            'site_host'=>(string)wp_parse_url(home_url('/'),PHP_URL_HOST),
            'current_epoch'=>$epoch,'proof_epoch'=>(int)($registry['css_epoch']??0),
            'baseline_contract'=>$manifest['contract']??null,
            'geometry_dependencies_verified'=>$same,
            'decision'=>$same?'reuse_geometry_without_browser':(empty($manifest)?'historical_dependency_baseline_missing':'dependency_identity_requires_review'),
            'query_validation'=>'independent_native_gate_unchanged',
            'entries'=>$rows,
        ];
    }
    public static function on_front_page():void {
        if(defined('WP_CLI')&&WP_CLI)return;
        if(is_admin() || wp_doing_ajax() || is_user_logged_in() || !is_front_page())return;
        self::renew(false);
    }
    public static function renew(bool $allow_migration=true):void {
        $original=get_option(self::PROOF,[]);
        $manifest=get_option(self::BASELINE_V2,[]);
        if(!is_array($original))return;
        if(empty($manifest)){
            $manifest=get_option(self::BASELINE,[]);
            if($allow_migration && is_array($manifest)){
                $derived=self::derive_geometry_baseline($original,$manifest);
                if($derived && add_option(self::BASELINE_V2,$derived,'',false))$manifest=$derived;
                else if($derived)$manifest=get_option(self::BASELINE_V2,$manifest);
            }
        }
        if(!is_array($original) || !is_array($manifest))return;
        $active=(int)get_option('mad4b_css_ai_epoch_v1',0);
        if($active<=0 || $active===(int)($original['css_epoch']??0))return;
        $full=self::matches($original,$manifest,$active);
        if(!$full && !self::selective_registry($original,$active))return;
        global $wpdb;
        $lock='mad4b_native_layout_epoch_renewal_v1';
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,1)',$lock))!==1)return;
        try {
            $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",self::PROOF));
            if(!is_string($raw) || maybe_unserialize($raw)!==$original)return;
            $activeNow=(int)get_option('mad4b_css_ai_epoch_v1',0);
            if(self::matches($original,$manifest,$activeNow)){
                $next=$original;$next['css_epoch']=$activeNow;$decision='renewed_identical_css_and_elementor_sources';
            }else{
                $next=self::selective_registry($original,$activeNow);
                if(!$next)return;
                $decision='renewed_verified_families_only';
            }
            $updated=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=%s",maybe_serialize($next),self::PROOF,$raw));
            if($updated!==1)return;
            wp_cache_delete(self::PROOF,'options');wp_cache_delete('alloptions','options');
            update_option(self::RECEIPT,[
                'contract'=>'mad4b.native-listing.scoped-css-epoch-continuity-receipt.v1',
                'site_host'=>(string)wp_parse_url(home_url('/'),PHP_URL_HOST),
                'old_epoch'=>(int)$original['css_epoch'],'new_epoch'=>$activeNow,
                'registry_semantic_sha256'=>self::proof_digest($original),
                'manifest_sha256'=>hash('sha256',serialize($manifest)),
                'verified_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
                'decision'=>$decision,
                'identity_contract'=>$manifest['contract']??'mad4b.native-listing.verified-family.v1',
            ],false);
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public static function boot():void {
        add_action('wp',[__CLASS__,'on_front_page'],1);
        add_action('mad4b_native_layout_continuity_hourly_v2',[__CLASS__,'renew']);
        add_action('init',static function(){
            if(!wp_next_scheduled('mad4b_native_layout_continuity_hourly_v2'))
                wp_schedule_event(time()+300,'hourly','mad4b_native_layout_continuity_hourly_v2');
        },30);
    }
}
MAD4B_Native_Layout_Epoch_Continuity_V1::boot();
