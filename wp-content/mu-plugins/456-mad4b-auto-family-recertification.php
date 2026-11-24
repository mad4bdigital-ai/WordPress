<?php
/**
 * Plugin Name: MAD4B Elementor Family Revision Re-Certification
 * Description: Re-certifies public family hashes after a trusted Elementor save; changed public islands need two matching native witnesses.
 * Version: 0.1.2
 */
defined('ABSPATH') || exit;

final class MAD4B_Auto_Family_Revision_V1 {
    private const MODE = 'mad4b_auto_family_recert_mode_v1';
    private const STATE = 'mad4b_auto_family_revision_state_v1';
    private const PENDING = 'mad4b_auto_family_pending_v1';
    private const CONTRACT = 'mad4b.elementor-family-revision.v1';
    private const GROUP = 'mad4b-auto-family-revision-v1';
    private static array $dirty = [];
    private static bool $busy = false;
    private static array $witnessed_this_request = [];
    private static array $receipts = [];

    public static function mode(): string {
        $mode=(string)get_option(self::MODE,'off');
        if($mode==='on')return 'on';
        return $mode==='canary'
            && ((($_SERVER['HTTP_X_MAD4B_FAMILY_RECERT_CANARY']??'')==='recert-20261124')
                || ((defined('WP_CLI')&&WP_CLI)
                    && getenv('MAD4B_FAMILY_RECERT_CANARY')==='recert-20261124'))
            ? 'canary':'off';
    }
    private static function host(): string {
        return (string)wp_parse_url(home_url('/'), PHP_URL_HOST);
    }
    private static function trusted(int $postId=0): bool {
        return (function_exists('current_user_can') &&
            ($postId>0 ? current_user_can('edit_post',$postId)
                : current_user_can('edit_theme_options')))
            || (defined('WP_CLI') && WP_CLI);
    }
    private static function read_state(): array {
        $s = get_option(self::STATE, []);
        return is_array($s) && ($s['contract']??'')===self::CONTRACT
            && ($s['site_host']??'')===self::host()
            && is_array($s['families']??null) ? $s : [];
    }
    private static function read_pending(): array {
        $p = get_option(self::PENDING,[]);
        return is_array($p) && ($p['contract']??'')===self::CONTRACT
            && ($p['site_host']??'')===self::host()
            && is_array($p['roots']??null)
            ? $p : ['contract'=>self::CONTRACT,'site_host'=>self::host(),'roots'=>[]];
    }
    public static function candidate_roots(array $nodes): array {
        if(!class_exists('MAD4B_Auto_Public_Family_V1',false))return [];
        $result=[]; $count=0; $duplicates=false;
        $visit = static function(array $items) use (&$visit,&$result,&$count,&$duplicates):void {
            foreach($items as $item) {
                if(!is_array($item) || ++$count>1400){$duplicates=true;return;}
                $id=(string)($item['id']??'');
                if(($item['elType']??'')==='container' &&
                    preg_match('/^[a-f0-9]{7}$/D',$id)) {
                    $details=MAD4B_Auto_Public_Family_V1::analyze_node($item);
                    if($details['eligible']) {
                        if(isset($result[$id])){$duplicates=true;return;}
                        $result[$id]=hash('sha256',(string)wp_json_encode(
                            $item, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                        continue; // no duplicated nested snapshot
                    }
                }
                if(!empty($item['elements']) && is_array($item['elements']))
                    $visit($item['elements']);
            }
        };
        $visit($nodes);
        return $duplicates || $count>1400 ? [] : $result;
    }
    private static function root_node(array $nodes,string $id):?array {
        foreach($nodes as $node) {
            if(!is_array($node))continue;
            if(($node['id']??'')===$id)return $node;
            if(is_array($node['elements']??null)) {
                $found=self::root_node($node['elements'],$id);
                if($found!==null)return $found;
            }
        }
        return null;
    }
    public static function enroll(): array {
        if(!self::trusted() || !class_exists('MAD4B_Auto_Public_Family_V1',false))
            return ['status'=>'unauthorized'];
        $cfg=get_option('mad4b_auto_public_family_v1',[]);
        if(!is_array($cfg) || ($cfg['site_host']??'')!==self::host()
            || ($cfg['status']??'')!=='approved'
            || !is_array($cfg['families']??null))return ['status'=>'bad_family_config'];
        if(self::read_state())return ['status'=>'already_enrolled'];
        $state=['contract'=>self::CONTRACT,'site_host'=>self::host(),
            'families'=>[],'revision'=>1];
        foreach($cfg['families'] as $entry){
            $id=absint($entry['id']??0);
            $sha=(string)($entry['sha256']??'');
            $raw=get_post_meta($id,'_elementor_data',true);
            if(!is_string($raw)||get_post_status($id)!=='publish'
                || !hash_equals($sha,hash('sha256',$raw)))return ['status'=>'stale_family','id'=>$id];
            $nodes=json_decode($raw,true);
            if(!is_array($nodes))return ['status'=>'invalid_json','id'=>$id];
            $state['families'][$id]=['sha'=>$sha,'roots'=>self::candidate_roots($nodes)];
        }
        if(!update_option(self::STATE,$state,false))
            return ['status'=>'unchanged_or_write_failed','families'=>count($state['families'])];
        $pending=self::read_pending();
        if(!update_option(self::PENDING,$pending,false) && !get_option(self::PENDING,false))
            return ['status'=>'pending_init_failed'];
        return ['status'=>'enrolled','families'=>count($state['families']),
            'roots'=>array_sum(array_map(static fn($x)=>count($x['roots']),$state['families']))];
    }
    public static function changed_meta($metaId,$id,$key):void {
        if($key!=='_elementor_data' || self::mode()==='off')return;
        $id=absint($id);
        if(!self::trusted($id))return;
        $map=MAD4B_Auto_Public_Family_V1::family_map();
        if(!$id || !isset($map[$id]) || get_post_type($id)!=='elementor_library')return;
        if(function_exists('wp_is_post_revision') && wp_is_post_revision($id))return;
        self::$dirty[$id]=true;
    }
    public static function elementor_saved($document):void {
        if(self::mode()==='off' || !is_object($document)
            || !method_exists($document,'get_id'))return;
        $id=absint($document->get_id());
        if(!self::trusted($id))return;
        if(isset(MAD4B_Auto_Public_Family_V1::family_map()[$id]))
            self::$dirty[$id]=true;
    }
    public static function shutdown():void {
        if(!self::$dirty)return;
        foreach(array_keys(self::$dirty) as $id)self::reconcile((int)$id);
        if(self::$receipts)
            @file_put_contents('/tmp/mad4b-auto-family-recert-20261124.jsonl',
                wp_json_encode(self::$receipts).PHP_EOL, FILE_APPEND|LOCK_EX);
    }
    private static function record(string $event,int $id,string $reason=''):void {
        self::$receipts[]=['event'=>$event,'family'=>$id,'reason'=>$reason,'utc'=>gmdate('c')];
    }
    public static function reconcile(int $id):array {
        if(self::mode()==='off' || !self::trusted($id) || self::$busy)
            return ['status'=>'unauthorized_or_busy'];
        $state=self::read_state();
        $cfg=get_option('mad4b_auto_public_family_v1',[]);
        if(!$state || !is_array($cfg) || ($cfg['status']??'')!=='approved'
            || ($cfg['site_host']??'')!==self::host()
            || !isset($state['families'][$id]))return ['status'=>'not_enrolled'];
        $index=null;
        foreach((array)$cfg['families'] as $i=>$f) {
            if((int)($f['id']??0)===$id){$index=$i;break;}
        }
        if($index===null)return ['status'=>'not_a_family'];
        $old=(string)($state['families'][$id]['sha']??'');
        $cfgOld=(string)($cfg['families'][$index]['sha256']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$old) || !hash_equals($old,$cfgOld))
            return ['status'=>'stale_concurrent_policy'];
        $raw=get_post_meta($id,'_elementor_data',true);
        $fresh=is_string($raw)?hash('sha256',$raw):'';
        if($fresh===$old)return ['status'=>'unchanged'];
        if(!is_string($raw) || strlen($raw)>650000 || strlen($raw)<10
            || get_post_type($id)!=='elementor_library'
            || get_post_status($id)!=='publish')return ['status'=>'not_public_document'];
        $nodes=json_decode($raw,true);
        if(!is_array($nodes))return ['status'=>'malformed_family'];
        $roots=self::candidate_roots($nodes);
        if(count($roots)>85)return ['status'=>'too_many_candidates'];
        $lock='family_'.$id;
        if(!wp_cache_add($lock,1,self::GROUP,25))return ['status'=>'retry_lock'];
        self::$busy=true;
        try {
            // Compare directly to the authoritative options table under
            // the lock: an earlier admin request may have stale local
            // WP_Object_Cache values after another editor committed.
            global $wpdb;
            if(is_object($wpdb) && isset($wpdb->options)) {
                foreach([
                    'mad4b_auto_public_family_v1'=>'families',
                    self::STATE=>'state'
                ] as $name=>$purpose) {
                    $serialized=$wpdb->get_var($wpdb->prepare(
                        "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$name));
                    $freshOption=is_string($serialized)?maybe_unserialize($serialized):null;
                    if(!is_array($freshOption))return ['status'=>'db_readback_failed'];
                    if($purpose==='families') {
                        $dbsha='';
                        foreach((array)($freshOption['families']??[]) as $f)
                            if((int)($f['id']??0)===$id)$dbsha=(string)($f['sha256']??'');
                    }else $dbsha=(string)($freshOption['families'][$id]['sha']??'');
                    if(!hash_equals($old,$dbsha))
                        return ['status'=>'superseded_by_newer_editor'];
                }
            }
            // Re-check after acquiring the lock. If a newer editor save
            // happened meanwhile, never sign an outdated family SHA.
            $check=get_post_meta($id,'_elementor_data',true);
            if(!is_string($check)||!hash_equals($fresh,hash('sha256',$check)))
                return ['status'=>'changed_during_audit'];
            $pending=self::read_pending();
            $newPending=[];
            $previous=(array)($state['families'][$id]['roots']??[]);
            foreach($roots as $rid=>$rsha) {
                if(isset($previous[$rid]) && hash_equals((string)$previous[$rid],$rsha))
                    continue; // already certified and exactly unchanged
                $newPending[$id.'|'.$rid]=['family'=>$id,'id'=>$rid,
                    'family_sha'=>$fresh,'root_sha'=>$rsha,
                    'stage'=>'native_witness','sample'=>null,'samples'=>0];
            }
            foreach(array_keys($pending['roots']) as $key)
                if(str_starts_with((string)$key,$id.'|'))unset($pending['roots'][$key]);
            $pending['roots']=array_merge($pending['roots'],$newPending);

            // Publish pending denial BEFORE the new family hash. No guest
            // request may replay newly changed markup without witnesses.
            if(!update_option(self::PENDING,$pending,false) && get_option(self::PENDING)!==$pending)
                return ['status'=>'pending_write_failed'];
            $cfg['families'][$index]['sha256']=$fresh;
            if(!update_option('mad4b_auto_public_family_v1',$cfg,false))
                return ['status'=>'certificate_write_failed'];

            // A separately pre-certified manual root can retain its
            // certificate only if its own subtree SHA did not change.
            $manual=get_option('mad4b_mixed_family_public_islands_v1',[]);
            if(is_array($manual) && is_array($manual['roots']??null)){
                $replaced=false;
                foreach($manual['roots'] as &$r) {
                    if(!is_array($r)||(int)($r['family']??0)!==$id
                        || ($r['family_sha256']??'')!==$old)continue;
                    $sub=self::root_node($nodes,(string)($r['id']??''));
                    if($sub && hash_equals((string)$r['root_sha256'],
                        hash('sha256',(string)wp_json_encode($sub,
                            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)))) {
                        $r['family_sha256']=$fresh; $replaced=true;
                    }
                }
                unset($r);
                if($replaced)update_option('mad4b_mixed_family_public_islands_v1',$manual,false);
            }

            // The original Related Destinations renderer is certified
            // independently. Only renew its parent SHA if the approved
            // child template widget and child document remain intact.
            $original=get_option('mad4b_compiled_tour_family_v1',[]);
            if(is_array($original) && (int)($original['family_document']??0)===$id
                && ($original['family_sha256']??'')===$old) {
                $widget=(string)($original['widget_id']??'');
                $child=(int)($original['content_document']??0);
                $content=get_post_meta($child,'_elementor_data',true);
                if($widget && is_string($content)
                    && hash_equals((string)$original['content_sha256'],hash('sha256',$content))
                    && str_contains($raw,'"id":"'.$widget.'"')
                    && str_contains($raw,'"template_id":"'.$child.'"')){
                    $original['family_sha256']=$fresh;
                    update_option('mad4b_compiled_tour_family_v1',$original,false);
                }
            }
            $state['families'][$id]=['sha'=>$fresh,'roots'=>$roots];
            $state['revision']=1+(int)($state['revision']??1);
            if(!update_option(self::STATE,$state,false)) {
                $cfg['families'][$index]['sha256']=$old;
                update_option('mad4b_auto_public_family_v1',$cfg,false);
                return ['status'=>'state_write_failed_reverted_certificate'];
            }
            $result=['status'=>'recertified','family'=>$id,
                'previous_sha'=>$old,'current_sha'=>$fresh,
                'stable_roots'=>count($roots)-count($newPending),
                'pending_native_witness'=>count($newPending)];
            self::record('RECERTIFIED',$id,'pending='.count($newPending));
            return $result;
        }finally{
            self::$busy=false;
            wp_cache_delete($lock,self::GROUP);
        }
    }

    public static function pending_root($node):?array {
        if(self::mode()==='off' || !class_exists('MAD4B_Auto_Public_Family_V1',false)
            || !is_object($node) || !method_exists($node,'get_id')
            || !class_exists('\Elementor\Plugin',false))return null;
        $document=\Elementor\Plugin::$instance->documents->get_current();
        if(!is_object($document)||!method_exists($document,'get_id'))return null;
        $id=(int)$document->get_id();$rid=(string)$node->get_id();
        $entry=self::read_pending()['roots'][$id.'|'.$rid]??null;
        if(!is_array($entry) || ($entry['stage']??'')!=='native_witness')return null;
        $raw=get_post_meta($id,'_elementor_data',true);
        if(!is_string($raw)||!hash_equals((string)$entry['family_sha'],hash('sha256',$raw))
            || (MAD4B_Auto_Public_Family_V1::family_map()[$id]??'')!==$entry['family_sha'])
            return null;
        $nodes=json_decode($raw,true);
        $sub=is_array($nodes)?self::root_node($nodes,$rid):null;
        if(!is_array($sub)||($sub['elType']??'')!=='container'
            || !hash_equals((string)$entry['root_sha'],hash('sha256',
                (string)wp_json_encode($sub,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)))
            || !MAD4B_Auto_Public_Family_V1::analyze_node($sub)['eligible'])return null;
        return $entry;
    }
    public static function witness(array $entry,string $html,array $scripts,array $styles):array {
        if(self::mode()==='off'||!MAD4B_Auto_Public_Family_V1::safe_html($html)
            || count($scripts)>32||count($styles)>48)
            return ['status'=>'native_unsafe'];
        $id=(int)$entry['family'];$rid=(string)$entry['id'];
        $once=$id.'|'.$rid;
        if(isset(self::$witnessed_this_request[$once]))
            return ['status'=>'same_request_not_a_second_witness'];
        self::$witnessed_this_request[$once]=true;
        if(!wp_cache_add('witness_'.$id.'_'.$rid,1,self::GROUP,20))
            return ['status'=>'locked'];
        try {
            $pending=self::read_pending();
            $key=$id.'|'.$rid;$live=$pending['roots'][$key]??null;
            if(!is_array($live)||($live['family_sha']??'')!==$entry['family_sha']
                ||($live['root_sha']??'')!==$entry['root_sha'])
                return ['status'=>'superseded'];
            sort($scripts);sort($styles);
            $digest=hash('sha256',$html.'|'.wp_json_encode([$scripts,$styles]));
            $scope=hash('sha256',wp_json_encode([
                apply_filters('wpml_current_language',''),get_locale(),
                function_exists('get_woocommerce_currency')?get_woocommerce_currency():'',
                function_exists('wp_is_mobile')?wp_is_mobile():false,
                (string)($_SERVER['HTTP_USER_AGENT']??''),
                (string)($_SERVER['HTTP_COOKIE']??'')]));
            $prior=$live['sample']??null;
            if(!is_array($prior)||($prior['digest']??'')!==$digest
                ||($prior['scope']??'')!==$scope
                ||(time()-(int)($prior['when']??0))>1800) {
                $live['sample']=['digest'=>$digest,'scope'=>$scope,'when'=>time()];
                $live['samples']=1;
                $pending['roots'][$key]=$live;
                update_option(self::PENDING,$pending,false);
                return ['status'=>'first_native_witness','family'=>$id,'root'=>$rid];
            }
            unset($pending['roots'][$key]);
            if(!update_option(self::PENDING,$pending,false))
                return ['status'=>'pending_write_failed'];
            self::record('TWO_NATIVE_WITNESSES',$id,$rid);
            return ['status'=>'approved_after_two_native_witnesses','family'=>$id,'root'=>$rid];
        }finally{
            wp_cache_delete('witness_'.$id.'_'.$rid,self::GROUP);
        }
    }
    public static function status():array {
        $s=self::read_state();$p=self::read_pending();
        return ['mode'=>self::mode(),'families'=>count($s['families']??[]),
            'revision'=>(int)($s['revision']??0),'pending_count'=>count($p['roots']),
            'pending_roots'=>array_keys($p['roots'])];
    }
}
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)
    add_action($hook,[MAD4B_Auto_Family_Revision_V1::class,'changed_meta'],210,4);
add_action('elementor/document/after_save',
    [MAD4B_Auto_Family_Revision_V1::class,'elementor_saved'],220,2);
add_action('shutdown',[MAD4B_Auto_Family_Revision_V1::class,'shutdown'],9998);
