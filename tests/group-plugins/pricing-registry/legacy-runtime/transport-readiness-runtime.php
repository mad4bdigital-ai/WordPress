<?php
/** Run under WP-CLI: wp eval-file tests/transport-readiness-runtime.php */
if (!defined('ABSPATH')) exit(2);
$class = 'MAD4B_Transport_Pricing_Readiness_V1';
if (!class_exists($class)) require_once dirname(__DIR__,4) . '/wp-content/plugins/mad4b-pricing-registry/includes/transport-readiness.php';
$errors = array();
$check = static function ($ok, $message) use (&$errors) { if (!$ok) $errors[] = $message; };
$normalized = $class::decompose(array('fixed_route_schedule', 'seat_based_inventory', 'point_to_point'));
$check(in_array('seat', $normalized['inventory_model'], true), 'seat_inventory');
$check(in_array('fixed_schedule', $normalized['schedule_model'], true), 'fixed_schedule');
$check(in_array('point_to_point', $normalized['route_pattern'], true), 'point_to_point');
$check($class::decompose('nonsense')['unknown_values'] === array('nonsense'), 'unknown_legacy_mode');
$parent = array('id' => 39707, 'exists' => true, 'mode_id' => 568, 'type_id' => 569);
$bad = array('_ID' => 1, 'related_transport_id' => 39707,
    'trans_mode_term_id' => 595, 'trans_type_term_id' => 598,
    'base_currency' => 'USD', 'pricing_type' => 'per_day',
    'base_price' => 1300, 'sale_price' => 1100,
    'trans_start_date' => 1770422400, 'trans_end_date' => 1772236800);
$verdict = $class::assess_rate($bad, $parent, 1790000000);
$check($verdict['status'] === 'review_required', 'mapping_fails');
$check(in_array('mode_mismatch', $verdict['issues'], true), 'mode_mismatch');
$check(in_array('type_mismatch', $verdict['issues'], true), 'type_mismatch');
$check(in_array('rate_expired', $verdict['issues'], true), 'expiry');
$check($verdict['eligible_for_checkout'] === false, 'checkout_disabled');
$good = $bad;
$good['trans_mode_term_id'] = 568; $good['trans_type_term_id'] = 569;
$good['trans_end_date'] = 1999999999;
$check($class::assess_rate($good, $parent, 1790000000)['status'] === 'structurally_valid', 'valid_structure');
$check(in_array('service_route_unclassified', $class::assess_transport(array(
    'mode_id' => 568, 'type_id' => 569, 'type_parent_id' => 568))['issues'], true), 'missing_service_mode');
$check(in_array('type_parent_mismatch', $class::assess_transport(array(
    'mode_id' => 568, 'type_id' => 569, 'type_parent_id' => 578, 'service_mode' => 'point_to_point'))['issues'], true), 'parent_mismatch');
$candidate = MAD4B_Transport_Source_Candidates_V1::snapshot();
$check($candidate['rows_count'] === 51, 'candidate_operations');
$check($candidate['price_cells'] === 210, 'candidate_price_cells');
$check($candidate['rows_by_city'] === array('Cairo'=>13,'Aswan'=>15,'Luxor'=>23), 'candidate_city_counts');
$check($candidate['ready_to_publish'] === false && $candidate['checkout_authorized'] === false, 'candidate_not_sellable');
$check(count(array_filter($candidate['review'], static fn($r) => $r['issue']==='vehicle_price_order_anomaly')) === 1, 'candidate_vehicle_anomaly');
$check(count(array_filter($candidate['review'], static fn($r) => $r['issue']==='all_prices_missing')) === 1, 'candidate_missing_row');
$snapshot = $class::scan();
$check(isset($snapshot['rates_table_present'], $snapshot['transport_count']), 'runtime_snapshot');
$check($snapshot['mutation_performed'] === false && $snapshot['checkout_authorized'] === false, 'read_only');
if ($errors) { echo wp_json_encode(array('status'=>'failed','errors'=>$errors,'snapshot'=>array(
    'transport_count'=>$snapshot['transport_count'],'rates_count'=>$snapshot['rates_count']))); exit(1); }
echo wp_json_encode(array('status'=>'passed','cases'=>17,
    'transport_count'=>$snapshot['transport_count'], 'rates_count'=>$snapshot['rates_count'],
    'transport_issue_counts'=>$snapshot['transport_issue_counts'],
    'rate_issue_counts'=>$snapshot['rate_issue_counts'],
    'checkout_authorized'=>$snapshot['checkout_authorized']), JSON_UNESCAPED_UNICODE);
