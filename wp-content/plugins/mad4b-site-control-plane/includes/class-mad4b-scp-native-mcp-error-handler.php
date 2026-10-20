<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Staging MU-backed, bounded Adapter error sink. Never alter the wire response. */
final class MAD4B_SCP_Native_MCP_Error_Handler implements \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface {
    public function log( string $message, array $context = array(), string $type = 'error' ): void {
        if ( !in_array($type,array('error','warning'),true) ) return;
        try {
            if ( class_exists('MAD4B_Native_Incident_MU',false) ) MAD4B_Native_Incident_MU::record_adapter_error($message,$context);
        } catch ( Throwable $ignored ) { /* Even DB/logger failures cannot crash the Adapter. */ }
    }
}
