<?php
namespace DynamicFilterSEOBridge\Integration;

/**
 * Read-only visitor impact for WPML SEO-route gap triage.
 * We do not make missing translations public or alter product eligibility.
 */
final class RewriteGapPriority {
    public const CONTRACT='dfsb.wpml-gap-priority.v1';

    public static function inspect(string $subject, int $termId=0, string $reason=''): array {
        static $cache=array();
        $cacheKey=$subject.'|'.$termId.'|'.$reason;
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        return $cache[$cacheKey]=self::evaluate($subject,$termId,$reason);
    }

    private static function evaluate(string $subject, int $termId, string $reason): array {
        $taxonomy=get_taxonomy($subject);
        $type=get_post_type_object($subject);
        if ($termId>0 && $taxonomy) {
            $term=get_term($termId,$subject);
            if (!$term instanceof \WP_Term) return self::none('term_unavailable');
            $count=max(0,(int)$term->count);
            $isDestination=$subject==='destinations';
            $visibility='not_applicable';
            if ($isDestination) {
                $visibility=trim((string)get_term_meta($termId,'is_public',true));
                if ($visibility==='') $visibility='unset';
            }
            $service=self::service($taxonomy->object_type);
            $public=(bool)$taxonomy->public && $service['visible'];
            // Required public flag on destination term. 0 means explicit
            // exclusion even when the parent has published content.
            $isPublic=!$isDestination || (
                $visibility==='1' &&
                !in_array((string)get_term_meta($termId,'destination_public_status',true),
                    array('0','false','disabled','no'),true) &&
                (!function_exists('destinations_activation_allowed') ||
                    destinations_activation_allowed($termId))
            );
            $score=($public?40:10)+($service['active']?20:0)+($count>0?25:0)+
                ($isDestination && $isPublic?15:0);
            if ($isDestination && !$isPublic) $score=min($score,25);
            return array(
                'priority'=>min(100,$score),
                'tier'=>($public && $service['active'] && $count>0 && $isPublic)
                    ? 'visitor_facing_published_term'
                    : (($isDestination && !$isPublic)?'destination_not_public':'candidate'),
                'source_published_term_count'=>$count,
                'destination_is_public'=>$isDestination?$visibility:null,
                'service_active'=>$service['active'],
                'frontend_visible'=>$public,
            );
        }
        // CPTs and taxonomies may legally share a registration name. The
        // issue reason, not name coincidence, selects the source contract.
        if ($taxonomy && $reason!=='cpt_base_unverified') {
            $count=wp_count_terms(array('taxonomy'=>$subject,'hide_empty'=>true));
            if (is_wp_error($count)) return self::none('term_count_unavailable');
            $service=self::service($taxonomy->object_type);
            $public=(bool)$taxonomy->public && $service['visible'];
            $score=($public?35:5)+($service['active']?25:0)+((int)$count>0?25:0);
            return array('priority'=>min(100,$score),
                'tier'=>$public && $service['active'] && (int)$count>0?
                    'active_service_published_taxonomy':'candidate',
                'source_published_term_count'=>(int)$count,
                'service_active'=>$service['active'],'frontend_visible'=>$public);
        }
        if ($type) {
            $published=wp_count_posts($subject);
            $published=$published instanceof \stdClass?(int)($published->publish??0):0;
            $configured=(bool)$type->public && (bool)$type->publicly_queryable &&
                !empty($type->rewrite) && $published>0 &&
                (!empty($type->has_archive) || !empty($type->show_in_nav_menus));
            // Published count is not public visibility: source installations
            // may deliberately return 404 for a seemingly public CPT.
            $proof=$configured?self::frontendProof($subject):array('visible'=>false,'reason'=>'not_frontend_configured');
            $visible=$configured && !empty($proof['visible']);
            $service=self::service(array($subject));
            $score=($visible?40:5)+($service['active']?25:0)+($published>0?25:0);
            if (!$visible) $score=min($score,30);
            return array('priority'=>min(100,$score),
                'tier'=>$visible && $service['active']?'active_service_frontend_cpt':'internal_or_unproven_cpt',
                'published_posts'=>$published,
                'service_active'=>$service['active'],'frontend_visible'=>$visible,
                'frontend_evidence'=>$proof);
        }
        return self::none('unregistered_subject');
    }

    private static function frontendProof(string $postType): array {
        static $requests=0;
        static $cached=array();
        if (isset($cached[$postType])) return $cached[$postType];
        $urls=array();
        $archive=get_post_type_archive_link($postType);
        if (is_string($archive) && $archive) $urls[]=$archive;
        $posts=get_posts(array('post_type'=>$postType,'post_status'=>'publish',
            'posts_per_page'=>1,'fields'=>'ids','suppress_filters'=>true));
        if (is_array($posts) && $posts) {
            $permalink=get_permalink((int)$posts[0]);
            if (is_string($permalink) && $permalink) $urls[]=$permalink;
        }
        $homeHost=wp_parse_url(home_url('/'),PHP_URL_HOST);
        $evidence=array('visible'=>false,'reason'=>'no_verified_frontend_route','checked'=>array());
        foreach(array_unique($urls) as $url) {
            if (++$requests > 24) {
                $evidence['reason']='bounded_visibility_probe_exhausted';
                break;
            }
            if (!is_string($homeHost) || wp_parse_url($url,PHP_URL_HOST)!==$homeHost
                || wp_parse_url($url,PHP_URL_SCHEME)!==wp_parse_url(home_url('/'),PHP_URL_SCHEME))
                continue;
            // Only HEAD, no visitor mutation and no redirects to a foreign host.
            $response=wp_remote_head($url,array('timeout'=>3,'redirection'=>0,'sslverify'=>true));
            $status=is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response);
            $evidence['checked'][]=array('url'=>$url,'status'=>$status);
            if ($status===200) {
                $evidence['visible']=true;
                $evidence['reason']='frontend_http_200';
                break;
            }
        }
        return $cached[$postType]=$evidence;
    }

    private static function service(array $types): array {
        $active=false;$visible=false;
        foreach ($types as $postType) {
            $post=get_post_type_object((string)$postType);
            if (!$post || !$post->publicly_queryable || !$post->public) continue;
            $counts=wp_count_posts($postType);
            if (!is_object($counts) || (int)($counts->publish??0)<1) continue;
            if (empty($post->has_archive) && empty($post->show_in_nav_menus)) continue;
            $visible=true;
            // An explicit service policy denial must not be bypassed.
            if (function_exists('service_channel_allowed') &&
                service_channel_allowed((string)$postType,'destination_discovery')) {
                $active=true;
            }
        }
        return array('active'=>$active,'visible'=>$visible);
    }

    private static function none(string $reason): array {
        return array('priority'=>10,'tier'=>$reason,'service_active'=>false,'frontend_visible'=>false);
    }
}
