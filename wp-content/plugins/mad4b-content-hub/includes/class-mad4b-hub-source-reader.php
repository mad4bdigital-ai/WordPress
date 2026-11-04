<?php
/** Native WordPress source discovery. Read-only. */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Source_Reader {
    private static function is_test_only($post_id) {
        $id = absint($post_id);
        if (!$id) return false;
        if (class_exists('MAD4B_Data_Classification_Isolation'))
            return MAD4B_Data_Classification_Isolation::is_test_only($id);
        return in_array(strtolower((string)get_post_meta($id, '_mad4b_is_test_data', true)), ['1','yes','true','on'], true)
            || in_array(strtolower((string)get_post_meta($id, '_mad4b_data_classification', true)), ['test_only','sandbox_only'], true);
    }
    const CONTRACT='mad4b.shared-source-candidate.v1';
    const FIELD_MAP=['single'=>'single_price','double'=>'double_price','triple'=>'triple_price'];
    const CONTENT_TYPES=['tours-and-activities','properties','transportations'];
    public static function content_candidate($post_id) {
        $id=absint($post_id);
        if (self::is_test_only($id)) return new WP_Error('mad4b_hub_test_data_denied');
        $post=get_post($id);
        if(!$post||!in_array($post->post_type,self::CONTENT_TYPES,true)
            ||$post->post_status!=='publish')
            return new WP_Error('mad4b_hub_unpublished_or_unsupported_content');
        $p=class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::status():[];
        if(empty($p['authority_ready'])||empty($p['same_origin_clone_protection']))
            return new WP_Error('mad4b_hub_source_identity_unverified');
        $site=(string)($p['site_uuid']??'');
        $language='';
        $translation_group=0;
        if(has_filter('wpml_post_language_details')){
            $details=apply_filters('wpml_post_language_details',null,$id);
            if(is_array($details)){
                $language=(string)($details['language_code']??'');
                $translation_group=absint($details['trid']??0);
            }
        }
        if($language==='' && defined('ICL_SITEPRESS_VERSION'))
            return new WP_Error('mad4b_hub_source_translation_language_unverified');
        $family=$translation_group>0?'wpml:'.$translation_group:'post:'.$id;
        $global='content-'.substr(hash('sha256',$site.'|'.$post->post_type.'|'.$family),0,32);
        $terms=[];
        foreach(get_object_taxonomies($post->post_type,'names') as $tax){
            if(!in_array($tax,['destinations','tour-types','property-types',
                'transportation-types','amenities','tour-styles'],true))continue;
            $values=get_the_terms($id,$tax);
            if(!is_array($values))continue;
            foreach($values as $term){
                $terms[]=['taxonomy'=>$tax,'term_slug'=>$term->slug,
                    'global_term_key'=>substr(hash('sha256',$site.'|'.$tax.'|'.$term->term_id),0,32)];
            }
        }
        usort($terms,static fn($a,$b)=>strcmp($a['taxonomy'].'|'.$a['term_slug'],
            $b['taxonomy'].'|'.$b['term_slug']));
        $data=[
            'site_uuid'=>$site,'post_type'=>$post->post_type,
            'source_id'=>$id,'source_modified_gmt'=>$post->post_modified_gmt,
            'title'=>(string)$post->post_title,'excerpt'=>(string)$post->post_excerpt,
            'body_sha256'=>hash('sha256',(string)$post->post_content),
            'language'=>$language,'wpml_translation_group'=>$translation_group,
            'media_featured_source_id'=>get_post_thumbnail_id($id)?:0,
            'taxonomies'=>$terms,
        ];
        // Candidate-only projection. A media reference is not a remotely
        // usable attachment ID until the signed Shared Media catalog resolves it.
        $source_media_id=absint(get_post_thumbnail_id($id));
        $asset_ref=$source_media_id && str_starts_with((string)get_post_mime_type($source_media_id),'image/')
            ? 'media-'.substr(hash('sha256',$site.'|attachment|'.$source_media_id),0,32)
            : null;
        $seo=[];
        foreach(['seo_title'=>'rank_math_title','seo_description'=>'rank_math_description',
                 'seo_focus_keyword'=>'rank_math_focus_keyword'] as $field=>$meta) {
            $v=get_post_meta($id,$meta,true);
            if(is_string($v) && strlen($v)<=20000)$seo[$field]=$v;
        }
        $gallery=get_post_meta($id,'gallery',true);
        $warnings=[];
        if(is_string($gallery) && $gallery!=='') {
            // Existing legacy gallery is a comma-separated collection of old
            // domain URLs, not portable media IDs. Never syndicate these links.
            $warnings[]='gallery_requires_media_catalog_resolution';
        }
        if(!$asset_ref)$warnings[]='featured_image_source_unresolved';
        $content_fields=array_merge([
            'title'=>$data['title'],
            'excerpt'=>$data['excerpt'],
            'body_sha256'=>$data['body_sha256'],
        ],$seo);
        if($asset_ref)$content_fields['featured_media_global_id']=$asset_ref;
        $data['content_fields']=$content_fields;
        $data['field_projection_sha256']=hash('sha256',wp_json_encode($content_fields,JSON_UNESCAPED_SLASHES));
        return [
            'contract'=>'mad4b.shared-content-candidate.v1',
            'global_content_id'=>$global,
            'global_translation_key'=>$global.':'.$language,
            'source_version'=>hash('sha256',wp_json_encode($data,JSON_UNESCAPED_SLASHES)),
            'candidate'=>$data,'source_projection'=>$content_fields,
            'source_warnings'=>$warnings,
            'per_language_override_supported'=>true,
            'featured_media_resolution_required'=>(bool)$asset_ref,
            'candidate_field_inheritance_only'=>true,
            'share_state'=>'candidate_only',
            'requires_editor_review'=>true,'tenant_mappings_created'=>false,
            'shared_media_copied'=>false,'publish_authorized'=>false,
            'mutation_performed'=>false,
        ];
    }
    public static function minor($input) {
        $s=is_scalar($input)?trim((string)$input):'';
        if(!preg_match('/^(0|[1-9]\d{0,8})(?:\.(\d{1,2}))?$/D',$s))return null;
        $p=explode('.',$s,2);
        return (int)$p[0]*100+(int)str_pad($p[1]??'',2,'0');
    }
    public static function candidate($post_id,$occupancy='double') {
        $id=absint($post_id);
        if (self::is_test_only($id)) return new WP_Error('mad4b_hub_test_data_denied');
        if(!isset(self::FIELD_MAP[$occupancy]))return new WP_Error('mad4b_hub_unknown_occupancy');
        $post=get_post($id);
        if(!$post||$post->post_type!=='tour-rates'||$post->post_status!=='publish')
            return new WP_Error('mad4b_hub_unpublished_rate');
        $related=absint(get_post_meta($id, 'related_tour_id', true));
        if ($related && self::is_test_only($related)) return new WP_Error('mad4b_hub_test_data_denied');
        $p=class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::status():[];
        if(empty($p['authority_ready'])||empty($p['same_origin_clone_protection']))
            return new WP_Error('mad4b_hub_source_identity_unverified');
        $currency=strtoupper((string)get_post_meta($id,'base_currency',true));
        if(!preg_match('/^[A-Z]{3}$/D',$currency))return new WP_Error('mad4b_hub_rate_currency_missing');
        $price=self::minor(get_post_meta($id,self::FIELD_MAP[$occupancy],true));
        if($price===null||$price<=0)return new WP_Error('mad4b_hub_rate_price_missing');
        $flag=get_post_meta($id,'stop_sale',true);
        $stopped=in_array(strtolower((string)$flag),['yes','true','on','1'],true);
        $site_uuid=(string)($p['site_uuid']??'');
        $global_id='price-'.substr(hash('sha256',$site_uuid.'|'.$id.'|'.$occupancy),0,32);
        $evidence=[
            'source_site_uuid'=>$site_uuid,'source_post_id'=>$id,'source_post_type'=>$post->post_type,
            'source_status'=>$post->post_status,
            'source_modified_gmt'=>$post->post_modified_gmt,
            'occupancy'=>$occupancy,'price_basis'=>'reference_retail',
            'amount_minor'=>$price,'currency'=>$currency,
            'source_parent_id'=>absint(get_post_meta($id,'related_tour_id',true)),
            'stop_sale'=>$stopped,
        ];
        return [
            'contract'=>self::CONTRACT,'global_offer_id'=>$global_id,
            'source_version'=>hash('sha256',wp_json_encode($evidence,JSON_UNESCAPED_SLASHES)),
            'source'=>$evidence,
            'share_state'=>'candidate_only',
            'allowed_tenants'=>[],
            'price_basis_verified_as_cost'=>false,
            'publish_authorized'=>false,
            'checkout_authorized'=>false,
            'mutation_performed'=>false,
        ];
    }
}
