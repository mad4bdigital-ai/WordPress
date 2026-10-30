<?php
/**
 * Host-only central receiving command:
 * docker exec -u www-data etg-commerce-staging-php wp --path=/var/www/html
 * --skip-themes eval-file /tmp/mad4b-hub-cross-ssh/receive.php
 * Manifest must have been copied into the fixed non-public location below.
 * No arbitrary command execution, plugin installation, consumer publication or DB overwrite.
 */
if(!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH'))exit(82);
if(wp_parse_url(home_url('/'),PHP_URL_HOST)!=='tours.dev.mad4b.com') {
    fwrite(STDERR,"CROSS_SSH_TARGET_WRONG_ENVIRONMENT\n");exit(83);
}
define('MAD4B_HUB_CROSS_SSH_HOST_RUNNER',true);
$path='/tmp/mad4b-hub-cross-ssh/manifest.json';
if(!is_file($path) || !is_readable($path) || filesize($path)>27000000) {
    fwrite(STDERR,"CROSS_SSH_MANIFEST_MISSING_OR_OVERSIZE\n");exit(84);
}
$json=file_get_contents($path);
if(!is_string($json) || !$json)exit(85);
$manifest=json_decode($json,true);
unset($json);
if(json_last_error()!==JSON_ERROR_NONE || !is_array($manifest))exit(86);
if(!class_exists('MAD4B_Hub_Source_Import_V1'))exit(87);
$result=MAD4B_Hub_Source_Import_V1::import_cross_ssh_snapshot($manifest);
unset($manifest);
if(is_wp_error($result)) {
    fwrite(STDERR,"CROSS_SSH_IMPORT_FAILED=".$result->get_error_code()."\n");
    exit(88);
}
if(!is_array($result) || ($result['transport']??'')!=='wp_cli_cross_ssh')exit(89);
echo wp_json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
