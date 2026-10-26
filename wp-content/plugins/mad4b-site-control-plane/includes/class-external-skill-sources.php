<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded, source-backed discovery of third-party Agent Skills.
 *
 * The inventory is external and untrusted. It is never copied into the
 * managed Skill registry, installed, executed or granted any authority.
 * Repository ownership and Git references are configuration, not code.
 */
final class SCP_External_Skill_Sources {
    const OPTION = 'scp_external_skill_sources_v1';
    const CONTRACT = 'external-skill-sources.v1';
    const MAX_SOURCES = 40;
    const MAX_SKILLS = 500;
    const MAX_TREE_ENTRIES = 10000;
    const MAX_TREE_BYTES = 4194304;
    const MAX_FILE_BYTES = 262144;
    const CACHE_GROUP = 'scp_external_skills';

    public static function sources() {
        $saved = get_option( self::OPTION, array() );
        if ( ! is_array( $saved ) ) return array();
        $sources = array();
        foreach ( array_slice( $saved, 0, self::MAX_SOURCES ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $valid = self::validate_source( $row['repository'] ?? '', $row['ref'] ?? '' );
            if ( is_wp_error( $valid ) ) continue;
            $sources[ $valid['id'] ] = $valid;
        }
        return array_values( $sources );
    }

    private static function validate_source( $repository, $ref ) {
        $repository = trim( (string) $repository );
        $ref = trim( (string) $ref );
        if ( strlen( $repository ) > 120 || ! preg_match( '~\A[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*\z~D', $repository ) ) {
            return new WP_Error( 'skill_source_repository_invalid', 'Use a GitHub owner/repository identifier, not a URL.' );
        }
        if ( strlen( $ref ) > 120 || ! preg_match( '~\A[A-Za-z0-9][A-Za-z0-9._/-]*\z~D', $ref ) || false !== strpos( $ref, '..' ) || false !== strpos( $ref, '//' ) || '/' === substr( $ref, -1 ) ) {
            return new WP_Error( 'skill_source_ref_invalid', 'The Git reference is invalid.' );
        }
        return array(
            'id' => substr( hash( 'sha256', strtolower( $repository ) . "\n" . $ref ), 0, 24 ),
            'repository' => $repository,
            'ref' => $ref,
            'provider' => 'github',
        );
    }

    public static function save_source( $repository, $ref ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'skill_source_access_denied', 'Administrator permission required.' );
        $valid = self::validate_source( $repository, $ref );
        if ( is_wp_error( $valid ) ) return $valid;
        $sources = self::sources();
        foreach ( $sources as $existing ) if ( hash_equals( $existing['id'], $valid['id'] ) ) return $valid;
        if ( count( $sources ) >= self::MAX_SOURCES ) return new WP_Error( 'skill_source_limit', 'Too many configured sources.' );
        $sources[] = $valid;
        if ( ! update_option( self::OPTION, $sources, false ) ) return new WP_Error( 'skill_source_save_failed', 'Source configuration was not saved.' );
        return $valid;
    }

