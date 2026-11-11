<?php
/** Browser-native lazy images for hidden navigation; no LiteSpeed placeholders. */
defined('ABSPATH') || exit;
add_filter('elementor/widget/render_content',static function($content,$widget){
 if($widget->get_name()!=='image')return $content;
 $classes=preg_split('/\s+/',trim((string)$widget->get_settings('_css_classes')));
 if(!in_array('navigation-editor-card-image',$classes,true))return $content;
 $html=new WP_HTML_Tag_Processor($content);
 while($html->next_tag('IMG')){
  // Keep LS lazy placeholders disabled; browser-native loading=lazy fetches
  // images when their menu becomes visible, without eagerly fetching all terms.
  // The header logo and currently visible hero images are NOT in this widget.
  $html->set_attribute('data-no-lazy','1');
  $html->set_attribute('loading','lazy');
  $html->remove_attribute('fetchpriority');
 }
 return $html->get_updated_html();
},20,2);
