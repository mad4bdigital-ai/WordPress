<?php
/**
 * Isolated CLI runner for ONLY the commerce-composition workflow queue.
 * Does not run other WordPress/Action Scheduler jobs.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){
    http_response_code(403);
    exit;
}
$bootstrap=dirname(__DIR__,4).'/wp-load.php';
if(!is_file($bootstrap)){
    fwrite(STDERR,"WordPress bootstrap not available\n");
    exit(3);
}
require_once $bootstrap;
if(!class_exists(\Commerce\Composition\WorkflowJobs::class)){
    fwrite(STDERR,"Workflow queue is not installed\n");
    exit(4);
}
$out=\Commerce\Composition\WorkflowJobs::drain(20);
$consent=class_exists(\Commerce\Composition\GuestConsentProof::class)
    ?\Commerce\Composition\GuestConsentProof::retryDue(10):['processed'=>0];
echo wp_json_encode(['queue'=>'commerce-composition',
    'result'=>$out,'guest_consent_retries'=>$consent],
    JSON_UNESCAPED_SLASHES).PHP_EOL;
