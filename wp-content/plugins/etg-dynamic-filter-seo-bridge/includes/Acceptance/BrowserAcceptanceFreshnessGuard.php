<?php
namespace ETG\DynamicFilterSEOBridge\Acceptance;

final class BrowserAcceptanceFreshnessGuard {
    const CONTRACT = 'etg.dfsb.browser-acceptance-freshness.v1';
    const CHALLENGE_CONTRACT = 'etg.dfsb.browser-acceptance-challenge.v1';
    const PROVIDER_ID = 'etg-dfsb';
    const TTL_SECONDS = 900;
    const FUTURE_SKEW_SECONDS = 30;

    public static function register(): void {
        if (!function_exists('add_filter')) return;
        add_filter('mad4b_browser_acceptance_providers', array(__CLASS__, 'decorateProviders'), 30, 1);
    }

    public static function decorateProviders($providers): array {
        $providers = is_array($providers) ? $providers : array();
        if (!isset($providers[self::PROVIDER_ID]) || !is_array($providers[self::PROVIDER_ID])) return $providers;

        $provider = $providers[self::PROVIDER_ID];
        $planCallback = $provider['plan_callback'] ?? null;
        $resultCallback = $provider['result_callback'] ?? null;
        $capabilitiesCallback = $provider['capabilities_callback'] ?? null;
        if (!is_callable($planCallback) || !is_callable($resultCallback) || !is_callable($capabilitiesCallback)) return $providers;

        $provider['capabilities_callback'] = static function () use ($capabilitiesCallback): array {
            try {
                $capabilities = call_user_func($capabilitiesCallback);
            } catch (\Throwable $error) {
                $capabilities = array('error'=>'provider_capabilities_exception');
            }
            $capabilities = is_array($capabilities) ? $capabilities : array('error'=>'provider_capabilities_invalid');
            $capabilities['freshness_challenge'] = array(
                'contract'=>self::CHALLENGE_CONTRACT,
                'required_for_observed_evidence'=>true,
                'stateless'=>true,
                'server_signed'=>true,
                'ttl_seconds'=>self::TTL_SECONDS,
                'nonce_bytes'=>16,
                'authorizing'=>false,
                'persistent_mutation'=>false,
            );
            return $capabilities;
        };

        $provider['plan_callback'] = static function (array $request) use ($planCallback): array {
            try {
                $plan = call_user_func($planCallback, $request);
            } catch (\Throwable $error) {
                return self::blockedPlan('', array('provider_plan_exception'));
            }
            if (!is_array($plan)) return self::blockedPlan('', array('provider_plan_invalid'));
            if ('ready' !== (string)($plan['state'] ?? '')) return $plan;

            $profileId = self::cleanId($plan['profile_id'] ?? ($request['profile_id'] ?? ''));
            $planDigest = strtolower(trim((string)($plan['plan_digest'] ?? '')));
            if ('' === $profileId || !preg_match('/^[a-f0-9]{64}$/', $planDigest)) {
                return self::blockedPlan($profileId, array('browser_challenge_plan_binding_invalid'));
            }
            $challenge = self::issueChallenge($profileId, $planDigest);
            if (!$challenge) return self::blockedPlan($profileId, array('browser_challenge_unavailable'));
            $plan['challenge'] = $challenge;
            return $plan;
        };

        $provider['result_callback'] = static function (array $request) use ($resultCallback): array {
            $evidence = $request['evidence'] ?? null;
            if (!is_array($evidence) || !$evidence) {
                try {
                    $result = call_user_func($resultCallback, $request);
                } catch (\Throwable $error) {
                    return self::infrastructureResult(self::cleanId($request['profile_id'] ?? ''), array('provider_result_exception'));
                }
                return is_array($result) ? $result : self::infrastructureResult(self::cleanId($request['profile_id'] ?? ''), array('provider_result_invalid'));
            }

            $profileId = self::cleanId($request['profile_id'] ?? '');
            $planDigest = strtolower(trim((string)($request['plan_digest'] ?? '')));
            $challenge = $evidence['challenge'] ?? null;
            $reasons = self::validateChallenge($challenge, $profileId, $planDigest);
            $nonce = is_array($challenge) ? strtolower(trim((string)($challenge['nonce'] ?? ''))) : '';
            if (!$reasons) {
                foreach ((array)($evidence['cases'] ?? array()) as $case) {
                    if (!is_array($case) || !hash_equals($nonce, strtolower(trim((string)($case['challenge_nonce'] ?? ''))))) {
                        $reasons[] = 'browser_challenge_nonce_mismatch';
                        break;
                    }
                }
            }
            if ($reasons) return self::infrastructureResult($profileId, $reasons, $planDigest);

            // Freshness metadata is owned and consumed by this decorator. Do not
            // leak it into the canonical provider evidence envelope after it has
            // been verified, because the provider intentionally rejects unknown
            // top-level fields and binds the per-case nonce in canonical evidence.
            unset($request['evidence']['challenge']);

            try {
                $result = call_user_func($resultCallback, $request);
            } catch (\Throwable $error) {
                return self::infrastructureResult($profileId, array('provider_result_exception'), $planDigest);
            }
            return is_array($result) ? $result : self::infrastructureResult($profileId, array('provider_result_invalid'), $planDigest);
        };

        $providers[self::PROVIDER_ID] = $provider;
        return $providers;
    }

