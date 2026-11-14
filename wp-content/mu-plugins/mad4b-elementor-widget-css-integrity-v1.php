<?php
/**
 * Plugin Name: MAD4B Native Elementor Widget CSS Integrity
 * Description: Detects Elementor CSS generated without JetEngine/JetTabs widget selectors, repairs natively only in complete frontend context.
 * Version: 1.2.1
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Elementor_Widget_CSS_Integrity_V1', false)) {
final class MAD4B_Elementor_Widget_CSS_Integrity_V1 {
    private const OPTION = 'mad4b_native_elementor_css_templates_v1';
    private const CACHE_TTL = 120;
    private static array $auditMemo=[];
    /**
     * Persistent observed-template catalog, not a static operation list.
     * Existing sites are enrolled once by their authorized native actor;
     * Elementor editor saves automatically enroll future Jet widgets.
     */
    public static function template_ids():array {
        $stored=get_option(self::OPTION,[]);
        $ids=is_array($stored)?array_values(array_unique(array_filter(array_map('absint',$stored)))):[];
        $filtered=apply_filters('mad4b/native_elementor_css_templates',$ids);
        return is_array($filtered)?array_slice(array_values(array_unique(array_filter(array_map('absint',$filtered)))),0,48):$ids;
    }
    /**
     * Discover previously imported / direct-CAS Elementor Tour templates.
     * The native route widget is the semantic discovery anchor; its other
     * Jet widgets are checked against their saved CSS using actual settings.
     * Executed only on approved editor admin visits or explicit CLI release,
     * never on a public guest render or AJAX price/booking operation.
     */
    public static function reconcile_route_templates(bool $write=false):array {
        global $wpdb;
        $like=$wpdb->esc_like('mad4b-itinerary-route');
        $sql=$wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
             WHERE p.post_type=%s AND p.post_status=%s AND pm.meta_key=%s
                AND pm.meta_value LIKE %s
             ORDER BY p.ID ASC LIMIT %d",
            'elementor_library','publish','_elementor_data','%'.$like.'%',64
        );
        $candidateIds=array_map('absint',(array)$wpdb->get_col($sql));
        // Reuse the same observed-template catalog for native Tour Rate
        // containers with Elementor border styling. Do not hardcode template
        // IDs, and do not enumerate unrelated Elementor documents.
        $rateSql=$wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
             WHERE p.post_type=%s AND p.post_status=%s AND pm.meta_key=%s
               AND pm.meta_value LIKE %s
               AND pm.meta_value LIKE %s
               AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s)
             ORDER BY p.ID ASC LIMIT %d",
            'elementor_library','publish','_elementor_data',
            '%'.$wpdb->esc_like('"tour_rates"').'%',
            '%'.$wpdb->esc_like('"jet-smart-filters-select"').'%',
            '%'.$wpdb->esc_like('"border_border"').'%',
            '%'.$wpdb->esc_like('"_border_border"').'%',64
        );
        $rateIds=array_map('absint',(array)$wpdb->get_col($rateSql));
        // Uncompiled rate templates must be fixed by their original editor/
        // publish lifecycle, not rebuilt speculatively on unrelated guest
        // Tour requests. Enroll only already-produced native CSS documents.
        $rateIds=array_values(array_filter($rateIds,static function($id){
            return is_file(WP_CONTENT_DIR.'/uploads/elementor/css/post-'.
                absint($id).'.css');
        }));
        $candidateIds=array_slice(array_values(array_unique(
            array_merge($candidateIds,$rateIds))),0,64);
        $eligible=[];
        foreach($candidateIds as $id) {
            if(count(self::expected_selectors($id)))$eligible[]=$id;
        }
        $current=self::template_ids();
        $toAdd=array_values(array_diff($eligible,$current));
        $next=array_values(array_unique(array_merge($current,$toAdd)));
        sort($next,SORT_NUMERIC);
        if(count($next)>48)return [
            'state'=>'catalog_capacity_hold',
            'existing'=>count($current),
            'eligible_route_templates'=>$eligible,
            'would_add'=>$toAdd,
            'candidate_count'=>count($candidateIds)
        ];
        $state='already_complete';
        if($write && $next!==$current){
            if(!update_option(self::OPTION,$next,false))return [
                'state'=>'persist_failed','would_add'=>$toAdd,'expected_catalog'=>$next
            ];
            foreach($toAdd as $id){
                delete_transient(self::cache_key($id));
                unset(self::$auditMemo[$id]);
            }
            $state=self::template_ids()===$next?'written_verified':'readback_failed';
        }elseif($next!==$current)$state='ready_to_enroll';
        return [
            'state'=>$state,
            'candidate_count'=>count($candidateIds),
            'eligible_route_templates'=>$eligible,
            'would_add'=>$toAdd,
            'catalog_size'=>count($next),
            'catalog'=>$write?self::template_ids():$next
        ];
    }
    public static function maybe_admin_reconcile():void {
        if(!is_admin()||!current_user_can('manage_options')||wp_doing_ajax())return;
        if(get_transient('mad4b_css_route_discovery_v1'))return;
        $out=self::reconcile_route_templates(true);
        if(in_array($out['state']??'',['written_verified','already_complete'],true))
            set_transient('mad4b_css_route_discovery_v1',1,43200);
    }
    public static function track_elementor_source($meta_id,$post_id,$meta_key,$meta_value):void {
        if($meta_key!=='_elementor_data')return;
        $post_id=absint($post_id);
        if(!$post_id||!get_post_type($post_id))return;
        // Elementor stores derived HTML independently of _elementor_data.
        // A native editor save or a WPML sync can otherwise serve stale
        // markup (including stale JetEngine lazy-load placeholders) for up
        // to the element-cache TTL. Invalidate this document only, never
        // every document or the public-page cache.
        // This hook also covers non-template Elementor post types.
        if(metadata_exists('post',$post_id,'_elementor_element_cache'))
            delete_post_meta($post_id,'_elementor_element_cache');
        if(!in_array(get_post_type($post_id),['elementor_library','jet-engine'],true))return;
        $catalog=self::template_ids();
        // A newly edited styled Jet widget joins without maintaining
        // an ID whitelist. Removed styling or deleted templates drop out.
        $hasSettings=(bool)self::expected_selectors($post_id);
        $next=array_values(array_diff($catalog,[$post_id]));
        if($hasSettings)$next[]=$post_id;
        sort($next,SORT_NUMERIC);
        if($next!==$catalog)update_option(self::OPTION,$next,false);
        delete_transient(self::cache_key($post_id));
        unset(self::$auditMemo[$post_id]);
    }
    private static function cache_key(int $id):string {
        return 'mad4b_widget_css_audit_'.$id;
    }
    public static function missing_selectors($css,array $expected):array {
        $missing=[];
        foreach($expected as $needle){
            if(!is_string($css)){$missing[]=$needle;continue;}
            if(str_starts_with($needle,'container-border:')){
                $parts=explode(':',$needle,3);
                $selector=$parts[1]??'';
                $style=$parts[2]??'';
                // The native Elementor declaration must belong to this
                // exact container, not to another styled widget.
                $pattern='~\.elementor-element\.'.preg_quote($selector,'~').
                    '\s*\{[^}]*\bborder-style\s*:\s*'.
                    preg_quote($style,'~').'\s*;~s';
                if(!$selector||!$style||!preg_match($pattern,$css))$missing[]=$needle;
            } elseif(!str_contains($css,$needle)) $missing[]=$needle;
        }
        return $missing;
    }
    private static function expected_selectors(int $id):array {
        if(get_post_type($id)!=='elementor_library' && get_post_type($id)!=='jet-engine') return [];
        $data=json_decode((string)get_post_meta($id,'_elementor_data',true),true);
        if(!is_array($data)) return [];
        $out=[];
        $walk=static function(array $parts)use(&$walk,&$out):void {
            foreach($parts as $node) {
                if(!is_array($node))continue;
                $type=(string)($node['widgetType']??'');
                $s=(array)($node['settings']??[]);
                // This also covers native Elementor parent containers. A
                // truncated post-*.css can silently remove the Tour Rates
                // border while leaving Jet widgets otherwise functional.
                $border=(string)($s['_border_border']??$s['border_border']??'');
                if(($node['elType']??'')==='container'&&!empty($node['id'])
                   &&in_array($border,['solid','dotted','dashed','double','groove','ridge'],true)){
                    $out[]='container-border:elementor-element-'.
                        (string)$node['id'].':'.$border;
                }
                $expect=false;
                // Ask only for selectors belonging to a native, styled
                // JetAccordion/JetListing widget already present in Elementor.
                if($type==='jet-accordion') {
                    $expect=isset($s['toggle_label_typography_font_size'])
                      ||isset($s['toggle_control_background_color']);
                } elseif($type==='jet-listing-grid') {
                    $expect=isset($s['arrow_color'])||isset($s['dots_bg_color'])
                      ||isset($s['horizontal_gap']['size']);
                } elseif($type==='jet-listing-dynamic-field') {
                    $expect=isset($s['field_color'])||isset($s['__globals__']['field_color'])
                      ||isset($s['field_typography_typography']);
                }
                if($expect&&!empty($node['id'])) {
                    $base='elementor-element-'.(string)$node['id'];
                    $out[]=$base;
                    // Presence of a widget ID alone does not prove its
                    // native appearance rules survived Elementor CSS
                    // generation. Match a key control selector as well.
                    if($type==='jet-accordion'&&isset($s['toggle_label_typography_font_size']))
                        $out[]=$base.' .jet-accordion > .jet-accordion__inner > .jet-toggle > .jet-toggle__control .jet-toggle__label-text';
                    if($type==='jet-listing-dynamic-field')
                        $out[]=$base.' .jet-listing-dynamic-field__content';
                }
                if(!empty($node['elements'])&&is_array($node['elements']))$walk($node['elements']);
            }
        };
        $walk($data);
        return array_values(array_unique($out));
    }
    /**
     * Fast path: trust a short-lived, CSS-file-state-bound receipt, not
     * a heavy template JSON parse plus file read on every guest request.
     * File size/mtime change instantly invalidates that receipt.
     */
    public static function fast_audit(int $id):array {
        $id=absint($id);
        $path=WP_CONTENT_DIR.'/uploads/elementor/css/post-'.$id.'.css';
        $state=is_file($path)?
          [(int)@filesize($path),(int)@filemtime($path)]:[0,0];
        $receipt=get_transient(self::cache_key($id));
        if(is_array($receipt)&&($receipt['state']??null)===$state &&
           ($receipt['status']??'')==='complete')
            return $receipt;
        // A CSS file can be regenerated during the current request.
        // Never reuse the prior in-process audit when file metadata changes.
        unset(self::$auditMemo[$id]);
        $out=self::audit($id);
        $out['state']=$state;
        if(($out['status']??'')==='complete')
            set_transient(self::cache_key($id),$out,self::CACHE_TTL);
        return $out;
    }
    public static function audit(int $id):array {
        $id=absint($id);
        if(isset(self::$auditMemo[$id]))return self::$auditMemo[$id];
        if(!in_array($id,self::template_ids(),true))
            return ['template'=>$id,'status'=>'outside_catalog'];
        $css=WP_CONTENT_DIR.'/uploads/elementor/css/post-'.$id.'.css';
        $expected=self::expected_selectors($id);
        if(!$expected)return self::$auditMemo[$id]=['template'=>$id,'status'=>'not_applicable','expected'=>0];
        $raw=is_file($css)&&@filesize($css)<=2097152?@file_get_contents($css):false;
        $missing=self::missing_selectors($raw,$expected);
        return self::$auditMemo[$id]=['template'=>$id,
          'status'=>$missing?'incomplete':'complete',
          'file_bytes'=>is_string($raw)?strlen($raw):0,
          'expected'=>count($expected),'missing'=>$missing];
    }
    public static function verify_and_repair():void {
        if((defined('WP_CLI')&&WP_CLI)||is_admin()||wp_doing_ajax()
           ||(defined('REST_REQUEST')&&REST_REQUEST)
           ||(!is_singular('tours-and-activities')&&!is_preview()))
            return;
        if(!class_exists('\Elementor\Core\Files\CSS\Post')
          ||!class_exists('\Elementor\Plugin'))return;
        $manager=\Elementor\Plugin::instance()->widgets_manager;
        if(!is_object($manager)
          ||!$manager->get_widget_types('jet-accordion')
          ||!$manager->get_widget_types('jet-listing-dynamic-field'))return;
        foreach(self::template_ids() as $id){
            $audit=self::fast_audit($id);
            if(($audit['status']??'')!=='incomplete')continue;
            // A failed native rebuild must never loop on every uncached
            // frontend request; try again after the bounded cooldown.
            if(get_transient('mad4b_css_repair_cooldown_'.$id))continue;
            $lockPath=sys_get_temp_dir().'/mad4b-native-elementor-css-'.substr(hash('sha256',home_url('/')),0,10).'-'.$id.'.lock';
            $lock=@fopen($lockPath,'c');
            if(!$lock)continue;
            if(!@flock($lock,LOCK_EX|LOCK_NB)){@fclose($lock);continue;}
            try{
                // Revalidate under the mutex; another request might have
                // already healed the file while this one was waiting.
                unset(self::$auditMemo[$id]);
                if((self::audit($id)['status']??'')!=='incomplete')continue;
                $css=\Elementor\Core\Files\CSS\Post::create($id);
                if(!is_object($css)||!method_exists($css,'update'))continue;
                $css->update(); // Native Elementor, full frontend widgets.
                unset(self::$auditMemo[$id]);
                $after=self::audit($id);
                if(($after['status']??'')!=='complete') {
                    set_transient('mad4b_css_repair_cooldown_'.$id,1,180);
                    error_log('MAD4B_ELEMENTOR_WIDGET_CSS_REPAIR_HOLD template='.$id);
                } else {
                    delete_transient('mad4b_css_repair_cooldown_'.$id);
                    delete_transient(self::cache_key($id));
                }
            } catch(\Throwable $e) {
                set_transient('mad4b_css_repair_cooldown_'.$id,1,180);
                error_log('MAD4B_ELEMENTOR_WIDGET_CSS_REPAIR_ERROR template='.$id.' class='.get_class($e));
            } finally {
                @flock($lock,LOCK_UN);
                @fclose($lock);
            }
        }
    }
}}
add_action('wp_enqueue_scripts',[MAD4B_Elementor_Widget_CSS_Integrity_V1::class,'verify_and_repair'],25);
add_action('added_post_meta',[MAD4B_Elementor_Widget_CSS_Integrity_V1::class,'track_elementor_source'],10,4);
add_action('updated_post_meta',[MAD4B_Elementor_Widget_CSS_Integrity_V1::class,'track_elementor_source'],10,4);
add_action('deleted_post_meta',[MAD4B_Elementor_Widget_CSS_Integrity_V1::class,'track_elementor_source'],10,4);
add_action('admin_init',[MAD4B_Elementor_Widget_CSS_Integrity_V1::class,'maybe_admin_reconcile'],90);
