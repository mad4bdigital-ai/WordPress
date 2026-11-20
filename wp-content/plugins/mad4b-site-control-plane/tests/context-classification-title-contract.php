<?php
/** Hermetic regression: source title classification must dominate incidental body references. */
define('ABSPATH',__DIR__.'/');
require dirname(__DIR__).'/includes/class-mad4b-scp-context-authority.php';
$cases=array(
    array('Sample Travel — Editorial Guidelines — Review Draft v1.0','editorial_guidelines',"BRAND STRATEGY\nBrand Core\nTone of Voice\nSEO", 'editorial_title_wins'),
    array('Sample Travel — Brand Strategy — Review Draft v1.0','brand_strategy',"Editorial Guidelines\nTone of Voice\nContent Policy", 'strategy_title_wins'),
    array('Sample Travel — Tone of Voice — Review','tone_of_voice',"Brand Strategy\nBrand Core\nEditorial Guidelines", 'voice_title_wins'),
    array('Source Hub MCP connector registry','uncategorized',"Brand Strategy and Brand Core diagnostics", 'operational_reference_not_brand'),
    array('Company transport field registry','uncategorized',"Our Brand Strategy and Tone of Voice are linked here", 'body_only_not_brand'),
    array('Room photography inventory','uncategorized',"This report cites Editorial Guidelines", 'body_reference_not_editorial'),
    array('Service catalog connector','uncategorized',"The Brand Core file is in the Brand Core folder", 'connector_not_brand'),
    array('Official Brand Core Guide','brand_strategy',"Editorial Guidelines are a separate document", 'explicit_brand_core_title'),
    array('Customer brand identity images','uncategorized',"The folder is named Brand Strategy", 'generic_brand_identity_not_strategy'),
);
foreach($cases as $test) {
    $actual=MAD4B_SCP_Context_Authority::classify_asset($test[0],'00 Brand Core/SEO Strategy/'.$test[0].'.txt',$test[2]);
    if(($actual['category']??'')!==$test[1]) {
        fwrite(STDERR,'FAIL '.$test[3].' expected='.$test[1].' got='.($actual['category']??'none')."\n");
        exit(1);
    }
    echo $test[3].": PASS\n";
}
echo "mad4b.context-classification-title-contract.v1: PASS\n";

// Test the actual private classifier drift helper under hermetic reflection;
// this is the same helper the approval path calls before any mutation.
$check = new ReflectionMethod(MAD4B_SCP_Context_Authority::class,'automatic_classification_drift');
if (PHP_VERSION_ID < 80100) $check->setAccessible(true);
$stale = array('title'=>'Company transport field registry',
    'path'=>'00 Brand Core/Brand Strategy',
    'category'=>'brand_strategy','authority_class'=>'brand_authority',
    'required'=>true,'classification_source'=>'automatic_heuristic');
if (true !== $check->invoke(null,$stale)) exit(1);
$correct = array('title'=>'Official Brand Core Guide',
    'path'=>'00 Brand Core', 'category'=>'brand_strategy',
    'authority_class'=>'brand_authority','required'=>true);
if (false !== $check->invoke(null,$correct)) exit(1);
$runtime_source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-context-authority.php');
if (false===strpos($runtime_source, "\$reasons[] = 'automatic_classification_stale_requires_reindex';")) exit(1);
echo "context_auto_reindex_drift_guard: PASS\n";
