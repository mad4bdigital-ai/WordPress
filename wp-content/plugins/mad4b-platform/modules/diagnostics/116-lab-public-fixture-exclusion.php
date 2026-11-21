<?php
/** Plugin Name: ETG Lab Public Fixture Exclusion
 * Keep retained pricing QA records out of public tour discovery, preserving direct test access.
 */
if(!defined('ABSPATH'))exit;
if(!defined('DB_NAME')||DB_NAME!=='etg_woocommerce_lab')return;
add_action('pre_get_posts',static function($query){
 if(is_admin()&&!wp_doing_ajax())return;
 if($query->is_singular())return;
 $types=(array)$query->get('post_type');
 if(!array_intersect($types,['tours-and-activities','properties','transportations','any'])&&!$query->is_search())return;
 $exclude=[];
 foreach([66133,66134,66140,66533,66562,66524,66580,66528] as $id){
  if(in_array(get_post_type($id),['tours-and-activities','properties','transportations'],true)&&(strpos((string)get_post_field('post_title',$id),'LAB-PRICING-ACCEPTANCE-20261024 ')===0||strpos((string)get_post_field('post_title',$id),'QA ONLY ')===0))$exclude[]=$id;
 }
 if($exclude)$query->set('post__not_in',array_values(array_unique(array_merge((array)$query->get('post__not_in'),$exclude))));
},999);
