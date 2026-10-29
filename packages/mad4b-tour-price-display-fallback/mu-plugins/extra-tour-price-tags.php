<?php
/** Editor-owned coherent Tour/Extra indicative prices. No booking authority. */
defined('ABSPATH') || exit;
function tour_component_price_current_id(){
 $object=function_exists('jet_engine')?jet_engine()->listings->data->get_current_object():null;
 if($object instanceof WP_Post)return $object->post_type==='tours-and-activities'?(int)$object->ID:0;
 $id=get_the_ID();return get_post_type($id)==='tours-and-activities'?(int)$id:0;
}
function tour_component_price_date_scope($mode,callable $callback){
 if($mode==='inherited')return $callback();
 $keys=[];$override=static function()use($mode){return $mode;};
 if(function_exists('jet_engine'))foreach((array)jet_engine()->options_pages->data->get_items()as$page){
  $fields=maybe_unserialize($page['meta_fields']??[]);if(!is_array($fields))continue;
  if(in_array('shared_financial_rules_json',array_column($fields,'name'),true)&&in_array('shared_price_card_date_mode',array_column($fields,'name'),true)){
   $key='pre_option_'.$page['slug'].'_shared_price_card_date_mode';add_filter($key,$override,999);$keys[]=$key;
  }
 }
 try{return $callback();}finally{foreach($keys as$key)remove_filter($key,$override,999);}
}
function tour_component_price_unit($post){
 if(get_post_type($post)==='tour-rates'){
  $r=function_exists('shared_product_resolve_price_unit')?shared_product_resolve_price_unit($post):null;
  return is_array($r)?($r['unit']??''):'';
 }
 $unit=get_post_meta($post,'tour_price_unit',true);
 return is_string($unit)&&in_array($unit,['per_person','per_trip','per_day','per_room','per_vehicle','per_cabin','table'],true)?$unit:'';
}
/**
 * Site-bound and explicitly opted-in nationality price display policy.
 * The passport selector by itself NEVER authorizes special audience pricing.
 *
 * Option contract company_tour_price_audience_policy_v1:
 * {schema_version:1, site_uuid:<registered site UUID>, mode:'configured',
 *  rules:[{nationality:'EG', audience:'egyptians'}]}
 *
 * When absent/mismatched, all visitor nationalities receive the general
 * indicative display price. Checkout quote authority is unchanged.
 */
function tour_component_company_price_auto_audience(int $tour_id): string {
 $raw=get_option('company_tour_price_audience_policy_v1',[]);
 $registry=get_option('commerce_company_policy_registry_v1',[]);
 if(!is_array($raw)||!is_array($registry)||
    ($raw['schema_version']??null)!==1||
    ($raw['mode']??'general')!=='configured')return 'general';
 $registered=(string)($registry['site_uuid']??'');
 $provided=(string)($raw['site_uuid']??'');
 if($registered===''||$provided===''||!hash_equals($registered,$provided))
  return 'general';
 $rules=$raw['rules']??[];
 if(!is_array($rules)||count($rules)>64)return 'general';
 $passport=class_exists('Shared_Product_Nationality_Source')
  ?Shared_Product_Nationality_Source::selected():null;
 if(!is_string($passport)||!preg_match('/^[A-Z]{2}$/D',$passport))return 'general';
 $chosen=null;
 foreach($rules as $row){
  if(!is_array($row)||!isset($row['nationality'],$row['audience'])||
     !is_string($row['nationality'])||!is_string($row['audience']))
   return 'general';
  if(!preg_match('/^[A-Z]{2}$/D',$row['nationality']))return 'general';
  // Only mapped audiences with implemented price resolvers are eligible.
  // Schema can add more nationalities without assuming a matching rate schema.
  if($row['nationality']!=='EG'||$row['audience']!=='egyptians')
   return 'general';
  if($row['nationality']===$passport){
   if($chosen!==null)return 'general'; // ambiguity never activates special pricing
   $chosen=$row['audience'];
  }
 }
 return $chosen?:'general';
}

