<?php
/**
 * Regression: Google grant AJAX saving must re-render the panel which contains
 * the extra-scope owner authorization button, not only its sibling settings.
 */
$file=dirname(__DIR__).'/includes/class-mad4b-scp-context-admin-ui.php';
$src=file_get_contents($file);
$target='"mad4b-google-primary-signin","mad4b-google-connection-method","mad4b-google-grants"';
$checks=[
 'initial Google sign-in panel rendered'=>substr_count($src,'mad4b-google-primary-signin')>=2,
 'correct Ajax replacement target'=>str_contains($src,$target),
 'incremental grant button retained'=>str_contains($src,'Authorize added Google access'),
 'first-party action retained'=>str_contains($src,'self::ACTION_CONNECT_GOOGLE'),
 'scope save enables refresh'=>str_contains($src,"'refresh' => true"),
];
foreach($checks as $label=>$ok){
 if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}
}
echo "PASS: Google incremental consent button refresh lifecycle\n";
