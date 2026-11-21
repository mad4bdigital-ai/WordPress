<?php
/**
 * PERMANENT MAD4B HOST TOPOLOGY REGRESSION.
 *
 * A local MCP receipt proves the current node only. A single-node deployment
 * may be considered complete ONLY with exact verified Host Registry ownership.
 * Multi-node completion requires every distinct scoped, hashed native node
 * receipt. An unknown topology is never assumed to be a single node.
 *
 * Read only, fixtures only: never creates a host, cluster, grant or job.
 */
defined('ABSPATH') || exit;
$assert = static function($condition,$why):void{
    if (!$condition) WP_CLI::error('MAD4B_TOPOLOGY_REGRESSION_FAIL_'.$why);
};
$owner='MAD4B_SCP_MCP_Runtime_Recovery';
$origin=str_repeat('a',64);$build=str_repeat('b',64);$mu=str_repeat('c',64);
$site='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
$local=[
    'contract'=>'mad4b.mcp-runtime-node-evidence.v2',
    'site_uuid'=>$site,'environment'=>'staging',
    'origin_sha256'=>$origin,'target_build'=>$build,
    'node_id'=>'app-01','cluster_id'=>'','site_binding_ready'=>true,
    'runtime_execution_verified'=>true,'expected_mu_sha256'=>$mu,
    'observed_mu_sha256'=>$mu
];
$unknown=$owner::topology_verdict($local);
$assert($unknown['local_runtime']==='LOCAL_VERIFIED','LOCAL_READBACK_LOST');
$assert($unknown['topology']==='UNKNOWN','UNREGISTERED_SITE_AUTOMATICALLY_COMPLETED');
$assert($unknown['cluster_aggregation_required']===null,'UNKNOWN_FORCED_CLUSTER');
$assert(!$unknown['deployment_complete'],'UNKNOWN_DEPLOYMENT_COMPLETE');

$registry=[
    'contract'=>'mad4b.company-host-topology-registry.v1',
    'state'=>'VERIFIED','registration_readback_verified'=>true,
    'site_uuid'=>$site,'environment'=>'staging',
    'origin_sha256'=>$origin,'target_build'=>$build,
    'topology'=>'single_node','required_node_ids'=>['app-01']
];
$single=$owner::topology_verdict($local,$registry);
$assert($single['topology']==='SINGLE_NODE_VERIFIED','SINGLE_NODE_UNDETECTED');
$assert($single['cluster_aggregation_required']===false,'SINGLE_NODE_REQUIRES_FAKE_CLUSTER');
$assert($single['cluster_readback']==='NOT_APPLICABLE_SINGLE_NODE','SINGLE_CLUSTER_STATUS');
$assert($single['deployment_complete']===true,'SINGLE_NODE_NOT_COMPLETE');
$blocked=$registry;$blocked['registration_readback_verified']=false;
$assert(!$owner::topology_verdict($local,$blocked)['deployment_complete'],
    'UNVERIFIED_HOST_REGISTRY_ADMITTED');
$stale=$registry;$stale['target_build']=str_repeat('d',64);
$assert(!$owner::topology_verdict($local,$stale)['deployment_complete'],
    'STALE_HOST_BUILD_ADMITTED');
$foreign=$registry;$foreign['site_uuid']='00000000-0000-0000-0000-000000000000';
$assert(!$owner::topology_verdict($local,$foreign)['deployment_complete'],
    'FOREIGN_SITE_ADMITTED');

$local['cluster_id']='company-staging-01';
$registry['cluster_id']='company-staging-01';
$registry['topology']='multi_node';
$registry['required_node_ids']=['app-01','app-02'];
$makeReceipt=static function($node)use($local,$mu):array{
    $r=$local;
    $r['node_id']=$node;
    $r['evidence_sha256']=hash('sha256',
        wp_json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    return $r;
};
$first=$makeReceipt('app-01');$second=$makeReceipt('app-02');
$partial=$owner::topology_verdict($local,$registry,[$first]);
$assert($partial['topology']==='MULTI_NODE_REGISTERED','MULTI_NODE_UNDETECTED');
$assert($partial['cluster_aggregation_required']===true,'MULTI_NODE_AGGREGATION_NOT_REQUIRED');
$assert($partial['cluster_readback']==='PENDING_REQUIRED_NODE_RECEIPTS',
    'PARTIAL_CLUSTER_MISLABELED_PASS');
$assert(!$partial['deployment_complete'] &&
    $partial['missing_node_ids']===['app-02'],'MISSING_NODE_NOT_SURFACED');

$full=$owner::topology_verdict($local,$registry,[$first,$second]);
$assert($full['cluster_readback']==='CLUSTER_VERIFIED','COMPLETE_CLUSTER_NOT_VERIFIED');
$assert($full['deployment_complete']===true&&$full['verified_node_count']===2,
    'COMPLETE_CLUSTER_NOT_READY');
$dup=$owner::topology_verdict($local,$registry,[$first,$second,$second]);
$assert($dup['cluster_readback']==='INVALID_OR_DUPLICATE_NODE_RECEIPTS' &&
    !$dup['deployment_complete'],'DUPLICATE_NODE_ADMITTED');
$bad=$second;$bad['target_build']=str_repeat('1',64); // digest stale on purpose
$invalid=$owner::topology_verdict($local,$registry,[$first,$bad]);
$assert($invalid['cluster_readback']==='INVALID_OR_DUPLICATE_NODE_RECEIPTS' &&
    !$invalid['deployment_complete'],'TAMPERED_RECEIPT_ADMITTED');
$wrongMu=$second;$wrongMu['expected_mu_sha256']=str_repeat('f',64);
$wrongMu['observed_mu_sha256']=str_repeat('f',64);
$wrongMu['evidence_sha256']=hash('sha256',
    wp_json_encode(array_diff_key($wrongMu,['evidence_sha256'=>true]),
        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$assert(!$owner::topology_verdict($local,$registry,[$first,$wrongMu])['deployment_complete'],
    'UNMATCHED_MU_FINGERPRINT_ADMITTED');
$unverified=$local;$unverified['runtime_execution_verified']=false;
$assert(!$owner::topology_verdict($unverified,$registry,[$first,$second])['deployment_complete'],
    'LOCAL_FAILURE_IGNORED');

WP_CLI::line(wp_json_encode([
    'contract'=>'mad4b.mcp-topology-regression.v1','result'=>'PASS',
    'unknown_not_complete'=>true,'single_verified_no_fake_cluster'=>true,
    'multi_missing_receipt_detected'=>true,'multi_complete_fixture_verified'=>true,
    'duplicate_rejected'=>true,'stale_build_rejected'=>true,
    'wrong_mu_rejected'=>true,'cross_site_rejected'=>true,
    'mutation_performed'=>false,
]));
