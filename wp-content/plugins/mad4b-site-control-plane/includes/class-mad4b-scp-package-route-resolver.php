<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Read-only routing before any package plan. No grant, network call or install. */
final class MAD4B_SCP_Package_Route_Resolver {
    public static function resolve( $target, $environment, $certified_providers ) {
        $target = strtolower( trim( (string) $target ) );
        $environment = strtolower( trim( (string) $environment ) );
        $self = in_array( $target, array( 'mad4b', 'mad4b-site-control-plane', 'mad4b_site_control_plane' ), true );
        $route = 'none';
        $ability = '';
        $blockers = array();
        if ( '' === $target ) {
            $blockers[] = 'target_required';
        } elseif ( $self ) {
            $route = 'control_plane_exact_head';
            $ability = 'mad4b/control-plane-selected-head-plan';
        } elseif ( is_array( $certified_providers ) && array_key_exists( $target, $certified_providers ) ) {
            $route = 'certified_provider_package';
            $ability = 'mad4b/plugin-package-plan';
        } else {
            $blockers[] = 'uncertified_provider';
        }
        if ( 'staging' !== $environment ) $blockers[] = 'staging_required';
        return array(
            'contract' => 'mad4b.package-route-resolution.v1',
            'target' => $target,
            'environment' => $environment,
            'route' => $route,
            'plan_ability' => $ability,
            'blockers' => $blockers,
            'eligible_to_plan' => '' !== $ability && empty( $blockers ),
            'auto_apply_allowed' => false,
            'caller_supplied_package_allowed' => false,
            'mutation_performed' => false,
        );
    }
}
