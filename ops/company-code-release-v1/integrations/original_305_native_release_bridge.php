<?php
/**
 * First-party ORIGINAL 305/306 Native Release request bridge.
 *
 * This file runs only inside the enrolled Central WP-CLI and NEVER assumes
 * WP --user. It forwards a separately Broker-signed exact body to the actual
 * original 305 intake or original 306 dispatch. No grant is minted here.
 */
defined('ABSPATH') || exit;
$blob=file_get_contents('php://stdin');
$data=is_string($blob) && strlen($blob)<=14000 ? json_decode($blob,true):null;
if (!is_array($data) || array_keys($data)!==['action','raw_body','proof']
    || !in_array($data['action'],['intake','dispatch'],true)
    || !is_string($data['raw_body'])
    || !is_array($data['proof'])
    || $data['proof']['method']!=='POST') {
    WP_CLI::error('ORIGINAL_305_NATIVE_RELEASE_INPUT_INVALID');
}
$route='/mad4b/v1/central-execution-lanes/'.
    $data['proof']['company'].'/'.
    ($data['action']==='intake'?'commands':'broker-dispatch');
if (($data['proof']['route']??'')!==$route) {
    WP_CLI::error('ORIGINAL_305_NATIVE_RELEASE_ROUTE_INVALID');
}
$request=new WP_REST_Request('POST',$route);
$request->set_url_params(['company'=>$data['proof']['company']]);
$request->set_header('Content-Type','application/json');
$request->set_header('X-MAD4B-Native-Proof',
    base64_encode(wp_json_encode($data['proof'])));
$request->set_body($data['raw_body']);
$response=$data['action']==='intake'
    ? MAD4B_Central_SSH_Command_Intake_V1::accept_command($request)
    : MAD4B_Central_VPS_Broker_V1::dispatch($request);
if (is_wp_error($response)) {
    WP_CLI::line(wp_json_encode([
       'state'=>'DENIED','reason'=>$response->get_error_code(),
       'http_status'=>$response->get_error_data()['status']??403,
       'job_created'=>false,'production_mutated'=>false]));
    exit(2);
}
if (!($response instanceof WP_REST_Response)) {
    WP_CLI::error('ORIGINAL_305_NATIVE_RELEASE_RESPONSE_INVALID');
}
WP_CLI::line(wp_json_encode([
    'state'=>'ACCEPTED','http_status'=>$response->get_status(),
    'result'=>$response->get_data(),'production_mutated'=>false
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
