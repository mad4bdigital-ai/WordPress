<?php
namespace DynamicFilterSEOBridge\Context;

use DynamicFilterSEOBridge\SEO\SiteSeoSources;

/** WP-query-first, tenant-neutral, read-only context authority. */
final class PageContextResolver {
    public const CONTRACT='dfsb.page-context.v1';
    private const RESTRICTED_TYPES=array(
        'attachment','elementor_library','elementor-hf','jet-woo-builder',
        'jet-form-builder','jet-engine-booking','tour-rates','tour_orders',
        'tour_bookings','e-floating-buttons','shop_order','shop_order_refund'
    );

    public static function publicType(string $type): bool {
        if($type==='' || in_array($type,self::RESTRICTED_TYPES,true))return false;
        $obj=get_post_type_object($type);
        return $obj && !empty($obj->public) && !empty($obj->publicly_queryable)
            && !empty($obj->show_ui);
    }
    public static function catalog(int $limit=100): array {
        $out=array('post_types'=>array(),'taxonomies'=>array(),'truncated'=>false);
        $limit=max(1,min(150,$limit));
        $types=get_post_types(array('public'=>true),'objects');
        foreach($types as $type=>$obj){
            if(!self::publicType((string)$type))continue;
            if(count($out['post_types'])>=$limit){$out['truncated']=true;break;}
            $out['post_types'][$type]=array('label'=>SiteSeoSources::plain((string)$obj->labels->name,95),
                'has_archive'=>(bool)$obj->has_archive,'taxonomies'=>array());
        }
        $taxonomies=get_taxonomies(array('public'=>true),'objects');
        foreach($taxonomies as $tax=>$obj){
            $owners=array_values(array_filter((array)$obj->object_type,
                static fn($type): bool=>self::publicType((string)$type)));
            if(!$owners)continue;
            if(count($out['taxonomies'])>=$limit){$out['truncated']=true;break;}
            $out['taxonomies'][$tax]=array('label'=>SiteSeoSources::plain((string)$obj->labels->name,95),
                'hierarchical'=>(bool)$obj->hierarchical,'post_types'=>$owners);
            foreach($owners as $type){
                if(isset($out['post_types'][$type]))$out['post_types'][$type]['taxonomies'][]=$tax;
            }
        }
        return $out;
    }
    private static function base(string $kind,string $type='',int $id=0): array {
        $language=function_exists('determine_locale')?determine_locale():get_locale();
        // Use the current translated page language, not the EN source locale.
        $wpml=has_filter('wpml_current_language')?apply_filters('wpml_current_language',null):null;
        if(is_string($wpml)&&preg_match('/^[a-z]{2,3}(?:-[a-zA-Z]{2,4})?$/',$wpml))$language=$wpml;
        return array('contract'=>self::CONTRACT,'read_only'=>true,'authorizing'=>false,
            'page_kind'=>$kind,'post_type'=>$type,'post_id'=>0,'taxonomy'=>'','term_id'=>0,
            'language'=>(string)$language,'title'=>'','description'=>'','image_attachment_id'=>0,
            'object_id'=>$id,'provider_scope'=>'source_only',
            'seo_publishable'=>false,'indexing_authorized'=>false,
            'pricing_authorized'=>false,'booking_authorized'=>false);
    }
    public static function forPost(int $id): array {
        if(WooCommerceContextResolver::restrictedPage($id)){
            $role=WooCommerceContextResolver::transactionRole($id);
            $out=self::base('commerce_transaction','',$id);
            $out['commerce_surface']=$role;
            return $out;
        }
        if($id>0 && WooCommerceContextResolver::pageId('shop')===$id)
            return WooCommerceContextResolver::shop();
        $post=get_post($id);
        if(!$post || !self::publicType((string)$post->post_type)
            || $post->post_status!=='publish')return self::base('unresolved');
        $out=self::base('singular',(string)$post->post_type,$id);
        $out['post_id']=$id;
        $translated=has_filter('wpml_element_language_code')?
            apply_filters('wpml_element_language_code',null,array(
                'element_id'=>$id,'element_type'=>'post_'.$post->post_type)):null;
        if(is_string($translated)&&preg_match('/^[a-z]{2,3}$/',$translated))
            $out['language']=$translated;
        if($post->post_type==='product' && WooCommerceContextResolver::available()){
            $out['commerce_surface']='product';
            $out['commerce_type']=WooCommerceContextResolver::forProduct($id)['product_type'];
        }
        $out['title']=SiteSeoSources::plain($post->post_title,150);
        $excerpt=has_excerpt($id)?get_the_excerpt($id):'';
        // Do not treat a shortcode-heavy itinerary as trustworthy SEO copy.
        $out['description']=SiteSeoSources::plain((string)$excerpt,260);
        $out['image_attachment_id']=(int)get_post_thumbnail_id($id);
        $imageRef=get_post_meta($id,'dfsb_seo_image_ref',true);
        if(is_string($imageRef) && str_starts_with($imageRef,'shared:'))
            $out['image_shared_reference']=$imageRef;
        return $out;
    }
    public static function forTaxonomy(string $taxonomy,int $termId): array {
        $tax=get_taxonomy($taxonomy);
        $owners=$tax?array_values(array_filter((array)$tax->object_type,
            static fn($type): bool=>self::publicType((string)$type))):array();
        $term=($tax && $owners)?get_term($termId,$taxonomy):null;
        if(!$term || is_wp_error($term))return self::base('unresolved');
        $out=self::base('taxonomy','',$termId);
        $out['taxonomy']=$taxonomy;$out['term_id']=$termId;
        $translated=has_filter('wpml_element_language_code')?
            apply_filters('wpml_element_language_code',null,array(
                'element_id'=>$termId,'element_type'=>'tax_'.$taxonomy)):null;
        if(is_string($translated)&&preg_match('/^[a-z]{2,3}$/',$translated))
            $out['language']=$translated;
        $out['post_types']=$owners;
        // Shared taxonomies cannot be assigned arbitrarily to the first CPT.
        if(count($owners)===1)$out['post_type']=$owners[0];
        $out['title']=SiteSeoSources::plain($term->name,150);
        $out['description']=SiteSeoSources::plain((string)$term->description,260);
        // Woo product taxonomies conventionally use thumbnail_id; JetEngine
        // tenant terms may use featured_image. Both must resolve to an actual
        // company-local attachment, never a guessed shared path.
        foreach(array('thumbnail_id','featured_image') as $key){
            $meta=get_term_meta($termId,$key,true);
            if(is_array($meta))$meta=$meta['id']??$meta['attachment_id']??0;
            if(is_scalar($meta) && ctype_digit((string)$meta) && (int)$meta>0){
                $out['image_attachment_id']=absint($meta);
                break;
            }
        }
        if(empty($out['image_attachment_id'])){
            $imageRef=get_term_meta($termId,'dfsb_seo_image_ref',true);
            if(is_string($imageRef) && str_starts_with($imageRef,'shared:'))
                $out['image_shared_reference']=$imageRef;
        }
        return $out;
    }
    public static function forArchive(string $type): array {
        if(!self::publicType($type))return self::base('unresolved');
        $obj=get_post_type_object($type);
        if(!$obj->has_archive)return self::base('unresolved');
        $out=self::base('post_type_archive',$type);
        $out['title']=SiteSeoSources::plain((string)$obj->labels->name,150);
        $out['description']=SiteSeoSources::plain((string)$obj->description,260);
        $out['taxonomies']=array_values(array_filter(get_object_taxonomies($type),
            static function($tax): bool { $obj=get_taxonomy($tax);return $obj && !empty($obj->public); }));
        return $out;
    }
    public static function forPostsHome(): array {
        $out=self::base('posts_home','post');
        if(!self::publicType('post'))return self::base('unresolved');
        $id=(int)get_option('page_for_posts',0);
        $post=$id?get_post($id):null;
        $out['object_id']=$id;
        $out['title']=SiteSeoSources::plain((string)($post->post_title??__('Blog')),150);
        $out['description']=SiteSeoSources::plain((string)($post->post_excerpt??''),260);
        $out['taxonomies']=array_values(array_filter(get_object_taxonomies('post'),
            static function($tax): bool { $obj=get_taxonomy($tax);return $obj && !empty($obj->public); }));
        return $out;
    }
    /** Distinguishes subsequent archive pages without rewriting canonicals. */
    private static function withPagination(array $ctx): array {
        $page=max(1,(int)get_query_var('paged',1));
        $ctx['pagination_page']=$page;
        $ctx['first_page']=$page===1;
        if($page>1)$ctx['seo_publishable']=false;
        return $ctx;
    }
    /** Resolves the real WordPress main query; no arbitrary URL guessing. */
    public static function fromQuery(): array {
        if(is_admin() || wp_doing_ajax() || !did_action('wp'))return self::base('unresolved');
        if(WooCommerceContextResolver::available()){
            if((function_exists('is_cart') && is_cart()) ||
                (function_exists('is_checkout') && is_checkout()) ||
                (function_exists('is_account_page') && is_account_page())){
                $transactionId=(int)get_queried_object_id();
                if($transactionId>0 && WooCommerceContextResolver::restrictedPage($transactionId))
                    return self::forPost($transactionId);
                return self::base('commerce_transaction');
            }
            if(function_exists('is_shop') && is_shop())return self::withPagination(WooCommerceContextResolver::shop());
        }
        if(is_404())return self::base('not_found');
        if(is_search())return self::base('search_results');
        if(is_author())return self::base('author_archive');
        if(is_date())return self::base('date_archive');
        if(is_home())return self::withPagination(self::forPostsHome());
        if(is_category() || is_tag() || is_tax()){
            $obj=get_queried_object();
            return $obj instanceof \WP_Term?self::withPagination(self::forTaxonomy($obj->taxonomy,(int)$obj->term_id)):self::base('unresolved');
        }
        if(is_post_type_archive()){
            $object=get_queried_object();
            $type=$object instanceof \WP_Post_Type?$object->name:get_query_var('post_type');
            if(is_array($type))return self::base('unresolved');
            return self::withPagination(self::forArchive((string)$type));
        }
        if(is_singular())return self::forPost((int)get_queried_object_id());
        return self::base('unresolved');
    }
}
