<?php
define('ABSPATH',__DIR__);
class WP_Error { private $c; public function __construct($c,$m=''){ $this->c=$c; } public function get_error_code(){return $this->c;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s));}
function sanitize_text_field($s){return trim((string)$s);}
function absint($v){return abs((int)$v);}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
function apply_filters($tag,$v){return $v;}
require dirname(__DIR__).'/includes/class-mad4b-scp-canonicalization.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-dynamic-ttl-policy.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-semantic-diff.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-dynamic-provider-contract.php';
$t=MAD4B_SCP_Dynamic_TTL_Policy::decide('mutation_lock',array('impact_level'=>'high','impact_flags'=>array('publication')));
if(is_wp_error($t)||$t['tier']!=='short'||strlen($t['policy_sha256'])!==64) exit(1);
$d=MAD4B_SCP_Semantic_Diff::compare(array('meta'=>array('secret'=>'a'),'post'=>array('title'=>'A')),array('meta'=>array('secret'=>'b'),'post'=>array('title'=>'B')));
if(empty($d['changed'])||empty($d['entries'][0])) exit(2);
$p=MAD4B_SCP_Dynamic_Provider_Contract::normalize(array('provider_id'=>'rankmath','existing_registry_identity'=>'rankmath','side_effects'=>array(array('effect_scope'=>'seo','ownership'=>'provider','reversibility'=>'reversible','compensation_support'=>'verified','externality'=>'local'))));
if(is_wp_error($p)||!empty($p['manifest_presence_authorizes'])) exit(3);
echo "mad4b.dynamic-hardening-pure-runtime.v1: PASS\n";
