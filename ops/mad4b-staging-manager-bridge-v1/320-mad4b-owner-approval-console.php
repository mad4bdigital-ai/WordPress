<?php
/**
 * Plugin Name: MAD4B Original Owner Staging Approval Console
 * Description: Native wp-admin interface for the existing 317 owner-bound REST assignment lifecycle. Never creates grants.
 * Version: 0.1.1
 */
defined('ABSPATH') || exit;

final class MAD4B_Staging_Owner_Approval_Console_V1 {
    const PAGE = 'mad4b-staging-owner-approval';

    public static function enabled(): bool {
        return function_exists('wp_get_environment_type')
            && wp_get_environment_type() === 'staging'
            && untrailingslashit(home_url('/')) === 'https://tours.dev.mad4b.com'
            && class_exists('MAD4B_Environment_Manager_Assignment_V1', false);
    }

    public static function boot(): void {
        if (!self::enabled()) return;
        add_action('admin_menu', [__CLASS__, 'menu']);
    }

    public static function menu(): void {
        $hook = add_management_page(
            'Staging Company Manager Approval',
            'Staging Manager Approval',
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render']
        );
        if ($hook) {
            add_action('admin_print_scripts-' . $hook, [__CLASS__, 'enqueue']);
        }
    }

    private static function company(): string {
        $value = isset($_GET['company']) && is_string($_GET['company'])
            ? wp_unslash($_GET['company']) : 'all-royal-egypt';
        return preg_match('/^[a-z][a-z0-9-]{2,47}$/D', $value)
            ? $value : '';
    }

