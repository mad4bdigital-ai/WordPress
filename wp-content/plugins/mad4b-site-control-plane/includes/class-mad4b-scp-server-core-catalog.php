<?php
/** Core server catalog declarations, extracted without changing the dispatch contract. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait MAD4B_SCP_Server_Core_Catalog {
	private static function declared_core_tools( $server_id ) {
        $cso_read = in_array( $server_id, array( 'mad4b-read', 'mad4b-chatgpt' ), true )
            && class_exists( 'MAD4B_SCP_CSO01_Read_Foundation', false )
            ? MAD4B_SCP_CSO01_Read_Foundation::read_ability_names() : array();
		$governed_status = 'mad4b-read' === $server_id ? array( 'mad4b/write-authority-status', 'mad4b/write-authority-reconciliation-plan', 'mad4b/write-runtime-certification', 'mad4b/rest-compatibility-status', 'mad4b/staging-certification-status', 'mad4b/staging-convergence-plan', 'mad4b/staging-write-candidate-binding-plan' ) : array();
		$map = array(
			'mad4b-read' => array_merge( array(
				'mad4b/site-info', 'mad4b/site-profile-status', 'mad4b/environment-journey', 'mad4b/site-autopilot-fast-status', 'mad4b/list-post-types', 'mad4b/post-identity', 'mad4b/list-plugins', 'mad4b/abilities-inventory', 'mad4b/filesystem-list', 'mad4b/filesystem-read',
				'mad4b/database-list-tables', 'mad4b/database-describe-table', 'mad4b/database-select', 'mad4b/diagnostics-health', 'mad4b/security-site-posture', 'mad4b/security-provider-evidence', 'mad4b/runtime-authority-status', 'mad4b/schema-status', 'mad4b/multi-authority-registry-status', 'mad4b/connection-status', 'mad4b/connector-preflight', 'mad4b/session-safe-diagnostics', 'mad4b/read-snapshot-header', 'mad4b/read-diagnostic-bundle', 'mad4b/read-metadata-envelope', 'mad4b/context-authority-status',
				'mad4b/plugin-lifecycle-plan', 'mad4b/plugin-package-plan', 'mad4b/plugin-remote-update-plan', 'mad4b/plugin-deployment-sources', 'mad4b/plugin-deployment-plan', 'mad4b/plugin-deployment-intake-plan', 'mad4b/control-plane-update-status', 'mad4b/control-plane-upload-plan', 'mad4b/control-plane-native-plan', 'mad4b/control-plane-selected-head-plan', 'mad4b/workflow-provider-status', 'mad4b/workflow-plan', 'mad4b/runtime-functional-gap-diagnostic', 'mad4b/code-snippets-rest-bootstrap-diagnostic',
				'mad4b/operating-model-status', 'mad4b/semantic-identity-map', 'mad4b/site-feature-bundle-validate', 'mad4b/state-diff', 'mad4b/operation-plan', 'mad4b/evidence-invalidation-plan', 'mad4b/invariant-evaluate', 'mad4b/candidate-state', 'mad4b/workflow-compile',
				'mad4b/capability-trait-profile', 'mad4b/capability-trait-resolve', 'mad4b/provider-execution-binding', 'mad4b/provider-execution-binding-revalidate', 'mad4b/addon-registry-status',
				'mad4b/data-processing-evaluate', 'mad4b/rights-record-plan', 'mad4b/data-processing-profile-plan', 'mad4b/rights-takedown-plan',
				'mad4b/research-provider-plan',
				'mad4b/decommission-preflight', 'mad4b/decommission-live-inventory', 'mad4b/decommission-governance-status', 'mad4b/decommission-quiesce-plan', 'mad4b/decommission-resume-plan', 'mad4b/decommission-finalize-plan', 'mad4b/portability-import-quarantine-plan', 'mad4b/portability-import-quarantine-status',
				'mad4b/export-bundle-build', 'mad4b/import-bundle-validate',
				'mad4b/scheduler-admission-evaluate',
				'mad4b/scheduler-fair-rank', 'mad4b/scheduler-backlog-status',
				'mad4b/optional-governance-status', 'mad4b/optional-governance-plan', 'mad4b/optional-governance-verify', 'mad4b/operator-doctor', 'mad4b/operator-dead-letter-status', 'mad4b/provider-candidate-matrix', 'mad4b/adapter-candidates', 'mad4b/plugin-capability-graph', 'mad4b/plugin-capability-route-plan', 'mad4b/native-mcp-candidates', 'mad4b/native-mcp-catalog', 'mad4b/native-mcp-hybrid-plan', 'mad4b/native-mcp-read-call', 'mad4b/native-mcp-hybrid-read', 'mad4b/production-certification-readonly-evidence', 'mad4b/production-certification-status', 'mad4b/production-certification-plan', 'mad4b/feature-007-workstream-certification-status', 'mad4b/governed-provider-plan-build', 'mad4b/governed-provider-plan-revalidate', 'mad4b/production-readiness-evaluate', 'mad4b/test-attestation-verify',
				'mad4b/site-bootstrap-snapshot',
				'mad4b/remote-operation-parity-status', 'mad4b/operation-discover', 'mad4b/operation-route-discover', 'mad4b/operation-route-plan', 'mad4b/provider-closure-matrix', 'mad4b/remote-operation-work-queue', 'mad4b/operation-classify',
                'mad4b/brand-core-remote-recovery-status', 'mad4b/brand-core-remote-recovery-prepare', 'mad4b/brand-core-recovery-alternatives', 'mad4b/context-source-onboarding-preflight',
                'mad4b/native-runtime-diagnostics', 'mad4b/native-recovery-status', 'mad4b/native-recovery-plan', 'mad4b/native-recovery-readback', 'mad4b/operator-journeys', 'mad4b/recovery-route-matrix', 'mad4b/deployment-binding-production-status',
                'mad4b/admin-surface-coverage', 'mad4b/admin-adapter-blueprint', 'mad4b/admin-control-census', 'mad4b/operational-acceptance-evidence',
                'mad4b/operational-remediation-status', 'mad4b/operational-remediation-prepare',
                'mad4b/root-cause-remediation-plan',
                'mad4b/deployment-binding-setup-status', 'mad4b/deployment-binding-setup-plan',
                'mad4b/deployment-binding-native-status', 'mad4b/deployment-binding-native-plan',
                'mad4b/staging-write-owner-review',
                'mad4b/staging-write-owner-reconciliation-diagnose',
                'mad4b/host-doctor', 'mad4b/host-operation-capabilities', 'mad4b/host-operation-plan',
                'mad4b/host-operation-status', 'mad4b/host-operation-receipt',
			), class_exists( 'MAD4B_SCP_Operation_Registry' ) ? MAD4B_SCP_Operation_Registry::read_projection( 'catalog' ) : array(), $governed_status, $cso_read ),
			'mad4b-chatgpt' => array_merge( array(
				'mad4b/site-info', 'mad4b/site-profile-status', 'mad4b/environment-journey', 'mad4b/site-autopilot-fast-status',
				'mad4b/session-safe-diagnostics', 'mad4b/optional-governance-status', 'mad4b/optional-governance-plan', 'mad4b/optional-governance-verify',
                'mad4b/recovery-route-matrix', 'mad4b/deployment-binding-production-status',
                'mad4b/staging-write-owner-review',
				'mad4b/full-staging-authority-handshake',
				'mad4b/runtime-gates-handshake',
				'mad4b/tool-discover', 'mad4b/tool-info', 'mad4b/read-execute',
				'mad4b/write-discover', 'mad4b/write-info', 'mad4b/write-execute',
                // Approval bootstrap cannot depend solely on the nested write
                // dispatcher: some MCP hosts expose its fixed typed tools but
                // do not admit the nested approval-plan result. Expose ONLY
                // the two already-governed approval abilities directly.
                // Transport_Context still forces them to mad4b-write; all
                // exact NHI grants, OAuth scopes, budgets, candidate-bound
                // ticket proof, AI delegation and execution claims still run.
                'mad4b/approval-plan', 'mad4b/approval-ai-decide',
				'mad4b/developer-discover', 'mad4b/developer-info', 'mad4b/developer-execute',
				'mad4b/enrollment-discover', 'mad4b/enrollment-info', 'mad4b/enrollment-execute',
				'mad4b/chatgpt-tool-projection-status', 'mad4b/chatgpt-tool-projection-discover', 'mad4b/chatgpt-tool-projection-plan', 'mad4b/chatgpt-tool-projection-recovery-plan', 'mad4b/chatgpt-tool-projection-apply', 'mad4b/frontend-origin-diagnostic',
				'mad4b/plugin-package-plan', 'mad4b/plugin-remote-update-plan', 'mad4b/control-plane-upload-plan', 'mad4b/control-plane-native-plan', 'mad4b/control-plane-selected-head-plan',
				'mad4b/operation-discover', 'mad4b/operation-route-discover', 'mad4b/operation-route-plan', 'mad4b/provider-closure-matrix', 'mad4b/provider-candidate-matrix', 'mad4b/plugin-capability-graph', 'mad4b/plugin-capability-route-plan', 'mad4b/native-mcp-candidates', 'mad4b/native-mcp-catalog', 'mad4b/native-mcp-hybrid-plan', 'mad4b/native-mcp-read-call', 'mad4b/native-mcp-hybrid-read', 'mad4b/production-certification-readonly-evidence', 'mad4b/production-certification-status', 'mad4b/production-certification-plan', 'mad4b/feature-007-workstream-certification-status', 'mad4b/governed-provider-plan-build', 'mad4b/governed-provider-plan-revalidate', 'mad4b/production-readiness-evaluate', 'mad4b/test-attestation-verify',
			), class_exists( 'MAD4B_SCP_Operation_Registry' ) ? MAD4B_SCP_Operation_Registry::read_projection( 'direct' ) : array(), $governed_status, $cso_read ),
			'mad4b-enrollment' => array_values( array_unique( array_merge(
				array(
					'mad4b/site-info',
					'mad4b/site-profile-status',
					'mad4b/build-provenance-status',
					'mad4b/multi-authority-registry-status',
					'mad4b/site-profile-feature-reenroll',
					'mad4b/site-profile-write-enable',
					'mad4b/staging-write-grant-reconciliation-plan',
					'mad4b/staging-write-grant-reconcile',
					'mad4b/staging-write-candidate-bind',
					'mad4b/staging-write-candidate-binding-audit',
					'mad4b/runtime-convergence-apply',
				),
				'mad4b-enrollment' === $server_id && class_exists( 'MAD4B_SCP_Remote_Operation_Parity' ) && method_exists( 'MAD4B_SCP_Remote_Operation_Parity', 'enrollment_abilities' )
					? MAD4B_SCP_Remote_Operation_Parity::enrollment_abilities()
					: array(),
				class_exists( 'MAD4B_SCP_Staging_Write_Authority_Convergence' ) ? MAD4B_SCP_Staging_Write_Authority_Convergence::enrollment_tools() : array(),
                class_exists( 'MAD4B_SCP_Staging_Write_Owner_Intent' ) ? array( MAD4B_SCP_Staging_Write_Owner_Intent::ATTEST_ABILITY ) : array(),
				class_exists( 'MAD4B_SCP_Developer_Authority' ) ? MAD4B_SCP_Developer_Authority::enrollment_tools() : array(),
				class_exists( 'MAD4B_SCP_Full_Staging_Authority' ) ? MAD4B_SCP_Full_Staging_Authority::enrollment_tools() : array(),
				class_exists( 'MAD4B_SCP_Governed_Runtime_Gates' ) ? MAD4B_SCP_Governed_Runtime_Gates::enrollment_tools() : array()
			) ) ),
			'mad4b-content' => array(
				'mad4b/content-get-post', 'mad4b/content-update-post',
				'mad4b/content-job-list', 'mad4b/content-job-get', 'mad4b/content-job-events',
				'mad4b/intent-registry-current', 'mad4b/intent-conflicts-analyze', 'mad4b/intent-bootstrap-plan',
				'mad4b/draft-plan', 'mad4b/draft-verify', 'mad4b/publication-verification-evaluate',
			),
			'mad4b-admin' => array(
				'mad4b/standalone-build-request',
                // WordPress-native build START is contributed solely by its
                // registered first-party adapter, not by this fixed catalog.
                // Bounded Staging bootstrap lane. Mounting
                // does NOT grant execution: exact NHI grants, owner OAuth
                // step-up, ticket, Site Profile and source/ZIP checks still apply.
                'mad4b/wordpress-native-candidate-opt-in-apply',
                'mad4b/wp-native-self-approve-install',
				'mad4b/provider-deep-diagnostic',
				'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/plugin-package-apply', 'mad4b/plugin-remote-update-apply', 'mad4b/control-plane-upload-apply', 'mad4b/control-plane-native-apply', 'mad4b/control-plane-selected-head-apply', 'mad4b/runtime-release-set-apply', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/audit-tail',
				'mad4b/mutation-get', 'mad4b/mutation-undo', 'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan',
				'mad4b/recovery-preview', 'mad4b/agent-access-workspace', 'mad4b/consent-profile-status', 'mad4b/change-history-search', 'mad4b/agent-permission-plan', 'mad4b/agent-permission-apply',
			),
			'mad4b-developer' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::tool_names( false ) : array(),
			'mad4b-developer-breakglass' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::tool_names( true ) : array(),
			'mad4b-breakglass' => array( 'mad4b/database-raw-query' ),
		);
		if ( 'mad4b-write' === $server_id ) return self::write_tools();
		$tools = isset( $map[ $server_id ] ) ? $map[ $server_id ] : array();
		// Mount only the resolver registration created by this plugin. Never
		// widen meta.mcp.public for the default server or trust a name collision.
		if ( 'mad4b-read' === $server_id && class_exists( 'MAD4B_SCP_Deployment_Mode_Resolver' ) ) {
			$binding = MAD4B_SCP_Deployment_Mode_Resolver::mcp_registration_status();
			if ( ! empty( $binding['ready'] ) ) $tools[] = MAD4B_SCP_Deployment_Mode_Resolver::ABILITY;
		}
		if ( 'mad4b-read' === $server_id && class_exists( 'MAD4B_SCP_Adaptive_Search_Intelligence' ) ) $tools = array_merge( $tools, MAD4B_SCP_Adaptive_Search_Intelligence::ability_names( 'read' ) );
		// Acceptance cores register their own WordPress Abilities. They are
		// first-party source-owned read definitions, not provider adapters.
		// Do not route them through anonymous Adapter_Base registrations: the
		// reviewed class/path trust policy correctly rejects anonymous classes.
		if ( 'mad4b-read' === $server_id ) {
			$tools[] = MAD4B_SCP_Admin_Remote_Parity::ABILITY;
			foreach ( array( 'MAD4B_SCP_Acceptance_Core', 'MAD4B_SCP_Browser_Acceptance_Core' ) as $core ) {
				if ( class_exists( $core, false ) && method_exists( $core, 'read_ability_names' ) ) {
					$tools = array_merge( $tools, $core::read_ability_names() );
				}
			}
		}
		if ( 'mad4b-admin' === $server_id
			&& class_exists( 'MAD4B_SCP_Context_Authority' )
			&& function_exists( 'wp_has_ability' )
			&& wp_has_ability( MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY ) ) {
			$tools[] = MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY;
		}
		return array_values( array_unique( $tools ) );
	}
}
