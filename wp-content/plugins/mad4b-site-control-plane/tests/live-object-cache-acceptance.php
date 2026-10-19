<?php
/** Run under WP-CLI only: wp eval-file THIS seed --user=<admin>; then read|increment|expiry <token>. */
if(!defined('WP_CLI')||true!==WP_CLI){throw new RuntimeException('WP CLI only');}
require_once dirname(__DIR__).'/includes/class-mad4b-scp-object-cache-diagnostics.php';
$phase=isset($args[0])?(string)$args[0]:'';$token=isset($args[1])?(string)$args[1]:'';
$out=MAD4B_SCP_Object_Cache_Diagnostics::cli_probe($phase,$token);
if(is_wp_error($out)){WP_CLI::error($out->get_error_code());}
WP_CLI::line(wp_json_encode($out,JSON_UNESCAPED_SLASHES));
