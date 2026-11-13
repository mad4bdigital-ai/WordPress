<?php
/**
 * Execute in WP-CLI: wp eval-file tests/test_brand_integrity_runtime.php
 * No database writes, content changes, or external network requests.
 */
if (!defined('ABSPATH')) { fwrite(STDERR,"WordPress required\n"); exit(2); }
if (!class_exists('MAD4B_SCP_Brand_Integrity', false)) {
    $path = dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-brand-integrity.php';
    if (!is_file($path)) { fwrite(STDERR, "Brand Integrity source unavailable\n"); exit(2); }
    require_once $path;
}
$identity = array('normalized'=>array('wayfa tours'=>true,'wayfa'=>true,'wayfatours'=>true),
    'scope'=>array('brand_name'=>'Wayfa Tours'));
$tests = array(
    array('Choosing ORBITA means a better travel experience', 'ORBITA'),
    array('Choosing Wayfa Tours means a better travel experience', null),
    array('Choosing WayfaTours means a better travel experience', null),
    array('Why travelers choose All Royal Egypt.', 'All Royal Egypt'),
    array('Choose Tour Type to explore available journeys', null),
    array('Choose Your Way and select any tour', null),
    array('Accommodation at Hilton Luxor is included', null),
    array('احجز مع بالغين اثنين', null),
    array('We at Meridian Path promise exceptional trips', 'Meridian Path'),
    array('Elegir Luminara Travel significa reservar sin estrés', 'Luminara Travel'),
    array('Réservez avec Luminara Travel pour votre voyage', 'Luminara Travel'),
    array('Viaggia con Luminara Travel durante la vacanza', 'Luminara Travel'),
    array('Scegliere Luminara Travel significa viaggiare facilmente', 'Luminara Travel'),
    array('Scegliere Wayfa Tours significa una scelta adatta', null),
    array('With Luminara Travel, you get a better trip', 'Luminara Travel'),
    array('Con Luminara Travel, tienes una mejor experiencia', 'Luminara Travel'),
    array('Experience the islamic cairo tour with Cairo Citadel, Al-Rifa’i Mosque', null),
    array('Temples near the border with Sudan.', null),
    array('Tour Cairo di Notte con Feluca, pensato per chi visita', null),
    array("Merci d'avoir choisi Luminara Travel pour ce voyage", 'Luminara Travel'),
    array('Gracias por elegir Luminara Travel', 'Luminara Travel'),
    array('Grazie per aver scelto Luminara Travel', 'Luminara Travel'),
    array('Vielen Dank, dass Sie sich für Luminara Travel entschieden haben', 'Luminara Travel'),
    array('Buchen mit Luminara Travel für Ihren Urlaub', 'Luminara Travel'),
    array('لماذا تختار شركة القمر للسياحة', 'شركة القمر للسياحة'),
);
$fail = array();
foreach ($tests as $n=>$case) {
    $matches = MAD4B_SCP_Brand_Integrity::analyze_text($case[0],$identity);
    $actual = $matches[0]['candidate']??null;
    if ($actual !== $case[1]) $fail[] = array('case'=>$n+1,'wanted'=>$case[1],'actual'=>$actual);
}
$link_cases = array(
    array('<a href="https://other.test/tours">Our tour collection</a>','meta.tour_internal_linking_exploration_content.0','high'),
    array('<a href="//other.test/tours">Our tour collection</a>','meta.tour_internal_linking_exploration_content.0','high'),
    array('<a href="'.home_url('/tours'). '">Our tours</a>','meta.tour_internal_linking_exploration_content.0',null),
    array('<a href="/tours/">Our tours</a>','meta.tour_internal_linking_exploration_content.0',null),
    array('<a href="https://other.test/about">Hotel partner</a>','post_content','needs_review'),
);
foreach ($link_cases as $n=>$case) {
    $matched = MAD4B_SCP_Brand_Integrity::analyze_links($case[0],$case[1],array());
    $actual = $matched[0]['confidence']??null;
    if ($actual!==$case[2]) $fail[] = array('case'=>'link-'.($n+1),'wanted'=>$case[2],'actual'=>$actual);
}
$total=count($tests)+count($link_cases);
echo wp_json_encode(array('contract'=>'mad4b.brand-integrity.runtime-test.v1',
    'passed'=>$total-count($fail),'total'=>$total,
    'failures'=>$fail),JSON_UNESCAPED_UNICODE)."\n";
if ($fail) exit(1);
