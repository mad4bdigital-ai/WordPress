<?php
// Usage on an authorized WordPress Staging lab:
// wp eval-file wp-content/plugins/mad4b-dfsb-native-presentation/tests/wp-cli-smoke.php
if (!defined('WP_CLI') || !WP_CLI) { throw new \RuntimeException('WP-CLI required'); }
if (!function_exists('MAD4B\\DFSB\\NativePresentation\\ready')) {
    require_once dirname(__DIR__).'/mad4b-dfsb-native-presentation.php';
}
\MAD4B\DFSB\NativePresentation\register_shortcodes();
$bootBefore=\DynamicFilterSEOBridge\Runtime\BootGuard::status();
$ids=get_posts(array('post_type'=>array('post','tours-and-activities'),
 'post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids'));
$id=(int)($ids[0]??0);
$ctx=\MAD4B\DFSB\NativePresentation\context('post',$id);
$title=\MAD4B\DFSB\NativePresentation\value('title',$ctx);
$unresolved=\MAD4B\DFSB\NativePresentation\context('post',999999999);
$tests=array(
 'dependency_available'=>\MAD4B\DFSB\NativePresentation\ready(),
 'published_post_available'=>$id>0,
 'public_title_resolved'=>$title!=='',
 'unresolved_post_denied'=>\MAD4B\DFSB\NativePresentation\value('title',$unresolved)==='',
 'value_shortcode_registered'=>shortcode_exists('dfsb_context_value'),
 'image_shortcode_registered'=>shortcode_exists('dfsb_context_image'),
 'seo_boot_unchanged'=>$bootBefore===\DynamicFilterSEOBridge\Runtime\BootGuard::status(),
);
// A WP-CLI request has no front controller query; supply an actual published
// post as the main query to exercise the real shortcodes without page edits.
if ($id>0 && get_post($id)) {
    $oldQuery=$GLOBALS['wp_query']??null;
    $q=new \WP_Query();
    $q->is_singular=true;
    $q->is_single=true;
    $q->queried_object=get_post($id);
    $q->queried_object_id=$id;
    $q->post=get_post($id);
    $q->posts=array($q->post);
    $q->post_count=1;
    $GLOBALS['wp_query']=$q;
    do_action('wp',$GLOBALS['wp']);
    $rendered=do_shortcode('[dfsb_context_value field="title"]');
    $tests['public_shortcode_renders_current_post']=html_entity_decode($rendered,ENT_QUOTES) === $title;
    $GLOBALS['wp_query']=$oldQuery;
}
if (class_exists('Elementor\\Plugin')) {
    $manager=\Elementor\Plugin::$instance->dynamic_tags;
    \MAD4B\DFSB\NativePresentation\register_tags($manager);
    $tags=$manager->get_tags();
    $tests['text_tag_registered']=isset($tags['dfsb-context-value']['instance'])
        && $tags['dfsb-context-value']['instance'] instanceof \MAD4B\DFSB\NativePresentation\NativeValueTag;
    $tests['image_tag_registered']=isset($tags['dfsb-context-image']['instance'])
        && $tags['dfsb-context-image']['instance'] instanceof \MAD4B\DFSB\NativePresentation\NativeImageTag;
}
foreach($tests as $name=>$okay) echo ($okay?'PASS ':'FAIL ').$name.PHP_EOL;
if(in_array(false,$tests,true)) exit(1);
