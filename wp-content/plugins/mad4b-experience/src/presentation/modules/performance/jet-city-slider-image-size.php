<?php
/**
 * MAD4B ETG staging: use registered intermediate images in City Listing carousel.
 * Explicit Elementor container allowlist; never changes canonical term media.
 * Deferred deploy: staging only and editable-preview/editor excluded.
 */
defined('ABSPATH') || exit;
add_action('elementor/frontend/container/before_render', static function($element) {
    if (!MAD4B_Presentation_Experience::enabled('city-card-intermediate-image')||is_admin()||wp_doing_ajax())return;
    $configured=(array)get_option('mad4b_city_lcp_preload_v1',[]);
    $id=(string)($configured['card_element_id']??'');
    if(!preg_match('/^[a-f0-9]{6,12}$/D',$id)
      ||!method_exists($element,'get_id')||$element->get_id()!==$id)return;
    if (class_exists('Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->editor)
        && \Elementor\Plugin::$instance->editor->is_edit_mode())return;
    $settings=$element->get_settings_for_display();
    $image=$settings['background_image']??null;
    if(!is_array($image))return;
    $id=absint($image['id']??0);
    if(!$id&&!empty($image['url'])&&is_string($image['url']))$id=attachment_url_to_postid($image['url']);
    if(!$id||get_post_type($id)!=='attachment')return;
    $src=wp_get_attachment_image_src($id,'medium_large');
    if(!is_array($src)||empty($src[0])||(int)$src[1]<400)return;
    $url=esc_url_raw($src[0]);
    $host=wp_parse_url($url,PHP_URL_HOST);
    if(!$host||$host!==wp_parse_url(home_url(),PHP_URL_HOST))return;
    // Container wrapper override wins over the generated full-size background.
    // The specific card still keeps its Elementor layout, crop and overlay.
    $element->add_render_attribute('_wrapper','style','background-image:url("'.esc_url($url).'");');
},125);

