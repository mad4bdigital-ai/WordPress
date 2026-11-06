<?php
/**
 * Plugin Name: MAD4B Persistent Site Shell
 * Description: Portable, local-only opt-in navigation preserving the header and footer for compatible public pages.
 * Version: 0.5.1
 * Requires PHP: 7.4
 * Text Domain: mad4b-persistent-site-shell
 */
defined('ABSPATH') || exit;
final class MAD4B_Persistent_Site_Shell {
    const OPT = 'mad4b_persistent_site_shell_v1';
    private static $page_template = '';
    public static function settings(): array {
        $raw = get_option(self::OPT, []);
        return is_array($raw) ? $raw : [];
    }
    public static function identity(): array {
        // A portable site derives origin and tenant from its own WordPress database.
        $home = untrailingslashit((string)get_option('home', ''));
        $scheme = (string)wp_parse_url($home, PHP_URL_SCHEME);
        $host = (string)wp_parse_url($home, PHP_URL_HOST);
        $profile = get_option('mad4b_scp_brand_context_profile_v1', []);
        $uuid = is_array($profile) ? (string)($profile['site_uuid'] ?? '') : '';
        $env = wp_get_environment_type();
        if (!in_array($scheme,['http','https'],true) || $host === ''
            || !wp_is_uuid($uuid) || ($env==='production' && $scheme!=='https'))
            return [];
        if (defined('MAD4B_TENANT_SITE_UUID') && $uuid !== (string)MAD4B_TENANT_SITE_UUID) return [];
        if (defined('MAD4B_TENANT_ORIGIN')
            && strtolower($home) !== strtolower(untrailingslashit((string)MAD4B_TENANT_ORIGIN))) return [];
        if (class_exists('MAD4B_SCP_Site_Profile',false)
            && method_exists('MAD4B_SCP_Site_Profile','status')) {
            $status = MAD4B_SCP_Site_Profile::status();
            if (!is_array($status) || empty($status['configured'])
                || empty($status['origin_match']) || empty($status['environment_match'])
                || empty($status['deployment_binding_match'])
                || (string)($status['site_uuid']??'') !== $uuid) return [];
        }
        return [
            'origin' => $home, 'uuid' => $uuid,
            'language' => (string)apply_filters('wpml_current_language',get_locale()),
            'environment' => $env
        ];
    }
    public static function sanitize($input): array {
        return ['enabled' => is_array($input) && !empty($input['enabled']) ? 1 : 0];
    }
    public static function admin(): void {
        add_options_page('MAD4B Persistent Site Shell', 'MAD4B Site Shell',
            'manage_options', 'mad4b-site-shell', [__CLASS__, 'admin_page']);
    }
    public static function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        $on = !empty(self::settings()['enabled']);
        $i = self::identity();
        echo '<div class="wrap"><h1>MAD4B Persistent Site Shell</h1>';
        echo '<p>Local-only opt-in partial navigation. Only certified static content pages use a smaller fragment response. Site templates, multilingual routes and assets are discovered dynamically. Interactive pages and unsupported templates use normal navigation.</p>';
        echo '<p><strong>Site:</strong> ' . esc_html($i['origin']??'unbound') . ' | <strong>Environment:</strong> ' . esc_html($i['environment']??'unbound') . '</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('mad4b-persistent-site-shell');
        echo '<label><input name="' . esc_attr(self::OPT) . '[enabled]" type="checkbox" value="1" ' . checked($on, true, false) . ' /> Enable compatible public-page navigation</label>';
        submit_button();
        echo '</form></div>';
    }
    private static function eligible(): bool {
        if (empty(self::settings()['enabled']) || !self::identity() || is_admin() || wp_doing_ajax()
            || is_user_logged_in() || is_preview() || is_feed() || is_404()
            || !is_page() || is_customize_preview()
            || ($_SERVER['REQUEST_METHOD']??'GET') !== 'GET') return false;
        if ((function_exists('is_checkout') && is_checkout())
            || (function_exists('is_cart') && is_cart())
            || (function_exists('is_account_page') && is_account_page())) return false;
        if (isset($_GET['elementor-preview']) || isset($_GET['preview'])) return false;
        return true;
    }
    private static function harmless_cookies(): bool {
        // Analytics/attribution cookies do not identify a WordPress session.
        // Unknown, consent, commerce and login cookies require full navigation.
        $raw = (string)($_SERVER['HTTP_COOKIE'] ?? '');
        if ($raw === '') return true;
        if (strlen($raw) > 8192) return false;
        $language = strtolower((string)apply_filters('wpml_current_language', get_locale()));
        $varySeen = false;
        foreach (explode(';', $raw) as $part) {
            $pair = explode('=', trim($part), 2);
            $name = trim($pair[0]);
            if ($name === '') return false;
            // Partition local-only public cache by the exact LiteSpeed vary
            // value. Reject duplicates rather than merging distinct variants.
            if ($name === '_lscache_vary') {
                if ($varySeen || empty($pair[1]) || strlen($pair[1]) >= 256) return false;
                $varySeen = true;
                continue;
            }
            if (preg_match('/^(?:sbjs_[a-z0-9_]+|_ga(?:_[a-z0-9_]+)?|_gcl_[a-z0-9_]+|_fbp|_fbc)$/i', $name)) continue;
            if ($name === 'wp-wpml_current_language'
                && strtolower(rawurldecode($pair[1] ?? '')) === $language) continue;
            return false;
        }
        return true;
    }
    private static function safe_request(): bool {
        if (!self::eligible() || !self::harmless_cookies()
            || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_WP_NONCE'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
            || !empty($_SERVER['HTTP_X_GEO_COUNTRY'])
            || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])
            || !empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY'])) return false;
        $params=(array)$_GET;
        unset($params['mad4b_shell_fragment'], $params['mad4b_shell_v']);
        return $params === [];
    }
    public static function cache_epoch(): string {
        $i=self::identity();
        if (!$i) return '';
        $post=(int)get_queried_object_id();
        $modified=$post>0 ? (string)get_post_field('post_modified_gmt',$post) : '';
        $data=[
            $i['uuid'], $i['origin'], $i['environment'], $i['language'],
            (string)get_option('mad4b_css_ai_epoch_v1',1),
            (string)get_option('mad4b_shell_cache_epoch_v1',1),
            (string)get_option('elementor_active_kit',0),
            (string)get_option('stylesheet',''), (string)get_option('template',''),
            defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
            defined('LSCWP_V') ? LSCWP_V : '',
            defined('ICL_SITEPRESS_VERSION') ? ICL_SITEPRESS_VERSION : '',
            // Cache the exact deployed contract, not an old fragment manifest.
            (string)@filemtime(__FILE__),
            (string)@filemtime(__DIR__ . '/assets/site-shell.js'),
            // No page ID/modified here: this token is shared in menu navigation.
        ];
        return substr(hash('sha256',wp_json_encode($data)),0,24);
    }
    private static function cache_key(): string {
        $i=self::identity();
        $post=(int)get_queried_object_id();
        $modified=$post>0 ? (string)get_post_field('post_modified_gmt',$post) : '';
        $device=class_exists('MAD4B_LSC_Local_Provider_V1',false)
            && is_callable(['MAD4B_LSC_Local_Provider_V1','device'])
            ? MAD4B_LSC_Local_Provider_V1::device((string)($_SERVER['HTTP_USER_AGENT']??''))
            : (wp_is_mobile()?'mobile':'desktop');
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        return 'page:'.hash('sha256',wp_json_encode([
            $i['origin']??'', $i['uuid']??'', $i['language']??'',
            $i['environment']??'', $path, $post, $modified, $device,
            self::vary_partition(), self::cache_epoch()
        ]));
    }
    private static function vary_partition(): string {
        $raw = (string)($_SERVER['HTTP_COOKIE'] ?? '');
        if ($raw === '') return 'none';
        $value = null;
        foreach (explode(';', $raw) as $part) {
            $pair = explode('=', trim($part), 2);
            if (($pair[0] ?? '') !== '_lscache_vary') continue;
            if ($value !== null) return 'duplicate';
            $value = (string)($pair[1] ?? '');
        }
        // Opaque value never appears in keys, output, receipts or logs.
        return $value === null ? 'none' : 'v:' . hash('sha256', $value);
    }
    private static function cache_read(string $key): ?string {
        if (wp_using_ext_object_cache()) {
            $value=wp_cache_get($key,'mad4b_shell_public');
        } else {
            $value=get_transient('mad4b_shell_'.hash('sha256',$key));
        }
        if (!is_string($value) || strlen($value)>300000 || strlen($value)<100) return null;
        $d=json_decode($value,true);
        return is_array($d) && !empty($d['eligible']) && !empty($d['main'])
            && !empty($d['proof']) ? $value : null;
    }
    private static function cache_write(string $key,string $value): void {
        if (strlen($value)>300000 || strlen($value)<100) return;
        if (wp_using_ext_object_cache()) wp_cache_set($key,$value,'mad4b_shell_public',30);
        else set_transient('mad4b_shell_'.hash('sha256',$key),$value,30);
    }
    // Uses MAD4B Adaptive Assets' existing company-scoped discovery plan.
    // It does not decide page rendering: the original DOM/asset checks remain authoritative.
    public static function navigation_preflight(): array {
        $full = static function(string $reason): array {
            return ['mode'=>'full','reason'=>$reason,'contract'=>'mad4b.shell-adaptive-preflight.v1'];
        };
        $i=self::identity();
        if (!$i) return $full('identity_unavailable');
        if (!is_page() || !self::safe_request()) return $full('request_requires_full');
        $snapshot=get_option('mad4b_adaptive_presentation_discovery_v1',[]);
        $plan=get_option('mad4b_adaptive_theme_builder_priority_v1',[]);
        if (!is_array($snapshot) || !is_array($plan)
            || ($snapshot['contract']??'')!=='mad4b.presentation-discovery.v1'
            || ($plan['contract']??'')!=='mad4b.theme-builder-priority.v1'
            || ($snapshot['site_uuid']??'')!==$i['uuid']
            || ($snapshot['site_host']??'')!==(string)wp_parse_url($i['origin'],PHP_URL_HOST))
            return $full('adaptive_inventory_missing_or_mismatched');
        if (empty($snapshot['scanned_at']) || !empty($snapshot['truncated'])
            || !empty($plan['truncated'])
            || (int)get_option('mad4b_adaptive_presentation_scan_needed_v1',0)!==0)
            return $full('adaptive_inventory_stale_or_partial');
        if (!class_exists('\\ElementorPro\\Modules\\ThemeBuilder\\Module')) {
            return $full('theme_builder_unavailable');
        }
        $documents=[];
        foreach ((array)($plan['templates']??[]) as $entry) {
            if (!empty($entry['document_id'])) $documents[(int)$entry['document_id']]=$entry;
        }
        $selected=[];
        try {
            $manager=\ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
            foreach (['header','single','footer'] as $location) {
                foreach ((array)$manager->get_documents_for_location($location) as $id=>$doc) {
                    $selected[$location][]=(int)$id;
                }
            }
        } catch (\Throwable $e) {
            return $full('theme_builder_selection_failed');
        }
        $static=['heading','text-editor','image','divider','spacer','icon','image-box','icon-box'];
        foreach ($selected as $location=>$ids) {
            foreach ($ids as $id) {
                if (!isset($documents[$id])) return $full('theme_template_not_indexed');
                $doc=$documents[$id];
                if (!empty($doc['preview_truncated']))return $full('theme_template_inventory_partial');
                foreach ((array)($doc['top_to_bottom']??[]) as $node) {
                    $type=(string)($node['type']??'');
                    // Header and footer stay mounted. Their structure is compared
                    // again by the browser, so ordinary menu widgets are safe.
                    // Preserved header/footer widgets do not need reinitialization;
                    // Elementor's selected theme locations and the browser's
                    // header/footer + CSS/JS proofs remain the final safety check.
                    if ($location==='single'
                        && (!empty($node['conditional']) || !empty($node['dynamic'])
                        || !empty($node['provider_signals'])
                        || !empty($node['template_dependency']) || !empty($node['query_dependency'])))
                        return $full('theme_dynamic_dependencies_single');
                    if ($location==='single'
                        && !in_array($type,['section','column','container','e-con','e-section','inner-section'],true)
                        && !in_array($type,$static,true)) return $full('theme_widget_reinit_not_verified');
                }
            }
        }
        $post_id=(int)get_queried_object_id();
        if (!$post_id)return $full('page_context_missing');
        $tree=json_decode((string)get_post_meta($post_id,'_elementor_data',true),true);
        if (is_array($tree)) {
            $walk=static function(array $items,int $depth=0)use(&$walk,$static): bool {
                if ($depth>35)return false;
                foreach($items as $node) {
                    if (!is_array($node))continue;
                    $type=(string)($node['widgetType']??'');
                    $settings=(array)($node['settings']??[]);
                    if ($type!=='' && !in_array($type,$static,true))return false;
                    if (!empty($settings['jedv_enabled'])||!empty($settings['__dynamic__'])
                        || !empty($settings['display_condition_list']))return false;
                    if (!empty($node['elements']) && is_array($node['elements'])
                        && !$walk($node['elements'],$depth+1))return false;
                }
                return true;
            };
            if (!$walk($tree))return $full('destination_main_unverified_dependencies');
        }
        return [
            'contract'=>'mad4b.shell-adaptive-preflight.v1','mode'=>'partial_candidate',
            'reason'=>'static_page_dependencies_prechecked',
            'theme_locations'=>array_map('count',$selected),
            'browser_asset_and_dom_verification_required'=>true,
        ];
    }
    private static function safe_static_main(string $body): bool {
        if (strlen($body)>200000 || strlen($body)<50
            || preg_match('~<(?:form|input|textarea|select|button|script|iframe|video|audio|canvas)\b~i',$body)
            || preg_match('~(?:woocommerce|jet-smart-filters|jet-listing|jet-form-builder|nonce|cart_hash|wp_rest)~i',$body)) return false;
        preg_match_all('~\bdata-widget_type\s*=\s*(["\x27])([^"\x27]+)\1~i',$body,$matches);
        $static=['heading.default','text-editor.default','image.default','divider.default',
            'spacer.default','icon.default','image-box.default','icon-box.default'];
        foreach ($matches[2] as $widget) if (!in_array($widget,$static,true)) return false;
        return true;
    }
    private static function output_fragment(string $json,bool $cacheable,string $status): void {
        if (!headers_sent()) {
            header('X-MAD4B-Shell-Local-Cache: '.$status,true);
            if ($cacheable) {
                // Public fragment data may vary by device/locale and must never
                // enter intermediary or browser caches. Reuse only the tenant-
                // scoped WordPress/Redis object cache validated by cache_key().
                header('X-MAD4B-Shell-Cacheable: local-static-v1',true);
            }
            header('Cache-Control: private, no-store, max-age=0',true);
            header('Vary: User-Agent, Accept-Language',false);
        }
        echo $json;
    }

    public static function litespeed_js_exclusions($excludes) {
        if (!is_array($excludes)) $excludes=[];
        $path=(string)wp_parse_url(plugins_url('assets/site-shell.js',__FILE__),PHP_URL_PATH);
        if ($path!=='') $excludes[]=$path;
        return array_values(array_unique($excludes));
    }
    public static function script_tag(string $tag,string $handle): string {
        if ($handle!=='mad4b-persistent-site-shell') return $tag;
        // This controller must run after its own inline configuration, not inside
        // a general-purpose LiteSpeed bundle with independently reordered scripts.
        return preg_replace('/<script\b/i',
            '<script data-no-optimize="1" data-no-defer="1" data-cfasync="false"', $tag, 1);
    }
    public static function enqueue(): void {
        if (!self::eligible()) return;
        $file = __DIR__ . '/assets/site-shell.js';
        if (!is_readable($file)) return;
        wp_enqueue_script('mad4b-persistent-site-shell',
            plugins_url('assets/site-shell.js', __FILE__), [], (string)filemtime($file), true);
        $cfg = self::identity();
        wp_add_inline_script('mad4b-persistent-site-shell',
            'window.MAD4BSiteShell=' . wp_json_encode([
                'origin' => $cfg['origin'],
                'language' => $cfg['language'],
                'epoch' => self::cache_epoch()
            ]) . ';', 'before');
    }
    public static function cli($args): void {
        $i=self::identity();
        WP_CLI::line(wp_json_encode([
            'contract'=>'mad4b.persistent-site-shell.v1', 'enabled'=>!empty(self::settings()['enabled']),
            'origin'=>$i['origin']??null, 'language'=>$i['language']??null,
            'environment'=>$i['environment']??null, 'uuid_present'=>!empty($i['uuid'])
        ], JSON_PRETTY_PRINT));
    }

    private static function attr(string $tag,string $key): string {
        $pattern='~(?:^|\s)'.preg_quote($key,'~').'\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27)~i';
        if (!preg_match($pattern,$tag,$m)) return '';
        return html_entity_decode((string)($m[1]??'')!==''
            ? (string)$m[1] : (string)($m[2]??''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    private static function page_proof(string $html): array {
        preg_match_all('~<link\b[^>]*>~i',$html,$links);
        $css=[];
        foreach ($links[0]??[] as $tag) {
            $rel=strtolower(self::attr($tag,'rel'));
            $as=strtolower(self::attr($tag,'as'));
            if (!preg_match('/(?:^|\s)stylesheet(?:\s|$)/',$rel)
                && !($as==='style' && strpos($rel,'preload')!==false)) continue;
            $css[]=['href'=>self::attr($tag,'href'), 'rel'=>$rel,
                'id'=>self::attr($tag,'id'), 'media'=>self::attr($tag,'media')];
        }
        preg_match_all('~<style\b([^>]*)>([\s\S]*?)</style\s*>~i',$html,$styles,PREG_SET_ORDER);
        $inlineCss=[];
        foreach ($styles as $match) $inlineCss[]=[
            'id'=>self::attr($match[1],'id'),
            'sha256'=>hash('sha256',str_replace("\r\n","\n",$match[2]))
        ];
        preg_match_all('~<script\b([^>]*)>([\s\S]*?)</script\s*>~i',$html,$scripts,PREG_SET_ORDER);
        $external=[];
        $inline=[];
        // Only standard wp_localize_script JSON objects are eligible for a
        // page-context refresh. Never execute server-provided JavaScript.
        $localized=[
            'elementor-frontend-js-before'=>'elementorFrontendConfig',
            'jet-engine-frontend-js-extra'=>'JetEngineSettings',
            'jet-woo-builder-js-extra'=>'jetWooBuilderData',
            'jet-blog-js-extra'=>'JetBlogSettings',
        ];
        foreach ($scripts as $match) {
            $type=strtolower(self::attr($match[1],'type'));
            if ($type==='application/ld+json') continue;
            $src=self::attr($match[1],'src');
            if ($src!=='') { $external[]=['src'=>$src,'type'=>$type]; continue; }
            $id=self::attr($match[1],'id');
            $source=str_replace("\r\n","\n",$match[2]);
            $entry=['id'=>$id,'type'=>$type,'sha256'=>hash('sha256',$source)];
            if (isset($localized[$id]) && strlen($source)<100000) {
                $name=$localized[$id];
                $pattern='~^\s*var\s+'.preg_quote($name,'~').'\s*=\s*(\{[\s\S]*\})\s*;\s*(?://# sourceURL=[^\r\n]*)?\s*$~';
                if (preg_match($pattern,$source,$parsed)) {
                    $value=json_decode($parsed[1]);
                    if (json_last_error()===JSON_ERROR_NONE && is_object($value)) {
                        $entry['configName']=$name;
                        $entry['configValue']=$value;
                    }
                }
            }
            $inline[]=$entry;
        }
        return ['css'=>$css,'inlineScripts'=>$inline,'styles'=>$inlineCss,'scripts'=>$external];
    }
    private static function seo_head(string $head): string {
        $tags=[];
        preg_match_all('~<title\b[^>]*>[\s\S]*?</title\s*>~i',$head,$title);
        if (!empty($title[0])) $tags[]=$title[0][0];
        preg_match_all('~<link\b[^>]*>|<meta\b[^>]*>~i',$head,$all);
        foreach ($all[0]??[] as $tag) {
            $rel=strtolower(self::attr($tag,'rel'));
            $name=strtolower(self::attr($tag,'name'));
            $property=strtolower(self::attr($tag,'property'));
            if (in_array($rel,['canonical','alternate'],true)
                || $name==='description' || str_starts_with($name,'twitter:')
                || str_starts_with($property,'og:')) $tags[]=$tag;
        }
        preg_match_all('~<script\b[^>]*type\s*=\s*(?:"application/ld\+json"|\x27application/ld\+json\x27)[^>]*>[\s\S]*?</script\s*>~i',$head,$jsonLd);
        foreach ($jsonLd[0]??[] as $tag) $tags[]=$tag;
        return implode("\n",$tags);
    }

    // Same-origin fragment mode. WordPress still renders the original template,
    // but only the main region and compatibility metadata cross the network.
    public static function select_template($template) {
        if (($_GET['mad4b_shell_fragment']??'') !== '1' || !self::eligible()
            || !is_string($template) || !is_file($template)) return $template;
        $wrapper=__DIR__.'/templates/fragment-template.php';
        if (!is_readable($wrapper) || !self::safe_request()
            || !hash_equals(self::cache_epoch(),(string)($_GET['mad4b_shell_v']??''))) return $template;
        self::$page_template=$template;
        // Never let a fragment response pollute the ordinary HTML cache key.
        do_action('litespeed_control_set_nocache', 'mad4b_shell_fragment');
        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: application/vnd.mad4b.shell+json; charset=UTF-8',true);
            header('X-Content-Type-Options: nosniff',true);
            header('Cache-Control: private, no-store, max-age=0',true);
        }
        return $wrapper;
    }
    public static function render_fragment_template(): void {
        $template=self::$page_template;
        if ($template==='' || !is_file($template)) { status_header(404); return; }
        $navigation=self::navigation_preflight();
        if (($navigation['mode']??'full')==='full') {
            status_header(422);
            self::output_fragment(wp_json_encode([
                'eligible'=>false,'reason'=>$navigation['reason'],
                'navigation'=>$navigation
            ]),false,'BYPASS');
            return;
        }
        $cacheKey=self::cache_key();
        $cached=self::cache_read($cacheKey);
        if ($cached!==null) {
            self::output_fragment($cached,true,'HIT');
            return;
        }
        ob_start();
        include $template;
        $html=(string)ob_get_clean();
        $found=preg_match_all('~<main\b[^>]*>[\s\S]*?</main\s*>~i',$html,$m,PREG_OFFSET_CAPTURE);
        if ($found!==1 || strlen($html)>5500000) {
            status_header(422);
            echo wp_json_encode(['eligible'=>false,'reason'=>'ambiguous_or_oversized_main']);
            return;
        }
        $body=(string)$m[0][0][0];
        $offset=(int)$m[0][0][1];
        $before=substr($html,0,$offset);
        $after=substr($html,$offset+strlen($body));
        // Header and footer tags are structural evidence only; their DOM stays mounted.
        preg_match_all('~<header\b[^>]*>~i',$before,$headers);
        preg_match_all('~<footer\b[^>]*>~i',$after,$footers);
        preg_match('~<head\b[^>]*>([\s\S]*?)</head\s*>~i',$html,$head);
        preg_match('~<html\b[^>]*>~i',$html,$htmlTag);
        preg_match('~<body\b[^>]*>~i',$html,$bodyTag);
        $headerTag='';
        foreach ($headers[0]??[] as $tag) {
            if (stripos($tag,'elementor-location-header')!==false) { $headerTag=$tag; break; }
        }
        if ($headerTag==='' && !empty($headers[0])) $headerTag=$headers[0][0];
        $footerTag='';
        foreach ($footers[0]??[] as $tag) {
            if (stripos($tag,'elementor-location-footer')!==false) { $footerTag=$tag; break; }
        }
        if ($footerTag==='' && !empty($footers[0])) $footerTag=$footers[0][0];
        if (empty($head[1]) || $headerTag==='' || $footerTag===''
            || empty($htmlTag[0]) || empty($bodyTag[0])) {
            status_header(422);
            echo wp_json_encode(['eligible'=>false,'reason'=>'unrecognized_template']);
            return;
        }
        $payload=[
            'navigation'=>$navigation,
            'eligible'=>true,'main'=>$body,
            'head'=>self::seo_head($head[1]),'htmlTag'=>$htmlTag[0],
            'bodyTag'=>$bodyTag[0],'headerTag'=>$headerTag,'footerTag'=>$footerTag,
            'proof'=>self::page_proof($html)
        ];
        $json=wp_json_encode($payload,JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {status_header(422);return;}
        // Only pages with static, anonymous main content enter the shared cache.
        $cacheable=self::safe_request() && self::safe_static_main($body)
            && !headers_sent() && !array_filter(headers_list(),static function($header) {
                return stripos($header,'Set-Cookie:')===0;
            });
        if ($cacheable) self::cache_write($cacheKey,$json);
        self::output_fragment($json,$cacheable,$cacheable?'MISS':'BYPASS');
    }

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'admin']);
        add_action('admin_init', static function () {
            register_setting('mad4b-persistent-site-shell', self::OPT, [
                'type'=>'array','default'=>['enabled'=>0],
                'sanitize_callback'=>[__CLASS__, 'sanitize']
            ]);
        });
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 99);
        add_filter('script_loader_tag',[__CLASS__,'script_tag'],99,2);
        add_filter('litespeed_optimize_js_excludes',[__CLASS__,'litespeed_js_exclusions'],9999);
        add_filter('template_include', [__CLASS__, 'select_template'], PHP_INT_MAX);
        if (defined('WP_CLI') && WP_CLI) WP_CLI::add_command('mad4b shell status', [__CLASS__, 'cli']);
    }
}
MAD4B_Persistent_Site_Shell::boot();
