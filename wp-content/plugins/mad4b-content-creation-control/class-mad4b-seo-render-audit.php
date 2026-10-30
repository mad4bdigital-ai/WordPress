<?php
defined('ABSPATH') || exit;
/**
 * Read-only Staging HTML audit. Inspects actual image markup from WordPress and
 * Elementor frontend, without inferring visual contents or modifying templates.
 */
final class MAD4B_SEO_Render_Audit {
    const CONTRACT='mad4b.seo-render-audit.v1';
    public static function boot() {
        if(wp_get_environment_type()!=='staging')return;
        add_action('rest_api_init',[__CLASS__,'routes'],95);
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],95);
    }
    public static function allowed(){
        return class_exists('MAD4B_SEO_Content_Lifecycle')
            && MAD4B_SEO_Content_Lifecycle::can_read();
    }
    public static function audit($input=[]) {
        if(!self::allowed())return new WP_Error('mad4b_render_audit_denied','Read access required');
        if(wp_get_environment_type()!=='staging'||home_url('/')!=='https://tours.dev.mad4b.com/')
            return new WP_Error('mad4b_render_audit_scope','Staging site required');
        $id=absint($input['post_id']??0);
        $post=$id?get_post($id):null;
        if(!$post||!current_user_can('edit_post',$id)||in_array($post->post_type,['attachment','revision'],true))
            return new WP_Error('mad4b_render_audit_post','Editable post required');
        $mode=get_post_meta($id,'_elementor_edit_mode',true)==='builder'?'elementor':'wordpress_content';
        $html='';
        try{
            if($mode==='elementor' && class_exists('\\Elementor\\Plugin')){
                $plugin=\Elementor\Plugin::$instance;
                if($plugin && isset($plugin->frontend)
                    && method_exists($plugin->frontend,'get_builder_content_for_display'))
                    $html=(string)$plugin->frontend->get_builder_content_for_display($id,true);
            }else{
                $html=apply_filters('the_content',$post->post_content);
            }
        }catch(Throwable $e){
            return new WP_Error('mad4b_render_audit_renderer_failure','Content renderer could not be inspected safely');
        }
        if(!is_string($html)||$html==='')return [
            'contract'=>self::CONTRACT,'post_id'=>$id,'renderer'=>$mode,
            'render_status'=>'empty_or_unsupported','images'=>[],
            'rendered_html_checked'=>false,'template_level_coverage'=>false,
            'mutation_performed'=>false];
        if(strlen($html)>750000)return new WP_Error('mad4b_render_audit_oversize','Rendered HTML exceeds safe audit limit');
        if(!class_exists('DOMDocument'))return new WP_Error('mad4b_render_audit_dom_unavailable','DOM parser unavailable');
        $previous=libxml_use_internal_errors(true);
        $doc=new DOMDocument();
        $parsed=$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOWARNING|LIBXML_NOERROR);
        libxml_clear_errors();libxml_use_internal_errors($previous);
        if(!$parsed)return new WP_Error('mad4b_render_audit_dom_invalid','Rendered markup could not be parsed');
        $nodes=$doc->getElementsByTagName('img');$rows=[];$no_alt=0;$empty_alt=0;$valid=0;$limit=100;
        foreach($nodes as $img) {
            if(count($rows)>=$limit)break;
            $src=trim((string)$img->getAttribute('src'));
            $has=$img->hasAttribute('alt');
            $alt=$has?trim((string)$img->getAttribute('alt')):'';
            if(!$has)$no_alt++;elseif($alt==='')$empty_alt++;else$valid++;
            $rows[]=['index'=>count($rows)+1,'has_alt_attribute'=>$has,
                'alt_text'=>$alt,'alt_empty'=>$has&&$alt==='',
                'src_host'=>sanitize_text_field((string)wp_parse_url($src,PHP_URL_HOST)),
                'class'=>sanitize_text_field(substr((string)$img->getAttribute('class'),0,130)),
                'loading'=>(string)$img->getAttribute('loading')];
        }
        return ['contract'=>self::CONTRACT,'post_id'=>$id,'renderer'=>$mode,
            'render_status'=>'parsed','rendered_html_checked'=>true,
            'image_count_observed'=>count($rows),'image_count_capped'=>count($nodes)>$limit,
            'without_alt_attribute'=>$no_alt,'empty_alt_including_decorative'=>$empty_alt,
            'nonempty_alt'=>$valid,'images'=>$rows,
            'visual_accuracy_checked'=>false,'empty_alt_may_be_decorative'=>true,
            'template_level_coverage'=>false,
            'custom_galleries_require_explicit_audit'=>true,
            'rank_math_score_recomputed'=>false,'mutation_performed'=>false];
    }
    public static function routes() {
        register_rest_route('mad4b/content-creation/v1','/seo-render-audit',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'allowed'],
            'callback'=>static function($request){return rest_ensure_response(self::audit($request->get_params()));}]);
    }
    public static function abilities() {
        if(!function_exists('wp_register_ability')||
            (function_exists('wp_has_ability')&&wp_has_ability('mad4b/seo-render-audit')))return;
        wp_register_ability('mad4b/seo-render-audit',[
            'label'=>'SEO rendered image ALT audit',
            'description'=>'Read-only Staging image markup audit for WordPress and Elementor.',
            'category'=>'mad4b-read','permission_callback'=>[__CLASS__,'allowed'],
            'execute_callback'=>[__CLASS__,'audit'],
            'input_schema'=>['type'=>'object','properties'=>['post_id'=>['type'=>'integer','minimum'=>1]],
                'required'=>['post_id'],'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>[
                'public'=>false,'type'=>'tool','surface'=>'read'],
                'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]]);
    }
}
