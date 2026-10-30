<?php
namespace MAD4B\DFSB\NativePresentation;

if (!defined('ABSPATH')) { exit; }

/**
 * Read-only discovery of actual Elementor -> JSF -> WordPress archive wiring.
 * Candidate discovery is not SEO approval, indexing, or rule creation.
 */
final class DynamicQualification {
    const CONTRACT = 'mad4b.jsf.dynamic-qualification.v1';

    public static function scan(bool $probe = false): array {
        $groups = array();
        $templates = get_posts(array(
            'post_type'=>array('elementor_library','page'),
            'post_status'=>'publish',
            'posts_per_page'=>-1, 'fields'=>'ids', 'suppress_filters'=>true,
            'orderby'=>'ID','order'=>'ASC',
        ));
        $seenTemplates = 0;
        foreach ($templates as $postId) {
            $tree = get_post_meta((int)$postId,'_elementor_data',true);
            if (is_string($tree)) $tree = json_decode($tree,true);
            if (!is_array($tree)) continue;
            $seenTemplates++;
            $post=get_post($postId);
            $lang=apply_filters('wpml_post_language_details',null,(int)$postId);
            $language=is_array($lang)?sanitize_key((string)($lang['language_code']??'')):'';
            self::visit($tree, static function(array $widget) use (&$groups,$postId,$post,$language): void {
                $type=(string)($widget['widgetType']??'');
                if (strpos($type,'jet-smart-filters-')!==0) return;
                $settings=$widget['settings']??array();
                if (!is_array($settings)) return;
                $provider=sanitize_key((string)($settings['content_provider']??''));
                $queryId=sanitize_key((string)($settings['query_id']??''));
                if ($provider==='' || $queryId==='') return;
                $key=$provider.'/'.$queryId;
                if (!isset($groups[$key])) $groups[$key]=array(
                    'provider'=>$provider,'query_id'=>$queryId,
                    'templates'=>array(),'languages'=>array(),'widgets'=>array(),
                    'filter_refs'=>array(),'apply_types'=>array(),
                );
                $g=&$groups[$key];
                $g['templates'][(int)$postId]=array('id'=>(int)$postId,
                    'title'=>wp_strip_all_tags((string)$post->post_title),
                    'post_type'=>(string)$post->post_type, 'language'=>$language);
                if ($language!=='') $g['languages'][$language]=true;
                $g['widgets'][$type]=($g['widgets'][$type]??0)+1;
                $apply=sanitize_key((string)($settings['apply_type']??''));
                if ($apply!=='') $g['apply_types'][$apply]=($g['apply_types'][$apply]??0)+1;
                if (in_array($type,array('jet-smart-filters-select',
                    'jet-smart-filters-checkboxes','jet-smart-filters-range',
                    'jet-smart-filters-date-range','jet-smart-filters-search',
                    'jet-smart-filters-color-image'),true)) {
                    // Elementor stores SELECT2 filter IDs as arrays, even for one filter.
                    // Do not infer IDs from labels, URL parameters, or unrelated widgets.
                    $raw=$settings['filter_id']??null;
                    $ids=is_array($raw)?$raw:array($raw);
                    $identified=false;
                    foreach ($ids as $ref) {
                        if ((is_string($ref)||is_int($ref)) && ctype_digit((string)$ref)
                            && (int)$ref>0) {
                            $id=(int)$ref;
                            $g['filter_refs'][$id]=($g['filter_refs'][$id]??0)+1;
                            $identified=true;
                        }
                    }
                    if (!$identified)
                        $g['filter_refs'][0]=($g['filter_refs'][0]??0)+1;
                }
                unset($g);
            });
        }
        ksort($groups);
        $result=array();$probeCache=array();
        foreach ($groups as $key=>$group) {
            $candidate=self::qualify($group,$probe,$probeCache);
            $result[]=$candidate;
        }
        $states=array_count_values(array_column($result,'status'));
        ksort($states);
        return array(
            'contract'=>self::CONTRACT, 'site'=>site_url('/'),
            'mode'=>'read_only_discovery', 'seo_rules_write'=>false,
            'indexing_enabled_by_scanner'=>false,
            'templates_scanned'=>$seenTemplates,
            'groups_discovered'=>count($result),'states'=>$states,
            'native_jsf_seo_enabled'=>function_exists('jet_smart_filters')
                ?(bool)jet_smart_filters()->settings->is_seo_enabled:false,
            'native_jsf_rules_count'=>function_exists('jet_smart_filters')
                ?count((array)jet_smart_filters()->seo->sitemap->rules):0,
            'groups'=>$result,
        );
    }

