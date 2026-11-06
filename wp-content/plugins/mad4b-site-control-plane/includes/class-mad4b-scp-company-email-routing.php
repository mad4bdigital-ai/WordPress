<?php
/**
 * Company Email Identity & Notification Routing.
 * Read-only discovery and governed binding resolution. No SMTP secrets, sends,
 * form mutations, cross-tenant inheritance, or automatic approval.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Company_Email_Routing {
    const CONTRACT = 'mad4b.company-email-routing.v1';
    const VERIFY_OPTION = 'mad4b_company_email_verification_v1';
    const BINDINGS_OPTION = 'mad4b_company_notification_bindings_v1';
    const NS = 'mad4b/v1';
    private static $scan_cache = null;

    private static function is_company_admin(): bool {
        return current_user_can('mad4b_manage_company_operations');
    }
    private static function email_list($raw): array {
        if (!is_string($raw)) return [];
        $items = preg_split('/[,;\s]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($items)) return [];
        $out = [];
        foreach ($items as $item) {
            $address = strtolower(sanitize_email($item));
            if (!$address || !is_email($address)) return [];
            $out[$address] = true;
        }
        return array_keys($out);
    }
    private static function domain(string $address): string {
        $at = strrpos($address, '@');
        return $at === false ? '' : strtolower(substr($address, $at + 1));
    }
    private static function ensure_company_source(): void {
        if (!class_exists('MAD4B_SCP_Company_Communication', false))
            require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-company-communication.php';
        if (!class_exists('MAD4B_SCP_Site_Profile', false))
            require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-site-profile.php';
    }
    public static function identity(): array {
        self::ensure_company_source();
        $config = MAD4B_SCP_Company_Communication::config();
        $profile = MAD4B_SCP_Site_Profile::profile();
        $uuid = (string)($profile['site_uuid'] ?? '');
        $environment = wp_get_environment_type();
        $email = strtolower(sanitize_email((string)($config['transactional_from_email'] ?? '')));
        $valid = $uuid !== '' && !empty(MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
            && $email !== '' && is_email($email);
        $source = ['site_uuid' => $uuid, 'environment' => $environment,
            'from_email' => $email, 'reply_to' => (string)($config['traveler_reply_to'] ?? ''),
            'bookings_inbox' => (string)($config['bookings_inbox'] ?? '')];
        $digest = hash('sha256', wp_json_encode($source, JSON_UNESCAPED_SLASHES));
        $receipt = get_option(self::VERIFY_OPTION, []);
        $verified = $valid && is_array($receipt)
            && ($receipt['contract'] ?? '') === 'mad4b.company-email-verification.v1'
            && ($receipt['site_uuid'] ?? '') === $uuid
            && ($receipt['identity_sha256'] ?? '') === $digest
            && ($receipt['status'] ?? '') === 'VERIFIED'
            && !empty($receipt['evidence_digest'])
            && !empty($receipt['verified_at'])
            && !empty($receipt['verifier_user_id']);
        return ['contract' => 'mad4b.company-email-identity.v1',
            'site_uuid' => $uuid, 'environment' => $environment,
            'source' => 'company-communication-operations',
            'company_display_name' => (string)($config['company_display_name'] ?? ''),
            'email_domain' => self::domain($email),
            'identity_sha256' => $digest,
            'valid' => (bool)$valid,
            'status' => $verified ? 'VERIFIED' : ($valid ? 'PENDING_VERIFICATION' : 'INCOMPLETE'),
            'verified' => (bool)$verified,
            'smtp_verified' => MAD4B_SCP_Company_Communication::smtpConfigured()];
    }
    private static function classify(string $mail, string $officialDomain): string {
        if (str_contains($mail, '{') || str_contains($mail, '}')) return 'unresolved_template_reference';
        $domain = self::domain($mail);
        if ($domain === '') return 'invalid_or_empty';
        if ($officialDomain === '') return 'unknown_without_company_identity';
        if ($domain === $officialDomain) return 'company_domain';
        if (str_ends_with($domain, '.' . $officialDomain)) return 'company_subdomain_requires_review';
        return 'foreign_domain_requires_review';
    }
    /** No email local parts or form data exposed in diagnostics. */
    public static function scan_legacy(): array {
        if (self::$scan_cache !== null) return self::$scan_cache;
        global $wpdb;
        $officialDomain = self::identity()['email_domain'];
        $issues = [];
        $feeds = 0;
        $table = $wpdb->prefix . 'fluentform_form_meta';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists === $table) {
            // Bound read on site-local Fluent Forms metadata, not submissions.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id,form_id,value FROM {$table} WHERE meta_key = %s ORDER BY id ASC LIMIT 3000",
                'notifications'), ARRAY_A);
            foreach ((array)$rows as $row) {
                $data = json_decode((string)$row['value'], true);
                if (!is_array($data)) continue;
                $feeds++;
                $raw = (string)($data['sendTo']['email'] ?? '');
                $active = !empty($data['enabled']);
                $values = preg_split('/[,;\s]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
                foreach ((array)$values as $value) {
                    $kind = self::classify($value, $officialDomain);
                    if (!in_array($kind, ['foreign_domain_requires_review','company_subdomain_requires_review','invalid_or_empty','unknown_without_company_identity','unresolved_template_reference'], true)) continue;
                    $issues[] = ['consumer' => 'fluent_forms',
                        'form_id' => (int)$row['form_id'], 'feed_id' => (int)$row['id'],
                        'active' => $active, 'kind' => $kind,
                        'domain' => self::domain($value)];
                }
            }
        }
        foreach (['admin_email','woocommerce_email_from_address'] as $key) {
            $mail = (string)get_option($key, '');
            if ($mail === '') continue;
            $kind = self::classify($mail, $officialDomain);
            if ($kind === 'company_domain') continue;
            $issues[] = ['consumer' => 'wordpress_option', 'option' => $key,
                'active' => true, 'kind' => $kind, 'domain' => self::domain($mail)];
        }
        $wc = get_option('woocommerce_new_order_settings', []);
        if (is_array($wc) && !empty($wc['recipient'])) {
            foreach (preg_split('/[,;\s]+/', trim((string)$wc['recipient']), -1, PREG_SPLIT_NO_EMPTY) as $mail) {
                $kind = self::classify($mail, $officialDomain);
                if ($kind === 'company_domain') continue;
                $issues[] = ['consumer' => 'woocommerce_new_order', 'active' => true,
                    'kind' => $kind, 'domain' => self::domain($mail)];
            }
        }
        self::$scan_cache = ['contract' => 'mad4b.company-email-legacy-scan.v1',
            'site_uuid' => self::identity()['site_uuid'],
            'official_domain' => $officialDomain, 'form_notification_feeds_scanned' => $feeds,
            'issues' => array_slice($issues, 0, 200), 'issues_total' => count($issues),
            'active_foreign_count' => count(array_filter($issues, static fn($i) =>
                !empty($i['active']) && in_array(($i['kind'] ?? ''), ['foreign_domain_requires_review','company_subdomain_requires_review'], true))),
            'read_only' => true, 'messages_sent' => 0];
        return self::$scan_cache;
    }
    public static function bindings(): array {
        $identity = self::identity();
        $raw = get_option(self::BINDINGS_OPTION, []);
        if (!is_array($raw) || ($raw['contract'] ?? '') !== 'mad4b.company-notification-bindings.v1'
            || ($raw['site_uuid'] ?? '') !== $identity['site_uuid']
            || ($raw['identity_sha256'] ?? '') !== $identity['identity_sha256'])
            return [];
        $entries = $raw['entries'] ?? [];
        return is_array($entries) ? array_slice($entries, 0, 200) : [];
    }
    private static function allowed_event(string $event): bool {
        return in_array($event, ['new_request','booking_confirmed','sales_quote',
            'traveler_support','operations_internal','operations_external'], true);
    }
    /** Resolve event-scoped company destinations; no implicit legacy fallback. */
    public static function resolve(string $consumer, string $event, array $context = []): array {
        self::ensure_company_source();
        $id = self::identity();
        $reasons = [];
        if (!in_array($consumer, ['fluent_forms','woocommerce','fluent_boards','company_system'], true))
            $reasons[] = 'consumer_unregistered';
        if (!self::allowed_event($event)) $reasons[] = 'event_unregistered';
        if (!$id['verified']) $reasons[] = 'company_identity_not_verified';
        if ($event === 'operations_external') $reasons[] = 'external_order_assignment_required';
        $recipients = (!$reasons && class_exists('MAD4B_SCP_Company_Communication'))
            ? MAD4B_SCP_Company_Communication::recipients($event) : [];
        if (!$recipients && !$reasons) $reasons[] = 'no_eligible_recipients';
        $configured = MAD4B_SCP_Company_Communication::config();
        $wouldSend = !$reasons && $id['smtp_verified']
            && $configured['outbound_email_policy'] === 'enabled'
            && MAD4B_SCP_Company_Communication::canSend();
        return ['contract' => self::CONTRACT, 'consumer' => $consumer,
            'event' => $event, 'site_uuid' => $id['site_uuid'],
            'identity_sha256' => $id['identity_sha256'],
            'status' => $reasons ? 'BLOCKED' : 'PREVIEW',
            'reasons' => array_values(array_unique($reasons)),
            'recipients' => $recipients, // internal use only; REST strips this key.
            'recipient_count' => count($recipients),
            'recipient_domains' => array_values(array_unique(array_map(
                static fn($x) => self::domain($x), $recipients))),
            'would_send' => (bool)$wouldSend, 'messages_sent' => 0, 'dry_run' => true];
    }
    /**
     * Opt-in form consumer. Bindings require exact current notification feed
     * identity and revision; unknown/foreign feed is blocked (never rerouted).
     * Traveler-directed field notifications preserve their native recipient
     * only while outgoing transport is independently authorized.
     */
    public static function fluent_form_recipient($address, $notification, $submitted, $form) {
        self::ensure_company_source();
        $config = MAD4B_SCP_Company_Communication::config();
        if ($config['fluent_forms_routing_policy'] !== 'enabled') return $address;
        if (!is_object($form) || !is_array($notification)) return '';
        if (!MAD4B_SCP_Company_Communication::canSend()) return '';
        $type = (string)($notification['sendTo']['type'] ?? '');
        if ($type !== 'email') {
            return $config['notify_traveler'] === 'enabled' ? $address : '';
        }
        $name = (string)($notification['name'] ?? '');
        if ($name === '') return '';
        $formId = (int)($form->id ?? 0);
        $matched = [];
        foreach (self::bindings() as $entry) {
            if (!is_array($entry) || ($entry['consumer'] ?? '') !== 'fluent_forms') continue;
            if ((int)($entry['form_id'] ?? 0) !== $formId) continue;
            if (($entry['notification_name'] ?? '') !== $name) continue;
            $matched[] = $entry;
        }
        if (count($matched) !== 1) return '';
        $binding = $matched[0];
        $event = (string)($binding['event'] ?? '');
        if (!self::allowed_event($event)) return '';
        $feedId = (int)($binding['feed_id'] ?? 0);
        if (!$feedId || empty($binding['source_sha256'])) return '';
        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_form_meta';
        $row = $wpdb->get_row($wpdb->prepare("SELECT form_id, value FROM {$table} WHERE id=%d AND meta_key=%s",
            $feedId, 'notifications'), ARRAY_A);
        if (!$row || (int)$row['form_id'] !== $formId) return '';
        $actual = json_decode((string)$row['value'], true);
        if (!is_array($actual) || empty($actual['enabled'])
            || ($actual['name'] ?? '') !== $name
            || !hash_equals((string)$binding['source_sha256'], hash('sha256', (string)$row['value'])))
            return '';
        $plan = self::resolve('fluent_forms', $event, ['form_id' => $formId, 'feed_id' => $feedId]);
        return $plan['would_send'] ? implode(', ', $plan['recipients']) : '';
    }
    public static function readiness_issues(): array {
        $issues = [];
        if (!self::identity()['verified']) $issues[] = 'company_email_identity_not_verified';
        $scan = self::scan_legacy();
        if ($scan['active_foreign_count'] > 0) $issues[] = 'legacy_foreign_recipient_review_required';
        return $issues;
    }
    public static function rest_permission() {
        return self::is_company_admin();
    }
    public static function register(): void {
        add_filter('fluentform/email_to', [__CLASS__,'fluent_form_recipient'], 100, 4);
        // Consumer adapters are opt-in. Nothing is sent by installing this layer.
        add_filter('fluentform/email_to', [__CLASS__, 'fluent_form_recipient'], 100, 4);
        add_action('rest_api_init', static function() {
            $cap = [__CLASS__,'rest_permission'];
            register_rest_route(self::NS, '/admin/company-email-identity', [
                'methods' => 'GET', 'permission_callback' => $cap,
                'callback' => static function() {return rest_ensure_response(self::identity());}]);
            register_rest_route(self::NS, '/admin/company-email-legacy-scan', [
                'methods' => 'GET', 'permission_callback' => $cap,
                'callback' => static function() {return rest_ensure_response(self::scan_legacy());}]);
            register_rest_route(self::NS, '/admin/company-notification-routing/preview', [
                'methods' => 'POST', 'permission_callback' => $cap,
                'callback' => static function($request) {
                    $consumer = sanitize_key((string)$request->get_param('consumer'));
                    $event = sanitize_key((string)$request->get_param('event'));
                    $result = self::resolve($consumer,$event);
                    unset($result['recipients']); // Only aggregate diagnostics leave server.
                    return rest_ensure_response($result);
                }]);
        }, 31);
    }
}
MAD4B_SCP_Company_Email_Routing::register();
