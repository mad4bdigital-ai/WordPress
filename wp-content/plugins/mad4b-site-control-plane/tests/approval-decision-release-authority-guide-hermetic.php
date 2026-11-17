<?php
/** Read-only explanation and authority safety; no WordPress bootstrap needed. */
declare(strict_types=1);
define('ABSPATH', '/');
function esc_html__(string $text, string $domain = ''): string {return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');}
$GLOBALS['test_dispatch_registered']=false;
$GLOBALS['test_readiness_calls']=0;
final class MAD4B_SCP_Company_Code_Release_Readiness {
    public static function status($input = array()): array {
        ++$GLOBALS['test_readiness_calls'];
        if ($input!==[]) throw new RuntimeException('Read-only status must receive empty input.');
        return ['dispatch_ability_registered'=>$GLOBALS['test_dispatch_registered']];
    }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-approval-decision-admin.php';
$tests=[];
$contains=static function(string $haystack,string $needle):bool{return false!==strpos($haystack,$needle);};
$assert=static function(string $name, bool $pass) use (&$tests):void {$tests[$name]=$pass;};
$status=MAD4B_SCP_Approval_Decision_Admin::release_authority_guidance();
$assert('new_status_contract_read_only',($status['contract']??'')==='mad4b.approval-inbox.release-authority-guide.readonly.v1' && $status['read_only'] === true);
$assert('wordPress_does_not_claim_host_signer_verification',($status['host_and_signer_verification']??'')==='not_observable_from_wordpress');
$assert('no_unbounded_auto_approval_claimed',($status['future_scoped_delegation']??'')==='design_option_not_enrolled_or_active');
$assert('current_exact_code_release_review_remains',($status['current_code_release_decision']??'')==='exact_master_admin_approval_required_by_current_original313_lane');
$assert('no_release_or_ticket_authority_created',empty($status['can_issue_approval_or_release']) && empty($status['production_release_authorized']));
$assert('disabled_dispatch_not_called_approved',empty($status['release_dispatch_registered_locally']));
ob_start(); MAD4B_SCP_Approval_Decision_Admin::render_release_authority_guidance(); $html=(string)ob_get_clean();
$assert('accessible_guide_and_sections',$contains($html,'aria-labelledby="mad4b-release-authority-title"') && $contains($html,'<caption>') && $contains($html,'<details>') && $contains($html,'<th scope="col">'));
$assert('role_separation_explained',$contains($html,'Master Admin') && $contains($html,'Original312 / Broker') && $contains($html,'Original313') && $contains($html,'technical signatures'));
$assert('current_readback_not_faked',$contains($html,'Not registered in this WordPress runtime') && $contains($html,'cannot be verified by this page'));
$assert('no_action_buttons_or_forms_in_guidance',!$contains($html,'<form') && !$contains($html,'<button') && !$contains($html,'admin-post.php'));
$assert('digital_signatures_not_manual_decisions',$contains($html,'not another manual approval') && $contains($html,'not a requirement of digital signatures'));
$assert('no_auto_approve_misrepresentation',$contains($html,'That mode is not enabled by this page'));
$GLOBALS['test_dispatch_registered']=true;
$yes=MAD4B_SCP_Approval_Decision_Admin::release_authority_guidance();
ob_start(); MAD4B_SCP_Approval_Decision_Admin::render_release_authority_guidance(); $enabled_html=(string)ob_get_clean();
$assert('local_dispatch_only_not_release_ready',!empty($yes['release_dispatch_registered_locally']) && empty($yes['production_release_authorized']) && $contains($enabled_html,'Registered in this WordPress runtime'));
$assert('readiness_calls_restricted_to_read_only', $GLOBALS['test_readiness_calls']===4);
$failed=array_keys(array_filter($tests,static fn($ok)=>!$ok));
echo json_encode(['contract'=>'mad4b.approval-inbox.release-authority-ui.contract.v1','result'=>$failed?'FAIL':'PASS',
    'passed'=>count($tests)-count($failed),'total'=>count($tests),'failed'=>$failed,'authority_created'=>false],JSON_UNESCAPED_SLASHES),"\n";
exit($failed?1:0);
