<?php
defined('ABSPATH') || exit;
/**
 * Governed native WordPress editorial publishing image gate.
 * Site scope is explicitly activated by its loader (Staging only).
 * Publishing requires a valid, local Media Library featured image.
 * This is not a content-rights or relevance certification.
 */
final class MAD4B_Editorial_Featured_Image_Gate {
    const CONTRACT = 'mad4b.editorial-featured-image-publish-gate.v1';
    const NOTICE_PREFIX = 'mad4b_featured_image_gate_notice_';
    private static $recovering = false;
    private static $rest_media_hint = null;
    private static $blocked = [];
    public static function boot() {
        add_filter('wp_insert_post_data', [__CLASS__, 'before_save'], 999, 4);
        add_filter('rest_pre_insert_post', [__CLASS__, 'before_rest_post'], 30, 2);
        add_action('rest_after_insert_post', [__CLASS__, 'after_rest_post'], 99, 3);
        add_action('publish_future_post', [__CLASS__, 'before_cron_publish'], 1, 1);
        add_action('transition_post_status', [__CLASS__, 'ensure_transition'], 1, 3);
        add_action('deleted_post_meta', [__CLASS__, 'thumbnail_deleted'], 10, 4);
        add_action('updated_post_meta', [__CLASS__, 'thumbnail_updated'], 10, 4);
        add_action('added_post_meta', [__CLASS__, 'thumbnail_updated'], 10, 4);
        // Removing a Media Library image must not strand published articles.
        add_action('delete_attachment', [__CLASS__, 'attachment_deleting'], 1, 1);
        add_action('wp_trash_post', [__CLASS__, 'attachment_trashing'], 1, 1);
        add_action('save_post', [__CLASS__, 'save_notice'], 99, 3);
        add_action('admin_notices', [__CLASS__, 'notice']);
        add_action('wp_abilities_api_init', [__CLASS__, 'register_ability'], 65);
        add_action('rest_api_init', [__CLASS__, 'register_rest'], 65);
    }
    private static function types() {
        // Pages, commerce products, templates and attachments are not posts.
        // Register additional editorial CPTs only through a deliberate filter.
        $types = apply_filters('mad4b_editorial_featured_image_required_post_types', ['post']);
        if (!is_array($types)) return ['post'];
        return array_values(array_unique(array_filter($types, static function($value) {
            return is_string($value) && (bool)preg_match('/^[a-z0-9_-]{2,32}$/D',$value);
        })));
    }
    public static function applies($type) {
        return in_array((string)$type, self::types(), true);
    }
    private static function is_publication($status) {
        return in_array($status, ['publish', 'future'], true);
    }
    public static function verify_image($id) {
        $id = absint($id);
        if (!$id) return ['valid'=>false,'reason'=>'featured_image_missing','attachment_id'=>0];
        if ('attachment' !== get_post_type($id) || !wp_attachment_is_image($id))
            return ['valid'=>false,'reason'=>'featured_image_not_image_attachment','attachment_id'=>$id];
        $file = get_attached_file($id, true);
        // Never certify broken attachment paths or unreachable local assets.
        $on_disk = is_string($file) && $file!=='' && is_file($file)
            && is_readable($file) && @filesize($file) > 0;
        if (!$on_disk)
            return ['valid'=>false,'reason'=>'featured_image_file_missing','attachment_id'=>$id];
        $image = wp_get_attachment_image_src($id,'full');
        if (!is_array($image) || empty($image[0]) ||
            (int)($image[1]??0) < 1 || (int)($image[2]??0) < 1)
            return ['valid'=>false,'reason'=>'featured_image_dimensions_missing','attachment_id'=>$id];
        return ['valid'=>true,'reason'=>'ok','attachment_id'=>$id];
    }
    private static function candidate($post_id,$provided=null) {
        $id = is_null($provided) ? ($post_id > 0 ? (int)get_post_thumbnail_id($post_id) : 0) : absint($provided);
        return self::verify_image($id);
    }
    private static function meta_candidate($postarr) {
        if (!is_array($postarr)) return null;
        if (isset($postarr['meta_input']) && is_array($postarr['meta_input'])
            && array_key_exists('_thumbnail_id',$postarr['meta_input']))
            return absint($postarr['meta_input']['_thumbnail_id']);
        if (array_key_exists('_thumbnail_id',$postarr))
            return absint($postarr['_thumbnail_id']);
        // Native classic editor may post the selected thumbnail separately.
        if (isset($_POST['_thumbnail_id']) && is_scalar($_POST['_thumbnail_id']))
            return absint(wp_unslash($_POST['_thumbnail_id']));
        return null;
    }
    public static function before_rest_post($prepared,$request) {
        if (is_wp_error($prepared) || !is_object($prepared) || !is_object($request))return $prepared;
        $status = (string)($prepared->post_status ?? $request->get_param('status') ?? '');
        if (!self::is_publication($status))return $prepared;
        $type = (string)($prepared->post_type ?? 'post');
        if (!self::applies($type))return $prepared;
        $post_id = absint($request->get_param('id') ?: ($prepared->ID ?? 0));
        $provided = $request->has_param('featured_media') ? absint($request->get_param('featured_media')) : null;
        $check=self::candidate($post_id,$provided);
        if (!$check['valid'])
            return new WP_Error('mad4b_editorial_featured_image_required',
                'Publishing is blocked. Add a valid Featured Image in the WordPress Media Library and retry.',
                ['status'=>422,'blocker'=>$check['reason'],'post_id'=>$post_id]);
        // One-shot hint: REST stores featured_media after wp_insert_post_data.
        if ($provided !== null)self::$rest_media_hint=['post_id'=>$post_id,'attachment_id'=>$provided];
        return $prepared;
    }
    public static function before_save($data,$postarr,$unsanitized_postarr,$update) {
        if (self::$recovering || !is_array($data))return $data;
        $status=(string)($data['post_status']??'');
        $type=(string)($data['post_type']??'');
        if (!self::applies($type) || !self::is_publication($status))return $data;
        $post_id = absint($postarr['ID']??$unsanitized_postarr['ID']??0);
        $provided=self::meta_candidate($unsanitized_postarr);
        if ($provided===null) $provided=self::meta_candidate($postarr);
        if (self::$rest_media_hint!==null) {
            $hint=self::$rest_media_hint;
            // REST new post and existing post insert callbacks have one data save.
            if ((int)$hint['post_id']===$post_id) {
                $provided=(int)$hint['attachment_id'];
                self::$rest_media_hint=null;
            }
        }
        $check=self::candidate($post_id,$provided);
        if ($check['valid']) return $data;
        // Native save API does not accept WP_Error from this hook.
        // Draft the post instead, protecting all WP-CLI and internal writes.
        $data['post_status']='draft';
        self::$blocked[$post_id?:0]=$check['reason'];
        self::set_notice($check['reason']);
        return $data;
    }
    private static function set_notice($reason) {
        if (get_current_user_id()>0) set_transient(self::NOTICE_PREFIX.get_current_user_id(),(string)$reason,90);
    }
    public static function save_notice($post_id,$post,$update) {
        if (!self::applies($post->post_type ?? '') || !isset(self::$blocked[$post_id]))
            return;
        update_post_meta($post_id,'_mad4b_editorial_publish_image_blocker',
            sanitize_key(self::$blocked[$post_id]));
        unset(self::$blocked[$post_id]);
    }
    private static function demote($post_id,$reason) {
        if (self::$recovering || !$post_id) return;
        $post=get_post($post_id);
        if (!$post || !self::applies($post->post_type) ||
            !self::is_publication($post->post_status))return;
        self::$recovering=true;
        try {
            $changed=wp_update_post(['ID'=>$post_id,'post_status'=>'draft'],true);
            if (!is_wp_error($changed)) {
                update_post_meta($post_id,'_mad4b_editorial_publish_image_blocker',sanitize_key($reason));
                self::set_notice($reason);
            }
        }finally{self::$recovering=false;}
    }
    public static function before_cron_publish($post_id) {
        $post=get_post($post_id);
        if (!$post || !self::applies($post->post_type) || $post->post_status!=='future')return;
        $check=self::candidate((int)$post_id);
        if (!$check['valid'])self::demote((int)$post_id,$check['reason']);
    }
    public static function ensure_transition($new,$old,$post) {
        if (self::$recovering || $new!=='publish' || !self::applies($post->post_type??''))return;
        $check=self::candidate((int)$post->ID);
        if (!$check['valid'])self::demote((int)$post->ID,$check['reason']);
    }
    public static function thumbnail_deleted($ids,$post_id,$key,$value) {
        if ($key!=='_thumbnail_id')return;
        self::thumbnail_changed($post_id);
    }
    public static function thumbnail_updated($meta_id,$post_id,$key,$value) {
        if ($key!=='_thumbnail_id')return;
        self::thumbnail_changed($post_id);
    }
    private static function thumbnail_changed($post_id) {
        $post=get_post($post_id);
        if (!$post || !self::applies($post->post_type) ||
            !self::is_publication($post->post_status))return;
        $check=self::candidate((int)$post_id);
        if (!$check['valid'])self::demote((int)$post_id,$check['reason']);
    }
    public static function attachment_trashing($attachment_id) {
        if ('attachment'===get_post_type($attachment_id))
            self::attachment_deleting($attachment_id);
    }
    public static function attachment_deleting($attachment_id) {
        $attachment_id=absint($attachment_id);
        if (!$attachment_id || !self::types())return;
        // Query only the exact attachment reference and currently publishable
        // editorial types. Demotion removes records from the next batch.
        global $wpdb;
        $types=self::types();
        $placeholders=implode(',',array_fill(0,count($types),'%s'));
        $query="SELECT DISTINCT p.ID FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
            WHERE pm.meta_key='_thumbnail_id' AND pm.meta_value=%s
              AND p.post_type IN ($placeholders)
              AND p.post_status IN ('publish','future')
            ORDER BY p.ID ASC LIMIT 200";
        for ($pass=0;$pass<30;$pass++) {
            $params=array_merge([(string)$attachment_id],$types);
            $ids=$wpdb->get_col($wpdb->prepare($query,$params));
            if (!is_array($ids) || !$ids)break;
            foreach($ids as $id)
                self::demote((int)$id,'featured_image_attachment_deleted');
            if(count($ids)<200)break;
        }
    }
    public static function notice() {
        if (!current_user_can('edit_posts'))return;
        $user=get_current_user_id();if (!$user)return;
        $key=self::NOTICE_PREFIX.$user;
        $reason=get_transient($key);
        if (!$reason)return;
        delete_transient($key);
        echo '<div class="notice notice-warning is-dismissible"><p><strong>'
          .esc_html__('Publishing blocked: featured image required.','mad4b')
          .'</strong> '.esc_html__('The article was kept as a draft. Add a valid Featured Image to publish or schedule it.','mad4b')
          .' <code>'.esc_html($reason).'</code></p></div>';
    }
    public static function audit($args=[]) {
        if (!current_user_can('edit_posts'))return new WP_Error('mad4b_editorial_image_read_denied','Permission denied',['status'=>403]);
        $post_id=absint($args['post_id']??0);
        if ($post_id) {
            $post=get_post($post_id);
            if (!$post || !self::applies($post->post_type))
                return new WP_Error('mad4b_editorial_image_post_unavailable','Unsupported or missing article',['status'=>404]);
            $check=self::candidate($post_id);
            return ['contract'=>self::CONTRACT,'post_id'=>$post_id,'post_status'=>$post->post_status,
                'image'=>$check,'publish_ready'=>$check['valid'],'needs_image'=>!$check['valid'],
                'mutation_performed'=>false];
        }
        $limit=max(1,min(100,absint($args['limit']??50)));
        $ids=get_posts(['post_type'=>self::types(),'post_status'=>['publish','future'],
            'fields'=>'ids','posts_per_page'=>$limit,'orderby'=>'modified','order'=>'DESC',
            'suppress_filters'=>false]);
        $missing=[];
        foreach($ids as $id){
            $check=self::candidate((int)$id);
            if (!$check['valid'])$missing[]=['post_id'=>(int)$id,
                'title'=>get_the_title((int)$id),'status'=>get_post_status((int)$id),'reason'=>$check['reason']];
        }
        return ['contract'=>self::CONTRACT,'scope_types'=>self::types(),
            'examined'=>count($ids),'missing_image'=>$missing,'missing_count'=>count($missing),
            'limit'=>$limit,'partial_scan'=>count($ids)===$limit,
            'mutation_performed'=>false];
    }
    public static function register_rest() {
        register_rest_route('mad4b-hub/v1','/editorial-image-publish-readiness',[
            'methods'=>'GET','permission_callback'=>static fn()=>current_user_can('edit_posts'),
            'callback'=>static fn($request)=>self::audit($request->get_params())]);
    }
    public static function register_ability() {
        if (!function_exists('wp_register_ability') || wp_has_ability('mad4b/editorial-image-publish-readiness'))return;
        wp_register_ability('mad4b/editorial-image-publish-readiness',[
            'label'=>'Editorial Featured Image Publish Readiness',
            'description'=>'Read-only proof that each article has a valid featured image before publishing. Supports native WP and Content Hub workflows.',
            'category'=>'mad4b-read','execute_callback'=>[__CLASS__,'audit'],
            'permission_callback'=>static fn()=>current_user_can('edit_posts'),
            'input_schema'=>['type'=>'object','properties'=>[
                'post_id'=>['type'=>'integer'],'limit'=>['type'=>'integer']],
                'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['public'=>false,'show_in_rest'=>false,
                'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
        ]);
    }
}
