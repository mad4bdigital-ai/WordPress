<?php
/** Post-update lifecycle is distinct for Staging and Production; no mutation. */
define('ABSPATH',__DIR__);
function check_policy($p,$name){if(!$p){fwrite(STDERR,"FAIL:$name\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-operator-workspace.php';
$klass='MAD4B_SCP_Operator_Workspace';
$prod=$klass::update_lifecycle_policy('production');
check_policy('PRODUCTION_READ_ONLY_RECONCILIATION'===$prod['state'],'Production read only');
check_policy(false===$prod['production_write_allowed'] && false===$prod['automatic_grant_expansion'],'no production auto authority');
check_policy('mad4b-runtime-components'===$prod['next_page'],'production routes to runtime components');
$staging=$klass::update_lifecycle_policy('staging');
check_policy('STAGING_REVIEW_AND_RECOVERY'===$staging['state'],'Staging contextual recovery');
check_policy(false===$staging['automatic_binding_rotation'],'reuse unchanged binding');
check_policy($staging['next_page']==='mad4b-operational-remediation','staging remediation correct');
$bad=$klass::update_lifecycle_policy('development');
check_policy('ENVIRONMENT_NOT_VERIFIED'===$bad['state'],'other environment fail closed');
check_policy(!$bad['production_promotion_inferred'] && !$bad['automatic_replay_allowed'],'no inferred promotion');
echo "ACTION_CENTER_UPDATE_POLICY: PASS\n";
