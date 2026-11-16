<?php
/** Actual Adapter Registry + Native Ability Trust: discovery is never authority. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 public function __construct($c,$m=''){ $this->code=$c; }
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$s));}
function add_action($a,$b,$c=10){return true;}
function add_filter($a,$b,$c=10,$d=1){return true;}
function wp_register_ability($name,$schema){$GLOBALS['native_abilities'][$name]=$schema;return true;}
function wp_has_ability($name){return isset($GLOBALS['native_abilities'][$name]);}
function wp_get_abilities(){return $GLOBALS['native_wp_abilities'];}
final class NativeProbeAbility {
 private $meta;
 public function __construct($read=true){
  $this->meta=array('annotations'=>array('readonly'=>$read,'destructive'=>false),
   'mcp'=>array('public'=>false,'surface'=>$read?'read':'write'),
   'public'=>false,'show_in_rest'=>false);
 }
 public function get_meta(){return $this->meta;}
}
final class MAD4B_SCP_Policy {
 public static function can_read(){return false;}
}
function native_assert($condition,$msg){if(!$condition){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$base=dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes';
require $base.'/adapters/class-mad4b-scp-adapter-base.php';
require_once $base.'/class-mad4b-scp-native-ability-trust.php';
require $base.'/class-mad4b-scp-adapter-registry.php';
$GLOBALS['native_wp_abilities']=array(
  'mad4b/native-read-a'=>new NativeProbeAbility(true),
  'mad4b/native-write-b'=>new NativeProbeAbility(false),
);
$registry=MAD4B_SCP_Adapter_Registry::instance();
$method=new ReflectionMethod($registry,'register_registry_ability');
if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$method->invoke($registry,'mad4b/native-capability-registry','Owner-Verified Native Ability Registry',
    'native_capability_registry','Owner-pinned read only catalog',
    array('type'=>'object','additionalProperties'=>false,
        'properties'=>array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>96))));
$desc=$GLOBALS['native_abilities']['mad4b/native-capability-registry']??array();
native_assert($desc['execute_callback']===array($registry,'native_capability_registry'),
  'Adapter Registry exact callback is the original Native Ability Trust facade');
native_assert($desc['permission_callback']===array('MAD4B_SCP_Policy','can_read')
  && $desc['meta']['mcp']['surface']==='read'
  && $desc['meta']['annotations']['readonly']===true
  && $desc['meta']['annotations']['destructive']===false
  && $desc['meta']['mcp']['public']===false,
  'The real registered native ability cannot create Write or grant permission');
native_assert(MAD4B_SCP_Policy::can_read()===false,
 'Discovery registration does not make an unpermitted caller a WordPress Admin');
$catalog=$registry->native_capability_registry(array('limit'=>1,'offset'=>0));
native_assert(is_array($catalog)&&$catalog['manifest_state']==='OWNER_PIN_NOT_CONFIGURED'
    && $catalog['discovery_only']===true
    && $catalog['execution_authorized_by_catalog']===false
    && $catalog['write_authority_created']===false
    && $catalog['mutation_performed']===false
    && $catalog['discovered_count']===2
    && count($catalog['items'])===1
    && $catalog['next_offset']===1,
 'The unconfigured owner manifest cannot turn runtime discovery into native approval');
$second=$registry->native_capability_registry(array('limit'=>1,'offset'=>1));
native_assert(count($second['items'])===1 && $second['next_offset']===null,
 'Dynamic page retrieval reports exactly the second unresolved native ability');
foreach(array(array('limit'=>-1),array('limit'=>200),
    array('offset'=>10001),array('limit'=>1,'unauthorized'=>true)) as $bad){
 native_assert(is_wp_error($registry->native_capability_registry($bad)),
  'Unreviewed page parameter must never increase the native registry surface');
}
foreach($catalog['items'] as $item){
 native_assert($item['read_source_eligible']===false
    && $item['execution_authorized_by_catalog']===false
    && $item['write_authorized']===false,
    'Unknown native source must remain review-only without execution');
}
echo "PASS: NATIVE_CAPABILITY_ADAPTER_REGISTRY 10 real-source read-only and denial checks\n";
