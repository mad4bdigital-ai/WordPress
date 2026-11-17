<?php
declare(strict_types=1);
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/../../wp-content/mu-plugins/mad4b-tour-viewpoint-coverage-v1.php';
$cases=[
 ['photo-of-facade-of-the-grand-egyptian-museum-in-giza-egypt','Angled entrance facade of Grand Egyptian Museum','museum_exterior_detail'],
 ['grand-egyptian-museum-heneghan-peng','Elevated view of the Grand Egyptian Museum and its landscaped grounds','museum_exterior_overview'],
 ['Facade-of-the-Egyptian-Museum-in-Cairo-and-the-Nile-River','Egyptian Museum building and gardens seen from above in central Cairo','museum_exterior_overview'],
 ['entrance-of-nmec-museum-cairo-egypt','Entrance plaza and geometric facade of the National Museum of Egyptian Civilization (NMEC) in Cairo','museum_exterior_overview'],
 ['GEM-facade-detail','Close-up of the entrance facade, cropped to the triangular stone wall','museum_exterior_detail'],
 ['GEM_Entrance@Grand20Museum','Entrada triangular del Gran Museo Egipcio','museum_exterior_overview'],
 ['photo-of-grand-egyptian-museum-statue-exhibit','Ancient Egyptian statues inside the Grand Egyptian Museum','museum_interior'],
 ['Grand Egyptian Museum Tour Portrait','Granite statues and a grand staircase inside the Grand Egyptian Museum','museum_interior'],
 ['pyramids-of-giza-in-desert-landscape','Panorama of the pyramids','pyramids'],
 ['sphinx-portrait','Sphinx and pyramids near Giza','sphinx']
];
foreach($cases as [$title,$alt,$expected]){
 $got=MAD4B_Tour_Viewpoint_Coverage_V1::scene_from_labels($title,$alt);
 if($got!==$expected)throw new RuntimeException("$title expected:$expected got:$got");
}
echo "PASS ".count($cases)." caption-only viewpoint cases\n";
