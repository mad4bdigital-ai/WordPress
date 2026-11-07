<?php
/**
 * Plugin Name: MAD4B Tour Gallery — Notify, Never Block
 * Description: Renders legacy gallery URLs through local attachment IDs while reporting duplicates to MAD4B Content Readiness.
 * Version: 2.1.1
 */
defined('ABSPATH') || exit;

/*
 * The old "replace shared gallery with featured image" behavior is retired.
 * Legacy URL galleries are normalized to WordPress attachment IDs only at
 * JetEngine's presentation boundary so UAEL can render their images.
 * All authored metadata remains unchanged. Duplicate or imported media
 * is never rejected. Content Readiness owns quality alerts, not the viewer.
 * No Content Jobs, emails or remote writes are created by the viewer.
 */
final class MAD4B_Tour_Gallery_Presentation_V2 {
    public static function normalize($value,$key,$post_id) {
        if ($key!=='gallery' || !is_string($value) || !str_contains($value,'/uploads/')
            || get_post_type((int)$post_id)!=='tours-and-activities') return $value;

        $urls=array_values(array_filter(array_map('trim',explode(',',$value)),'strlen'));
        if (!$urls || count($urls)>500) return $value;
        $paths=[];
        foreach ($urls as $url) {
            $parts=wp_parse_url($url);
            if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true))
                return $value;
            $path=rawurldecode((string)($parts['path']??''));
            $at=strpos($path,'/wp-content/uploads/');
            if ($at===false)return $value;
            $relative=substr($path,$at+strlen('/wp-content/uploads/'));
            if ($relative==='' || str_contains($relative,'..') || str_contains($relative,'\\')
                || preg_match('/[\x00-\x1f]/',$relative)) return $value;
            $paths[]=$relative;
        }
        $cache_key='gallery-map-'.hash('sha256',$value);
        $cached=wp_cache_get($cache_key,'mad4b_gallery_presentation');
        if (is_string($cached) && $cached!=='')return $cached;

        $unique=array_values(array_unique($paths));
        global $wpdb;
        $args=array_merge(['_wp_attached_file'], $unique);
        $marks=implode(',',array_fill(0,count($unique),'%s'));
        $sql=$wpdb->prepare(
            "SELECT pm.post_id,pm.meta_value FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE pm.meta_key=%s AND p.post_type='attachment'
             AND p.post_mime_type LIKE 'image/%%'
             AND pm.meta_value IN ($marks) ORDER BY pm.post_id ASC",
            ...$args
        );
        $rows=$wpdb->get_results($sql,ARRAY_A);
        if (!is_array($rows)||$wpdb->last_error)return $value;
        $by_path=[];
        foreach ($rows as $row) {
            $f=(string)($row['meta_value']??'');
            if (!isset($by_path[$f]))$by_path[$f]=(int)$row['post_id'];
        }
        $ids=[];
        foreach ($paths as $path) {
            if (isset($by_path[$path]))$ids[]=$by_path[$path];
        }
        // Rendering maps only locally available assets; unresolved URLs
        // remain in the original meta unchanged for editorial follow-up.
        if (!$ids)return $value;
        $presentation=implode(',',array_values(array_unique($ids)));
        wp_cache_set($cache_key,$presentation,'mad4b_gallery_presentation',HOUR_IN_SECONDS);
        return $presentation;
    }
}
add_filter('jet-engine/listing/data/get-post-meta',
    ['MAD4B_Tour_Gallery_Presentation_V2','normalize'],25,3);

final class MAD4B_Tour_Gallery_Advisory_V2 {
    private const SOURCE = 'mad4b-tour-gallery-diagnostics';
    private const CODE = 'media_gallery_reused_across_tours';
    private const MAX_POSTS = 3000;

    /**
     * Read-only advisory on the existing MAD4B Content Readiness screen.
     * Does not write an issue row or bypass the site's scheduled-run policy.
     */
    public static function admin_notice(): void {
        if (!current_user_can('manage_options')
            && !current_user_can('mad4b_manage_company_operations')) return;
        $page=isset($_GET['page']) && is_scalar($_GET['page'])
            ? sanitize_key(wp_unslash((string)$_GET['page'])) : '';
        if ($page!=='mad4b-content-readiness-alerts') return;
        $rows=self::issues([],['post_types'=>['tours-and-activities']]);
        $count=0;
        foreach ($rows as $row) {
            if (($row['source']??'')==='mad4b-tour-gallery-diagnostics'
                && ($row['code']??'')===self::CODE) $count++;
        }
        if ($count<1)return;
        echo '<div class="notice notice-warning"><p>';
        printf(
            esc_html__('Media advisory: %d tour families reuse gallery images. Review media sources and licensing. All available images remain visible; no content was blocked.','mad4b'),
            $count
        );
        echo '</p><p>';
        echo esc_html__('Read-only live diagnostics. Formal issue records follow the authorized Content Readiness scan lifecycle.','mad4b');
        echo '</p></div>';
    }

    public static function issues($issues, $policy) {
        if (!is_array($issues)) return $issues;
        // Only the existing MAD4B readiness scan calls this hook.
        // This never scans or writes on customer-facing page requests.
        if (!is_array($policy) || !in_array('tours-and-activities', (array)($policy['post_types']??[]), true))
            return $issues;

        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT pm.post_id, SHA2(pm.meta_value,256) AS digest
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE pm.meta_key=%s AND p.post_type=%s AND p.post_status=%s
               AND LENGTH(pm.meta_value)>%d ORDER BY pm.post_id ASC LIMIT %d",
            'gallery', 'tours-and-activities', 'publish', 50, self::MAX_POSTS + 1
        );
        $rows = $wpdb->get_results($query, ARRAY_A);
        // Explicit scan failure: stop the global reconciler from incorrectly
        // closing previously discovered alerts. Do not alter gallery output.
        if (!is_array($rows) || $wpdb->last_error || count($rows)>self::MAX_POSTS) {
            $issues[] = array('source'=>self::SOURCE,'code'=>'media_gallery_scan_incomplete');
            return $issues;
        }

        $default_language=(string)apply_filters('wpml_default_language','en');
        $groups=[];
        foreach ($rows as $row) {
            $post_id=absint($row['post_id']??0);
            $digest=(string)($row['digest']??'');
            if (!$post_id || !preg_match('/^[a-f0-9]{64}$/D',$digest))continue;
            $canonical=(int)apply_filters('wpml_object_id',$post_id,'tours-and-activities',false,$default_language);
            if (!$canonical || get_post_status($canonical)!=='publish') $canonical=$post_id;
            $groups[$digest][$canonical]=true;
        }

        foreach ($groups as $fingerprint=>$families) {
            $count=count($families);
            if ($count<2)continue;
            foreach (array_keys($families) as $canonical) {
                $code=self::CODE;
                $issues[]=[
                    'id'=>hash('sha256',get_current_blog_id().'|tours-and-activities|'.$canonical.'|'.$code),
                    'post_type'=>'tours-and-activities',
                    'object_id'=>$canonical,
                    'code'=>$code,
                    'source'=>self::SOURCE,
                    'severity'=>'medium',
                    'label'=>sprintf(
                        'Gallery reused by %d tour families. Review source, ownership and licensing. Gallery remains published; no media blocked.',
                        $count
                    ),
                ];
            }
        }
        return $issues;
    }
}
add_filter('mad4b/content_readiness/external_issues',
    ['MAD4B_Tour_Gallery_Advisory_V2','issues'],25,2);

add_action('admin_notices', ['MAD4B_Tour_Gallery_Advisory_V2','admin_notice'],35);
