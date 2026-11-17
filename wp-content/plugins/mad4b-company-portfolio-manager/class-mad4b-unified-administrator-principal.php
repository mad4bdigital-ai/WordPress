<?php
/**
 * Staging-only, first-party authenticated identity adapter.
 * Does not authenticate OAuth tokens itself: a verified OAuth Resource Bridge
 * and the enrolled exact WordPress user are both mandatory.
 * Does not issue remote company authority, add roles or alter Company Registry.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Manager_Unified_Administrator_Principal {
    const CONTRACT='mad4b.company-manager.unified-administrator-principal.v1';

    public static function verified() : bool {
        if (!class_exists('MAD4B_Company_Portfolio_Manager_V1') ||
            !MAD4B_Company_Portfolio_Manager_V1::staging() ||
            !current_user_can('manage_options') || !current_user_can('activate_plugins')) return false;
        foreach (['MAD4B_SCP_Site_Profile','MAD4B_SCP_OAuth_Resource_Bridge',
                  'MAD4B_SCP_Identity_Context','MAD4B_SCP_Local_OAuth_Server'] as $name) {
            if (!class_exists($name)) return false;
        }
        if (MAD4B_SCP_Site_Profile::current_environment() !== 'staging' ||
            !MAD4B_SCP_Site_Profile::origin_enrolled() ||
            !MAD4B_SCP_Site_Profile::site_urls_match_enrollment()) return false;
        $ids=MAD4B_SCP_Site_Profile::oauth_user_ids();
        $user=get_current_user_id();
        if ($user<1 || !is_array($ids) || count($ids)!==1 || (int)$ids[0]!==$user)
            return false;
        if (class_exists('MAD4B_SCP_Policy') && MAD4B_SCP_Policy::can_breakglass())
            return false;
        if (!MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ||
            !MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope(
                MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE) ||
            !MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(
                MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID)) return false;
        $principal=MAD4B_SCP_Identity_Context::current();
        return is_array($principal)
            && !empty($principal['authenticated'])
            && ($principal['subject_type']??'')==='oauth'
            && ($principal['auth_method']??'')==='oauth2_bearer'
            && (int)($principal['wp_user_id']??0)===$user
            && ($principal['origin']??'')==='mcp'
            && preg_match('/^[a-f0-9]{64}$/D',(string)($principal['subject_fingerprint']??''))===1
            && preg_match('/^[a-f0-9]{64}$/D',(string)($principal['client_fingerprint']??''))===1;
    }
}
