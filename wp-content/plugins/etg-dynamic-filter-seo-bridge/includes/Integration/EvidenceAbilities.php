<?php
namespace ETG\DynamicFilterSEOBridge\Integration;

use ETG\DynamicFilterSEOBridge\Diagnostics\EvidenceProvider;

/**
 * WordPress Abilities API export for the bounded ETG evidence provider.
 *
 * WordPress/Core/MCP own discovery, authentication and transport. This bridge
 * only makes the existing provider callable through a governed, read-only
 * public ability surface; it does not duplicate ETG diagnostic semantics.
 */
final class EvidenceAbilities {
    const CATEGORY = 'etg-dfsb-diagnostics';
    const DESCRIPTOR_ABILITY = 'etg-dfsb/evidence-provider';
    const QUERY_ABILITY = 'etg-dfsb/evidence-query';

    /** @var EvidenceProvider */
    private $provider;

    public function __construct( EvidenceProvider $provider ) {
        $this->provider = $provider;
    }

    public function register(): void {
        if ( ! function_exists( 'add_action' ) ) { return; }
        add_action( 'wp_abilities_api_categories_init', array( $this, 'registerCategory' ) );
        add_action( 'wp_abilities_api_init', array( $this, 'registerAbilities' ) );
    }

    public function registerCategory(): void {
        if ( ! function_exists( 'wp_register_ability_category' ) ) { return; }
        if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) { return; }

        wp_register_ability_category(
            self::CATEGORY,
            array(
                'label' => 'ETG DFSB Diagnostics',
                'description' => 'Read-only bounded diagnostic evidence for ETG Dynamic Filter SEO Bridge.',
            )
        );
    }

    public function registerAbilities(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) { return; }

        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::DESCRIPTOR_ABILITY ) ) {
            wp_register_ability(
                self::DESCRIPTOR_ABILITY,
                array(
                    'label' => 'ETG Evidence Provider',
                    'description' => 'Returns the versioned, read-only ETG evidence provider descriptor and bounded section contract.',
                    'category' => self::CATEGORY,
                    'output_schema' => $this->objectOutputSchema(),
                    'execute_callback' => array( $this, 'executeDescriptor' ),
                    'permission_callback' => array( $this, 'canReadEvidence' ),
                    'meta' => $this->readOnlyMeta(),
                )
            );
        }

        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::QUERY_ABILITY ) ) {
            wp_register_ability(
                self::QUERY_ABILITY,
                array(
                    'label' => 'Query ETG Evidence',
                    'description' => 'Queries one bounded ETG diagnostic evidence section. The ETG EvidenceProvider remains the canonical source of validation and semantics.',
                    'category' => self::CATEGORY,
                    'input_schema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'section' => array(
                                'type' => 'string',
                                'enum' => array( 'summary', 'unresolved_surfaces', 'filters', 'profile_reconciliation', 'provider_group_drift' ),
                            ),
                            'offset' => array( 'type' => 'integer', 'minimum' => 0 ),
                            'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => EvidenceProvider::MAX_PAGE_SIZE ),
                            'filter_ids' => array(
                                'type' => 'array',
                                'items' => array( 'type' => 'integer' ),
                            ),
                            'profile_id' => array( 'type' => 'string' ),
                            'template_id' => array( 'type' => array( 'integer', 'string' ) ),
                            'node_id' => array( 'type' => array( 'integer', 'string' ) ),
                        ),
                        'required' => array( 'section' ),
                        'additionalProperties' => false,
                    ),
                    'output_schema' => $this->objectOutputSchema(),
                    'execute_callback' => array( $this, 'executeQuery' ),
                    'permission_callback' => array( $this, 'canReadEvidence' ),
                    'meta' => $this->readOnlyMeta(),
                )
            );
        }
    }

    public function canReadEvidence( $input = null ): bool {
        unset( $input );
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    }

    public function executeDescriptor( $input = null ): array {
        unset( $input );
        return $this->provider->descriptor();
    }

    public function executeQuery( $input = array() ): array {
        if ( ! is_array( $input ) ) {
            return array(
                'contract' => EvidenceProvider::CONTRACT,
                'provider_id' => EvidenceProvider::PROVIDER_ID,
                'authorizing' => false,
                'read_only' => true,
                'profile_mutation' => false,
                'section' => '',
                'state' => 'invalid_request',
                'errors' => array( 'request_must_be_object' ),
                'payload' => array(),
            );
        }
        return $this->provider->query( $input );
    }

    private function readOnlyMeta(): array {
        return array(
            'public' => true,
            'show_in_rest' => true,
            'annotations' => array(
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ),
            'etg_contract' => EvidenceProvider::CONTRACT,
            'authorizing' => false,
            'profile_mutation' => false,
        );
    }

    private function objectOutputSchema(): array {
        return array(
            'type' => 'object',
            'additionalProperties' => true,
        );
    }
}
