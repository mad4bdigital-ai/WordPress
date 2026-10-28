<?php
/**
 * MAD4B WP-Cron admission reporter.
 * CLI-only, Test-environment-only. No hook dispatch, mail, WP-Cron spawning or event mutation.
 * A future certified executor may consume the exact fingerprints, but never this
 * read-only script as an execution permission.
 */
if (PHP_SAPI !== 'cli') exit(77);
$root=dirname(__DIR__,2);
if(!defined('ABSPATH'))require $root.'/wp-load.php';
require __DIR__.'/governed-cron-plan.php';
if(in_array('--execute',$argv,true)||in_array('--apply',$argv,true)){
    fwrite(STDERR,"EXECUTION_LOCKED: no approved single-hook worker certificate; use --preview for inspection.\n");exit(77);
}
if(!in_array('--preview',$argv,true)){
    fwrite(STDERR,"PREVIEW_ONLY: invoke with --preview; never executes due WP-Cron hooks.\n");exit(64);
}
$report=MAD4B_Governed_Cron_Preview::plan(_get_cron_array(),time());
echo wp_json_encode($report,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
exit(($report['status']??'')==='preview_only'?0:77);
