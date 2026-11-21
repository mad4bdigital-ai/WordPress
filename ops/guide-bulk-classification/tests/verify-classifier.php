<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
$cls='MAD4B_Guide_Bulk_Classification_V1';
$cases=[
 ['full_day','high','Day 2: Full-day guided tour',
  'Meet your guide for the full-day guided tour of Giza.'],
 ['half_day','high','Day 3: Half-day guided tour',
  'A private half-day guided tour visits the Museum.'],
 ['optional','high','Day 4: Optional guide',
  'Optional guide available by request, not included.'],
 ['none','high','Day 5: No guide required',
  'This is a self-guided walk without a guide.'],
 ['unknown','conflict','Day 2: Conflicting tour',
  'No guide included. Full-day guided tour of the Pyramids.'],
 ['full_day','medium','Day 6: Museum',
  'Your personal guide will take you to the museum in Cairo.'],
 ['none','low','Day 1: Airport arrival',
  'Hotel check-in and private airport transfer.'],
 ['unknown','none','Day 3: Sightseeing',
  'Visit the Karnak Temple and Luxor Temple in the morning.']
];
foreach($cases as [$expected,$confidence,$title,$desc]){
 $x=$cls::classify(['key'=>'item-0','title'=>$title],['title'=>$title,'description'=>$desc]);
 if($x['suggested_status']!==$expected||$x['confidence']!==$confidence)
  WP_CLI::error('Classifier mismatched: '.$title.' expected='.$expected.'/'.$confidence.' got='.$x['suggested_status'].'/'.$x['confidence']);
 if($x['bulk_eligible']!==($confidence==='high'))
  WP_CLI::error('Bulk promotion confidence rule invalid: '.$title);
 WP_CLI::log('PASS '.str_replace('Day ','',$title).' -> '.$expected.'/'.$confidence);
}
WP_CLI::success('GUIDE_CLASSIFIER_REGRESSION_PASSED cases='.count($cases));