    public static function render(): void {
        if (!current_user_can('manage_options') || !self::enabled()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'mad4b'));
        }
        $company = self::company();
        ?>
        <div class="wrap" id="mad4b-staging-owner-console">
            <h1>Staging Company Manager — original owner approval</h1>
            <p>Only the original enrolled WordPress owner may approve. A server SSH key,
               WP-CLI user override or verified identity.read proof does not grant operations.write.</p>
            <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
                <label for="mad4b-staging-company">Company code</label>
                <input id="mad4b-staging-company" name="company" type="text"
                    pattern="[a-z][a-z0-9\-]{2,47}"
                    value="<?php echo esc_attr($company); ?>" required>
                <button class="button">Inspect target</button>
            </form>
            <?php if ($company !== '') : ?>
                <p><strong>Company:</strong> <?php echo esc_html($company); ?> |
                   <strong>Environment:</strong> staging</p>
                <p><strong>Assignment lifecycle:</strong> propose → approve → activate → revoke.
                   Each action requires explicit confirmation, a fresh native REST nonce,
                   exact signed binding, proposal hash and CAS revision.</p>
                <p id="mad4b-manager-notice" role="status" aria-live="polite">Reading original REST plan…</p>
                <p id="mad4b-manager-auth-state" role="status" aria-live="polite">Verifying the current owner session…</p>
                <div id="mad4b-manager-action-result" role="alert" aria-live="assertive" style="margin:12px 0;font-weight:600"></div>
                <pre id="mad4b-manager-plan" style="white-space:pre-wrap;max-width:980px"></pre>
                <p>
                    <button type="button" class="button" id="mad4b-manager-refresh">Refresh original readback</button>
                    <button type="button" class="button button-primary" id="mad4b-manager-next" disabled>Next owner action</button>
                    <button type="button" class="button" id="mad4b-manager-revoke" disabled>Revoke assignment</button>
                </p>
                <p>After activation, enroll the transport separately through original 305.
                   This console never issues a grant, activates an executor, or runs a Job.</p>
            <?php else : ?>
                <p role="alert">Invalid company code. No REST request was sent.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function enqueue(): void {
        if (!self::enabled() || !current_user_can('manage_options')) return;
        $company = self::company();
        if ($company === '') return;
        wp_enqueue_script('wp-api-fetch');
        $config = wp_json_encode([
            'company' => $company,
            'nonce' => wp_create_nonce('wp_rest'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($config)) return;
        $js = <<<'JS'
(function () {
    'use strict';
    const cfg = __CONFIG__;
    if (!window.wp || !wp.apiFetch || !wp.apiFetch.createNonceMiddleware) return;
    wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(cfg.nonce));
    const root = document.getElementById('mad4b-staging-owner-console');
    if (!root) return;
    const notice = root.querySelector('#mad4b-manager-notice');
    const display = root.querySelector('#mad4b-manager-plan');
    const authState = root.querySelector('#mad4b-manager-auth-state');
    const actionResult = root.querySelector('#mad4b-manager-action-result');
    const refreshButton = root.querySelector('#mad4b-manager-refresh');
    const nextButton = root.querySelector('#mad4b-manager-next');
    const revokeButton = root.querySelector('#mad4b-manager-revoke');
    const allowed = ['propose', 'approve', 'activate'];
    const path = '/mad4b-central/v1/company-environments/'
        + encodeURIComponent(cfg.company) + '/staging/manager-assignment';
    let plan = null;
    let busy = false;
    function controls() {
        const ready = !busy && plan && plan.eligible === true
            && plan.environment === 'staging' && plan.company === cfg.company
            && plan.owner_action_session_verified === true
            && /^[a-f0-9]{64}$/.test(plan.binding_sha256 || '')
            && /^[a-f0-9-]{36}$/.test(plan.site_uuid || '')
            && Number.isInteger(plan.revision) && plan.revision >= 0;
        const next = ready && allowed.includes(plan.next_action) ? plan.next_action : null;
        nextButton.disabled = !next;
        nextButton.textContent = next ? 'Owner action: ' + next : 'No available approval action';
        revokeButton.disabled = !ready || !['proposed', 'approved', 'active'].includes(plan.assignment_state);
        refreshButton.disabled = busy;
    }
    function show(message) {
        notice.textContent = message;
        display.textContent = plan ? JSON.stringify(plan, null, 2) : '';
        authState.textContent = !plan || plan.eligible !== true
            ? 'Target is unavailable or its signed Staging identity is not eligible.'
            : plan.owner_action_session_verified === true
                ? 'Owner session verified by original REST. Ready for a confirmed owner action.'
                : 'Owner session not verified. Sign in as the enrolled owner and reload this page to refresh the REST nonce.';
        controls();
    }
    function outcome(message, failure) {
        // Preserve the action result across all independent GET/readback refreshes.
        // Never hide a denied POST by replacing it with a successful GET message.
        actionResult.textContent = message;
        actionResult.style.color = failure ? '#b32d2e' : '#135e20';
    }
    async function read(manageBusy = true) {
        if (manageBusy) busy = true;
        controls();
        try {
            plan = await wp.apiFetch({path});
            show('Original REST readback: ' + (plan.assignment_state || 'unknown')
                 + ' | revision ' + String(plan.revision));
            return plan;
        } catch (e) {
            plan = null;
            show('Read denied or unavailable: ' + (e.message || String(e)));
            return null;
        } finally {
            if (manageBusy) busy = false;
            controls();
        }
    }
    async function transition(action) {
        if (busy) return;
        busy = true;
        outcome('Checking current owner session and revision…', false);
        controls();
        try {
            // Refetch immediately before every owner choice. Never reuse a prior CAS version.
            const fresh = await wp.apiFetch({path});
            plan = fresh;
            if (!fresh || fresh.eligible !== true || fresh.environment !== 'staging'
                || fresh.company !== cfg.company
                || fresh.owner_action_session_verified !== true
                || !/^[a-f0-9]{64}$/.test(fresh.binding_sha256 || '')
                || !Number.isInteger(fresh.revision) || fresh.revision < 0) {
                throw new Error('Signed Staging assignment is not eligible.');
            }
            if (action === 'revoke'
                ? !['proposed', 'approved', 'active'].includes(fresh.assignment_state)
                : fresh.next_action !== action) {
                throw new Error('Assignment state changed; refresh the plan.');
            }
            if (action !== 'propose' && !/^[a-f0-9]{64}$/.test(fresh.proposal_sha256 || '')) {
                throw new Error('Exact original proposal hash is missing.');
            }
            const summary = 'Company: ' + fresh.company + '\nEnvironment: staging\n'
                + 'Origin: ' + fresh.canonical_origin + '\nSite UUID: ' + fresh.site_uuid
                + '\nBinding SHA-256: ' + fresh.binding_sha256
                + '\nRevision: ' + fresh.revision
                + '\nProposal SHA-256: ' + (fresh.proposal_sha256 || '(new proposal)')
                + '\nAction: ' + action
                + '\n\nThis is an original owner approval action; it does NOT issue SSH grants.';
            if (!window.confirm(summary)) {
                outcome('Owner action cancelled; assignment remains unchanged.', false);
                show('Owner action cancelled. No transition requested.');
                return;
            }
            const payload = {
                action,
                expected_revision: fresh.revision,
                expected_binding_sha256: fresh.binding_sha256
            };
            if (action !== 'propose') payload.expected_proposal_sha256 = fresh.proposal_sha256;
            outcome('Submitting original REST ' + action + ' request…', false);
            const result = await wp.apiFetch({path, method: 'POST', data: payload});
            if (result.revision !== fresh.revision + 1
                || result.company !== fresh.company
                || result.binding_sha256 !== fresh.binding_sha256) {
                throw new Error('Unexpected original REST postcondition. Recheck independently.');
            }
            const independent = await read(false);
            const expected = {propose: 'proposed', approve: 'approved', activate: 'active', revoke: 'revoked'}[action];
            if (!independent || independent.revision !== result.revision
                || independent.assignment_state !== expected
                || independent.binding_sha256 !== fresh.binding_sha256) {
                throw new Error('Independent REST readback did not verify the expected state. Do not repeat blindly.');
            }
            outcome('Owner ' + action + ' confirmed: ' + expected
                + ' | revision ' + independent.revision + '. Continue with the next action.', false);
        } catch (e) {
            const http = e && e.data && e.data.status ? 'HTTP ' + e.data.status + ' ' : '';
            const code = e && e.code ? e.code + ': ' : '';
            const guidance = e && e.code === 'mad4b_environment_manager_assignment_locked'
                ? ' The server is busy with another assignment request. Refresh and confirm again after the lock is released.'
                : e && e.code === 'mad4b_environment_manager_assignment_lock_backend_unavailable'
                    ? ' Database locking is unavailable; no assignment was changed. Ask the operator to inspect the database connection.'
                    : '';
            outcome('Owner ' + action + ' failed or could not be verified: '
                + http + code + (e.message || String(e))
                + guidance + ' Check the readback below; if the session expired, sign in and reload.', true);
            await read(false);
        } finally {
            busy = false;
            controls();
        }
    }
    refreshButton.addEventListener('click', function () { read(true); });
    nextButton.addEventListener('click', function () {
        if (plan && allowed.includes(plan.next_action)) transition(plan.next_action);
    });
    revokeButton.addEventListener('click', function () { transition('revoke'); });
    read();
})();
JS;
        $js = str_replace('__CONFIG__', $config, $js);
        wp_add_inline_script('wp-api-fetch', $js, 'after');
    }
}
add_action('plugins_loaded', [MAD4B_Staging_Owner_Approval_Console_V1::class, 'boot'], 95);
