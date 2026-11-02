<?php
/** Cross-environment activation must not be restricted to Local Lab. */
declare(strict_types=1);
$root=realpath(__DIR__.'/../mu-plugins');
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
$bad=[];$count=0;
foreach($iterator as $file){
 if(!$file->isFile()||strtolower($file->getExtension())!=='php')continue;
 ++$count;
 $src=file_get_contents($file->getPathname());
 if(str_contains($src,'SHARED_PRODUCT_LAB_ONLY') ||
   preg_match('/wp_get_environment_type\\(\\)\\s*(?:!==|!=|===|==)\\s*[\\x27"]local[\\x27]/',$src))
    $bad[]=str_replace($root.'/','',$file->getPathname());
}
$loader=(string)file_get_contents($root.'/shared-product-core-loader.php');
if(!str_contains($loader,'MAD4B_COMMERCE_RUNTIME_ENABLED'))$bad[]='loader-grant';
$generator=(string)file_get_contents($root.'/shared-product-core/pricing/rate-generation.php');
if(!str_contains($generator,"'post_status'=>'draft'")||
 !str_contains($generator,"'stop_sale'=>'1'"))$bad[]='draft-safety';
$admin=(string)file_get_contents($root.'/shared-product-core/admin/rate-generation.php');
if(!str_contains($admin,"current_user_can('manage_woocommerce')"))$bad[]='admin-capability';
echo 'CROSS_ENVIRONMENT_RUNTIME '.($bad?'FAIL '.implode(',',$bad):'PASS')." PHP_FILES=$count\n";
exit($bad?1:0);