    public static function remove_source( $id ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'skill_source_access_denied', 'Administrator permission required.' );
        $sources = self::sources();
        $found = false;
        $keep = array();
        foreach ( $sources as $row ) {
            if ( hash_equals( $row['id'], (string) $id ) ) { $found = true; continue; }
            $keep[] = $row;
        }
        if ( ! $found ) return new WP_Error( 'skill_source_not_found', 'Source was not found.' );
        if ( ! update_option( self::OPTION, $keep, false ) ) return new WP_Error( 'skill_source_remove_failed', 'Source configuration was not changed.' );
        return true;
    }

    public static function list_sources( $input = array() ) {
        $sources = self::sources();
        return array( 'contract' => self::CONTRACT, 'count' => count( $sources ), 'sources' => $sources, 'mode' => 'read_only', 'installation' => false );
    }

    private static function source( $id ) {
        $id = (string) $id;
        if ( ! preg_match( '/\A[a-f0-9]{24}\z/D', $id ) ) return new WP_Error( 'skill_source_not_found', 'Unknown source.' );
        foreach ( self::sources() as $row ) if ( hash_equals( $row['id'], $id ) ) return $row;
        return new WP_Error( 'skill_source_not_found', 'Unknown source.' );
    }


    /** Reviewed public provider endpoint, never a tenant or caller URL. */
    private static function github_origin() {
        $file = MAD4B_SCP_DIR . 'config/external-skill-source-providers.json';
        if ( ! is_readable( $file ) ) return new WP_Error( 'skill_source_provider_missing', 'The source provider configuration is unavailable.' );
        $config = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $config ) || ( $config['contract'] ?? '' ) !== 'external-skill-source-providers.v1' ) {
            return new WP_Error( 'skill_source_provider_invalid', 'The source provider configuration is invalid.' );
        }
        $origin = $config['providers']['github']['origin'] ?? '';
        $parts = is_string( $origin ) ? parse_url( $origin ) : false;
        if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' || empty( $parts['host'] )
            || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] )
            || isset( $parts['path'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
            || ! preg_match( '/\A[a-z0-9.-]+\z/D', $parts['host'] ) ) {
            return new WP_Error( 'skill_source_provider_origin_invalid', 'The source provider origin is not a valid reviewed HTTPS authority.' );
        }
        return $origin;
    }

    private static function github_json( $url, $limit, $deadline = null ) {
        // One request deadline is shared by discovery and all advisory reads.
        if ( null !== $deadline && ! ( is_float( $deadline ) || is_int( $deadline ) )
            || null !== $deadline && ! is_finite( (float) $deadline ) ) {
            return new WP_Error( 'skill_source_deadline_invalid', 'Invalid read deadline.' );
        }
        $remaining = null === $deadline ? 12 : (int) floor( $deadline - microtime( true ) );
        if ( $remaining < 1 ) {
            return new WP_Error( 'skill_source_budget_exhausted', 'Source read budget exhausted.' );
        }
        if ( ! class_exists( 'MAD4B_SCP_Egress_Policy' ) ) return new WP_Error( 'skill_source_egress_unavailable', 'Outbound security policy is unavailable.' );
        $origin = self::github_origin();
        if ( is_wp_error( $origin ) ) return $origin;
        if ( 0 !== strpos( $url, $origin . '/repos/' ) ) return new WP_Error( 'skill_source_endpoint_invalid', 'Only the reviewed source provider API is permitted.' );
        $args = array(
            'timeout' => min( 12, $remaining ),
            'redirection' => 0,
            'sslverify' => true,
            'reject_unsafe_urls' => true,
            'limit_response_size' => $limit + 1,
            'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'Skill-Source-Explorer' ),
        );
        $args = MAD4B_SCP_Egress_Policy::mark_request( 'skill_source_discovery', $url, $origin, $args );
        if ( is_wp_error( $args ) ) return $args;
        MAD4B_SCP_Egress_Policy::boot();
        if ( null !== $deadline ) {
            $remaining = (int) floor( $deadline - microtime( true ) );
            if ( $remaining < 1 ) return new WP_Error( 'skill_source_budget_exhausted', 'Source read budget exhausted.' );
            $args['timeout'] = min( (float) $args['timeout'], 12, $remaining );
        }
        $response = wp_safe_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) return new WP_Error( 'skill_source_transport_unavailable', 'GitHub request failed.' );
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) return new WP_Error( 'skill_source_upstream_rejected', 'GitHub did not return the requested public resource.', array( 'status' => $code ) );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( strlen( $body ) > $limit ) return new WP_Error( 'skill_source_response_too_large', 'GitHub response exceeds the size budget.' );
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) return new WP_Error( 'skill_source_response_invalid', 'GitHub response is not valid JSON.' );
        return $decoded;
    }

    private static function tree( array $source, $deadline = null ) {
        $key = 'tree_' . $source['id'];
        $cached = wp_cache_get( $key, self::CACHE_GROUP );
        if ( is_array( $cached ) && isset( $cached['sha'], $cached['skills'], $cached['files'] ) ) return $cached;
        $origin = self::github_origin();
        if ( is_wp_error( $origin ) ) return $origin;
        $url = $origin . '/repos/' . $source['repository'] . '/git/trees/' . rawurlencode( $source['ref'] ) . '?recursive=1';
        $tree = self::github_json( $url, self::MAX_TREE_BYTES, $deadline );
        if ( is_wp_error( $tree ) ) return $tree;
        if ( empty( $tree['sha'] ) || ! preg_match( '/\A[a-f0-9]{40}\z/D', $tree['sha'] ) || ! isset( $tree['tree'] ) || ! is_array( $tree['tree'] ) || ! empty( $tree['truncated'] ) ) {
            return new WP_Error( 'skill_source_tree_incomplete', 'The upstream Git tree is incomplete or invalid.' );
        }
        // Git trees are untrusted input: MAX_TREE_BYTES limits wire size but
        // not decoded PHP array/cardinality and subsequent indexed copies.
        if ( count( $tree['tree'] ) > self::MAX_TREE_ENTRIES ) {
            return new WP_Error( 'skill_source_tree_entry_limit', 'The upstream tree has too many entries for safe discovery.' );
        }
        $files = array();
        $skills = array();
        foreach ( $tree['tree'] as $entry ) {
            if ( ! is_array( $entry ) || ( $entry['type'] ?? '' ) !== 'blob' ) continue;
            $path = $entry['path'] ?? '';
            $sha = $entry['sha'] ?? '';
            if ( ! self::valid_path( $path ) || ! is_string( $sha ) || ! preg_match( '/\A[a-f0-9]{40}\z/D', $sha ) ) continue;
            $files[ $path ] = array( 'sha' => $sha, 'size' => isset( $entry['size'] ) ? (int) $entry['size'] : -1 );
            if ( 'SKILL.md' !== basename( $path ) ) continue;
            $skills[] = array( 'name' => basename( dirname( $path ) ), 'path' => $path, 'blob_sha' => $sha );
            if ( count( $skills ) > self::MAX_SKILLS ) return new WP_Error( 'skill_source_skill_limit', 'The Skill inventory exceeds the safe limit.' );
        }
        usort( $skills, function ( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
        $result = array( 'sha' => $tree['sha'], 'skills' => $skills, 'files' => $files );
        wp_cache_set( $key, $result, self::CACHE_GROUP, 300 );
        return $result;
    }

    private static function valid_path( $path ) {
        if ( ! is_string( $path ) || '' === $path || strlen( $path ) > 512 || '\\' === substr( $path, 0, 1 ) ) return false;
        foreach ( explode( '/', $path ) as $part ) {
            if ( '' === $part || '.' === $part || '..' === $part || false !== strpos( $part, "\0" ) || false !== strpos( $part, '\\' ) ) return false;
        }
        return true;
    }

    public static function list_skills( $input = array() ) {
        $source = self::source( is_array( $input ) ? ( $input['source_id'] ?? '' ) : '' );
        if ( is_wp_error( $source ) ) return $source;
        $deadline = is_array( $input ) ? ( $input['deadline'] ?? null ) : null;
        $tree = self::tree( $source, $deadline );
        if ( is_wp_error( $tree ) ) return $tree;
        return array(
            'contract' => self::CONTRACT, 'source' => $source, 'tree_sha' => $tree['sha'],
            'count' => count( $tree['skills'] ), 'skills' => $tree['skills'],
            'note' => 'Source-backed inventory only. No Skill was installed or executed.',
        );
    }

    private static function document( array $source, array $tree, $path, $expected_sha, $deadline = null ) {
        if ( ! self::valid_path( $path ) || ! isset( $tree['files'][ $path ] ) ) return new WP_Error( 'skill_source_path_unavailable', 'The requested file is not in the exact source tree.' );
        $entry = $tree['files'][ $path ];
        if ( ! is_string( $expected_sha ) || ! preg_match( '/\A[a-f0-9]{40}\z/D', $expected_sha ) || ! hash_equals( $entry['sha'], $expected_sha ) ) {
            return new WP_Error( 'skill_source_revision_changed', 'File digest differs from the inspected tree. Rediscover the source.' );
        }
        if ( $entry['size'] < 0 || $entry['size'] > self::MAX_FILE_BYTES ) return new WP_Error( 'skill_source_file_too_large', 'File exceeds the text read budget.' );
        $origin = self::github_origin();
        if ( is_wp_error( $origin ) ) return $origin;
        $url = $origin . '/repos/' . $source['repository'] . '/git/blobs/' . $entry['sha'];
        $blob = self::github_json( $url, 400000, $deadline );
        if ( is_wp_error( $blob ) ) return $blob;
        if ( ( $blob['encoding'] ?? '' ) !== 'base64' || ( $blob['sha'] ?? '' ) !== $entry['sha'] || ! is_string( $blob['content'] ?? null ) ) return new WP_Error( 'skill_source_blob_invalid', 'Blob encoding or identity is invalid.' );
        $content = base64_decode( preg_replace( '/\s+/', '', $blob['content'] ), true );
        if ( ! is_string( $content ) || strlen( $content ) > self::MAX_FILE_BYTES || strlen( $content ) !== $entry['size'] || ! hash_equals( $entry['sha'], sha1( 'blob ' . strlen( $content ) . "\0" . $content ) ) || ! preg_match( '//u', $content ) || false !== strpos( $content, "\0" ) ) {
            return new WP_Error( 'skill_source_blob_verification_failed', 'Content failed exact Git blob or UTF-8 verification.' );
        }
        return array(
            'contract' => self::CONTRACT, 'source' => $source, 'path' => $path,
            'tree_sha' => $tree['sha'], 'blob_sha' => $entry['sha'],
            'sha256' => hash( 'sha256', $content ), 'bytes' => strlen( $content ),
            'content' => $content, 'trust' => 'untrusted_external_instructions',
            'installed' => false, 'executed' => false,
        );
    }

    public static function get_skill( $input = array() ) {
        $source = self::source( is_array( $input ) ? ( $input['source_id'] ?? '' ) : '' );
        if ( is_wp_error( $source ) ) return $source;
        $path = is_array( $input ) ? ( $input['path'] ?? '' ) : '';
        if ( ! self::valid_path( $path ) || 'SKILL.md' !== basename( $path ) ) return new WP_Error( 'skill_source_skill_path_invalid', 'Choose an exact SKILL.md path from discovery.' );
        $deadline = is_array( $input ) ? ( $input['deadline'] ?? null ) : null;
        $tree = self::tree( $source, $deadline );
        if ( is_wp_error( $tree ) ) return $tree;
        $paths = array_column( $tree['skills'], 'path' );
        if ( ! in_array( $path, $paths, true ) ) return new WP_Error( 'skill_source_skill_not_found', 'This path is not a discovered Skill.' );
        return self::document( $source, $tree, $path, is_array( $input ) ? ( $input['blob_sha'] ?? '' ) : '', $deadline );
    }

    public static function get_resource( $input = array() ) {
        $source = self::source( is_array( $input ) ? ( $input['source_id'] ?? '' ) : '' );
        if ( is_wp_error( $source ) ) return $source;
        $skill = is_array( $input ) ? ( $input['skill_path'] ?? '' ) : '';
        $path = is_array( $input ) ? ( $input['path'] ?? '' ) : '';
        if ( ! self::valid_path( $skill ) || 'SKILL.md' !== basename( $skill ) || ! self::valid_path( $path ) ) return new WP_Error( 'skill_source_resource_path_invalid', 'Invalid Skill or resource path.' );
        $prefix = 'SKILL.md' === $skill ? '' : substr( $skill, 0, -8 );
        $relative = 0 === strpos( $path, $prefix ) ? substr( $path, strlen( $prefix ) ) : '';
        if ( ! preg_match( '~\A(?:references|assets|scripts)/[^/]+~', $relative ) ) return new WP_Error( 'skill_source_resource_scope_denied', 'Supporting files must belong to the selected Skill.' );
        $tree = self::tree( $source );
        if ( is_wp_error( $tree ) ) return $tree;
        if ( ! in_array( $skill, array_column( $tree['skills'], 'path' ), true ) ) return new WP_Error( 'skill_source_skill_not_found', 'Selected Skill was not discovered.' );
        return self::document( $source, $tree, $path, is_array( $input ) ? ( $input['blob_sha'] ?? '' ) : '' );
    }

    public static function handle_admin( $action, $input ) {
        check_admin_referer( 'external_skill_sources', 'external_skill_sources_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'skill_source_access_denied', 'Administrator permission required.' );
        if ( 'add' === $action ) {
            $repo = isset( $input['source_repository'] ) && is_string( $input['source_repository'] ) ? sanitize_text_field( wp_unslash( $input['source_repository'] ) ) : '';
            $ref = isset( $input['source_ref'] ) && is_string( $input['source_ref'] ) ? sanitize_text_field( wp_unslash( $input['source_ref'] ) ) : '';
            $saved = self::save_source( $repo, $ref );
            return is_wp_error( $saved ) ? $saved : 'External source configured. Its files remain remote and untrusted.';
        }
        if ( 'remove' === $action ) {
            $id = isset( $input['source_id'] ) && is_string( $input['source_id'] ) ? sanitize_key( wp_unslash( $input['source_id'] ) ) : '';
            $removed = self::remove_source( $id );
            return is_wp_error( $removed ) ? $removed : 'Source removed from the discovery catalog.';
        }
        return new WP_Error( 'skill_source_action_invalid', 'Unsupported source operation.' );
    }

    public static function render_admin() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        echo '<hr><h2>External Skill sources</h2><p>Add public GitHub repositories by owner/repository and Git ref. Discovery reads external text only; it never installs or executes Skills.</p>';
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Repository</th><th>Git ref</th><th>Source ID</th><th>Action</th></tr></thead><tbody>';
        foreach ( self::sources() as $row ) {
            echo '<tr><td>' . esc_html( $row['repository'] ) . '</td><td>' . esc_html( $row['ref'] ) . '</td><td><code>' . esc_html( $row['id'] ) . '</code></td><td><form method="post">';
            wp_nonce_field( 'external_skill_sources', 'external_skill_sources_nonce' );
            echo '<input type="hidden" name="mad4b_skill_action" value="source_remove"><input type="hidden" name="source_id" value="' . esc_attr( $row['id'] ) . '"><button type="submit" class="button">Remove</button></form></td></tr>';
        }
        echo '</tbody></table><form method="post" style="max-width:1000px"><p>';
        wp_nonce_field( 'external_skill_sources', 'external_skill_sources_nonce' );
        echo '<input type="hidden" name="mad4b_skill_action" value="source_add">';
        echo '<label>Public repository <input type="text" name="source_repository" placeholder="owner/repository" required maxlength="120"></label> ';
        echo '<label>Reference <input type="text" name="source_ref" value="main" required maxlength="120"></label> ';
        echo '<button type="submit" class="button button-secondary">Add source</button></p></form>';
    }
}
