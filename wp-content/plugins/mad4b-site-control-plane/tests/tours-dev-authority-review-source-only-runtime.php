<?php
/** Byte-identical source preservation without authority registration. */
error_reporting(E_ALL);
function check_seal($ok,$name){if(!$ok){fwrite(STDERR,'FAIL: '.$name.PHP_EOL);exit(1);}}
$plugin=dirname(__DIR__);
$bootstrap=file_get_contents($plugin.'/mad4b-site-control-plane.php');
check_seal(is_string($bootstrap),'Candidate plugin Bootstrap required');
$sealed=array(
    'includes/class-mad4b-scp-authority-auto-reconciliation.php'=>'1465a1e518031aa07e9770dcfa0976eb1492677dc4eafdbb9ab0efb2450cc238',
    'includes/class-mad4b-scp-authority-reviews-bridge.php'=>'75c866b308536f07b752947ae8ca65994a90e7835aeb5432ecb8b2eb7098d6bf',
    'includes/class-mad4b-scp-company-reconcile-skill-set.php'=>'40a21583f5f03fad46658de78b6c9dad2eca4aa8ce77b01d49f8903c6af7fb66',
    'includes/class-mad4b-scp-core-connector-vault.php'=>'166015109319ec3a88d130b295d9272101c3858d57bd49f7680f533464dbfc2b',
    'includes/class-mad4b-scp-native-host-source-release-review.php'=>'e6d1900343d9ffd5347d7d51e916ccb918513f835136da97b7547ff6f312378a',
    'includes/class-mad4b-scp-original305-incident-review.php'=>'142c278c4b952f334171d1bbbb81d9e655f5189bc7d87948065b7eda9d7adb6f',
);

foreach($sealed as $rel=>$sha){
 $path=$plugin.'/'.$rel;
 check_seal(is_file($path)&&hash_equals($sha,hash_file('sha256',$path)),
    'Frozen original source changed: '.$rel);
 check_seal(false===strpos($bootstrap,basename($rel)),
    'New authority source unexpectedly loaded by plugin Bootstrap: '.$rel);
}
// Exact Git-only whitespace normalization: one final blank line removed
// from the independently sealed installed file, with no semantic differences.
$normalized=file_get_contents($plugin.'/includes/class-mad4b-scp-company-reconcile-skill-set.php');
check_seal(is_string($normalized) && hash('sha256',$normalized."\n")===
 '75586bbcd4d89d160b079be43a4f613a7d26fa788d9b936003867a3bf12b6464',
 'Reviewed successor differs only by one terminal newline');
// Static candidate inventory: none is registered by the candidate Bootstrap.
$static_candidates=array(
    'ability:mad4b/content-job-reconcile-skill-acceptance'=>'includes/class-mad4b-scp-company-reconcile-skill-set.php',
    'ability:mad4b/content-job-reconcile-skill-plan'=>'includes/class-mad4b-scp-company-reconcile-skill-set.php',
    'ability:mad4b/host-source-release'=>'includes/class-mad4b-scp-native-host-source-release-review.php',
    'ability:mad4b/original305-incident-review'=>'includes/class-mad4b-scp-original305-incident-review.php',
    'ajax:wp_ajax_mad4b_original305_readiness_status'=>'includes/class-mad4b-scp-original305-incident-review.php',
    'cron:hourly'=>'includes/class-mad4b-scp-authority-auto-reconciliation.php',
);
foreach($static_candidates as $candidate=>$rel){
 $file=$plugin.'/'.$rel;
 check_seal(is_file($file),'Missing sealed source for candidate: '.$candidate);
 $source=file_get_contents($file);
 $raw=substr($candidate,strpos($candidate,':')+1);
 check_seal(is_string($source)&&false!==strpos($source,$raw),
     'Candidate identifier must exist in exact sealed source: '.$candidate);
 check_seal(false===strpos($bootstrap,basename($rel)),
     'Static source may not be activated through main bootstrap: '.$candidate);
}
check_seal(count($static_candidates)===6,'Six actual newly discovered capability or route surfaces');
// Two existing intent references are not registered Abilities and shall not
// be reclassified as new grants or registered capabilities.
$authority_source=file_get_contents($plugin.'/includes/class-mad4b-scp-authority-auto-reconciliation.php');
foreach(array('mad4b/host-operator-bind-intent','mad4b/retire-obsolete-grant-intent') as $intent){
 check_seal(false!==strpos($authority_source,$intent),'Existing intended authority reference preserved: '.$intent);
}

check_seal(count($sealed)===6,'Exactly six original authority sources');
$one=file_get_contents($plugin.'/includes/class-mad4b-scp-authority-auto-reconciliation.php');
$two=file_get_contents($plugin.'/includes/class-mad4b-scp-authority-reviews-bridge.php');
check_seal(false!==strpos($one,'MAD4B_SCP_Authority_Auto_Reconciliation::boot();'),
 'Original authority auto-boot is a known hazard and cannot be silently dropped');
check_seal(false!==strpos($two,'MAD4B_SCP_Authority_Reviews_Bridge::boot();'),
 'Original authority bridge auto-boot is a known hazard and cannot be silently dropped');
// Repeated cron schedules are merely literal strings in dormant source
// until independently admitted through the installed main Bootstrap.
$scheduled=file_get_contents($plugin.'/includes/class-mad4b-scp-frontend-impact-priority.php');
check_seal(is_string($scheduled) && false!==strpos($scheduled,"'hourly'"),
 'Original hourly scheduler label retained in dormant source');
check_seal(false===strpos($bootstrap,'class-mad4b-scp-frontend-impact-priority.php'),
 'No hourly site scan can start from candidate Bootstrap');
echo "PASS: 6 original source modules, 2 inline boot hazards isolated, 0 newly loaded".PHP_EOL;
