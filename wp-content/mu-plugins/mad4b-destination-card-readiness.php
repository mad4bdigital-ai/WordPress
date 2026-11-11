<?php
/**
 * Plugin Name: MAD4B Destination Card Readiness
 * Description: Read-only marketing destination media and eligible-offer checks for Configuration Jobs.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Destination_Card_Readiness_V1',false)) {
final class MAD4B_Destination_Card_Readiness_V1 {
    private const TYPES=['state_governorate'];
    public static function local_image_status(WP_Term $term):string {
        $value=get_term_meta($term->term_id,'featured_image',true);
        if(is_array($value))$value=$value['url']??$value['id']??null;
        if(is_numeric($value) && (int)$value>0) {
            $file=get_attached_file((int)$value);
            return $file && is_file($file) && is_readable($file) ? 'ready' : 'missing';
        }
        if(!is_string($value) || trim($value)==='')return 'missing';
        $url=trim($value);
        $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
        $scheme=strtolower((string)wp_parse_url($url,PHP_URL_SCHEME));
        if(!in_array($scheme,['http','https'],true)||$host==='')return 'invalid';
        $base=wp_get_upload_dir();
        $site_host=strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST));
        if($host!==$site_host)return 'external_unverified';
        $path=(string)wp_parse_url($url,PHP_URL_PATH);
        $base_path=rtrim((string)wp_parse_url($base['baseurl'],PHP_URL_PATH),'/');
        if($base_path==='' || !str_starts_with($path,$base_path.'/'))return 'local_unverified';
        $relative=rawurldecode(substr($path,strlen($base_path)+1));
        if($relative==='' || preg_match('~(^|/)(\.{1,2})(/|$)~',$relative) || str_contains($relative,"\\"))return 'invalid';
        $file=rtrim($base['basedir'],'/').'/'.$relative;
        return is_file($file) && is_readable($file) ? 'ready' : 'missing';
    }
    public static function inspect():array {
        if(!class_exists('Shared_Product_Destination_Term_Presentation_v1') ||
            !function_exists('native_destinations_sale_term_index'))return [];
        $term_ids=get_terms(['taxonomy'=>'destinations','hide_empty'=>false,'fields'=>'ids',
            'meta_query'=>[['key'=>'destination_type','value'=>'state_governorate']]]);
        if(is_wp_error($term_ids))return [];
        $index=native_destinations_sale_term_index('en');
        $result=[];
        foreach($term_ids as $id) {
            $id=(int)$id;
            // Inspect canonical EN terms once; each locale inherits the
            // same commercial parent policy, but may have its own image.
            $canonical=(int)apply_filters('wpml_object_id',$id,'destinations',false,'en');
            if($canonical && $canonical!==$id)continue;
            $term=get_term($id,'destinations');
            if(!$term instanceof WP_Term)continue;
            $class='Shared_Product_Destination_Term_Presentation_v1';
            $eligible=method_exists($class,'eligible_for_hierarchy')
                ? $class::eligible_for_hierarchy($term,'en')
                : $class::activation($term);
            if(!$eligible)continue;
            $result[]=['term_id'=>$id,'name'=>$term->name,
                'offer_ready'=>!empty($index[$id]),
                'featured'=>in_array((string)get_term_meta($id,'is_featured',true),['1','true'],true),
                'image_state'=>self::local_image_status($term)];
        }
        return $result;
    }
    public static function signals(array $rows):array {
        foreach(self::inspect() as $term) {
            $scope='destinations:'.(int)$term['term_id'];
            if(!in_array($term['image_state'],['ready'],true)) {
                $rows[]=['provider'=>'destination-card','code'=>'destination_featured_image_missing',
                    'scope'=>$scope,'source'=>'probe','severity'=>'medium',
                    'label'=>'Public destination requires image review: '.$term['name'],
                    'guidance'=>'Review the original JetEngine featured_image for taxonomy term '.$term['term_id'].'. State: '.$term['image_state'].'. Verify exact photograph, permissions and physical file. Never borrow a photograph from another destination automatically.'];
            }
            if(!$term['offer_ready']) {
                $rows[]=['provider'=>'destination-card','code'=>'destination_without_eligible_tours',
                    'scope'=>$scope,'source'=>'probe','severity'=>'medium',
                    'label'=>'Public destination has no qualified published offer: '.$term['name'],
                    'guidance'=>'Review published eligible tour or standalone service associations to destination '.$term['term_id'].'. Curated commercial cards stay hidden; public taxonomy hierarchy remains available for editorial work.'];
            }
        }
        return $rows;
    }
}
add_filter('mad4b/configuration_jobs/discover',[MAD4B_Destination_Card_Readiness_V1::class,'signals'],30,1);
}
