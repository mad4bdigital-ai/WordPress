<?php
/** Verify read-only Studio binds to canonical Drift EvidenceProvider; never mutate on render. */
$source=file_get_contents(dirname(__DIR__).'/includes/Admin/InventoryControlPage.php');
if(!is_string($source)){throw new RuntimeException('Inventory Control source unavailable');}
foreach(array("'drift'=>'Drift Triage'","renderDriftTriage(\$snapshot)","'section'=>'drift_triage'","'source_category_counts'","'etg_drift_offset'","Confirm Profile scope","Review Profile Plans")as$needle){if(false===strpos($source,$needle)){throw new RuntimeException('Missing bounded studio surface: '.$needle);}}
$start=strpos($source,'private function renderDriftTriage(');
$end=strpos($source,'private function renderSafety():void',$start);
if(false===$start||false===$end||$start>=$end){throw new RuntimeException('Studio boundary not found');}
$render=substr($source,$start,$end-$start);
foreach(array('update_option(','delete_option(','wp_insert_post(','wp_set_object_terms(',"profile_mutation'=>true")as$prohibited){if(false!==strpos($render,$prohibited)){throw new RuntimeException('Studio rendering may not mutate: '.$prohibited);}}
echo "PASS Configuration Studio read-only bounded Drift Triage integration\n";
