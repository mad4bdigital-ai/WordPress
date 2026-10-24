<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reviewed ChatGPT approval convenience tools. Not an authority grant.
 * This selector is evaluated against the enrolled Staging Write mount.
 */
final class MAD4B_SCP_ChatGPT_Approval_Bootstrap {
    public static function catalog_tools() {
        // Do not materialize Write schemas solely because a Site Profile is new.
        // First binding remains an explicit WordPress admin or dispatcher action.
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || 'staging' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() )
            || ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
            || ! MAD4B_SCP_Staging_Write_Authority::effective() )
            return array();
        $names = array( 'mad4b/approval-plan' );
        if ( class_exists( 'MAD4B_SCP_AI_Approval' )
            && MAD4B_SCP_AI_Approval::catalog_eligible() )
            $names[] = 'mad4b/approval-ai-decide';
        $result = array();
        foreach ( $names as $ability_name ) {
            if ( ! function_exists( 'wp_has_ability' )
                || ! function_exists( 'wp_get_ability' )
                || ! wp_has_ability( $ability_name )
                || ! class_exists( 'MAD4B_SCP_Servers' )
                || ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name ) )
                continue;
            $ability = wp_get_ability( $ability_name );
            $meta = is_object( $ability ) && method_exists( $ability, 'get_meta' )
                ? $ability->get_meta() : array();
            $annotations = is_array( $meta ) && isset( $meta['annotations'] )
                && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
            if ( array_key_exists( 'readonly', $annotations )
                && false === $annotations['readonly'] )
                $result[] = $ability_name;
        }
        return $result;
    }

    /** Classification used by both catalog preflight and actual registration. */
    public static function materialization_optional_tools( array $requested_tools ) {
        $dynamic_optional = class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' )
            ? MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names() : array();
        $reviewed = MAD4B_SCP_Servers::chatgpt_reviewed_direct_step_up_tools();
        // Keep a reviewed management escape hatch if projected optional tools
        // otherwise crowd it out. This never creates execution authority.
        $management = array_values( array_intersect(
            array( 'mad4b/chatgpt-tool-projection-apply' ), $reviewed
        ) );
        $profile_tools = class_exists( 'MAD4B_SCP_Direct_Profile' )
            ? MAD4B_SCP_Direct_Profile::catalog_tools() : array();
        return array_values( array_intersect(
            array_values( array_unique( array_merge(
                $management, $dynamic_optional, $reviewed, $profile_tools
            ) ) ),
            $requested_tools
        ) );
    }
}