    private static function issueChallenge(string $profileId, string $planDigest): array {
        if (!function_exists('wp_salt')) return array();
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (\Throwable $error) {
            return array();
        }
        $issuedAt = time();
        $expiresAt = $issuedAt + self::TTL_SECONDS;
        $signature = self::challengeSignature($profileId, $planDigest, $nonce, $issuedAt, $expiresAt);
        if ('' === $signature) return array();
        return array(
            'contract'=>self::CHALLENGE_CONTRACT,
            'nonce'=>$nonce,
            'issued_at'=>$issuedAt,
            'expires_at'=>$expiresAt,
            'signature'=>$signature,
        );
    }

    private static function validateChallenge($challenge, string $profileId, string $planDigest): array {
        $reasons = array();
        if (!is_array($challenge)) return array('browser_challenge_required');
        if (self::CHALLENGE_CONTRACT !== (string)($challenge['contract'] ?? '')) $reasons[] = 'browser_challenge_contract_invalid';
        $nonce = strtolower(trim((string)($challenge['nonce'] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) $reasons[] = 'browser_challenge_nonce_invalid';
        $issuedAt = isset($challenge['issued_at']) && is_numeric($challenge['issued_at']) ? (int)$challenge['issued_at'] : 0;
        $expiresAt = isset($challenge['expires_at']) && is_numeric($challenge['expires_at']) ? (int)$challenge['expires_at'] : 0;
        if ($issuedAt < 1 || $expiresAt <= $issuedAt || ($expiresAt - $issuedAt) > self::TTL_SECONDS) $reasons[] = 'browser_challenge_window_invalid';
        $now = time();
        if ($issuedAt > ($now + self::FUTURE_SKEW_SECONDS)) $reasons[] = 'browser_challenge_not_yet_valid';
        if ($expiresAt > 0 && $now > $expiresAt) $reasons[] = 'browser_challenge_expired';
        if ('' === $profileId || !preg_match('/^[a-f0-9]{64}$/', $planDigest)) $reasons[] = 'browser_challenge_plan_binding_invalid';
        $signature = strtolower(trim((string)($challenge['signature'] ?? '')));
        $expected = self::challengeSignature($profileId, $planDigest, $nonce, $issuedAt, $expiresAt);
        if (!preg_match('/^[a-f0-9]{64}$/', $signature) || '' === $expected || !hash_equals($expected, $signature)) $reasons[] = 'browser_challenge_signature_invalid';
        return array_values(array_unique($reasons));
    }

    private static function challengeSignature(string $profileId, string $planDigest, string $nonce, int $issuedAt, int $expiresAt): string {
        if (!function_exists('wp_salt')) return '';
        $secret = (string)wp_salt('auth');
        if ('' === $secret) return '';
        $message = implode('|', array('browser_challenge', self::CHALLENGE_CONTRACT, self::PROVIDER_ID, $profileId, $planDigest, $nonce, (string)$issuedAt, (string)$expiresAt));
        return hash_hmac('sha256', $message, $secret);
    }

    private static function infrastructureResult(string $profileId, array $reasons, string $planDigest=''): array {
        return array(
            'contract'=>'mad4b.browser-acceptance-result.v1',
            'provider_contract'=>BrowserAcceptanceProvider::CONTRACT,
            'provider_id'=>self::PROVIDER_ID,
            'profile_id'=>$profileId,
            'suite'=>'browser_runtime',
            'plan_digest'=>$planDigest,
            'verification'=>array('semantic_parity_verified'=>false,'browser_runtime_parity_verified'=>false,'verified_through'=>'none'),
            'tests'=>array(),
            'cases'=>array(),
            'case_count'=>0,
            'authority'=>array('authorizing'=>false,'persistent_mutation'=>false,'profile_mutation'=>false,'seo_publication'=>false,'production_activation'=>false,'browser_state_transient_only'=>true),
            'blocking_reasons'=>array(),
            'incomplete_evidence'=>array(),
            'defect_reasons'=>array(),
            'infrastructure_failures'=>array_values(array_unique($reasons)),
            'classification'=>'TEST_INFRASTRUCTURE_FAILURE',
            'verdict'=>'BLOCKED',
            'browser_runtime'=>'BLOCKED',
        );
    }

    private static function blockedPlan(string $profileId, array $reasons): array {
        return array(
            'contract'=>'mad4b.browser-acceptance-plan.v1',
            'provider_contract'=>BrowserAcceptanceProvider::CONTRACT,
            'state'=>'blocked',
            'provider_id'=>self::PROVIDER_ID,
            'profile_id'=>$profileId,
            'suite'=>'browser_runtime',
            'authorizing'=>false,
            'read_only'=>true,
            'cases'=>array(),
            'case_count'=>0,
            'blocking_reasons'=>array_values(array_unique($reasons)),
        );
    }

    private static function cleanId($value): string {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-z0-9][a-z0-9._\-]{0,63}$/', $value) ? $value : '';
    }
}
