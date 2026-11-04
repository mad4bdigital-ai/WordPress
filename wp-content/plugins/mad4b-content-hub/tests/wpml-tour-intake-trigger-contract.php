<?php
declare(strict_types=1);
defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-mad4b-hub-trigger-bus.php';
$base=[
 'state'=>'NEW','job_revision'=>1,'content_type'=>'translation',
 'target_post_type'=>'tours-and-activities','language'=>'ar',
 'subject'=>'WPML Tour translation — post #60475 wpml-tour:60475:ar'
];
$cases=[
 ['ar-new',$base,true],
 ['fr-new',array_replace($base,['language'=>'fr','subject'=>'wpml-tour:60475:fr']),true],
 ['de-new',array_replace($base,['language'=>'de','subject'=>'wpml-tour:60475:de']),true],
 ['queued',array_replace($base,['state'=>'QUEUED']),false],
 ['rev2',array_replace($base,['job_revision'=>2]),false],
 ['article',array_replace($base,['content_type'=>'article']),false],
 ['not-a-tour',array_replace($base,['target_post_type'=>'post']),false],
 ['no-marker',array_replace($base,['subject'=>'ordinary translation']),false],
 ['language-mismatch',array_replace($base,['language'=>'fr']),false],
 ['non-translated-content',array_replace($base,['language'=>'en','content_type'=>'article']),false],
];
foreach($cases as [$name,$data,$expected]){
 $actual=MAD4B_Hub_Trigger_Bus::defer_unqueued_tour_translation($data);
 if($actual!==$expected){fwrite(STDERR,"FAIL $name\n");exit(1);}
 echo "PASS $name\n";
}
echo 'CONTENT_HUB_TRANSLATION_REVISION_CONTRACT='.count($cases)."/".count($cases)." PASS\n";
