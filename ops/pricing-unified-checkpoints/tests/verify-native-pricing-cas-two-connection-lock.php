<?php
/**
 * Live, read-only cross-connection DB lock verification for native JetEngine
 * Save. Deliberately never invokes vendor Save or changes options.
 *
 * Run with wp eval-file (authorized admin only). This proves the MySQL
 * advisory lock serializes independent DB connections, not merely that
 * one request can acquire and release it.
 */
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
$cls='MAD4B_JetEngine_Native_Pricing_CAS_V1';
if(!class_exists($cls,false))WP_CLI::error('Native CAS missing');
wp_set_current_user(1);
$before=$cls::source_revision();
if(is_wp_error($before))WP_CLI::error($before->get_error_code());
global $wpdb;
$key='mad4b:pricing:'.substr(hash('sha256',DB_NAME.':'.$cls::PAGE),0,38);
$primary=(string)$wpdb->get_var('SELECT CONNECTION_ID()');
$peer=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
if($peer->error)WP_CLI::error('Secondary DB connection unavailable');
$secondary=(string)$peer->get_var('SELECT CONNECTION_ID()');
if(!$primary||!$secondary||$primary===$secondary)
    WP_CLI::error('Second independent DB connection not established');
$method=(new ReflectionClass($cls))->getMethod('lock');
$method->setAccessible(true);
$locked=$method->invoke(null);
if(is_wp_error($locked))WP_CLI::error('Primary CAS lock unavailable: '.$locked->get_error_code());
try{
    $already=$peer->get_var($peer->prepare('SELECT IS_USED_LOCK(%s)',$key));
    if((string)$already!==$primary)
        WP_CLI::error('CAS lock not owned by primary DB connection');
    $contended=$peer->get_var($peer->prepare('SELECT GET_LOCK(%s,0)',$key));
    if((string)$contended!=='0')
        WP_CLI::error('Second DB connection acquired a held CAS lock');
}finally{
    $cls::unlock();
}
$available=$peer->get_var($peer->prepare('SELECT GET_LOCK(%s,0)',$key));
if((string)$available!=='1')
    WP_CLI::error('CAS lock not released after primary request');
$released=$peer->get_var($peer->prepare('SELECT RELEASE_LOCK(%s)',$key));
if((string)$released!=='1')
    WP_CLI::error('Secondary DB lock not released');
$after=$cls::source_revision();
if(is_wp_error($after)||!hash_equals($before,$after))
    WP_CLI::error('CAS contention verification modified real pricing options');
echo 'NATIVE_CAS_TWO_CONNECTION_LOCK_PASS '.wp_json_encode([
    'site'=>home_url(),'distinct_mysql_connections'=>true,
    'contender_blocked_while_held'=>true,
    'new_writer_allowed_after_release'=>true,
    'source_revision_preserved'=>true,
    'native_writer_executed'=>false,
    'pricing_options_mutated'=>false
],JSON_UNESCAPED_SLASHES).PHP_EOL;
