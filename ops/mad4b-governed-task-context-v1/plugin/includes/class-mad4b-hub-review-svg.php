<?php
defined('ABSPATH') || exit;
/**
 * Dynamic evidence-backed, safe SVG review diagrams.
 * All shapes, attributes, labels and coordinates are generated from this
 * trusted renderer. The model/provider supplies only typed review data.
 * No script, foreignObject, style injection, image href or external URLs.
 */
final class MAD4B_Hub_Review_SVG {
 const CONTRACT='mad4b.hub.review-svg.v1';
 public static function plan($review){
  $o=is_array($review['output']??null)?$review['output']:[];
  $refs=array_filter((array)($o['evidence_refs']??[]),'is_string');
  $count=count($refs);
  $qa=!empty($review['format_pass']);
  $status=$review['review']['decision']??'AWAITING_MASTER_REVIEW';
  $choices=['approve','needs_changes','reject'];
  $valid_status=in_array($status,$choices,true) || $status==='AWAITING_MASTER_REVIEW';
  // Only display when a source-bound reviewed output has enough separate
  // checkpoints to be more intelligible than a plain short text card.
  $use=$count>0 && $qa && $valid_status;
  $review_done=$status==='approve'?'complete':(
   in_array($status,['needs_changes','reject'],true)?'attention':'pending');
  return [
   'contract'=>self::CONTRACT,'render'=>$use,
   'type'=>$use?'review_flow':'none',
   'nodes'=>[
    ['id'=>'source','state'=>$count?'complete':'attention','count'=>$count],
    ['id'=>'qa','state'=>$qa?'complete':'attention'],
    ['id'=>'review','state'=>$review_done],
    ['id'=>'publish','state'=>'locked']
   ],
   'publication_verified'=>false,'source_reference_count'=>$count,
   'chart_is_evidence_visualization'=>true,
   'source_claims_validated'=>!empty($review['facts_verified'])
  ];
 }
 public static function render($review,$bilingual=false){
  $plan=self::plan($review);
  if(!$plan['render'])return '';
  $states=array_column($plan['nodes'],'state');
  $names=['Source','Output QA','Editorial review','Publishing'];
  $ar=['المصدر','فحص التنسيق','المراجعة التحريرية','النشر'];
  $short=['Source','QA','Review','Publish'];
  $desc='Evidence references present; formatting QA recorded; editorial decision '
    .self::status_text($states[2]).'; publishing is not authorized by this review.';
  $html='<section class="mad4b-rv-visual" aria-label="Review workflow illustration">';
  $html.='<div class="mad4b-rv-visual-heading"><div><strong>'
    .($bilingual?'مخطط مراحل المراجعة / ':'').'Review flow</strong>'
    .'<p>'.($bilingual?'مبني على حالة المسودة الفعلية؛ النشر منفصل. ':'')
    .'An illustration of recorded review states, not an approval certificate.</p></div>'
    .'<span class="mad4b-rv-visual-tag">SVG · Dynamic</span></div>';
  $html.='<div class="mad4b-rv-svg-scroll"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1220 135" width="100%" height="auto" role="img"'
    .' aria-label="'.esc_attr($desc).'">';
  $html.='<title>'.esc_html('Source to publication review stages').'</title>';
  $html.='<desc>'.esc_html($desc).'</desc>';
  // Fixed coordinates eliminate any path interpolation or script injection.
  foreach([0,1,2] as $i){
   $x=284+$i*310;
   $html.='<line x1="'.(int)$x.'" y1="62" x2="'.(int)($x+27).'" y2="62" stroke="#a8b6cb" stroke-width="2" stroke-linecap="round"/>';
   $html.='<path d="M'.(int)($x+21).' 57 L'.(int)($x+27).' 62 L'.(int)($x+21).' 67" fill="none" stroke="#a8b6cb" stroke-width="2" />';
  }
  foreach($states as $i=>$state){
   $x=10+$i*310;$fill=['complete'=>'#e3f6eb','pending'=>'#f2eafd',
    'attention'=>'#fff0e2','locked'=>'#eef1f6'][$state]??'#eef1f6';
   $stroke=['complete'=>'#1a9d64','pending'=>'#8256ba',
    'attention'=>'#cd8731','locked'=>'#96a3b8'][$state]??'#96a3b8';
   $html.='<rect x="'.(int)$x.'" y="12" width="268" height="101" rx="12" fill="#fff" stroke="#d9e3ef"/>';
   $html.='<circle cx="'.(int)($x+24).'" cy="36" r="12" fill="'.$fill.'" stroke="'.$stroke.'" stroke-width="1.5"/>';
   if($state==='complete')$symbol='✓';
   elseif($state==='locked')$symbol='–';
   elseif($state==='attention')$symbol='!';
   else $symbol='·';
   $html.='<text x="'.(int)($x+24).'" y="41" font-size="16" fill="'.$stroke.'" font-weight="700" text-anchor="middle">'.esc_html($symbol).'</text>';
   $html.='<text x="'.(int)($x+44).'" y="40" font-size="13" font-weight="700" fill="#25334a">'.esc_html($names[$i]).'</text>';
   $html.='<rect x="'.(int)($x+13).'" y="56" width="238" height="36" rx="7" fill="'.$fill.'"/>';
   $caption=($i===0 ? $plan['source_reference_count'].' refs' :
     ($i===1?'Format checked':($i===3?'Not authorized':self::status_text($state))));
   $html.='<text x="'.(int)($x+135).'" y="78" text-anchor="middle" font-size="12" font-weight="600" fill="'.$stroke.'">'.esc_html($caption).'</text>';
  }
  $html.='</svg></div>';
  if($bilingual){
   $html.='<ol class="mad4b-rv-svg-legend">';
   foreach($names as $i=>$n)$html.='<li><span lang="ar" dir="rtl">'.esc_html($ar[$i]).'</span>'
     .'<span class="mad4b-rv-labelslash"> / </span><span lang="en">'.esc_html($n).'</span></li>';
   $html.='</ol>';
  }
  $html.='</section>';
  return $html;
 }
 private static function status_text($state){
  return ['complete'=>'Recorded','pending'=>'Awaiting decision','attention'=>'Needs attention',
    'locked'=>'Not authorized'][$state]??'Unknown';
 }
 public static function inspect($input=[]){
  $batch=(string)($input['batch_id']??'');
  if(!wp_is_uuid($batch))return new WP_Error('mad4b_review_svg_invalid_batch','Invalid review batch.',[
   'status'=>400
  ]);
  $review=MAD4B_Hub_Role_Review::preview(['batch_id'=>$batch]);
  if(is_wp_error($review))return $review;
  $plan=self::plan($review);
  $plan['batch_id']=$batch;
  $plan['language_policy']=MAD4B_Hub_Review_Language::status();
  $plan['sanitized_svg_rendering_server_owned']=true;
  $plan['remote_svg_markup_allowed']=false;
  return $plan;
 }
 public static function boot(){
  add_action('wp_abilities_api_init',[__CLASS__,'abilities'],64);
  add_action('rest_api_init',[__CLASS__,'routes'],64);
 }
 public static function abilities(){
  if(!function_exists('wp_register_ability'))return;
  $name='mad4b/hub-review-svg-plan';
  if(wp_has_ability($name))return;
  wp_register_ability($name,[
   'label'=>'Content Hub Review SVG Plan',
   'description'=>'Read-only site-bound review diagram plan derived from verified review evidence; no markup or mutation.',
   'category'=>'mad4b-read','execute_callback'=>[__CLASS__,'inspect'],
   'permission_callback'=>static fn()=>get_current_user_id()>0 &&
     (current_user_can('edit_posts')||current_user_can('mad4b_manage_company_operations')||current_user_can('manage_options')),
   'input_schema'=>['type'=>'object','properties'=>['batch_id'=>['type'=>'string']],'required'=>['batch_id'],'additionalProperties'=>false],
   'output_schema'=>['type'=>'object','additionalProperties'=>true],
   'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
     'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
  ]);
 }
 public static function routes(){
  register_rest_route('mad4b-hub/v1','/control-workflow/review-svg/plan',[
   'methods'=>'GET',
   'permission_callback'=>static fn()=>get_current_user_id()>0 &&
     (current_user_can('edit_posts')||current_user_can('mad4b_manage_company_operations')||current_user_can('manage_options')),
   'callback'=>static fn($request)=>self::inspect($request->get_params())
  ]);
 }
}