function tour_component_price_evidence($id,$audience='auto',$date_mode='inherited'){
 if(!in_array($audience,['auto','general','egyptians'],true)||!in_array($date_mode,['inherited','active_today','upcoming'],true))return new WP_Error('component_price_context_invalid');
 $post=get_post($id);if(!$post||$post->post_type!=='tours-and-activities'||$post->post_status!=='publish')return new WP_Error('component_price_not_public');
 $qa_label_allowed=($audience==='auto'||$audience==='general')
  &&function_exists('lab_qa_extra_price_label_allowed')
  &&lab_qa_extra_price_label_allowed((int)$id);
 if(!current_user_can('manage_woocommerce')&&
    ($post->post_password!==''||get_post_meta($id,'_booking_experience_qa_pricing_fixture',true)!=='')
    &&!$qa_label_allowed)return new WP_Error('component_price_protected');
 if($audience==='auto')$audience=tour_component_company_price_auto_audience((int)$id);
 foreach(['pricing_tour_price_projection','pricing_currency_convert','pricing_display_currency','shared_product_resolve_price_sources']as$f)if(!function_exists($f))return new WP_Error('component_price_provider_unavailable');
 $target=pricing_display_currency();$candidates=[];$rate_id=0;
 $direct=get_post_meta($id,$audience==='egyptians'?'egyptians_start_price':'start_price',true);
 $currency=get_post_meta($id,$audience==='egyptians'?'tour_egyptians_currency':'tour_base_currency',true);
 if(is_numeric($direct)&&is_finite((float)$direct)&&(float)$direct>=0&&is_string($currency)&&preg_match('/^[A-Z]{3}$/D',$currency))$candidates['direct']=['amount'=>(float)$direct,'currency'=>$currency];
 if($audience==='general'&&function_exists('shared_lab_tour_price_resolve')){
  $resolved=shared_lab_tour_price_resolve((int)$id);
  if(($resolved['status']??'')==='available'){
   $chosen=$resolved['best'];$rid=(int)($chosen['rate_id']??0);
   $unit=tour_component_price_unit($rid?:$id);
   if($unit==='')$unit='per_person';
   return ['tour_id'=>(int)$id,'rate_id'=>$rid,
    'source'=>$rid?'rate':'direct','audience'=>$audience,
    'amount'=>$chosen['final'],'currency'=>$resolved['currency'],
    'unit'=>$unit,'upcoming'=>$resolved['period']==='upcoming',
    'status'=>'indicative_not_booking_authority'];
  }
  return new WP_Error('component_price_unavailable');
 }
 if($audience==='general'){
  $projection=tour_component_price_date_scope($date_mode,static function()use($id){return pricing_tour_price_projection($id);});
  if(($projection['reason']??'')==='fx_unavailable_for_eligible_rate')return new WP_Error('component_price_fx_unavailable');
  if(($projection['status']??'')==='available'){$b=$projection['best'];$rate_id=(int)$b['rate_id'];$rp=get_post($rate_id);if(!$rp||(!current_user_can('manage_woocommerce')&&($rp->post_password!==''||get_post_meta($rate_id,'_booking_experience_qa_pricing_fixture',true)!=='')))return new WP_Error('component_price_rate_protected');$candidates['rate']=['amount'=>$b['final'],'currency'=>$b['currency']];}
 }else{
  // Reuse the authoritative Egyptian quote; do not substitute a general price.
  // Its current-date restriction remains intact; upcoming Egyptian Rates require a date-aware quote provider.
  $rows=[];$failed_fx=false;
  $ids=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>101,'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>$id]);
  if(count($ids)>100)return new WP_Error('component_price_rate_limit');
  foreach($ids as$rid){
   $rp=get_post($rid);if(!current_user_can('manage_woocommerce')&&($rp->post_password!==''||get_post_meta($rid,'_booking_experience_qa_pricing_fixture',true)!==''))continue;
   foreach(['single','double','triple']as$occupancy){$q=pricing_audience_quote($rid,'egyptians',$occupancy);if(($q['status']??'')!=='available')continue;
    $v=pricing_currency_convert($q['final'],$q['source_currency'],$target);if($v===null){$failed_fx=true;continue;}$rows[]=['id'=>$rid,'amount'=>$v];
   }
  }
  if($failed_fx)return new WP_Error('component_price_fx_unavailable');
  usort($rows,static fn($a,$b)=>($a['amount']<=>$b['amount'])?:($a['id']<=>$b['id']));
  if($rows){$rate_id=(int)$rows[0]['id'];$candidates['rate']=['amount'=>$rows[0]['amount'],'currency'=>$target];}
 }
 $choice=shared_product_resolve_price_sources($candidates,(string)get_post_meta($id,'tour_mode',true));if(is_wp_error($choice))return $choice;
 $source_id=$choice['source']==='rate'?$rate_id:$id;$unit=tour_component_price_unit($source_id);if($unit==='')return new WP_Error('component_price_unit_unconfigured');
 $amount=pricing_currency_convert($choice['amount'],$choice['currency'],$target);if($amount===null)return new WP_Error('component_price_fx_unavailable');
 $upcoming=$choice['source']==='rate'&&(int)get_post_meta($rate_id,'tour_rate_start_date',true)>current_time('timestamp',true);
 return ['tour_id'=>(int)$id,'rate_id'=>$choice['source']==='rate'?$rate_id:0,'source'=>$choice['source'],'audience'=>$audience,'amount'=>$amount,'currency'=>$target,'unit'=>$unit,'upcoming'=>$upcoming,'status'=>'indicative_not_booking_authority'];
}
function tour_component_price_html($id=0,$audience='auto',$date_mode='inherited',$show_unit=true){
 $id=$id?:tour_component_price_current_id();$e=tour_component_price_evidence($id,$audience,$date_mode);
 if(is_wp_error($e))return '<span class="price-on-request">'.esc_html__('Price on request','woocommerce').'</span>';
 $labels=['per_person'=>__('per person','woocommerce'),'per_trip'=>__('per trip','woocommerce'),'per_day'=>__('per day','woocommerce'),'per_room'=>__('per room','woocommerce'),'per_vehicle'=>__('per vehicle','woocommerce'),'per_cabin'=>__('per cabin','woocommerce'),'table'=>__('per table','woocommerce')];
 return '<span class="tour-component-price" data-price-source="'.esc_attr($e['source']).'" data-price-unit="'.esc_attr($e['unit']).'"><span class="price-label">'.esc_html($e['upcoming']?__('Upcoming from','woocommerce'):__('From','woocommerce')).' </span>'.wp_kses_post(wc_price($e['amount'],['currency'=>$e['currency']])).($show_unit?' <span class="price-unit">/ '.esc_html($labels[$e['unit']]).'</span>':'').'</span>';
}
add_action('elementor/dynamic_tags/register',static function($manager){
 if(!class_exists('Elementor\\Core\\DynamicTags\\Data_Tag'))return;
 if(!class_exists('Tour_Component_Price_Tag')){
  class Tour_Component_Price_Tag extends Elementor\Core\DynamicTags\Data_Tag {
   public function get_name(){return 'tour-component-price';}
   public function get_title(){return __('Shared Pricing · Tour / Extra Price','woocommerce');}
   public function get_group(){return 'shared_pricing';}
   public function get_categories(){return [Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
   protected function register_controls(){
    $this->add_control('audience',['label'=>__('Audience','woocommerce'),'type'=>Elementor\Controls_Manager::SELECT,'options'=>['auto'=>'Current nationality profile','general'=>'General','egyptians'=>'Egyptian'],'default'=>'auto']);
    $this->add_control('date_mode',['label'=>__('Card date policy','woocommerce'),'type'=>Elementor\Controls_Manager::SELECT,'options'=>['inherited'=>'Inherit shared policy','active_today'=>'Active today','upcoming'=>'Include upcoming indicative Rates'],'default'=>'inherited']);
    $this->add_control('show_unit',['label'=>__('Show pricing unit','woocommerce'),'type'=>Elementor\Controls_Manager::SWITCHER,'default'=>'yes']);
   }
   public function get_value(array $options=[]){return tour_component_price_html(0,(string)($this->get_settings('audience')?:'auto'),(string)($this->get_settings('date_mode')?:'inherited'),$this->get_settings('show_unit')==='yes');}
  }
 }
 $manager->register_group('shared_pricing',['title'=>__('Shared Pricing','woocommerce')]);$manager->register(new Tour_Component_Price_Tag());
},20);
