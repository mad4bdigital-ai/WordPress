<?php
defined('ABSPATH')||exit;
add_filter('elementor/frontend/container/should_render',static function($should,$element){
 if(!$should||is_admin()||!is_tax('destinations')||!is_object($element)||!method_exists($element,'get_children'))return $should;
 $heading=false;$queries=[];$walk=function($children)use(&$walk,&$heading,&$queries){foreach($children as $child){$s=$child->get_settings();if(preg_match('/Explore\s+More\s+Destinations/i',(string)($s['title']??'')))$heading=true;if($child->get_name()==='jet-listing-grid'&&($s['custom_query']??'')==='yes'&&!empty($s['custom_query_id']))$queries[]=(int)$s['custom_query_id'];$walk($child->get_children());}};$walk($element->get_children());
 if(!$heading||!$queries)return $should;
 if(!class_exists('Jet_Engine\Query_Builder\Manager'))return false;
 foreach(array_unique($queries) as $id){$q=\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id($id);if($q&&count((array)$q->get_items()))return true;}return false;
},990,2);
add_filter('elementor/widget/render_content',static function($content,$widget){
 if(!is_tax('destinations')||!is_object($widget)||$widget->get_name()!=='jet-listing-dynamic-field')return $content;
 if(preg_match('~class=["\'][^"\']*jet-listing-dynamic-field__content[^"\']*["\'][^>]*>(.*?)</(?:div|span)>~s',$content,$m)&&trim(html_entity_decode(wp_strip_all_tags($m[1])))==='')return '';
 return $content;
},990,2);
