<?php
/**
 * Contract: GTM Publish covers creating a version AND publishing that version.
 * Google requires separate oauth scopes for the two operations.
 */
$context=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-google-drive-context.php');
$bridge=file_get_contents(dirname(__DIR__,2).'/mad4b-google-measurement-bridge/mad4b-google-measurement-bridge.php');
$tests=[
 'OAuth version scope constant'=>str_contains($context,"const TAG_MANAGER_EDIT_VERSIONS_SCOPE = 'https://www.googleapis.com/auth/tagmanager.edit.containerversions'"),
 'Publish includes version-create scope'=>str_contains($context,'self::TAG_MANAGER_EDIT_SCOPE, self::TAG_MANAGER_EDIT_VERSIONS_SCOPE, self::TAG_MANAGER_PUBLISH_SCOPE'),
 'Broker can accept exact version scope'=>str_contains($context,'self::TAG_MANAGER_EDIT_SCOPE, self::TAG_MANAGER_EDIT_VERSIONS_SCOPE, self::TAG_MANAGER_PUBLISH_SCOPE,'),
 'Bridge exposes exact scope'=>str_contains($bridge,"const SCOPE_GTM_EDIT_VERSIONS = 'https://www.googleapis.com/auth/tagmanager.edit.containerversions'"),
];
foreach($tests as $label=>$ok){
 if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}
}
echo "PASS: GTM Publish lifecycle includes version creation and publish scopes\n";
