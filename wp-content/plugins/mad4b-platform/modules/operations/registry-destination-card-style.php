<?php
defined('ABSPATH') || exit;
if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function registry_destination_card_style(){
 $v=get_option('look-and-feel',[]);
 $v=is_array($v)?$v:[];
 $primary=(string)($v['destination_activity_card_primary']??'15666');
 $alternate=(string)($v['destination_activity_card_alternate']??'15672');
 if(!in_array($primary,['15666','15672'],true))$primary='15666';
 if(!in_array($alternate,['15666','15672','none'],true))$alternate='none';
 return ['primary'=>$primary,'alternate'=>$alternate];
}
add_filter('elementor/frontend/builder_content_data',static function($nodes,$template_id){
 if(!in_array((int)$template_id,[67247,67249],true)||!is_array($nodes))return $nodes;
 $c=registry_destination_card_style();
 $ids=(int)$template_id===67249?['15666'=>67259,'15672'=>67260]:['15666'=>67257,'15672'=>67258];
 $visit=function(&$nodes)use(&$visit,$ids,$c){
  foreach($nodes as &$node){
   if(($node['widgetType']??'')==='jet-listing-grid'&&($node['settings']['_element_id']??'')==='registry_destination_activity_grid'){
    $s=&$node['settings'];
    $s['lisitng_id']=(string)$ids[$c['primary']];
    // All cards in a view use one Listing. Alternate controls List mode only.
    $s['inject_alternative_items']='';
    $s['injection_items']=[];
    unset($s);
   }
   if(!empty($node['elements']))$visit($node['elements']);
  }
  unset($node);
 };
 $visit($nodes);
 return $nodes;
},35,2);

/** Resolve a source-design ID into this language's authorized Listing copy. */
function registry_destination_card_listing_map(string $language,array $style):array {
	$maps=[
		'en'=>['15666'=>67257,'15672'=>67258],
		'ar'=>['15666'=>67259,'15672'=>67260]
	];
	$map=$maps[$language]??$maps['en'];
	$primary=(int)$map[$style['primary']];
	// Both Grid and List are different arrangements of ONE selected card source.
	// The legacy alternate option is retained in storage for rollback only.
	return ['primary'=>$primary,'alternate'=>0,'list'=>$primary];
}
/**
 * Keep native Elementor Document settings in sync with the JetEngine Look and
 * Feel Options Page. The layout-switcher addon reads stored Elementor data
 * directly, so a frontend dynamic-only override is insufficient.
 */
function registry_destination_card_sync_native_documents(array $style):array {
	if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return [];
	$changed=[];
	foreach([67247=>'en',67249=>'ar'] as $template_id=>$lang){
		$source=(string)get_post_meta($template_id,'_elementor_data',true);
		$nodes=json_decode($source,true);
		if(!is_array($nodes)||!$nodes)continue;
		$values=registry_destination_card_listing_map($lang,$style);
		$found=['grid'=>0,'switcher'=>0];
		$walk=function(&$items)use(&$walk,&$found,$values){
			foreach($items as &$node){
				$type=$node['widgetType']??'';
				if($type==='jet-listing-grid'&&($node['settings']['_element_id']??'')==='registry_destination_activity_grid'){
					$s=&$node['settings'];
					$s['lisitng_id']=(string)$values['primary'];
					// Never mix the List card into a Grid view.
					$s['inject_alternative_items']='';
					$s['injection_items']=[];
					unset($s);
					$found['grid']++;
				}
				if($type==='jet-engine-layout-switcher'){
					foreach($node['settings']['layouts'] as &$layout){
						if(in_array(($layout['slug']??''),['grid','list'],true))
							$layout['lisitng_id']=(string)$values['primary'];
					}
					unset($layout);
					$found['switcher']++;
				}
				if(!empty($node['elements']))$walk($node['elements']);
			}
			unset($node);
		};
		$walk($nodes);
		if($found!==['grid'=>1,'switcher'=>1])continue;
		$next=wp_json_encode($nodes);
		if(!is_string($next)||$next===$source)continue;
		update_post_meta($template_id,'_elementor_data',wp_slash($next));
		if(class_exists('\Elementor\Core\Files\CSS\Post')){
			(new \Elementor\Core\Files\CSS\Post($template_id))->update();
		}
		$changed[]=['id'=>$template_id,'primary'=>$values['primary'],'list'=>$values['list']];
	}
	return $changed;
}
add_action('updated_option',static function($name,$previous,$current){
	if($name!=='look-and-feel')return;
	$old=is_array($previous)?$previous:[];
	$new=is_array($current)?$current:[];
	if(($old['destination_activity_card_primary']??'')===($new['destination_activity_card_primary']??'')
		&&($old['destination_activity_card_alternate']??'')===($new['destination_activity_card_alternate']??''))return;
	registry_destination_card_sync_native_documents(registry_destination_card_style());
},100,3);
