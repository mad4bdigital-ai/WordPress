<?php
// Hermetic registration/permission regression, without network, WordPress database or writes.
define( 'ABSPATH', __DIR__ );
$GLOBALS['skill_ability_registry'] = array();
$GLOBALS['skill_can_read'] = false;
function wp_register_ability( $name, $args ) {
    if ( isset( $GLOBALS['skill_ability_registry'][ $name ] ) ) throw new RuntimeException( 'Duplicate ability: ' . $name );
    $GLOBALS['skill_ability_registry'][ $name ] = $args;
    return true;
}
class MAD4B_SCP_Skill_Registry {
    public static function levels() { return array( 'site', 'connection', 'provider', 'adapter', 'workflow' ); }
}
class MAD4B_SCP_Policy {
    public static function can_read() { return true === $GLOBALS['skill_can_read']; }
}
class MAD4B_SCP_Adapter_Base {}
function assert_skill( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
$root = dirname( __DIR__ );
require_once $root . '/includes/class-mad4b-scp-skill-abilities.php';
require_once $root . '/includes/adapters/class-mad4b-scp-skills-adapter.php';
MAD4B_SCP_Skill_Abilities::register_abilities();
$expected = array(
    'mad4b/skill-match-task' => 'match_task',
    'mad4b/skill-composition-plan' => 'composition_plan',
);
$adapter = new MAD4B_SCP_Skills_Adapter();
$projection = $adapter->ability_names();
assert_skill( empty( $projection['content'] ) && empty( $projection['admin'] ), 'Skills adapter must remain read-only' );
foreach ( $expected as $name => $method ) {
    assert_skill( isset( $GLOBALS['skill_ability_registry'][ $name ] ), 'Missing registered ability: ' . $name );
    $ability = $GLOBALS['skill_ability_registry'][ $name ];
    assert_skill( in_array( $name, $projection['read'], true ), 'Missing adapter read attribution' );
    assert_skill( $ability['category'] === 'mad4b-read', 'Read category missing' );
    assert_skill( $ability['meta']['public'] === false && $ability['meta']['show_in_rest'] === false, 'Ability exposed to public REST' );
    assert_skill( $ability['meta']['mcp']['public'] === false, 'MCP tool exposed publicly' );
    assert_skill( $ability['meta']['annotations']['readonly'] === true &&
                  $ability['meta']['annotations']['destructive'] === false, 'Read-only annotations missing' );
    assert_skill( $ability['permission_callback'] === array( 'MAD4B_SCP_Policy', 'can_read' ), 'Existing permission policy bypassed' );
    assert_skill( $ability['execute_callback'] === array( 'SCP_Skill_Task_Composition', $method ), 'Router callback drift' );
    assert_skill( $ability['input_schema']['required'] === array( 'task' ), 'Task input not required' );
    assert_skill( $ability['input_schema']['additionalProperties'] === false, 'Unbounded inputs admitted' );
    assert_skill( $ability['input_schema']['properties']['source_ids']['maxItems'] === 40, 'Source limits drifted' );
    assert_skill( $ability['input_schema']['properties']['task_context']['maxLength'] === 4000, 'Follow-up context must be bounded' );
    assert_skill( call_user_func( $ability['permission_callback'] ) === false, 'Anonymous/read-denied caller admitted' );
}
// Verify the four external source discovery/read tools use the same governed
// permission path and never acquire mutation or public exposure.
$source_expected = array(
    'mad4b/skill-sources-list' => array( 'list_sources', array() ),
    'mad4b/skill-source-skills' => array( 'list_skills', array( 'source_id' ) ),
    'mad4b/skill-source-get' => array( 'get_skill', array( 'source_id', 'path', 'blob_sha' ) ),
    'mad4b/skill-source-resource' => array( 'get_resource', array( 'source_id', 'skill_path', 'path', 'blob_sha' ) ),
);
foreach ( $source_expected as $name => $spec ) {
    assert_skill( isset( $GLOBALS['skill_ability_registry'][ $name ] ), 'Missing external source read ability: ' . $name );
    $ability = $GLOBALS['skill_ability_registry'][ $name ];
    assert_skill( in_array( $name, $projection['read'], true ), 'External source read omitted from adapter: ' . $name );
    assert_skill( 'mad4b-read' === $ability['category'] && false === $ability['meta']['public']
        && false === $ability['meta']['show_in_rest'] && false === $ability['meta']['mcp']['public'],
        'External source ability became public: ' . $name );
    assert_skill( true === $ability['meta']['annotations']['readonly']
        && false === $ability['meta']['annotations']['destructive'], 'Source read-only annotation drift: ' . $name );
    assert_skill( $ability['permission_callback'] === array( 'MAD4B_SCP_Policy', 'can_read' ),
        'External source bypassed canonical read permission: ' . $name );
    assert_skill( $ability['execute_callback'] === array( 'SCP_External_Skill_Sources', $spec[0] ),
        'External source callback changed: ' . $name );
    assert_skill( ( $ability['input_schema']['required'] ?? array() ) === $spec[1],
        'External source required arguments drifted: ' . $name );
    assert_skill( false === $ability['input_schema']['additionalProperties'],
        'External source input schema became open-ended: ' . $name );
    assert_skill( false === call_user_func( $ability['permission_callback'] ),
        'Unauthorized caller admitted to external source: ' . $name );
}

$GLOBALS['skill_can_read'] = true;
foreach ( $expected as $name => $method ) {
    $ability = $GLOBALS['skill_ability_registry'][ $name ];
    assert_skill( call_user_func( $ability['permission_callback'] ) === true, 'Authorized read denied' );
}
foreach ( array_keys( $source_expected ) as $name ) {
    assert_skill( true === call_user_func( $GLOBALS['skill_ability_registry'][ $name ]['permission_callback'] ),
        'Authorized source reader incorrectly denied: ' . $name );
}
assert_skill( ! isset( $GLOBALS['skill_ability_registry']['mad4b/skill-execution-dispatch'] ), 'Unexpected execution surface' );
echo "Skill Task Abilities registration fixtures: PASS\n";
