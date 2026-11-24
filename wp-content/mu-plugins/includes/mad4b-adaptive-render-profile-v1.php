<?php
/**
 * Shared Render Governor telemetry. Read-only ranking; never grants cache admission.
 */
defined('ABSPATH') || exit;
final class MAD4B_Adaptive_Render_Profile_V1 {
    private static array $stack=[], $rows=[], $phases=[], $queries=[], $executions=[];
    private static bool $active=false, $valid=true;
    private static int $seen=0,$origin=0,$queryStart=0,$rootNs=0;
    private const GROUP='mad4b-fragment-lifecycle';
    public static function boot():void {
        // No instrumentation on ordinary traffic. Staging-only diagnostics.
        if (($_SERVER['HTTP_X_MAD4B_RENDER_PROFILE']??'')!=='v1'
            || ($_SERVER['REQUEST_METHOD']??'')!=='GET'
            || !empty($_SERVER['HTTP_COOKIE'])||!empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['PHP_AUTH_USER'])||!empty($_SERVER['HTTP_X_WP_NONCE'])
            || wp_get_environment_type()!=='staging'
            || (defined('WP_CLI')&&WP_CLI))return;
        self::$origin=hrtime(true);
        foreach(['plugins_loaded','init','wp_loaded','wp','template_redirect','wp_head','wp_footer'] as $hook)
            add_action($hook,static function()use($hook){self::phase($hook);},PHP_INT_MAX);
        add_action('wp',[self::class,'activate'],1);
    }
    public static function phase(string $name):void {
        global $wpdb;
        self::$phases[$name]=['elapsed_ms'=>round((microtime(true)-(float)($_SERVER['REQUEST_TIME_FLOAT']??microtime(true)))*1000,2),'queries'=>(int)($wpdb->num_queries??0)];
    }
    public static function activate():void {
        if(is_admin()||is_user_logged_in()||is_preview()||wp_doing_ajax()
            || (defined('REST_REQUEST')&&REST_REQUEST)
            || !(is_front_page()||is_singular('tours-and-activities')||is_post_type_archive('tours-and-activities')||is_tax()))return;
        self::$active=true;
        add_action('elementor/frontend/before_render',[self::class,'before'],PHP_INT_MIN,1);
        add_action('elementor/frontend/after_render',[self::class,'after'],PHP_INT_MAX,1);
        add_action('jet-engine/query-builder/query/after-query-setup',[self::class,'query'],PHP_INT_MAX,1);
        add_action('jet-engine/query-builder/query/before-get-items',[self::class,'execution'],PHP_INT_MAX,2);
        add_action('shutdown',[self::class,'finish'],PHP_INT_MAX);
    }
    public static function before($el):void {
        if(!self::$active||!self::$valid)return;
        if(++self::$seen>8000||count(self::$stack)>64){self::$valid=false;return;}
        if(!is_object($el)||!method_exists($el,'get_name')){self::$valid=false;return;}
        $type=(string)$el->get_name();
        if(!preg_match('/^[a-z0-9_-]{1,80}$/D',$type))$type='unknown';
        $doc=0;
        if(class_exists('Elementor\Plugin',false)){
            $d=\Elementor\Plugin::$instance->documents->get_current();
            if(is_object($d)&&method_exists($d,'get_id'))$doc=(int)$d->get_id();
        }
        global $wpdb;
        self::$stack[]=['object'=>spl_object_id($el),'key'=>$doc.'|'.$type,'family'=>$doc,'type'=>$type,
            'start'=>hrtime(true),'q'=>(int)($wpdb->num_queries??0),'child'=>0,'child_q'=>0];
    }
    public static function after($el):void {
        if(!self::$active||!self::$valid)return;
        if(!is_object($el)||!self::$stack){self::$valid=false;return;}
        $row=array_pop(self::$stack);
        if($row['object']!==spl_object_id($el)){self::$valid=false;return;}
        global $wpdb;
        $ns=max(0,hrtime(true)-$row['start']);$q=max(0,(int)($wpdb->num_queries??0)-$row['q']);
        if(self::$stack){$i=count(self::$stack)-1;self::$stack[$i]['child']+=$ns;self::$stack[$i]['child_q']+=$q;}else self::$rootNs+=$ns;
        $key=$row['key'];
        if(!isset(self::$rows[$key])&&count(self::$rows)>=512){self::$valid=false;return;}
        $r=self::$rows[$key]??['family'=>$row['family'],'type'=>$row['type'],'count'=>0,'inclusive_ns'=>0,'exclusive_ns'=>0,'exclusive_queries'=>0];
        $r['count']++;$r['inclusive_ns']+=$ns;$r['exclusive_ns']+=max(0,$ns-$row['child']);
        $r['exclusive_queries']+=max(0,$q-$row['child_q']);self::$rows[$key]=$r;
    }
    public static function query($q):void {
        if(!self::$active||!self::$valid||!is_object($q))return;
        $id=(int)($q->id??0);$type=(string)($q->query_type??'');
        if($id<1||!preg_match('/^[a-z0-9_-]{1,50}$/D',$type))return;
        $key=$id.'|'.$type;if(!isset(self::$queries[$key])&&count(self::$queries)>=128)return;
        $r=self::$queries[$key]??['query_id'=>$id,'type'=>$type,'setup_count'=>0,'cache_admitted'=>0];
        $r['setup_count']++;if(!empty($q->cache_query))$r['cache_admitted']++;
        self::$queries[$key]=$r;
    }
    // Observe native cache decisions; never call setup_query or the renderer
    // to measure a query. Hash resolved inputs, not the Query Builder ID.
    public static function execution($q,$hit):void {
        if(!self::$active||!self::$valid||!is_object($q)||!in_array(($q->query_type??''),['posts','terms'],true)
            ||!is_array($q->final_query??null)||empty($q->id))return;
        $id=(int)$q->id;
        if(!isset(self::$executions[$id])&&count(self::$executions)>=128)return;
        $args=wp_json_encode($q->final_query);
        if(!is_string($args)||strlen($args)>65536)return;
        $fingerprint=hash('sha256',get_class($q).'|'.(string)($q->cache_group??'').'|'.(int)($q->cache_expires??0).'|'.$args);
        $r=self::$executions[$id]??['query_id'=>$id,'calls'=>0,'native_hits'=>0,'native_misses'=>0,'distinct'=>[],'truncated'=>false];
        $r['calls']++;if($hit===true)$r['native_hits']++;else $r['native_misses']++;
        if(isset($r['distinct'][$fingerprint])||count($r['distinct'])<128)$r['distinct'][$fingerprint]=true;else $r['truncated']=true;
        self::$executions[$id]=$r;
    }
    private static function execution_summary():array {
        $out=[];
        foreach(self::$executions as $r){
            $r['distinct_resolved_scopes']=count($r['distinct']);unset($r['distinct']);
            $r['repeat_observed']=$r['truncated']?null:max(0,$r['calls']-$r['distinct_resolved_scopes']);
            $r['dedup_authorized']=false;$out[]=$r;
        }
        return $out;
    }
    public static function rank(array $rows):array {
        $out=[];
        foreach($rows as $r){
            $type=(string)$r['type'];
            $policy=preg_match('/(?:form|price|rate|checkout|book|payment|cart|login|account|filter)/i',$type)?'live_only':
                (in_array($type,['heading','text-editor','icon','icon-box','divider','jet-listing-dynamic-field','jet-listing-dynamic-terms','jet-listing-grid','container'],true)?'inspect_public_structure':'native_review');
            $out[]=['family'=>(int)$r['family'],'widget'=>$type,'count'=>(int)$r['count'],
                'inclusive_ms'=>round($r['inclusive_ns']/1e6,2),'exclusive_ms'=>round($r['exclusive_ns']/1e6,2),
                'exclusive_queries'=>(int)$r['exclusive_queries'],'candidate_action'=>$policy,'cache_authorized'=>false];
        }
        usort($out,static fn($a,$b)=>$b['exclusive_ms']<=>$a['exclusive_ms']);
        return array_slice($out,0,60);
    }
    public static function finish():void {
        if(!self::$active)return;self::$active=false;
        if(!self::$valid||self::$stack||!self::$rows)return;
        global $wpdb;
        $sample=['contract'=>'mad4b.adaptive-render-profile.v1','at'=>microtime(true),
            'route_kind'=>is_front_page()?'home':(is_singular('tours-and-activities')?'tour':'archive'),
            'language'=>(string)apply_filters('wpml_current_language',''),
            'device'=>wp_is_mobile()?'mobile':'desktop','runtime_ms'=>round((microtime(true)-(float)($_SERVER['REQUEST_TIME_FLOAT']??microtime(true)))*1000,2),
            'queries'=>(int)($wpdb->num_queries??0),'elementor_root_ms'=>round(self::$rootNs/1e6,2),
            'phases'=>self::$phases,'ranked_widgets'=>self::rank(self::$rows),'query_setups'=>array_values(self::$queries),'query_executions'=>self::execution_summary(),
            'cache_authorized'=>false,'native_certificate_written'=>false];
        $slot=(int)(microtime(true)*1000)%16;
        wp_cache_set('adaptive-profile-'.$slot,$sample,self::GROUP,3600);
        do_action('mad4b/render_query/adaptive_profile_sample',$sample);
    }
    public static function samples():array {
        $out=[];for($i=0;$i<16;$i++){$v=wp_cache_get('adaptive-profile-'.$i,self::GROUP);if(is_array($v)&&($v['contract']??'')==='mad4b.adaptive-render-profile.v1')$out[]=$v;}
        usort($out,static fn($a,$b)=>$a['at']<=>$b['at']);return $out;
    }
}
MAD4B_Adaptive_Render_Profile_V1::boot();
