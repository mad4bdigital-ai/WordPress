<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// Disposable CI database only: seed prior to the next process materializing MCP.
$plan = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array( 'ability_names' => array( 'mad4b/diagnostics-health', 'mad4b-ci/unsafe-write-projection-fixture' ) ) );
if ( is_wp_error( $plan ) || empty( $plan['ready_for_apply'] ) ) throw new RuntimeException( 'Projection HTTP fixture preflight failed.' );
$rows = array();
foreach ( $plan['desired_abilities'] as $row ) $rows[ $row['ability_name'] ] = $row;
update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, array( 'contract' => MAD4B_SCP_ChatGPT_Tool_Projection::CONTRACT, 'revision' => 1, 'binding' => MAD4B_SCP_ChatGPT_Tool_Projection::current_binding(), 'abilities' => $rows, 'updated_at' => gmdate( 'c' ) ), false );
