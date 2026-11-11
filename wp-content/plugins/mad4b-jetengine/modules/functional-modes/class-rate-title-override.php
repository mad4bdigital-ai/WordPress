<?php
/**
 * MAD4B Native Tour Rate Title Override v1.
 *
 * Post titles (not a presentation-only label) are canonicalized from:
 * ID · original season taxonomy label · base currency · UTC validity period.
 *
 * Hooks queue the Rate and finalize once at shutdown, after JetEngine,
 * WPML, rate-generation meta_input and wp_set_object_terms are complete.
 * No rate prices, booking state, status, slug or taxonomy are modified.
 */
defined('ABSPATH') || exit;
final class MAD4B_JetEngine_Rate_Title_Override_V1 {
    public const ORIGINAL = '_mad4b_rate_title_original_v1';
    public const GENERATED = '_mad4b_rate_title_generated_v1';
    private const CPT = 'tour-rates';
    private static array $pending = [];
    private static bool $applying = false;
    private static bool $flushing = false;

    public static function boot(): void {
        add_action('save_post_'.self::CPT, [__CLASS__,'saved'], 90, 3);
        add_action('wp_after_insert_post', [__CLASS__,'inserted'], 90, 4);
        foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook) {
            add_action($hook,[__CLASS__,'meta_changed'],90,4);
        }
        add_action('set_object_terms',[__CLASS__,'terms_changed'],90,6);
        add_action('deleted_term_relationships',[__CLASS__,'terms_deleted'],90,3);
        add_action('edited_term',[__CLASS__,'term_renamed'],90,3);
        add_action('shutdown',[__CLASS__,'flush'],70);
        if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('mad4b rate-titles',[__CLASS__,'cli']);
        }
    }
    public static function saved(int $id, WP_Post $post, bool $update): void {
        self::queue($id);
    }
    public static function inserted(int $id, WP_Post $post, bool $update, ?WP_Post $post_before): void {
        self::queue($id);
    }
    public static function meta_changed($meta_id,int $post_id,string $meta_key,$value): void {
        if(in_array($meta_key,['base_currency','tour_rate_start_date','tour_rate_end_date'],true)) {
            self::queue($post_id);
        }
    }
    public static function terms_changed(int $object_id,$terms,$tt_ids,string $taxonomy,$append,$old_tt_ids): void {
        if($taxonomy==='season') self::queue($object_id);
    }
    public static function terms_deleted(int $object_id,$tt_ids,string $taxonomy): void {
        if($taxonomy==='season') self::queue($object_id);
    }
    public static function term_renamed(int $term_id,int $tt_id,string $taxonomy): void {
        if($taxonomy!=='season') return;
        $ids=get_objects_in_term($term_id,'season');
        if(is_wp_error($ids))return;
        foreach((array)$ids as $id)self::queue((int)$id);
    }
    private static function queue(int $id): void {
        if(self::$applying || $id<1 || get_post_type($id)!==self::CPT) return;
        self::$pending[$id]=$id;
    }
    private static function valid_date($value): ?string {
        if(!is_scalar($value)) return null;
        $raw=trim((string)$value);
        if(ctype_digit($raw) && (int)$raw>0 && (int)$raw<4102444800)
            return gmdate('Y-m-d',(int)$raw);
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$raw)) {
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,new DateTimeZone('UTC'));
            return $date && $date->format('Y-m-d')===$raw?$raw:null;
        }
        return null;
    }
    public static function title(int $rate_id): ?string {
        if($rate_id<1||get_post_type($rate_id)!==self::CPT)return null;
        $post=get_post($rate_id);
        if(!$post || in_array($post->post_status,['trash','auto-draft','inherit'],true))
            return null;
        $resolved=MAD4B_JetEngine_Rate_Season_Term_Matcher_V1::resolve($rate_id);
        $season=$resolved['label'];
        $currency=strtoupper(trim((string)get_post_meta($rate_id,'base_currency',true)));
        if(!preg_match('/^[A-Z]{3}$/D',$currency))$currency='Currency not specified';
        $start=self::valid_date(get_post_meta($rate_id,'tour_rate_start_date',true));
        $end=self::valid_date(get_post_meta($rate_id,'tour_rate_end_date',true));
        $validity=$start||$end
            ?($start??'Not specified').' → '.($end??'Not specified')
            :'Validity not specified';
        $title='Rate #'.$rate_id.' · '.$season.' · '.$currency.' · '.$validity;
        // Preserve custom season terms while bounding wp_posts.post_title.
        return function_exists('mb_substr')?mb_substr($title,0,240):substr($title,0,240);
    }
    public static function apply(int $rate_id): array {
        $title=self::title($rate_id);
        $post=get_post($rate_id);
        if(!$post || $title===null) return [
            'id'=>$rate_id,'status'=>'skipped','reason'=>'not_an_active_tour_rate'
        ];
        if($post->post_title===$title) {
            $resolved=MAD4B_JetEngine_Rate_Season_Term_Matcher_V1::resolve($rate_id);
            $link=MAD4B_JetEngine_Rate_Season_Term_Matcher_V1::bind_if_safe($rate_id,$resolved);
            return ['id'=>$rate_id,'status'=>'unchanged','title'=>$title,
                'season_source'=>$resolved['source'],'season_binding'=>$link['status']];
        }
        if(self::$applying) return [
            'id'=>$rate_id,'status'=>'skipped','reason'=>'recursive_write_guard'
        ];
        // Honor the same MAD4B Lifecycle Access gate used by wp_update_post.
        // Native WP-CLI jobs must explicitly supply an authorized --user;
        // never impersonate an Administrator or bypass the lifecycle filter.
        $type=get_post_type_object(self::CPT);
        if(!$type || !current_user_can('edit_post',$rate_id)
            || (in_array($post->post_status,['publish','future','private'],true)
                && !current_user_can($type->cap->publish_posts))) {
            return ['id'=>$rate_id,'status'=>'skipped',
                'reason'=>'authorized_wordpress_publisher_required'];
        }
        self::$applying=true;
        try {
            // Store the exact original title one time only.
            if(!metadata_exists('post',$rate_id,self::ORIGINAL)){
                add_post_meta($rate_id,self::ORIGINAL,$post->post_title,true);
            }
            $previous=$post->post_title;
            $result=wp_update_post([
                'ID'=>$rate_id,'post_title'=>$title,
                // Keep the canonical route stable even for already published
                // Rates; changing names must not change existing permalinks.
                'post_name'=>$post->post_name
            ],true);
            if(is_wp_error($result) || !$result) {
                return ['id'=>$rate_id,'status'=>'error',
                    'reason'=>is_wp_error($result)?$result->get_error_code():'update_failed'];
            }
            update_post_meta($rate_id,self::GENERATED,$title);
            $resolved=MAD4B_JetEngine_Rate_Season_Term_Matcher_V1::resolve($rate_id);
            $link=MAD4B_JetEngine_Rate_Season_Term_Matcher_V1::bind_if_safe($rate_id,$resolved);
            return ['id'=>$rate_id,'status'=>'updated','before'=>$previous,
                'after'=>$title,'season_source'=>$resolved['source'],
                'season_binding'=>$link['status'],
                'slug_unchanged'=>get_post_field('post_name',$rate_id)===$post->post_name];
        } finally {
            self::$applying=false;
        }
    }
    public static function flush(): void {
        if(self::$flushing||self::$applying||!self::$pending)return;
        self::$flushing=true;
        try {
            // A single request may create many Rate posts. Process them
            // only after meta and season relationship updates complete.
            $ids=array_values(self::$pending);
            self::$pending=[];
            foreach($ids as $id)self::apply((int)$id);
        } finally {
            self::$flushing=false;
        }
    }
    public static function rollback(int $rate_id): array {
        if(get_post_type($rate_id)!==self::CPT ||
            !metadata_exists('post',$rate_id,self::ORIGINAL))
            return ['id'=>$rate_id,'status'=>'skipped','reason'=>'no_original_title'];
        $before=(string)get_post_meta($rate_id,self::ORIGINAL,true);
        $generated=(string)get_post_meta($rate_id,self::GENERATED,true);
        $post=get_post($rate_id);
        $type=get_post_type_object(self::CPT);
        if(!$post || !$type || !current_user_can('edit_post',$rate_id)
           || (in_array($post->post_status,['publish','future','private'],true)
              && !current_user_can($type->cap->publish_posts))) {
            return ['id'=>$rate_id,'status'=>'skipped',
                'reason'=>'authorized_wordpress_publisher_required'];
        }
        if($generated===''||$post->post_title!==$generated) {
            return ['id'=>$rate_id,'status'=>'conflict',
                'reason'=>'title_changed_since_override'];
        }
        $matcher='MAD4B_JetEngine_Rate_Season_Term_Matcher_V1';
        $ownership=$matcher::owned_rollback_status($rate_id);
        if($ownership['status']==='managed_term_changed') {
            return ['id'=>$rate_id,'status'=>'conflict',
                'reason'=>'auto_season_relation_changed_since_override'];
        }
        self::$applying=true;
        try {
            $result=wp_update_post([
                'ID'=>$rate_id,'post_title'=>$before,'post_name'=>$post->post_name
            ],true);
            if(is_wp_error($result)||!$result)
                return ['id'=>$rate_id,'status'=>'error','reason'=>'wp_update_post_failed'];
            $seasonRollback=$matcher::rollback_owned($rate_id);
            if(!in_array($seasonRollback['status'],
                ['not_owned','auto_season_unlinked'],true)) {
                // The title was restored but the term relationship was not
                // cleared: keep the provenance snapshots for reconciliation.
                return ['id'=>$rate_id,'status'=>'error',
                    'reason'=>'title_restored_season_rollback_'.$seasonRollback['status']];
            }
            delete_post_meta($rate_id,self::GENERATED);
            delete_post_meta($rate_id,self::ORIGINAL);
            return ['id'=>$rate_id,'status'=>'restored','title'=>$before,
                'season_rollback'=>$seasonRollback['status']];
        }finally{
            self::$applying=false;
        }
    }
    public static function cli(array $args,array $opts): void {
        $command=(string)($args[0]??'preview');
        if(!in_array($command,['preview','apply','rollback'],true))
            WP_CLI::error('Usage: wp mad4b rate-titles preview|apply|rollback [--ids=1,2] [--status=publish,draft]');
        if($command!=='preview'&&!isset($opts['confirm']))
            WP_CLI::error('Include --confirm for title modifications');
        $statuses=array_filter(array_map('sanitize_key',explode(',',(string)($opts['status']??'publish,draft'))));
        $statuses=array_values(array_intersect($statuses,['publish','draft','private','pending','future']));
        if(!$statuses)WP_CLI::error('No valid statuses');
        $ids=[];
        if(isset($opts['ids'])){
            foreach(explode(',',(string)$opts['ids']) as $id)
                if(ctype_digit(trim($id)))$ids[]=(int)$id;
            $ids=array_values(array_unique($ids));
        }else{
            $ids=get_posts([
                'post_type'=>self::CPT,'post_status'=>$statuses,
                'fields'=>'ids','posts_per_page'=>-1,
                'suppress_filters'=>true,'orderby'=>'ID','order'=>'ASC'
            ]);
        }
        $stats=['updated'=>0,'unchanged'=>0,'restored'=>0,
            'skipped'=>0,'conflict'=>0,'error'=>0,'would_update'=>0];
        $samples=[];
        foreach($ids as $id){
            $id=(int)$id;
            if(get_post_type($id)!==self::CPT ||
                !in_array(get_post_status($id),$statuses,true))continue;
            if($command==='rollback')$result=self::rollback($id);
            elseif($command==='apply')$result=self::apply($id);
            else{
                $current=get_the_title($id);
                $target=self::title($id);
                $result=['id'=>$id,'status'=>$target!==null&&$target!==$current
                    ?'would_update':'unchanged','before'=>$current,'after'=>$target];
            }
            $status=(string)$result['status'];
            $stats[$status]=($stats[$status]??0)+1;
            if(count($samples)<15 && $status!=='unchanged')$samples[]=$result;
        }
        WP_CLI::line(wp_json_encode(['operation'=>$command,
            'count'=>array_sum($stats),'stats'=>$stats,'samples'=>$samples],
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        // A rollback must not instantly reapply this Rate on shutdown.
        if($command==='rollback')self::$pending=[];
    }
}
