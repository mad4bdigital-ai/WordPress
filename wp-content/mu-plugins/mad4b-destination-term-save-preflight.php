<?php
/**
 * Plugin Name: MAD4B Destination Term Save Preflight
 * Description: Site-bound, fail-closed pre-write checks for visitor-facing destination links.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

/**
 * Does not rewrite historical links. Rejects only newly introduced external
 * clickable URLs / KSES-unstable local URLs on public destination fields.
 * Supports JetEngine admin saves, programmatic term-meta updates, and new terms.
 */
final class MAD4B_Destination_Term_Save_Preflight_V1 {
    const CONTRACT = 'mad4b.destination-term-save-preflight.v1';
    const TAXONOMY = 'destinations';
    const MAX_FIELD_BYTES = 262144;
    const MAX_LINKS = 120;
    private static $public_fields = array(
        'internal_links'=>true, 'why_visit'=>true,'travel_tips'=>true,
        'highlights'=>true,'faq'=>true,'best_time_to_visit'=>true,
        'exerpt'=>true,'legacy_location_localized_description'=>true,
    );

    public static function boot() {
        add_action('admin_init', array(__CLASS__,'admin_before_save'), 1);
        add_filter('pre_insert_term', array(__CLASS__,'before_insert_term'), 3, 3);
        add_filter('update_term_metadata', array(__CLASS__,'metadata_fence'), 1, 5);
        add_filter('add_term_metadata', array(__CLASS__,'metadata_fence'), 1, 5);
    }
    private static function identity() {
        $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        $host=preg_replace('/^www\./','',$host);
        $site=class_exists('MAD4B_SCP_Site_Profile',false)
            ?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
        $ctx=class_exists('MAD4B_SCP_Brand_Experience_Quality',false)
            ?(array)MAD4B_SCP_Brand_Experience_Quality::brand_context():array();
        $scope=(array)($ctx['scope']??array());
        if($host==='' || $site==='' || $site!==(string)($scope['site_uuid']??'')
           || empty($scope['brand_name']))
            return new WP_Error('mad4b_destination_brand_identity_missing',
                'Site identity is incomplete; destination links were not saved.');
        $approved=apply_filters('mad4b/brand_integrity/approved_domains',array(),$scope);
        $approved=is_array($approved)?$approved:array();
        $approved[]=$host;
        $hosts=array();
        foreach($approved as $item) {
            if(!is_string($item)||strlen($item)>255)continue;
            $v=strtolower(trim($item));
            if(strpos($v,'://')!==false)$v=(string)wp_parse_url($v,PHP_URL_HOST);
            $v=trim((string)preg_replace('/^www\./','',$v),'.');
            if($v!=='' && preg_match('/^[a-z0-9.-]+$/D',$v))$hosts[$v]=true;
        }
        return array('site_uuid'=>$site,'hosts'=>$hosts);
    }
    private static function leaf_strings($value,&$all,$depth=0) {
        if($depth>8||count($all)>100)return false;
        if(is_array($value)||is_object($value)) {
            foreach((array)$value as $entry)
                if(!self::leaf_strings($entry,$all,$depth+1))return false;
            return true;
        }
        if($value===null||is_bool($value)||is_int($value)||is_float($value))return true;
        if(!is_string($value)||strlen($value)>self::MAX_FIELD_BYTES)return false;
        $all[]=$value;
        return true;
    }
    private static function urls($value,$key) {
        $leaves=array();
        if(!self::leaf_strings(maybe_unserialize($value),$leaves))
            return new WP_Error('mad4b_destination_field_coverage_incomplete',
                'Destination field is too large or deeply nested to verify.');
        $found=array();
        foreach($leaves as $content) {
            preg_match_all('~<a\b[^>]*\bhref\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))~iu',
                $content,$anchors,PREG_SET_ORDER);
            if(count($anchors)>self::MAX_LINKS)
                return new WP_Error('mad4b_destination_link_count_exceeded','Too many links to verify.');
            foreach($anchors as $a) {
                $href=(string)($a[1]!==''?$a[1]:($a[2]!==''?$a[2]:($a[3]??'')));
                $href=trim(html_entity_decode($href,ENT_QUOTES|ENT_HTML5,'UTF-8'));
                if($href!=='')$found[$href]=true;
            }
            // Legacy JetEngine internal-links fields can contain raw URLs.
            if($key==='internal_links') {
                preg_match_all('~https?://[^\s"\'<>\\\\,]+~iu',$content,$urls);
                if(count($urls[0])>self::MAX_LINKS)
                    return new WP_Error('mad4b_destination_link_count_exceeded','Too many URLs to verify.');
                foreach($urls[0] as $href)$found[html_entity_decode(rtrim($href,',.;'),ENT_QUOTES|ENT_HTML5,'UTF-8')]=true;
            }
        }
        if(count($found)>self::MAX_LINKS)
            return new WP_Error('mad4b_destination_link_count_exceeded','Too many unique links to verify.');
        return array_keys($found);
    }
    private static function classify_url($href,array $identity) {
        if(strncmp($href,'//',2)===0)$href='https:'.$href;
        if(strncmp($href,'/',1)===0) {
            $path=explode('?',explode('#',$href,2)[0],2)[0];
            if(preg_match('~/(?:[^/?#]+:[^/?#]+)~',$path))return 'unstable_relative_link';
            return '';
        }
        if($href!==''&&$href[0]==='#')return '';
        if(preg_match('/^(?:mailto|tel):/i',$href))return '';
        if(!preg_match('~^https?://~i',$href))
            return preg_match('/^[a-z][a-z0-9+.-]*:/i',$href)?'unsafe_url_scheme':'';
        $host=strtolower((string)wp_parse_url($href,PHP_URL_HOST));
        $host=preg_replace('/^www\./','',$host);
        if($host==='' || !isset($identity['hosts'][$host]))return 'foreign_domain';
        return '';
    }
    private static function current_values($term_id,$key) {
        global $wpdb;
        $rows=$wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id=%d AND meta_key=%s ORDER BY meta_id ASC LIMIT 101",
            $term_id,$key));
        if(!is_array($rows)||$wpdb->last_error!==''||count($rows)>100)
            return new WP_Error('mad4b_destination_baseline_unavailable',
                'Current Term Meta could not be verified.');
        return $rows;
    }
    public static function preflight($term_id,$key,$candidate,$is_new=false) {
        if(!is_string($key)||!isset(self::$public_fields[$key]))
            return array('allowed'=>true,'not_guarded'=>true);
        if(!$is_new) {
            $term=get_term((int)$term_id,self::TAXONOMY);
            if(!$term || is_wp_error($term))
                return new WP_Error('mad4b_destination_invalid_term','Destination term unavailable.');
        }
        $identity=self::identity();
        if(is_wp_error($identity))return $identity;
        $before=$is_new?array():self::current_values((int)$term_id,$key);
        if(is_wp_error($before))return $before;
        $prior=array();
        foreach($before as $raw) {
            $refs=self::urls($raw,$key);
            if(is_wp_error($refs))return $refs;
            foreach($refs as $ref)$prior[$ref]=true;
        }
        $incoming=self::urls($candidate,$key);
        if(is_wp_error($incoming))return $incoming;
        $errors=array();
        foreach($incoming as $url) {
            if(isset($prior[$url]))continue; // legacy source unchanged; repair remains possible
            $reason=self::classify_url($url,$identity);
            if($reason!=='')$errors[$reason]=true;
        }
        if($errors)
            return new WP_Error('mad4b_destination_external_link_blocked',
                'Destination links were not saved. Use a first-party URL or remove the newly introduced external/unsafe link.',
                array('contract'=>self::CONTRACT,'term_id'=>(int)$term_id,
                    'meta_key'=>$key,'reasons'=>array_keys($errors),
                    'site_uuid'=>$identity['site_uuid'],'mutation_performed'=>false));
        if(class_exists('MAD4B_SCP_Brand_Integrity',false)
           && method_exists('MAD4B_SCP_Brand_Integrity','term_meta_candidate_preflight')
           && !$is_new) {
            $extra=MAD4B_SCP_Brand_Integrity::term_meta_candidate_preflight($term_id,$key,$candidate);
            if(is_wp_error($extra))return $extra;
        }
        return array('contract'=>self::CONTRACT,'allowed'=>true,
            'site_uuid'=>$identity['site_uuid'],'mutation_performed'=>false);
    }
    private static function check_post_fields($term_id,$post,$is_new) {
        if(!is_array($post))return new WP_Error('mad4b_destination_invalid_request','Invalid destination submission.');
        foreach(self::$public_fields as $key=>$enabled) {
            if(!array_key_exists($key,$post))continue;
            $value=wp_unslash($post[$key]);
            $ok=self::preflight($term_id,$key,$value,$is_new);
            if(is_wp_error($ok))return $ok;
        }
        return true;
    }
    public static function admin_before_save() {
        global $pagenow;
        if(!is_admin()||($pagenow??'')!=='edit-tags.php'
           ||strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'
           ||(string)($_POST['action']??'')!=='editedtag'
           ||(string)($_POST['taxonomy']??'')!==self::TAXONOMY)return;
        $term_id=absint($_POST['tag_ID']??0);
        if(!$term_id)return;
        // Verify exactly the WordPress core nonce and term-specific capability.
        check_admin_referer('update-tag_'.$term_id);
        if(!current_user_can('edit_term',$term_id))return;
        $ok=self::check_post_fields($term_id,$_POST,false);
        if(is_wp_error($ok))
            wp_die(esc_html($ok->get_error_message()).
                ' '.esc_html('No destination fields were saved.'),
                esc_html('MAD4B Destination Link Preflight'),array('response'=>422,'back_link'=>true));
    }
    public static function before_insert_term($term,$taxonomy,$args) {
        if($taxonomy!==self::TAXONOMY||is_wp_error($term))return $term;
        if(!is_array($args))$args=array();
        $fields=array();
        if(isset($args['meta_input'])&&is_array($args['meta_input']))
            $fields=$args['meta_input'];
        if(isset($_POST['action'])&&$_POST['action']==='add-tag'
           &&(string)($_POST['taxonomy']??'')===self::TAXONOMY)
            $fields=array_merge($fields,wp_unslash($_POST));
        // Raw args are supplied by integrations; avoid second unslashing.
        foreach(self::$public_fields as $key=>$enabled) {
            if(!array_key_exists($key,$fields))continue;
            $res=self::preflight(0,$key,$fields[$key],true);
            if(is_wp_error($res))return $res;
        }
        return $term;
    }
    public static function metadata_fence($check,$term_id,$meta_key,$value,$prev_value=null) {
        if(!is_string($meta_key)||!isset(self::$public_fields[$meta_key]))
            return $check;
        // A different metadata provider's earlier non-null result does not
        // override this term-link safety fence. Preserve earlier denials.
        if($check===false||is_wp_error($check))return $check;
        $term=get_term((int)$term_id,self::TAXONOMY);
        if(!$term||is_wp_error($term))return $check;
        $result=self::preflight((int)$term_id,$meta_key,$value);
        if(is_wp_error($result)) {
            // Native update_term_meta() receives false, never a deceptive success.
            // The admin form fails as a whole via admin_before_save().
            do_action('mad4b/destination_term_preflight/rejected',array(
                'term_id'=>(int)$term_id,'key'=>$meta_key,
                'code'=>$result->get_error_code(),
                'contract'=>self::CONTRACT,'mutated'=>false));
            return false;
        }
        return $check;
    }
}
MAD4B_Destination_Term_Save_Preflight_V1::boot();
