<?php
/** Staging-only hermetic Native Ability Trust fixture entrypoint for repository readiness coverage. */
$fixture=dirname(__DIR__,4).'/ops/native-capability-manifest-v1/test-native-ability-trust.php';
if (!is_file($fixture)) { fwrite(STDERR,"Native ability trust fixture missing\n"); exit(3); }
require $fixture;
