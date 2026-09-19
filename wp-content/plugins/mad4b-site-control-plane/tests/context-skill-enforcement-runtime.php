<?php

define( 'ABSPATH', '/tmp/mad4b-context-skill-enforcement/' );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }

final class MAD4B_SCP_Skill_Registry {
	public static function levels() { return array( 'site' ); }
	public static function get_skill( $level, $target, $name ) {
		return array(
			'logical_id' => 'site:_site:' . $name,
			'level' => 'site',
			'target' => '_site',
			'name' => $name,
			'sha256' => str_repeat( 'a', 64 ),
			'context_policy' => array( 'preset' => 'brand_core', 'brand_context_required' => true, 'allowed_mutation_abilities' => array( 'mad4b/content-update-post' ) ),
		);
	}
}
final class MAD4B_SCP_Context_Preflight {
	public static $ready = false;
	public static function preflight_entry( array $skill, $task_scope = '', $intended_ability = '' ) {
		return array(
			'contract' => 'mad4b.context-preflight.v1',
			'ready' => self::$ready,
			'state' => self::$ready ? 'ready' : 'blocked',
			'blockers' => self::$ready ? array() : array( 'required_context_sets_missing' ),
			'envelope' => array( 'contract' => 'mad4b.context-envelope.v1', 'required' => true, 'assets' => array() ),
			'receipt' => array( 'contract' => 'mad4b.content-context-receipt.v1', 'receipt_sha256' => str_repeat( 'b', 64 ), 'ready' => self::$ready, 'intended_ability' => $intended_ability ),
		);
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-skill-abilities.php';

function mad4b_skill_context_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$blocked = MAD4B_SCP_Skill_Abilities::skill_get( array( 'level' => 'site', 'name' => 'blog-writer', 'task_scope' => 'campaign-x', 'intended_ability' => 'mad4b/content-update-post' ) );
mad4b_skill_context_assert( is_wp_error( $blocked ), 'Context-required Skill must fail closed when preflight is blocked.', $blocked );
mad4b_skill_context_assert( 'mad4b_required_brand_context_unavailable' === $blocked->get_error_code(), 'Blocked Skill must expose canonical Context blocker.', $blocked->get_error_code() );

MAD4B_SCP_Context_Preflight::$ready = true;
$ready = MAD4B_SCP_Skill_Abilities::skill_get( array( 'level' => 'site', 'name' => 'blog-writer', 'task_scope' => 'campaign-x', 'intended_ability' => 'mad4b/content-update-post' ) );
mad4b_skill_context_assert( is_array( $ready ) && 'mad4b.skill-get.v3' === $ready['contract'], 'Ready Skill exposure must use Context-bound contract.', $ready );
mad4b_skill_context_assert( 'mad4b.context-envelope.v1' === $ready['context_envelope']['contract'], 'Ready Skill must carry Context Envelope.', $ready );
mad4b_skill_context_assert( 'mad4b.content-context-receipt.v1' === $ready['context_receipt']['contract'], 'Ready Skill must carry Context Receipt.', $ready );

echo "mad4b.site-control-plane.context-skill-enforcement.runtime.v1: PASS\n";
