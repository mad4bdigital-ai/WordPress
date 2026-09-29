<?php
define('ABSPATH', __DIR__);
class WP_Error { private $c; public function __construct($c,$m=''){ $this->c=$c; } public function get_error_code(){return $this->c;} }
function is_wp_error($v){ return $v instanceof WP_Error; }
function wp_json_encode($v,$f=0){ return json_encode($v,$f); }
require dirname(__DIR__) . '/includes/class-mad4b-scp-canonicalization.php';
$a=MAD4B_SCP_Canonicalization::canonical_json(array('z'=>1,'a'=>array('b'=>2,'a'=>1)));
$b=MAD4B_SCP_Canonicalization::canonical_json(array('a'=>array('a'=>1,'b'=>2),'z'=>1));
if($a!==$b) { fwrite(STDERR,"canonical object order mismatch\n"); exit(1); }
$d1=MAD4B_SCP_Canonicalization::digest('dynamic-operation-binding:v1',array('x'=>1));
$d2=MAD4B_SCP_Canonicalization::digest('dynamic-operation-event:v1',array('x'=>1));
if(!is_string($d1)||strlen($d1)!==64||$d1===$d2){ fwrite(STDERR,"domain separation failed\n"); exit(1); }
if(!is_wp_error(MAD4B_SCP_Canonicalization::canonical_json(array('x'=>1.2)))){ fwrite(STDERR,"float must fail closed\n"); exit(1); }
echo "mad4b.dynamic-canonicalization.runtime.v1: PASS\n";
