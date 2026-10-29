<?php
// Standalone fixture for PHP CI. It creates no WordPress customer records.
$case=$argv[1]??'valid';
$allowed=['valid','source_drift','schema_drift','scope_write','wrong_host','wrong_callback','pin_mismatch','manifest_inside_content','pagination'];
if (!in_array($case,$allowed,true)) exit(3);
$root=sys_get_temp_dir().'/mad4b-ability-trust-test-'.getmypid();
$plugin=$root.'/content/mu-plugins';
$owner=$root.'/owner';
@mkdir($plugin,0700,true);@mkdir($owner,0700,true);
$source=$plugin.'/synthetic-read.php';
file_put_contents($source,'<?php final class Synthetic_Read_Ability { public static function execute($args=[]) { return ["status"=>"not_called"]; }}'."\n");
require $source;
define('ABSPATH',$root.'/wp/');
@mkdir(ABSPATH);
define('WP_CONTENT_DIR',$root.'/content');
class WP_Ability {
    private $execute_callback;
    private $meta;
    private $input_schema;
    public function __construct($callback, $meta, $schema) {
        $this->execute_callback=$callback;
        $this->meta=$meta;
        $this->input_schema=$schema;
    }
    public function get_meta(){return $this->meta;}
    public function get_input_schema(){return $this->input_schema;}
}
function wp_parse_url($v,$flag){return parse_url($v,$flag);}
function home_url($ignored='/'){return 'https://tenant.example.test/';}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
class WP_Error {
    public function __construct($code,$message){}
}
$schema=['type'=>'object','additionalProperties'=>false];
$meta=['annotations'=>['readonly'=>true,'destructive'=>false],
       'mcp'=>['public'=>false,'surface'=>'read'],'public'=>false,'show_in_rest'=>false];
$abilities=['sample/health'=>new WP_Ability(['Synthetic_Read_Ability','execute'],$meta,$schema)];
if ($case==='pagination')for ($i=0;$i<150;$i++)
    $abilities[sprintf('sample/item-%03d',$i)]=new WP_Ability(['Synthetic_Read_Ability','execute'],$meta,$schema);
function wp_get_abilities(){global $abilities;return $abilities;}
$manifest=[
  'contract'=>'mad4b.native-ability-trust.v1','site_host'=>'tenant.example.test',
  'abilities'=>[[
    'name'=>'sample/health','scope'=>'read',
    'source_relpath'=>'mu-plugins/synthetic-read.php',
    'source_sha256'=>hash_file('sha256',$source),
    'input_schema_sha256'=>hash('sha256',wp_json_encode($schema,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))
  ]]
];
switch ($case) {
 case 'source_drift': $manifest['abilities'][0]['source_sha256']=str_repeat('f',64);break;
 case 'schema_drift': $manifest['abilities'][0]['input_schema_sha256']=str_repeat('f',64);break;
 case 'scope_write': $manifest['abilities'][0]['scope']='write';break;
 case 'wrong_host': $manifest['site_host']='another.example.test';break;
 case 'wrong_callback': $manifest['abilities'][0]['source_relpath']='mu-plugins/unrelated.php';break;
}
$file=$case==='manifest_inside_content'?$plugin.'/self-approval.json':$owner.'/owner-approved.json';
file_put_contents($file,json_encode($manifest));
$hash=hash_file('sha256',$file);
if ($case==='pin_mismatch')$hash=str_repeat('0',64);
define('MAD4B_SCP_NATIVE_ABILITY_MANIFEST_PATH',$file);
define('MAD4B_SCP_NATIVE_ABILITY_MANIFEST_SHA256',$hash);
require dirname(__DIR__,2).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-native-ability-trust.php';
$input=['prefix'=>'sample/','limit'=>10,'offset'=>0];
$out=MAD4B_SCP_Native_Ability_Trust::catalog($input);
$expected=$case==='valid'||$case==='pagination';
$success=($out['approved_read_count']===($expected?1:0) &&
    $out['execution_authorized_by_catalog']===false &&
    $out['write_authority_created']===false &&
    ($out['items'][0]['read_source_eligible']??null)===$expected);
if ($case==='pagination') {
 $second=MAD4B_SCP_Native_Ability_Trust::catalog(['prefix'=>'sample/','limit'=>10,'offset'=>10]);
 $success=$success && $out['discovered_count']===151 && $out['page_count']===10 &&
    $out['next_offset']===10 && $second['offset']===10 &&
    $second['items'][0]['ability']!==$out['items'][0]['ability'];
}
echo $case.' '.($success?'PASS':'FAIL')." approved=".$out['approved_read_count']." state=".$out['manifest_state']."\n";
if (!$success)exit(2);
// Clean only our synthetic fixture.
foreach ([$file,$source] as $p)@unlink($p);
@rmdir($owner);@rmdir($plugin);@rmdir($root.'/content');@rmdir(ABSPATH);@rmdir($root);