    private static function visit(array $nodes, callable $fn): void {
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $fn($node);
            if (isset($node['elements']) && is_array($node['elements']))
                self::visit($node['elements'],$fn);
        }
    }

    /** Deterministic first phase, testable without database or network. */
    public static function qualifyFilter(array $meta): array {
        $reasons=array();
        $id=(int)($meta['id']??0);
        if ($id<=0) $reasons[]='filter_reference_unresolved';
        if (($meta['post_status']??'')!=='publish') $reasons[]='filter_not_published';
        $type=(string)($meta['filter_type']??'');
        if (!in_array($type,array('checkboxes','select','color-image'),true))
            $reasons[]='non_evergreen_filter_type';
        if (($meta['query_type']??'')!=='tax_query')
            $reasons[]='non_taxonomy_query';
        $taxonomy=(string)($meta['query_var']??'');
        if ($taxonomy==='' || empty($meta['taxonomy_public']))
            $reasons[]='taxonomy_not_public';
        if (empty($meta['options_count'])) $reasons[]='filter_options_missing';
        if (!empty($meta['restricted_pricing_or_availability']))
            $reasons[]='price_or_availability_filter';
        return array(
            'id'=>$id,'title'=>(string)($meta['title']??''),
            'filter_type'=>$type,'query_type'=>(string)($meta['query_type']??''),
            'taxonomy'=>$taxonomy,'options_count'=>(int)($meta['options_count']??0),
            'eligible'=>!$reasons,'reasons'=>array_values(array_unique($reasons)),
        );
    }

    private static function metadata(int $id): array {
        if (!$id) return array('id'=>0,'title'=>'','post_status'=>'',
            'filter_type'=>'','query_type'=>'','query_var'=>'',
            'taxonomy_public'=>false,'options_count'=>0);
        $post=get_post($id);
        $instance=function_exists('jet_smart_filters')
            ?jet_smart_filters()->filter_types->get_filter_instance($id):null;
        $args=is_object($instance)?(array)($instance->args??array()):array();
        $var=(string)($args['query_var']??'');
        $taxonomy=$var!==''?get_taxonomy($var):null;
        $type=(string)get_post_meta($id,'_filter_type',true);
        $risk=preg_match('/(?:price|rate|cost|deposit|inventory|stock|booking|date|arrival|stay|range)/i',
            $type.' '.(string)($post->post_title??'').' '.$var)===1;
        return array(
            'id'=>$id,'title'=>(string)($post->post_title??''),
            'post_status'=>(string)($post->post_status??''),
            'filter_type'=>$type,
            'query_type'=>(string)($args['query_type']??''),
            'query_var'=>$var,'taxonomy_public'=>$taxonomy && !empty($taxonomy->public),
            'options_count'=>is_array($args['options']??null)?count($args['options']):0,
            'restricted_pricing_or_availability'=>$risk,
        );
    }

    /** The route is inferred from actual registered CPT archive slugs, not hardcoded IDs. */
    public static function inferRoute(string $queryId, array $archives): array {
        if (strpos($queryId,'archive')===false) return array('status'=>'not_archive_query');
        $tokens=preg_split('/[^a-z0-9]+/',strtolower($queryId),-1,PREG_SPLIT_NO_EMPTY);
        $matches=array();
        foreach ($archives as $type=>$url) {
            $words=preg_split('/[^a-z0-9]+/',strtolower((string)$type),-1,PREG_SPLIT_NO_EMPTY);
            foreach ($tokens as $t) {
                if (strlen($t)<5) continue;
                foreach ($words as $w) {
                    if (strlen($w)>=5 && substr($t,0,5)===substr($w,0,5)) {
                        $matches[$type]=$url;break 2;
                    }
                }
            }
        }
        if (count($matches)!==1) return array(
            'status'=>count($matches)>1?'ambiguous_archive_route':'archive_route_unmapped',
            'matches'=>array_keys($matches)
        );
        $type=array_key_first($matches);
        return array('status'=>'inferred_archive_route','post_type'=>$type,'url'=>$matches[$type]);
    }

    private static function publicArchives(): array {
        $out=array();
        foreach (get_post_types(array('public'=>true,'has_archive'=>true),'objects') as $type=>$obj) {
            $url=get_post_type_archive_link($type);
            if ($url && parse_url($url,PHP_URL_HOST)===parse_url(home_url('/'),PHP_URL_HOST))
                $out[$type]=$url;
        }
        return $out;
    }

    /** Same-origin only. No arbitrary URL, private host, REST or sitemap mutation. */
    private static function probe(string $url,array &$cache): int {
        if (isset($cache[$url])) return $cache[$url];
        if (parse_url($url,PHP_URL_HOST)!==parse_url(home_url('/'),PHP_URL_HOST))
            return $cache[$url]=0;
        $res=wp_remote_get($url,array('timeout'=>8,'redirection'=>1,'limit_response_size'=>2048,
            'headers'=>array('Accept'=>'text/html'),'sslverify'=>true));
        return $cache[$url]=is_wp_error($res)?0:(int)wp_remote_retrieve_response_code($res);
    }

    private static function qualify(array $group,bool $probe,array &$cache): array {
        $filters=array();$eligible=array();$reasons=array();
        foreach ($group['filter_refs'] as $id=>$uses) {
            $meta=self::metadata((int)$id);
            $item=self::qualifyFilter($meta);
            $item['widget_uses']=$uses;
            $filters[]=$item;
            if ($item['eligible']) $eligible[]=$item;
        }
        usort($eligible,static fn($a,$b)=>$b['widget_uses']<=>$a['widget_uses'] ?: $a['id']<=>$b['id']);
        $route=self::inferRoute($group['query_id'],self::publicArchives());
        $code=null;
        if ($probe && ($route['status']??'')==='inferred_archive_route')
            $code=self::probe((string)$route['url'],$cache);
        if (!$filters) $reasons[]='no_direct_filter_widgets';
        if ($filters && !$eligible) $reasons[]='no_eligible_taxonomy_filters';
        if (($route['status']??'')!=='inferred_archive_route') $reasons[]=$route['status'];
        if ($probe && $code!==null && $code!==200) $reasons[]='archive_http_not_200';
        if (!$probe && ($route['status']??'')==='inferred_archive_route')
            $reasons[]='archive_http_not_probed';
        if (count($eligible)>3) $reasons[]='more_than_three_eligible_filters_select_subset';
        $ready=!$reasons && count($eligible)>0 && $code===200;
        $status=$ready?'candidate_for_manual_rule_review':
            (in_array('archive_http_not_200',$reasons,true)?'route_blocked':
            (in_array('not_archive_query',$reasons,true)?'display_only':
            (in_array('no_direct_filter_widgets',$reasons,true) ||
                in_array('no_eligible_taxonomy_filters',$reasons,true)?'needs_filter_mapping':
            'needs_validation')));
        return array(
            'provider'=>$group['provider'],'query_id'=>$group['query_id'],
            'status'=>$status,'reasons'=>$reasons,
            'indexing_approved'=>false,'rule_created'=>false,
            'template_count'=>count($group['templates']),
            'templates'=>array_values($group['templates']),
            'languages'=>array_keys($group['languages']),
            'widgets'=>$group['widgets'],'apply_types'=>$group['apply_types'],
            'filters'=>$filters,'eligible_filter_ids'=>array_column($eligible,'id'),
            'suggested_max_three_filter_ids'=>array_column(array_slice($eligible,0,3),'id'),
            'route'=>$route,'route_http'=>$code,
        );
    }
}
