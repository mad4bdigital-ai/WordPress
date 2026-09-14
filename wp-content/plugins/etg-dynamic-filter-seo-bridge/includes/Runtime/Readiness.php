<?php
namespace ETG\DynamicFilterSEOBridge\Runtime;

require_once __DIR__ . '/CapabilityRequirements.php';
require_once __DIR__ . '/AdapterRegistry.php';

use ETG\DynamicFilterSEOBridge\Compatibility;
use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;

final class Readiness {
    private $compatibility;
    private $config;
    private $profiles;

    public function __construct( Compatibility $compatibility, Configuration $config, ProfileRegistry $profiles ) {
        $this->compatibility = $compatibility;
        $this->config = $config;
        $this->profiles = $profiles;
    }

    public function report(): array {
        $compat = $this->compatibility->report();
        $profiles = $this->profiles->all();
        $requirements = CapabilityRequirements::forProfiles( $profiles, true );
        $allRequirements = CapabilityRequirements::forProfiles( $profiles, false );

        $missingDependencies = array();
        foreach ( $requirements['dependencies'] as $dependency ) {
            if ( empty( $compat[ $dependency ] ) ) { $missingDependencies[] = $dependency; }
        }

        $missingCapabilities = array();
        $observedCapabilities = (array) ( $compat['capabilities'] ?? array() );
        foreach ( $requirements['capabilities'] as $capability ) {
            if ( empty( $observedCapabilities[ $capability ] ) ) { $missingCapabilities[] = $capability; }
        }

        $configErrors = array_merge( $this->config->validationErrors(), $this->profiles->validationErrors() );
        foreach ( (array) $requirements['unsupported_providers'] as $unsupported ) {
            $configErrors[] = 'unsupported_provider:' . $unsupported;
        }
        foreach ( (array) $requirements['unsupported_semantic_capabilities'] as $unsupported ) {
            $configErrors[] = 'unsupported_semantic_capability:' . $unsupported;
        }
        $configErrors = array_values( array_unique( $configErrors ) );

        $runtimeChecks = array();
        $pending = ! function_exists( 'did_action' ) || did_action( 'init' ) < 1;
        if ( ! $pending ) {
            foreach ( $profiles as $profileId => $profile ) {
                if ( empty( $profile['enabled'] ) ) { continue; }
                $postTypes = (array) ( $profile['post_types'] ?? array() );
                if ( function_exists( 'post_type_exists' ) ) {
                    foreach ( $postTypes as $postType ) { $runtimeChecks['post_type:' . $profileId . ':' . $postType] = post_type_exists( $postType ); }
                }
                foreach ( array_keys( (array) ( $profile['taxonomy_rules'] ?? array() ) ) as $taxonomy ) {
                    if ( function_exists( 'taxonomy_exists' ) ) { $runtimeChecks['taxonomy:' . $profileId . ':' . $taxonomy] = taxonomy_exists( $taxonomy ); }
                    if ( $postTypes && function_exists( 'get_taxonomy' ) ) {
                        $object = get_taxonomy( $taxonomy );
                        $objectTypes = is_object( $object ) && isset( $object->object_type ) ? (array) $object->object_type : array();
                        $runtimeChecks['taxonomy_relation:' . $profileId . ':' . $taxonomy] = (bool) array_intersect( $postTypes, $objectTypes );
                    }
                }
            }
        }
        $failedRuntime = array();
        foreach ( $runtimeChecks as $name => $ok ) { if ( ! $ok ) { $failedRuntime[] = $name; } }

        $enabledProfiles = array_values( array_keys( array_filter( $profiles, static function ( $profile ) { return ! empty( $profile['enabled'] ); } ) ) );
        $publicationProfiles = array();
        $darkValidationProfiles = array();
        foreach ( $profiles as $profileId => $profile ) {
            if ( ! empty( $profile['enabled'] ) && CapabilityRequirements::profileNeedsPublication( $profile ) ) { $publicationProfiles[] = (string) $profileId; }
            if ( ! empty( $profile['publication']['require_elementor_content'] ) ) { $darkValidationProfiles[] = (string) $profileId; }
        }

        $globalEnabled = $this->config->enabled();
        if ( ! $globalEnabled ) {
            $status = 'inactive';
        } elseif ( $missingDependencies || $missingCapabilities || $configErrors || $pending || $failedRuntime ) {
            $status = 'degraded';
        } else {
            $status = 'ready';
        }

        $adapterStatus = ( $missingDependencies || $missingCapabilities ) ? 'degraded' : 'ready';
        $profileStatus = $configErrors ? 'invalid' : ( $enabledProfiles ? 'ready' : 'inactive' );
        $publicationStatus = ! $publicationProfiles || ! $globalEnabled ? 'inactive' : ( 'ready' === $adapterStatus && ! $configErrors && ! $pending && ! $failedRuntime ? 'ready' : 'degraded' );

        return array(
            'contract' => 'etg.dfsb.readiness.v5',
            'status' => $status,
            'core_status' => $configErrors ? 'degraded' : 'ready',
            'adapter_status' => $adapterStatus,
            'profile_status' => $profileStatus,
            'publication_status' => $publicationStatus,
            'configuration_revision' => $this->config->revision(),
            'profile_count' => count( $profiles ),
            'enabled_profiles' => $enabledProfiles,
            'publication_profiles' => $publicationProfiles,
            'dark_validation_profiles' => array_values( array_unique( $darkValidationProfiles ) ),
            'publication_requires_elementor_pro' => in_array( 'elementor_pro', $requirements['dependencies'], true ),
            'required_semantic_capabilities' => $requirements['semantic_capabilities'],
            'required_adapters' => $requirements['adapters'],
            'required_dependencies' => $requirements['dependencies'],
            'required_capabilities' => $requirements['capabilities'],
            'missing_dependencies' => $missingDependencies,
            'missing_capabilities' => $missingCapabilities,
            'unsupported_providers' => $requirements['unsupported_providers'],
            'unsupported_semantic_capabilities' => $requirements['unsupported_semantic_capabilities'],
            'profile_requirements' => $allRequirements['profiles'],
            'configuration_errors' => $configErrors,
            'runtime_checks_pending' => $pending,
            'runtime_checks' => $runtimeChecks,
            'failed_runtime_checks' => $failedRuntime,
            'migration' => $this->config->migrationStatus(),
            'compatibility' => $compat,
        );
    }

    public function ready(): bool {
        $report = $this->report();
        return 'ready' === $report['status'];
    }
}
