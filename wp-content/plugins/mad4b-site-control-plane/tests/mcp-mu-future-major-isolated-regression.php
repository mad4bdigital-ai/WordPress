<?php
/**
 * PERMANENT UPDATE REGRESSION: early MU dynamic selection is semantic.
 * No WordPress bootstrap/database/network, no host options, no plugin update.
 * Isolated fixture substitutes 0.8.x, 1.x and 2.x header labels while testing
 * real current MCP plugin files. This is not proof of any unreleased 0.8 ABI.
 */
declare(strict_types=1);
function wp_normalize_path(string $p):string{return str_replace('\\','/',$p);}
$root=dirname(__DIR__);
$source=$root.'/bootstrap/mad4b-mcp-adapter-mu-bootstrap.php';
$content=file_get_contents($source);
$start=strpos($content,'/**'."\n".' * PERMANENT FORWARD-UPDATE BEHAVIOR');
$end=strpos($content,"\n\$mad4b_mcp_mu_critical_hashes =", $start);
if($start===false||$end===false||$end<=$start)throw new RuntimeException('dynamic block markers missing');
$block=substr($content,$start,$end-$start);
$profiles=json_decode(file_get_contents($root.'/config/certified-provider-profiles.json'),true);
$plugin=dirname($root).'/mcp-adapter/';
$base=$profiles['providers']['mcp_adapter'];
$run=function(string $version,array $catalog)use($block,$plugin):array{
    $mad4b_mcp_mu_adapter_version=$version;
    $mad4b_mcp_mu_profiles_catalog=$catalog;
    $mad4b_mcp_mu_selected_profile=[];
    $mad4b_mcp_mu_status=[];
    $mad4b_mcp_mu_root=$plugin;
    eval($block);
    return [
       'version'=>$version,
       'dynamic'=>(bool)($mad4b_mcp_mu_status['adapter_profile_dynamic']??false),
       'reference'=>$mad4b_mcp_mu_status['dynamic_reference_version']??null,
       'symbol_count'=>count($mad4b_mcp_mu_selected_profile['runtime_symbols']??[]),
       'critical_count'=>count($mad4b_mcp_mu_selected_profile['critical_files']??[]),
       'profile_source'=>$mad4b_mcp_mu_status['adapter_profile_source']??null,
       'runtime_readback_required'=>$mad4b_mcp_mu_status['dynamic_requires_runtime_class_readback']??false,
    ];
};
$assert=static function(bool $x,string $msg){if(!$x)throw new RuntimeException('REGRESSION_FAILED:'.$msg);};
$results=[];
foreach(['0.7.2','0.8.0','0.8.99','0.8.10000','1.0.0','2.15.99','11.0.0'] as $v){
    $r=$run($v,$profiles);
    $assert($r['dynamic'] && $r['reference']==='0.7.1','forward_'.$v);
    $assert($r['symbol_count']>=54 && $r['critical_count']>=39 && $r['runtime_readback_required'],'layout_'.$v);
    $results[]=$r;
}
foreach(['0.6.0','0.7.0','0.8','0.8.0/evil',str_repeat('9',81)] as $v){
    $r=$run($v,$profiles);
    $assert(!$r['dynamic'],'reject_'.$v);
    $results[]=['version'=>strlen($v)>25?'overlong':$v,'dynamic'=>$r['dynamic']];
}
$bad=$profiles;
$bad['providers']['mcp_adapter']['0.7.1']['runtime_symbols'][0]['file']='includes/Core/DOES_NOT_EXIST.php';
$r=$run('0.8.0',$bad);
$assert(!$r['dynamic'],'missing_abi_file_fails');
$results[]=['version'=>'0.8.0 with missing required ABI file','dynamic'=>$r['dynamic']];
echo json_encode(['contract'=>'mad4b.mcp-mu-future-major-isolated-regression.v1',
    'result'=>'PASS','source_executed'=>$source,'fixture_layout'=>'installed 0.7.1 (not unreleased 0.8)',
    'cases'=>$results,'production_mutation'=>false,'live_mu_mutation'=>false],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
