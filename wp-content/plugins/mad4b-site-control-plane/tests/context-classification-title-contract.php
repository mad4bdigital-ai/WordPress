<?php
/** Hermetic regression: source title classification must dominate incidental body references. */
define('ABSPATH',__DIR__.'/');
require dirname(__DIR__).'/includes/class-mad4b-scp-context-authority.php';
$cases=array(
    array('Sample Travel — Editorial Guidelines — Review Draft v1.0','editorial_guidelines',"BRAND STRATEGY\nBrand Core\nTone of Voice\nSEO", 'editorial_title_wins'),
    array('Sample Travel — Brand Strategy — Review Draft v1.0','brand_strategy',"Editorial Guidelines\nTone of Voice\nContent Policy", 'strategy_title_wins'),
    array('Sample Travel — Tone of Voice — Review','tone_of_voice',"Brand Strategy\nBrand Core\nEditorial Guidelines", 'voice_title_wins'),
    array('Source Hub MCP connector registry','uncategorized',"Brand Strategy and Brand Core diagnostics", 'operational_reference_not_brand'),
);
foreach($cases as $test) {
    $actual=MAD4B_SCP_Context_Authority::classify_asset($test[0],$test[0].'.txt',$test[2]);
    if(($actual['category']??'')!==$test[1]) {
        fwrite(STDERR,'FAIL '.$test[3].' expected='.$test[1].' got='.($actual['category']??'none')."\n");
        exit(1);
    }
    echo $test[3].": PASS\n";
}
echo "mad4b.context-classification-title-contract.v1: PASS\n";
