<?php
/**
 * Plugin Name: MAD4B Listing Content Context Provider
 * Description: Bounded, read-only JetEngine card-family and parent-data-dependency discovery for local company policies.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Listing_Content_Context {
    public const VERSION = '1.0.0';
    public const FAMILIES = ['destination','transportation','unit','property','blog','tour','other','unknown'];
    public const DEPENDENCIES = ['independent','parent_object','rate_sensitive','unknown'];

    /** Resolve the local JetEngine listing document once per PHP request. */
    public static function describe(int $id): array {
        static $memo = [];
        $id=absint($id);
        if (isset($memo[$id])) return $memo[$id];
        $unknown=['contract'=>'mad4b.listing.content-context.v1','listing_id'=>$id,
            'content_family'=>'unknown','data_dependency'=>'unknown',
            'listing_source'=>'unknown','source_post_type'=>'','source_taxonomy'=>'',
            'nested_listings'=>0,'classification'=>'incomplete'];
        if (!$id) return $unknown;
        $p=get_post($id);
        if (!$p || $p->post_type!=='jet-engine') return $memo[$id]=$unknown;
        $settings=get_post_meta($id,'_elementor_page_settings',true);
        if (!is_array($settings)) $settings=[];
        $legacy=get_post_meta($id,'_listing_data',true);
        if (is_array($legacy)) $settings=array_replace($legacy,$settings);
        $source=strtolower((string)($settings['listing_source']??$settings['source']??''));
        $post_type=strtolower((string)($settings['listing_post_type']??$settings['post_type']??''));
        $taxonomy=strtolower((string)($settings['listing_tax']??''));
        $name=strtolower((string)$p->post_title);
        $out=$unknown;
        $out['listing_source']=$source?:'unknown';
        $out['source_post_type']=$post_type;
        $out['source_taxonomy']=$taxonomy;
        // Semantic title fallback is used only for distinguishing components
        // that share a generic JetEngine source (e.g. transport vs room units).
        if ($source==='terms' && (preg_match('/destinat|location|attract|cit(?:y|ies)|govern|region|countr/i',$taxonomy.' '.$name)))
            $out['content_family']='destination';
        elseif ($post_type==='units' && preg_match('/transport|transfer|train|vehicle|fleet/i',$name))
            $out['content_family']='transportation';
        elseif ($post_type==='units') $out['content_family']='unit';
        elseif ($post_type==='transportations' || preg_match('/transport|transfer|train\s+stop/i',$name))
            $out['content_family']='transportation';
        elseif ($post_type==='properties' || preg_match('/propert|hotel|accommod/i',$name))
            $out['content_family']='property';
        elseif ($post_type==='post' && $source==='posts' || preg_match('/blog|post\s+(?:grid|item|list|archive)|featured\s+posts|help.center/i',$name))
            $out['content_family']='blog';
        elseif ($post_type==='tours-and-activities') $out['content_family']='tour';
        elseif ($source==='terms' && preg_match('/destinat|location|attract|cit(?:y|ies)|govern/i',$name))
            $out['content_family']='destination';
        elseif (in_array($source,['posts','terms','repeater','query'],true)) $out['content_family']='other';
        // Don't equate an empty Elementor tree with a verified leaf. Some
        // block-based listings have empty Elementor data but real PHP output.
        $raw=get_post_meta($id,'_elementor_data',true);
        if (!is_string($raw) || strlen($raw)<3 || strlen($raw)>650000) return $memo[$id]=$out;
        $nodes=json_decode($raw,true);
        if (!is_array($nodes) || json_last_error()!==JSON_ERROR_NONE) return $memo[$id]=$out;
        $nested=0;$price=false;$unresolved=false;$count=0;
        $walk=function(array $elements,int $depth) use (&$walk,&$nested,&$price,&$unresolved,&$count): void {
            if ($depth>12) { $unresolved=true;return; }
            foreach($elements as $node) {
                if (!is_array($node) || ++$count>650) { $unresolved=true;return; }
                $type=(string)($node['widgetType']??'');
                $settings=(array)($node['settings']??[]);
                if ($type==='jet-listing-grid') {
                    $nested++;
                    $child=absint($settings['lisitng_id']??0);
                    if (!$child || !get_post($child)) { $unresolved=true; }
                    else {
                        $child_title=strtolower((string)get_the_title($child));
                        if (preg_match('/price|rate|cost|availability|book/i',$child_title)) $price=true;
                    }
                    if (!empty($settings['__dynamic__']['lisitng_id'])) $unresolved=true;
                }
                if (in_array($type,['jet-listing-dynamic-repeater','jet-listing-dynamic-meta'],true)) $nested++;
                if ($type==='shortcode' || $type==='wp-widget-shortcode') $unresolved=true;
                if ($type==='jet-listing-dynamic-field' && preg_match('/price|rate|cost|booking|availability/i',
                    (string)($settings['dynamic_field_post_meta']??$settings['field']??''))) $price=true;
                $children=$node['elements']??[];
                if (!is_array($children)) $unresolved=true;
                else if ($children) $walk($children,$depth+1);
            }
        };
        $walk($nodes,0);
        $out['nested_listings']=$nested;
        $out['classification']=$unresolved?'incomplete':'document_scanned';
        $out['data_dependency']=$unresolved?'unknown':($price?'rate_sensitive':($nested>0?'parent_object':'independent'));
        return $memo[$id]=$out;
    }

    /** Context changes with the parent object even for the same template. */
    public static function runtime_dependency(): string {
        if (!function_exists('jet_engine')) return '';
        $jet=jet_engine();
        $data=is_object($jet)&&isset($jet->listings->data)?$jet->listings->data:null;
        if(!is_object($data)||!method_exists($data,'get_current_object'))return '';
        $current=$data->get_current_object();
        if(!$current)return '';
        $main=(int)get_queried_object_id();
        if($current instanceof \WP_Post) return (int)$current->ID!==$main?'parent_object':'';
        if($current instanceof \WP_Term) return (int)$current->term_id!==$main?'parent_object':'';
        return 'parent_object'; // Repeater rows and other non-post objects.
    }
    public static function add_context($context,$settings): array {
        if (!is_array($context)) $context=[];
        if (!is_array($settings)) return $context;
        $id=absint($context['listing_id']??$settings['lisitng_id']??0);
        $d=self::describe($id);
        $dependency=self::runtime_dependency();
        $context['content_family']=$d['content_family'];
        $context['data_dependency']=$dependency?:$d['data_dependency'];
        return $context;
    }
    public static function boot(): void {
        add_filter('mad4b/policy_registry/render_context',[self::class,'add_context'],10,2);
    }
}
MAD4B_Listing_Content_Context::boot();
