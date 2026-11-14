<?php
/** No WP loading; incomplete owner APIs must deny approved Brand Core assets. */
define('ABSPATH', '/');
if ( ! in_array( '--without-class', $argv, true ) ) {
    class MAD4B_SCP_Context_Authority {} // Existing hermetic shim without callback.
}
require dirname(__DIR__).'/includes/class-mad4b-scp-brand-context-builder.php';
$reflection=new ReflectionMethod('MAD4B_SCP_Brand_Context_Builder','approved_brand_asset');
// PHP 7.4 requires private reflection access; PHP 8.1+ ignores it.
if ( PHP_VERSION_ID < 80100 ) $reflection->setAccessible( true );
$asset=array(
 'status'=>'ready','authority_class'=>'brand_authority',
 'review_status'=>'approved',
 'content_hash'=>str_repeat('a',64),
 'reviewed_content_hash'=>str_repeat('a',64),
 'title'=>'A brand strategy',
);
if ($reflection->invoke(null,$asset)!==false) {
 fwrite(STDERR,"BRAND_CONTEXT_OWNER_PROOF_MISSING_FAIL_OPEN\n");
 exit(1);
}
echo "BRAND_CONTEXT_MISSING_OWNER_PROOF_FAIL_CLOSED: PASS\n";
