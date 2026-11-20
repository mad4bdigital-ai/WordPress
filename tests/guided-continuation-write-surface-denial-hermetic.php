<?php
/**
 * Original Guided Continuation provider references. Synthetic abilities
 * only; no externally registered ability, data transfer or writes.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['guided_can_read']=false;
$GLOBALS['guided_registry']=array();
$GLOBALS['guided_ability_registration']=array();
class WP_Error {
 private $code;public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action($hook,$callback,$priority=10){}
function wp_register_ability($name,$config){$GLOBALS['guided_ability_registration'][$name]=$config;}
function wp_has_ability($name){return isset($GLOBALS['guided_ability_registration'][$name]);}
function wp_get_ability($name){return $GLOBALS['guided_registry'][$name]??null;}
class MAD4B_SCP_Policy {
 public static function can_read(){return $GLOBALS['guided_can_read'];}
}
class FakeReadAbility {
 public $name; public $meta;public $schema;public $next;public $category='mad4b-read';public $executed=0;
 public function __construct($name,$next='', $surface='read') {
  $this->name=$name;$this->next=$next;
  $this->meta=['mcp'=>['surface'=>$surface],
   'annotations'=>['readonly'=>true,'destructive'=>false]];
  $this->schema=['type'=>'object','additionalProperties'=>false,'required'=>[]];
 }
 public function get_category(){return $this->category;}
 public function get_meta(){return $this->meta;}
 public function get_input_schema(){return $this->schema;}
 public function execute($input){++$this->executed;return [
  'state'=>'SOURCE_ONLY','ready'=>false,
  'next_ability'=>$this->next,'secret'=>'never_return_raw'];}
}
function continuation_assert($ok,$reason){
 if(!$ok)throw new RuntimeException('FAIL GUIDED CONTINUATION: '.$reason);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-guided-continuation.php';
$c='MAD4B_SCP_Guided_Continuation';
$c::register_abilities();
continuation_assert(count($GLOBALS['guided_ability_registration'])===1
 && isset($GLOBALS['guided_ability_registration']['mad4b/guided-continuation-run']),
 'Only the original one read-only guided continuation tool may be registered');
$reg=$GLOBALS['guided_ability_registration']['mad4b/guided-continuation-run'];
continuation_assert($reg['input_schema']['additionalProperties']===false
 && $reg['meta']['annotations']['readonly']===true
 && $reg['permission_callback']===['MAD4B_SCP_Policy','can_read'],
 'Original WordPress read permission must protect bounded continuation');
$first='mad4b/runtime-convergence-status';
$second='mad4b/write-runtime-certification';
$a=new FakeReadAbility($first,$second);
$b=new FakeReadAbility($second);
$GLOBALS['guided_registry'][$first]=$a;
$GLOBALS['guided_registry'][$second]=$b;
$denied=$c::run(['next_ability'=>$first]);
continuation_assert($denied instanceof WP_Error
 && $denied->get_error_code()==='mad4b_guided_continuation_read_denied'
 && $a->executed===0,
 'Unprivileged provider follow-up must not execute an inherited read ability');
$GLOBALS['guided_can_read']=true;
$follow=$c::run(['next_ability'=>$first,'original_operation_sha256'=>str_repeat('a',64),'max_steps'=>3]);
continuation_assert($follow['step_count']===2 && $follow['read_only']===true
 && $follow['mutation_performed']===false
 && $follow['authorizing']===false
 && $follow['approval_created']===false
 && $follow['original_mutation_replayed']===false
 && $follow['fresh_receipt_and_exact_write_approval_required']===true,
 'Bounded native read chain must return summary only and never create authority');
continuation_assert(!isset($follow['steps'][0]['secret']) && $a->executed===1 && $b->executed===1,
 'No raw provider payload or secrets in guided result');
$unknown=$c::run(['next_ability'=>'mad4b/grant-owner-write']);
continuation_assert(is_array($unknown)&&$unknown['step_count']===0
 && $unknown['state']==='AWAITING_GOVERNED_ACTION',
 'A suggested mutation cannot join the automatic read graph');
$cycle=$c::run(['next_ability'=>$first,'max_steps'=>3]);
continuation_assert(is_array($cycle),'Run must remain bounded even with cycles');
$b->meta['mcp']['surface']='write';
$before=$b->executed;
$malicious=$c::run(['next_ability'=>$second]);
continuation_assert($malicious['state']==='READ_CONTRACT_NOT_CERTIFIED' && $b->executed===$before,
 'A whitelisted Ability declaring write surface cannot execute via misleading readonly annotation');
$b->meta['mcp']['surface']='read';
$b->meta['annotations']['readonly']=false;
$before=$b->executed;
$wrong=$c::run(['next_ability'=>$second]);
continuation_assert($wrong['state']==='READ_CONTRACT_NOT_CERTIFIED' && $b->executed===$before,
 'Original whitelist cannot bypass an Ability that lacks readonly evidence');
$b->meta['annotations']['readonly']=true;
$b->schema['required']=['secret'];
$needs=$c::run(['next_ability'=>$second]);
continuation_assert($needs['state']==='READ_CONTRACT_NOT_CERTIFIED',
 'A read requiring nonempty principal inputs cannot be auto-run');
$b->schema['required']=[];
$b->meta['mcp']=[];
$legacy=$c::run(['next_ability'=>$second]);
continuation_assert(is_array($legacy)&&$legacy['step_count']===1,
 'Legacy first-party read Ability with omitted Surface should remain compatible');
$bad=$c::run(['next_ability'=>$first,'max_steps'=>4]);
continuation_assert($bad instanceof WP_Error && $bad->get_error_code()==='mad4b_guided_continuation_identity_invalid',
 'Do not permit unbounded read loops');
echo "PASS: GUIDED_READ_SURFACE native bounded continuation and explicit write-surface denial\n";
