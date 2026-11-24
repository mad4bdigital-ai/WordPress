<?php
/**
 * Plugin Name: MAD4B JetEngine Scoped Term CSS Identity
 * Description: Normalize the same public term/Listing CSS selectors on live and cached paths; never touch commercial markup.
 * Version: 0.2.1
 */
defined('ABSPATH') || exit;
final class MAD4B_Term_CSS_Scoped_V2 {
    private static ?array $allowed = null;
    private static int $corrected = 0;
    private static int $rejected = 0;

    private static function admitted(): bool {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || is_admin() || wp_doing_ajax() || is_user_logged_in()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || !empty($_SERVER['QUERY_STRING']) || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !is_singular('tours-and-activities')
            || !class_exists('MAD4B_Stable_Listing_CSS_Identity', false)
            || !MAD4B_Stable_Listing_CSS_Identity::cache_ready()) return false;
        $mode = (string)get_option('mad4b_term_css_scoped_v2', 'off');
        if ($mode === 'on') return true;
        return $mode === 'canary'
            && in_array(wp_get_environment_type(), ['staging','production'], true)
            && ($_SERVER['HTTP_X_MAD4B_TERM_CSS_PROOF'] ?? '') === 'strict-term-css-20261124';
    }

    private static function allowed_template(int $id): bool {
        if ($id < 1 || $id > 9999999) return false;
        if (self::$allowed === null) {
            self::$allowed = [];
            $file = WPMU_PLUGIN_DIR . '/mad4b-selective-render-policy.json';
            if (!is_file($file) || is_link($file) || !is_readable($file)) return false;
            $raw = file_get_contents($file);
            $policy = is_string($raw) && strlen($raw) < 60000 ? json_decode($raw, true) : null;
            if (!is_array($policy) || ($policy['contract'] ?? '') !== 'mad4b.render-query.public-fragments.v1'
                || ($policy['environment'] ?? '') !== wp_get_environment_type()
                || ($policy['site_host'] ?? '') !== wp_parse_url(home_url('/'), PHP_URL_HOST)
                || ($policy['status'] ?? '') !== 'active') return false;
            foreach ((array)($policy['templates'] ?? []) as $key => $cfg) {
                if (!is_array($cfg)
                    || empty($cfg['tour_public_fragment'])
                    || ($cfg['tour_css_identity'] ?? '') !== 'jet_term_fallback'
                    || !in_array('destinations', (array)($cfg['taxonomies'] ?? []), true)) continue;
                $template = (int)$key;
                if ($template <= 0 || (string)$template !== (string)$key) continue;
                self::$allowed[$template] = (string)($cfg['elementor_sha256'] ?? '');
            }
        }
        if (!isset(self::$allowed[$id])) return false;
        $raw = get_post_meta($id, '_elementor_data', true);
        if (!is_string($raw) || strlen($raw) > 500000
            || !hash_equals(self::$allowed[$id], hash('sha256', $raw))) return false;
        return true;
    }

    public static function canonical(string $html, array $templates): string {
        if (strlen($html) < 100 || strlen($html) > 5000000 || !str_contains($html, 'jet-listing-dynamic-post-')) return $html;
        // Complete consecutive fragment: outer item with term ID; optional
        // inline CSS; then the immediate Elementor Jet Listing document.
        // Never match other widgets, menu HTML without inline CSS, or arbitrary
        // CSS/text outside the exact outer item.
        $pattern = '~(<div\b[^>]{0,1300}\bclass="[^"]{0,800}\bjet-listing-grid__item\s+jet-listing-dynamic-post-([0-9]{1,12})\s+)(elementor-dcss-(?:mad4b-[a-f0-9]{16}|[0-9]{10,22}))([^"]{0,500}"[^>]{0,850}\bdata-post-id="\2"[^>]{0,850}>)(\s*<style\b[^>]{0,200}>.{50,12000}?</style>)(\s*<div\s+data-elementor-type="jet-listing-items"\s+data-elementor-id="([0-9]{1,12})")~s';
        $result = preg_replace_callback($pattern, static function(array $m) use ($templates): string {
            $term = (int)$m[2];
            $doc  = (int)$m[7];
            $original = $m[3];
            if ($term <= 0 || !isset($templates[$doc])) return $m[0];
            $css = $m[5];
            // Inline selector must actually target THIS term and this token.
            // A different tag/document/selector is never changed or cached.
            if (!str_contains($css, '.jet-listing-dynamic-post-' . $term . ':not(')) {
                self::$rejected++;
                return $m[0];
            }
            preg_match_all('/elementor-dcss-(?:mad4b-[a-f0-9]{16}|[0-9]{10,22})/', $css, $tokens);
            $unique = array_values(array_unique($tokens[0]));
            if (count($unique) !== 1) {
                self::$rejected++;
                return $m[0];
            }
            $styleToken = $unique[0];
            $new = 'elementor-dcss-mad4b-' . substr(hash('sha256', $term . ':' . $doc), 0, 16);
            $fallback = 'elementor-dcss-mad4b-' . substr(hash('sha256', $term . ':listing'), 0, 16);
            $objectToken = 'elementor-dcss-mad4b-' . substr(hash('sha256', 'WP_Term:' . $term), 0, 16);
            // A native MISS and Redis HIT may carry DIFFERENT legacy stable
            // tokens: the outer class can inherit the first matching menu
            // item (e.g. term:67125), while the inner inline CSS uses the
            // governor's term:listing fallback. Recognize only proven
            // term-local sources; never replace arbitrary CSS selectors.
            $trusted = $styleToken === $original
                || $styleToken === $fallback
                || $styleToken === $objectToken
                || $styleToken === $new
                || preg_match('/^elementor-dcss-[0-9]{10,22}$/D', $styleToken);
            if (!$trusted || !str_contains($css, '.' . $styleToken)) {
                self::$rejected++;
                return $m[0];
            }
            if ($original === $new && $styleToken === $new) return $m[0];
            self::$corrected++;
            return $m[1] . $new . $m[4] . str_replace($styleToken, $new, $css) . $m[6];
        }, $html);
        return is_string($result) ? $result : $html;
    }

    public static function normalize($buffer) {
        if (!is_string($buffer) || !self::admitted()) return $buffer;
        $templates = [];
        // Only whitelist a pinned, site-local public term listing by content digest.
        if (self::$allowed === null) self::allowed_template(1);
        foreach ((array)self::$allowed as $id => $_) {
            if (self::allowed_template((int)$id)) $templates[(int)$id] = true;
        }
        if (!$templates) return $buffer;
        $out = self::canonical($buffer, $templates);
        if (($_SERVER['HTTP_X_MAD4B_TERM_CSS_PROOF'] ?? '') === 'strict-term-css-20261124'
            && in_array(wp_get_environment_type(), ['staging','production'], true)) {
            @file_put_contents('/tmp/mad4b-term-css-v2-proof-20261124.jsonl',
                wp_json_encode(['changed'=>self::$corrected,'rejected'=>self::$rejected,
                  'before'=>hash('sha256',$buffer),'after'=>hash('sha256',$out),
                  'len'=>strlen($buffer)]).PHP_EOL, FILE_APPEND|LOCK_EX);
        }
        return $out;
    }
}
add_filter('litespeed_buffer_before', ['MAD4B_Term_CSS_Scoped_V2','normalize'], 10020, 1);
