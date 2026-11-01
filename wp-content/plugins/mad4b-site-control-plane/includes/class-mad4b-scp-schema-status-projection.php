<?php
/**
 * Read-only Schema status view, split from the legacy Abilities facade.
 * This class does not migrate the schema or authorize any write.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Schema_Status_Projection {
	public static function status() {
		$status = MAD4B_SCP_Schema::status( true );
		$physical = isset( $status['physical_integrity'] ) && is_array( $status['physical_integrity'] ) ? $status['physical_integrity'] : array();
		$migration = isset( $status['migration'] ) && is_array( $status['migration'] ) ? $status['migration'] : array();
		$preflight = isset( $migration['preflight'] ) && is_array( $migration['preflight'] ) ? $migration['preflight'] : array();
		$receipt = isset( $migration['receipt'] ) && is_array( $migration['receipt'] ) ? $migration['receipt'] : array();
		$missing_tables = isset( $physical['missing_tables'] ) && is_array( $physical['missing_tables'] ) ? array_values( $physical['missing_tables'] ) : array();
		$durable = array(
			'content_jobs'       => ! in_array( 'content_jobs', $missing_tables, true ),
			'content_job_events' => ! in_array( 'content_job_events', $missing_tables, true ),
			'work_leases'        => ! in_array( 'work_leases', $missing_tables, true ),
			'idempotency'        => ! in_array( 'idempotency', $missing_tables, true ),
			'execution_outbox'   => ! in_array( 'outbox', $missing_tables, true ),
			'execution_inbox'    => ! in_array( 'inbox', $missing_tables, true ),
		);
		return array(
			'contract' => 'mad4b.schema-status.v1',
			'read_only' => true,
			'mutation_performed' => false,
			'expected_version' => isset( $status['expected_version'] ) ? (int) $status['expected_version'] : MAD4B_SCP_Schema::VERSION,
			'installed_version' => isset( $status['installed_version'] ) ? (int) $status['installed_version'] : 0,
			'integrity_token_valid' => ! empty( $status['integrity_token_valid'] ),
			'migration' => array(
				'contract' => isset( $migration['contract'] ) && is_array( $migration['contract'] ) ? $migration['contract'] : array(),
				'contract_sha256' => isset( $migration['contract_sha256'] ) ? (string) $migration['contract_sha256'] : '',
				'preflight' => array(
					'contract' => isset( $preflight['contract'] ) ? (string) $preflight['contract'] : '',
					'migration_id' => isset( $preflight['migration_id'] ) ? (string) $preflight['migration_id'] : '',
					'installed_version' => isset( $preflight['installed_version'] ) ? (int) $preflight['installed_version'] : 0,
					'target_version' => isset( $preflight['target_version'] ) ? (int) $preflight['target_version'] : 0,
					'fresh_install' => ! empty( $preflight['fresh_install'] ),
					'repair_run' => ! empty( $preflight['repair_run'] ),
					'contract_sha256' => isset( $preflight['contract_sha256'] ) ? (string) $preflight['contract_sha256'] : '',
					'blockers' => isset( $preflight['blockers'] ) && is_array( $preflight['blockers'] ) ? array_values( $preflight['blockers'] ) : array(),
					'ready' => ! empty( $preflight['ready'] ),
					'read_only' => ! empty( $preflight['read_only'] ),
					'mutation_performed' => ! empty( $preflight['mutation_performed'] ),
				),
				'receipt' => array(
					'contract' => isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '',
					'migration_id' => isset( $receipt['migration_id'] ) ? (string) $receipt['migration_id'] : '',
					'from_version' => isset( $receipt['from_version'] ) ? (int) $receipt['from_version'] : 0,
					'to_version' => isset( $receipt['to_version'] ) ? (int) $receipt['to_version'] : 0,
					'run_type' => isset( $receipt['run_type'] ) ? (string) $receipt['run_type'] : '',
					'contract_sha256' => isset( $receipt['contract_sha256'] ) ? (string) $receipt['contract_sha256'] : '',
					'target_integrity_token' => isset( $receipt['target_integrity_token'] ) ? (string) $receipt['target_integrity_token'] : '',
					'physical_integrity_sha256' => isset( $receipt['physical_integrity_sha256'] ) ? (string) $receipt['physical_integrity_sha256'] : '',
					'physical_verified' => ! empty( $receipt['physical_verified'] ),
					'readiness_finalized' => ! empty( $receipt['readiness_finalized'] ),
					'destructive' => ! empty( $receipt['destructive'] ),
					'authority_widened' => ! empty( $receipt['authority_widened'] ),
					'completed_at' => isset( $receipt['completed_at'] ) ? (string) $receipt['completed_at'] : '',
				),
				'receipt_valid' => ! empty( $migration['receipt_valid'] ),
			),
			'durable_tables' => $durable,
			'physical_integrity' => array(
				'contract' => isset( $physical['contract'] ) ? (string) $physical['contract'] : '',
				'ready' => ! empty( $physical['ready'] ),
				'missing_tables' => $missing_tables,
				'missing_approval_columns' => isset( $physical['missing_approval_columns'] ) && is_array( $physical['missing_approval_columns'] ) ? array_values( $physical['missing_approval_columns'] ) : array(),
				'missing_durable_columns' => isset( $physical['missing_durable_columns'] ) && is_array( $physical['missing_durable_columns'] ) ? array_values( $physical['missing_durable_columns'] ) : array(),
				'missing_durable_indexes' => isset( $physical['missing_durable_indexes'] ) && is_array( $physical['missing_durable_indexes'] ) ? array_values( $physical['missing_durable_indexes'] ) : array(),
			),
			'ready' => ! empty( $status['ready'] ) && ! empty( $physical['ready'] ),
		);
	}

}
