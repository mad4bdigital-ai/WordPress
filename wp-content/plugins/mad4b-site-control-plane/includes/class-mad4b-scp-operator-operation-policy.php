<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Stateless semantic admission for the external unified operator broker.
 *
 * This is a planner, NOT a SQL parser, an authorization decision or a worker.
 * In particular a database READ ONLY credential remains mandatory even when
 * a statement is classified as sql.read. No process is launched here.
 */
final class MAD4B_SCP_Operator_Operation_Policy {
    const CONTRACT = 'mad4b.operator-operation-policy.v1';
    const MAX_SQL_BYTES = 32768;
    const MAX_COMMAND_BYTES = 8192;

    private static function error( $code, $message ) {
        return new WP_Error( $code, $message );
    }

    public static function inspect( $input ) {
        if ( ! is_array( $input ) ) return self::error( 'mad4b_operator_operation_input_invalid', 'Expected object.' );
        $op = (string) ( $input['operation'] ?? '' );
        $payload = $input['payload'] ?? null;
        if ( ! is_array( $payload ) ) return self::error( 'mad4b_operator_payload_required', 'Expected operation payload object.' );

        $valid_ops = array( 'shell', 'wp-cli', 'sql.read', 'sql.write', 'sql.schema' );
        if ( ! in_array( $op, $valid_ops, true ) ) return self::error( 'mad4b_operator_operation_denied', 'Unknown or unsupported operation.' );
        $allowed = array( 'operation', 'payload' );
        if ( array_diff( array_keys( $input ), $allowed ) ) return self::error( 'mad4b_operator_operation_extra_fields', 'Unexpected input fields.' );

        $normalized = array();
        if ( 'shell' === $op ) {
            if ( array_keys( $payload ) !== array( 'command' ) ||
                 ! is_string( $payload['command'] ) || '' === trim( $payload['command'] ) ||
                 strlen( $payload['command'] ) > self::MAX_COMMAND_BYTES ||
                 false !== strpos( $payload['command'], "\0" ) )
                return self::error( 'mad4b_operator_shell_input_invalid', 'Shell requires one bounded command.' );
            $normalized = array( 'command' => $payload['command'] );
        } elseif ( 'wp-cli' === $op ) {
            if ( array_keys( $payload ) !== array( 'args' ) || ! is_array( $payload['args'] )
                || count( $payload['args'] ) < 1 || count( $payload['args'] ) > 32 ) {
                return self::error( 'mad4b_operator_wpcli_input_invalid', 'WP-CLI requires a bounded argv array.' );
            }
            foreach ( $payload['args'] as $arg ) {
                if ( ! is_string( $arg ) || strlen( $arg ) > 2048 || false !== strpos( $arg, "\0" ) )
                    return self::error( 'mad4b_operator_wpcli_arg_invalid', 'WP-CLI argv item invalid.' );
            }
            if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]*$/', $payload['args'][0] ) )
                return self::error( 'mad4b_operator_wpcli_command_invalid', 'Expected a WP-CLI command name.' );
            // Worker must insert exact --path and reject --ssh/--http and plugin code hooks.
            $normalized = array( 'args' => array_values( $payload['args'] ) );
        } else {
            if ( array_keys( $payload ) !== array( 'sql' ) ||
                 ! is_string( $payload['sql'] ) || '' === trim( $payload['sql'] ) ||
                 strlen( $payload['sql'] ) > self::MAX_SQL_BYTES ||
                 false !== strpos( $payload['sql'], "\0" ) )
                return self::error( 'mad4b_operator_sql_input_invalid', 'SQL requires one bounded statement.' );

            $sql = trim( $payload['sql'] );
            // No unsupported nested/multiple statements. The worker's SQL driver
            // must ALSO disable multi-statements and enforce DB-level grants.
            if ( substr_count( $sql, ';' ) > 1 || ( false !== strpos( $sql, ';' ) && ';' !== substr( $sql, -1 ) ) )
                return self::error( 'mad4b_operator_sql_batch_denied', 'SQL multi-statements are not admitted.' );
            $head = strtoupper( strtok( ltrim( $sql ), " \r\n\t(" ) );
            if ( 'sql.read' === $op && ! in_array( $head, array( 'SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN' ), true ) )
                return self::error( 'mad4b_operator_sql_read_type_denied', 'Not a read-type SQL statement.' );
            if ( 'sql.write' === $op && ! in_array( $head, array( 'INSERT', 'UPDATE', 'DELETE', 'REPLACE' ), true ) )
                return self::error( 'mad4b_operator_sql_write_type_denied', 'Not a DML statement.' );
            if ( 'sql.schema' === $op && ! in_array( $head, array( 'CREATE', 'ALTER', 'DROP', 'RENAME' ), true ) )
                return self::error( 'mad4b_operator_sql_schema_type_denied', 'Not a DDL statement.' );
            if ( 'sql.read' === $op && preg_match( '/\b(?:INTO\s+(?:OUTFILE|DUMPFILE)|LOAD_FILE\s*\(|SLEEP\s*\(|BENCHMARK\s*\(|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE)\b/i', $sql ) )
                return self::error( 'mad4b_operator_sql_read_side_effect_denied', 'Read statement includes known side effects.' );
            $normalized = array( 'sql' => $sql );
        }

        $mutating = in_array( $op, array( 'shell', 'wp-cli', 'sql.write', 'sql.schema' ), true );
        return array(
            'contract' => self::CONTRACT,
            'operation' => $op,
            'payload' => $normalized,
            'payload_sha256' => hash( 'sha256', wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            'risk' => $mutating ? 'mutation_possible' : 'read_only_db_role_required',
            'backup_required' => in_array( $op, array( 'sql.write', 'sql.schema' ), true ),
            'independent_worker_authority_required' => true,
            'external_sandbox_required' => in_array( $op, array( 'shell', 'wp-cli' ), true ),
            'execution_enabled' => false,
            'mutation_performed' => false,
            'production_authorized' => false,
        );
    }
}
