<?php
// Self-contained GitHub-fixture regression: no network, database, or plugin bootstrap.
define( 'ABSPATH', __DIR__ );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['fake_sources'] = array();
$GLOBALS['is_admin'] = true;
$GLOBALS['tree_truncated'] = false;
$GLOBALS['fake_blob_valid'] = true;
$GLOBALS['fake_urls'] = array();
$GLOBALS['fake_timeouts'] = array();
function get_option( $name, $default = array() ) { return $GLOBALS['fake_sources']; }
function update_option( $name, $value, $autoload = false ) { $GLOBALS['fake_sources'] = $value; return true; }
function current_user_can( $cap ) { return $GLOBALS['is_admin']; }
function wp_cache_get( $key, $group ) { return false; }
function wp_cache_set( $key, $value, $group, $ttl ) { return true; }
function wp_remote_retrieve_response_code( $v ) { return $v['response']['code']; }
function wp_remote_retrieve_body( $v ) { return $v['body']; }
class MAD4B_SCP_Egress_Policy {
    public static function mark_request( $purpose, $url, $authority, $args ) {
        if ( $purpose !== 'skill_source_discovery' || $authority !== 'https://api.github.com' || strpos( $url, 'https://api.github.com/repos/' ) !== 0 || $args['redirection'] !== 0 || $args['sslverify'] !== true ) return new WP_Error( 'egress_test_failed' );
        return $args;
    }
    public static function boot() {}
}
function wp_safe_remote_get( $url, $args ) {
    $GLOBALS['fake_urls'][] = $url;
    $GLOBALS['fake_timeouts'][] = $args['timeout'];
    $content = "---\nname: sample\ndescription: Example\n---\n\nRead only.\n";
    $sha = sha1( 'blob ' . strlen( $content ) . "\0" . $content );
    if ( strpos( $url, '/git/trees/' ) !== false ) {
        $result = array( 'sha' => str_repeat( 'a', 40 ), 'truncated' => $GLOBALS['tree_truncated'], 'tree' => array(
            array( 'type' => 'blob', 'path' => 'skills/sample/SKILL.md', 'sha' => $sha, 'size' => strlen( $content ) ),
            array( 'type' => 'blob', 'path' => 'skills/sample/references/notes.md', 'sha' => $sha, 'size' => strlen( $content ) ),
        ) );
    } else {
        $result = array( 'sha' => $GLOBALS['fake_blob_valid'] ? $sha : str_repeat( 'b', 40 ), 'encoding' => 'base64', 'content' => base64_encode( $content ) );
    }
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $result ) );
}
function assert_ok( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, $message . PHP_EOL ); exit( 1 ); } }
require_once dirname( __DIR__ ) . '/includes/class-external-skill-sources.php';
assert_ok( is_wp_error( SCP_External_Skill_Sources::save_source( 'https://example.invalid/foo', 'main' ) ), 'URL should not be admitted' );
assert_ok( is_wp_error( SCP_External_Skill_Sources::save_source( 'owner/project', '../main' ) ), 'unsafe ref admitted' );
$GLOBALS['is_admin'] = false;
assert_ok( is_wp_error( SCP_External_Skill_Sources::save_source( 'owner/project', 'main' ) ), 'non-admin write admitted' );
$GLOBALS['is_admin'] = true;
$source = SCP_External_Skill_Sources::save_source( 'owner/project', 'main' );
assert_ok( ! is_wp_error( $source ) && strlen( $source['id'] ) === 24, 'source save failed' );
assert_ok( count( SCP_External_Skill_Sources::list_sources()['sources'] ) === 1, 'list sources failed' );
$listing = SCP_External_Skill_Sources::list_skills( array( 'source_id' => $source['id'] ) );
assert_ok( ! is_wp_error( $listing ) && $listing['count'] === 1, 'discovery failed' );
$path = $listing['skills'][0]['path'];
$sha = $listing['skills'][0]['blob_sha'];
$input = array( 'source_id' => $source['id'], 'path' => $path, 'blob_sha' => $sha );
$read = SCP_External_Skill_Sources::get_skill( $input );
assert_ok( ! is_wp_error( $read ) && $read['installed'] === false && $read['executed'] === false, 'source read failed or installation implied' );
$input['blob_sha'] = str_repeat( '0', 40 );
assert_ok( is_wp_error( SCP_External_Skill_Sources::get_skill( $input ) ), 'stale blob accepted' );
$input['path'] = 'skills/../sample/SKILL.md';
assert_ok( is_wp_error( SCP_External_Skill_Sources::get_skill( $input ) ), 'path traversal accepted' );
$r = SCP_External_Skill_Sources::get_resource( array( 'source_id' => $source['id'], 'skill_path' => $path, 'path' => 'skills/sample/references/notes.md', 'blob_sha' => $sha ) );
assert_ok( ! is_wp_error( $r ), 'safe supporting file rejected' );
$r = SCP_External_Skill_Sources::get_resource( array( 'source_id' => $source['id'], 'skill_path' => $path, 'path' => 'skills/other/references/notes.md', 'blob_sha' => $sha ) );
assert_ok( is_wp_error( $r ), 'cross-skill resource accepted' );
$GLOBALS['fake_blob_valid'] = false;
$input = array( 'source_id' => $source['id'], 'path' => $path, 'blob_sha' => $sha );
assert_ok( is_wp_error( SCP_External_Skill_Sources::get_skill( $input ) ), 'wrong upstream blob identity accepted' );
$GLOBALS['fake_blob_valid'] = true;
// Deadline must propagate into both Git tree and blob requests.
$budget_input=array('source_id'=>$source['id'],'path'=>$path,'blob_sha'=>$sha,'deadline'=>microtime(true)+2.4);
$before=count($GLOBALS['fake_timeouts']);
$bounded=SCP_External_Skill_Sources::get_skill($budget_input);
assert_ok(!is_wp_error($bounded),'Small shared request deadline unexpectedly failed');
$timeouts=array_slice($GLOBALS['fake_timeouts'],$before);
assert_ok(count($timeouts)>=2 && max($timeouts)<=2.0, 'GitHub per-request timeout exceeded shared deadline');
$expired=$budget_input;
$expired['deadline']=microtime(true)-2;
$before=count($GLOBALS['fake_urls']);
$result=SCP_External_Skill_Sources::get_skill($expired);
assert_ok(is_wp_error($result) && $result->get_error_code()==='skill_source_budget_exhausted','Expired deadline must fail closed');
assert_ok(count($GLOBALS['fake_urls'])===$before,'Expired deadline started external request');
$GLOBALS['tree_truncated'] = true;
assert_ok( is_wp_error( SCP_External_Skill_Sources::list_skills( array( 'source_id' => $source['id'] ) ) ), 'truncated tree accepted' );
assert_ok( SCP_External_Skill_Sources::remove_source( $source['id'] ) === true, 'delete failed' );
assert_ok( count( SCP_External_Skill_Sources::sources() ) === 0, 'removed source remained' );
echo "External Skill Source fixtures: PASS\n";
