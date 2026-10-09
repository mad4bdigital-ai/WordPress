<?php
// Isolated blocked authoring contract: missing Brand Core never leaks Skill text or signed receipt.
define('ABSPATH', __DIR__);
function sanitize_text_field($s) { return strip_tags((string) $s); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_register_ability($name, $args) { $GLOBALS['abilities'][$name] = $args; }
class WP_Error {
  private $code;
  function __construct($code, $message = '') { $this->code = (string)$code; }
  function get_error_code() { return $this->code; }
}
class MAD4B_SCP_Skill_Registry {
  static function levels() { return array('site','workflow'); }
  static function list_skills($filter = array()) { return isset($GLOBALS['candidates']) ? $GLOBALS['candidates'] : array(array('level'=>'workflow','target'=>'content-authoring','name'=>'wordpress-content-authoring')); }
  static function get_skill($level, $target, $name) {
    if ('workflow' !== $level || 'content-authoring' !== $target || 'wordpress-content-authoring' !== $name) return new WP_Error('mad4b_skill_target_invalid');
    return array('logical_id'=>'workflow:content-authoring:wordpress-content-authoring','enabled'=>true,
      'content'=>'PRIVATE_AUTHORING_INSTRUCTIONS_NOT_FOR_DIAGNOSTICS',
      'context_policy'=>array('brand_context_required'=>true,
        'required_context_sets'=>array('brand_strategy','tone_of_voice','editorial_guidelines')));
  }
}
class MAD4B_SCP_Context_Preflight {
  static function preflight_entry($skill, $scope, $ability) {
    $ready = !empty($GLOBALS['brand_context_ready']);
    return array(
      'ready'=>$ready,
      'effective_policy'=>$skill['context_policy'],
      'blockers'=>$ready ? array() : array('required_context_sets_missing'),
      'missing_context_sets'=>$ready ? array() : array('tone_of_voice','editorial_guidelines'),
      'envelope'=>array('assets'=>array('PRIVATE_CONTEXT_CONTENT')),
      'receipt'=>array('ready'=>$ready,'signature_b64url'=>'PRIVATE_SIGNATURE'),
    );
  }
}
require __DIR__.'/../includes/class-mad4b-scp-skill-abilities.php';
MAD4B_SCP_Skill_Abilities::register_abilities();
if (!isset($GLOBALS['abilities']['mad4b/skill-context-preflight']) ||
    !isset($GLOBALS['abilities']['mad4b/skill-get'])) throw new RuntimeException('Read-only Context gate not registered');

$input = array('level'=>'workflow','name'=>'wordpress-content-authoring',
               'intended_ability'=>'mad4b/content-create-post');
$blocked = MAD4B_SCP_Skill_Abilities::skill_context_preflight($input);
if (is_wp_error($blocked) || !empty($blocked['ready']) ||
    $blocked['state'] !== 'blocked' ||
    $blocked['next_action'] !== 'reconcile_and_approve_required_brand_context' ||
    !in_array('editorial_guidelines',$blocked['missing_context_sets'],true) ||
    empty($blocked['read_only']) || !empty($blocked['mutation_performed'])) {
  throw new RuntimeException('Missing Brand Context was not reported as structured blocked metadata');
}
if (strpos(json_encode($blocked),'PRIVATE_') !== false ||
    isset($blocked['receipt']) || isset($blocked['envelope']) ||
    !empty($blocked['context_receipt_issued']) ||
    !empty($blocked['skill_body_exposed'])) {
  throw new RuntimeException('Blocked Context preflight leaked confidential Skill or receipt material');
}
$skill = MAD4B_SCP_Skill_Abilities::skill_get($input);
if (!is_wp_error($skill) || 'mad4b_required_brand_context_unavailable' !== $skill->get_error_code()) {
  throw new RuntimeException('Blocked Skill content unexpectedly bypassed original publication guard');
}
$GLOBALS['brand_context_ready'] = true;
$ready = MAD4B_SCP_Skill_Abilities::skill_context_preflight($input);
if (empty($ready['ready']) || $ready['next_action'] !== 'request_skill_get_for_exact_signed_context_receipt' ||
    isset($ready['receipt']) || isset($ready['skill'])) {
  throw new RuntimeException('Ready preflight did not preserve credential separation');
}
$skill = MAD4B_SCP_Skill_Abilities::skill_get($input);
if (is_wp_error($skill) || $skill['skill']['content'] !== 'PRIVATE_AUTHORING_INSTRUCTIONS_NOT_FOR_DIAGNOSTICS') {
  throw new RuntimeException('Authorized Skill getter stopped returning context on ready');
}
$GLOBALS['candidates'] = array(
 array('level'=>'workflow','target'=>'content-authoring','name'=>'wordpress-content-authoring'),
 array('level'=>'workflow','target'=>'other','name'=>'wordpress-content-authoring')
);
$ambiguous = MAD4B_SCP_Skill_Abilities::skill_context_preflight($input);
if (!is_wp_error($ambiguous) || $ambiguous->get_error_code() !== 'mad4b_skill_target_ambiguous') {
  throw new RuntimeException('Ambiguous target not denied closed');
}
echo "PASS Skill Context preflight: redacted blocked status, signed receipt separation, unique target, ambiguous denial\n";
