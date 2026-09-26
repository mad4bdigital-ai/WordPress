<?php
/**
 * Runtime fault model for Bit Flows possible-side-effect uncertainty.
 *
 * Uses exact adapter code with bounded in-memory provider model stubs. No network,
 * WordPress database or real Bit Flows mutation is performed.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MAD4B_MCP_BITFLOWS_EXECUTION_ENABLED', true );

	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code, $message = '', $data = null ) {
			$this->code = (string) $code;
			$this->message = (string) $message;
			$this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {}
	function apply_filters( $tag, $value, ...$args ) {
		if ( 'mad4b_scp_bitflows_flow_allowed' === $tag ) return true;
		if ( 'mad4b_scp_bitflows_run_permission' === $tag ) return true;
		return $value;
	}
	function get_current_user_id() { return 1; }
	function current_user_can( $capability ) { return 'manage_options' === $capability; }

	class MAD4B_SCP_Adapter_Base {
		public function status() { return array(); }
		protected function normalize( $value ) {
			return json_decode( json_encode( $value ), true );
		}
		protected function unavailable_error() {
			return new WP_Error( 'mad4b_provider_unavailable', 'Provider unavailable.' );
		}
		public function execute_canary( $ability_name, array $input ) {
			return new WP_Error( 'unsupported', 'Unsupported canary.' );
		}
		public function canary_result_summary( $ability_name, $result ) { return $result; }
		protected function add_ability() {}
		protected function schema( array $properties, array $required = array() ) {
			return array( 'type' => 'object', 'properties' => $properties, 'required' => $required );
		}
	}

	final class MAD4B_SCP_Site_Profile {
		public static function site_uuid() { return '11111111-2222-4333-8444-555555555555'; }
	}

	final class MAD4B_SCP_Workflow_Providers {
		public static $plan_sha = '';
		public static function plan( $input ) {
			return array(
				'contract' => 'mad4b.workflow-plan.v1',
				'plan_sha256' => self::$plan_sha,
				'provider' => 'bitflows',
				'operation' => 'execute',
			);
		}
	}

	final class MAD4B_SCP_Audit {
		public static $events = array();
		public static function record( $ability, $summary, $status, $mutation = false ) {
			self::$events[] = compact( 'ability', 'summary', 'status', 'mutation' );
			return array( 'recorded' => true );
		}
	}

	final class MAD4B_SCP_Durable_Execution {
		public static $rows = array();
		public static function reset() { self::$rows = array(); }
		public static function scope_key( $site_uuid, $ability, $operation, $aggregate ) {
			return hash( 'sha256', implode( "\0", array( $site_uuid, $ability, $operation, $aggregate ) ) );
		}
		private static function key( $scope, $idempotency ) { return $scope . ':' . $idempotency; }
		public static function begin_idempotency( $scope, $idempotency, $request_sha, $ttl = 3600 ) {
			$key = self::key( $scope, $idempotency );
			if ( isset( self::$rows[ $key ] ) ) {
				$row = self::$rows[ $key ];
				if ( ! hash_equals( $row['request_sha256'], $request_sha ) ) return new WP_Error( 'mad4b_idempotency_hash_conflict', 'Request hash conflict.' );
				if ( 'completed' === $row['status'] ) {
					return array(
						'claimed' => false,
						'replayed' => true,
						'result' => $row['result'],
						'result_sha256' => $row['result_sha256'],
					);
				}
				return new WP_Error( 'mad4b_idempotency_in_progress', 'Idempotency claim still pending.' );
			}
			self::$rows[ $key ] = array(
				'status' => 'pending',
				'request_sha256' => $request_sha,
				'claim_epoch' => 1,
				'result' => null,
				'result_sha256' => '',
				'reconciliation_ref' => '',
			);
			return array(
				'claimed' => true,
				'replayed' => false,
				'scope_key' => $scope,
				'idempotency_key' => $idempotency,
				'request_sha256' => $request_sha,
				'claim_epoch' => 1,
			);
		}
		public static function complete_idempotency( $claim, $result ) {
			$key = self::key( $claim['scope_key'], $claim['idempotency_key'] );
			if ( ! isset( self::$rows[ $key ] ) || 'pending' !== self::$rows[ $key ]['status'] ) {
				return new WP_Error( 'mad4b_idempotency_completion_invalid', 'Claim is unavailable.' );
			}
			self::$rows[ $key ]['status'] = 'completed';
			self::$rows[ $key ]['result'] = $result;
			self::$rows[ $key ]['result_sha256'] = hash( 'sha256', json_encode( $result ) );
			return array( 'result_sha256' => self::$rows[ $key ]['result_sha256'] );
		}
		public static function complete_idempotency_from_reconciliation( $scope, $idempotency, $request_sha, $ref, $result ) {
			$key = self::key( $scope, $idempotency );
			if ( ! isset( self::$rows[ $key ] ) ) {
				self::$rows[ $key ] = array(
					'status' => 'pending',
					'request_sha256' => $request_sha,
					'claim_epoch' => 1,
					'result' => null,
					'result_sha256' => '',
					'reconciliation_ref' => '',
				);
			}
			if ( ! hash_equals( self::$rows[ $key ]['request_sha256'], $request_sha ) ) {
				return new WP_Error( 'mad4b_idempotency_hash_conflict', 'Reconciliation request hash conflict.' );
			}
			$replayed = 'completed' === self::$rows[ $key ]['status'];
			if ( ! $replayed ) {
				self::$rows[ $key ]['status'] = 'completed';
				self::$rows[ $key ]['result'] = $result;
				self::$rows[ $key ]['result_sha256'] = hash( 'sha256', json_encode( $result ) );
				self::$rows[ $key ]['reconciliation_ref'] = $ref;
			}
			return array(
				'reconciled' => ! $replayed,
				'replayed' => $replayed,
				'result' => self::$rows[ $key ]['result'],
				'result_sha256' => self::$rows[ $key ]['result_sha256'],
			);
		}
		public static function pending_count() {
			$count = 0;
			foreach ( self::$rows as $row ) if ( 'pending' === $row['status'] ) ++$count;
			return $count;
		}
	}
}

namespace BitApps\Pi\Model {
	final class ListQuery {
		private $rows;
		public function __construct( array $rows ) { $this->rows = array_values( $rows ); }
		public function desc() {
			usort( $this->rows, static function ( $a, $b ) {
				$ai = is_array( $a ) ? (int) ( $a['id'] ?? 0 ) : (int) ( $a->id ?? 0 );
				$bi = is_array( $b ) ? (int) ( $b['id'] ?? 0 ) : (int) ( $b->id ?? 0 );
				return $bi <=> $ai;
			} );
			return $this;
		}
		public function take( $limit ) { $this->rows = array_slice( $this->rows, 0, (int) $limit ); return $this; }
		public function select( $fields ) { return $this; }
		public function get() { return $this->rows; }
	}

	final class FlowQuery {
		public function findOne( $where ) {
			$id = (int) ( $where['id'] ?? 0 );
			return 7 === $id ? Flow::fixture() : null;
		}
	}

	final class Flow {
		public static function fixture() {
			return (object) array(
				'id' => 7,
				'title' => 'MAD4B uncertainty fixture',
				'map' => array( 'nodes' => array() ),
				'settings' => array( 'mode' => 'test' ),
				'listener_type' => 'webhook',
				'is_hook_capture' => 0,
				'is_active' => 1,
				'trigger_type' => 'manual',
			);
		}
		public static function select( $fields ) { return new FlowQuery(); }
	}

	final class FlowNode {
		public static function where( $field, $value ) { return new ListQuery( array() ); }
	}

	final class FlowLog {
		public static $rows = array();
		public static function reset() { self::$rows = array(); }
		public static function where( $field, $value ) {
			$rows = array_values( array_filter( self::$rows, static function ( $row ) use ( $field, $value ) {
				return isset( $row[ $field ] ) && (string) $row[ $field ] === (string) $value;
			} ) );
			return new ListQuery( $rows );
		}
	}

	final class FlowHistory {
		public static $rows = array();
		public static function reset() { self::$rows = array(); }
		public static function findOne( $where ) {
			$id = (int) ( $where['id'] ?? 0 );
			return isset( self::$rows[ $id ] ) ? (object) self::$rows[ $id ] : null;
		}
	}
}

namespace BitApps\Pi\src\Flow {
	final class FlowExecutor {
		public static $mode = 'side_effect_then_throw';
		public static $executions = 0;
		public static $last_trigger = array();
		public static function reset( $mode ) {
			self::$mode = $mode;
			self::$executions = 0;
			self::$last_trigger = array();
		}
		public static function execute( $flow, $trigger ) {
			++self::$executions;
			self::$last_trigger = $trigger;
			if ( 'side_effect_then_throw' === self::$mode ) {
				self::materialize_provider_evidence( $flow, $trigger, 101 );
				throw new \RuntimeException( 'provider timeout after possible side effect' );
			}
			if ( 'throw_without_readback' === self::$mode ) {
				throw new \RuntimeException( 'provider timeout before readback became visible' );
			}
			throw new \RuntimeException( 'unexpected fixture mode' );
		}
		public static function materialize_provider_evidence( $flow, $trigger, $history_id ) {
			\BitApps\Pi\Model\FlowHistory::$rows[ $history_id ] = array(
				'id' => $history_id,
				'flow_id' => (int) $flow->id,
				'status' => 'success',
			);
			\BitApps\Pi\Model\FlowLog::$rows[] = array(
				'id' => $history_id + 1000,
				'flow_history_id' => $history_id,
				'node_id' => (int) $flow->id . '-1',
				'status' => 'success',
				'output' => json_encode( array(
					'__mad4b_execution' => $trigger['__mad4b_execution'],
				) ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			);
		}
	}
}

namespace {
	require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-bitflows-adapter.php';

	$fail = static function ( $message ) {
		fwrite( STDERR, 'FAIL bitflows-uncertain-execution-runtime: ' . $message . PHP_EOL );
		exit( 1 );
	};
	$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };

	$adapter = new MAD4B_SCP_BitFlows_Adapter();
	$flow = $adapter->get_flow( array( 'flow_id' => 7 ) );
	$check( is_array( $flow ) && isset( $flow['flow_sha256'] ), 'unable to derive exact fixture flow fingerprint' );
	$flow_sha = (string) $flow['flow_sha256'];
	MAD4B_SCP_Workflow_Providers::$plan_sha = hash( 'sha256', 'bitflows-reviewed-plan-v1' );
	$plan_sha = MAD4B_SCP_Workflow_Providers::$plan_sha;

	$base_input = array(
		'flow_id' => 7,
		'expected_flow_sha256' => $flow_sha,
		'expected_plan_sha256' => $plan_sha,
		'trigger_data' => array( 'payload' => 'ci' ),
		'reason' => 'prove timeout-after-side-effect semantics',
	);

	// Case A: provider side effect exists, executor throws, exact correlation
	// readback proves the execution and completes idempotency without re-exec.
	MAD4B_SCP_Durable_Execution::reset();
	\BitApps\Pi\Model\FlowLog::reset();
	\BitApps\Pi\Model\FlowHistory::reset();
	\BitApps\Pi\src\Flow\FlowExecutor::reset( 'side_effect_then_throw' );
	$input = $base_input;
	$input['idempotency_key'] = 'ci-side-effect-readback-001';
	$result = $adapter->run_flow( $input );
	$check( is_array( $result ), 'side-effect timeout with readback did not reconcile to result' );
	$check( true === $result['readback_verified'], 'reconciled provider result was not readback verified' );
	$check( true === $result['idempotency_reconciled'], 'provider timeout result did not reconcile idempotency' );
	$check( 1 === \BitApps\Pi\src\Flow\FlowExecutor::$executions, 'provider executed more than once during immediate reconciliation' );
	$check( 0 === MAD4B_SCP_Durable_Execution::pending_count(), 'reconciled provider result left pending idempotency' );

	$replay = $adapter->run_flow( $input );
	$check( is_array( $replay ) && ! empty( $replay['idempotency_replayed'] ), 'completed provider result was not replayed durably' );
	$check( 1 === \BitApps\Pi\src\Flow\FlowExecutor::$executions, 'durable replay re-executed provider' );

	// Case B: provider throws and no correlated readback exists yet. The
	// request remains pending/uncertain and a retry must not call provider.
	MAD4B_SCP_Durable_Execution::reset();
	\BitApps\Pi\Model\FlowLog::reset();
	\BitApps\Pi\Model\FlowHistory::reset();
	\BitApps\Pi\src\Flow\FlowExecutor::reset( 'throw_without_readback' );
	$input = $base_input;
	$input['idempotency_key'] = 'ci-uncertain-timeout-002';
	$uncertain = $adapter->run_flow( $input );
	$check( is_wp_error( $uncertain ), 'provider timeout without readback did not fail closed' );
	$check( 'mad4b_bitflows_execution_uncertain' === $uncertain->get_error_code(), 'unexpected uncertain execution error code' );
	$data = $uncertain->get_error_data();
	$check( is_array( $data ) && false === $data['retry_safe'] && true === $data['idempotency_pending'], 'uncertain execution did not block retry' );
	$check( 1 === MAD4B_SCP_Durable_Execution::pending_count(), 'uncertain execution did not preserve pending idempotency' );
	$check( 1 === \BitApps\Pi\src\Flow\FlowExecutor::$executions, 'unexpected provider execution count after first timeout' );

	$blocked_retry = $adapter->run_flow( $input );
	$check( is_wp_error( $blocked_retry ), 'pending uncertain execution was silently retried' );
	$check( 'mad4b_idempotency_in_progress' === $blocked_retry->get_error_code(), 'pending retry did not remain fail-closed' );
	$check( 1 === \BitApps\Pi\src\Flow\FlowExecutor::$executions, 'pending retry re-executed provider' );

	// Evidence becomes visible later. The next identical request reconciles
	// from correlation and still must not re-execute the provider.
	$trigger = \BitApps\Pi\src\Flow\FlowExecutor::$last_trigger;
	$check( isset( $trigger['__mad4b_execution'] ), 'provider trigger lost MAD4B correlation metadata' );
	\BitApps\Pi\src\Flow\FlowExecutor::materialize_provider_evidence(
		\BitApps\Pi\Model\Flow::fixture(),
		$trigger,
		202
	);
	$late = $adapter->run_flow( $input );
	$check( is_array( $late ) && true === $late['readback_verified'], 'late provider evidence did not reconcile' );
	$check( ! empty( $late['idempotency_replayed'] ), 'late reconciliation was not classified as replay/reconciliation' );
	$check( ! empty( $late['idempotency_reconciled'] ), 'late reconciliation did not complete pending idempotency' );
	$check( 1 === \BitApps\Pi\src\Flow\FlowExecutor::$executions, 'late evidence reconciliation re-executed provider' );
	$check( 0 === MAD4B_SCP_Durable_Execution::pending_count(), 'late reconciliation left pending idempotency' );

	echo "mad4b.bitflows-uncertain-execution.runtime.v1: PASS\n";
}
