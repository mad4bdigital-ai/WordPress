<?php
/** Frozen Tours.dev source preservation only: no boot, provider calls or grants. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');
function ensure_source($ok,$message){if(!$ok){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}}
$plugin=dirname(__DIR__);
$main=file_get_contents($plugin.'/mad4b-site-control-plane.php');
ensure_source(is_string($main),'Existing candidate main bootstrap required');
$sealed=array(
    'includes/adapters/class-mad4b-scp-chatbotx-governed-writes.php'=>array('0832e17160191066e8ab5de4d08d1506c009ed1a448583a7b15d0fdbefafc545','53f27db33a04980a6103987b0927805fe0df7afd20df3cc7f768c3e8815de9f4',true),
    'includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php'=>array('5f95b30d40b15db55a493178a30819733faf8c396f06bcb1b25f935a92395e2b','4b58adad617d2fa7bb953604dee4c107b255649f5a747759cec37b21a326b2d6',true),
    'includes/adapters/class-mad4b-scp-chatbotx-write-preflight.php'=>array('9ee3139c6443786904a36ad80eb43089b4be8879b6e85c2020837c8658b12488','da5f525201efdb522582ce40d6103b270304b1ac86e14620cb58e2b31730047e',true),
    'includes/adapters/class-mad4b-scp-dfsb-adapter.php'=>array('7a0a817d4b93d83a344840b51ecada1e89cb5e7e6f88dede9a15cdc5f7974dd7','7a0a817d4b93d83a344840b51ecada1e89cb5e7e6f88dede9a15cdc5f7974dd7',false),
    'includes/adapters/class-mad4b-scp-local-brand-draft-adapter.php'=>array('ba08bab80236d29adcf98010a5567a67925595dc0cd322b4e1d297100e17c27f','ba08bab80236d29adcf98010a5567a67925595dc0cd322b4e1d297100e17c27f',false),
    'includes/class-mad4b-scp-adaptive-interaction-ui.php'=>array('a49cf0ed5a21ed6235e30dffb0f650f6f59d19db3b21c67db6ec2778b502991e','a49cf0ed5a21ed6235e30dffb0f650f6f59d19db3b21c67db6ec2778b502991e',false),
    'includes/class-mad4b-scp-ai-task-routing.php'=>array('7783241dcd74e0b196891a8672739c78b001bfd19560fd76ecfc401b937cb3b2','7783241dcd74e0b196891a8672739c78b001bfd19560fd76ecfc401b937cb3b2',false),
    'includes/class-mad4b-scp-cli-readiness.php'=>array('3242bd0236e19f6901078fd7c98e1a59444326205759b319dd9ccd0c1a36ae2a','3242bd0236e19f6901078fd7c98e1a59444326205759b319dd9ccd0c1a36ae2a',false),
    'includes/class-mad4b-scp-company-code-release-readiness.php'=>array('e8fac42bc4eaf8a6977af03e239f51a86cb5aed0bcc38db071f0cf61b83543d5','e8fac42bc4eaf8a6977af03e239f51a86cb5aed0bcc38db071f0cf61b83543d5',false),
    'includes/class-mad4b-scp-core-ai-governance.php'=>array('bd39809b26e5c64faf332d149a6accf44ff228f6c0e375d5482b8d22058ae1c2','bd39809b26e5c64faf332d149a6accf44ff228f6c0e375d5482b8d22058ae1c2',false),
    'includes/class-mad4b-scp-dfsb-environment-policy.php'=>array('5a9d6808b202578b35b9d24547ade1b8d861775db06a191f725a32b25b11bebb','fd05827b9f8a8bdcd6bfd0e8a8155b5593534185dc400608de5a91cb21cc488c',true),
    'includes/class-mad4b-scp-faore-staging-canary-inspection.php'=>array('09cabac435a12b4b5a76e515208fb572ac83359f7b81f3650b3cb3fcecbee8b9','09cabac435a12b4b5a76e515208fb572ac83359f7b81f3650b3cb3fcecbee8b9',false),
    'includes/class-mad4b-scp-guided-continuation.php'=>array('975aa17dec21915fca05da5dd1e0142801b8682dea36733031c388f77ec7cbfd','975aa17dec21915fca05da5dd1e0142801b8682dea36733031c388f77ec7cbfd',false),
    'includes/class-mad4b-scp-local-ai-governance.php'=>array('8b5f2761443664f28493c1a9b409c6492cc678cfc8eb42ffa18368bc8a978492','8b5f2761443664f28493c1a9b409c6492cc678cfc8eb42ffa18368bc8a978492',false),
    'includes/class-mad4b-scp-local-ollama-ability.php'=>array('9d18eaeefb2265a3364bdff00fd913c2954ee24855e0242338c6b0c80a6a7586','9d18eaeefb2265a3364bdff00fd913c2954ee24855e0242338c6b0c80a6a7586',false),
    'includes/class-mad4b-scp-site-lifecycle-rest.php'=>array('0a15798fca4cb26390d5dd2b214bf7da39e9d0b7846bfed358831e4b22c5dd23','0a15798fca4cb26390d5dd2b214bf7da39e9d0b7846bfed358831e4b22c5dd23',false),
);
$source_preserved=0;$reviewed_successors=0;
foreach($sealed as $rel=>$hashes){
    list($candidate_sha,$original_sha,$successor)=$hashes;
    $path=$plugin.'/'.$rel;
    ensure_source(is_file($path),'Original module not copied: '.$rel);
    $observed=hash_file('sha256',$path);
    ensure_source(is_string($observed)&&hash_equals($candidate_sha,$observed),'Git source SHA changed: '.$rel);
    ensure_source($successor ? !hash_equals($original_sha,$observed) : hash_equals($original_sha,$observed),
        'Frozen live-source relationship drifted: '.$rel);
    ensure_source(strpos($main,basename($rel))===false,'Main bootstrap must not auto-load new module: '.$rel);
    if($successor)$reviewed_successors++;else $source_preserved++;
}
// Conservative static Ability / REST candidate inventory only. No added module
// is loaded, so a quoted name never establishes executable authority.
$candidate_abilities=array(
    "ability:mad4b/chatbotx-booking-readiness"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-business-context"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-channels-read"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-commerce-route-plan"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-cpt-create-draft"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-cpt-product-source"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-integration-inventory"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-presentation-context"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-pricing-preview"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-provider-snapshot"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-remote-health"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-support-add-note"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-support-create-ticket"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/chatbotx-write-preflight"=>"includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php",
    "ability:mad4b/company-code-release-dispatch"=>"includes/class-mad4b-scp-company-code-release-readiness.php",
    "ability:mad4b/company-code-release-readiness"=>"includes/class-mad4b-scp-company-code-release-readiness.php",
    "ability:mad4b/faore-staging-canary-plan"=>"includes/class-mad4b-scp-faore-staging-canary-inspection.php",
    "ability:mad4b/faore-staging-canary-verify"=>"includes/class-mad4b-scp-faore-staging-canary-inspection.php",
    "ability:mad4b/guided-continuation-run"=>"includes/class-mad4b-scp-guided-continuation.php",
    "ability:mad4b/runtime-convergence-status"=>"includes/class-mad4b-scp-guided-continuation.php",
    "ability:mad4b/skills-runtime-certification"=>"includes/class-mad4b-scp-guided-continuation.php",
    "ability:mad4b/write-runtime-certification"=>"includes/class-mad4b-scp-guided-continuation.php",
    "rest:mad4b/v1/core-ai/usage"=>"includes/class-mad4b-scp-core-ai-governance.php",
);
foreach($candidate_abilities as $identifier=>$relative){
    ensure_source(isset($sealed[$relative]),'Catalog candidate must come from sealed source: '.$identifier);
    $contents=file_get_contents($plugin.'/'.$relative);
    ensure_source(is_string($contents),'Source-only candidate missing: '.$identifier);
    if(0===strpos($identifier,'ability:')) {
        ensure_source(false!==strpos($contents,substr($identifier,8)),
            'Static Ability identifier missing: '.$identifier);
    } else {
        ensure_source(false!==strpos($contents,"register_rest_route('mad4b/v1','core-ai/usage'"),
            'Bounded original admin GET route cannot change: '.$identifier);
        ensure_source(false!==strpos($contents,"'methods'=>'GET'")
            && false!==strpos($contents,"current_user_can('manage_options')"),
            'Original admin route has no read-only capability guard: '.$identifier);
    }
    ensure_source(false===strpos($main,basename($relative)),
        'New service must remain unbootstrapped: '.$identifier);
}
ensure_source(count($candidate_abilities)===23,'Exact new static candidate inventory');

ensure_source(count($sealed)===16&&$source_preserved===12&&$reviewed_successors===4,
    'Expected 12 frozen-identical modules and 4 site-neutral compatibility successors');
echo "PASS: 16 source-only files, 12 frozen-SHA-identical, 4 policy successors, no new bootstrap activation\n";
