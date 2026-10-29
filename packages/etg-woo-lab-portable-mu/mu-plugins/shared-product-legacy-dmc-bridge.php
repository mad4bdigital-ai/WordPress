<?php
/** Read-only compatibility inspection; never creates organizations or legacy JetEngine relations. */
if(!defined('ABSPATH'))return;
final class Shared_Product_Legacy_DMC_Bridge {
 public const RELATION_ID=154;
 public static function inspect():array {
  global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare("SELECT args FROM {$wpdb->prefix}jet_post_types WHERE id=%d AND status='relation'",self::RELATION_ID));
  if(!$row)return ['ready'=>false,'reason'=>'relation_definition_missing'];
  $args=maybe_unserialize($row->args);
  $expected=($args['parent_object']??'')==='posts::dmcs' && ($args['child_object']??'')==='posts::tours-and-activities';
  $links=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=%d",self::RELATION_ID));
  return ['ready'=>$expected,'relation_id'=>self::RELATION_ID,'parent_object'=>$args['parent_object']??null,'child_object'=>$args['child_object']??null,'parent_rel'=>$args['parent_rel']??null,'parent_relation_requires_review'=>!empty($args['parent_rel']),'legacy_links'=>$links,'migration_permitted'=>false];
 }
 public static function linked_tours(int $dmc_id):array {
  global $wpdb;
  if(get_post_type($dmc_id)!=='dmcs')return [];
  return array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT DISTINCT child_object_id FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=%d AND parent_object_id=%d",self::RELATION_ID,$dmc_id)));
 }
 public static function resolve_existing_organization(int $dmc_id) {
  if(get_post_type($dmc_id)!=='dmcs')return new WP_Error('invalid_dmc','Invalid legacy DMC');
  $id=(int)get_post_meta($dmc_id,'_linked_organization_id',true);
  if(!$id||get_post_type($id)!=='organization')return new WP_Error('organization_mapping_missing','Explicit organization mapping required');
  return $id;
 }
}
