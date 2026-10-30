<?php
// Usage on an authorized WordPress Staging lab:
// wp eval-file wp-content/plugins/mad4b-dfsb-native-presentation/tests/wp-cli-smoke.php
if (!defined('WP_CLI') || !WP_CLI) { throw new \RuntimeException('WP-CLI required'); }
require_once dirname(__DIR__).'/mad4b-dfsb-native-presentation.php';
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
