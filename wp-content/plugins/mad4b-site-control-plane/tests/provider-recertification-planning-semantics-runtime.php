<?php
/* Core wrapper permission identity must not be confused with nested target provider. */
define('ABSPATH',__DIR__.'/');
define('MAD4B_SCP_DIR',dirname(__DIR__).'/');
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{
 private $code;private $data;
 public function __construct($code,$message='',$data=[]){$this->code=$code;$this->data=$data;}
 public function get_error_code(){return $this->code;}
 public function get_error_data(){return $this->data;}
}
class MAD4B_SCP_Servers{
 public static function provider_for_ability($server,$ability){
  if ($server!=='mad4b-write') return null;
  $mounts=[
    'mad4b/provider-behavioral-recertify'=>'core',
    'elementor/update-widget-settings'=>'elementor',
    'jetsmartfilters/update-filter-meta'=>'jetsmartfilters',
    'jetengine/update-post-meta'=>'jetengine',
    'woocommerce/update-product'=>'woocommerce',
    'seo/update-meta'=>'seo',
  ];
  return $mounts[$ability]??null;
 }
}
class MAD4B_SCP_Provider_Compatibility_Certification {
 public static function ability_status($provider,$ability) {
  $catalog=[
   'elementor/update-widget-settings'=>'elementor',
   'jetsmartfilters/update-filter-meta'=>'jetsmartfilters',
   'jetengine/update-post-meta'=>'jetengine',
   'woocommerce/update-product'=>'woocommerce',
   'seo/update-meta'=>'seo'
  ];
  if(!isset($catalog[$ability]) || $catalog[$ability]!==$provider) return [];
  return ['provider'=>$provider,'risk'=>'bounded_write','reversible'=>true,
   'structural_compatible'=>true,'write_eligible'=>false,
   'activation_stage'=>'shadow'];
 }
}
function ck($v,$why){if(!$v){fwrite(STDERR,'FAIL '.$why."\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-semantic-content-field-contracts.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-staging-write-planning-guard.php';
$a='MAD4B_SCP_Semantic_Content_Field_Contracts';
$g='MAD4B_SCP_Staging_Write_Planning_Guard';
$base=['ability'=>'mad4b/provider-behavioral-recertify',
 'input'=>['provider_id'=>'elementor','target_ability'=>'elementor/update-widget-settings',
 'target_input'=>['post_id'=>103,'widget_id'=>'abc','settings'=>['background_color'=>'#123456']]]];
$ok=$g::canonicalize_remote_plan_input($base);
ck(is_array($ok)&&$ok['provider']==='core'&&$ok['input']['provider_id']==='elementor',
 'core outer identity automatically assigned; inner provider preserved');
$bad=$base;$bad['provider']='elementor';
$x=$g::canonicalize_remote_plan_input($bad);
ck(is_wp_error($x) && $x->get_error_code()==='mad4b_remote_plan_provider_mismatch'
 && $x->get_error_data()['expected_provider']==='core',
 'misplaced outer provider rejected with exact safe guidance');
$x=$a::classify('mad4b/provider-behavioral-recertify',$base['input']);
ck($x['field_contract_known']===true && !$x['required'] && !$x['review_required']
 && $x['mutation_classification_ready']===true,'known operational Elementor settings');
$description=$base['input'];
$description['target_input']['settings']=['title'=>'Museum tour description'];
$x=$a::classify('mad4b/provider-behavioral-recertify',$description);
ck($x['required']===true && in_array('target_input.settings.title',$x['matched_fields'],true),
 'Elementor content text remains classified as brand-bearing');
$j=['provider_id'=>'jetsmartfilters','target_ability'=>'jetsmartfilters/update-filter-meta',
 'target_input'=>['filter_id'=>123,'meta_key'=>'_query_var','value'=>'safe_query_22',
 'expected_sha256'=>str_repeat('a',64)]];
$x=$a::classify('mad4b/provider-behavioral-recertify',$j);
ck($x['field_contract_known']===true && !$x['required'] && !$x['review_required'],
 'exact bounded JetSmartFilters _query_var recognized as operational only');
$j['target_input']['meta_key']='seo_title';
$x=$a::classify('mad4b/provider-behavioral-recertify',$j);
ck($x['review_required']===true,'unapproved title-like meta retains review requirement');
$j['provider_id']='elementor';
$x=$a::classify('mad4b/provider-behavioral-recertify',$j);
ck($x['review_required']===true && !$x['mutation_classification_ready'],
 'mismatched claimed nested provider fails closed');
$x=$a::classify('mad4b/provider-behavioral-recertify',['provider_id'=>'core','target_ability'=>'mad4b/provider-behavioral-recertify','target_input'=>[]]);
ck($x['review_required']===true && !$x['mutation_classification_ready'],
 'recursive wrapper cannot bypass semantic classification');
$multi=[
  ['provider_id'=>'jetengine','target_ability'=>'jetengine/update-post-meta',
    'target_input'=>['post_id'=>2,'meta_key'=>'public_label','value'=>'Example']],
  ['provider_id'=>'woocommerce','target_ability'=>'woocommerce/update-product',
    'target_input'=>['product_id'=>123,'name'=>'Sample product']],
  ['provider_id'=>'seo','target_ability'=>'seo/update-meta',
    'target_input'=>['post_id'=>99,'meta_key'=>'title','value'=>'Draft title']],
];
foreach($multi as $case){
 $x=$a::classify('mad4b/provider-behavioral-recertify',$case);
 ck($x['field_contract_known']===true && $x['provider_id']==='core',
    'mounted provider contract covers other plugins: '.$case['provider_id']);
}
$ineligible=['provider_id'=>'unknown','target_ability'=>'seo/update-meta',
 'target_input'=>['post_id'=>9,'meta_key'=>'title','value'=>'test']];
$x=$a::classify('mad4b/provider-behavioral-recertify',$ineligible);
ck(!$x['field_contract_known'] && $x['review_required'],
 'not catalogued provider fails even if a semantic rule exists');
$extra=['provider_id'=>'seo','target_ability'=>'unmounted/undefined',
 'target_input'=>['post_id'=>5,'value'=>'unknown']];
$x=$a::classify('mad4b/provider-behavioral-recertify',$extra);
ck(!$x['field_contract_known'] && $x['review_required'] && !$x['mutation_classification_ready'],
    'uncatalogued ability is never trusted by a semantic contract');

// Assert planning and execution use one server-resolved target identity.
// Only the one-time ticket envelope is excluded; the mutation input is exact.
function apply_filters($name,$value,...$args){return $value;}
require dirname(__DIR__).'/includes/class-mad4b-scp-staging-write-authority.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-resource-constraint-set.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-authorization-target-fingerprint.php';
$fp='MAD4B_SCP_Authorization_Target_Fingerprint';
$authority='MAD4B_SCP_Staging_Write_Authority';
$ability='mad4b/provider-behavioral-recertify';
$agent=['id'=>7,'public_id'=>'aaaabbbb-cccc-dddd-eeee-111122223333'];
$planned=$g::canonicalize_remote_plan_input($base);
$plan_input=$authority::authorization_input($planned['input'],$ability);
$plan_target=$fp::fingerprint($ability,'core',$plan_input,$agent,['planning'=>true]);
ck(is_string($plan_target) && preg_match('/^[a-f0-9]{64}$/D',$plan_target),
 'planning uses a server-resolved SHA256 target');
$execution_input=$planned['input'];
$execution_input['_mad4b_approval_ticket_id']='11111111-2222-4333-8444-555555555555';
$execution_target=$fp::fingerprint($ability,'core',
 $authority::authorization_input($execution_input,$ability),$agent,['mutation'=>true]);
ck(hash_equals($plan_target,$execution_target),
 'exact same mutation target remains identical after ticket envelope stripping');
$swapped=$planned['input'];
$swapped['target_input']['settings']['background_color']='#ABCDEF';
$other_target=$fp::fingerprint($ability,'core',
 $authority::authorization_input($swapped,$ability),$agent,['mutation'=>true]);
ck($other_target!==$plan_target,
 'changed target input cannot reuse an approved fingerprint');
$other_provider=$fp::fingerprint($ability,'elementor',$plan_input,$agent,['mutation'=>true]);
ck($other_provider!==$plan_target,'core wrapper and plugin provider are distinct identities');
$reordered=$planned['input'];
$reordered=array_reverse($reordered,true);
$reordered_target=$fp::fingerprint($ability,'core',
 $authority::authorization_input($reordered,$ability),$agent,['mutation'=>true]);
ck(hash_equals($plan_target,$reordered_target),
 'equivalent associative field ordering does not cause spurious target drift');
echo "PROVIDER_RECERTIFICATION_PLANNING_SEMANTICS_PASS\n";
