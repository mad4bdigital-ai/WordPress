<?php
defined('ABSPATH') || exit;

$approved_site_ids=[
  'https://tours.dev.mad4b.com',
  'https://wayfatours.com',
];
$site=home_url();
if(!in_array($site,$approved_site_ids,true))throw new RuntimeException('unapproved_company_site');
if(!current_user_can('manage_options') || !current_user_can('manage_woocommerce'))
 throw new RuntimeException('authorized_owner_role_required');
$meta='_mad4b_abela_public_tariff_evidence_v1';
$source_urls=[
  'official_2026_display'=>'https://sleep.abelatrains.com/files/train_price_list_and_statins.pdf',
  'official_2026_current_link'=>'https://sleep.abelatrains.com/files/train_price_list_and_statins_new.pdf',
  'seat61_2026'=>'https://www.seat61.com/cairo-to-luxor-and-aswan-by-sleeper-train.htm',
  'this_is_egypt_oct_2026_abella_booking'=>'https://thisis-egypt.com/cs/guides/cairo-to-luxor-train-flight-bus',
  'al_masry_al_youm_may_2026'=>'https://www.almasryalyoum.com/news/details/4271561',
  'al_masry_al_youm_peak_may_2026'=>'https://www.almasryalyoum.com/news/details/4277605',
];
$matrix=[
 'foreign_usd_one_way_per_person'=>[
   'off_peak'=>[
      'standard'=>['shared_double'=>110.00,'single_sole_use'=>150.00],
      'elite'=>['shared_double'=>125.00,'single_sole_use'=>170.00]
   ],
   'peak'=>[
      'standard'=>['shared_double'=>125.00,'single_sole_use'=>165.00],
      'elite'=>['shared_double'=>135.00,'single_sole_use'=>185.00]
   ],
 ],
 'children_foreign'=>[
    'status'=>'conflicting_publications_no_cost_approval',
    'official_archived_2026_observation'=>[
       'off_peak_standard'=>105.0,
       'off_peak_elite'=>120.0,
       'peak_standard'=>125.0,
       'peak_elite'=>135.0
    ],
    'independent_rail_guide_claim'=>70.0,
    'age_years'=>[4,9],
    'child_approved_price'=>null,
    'infant_without_own_berth_reference'=>'free_under_4_per_seat61_not_booking_confirmation',
 ],
 'domestic_peak_egp_observations'=>[
    'standard'=>[
       'operator_display'=>['single'=>2175.00,'shared_double'=>1450.00],
       'published_may_price'=>['single'=>2283.75,'shared_double'=>1522.50],
       'bank_fee_5pct_mathematically_explains_difference'=>true,
       'bank_fee_confirmed_for_egyptian_payment'=>false
    ],
    'pricing_status'=>'reference_requires_current_route_date_payment_method_verification'
 ],
 'arab_peak_egp_observations'=>[
    'standard'=>[
      'operator_display'=>['single'=>3000.00,'shared_double'=>2000.00],
      'published_may_price'=>['single'=>3150.00,'shared_double'=>2100.00],
      'bank_fee_5pct_mathematically_explains_difference'=>true,
      'bank_fee_confirmed_for_arab_payment'=>false
    ],
    'pricing_status'=>'reference_requires_current_route_date_payment_method_verification'
 ],
];
$effective_calendar=[
 'foreign_2026_peak_windows_displayed'=>[
   ['from'=>'2026-02-20','to'=>'2026-02-28','label'=>'Sun Alignment 1'],
   ['from'=>'2026-10-20','to'=>'2026-10-31','label'=>'Sun Alignment 2'],
   ['from'=>'2026-12-10','to'=>'2026-12-31','label'=>'Christmas']
 ],
 'egyptian_arab_2026_peak_windows_displayed'=>[
   ['from'=>'2026-01-22','to'=>'2026-02-06','label'=>'Midyear'],
   ['from'=>'2026-03-12','to'=>'2026-03-30','label'=>'Eid Fitr'],
   ['from'=>'2026-05-19','to'=>'2026-06-07','label'=>'Eid Adha'],
   ['from'=>'2026-07-01','to'=>'2026-08-31','label'=>'Summer'],
   ['from'=>'2026-12-10','to'=>'2026-12-31','label'=>'Christmas']
 ],
 '2027_calendar_supported'=>false
];
$report=[];
foreach([54552=>'86',54553=>'87'] as $id=>$train){
 $post=get_post($id);
 if(!$post || $post->post_type!=='transportations' ||
     $post->post_status!=='publish' ||
     (string)get_post_meta($id,'trans_train_number',true)!==$train)
   throw new RuntimeException('train_identity_not_verified:'.$id);
 $existing=get_post_meta($id,$meta,true);
 if($existing!=='')throw new RuntimeException('existing_tariff_evidence_requires_merging:'.$id);
 $before=[
  'start'=>get_post_meta($id,'trans_start_price',true),
  'currency'=>get_post_meta($id,'trans_base_currency',true),
  'unit'=>get_post_meta($id,'trans_unit_type',true),
  'role'=>get_post_meta($id,'_mad4b_transport_start_price_usage_v1',true),
  'tour_rate_optin'=>get_post_meta($id,'trans_use_in_tour_rate_generation',true)
 ];
 if($before['start']!=='' || $before['role']!==''||
    $before['tour_rate_optin']!=='0' || $before['unit']!=='per_person' )
    throw new RuntimeException('rail_commercial_state_changed_no_write:'.$id);
 $record=[
  'contract'=>'mad4b.abela.public-tariff-evidence.v1',
  'operator'=>'Abela Trains Services Egypt',
  'operator_national_tariff'=>true,
  'source_type'=>'published_fare_reference_not_trade_supplier_contract',
  'tour_id'=>60623,'transportation_id'=>$id,'train_number'=>$train,
  'route'=>$train==='86'?'cairo_upper_egypt_to_aswan':'luxor_to_cairo_upper_egypt',
  'travel_nights'=>1,
  'source_date_checked_utc'=>'2026-11-22',
  'applicable_year'=>2026,
  'fare_basis'=>'per_person_per_one_way_journey',
  'money'=>$matrix,
  'season_windows'=>$effective_calendar,
  'sources'=>$source_urls,
  'corroboration'=>[
    'foreign_adult_standard_elite_shared_single'=>'official_2026_display_and_2026_rail_expert_and_oct_booking_sample',
    'foreign_peak_elite'=>'official_peak_table_plus_2026-10_booking_observation_for_2026-12-28',
    'foreign_peak_standard'=>'official_peak_table_plus_2026-05_news',
    'egyptian_arab_peak_prices'=>'operator_table_and_May_2026_news_after_5pct_arithmetic_not_payment_policy_proof',
    'child_tariff'=>'conflicted_public_sources_deny_auto_price',
  ],
  'approved_for_internal_structured_public_tariff_reference'=>true,
  'approved_for_current_date_booking_quote'=>false,
  'eligible_to_fill_generic_trans_start_price'=>false,
  'eligible_to_activate_cost_role'=>false,
  'eligible_to_generate_tour_rate'=>false,
  'date_inventory_verified'=>false,
  'supplier_trade_cost_approved'=>false,
  'commercial_checkout_authorized'=>false,
  'validation_notes'=>[
   'Official archived PDF displayed in search snapshot but current old link returns 404; current homepage links a later October-2026 PDF, content not independently parsed from that current PDF.',
   'Separate nationality-specific prices cannot safely be flattened to a generic transportation from-price for all markets.',
   'Child rates differ between Abela 2026 displayed table and expert traveller website; do not auto-calculate from either until conditions confirmed.',
   'May 2026 Egyptian and Arab newspaper prices align mathematically with 5 percent above peak list, not proof a bank fee applies to all domestic payment methods.',
   'Fare is not date-specific capacity, actual booking, supplier private contract or revenue price.'
  ],
 ];
 update_post_meta($id,$meta,$record);
 $read=get_post_meta($id,$meta,true);
 if($read!==$record)
    throw new RuntimeException('tariff_evidence_readback_failed:'.$id);
 $after=[
  'start'=>get_post_meta($id,'trans_start_price',true),
  'currency'=>get_post_meta($id,'trans_base_currency',true),
  'unit'=>get_post_meta($id,'trans_unit_type',true),
  'role'=>get_post_meta($id,'_mad4b_transport_start_price_usage_v1',true),
  'tour_rate_optin'=>get_post_meta($id,'trans_use_in_tour_rate_generation',true)
 ];
 if($after!==$before)
    throw new RuntimeException('native_price_fields_changed_unexpectedly:'.$id);
 $report[]=[
  'id'=>$id,'train'=>$train,
  'foreign_adult_tariff_season_categories'=>count($matrix['foreign_usd_one_way_per_person']),
  'source_count'=>count($source_urls),'evidence_status'=>'recorded_nonbookable',
  'children_pricing_blocked'=>true,'native_price_fields_untouched'=>true,
  'postmeta_contract'=>$read['contract'],
  'commercial_checkout_authorized'=>false
 ];
}
echo wp_json_encode(['site'=>$site,'rows'=>$report,'total'=>count($report),
  'written_native_transport_rates'=>0,'changed_generic_start_price'=>false,
  'approved_supplier_cost'=>false,'checkout_authorized'=>false],
  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

