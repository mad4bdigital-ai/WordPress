<?php
/**
 * Plugin Name: JetEngine Term Tree Macros (Safe)
 * Description: Adds safe macros to JetEngine (no args controls) to output parent tree any depth.
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * IMPORTANT for this JetEngine version:
 * - Use jet-engine/listings/macros-list
 * - Callback MUST accept 2 params because JetEngine always passes 2
 */

if ( ! function_exists( 'term_tree_get_queried_term' ) ) {
function term_tree_get_queried_term() {
  $obj = get_queried_object();
  return ($obj instanceof WP_Term) ? $obj : null;
}
}

if ( ! function_exists( 'term_tree_get_ancestors' ) ) {
function term_tree_get_ancestors(WP_Term $term): array {
  $out = [];
  $seen = [];
  $cur = $term;

  while ( ! empty($cur->parent) ) {
    $pid = (int) $cur->parent;
    if ($pid <= 0) break;
    if (isset($seen[$pid])) break;
    $seen[$pid] = true;

    $p = get_term($pid, $term->taxonomy);
    if (!$p || is_wp_error($p)) break;

    $out[] = $p;
    $cur = $p;

    if (count($out) > 100) break;
  }

  return $out; // closest parent -> root
}
}

/** %queried_term_parent_id% */
if ( ! function_exists( 'queried_term_parent_id_cb' ) ) {
function queried_term_parent_id_cb($value = false, $args = '') {
  $t = term_tree_get_queried_term();
  if (!$t) return '';
  return (string) (int) $t->parent;
}
}

/** %queried_term_ancestors_ids% => "45,12,3" */
if ( ! function_exists( 'queried_term_ancestors_ids_cb' ) ) {
function queried_term_ancestors_ids_cb($value = false, $args = '') {
  $t = term_tree_get_queried_term();
  if (!$t) return '';
  $anc = term_tree_get_ancestors($t);
  if (!$anc) return '';
  $ids = array_map(fn($x) => (string)(int)$x->term_id, $anc);
  return implode(',', $ids);
}
}

/** %queried_term_ancestors_names% => "Aswan > Upper Egypt > Egypt" */
if ( ! function_exists( 'queried_term_ancestors_names_cb' ) ) {
function queried_term_ancestors_names_cb($value = false, $args = '') {
  $t = term_tree_get_queried_term();
  if (!$t) return '';
  $anc = term_tree_get_ancestors($t);
  if (!$anc) return '';
  $names = array_map(fn($x) => $x->name, $anc);
  return implode(' > ', array_map('esc_html', $names));
}
}

/** %queried_term_root_id% => top-most ancestor id (or current if no parent) */
if ( ! function_exists( 'queried_term_root_id_cb' ) ) {
function queried_term_root_id_cb($value = false, $args = '') {
  $t = term_tree_get_queried_term();
  if (!$t) return '';
  $anc = term_tree_get_ancestors($t);
  if (!$anc) return (string)(int)$t->term_id;
  $root = end($anc);
  return $root ? (string)(int)$root->term_id : (string)(int)$t->term_id;
}
}

add_filter('jet-engine/listings/macros-list', function($list){

  if (!is_array($list)) $list = [];

  $list['queried_term_parent_id'] = [
    'label' => 'Queried Term Parent ID',
    'cb'    => 'queried_term_parent_id_cb',
  ];

  $list['queried_term_ancestors_ids'] = [
    'label' => 'Queried Term Ancestors IDs (CSV)',
    'cb'    => 'queried_term_ancestors_ids_cb',
  ];

  $list['queried_term_ancestors_names'] = [
    'label' => 'Queried Term Ancestors Names (Breadcrumb)',
    'cb'    => 'queried_term_ancestors_names_cb',
  ];

  $list['queried_term_root_id'] = [
    'label' => 'Queried Term Root ID',
    'cb'    => 'queried_term_root_id_cb',
  ];

  return $list;

}, 20);
