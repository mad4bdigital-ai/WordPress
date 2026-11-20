<?php
/** Preserve external sources and legacy skills-list while adding bounded discovery. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
final class MAD4B_SCP_Skill_Registry {
    const MAX_SCAN_SKILLS = 500;
    public static $items = array();
    public static function levels() { return array( 'site', 'provider' ); }
    public static function list_skills( array $filters = array() ) { return self::$items; }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-skill-abilities.php';
function assert_it( $ok, $label ) { if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); } }
MAD4B_SCP_Skill_Registry::$items = array(
    array( 'name'=>'cruise-booking', 'description'=>'Nile cruise', 'logical_id'=>'site:travel:cruise-booking' ),
    array( 'name'=>'company-ops', 'description'=>'Team operations', 'logical_id'=>'site:company:company-ops' ),
    array( 'name'=>'content-audit', 'description'=>'Safe media audit', 'logical_id'=>'site:content:content-audit' ),
);
$legacy = MAD4B_SCP_Skill_Abilities::skills_list();
assert_it( 3 === $legacy['count'] && 3 === count($legacy['skills']), 'Legacy list remains complete' );
assert_it( 'none' === $legacy['authority_effect'], 'Skills list must not grant authority' );
$one = MAD4B_SCP_Skill_Abilities::skills_list(array('limit'=>2));
assert_it( 2 === $one['count'] && 3 === $one['total_count'], 'First bounded page' );
assert_it( true === $one['has_more'] && 2 === $one['next_offset'], 'Continuation metadata' );
$two = MAD4B_SCP_Skill_Abilities::skills_list(array('offset'=>2,'limit'=>2));
assert_it( 1 === $two['count'] && false === $two['has_more'], 'Last page' );
$q = MAD4B_SCP_Skill_Abilities::skills_list(array('query'=>'NILE', 'limit'=>10));
assert_it( 1 === $q['total_count'] && 'cruise-booking' === $q['skills'][0]['name'], 'Case-insensitive query' );
$oversized = MAD4B_SCP_Skill_Abilities::skills_list(array('limit'=>999));
assert_it( 100 === $oversized['limit'], 'Page limit is clamped' );
$gone = MAD4B_SCP_Skill_Abilities::skills_list(array('offset'=>1000,'limit'=>10));
assert_it( 0 === $gone['count'] && null === $gone['next_offset'], 'Out-of-range page' );
// The new registry streams the complete Skill universe; the former 500-count
// constant is no longer a scan cap. Assert completeness beyond that boundary,
// while preserving the per-page 100-item limit and exact continuation.
MAD4B_SCP_Skill_Registry::$items = array_fill(0, 501, array('name'=>'same','description'=>'same','logical_id'=>'site:sample'));
$cap = MAD4B_SCP_Skill_Abilities::skills_list(array('query'=>'sample','limit'=>2));
assert_it( 501 === $cap['total_count'] && 2 === $cap['count'], 'Full 501-record universe must not silently truncate' );
assert_it( true === $cap['has_more'] && 2 === $cap['next_offset'], 'Exact continuation when more than old scan ceiling' );
assert_it( false === ($cap['scan_ceiling_reached'] ?? false), 'Uncapped registry must not advertise a false scan limit' );
$source = file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-skill-abilities.php');
foreach (array('mad4b/skill-sources-list','mad4b/skill-source-skills','mad4b/skill-source-get','mad4b/skill-source-resource','mad4b/skill-match-task','mad4b/skill-composition-plan') as $ability) {
    assert_it( false !== strpos($source,$ability), 'Do not erase existing external sources/composition: '.$ability );
}
echo "PASS: Live source Skills query and bounded pagination + legacy external sources (14 guards)\n";
