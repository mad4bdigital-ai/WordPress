<?php
/**
 * Idempotent, reversible Fluent Forms #6 Guide Language preference migration.
 * Run with wp eval-file in the target site's WordPress runtime.
 * Default = dry run. MAD4B_FORM6_APPLY=1 writes after a JSON backup.
 * Does not calculate guide cost, enable bookings or alter orders.
 */
if (!defined('ABSPATH') || !defined('WP_CLI')) { exit(1); }
global $wpdb;
$table=$wpdb->prefix.'fluentform_forms';
$row=$wpdb->get_row($wpdb->prepare("SELECT id,title,status,form_fields FROM {$table} WHERE id=%d",6),ARRAY_A);
if(!$row || $row['status']!=='published' || strpos((string)$row['title'],'Tour Order Form')===false) {
  WP_CLI::error('Expected published Tour Order Form #6 not found');
}
$original=(string)$row['form_fields'];
$form=json_decode($original,true);
if(!is_array($form)||!isset($form['fields'])||!is_array($form['fields'])) {
  WP_CLI::error('Form structure invalid');
}
$known=[];
foreach($form['fields'] as $field) {
  $name=(string)($field['attributes']['name']??'');
  if($name!=='') $known[$name]=true;
}
if(isset($known['guide_language'])) {
  WP_CLI::log('ALREADY_PRESENT guide_language site='.get_site_url().' sha256='.hash('sha256',$original));
  return;
}
if(!taxonomy_exists('guide-languages')) WP_CLI::error('Guide Language taxonomy missing');
$terms=get_terms(['taxonomy'=>'guide-languages','hide_empty'=>false,'orderby'=>'name','order'=>'ASC']);
if(is_wp_error($terms) || !$terms) WP_CLI::error('Guide Language terms unavailable');
$options=[];
foreach($terms as $term) {
  if(str_starts_with((string)$term->slug,'qa-') || str_starts_with((string)$term->name,'QA ONLY')) continue;
  if(!preg_match('/^[a-z0-9-]{2,64}$/D',(string)$term->slug)) continue;
  $options[]=['label'=>$term->name,'value'=>$term->slug,'calc_value'=>'','id'=>count($options)];
}
if(count($options)<2) WP_CLI::error('Not enough guide language choices');
$template=null;
foreach($form['fields'] as $i=>$field) {
  if(($field['element']??'')==='select' && ($field['attributes']['name']??'')==='dropdownitenary') {
    $template=$field;
    $insert_at=$i+1;
    break;
  }
}
if(!$template) WP_CLI::error('Itinerary select anchor absent');
$template['index']=count($form['fields'])+1;
$template['attributes']=['name'=>'guide_language','value'=>'','id'=>'','class'=>''];
$template['settings']['dynamic_default_value']='';
$template['settings']['label']='Guide Language';
$template['settings']['admin_field_label']='Guide Language Preference';
$template['settings']['help_message']='Language preference only. Availability and any guide fees are confirmed in your quote.';
$template['settings']['container_class']='mad4b-guide-language-field';
$template['settings']['label_placement']='default';
$template['settings']['placeholder']='Preferred guide language (optional)';
$template['settings']['advanced_options']=$options;
$template['settings']['validation_rules']['required']=[
 'value'=>false,'message'=>'This field is required',
 'global_message'=>'This field is required','global'=>true
];
$template['settings']['conditional_logics']['status']=false;
$template['uniqElKey']='el_mad4b_guide_language_v1';
array_splice($form['fields'],$insert_at,0,[$template]);
$new=wp_json_encode($form,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
if(!$new || json_last_error()!==JSON_ERROR_NONE) WP_CLI::error('New JSON invalid');
$dry=getenv('MAD4B_FORM6_APPLY')!=='1';
WP_CLI::log(($dry?'DRY_RUN':'APPLY').' site='.get_site_url().' choices='.count($options).' before='.hash('sha256',$original).' after='.hash('sha256',$new));
if($dry) return;
$backup='/tmp/mad4b-form6-guide-language-before-'.gmdate('Ymd-His').'.json';
if(file_put_contents($backup,$original)===false) WP_CLI::error('Cannot write backup');
$affected=$wpdb->update($table,['form_fields'=>$new,'updated_at'=>current_time('mysql')],['id'=>6],['%s','%s'],['%d']);
if($affected!==1) WP_CLI::error('DB update failed; backup '.$backup);
$actual=(string)$wpdb->get_var($wpdb->prepare("SELECT form_fields FROM {$table} WHERE id=%d",6));
if(hash('sha256',$actual)!==hash('sha256',$new)) WP_CLI::error('Readback mismatch; backup '.$backup);
WP_CLI::success('FORM6_GUIDE_LANGUAGE_ADDED backup='.$backup.' sha256='.hash('sha256',$actual));
