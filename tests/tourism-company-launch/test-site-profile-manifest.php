<?php
/* Isolated, no-WordPress, no-credentials Site Profile intent contract. */
define('ABSPATH', __DIR__);
class WP_Error {
    private $code;
    public function __construct($code,$message){ $this->code=$code; }
    public function get_error_code(){ return $this->code; }
}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($x){return json_encode($x);}
require dirname(__DIR__,2).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-tourism-profile-manifest.php';
$input=array(
    'company_code'=>'wayfatours',
    'brand_name'=>'Wayfatours',
    'email'=>'contact@wayfatours.example',
    'phone_e164'=>'+201000000001',
    'domain'=>'wayfatours.mad4b.com',
    'logo_url'=>'',
    'colors'=>array('primary'=>'#173A4C','secondary'=>'#235E6D','accent'=>'#BB9253','text'=>'#173A4C'),
    'field_sources'=>array('logo_url'=>'neutral_demo_placeholder','colors'=>'demo_design_default'),
);
function check($yes,$label){if(!$yes){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(1);}}
$class='MAD4B_SCP_Tourism_Profile_Manifest';
$id='coolify-tenant-ab123456';
$m=$class::compose($input,$id);
check(is_array($m),'construct profile');
check($m['native_contract']==='mad4b.site-profile.v2','reuse native contract');
check($m['expected_origin']==='https://wayfatours.mad4b.com','origin company bound');
check($m['resource_uuid']===$id,'coolify resource bound');
check($m['presentation']['field_sources']['logo_url']==='neutral_demo_placeholder','demo logo provenance');
check($m['native_site_uuid']===null && $m['native_enrollment_verified']===false,'not a native enrolled profile');
check($m['write_authority_granted']===false && $m['public_live']===false,'no implicit production authority');
check($class::matches($m,$input,$id),'exact intended matching');
$foreign=$m; $foreign['expected_origin']='https://unrelated.mad4b.com';
check(!$class::matches($foreign,$input,$id),'cross company domain rejected');
check(!$class::matches($m,$input,'coolify-different'), 'cross resource rejected');
$changed=$input; $changed['email']='other@example.org';
check(!$class::matches($m,$changed,$id),'contact change invalidates intent');
$dirty=$m;$dirty['native_enrollment_verified']=true;
check(!$class::matches($dirty,$input,$id),'cannot claim verification in central intent');
$preview=$class::preview($m);
check($preview['native_enrollment_verified']===false && $preview['public_live']===false,'preview never implies launch');
check(is_wp_error($class::compose($input,'')),'missing resource denied');
echo "PASS: native Site Profile v2 intent, source identity, isolated Coolify resource, demo provenance, no implicit authority\n";
