<?php
/**
 * Plugin Name: MAD4B Simple Published Content Feed
 * Description: Read-only, opt-in source feed for central Content Hub imports.
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) { exit; }
final class MAD4B_Company_Simple_Content_Feed_V1 {
    const ROUTE = '/mad4b-company/v1/simple-content-feed';
    const TYPES = [
        'tours-and-activities' => 'tour',
        'properties' => 'property',
        'transportations' => 'transportation',
    ];
    public static function permission($request) {
        if (!class_exists('MAD4B_Company_Simple_Identity_V1')) {
            return new WP_Error('mad4b_feed_unavailable','Source feed unavailable',['status'=>403]);
        }
        return MAD4B_Company_Simple_Identity_V1::authorized_for($request,'GET',self::ROUTE);
    }
    public static function page(WP_REST_Request $request) {
        $allowed=self::permission($request);
        if ($allowed!==true)return $allowed;
        $type=(string)$request->get_param('post_type');
        $page=filter_var($request->get_param('page'),FILTER_VALIDATE_INT);
        if (!isset(self::TYPES[$type]) || !is_int($page) || $page<1 || $page>100 ||
            !post_type_exists($type)) {
            return new WP_Error('mad4b_feed_invalid_selection','Invalid post type or page',['status'=>400]);
        }
        $query=new WP_Query([
            'post_type'=>$type, 'post_status'=>'publish', 'posts_per_page'=>20, 'paged'=>$page,
            'orderby'=>'ID','order'=>'ASC', 'ignore_sticky_posts'=>true,
            'no_found_rows'=>false,'suppress_filters'=>true,'fields'=>'ids',
        ]);
        $total=(int)$query->found_posts;
        if ($total<0 || $total>2000 || $page>max(1,(int)ceil($total/20))) {
            return new WP_Error('mad4b_feed_bounds','Source collection outside pilot limits',['status'=>409]);
        }
        $items=[];
        foreach ((array)$query->posts as $id) {
            $id=(int)$id;
            $p=get_post($id);
            if (!$p || $p->post_type!==$type || $p->post_status!=='publish') {
                return new WP_Error('mad4b_feed_changed','Source post changed during read',['status'=>409]);
            }
            $lang=apply_filters('wpml_post_language_details',null,$id);
            $lang=is_array($lang)?($lang['language_code']??''): '';
            if (!in_array($lang,['en','ar'],true)) {
                return new WP_Error('mad4b_feed_language','Unknown source language',['status'=>409]);
            }
            $items[]=[
                'source_company'=>MAD4B_Company_Simple_Identity_V1::identity_for_host()['company_id'],
                'source_post_type'=>$type,
                'kind'=>self::TYPES[$type],
                'source_id'=>$id,
                'language'=>$lang,
                'title'=>sanitize_text_field((string)$p->post_title),
                'summary'=>sanitize_textarea_field(wp_strip_all_tags((string)$p->post_excerpt)),
                'body'=>sanitize_textarea_field(mb_substr(wp_strip_all_tags((string)$p->post_content),0,12000)),
                'modified_gmt'=>preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',(string)$p->post_modified_gmt)
                    ? $p->post_modified_gmt : '0000-00-00 00:00:00',
                'reference_only'=>true, 'may_publish'=>false,'may_checkout'=>false,
            ];
        }
        $site=MAD4B_Company_Simple_Identity_V1::identity_for_host();
        $response=new WP_REST_Response([
            'contract'=>'mad4b.company.published-content-page.v1',
            'company_id'=>$site['company_id'],'canonical_host'=>$site['canonical_host'],
            'source_post_type'=>$type,'page'=>$page,'per_page'=>20,
            'total'=>$total,'items'=>$items,
            'reference_only'=>true,'may_publish'=>false,'may_checkout'=>false,
        ],200);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
    public static function boot(): void {
        if (!class_exists('MAD4B_Company_Simple_Identity_V1') ||
            !MAD4B_Company_Simple_Identity_V1::identity_for_host())return;
        add_action('rest_api_init',static function() {
            register_rest_route('mad4b-company/v1','/simple-content-feed',[
                'methods'=>WP_REST_Server::READABLE,
                'permission_callback'=>[__CLASS__,'permission'],
                'callback'=>[__CLASS__,'page'],
            ]);
        });
    }
}
MAD4B_Company_Simple_Content_Feed_V1::boot();
