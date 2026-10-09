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
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path = '/') { return 'https://staging.allroyalegypt.com' . $path; }
class MAD4B_SCP_Context_Authority {
  static function brand_core_coverage() {
    return array('ready'=>false,
      'missing_required_context_sets'=>array('brand_strategy','tone_of_voice','editorial_guidelines'));
  }
}
$rights = MAD4B_SCP_Skill_Abilities::external_source_rights_preflight(array(
  'source_url'=>'https://www.memphistours.com/egypt/cruises/river-nile-cruises',
  'intended_use'=>'commercial_offer'
));
if (is_wp_error($rights) || empty($rights['external_source']) ||
    !in_array('signed_distribution_or_resale_authorization',$rights['evidence_requirements'],true) ||
    !empty($rights['supplier_rights_verified']) ||
    !empty($rights['commercial_reuse_authorized']) ||
    !empty($rights['authorizing']) || empty($rights['read_only']) ||
    count($rights['brand_core_missing_context_sets']) !== 3) {
  throw new RuntimeException('Third-party commercial rights were not denied open with exact evidence');
}
$media = MAD4B_SCP_Skill_Abilities::external_source_rights_preflight(array(
  'source_url'=>'https://www.memphistours.com/image.jpg', 'intended_use'=>'third_party_media'
));
if (is_wp_error($media) || empty($media['external_source']) ||
    !in_array('license_or_permission',$media['evidence_requirements'],true) ||
    !empty($media['licensed_media_verified'])) {
  throw new RuntimeException('Media rights were implicitly granted');
}
$local = MAD4B_SCP_Skill_Abilities::external_source_rights_preflight(array(
  'source_url'=>'https://staging.allroyalegypt.com/tour', 'intended_use'=>'editorial_reference'
));
if (is_wp_error($local) || !empty($local['external_source']) ||
    !empty($local['commercial_reuse_authorized'])) {
  throw new RuntimeException('First-party URL was classified as a supplier license');
}
$bad = MAD4B_SCP_Skill_Abilities::external_source_rights_preflight(array(
  'source_url'=>'file:///etc/passwd', 'intended_use'=>'commercial_offer'
));
if (!is_wp_error($bad) || 'mad4b_external_source_url_invalid' !== $bad->get_error_code()) {
  throw new RuntimeException('Non-HTTP source URL was accepted');
}
echo "PASS Skill Context and supplier rights preflights: redacted blocked status, signed receipt separation, unique target, ambiguous denial\n";
