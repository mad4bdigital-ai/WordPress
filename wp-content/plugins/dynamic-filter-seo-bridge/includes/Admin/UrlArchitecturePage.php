<?php
namespace DynamicFilterSEOBridge\Admin;

use DynamicFilterSEOBridge\IndexFlow\ManagedUrlArchitecture;
use DynamicFilterSEOBridge\IndexFlow\WordPressRouteResolver;
use DynamicFilterSEOBridge\IndexFlow\RouteDocumentStore;

/** Draft planning UI. POST evaluates in memory; it is deliberately not a save. */
final class UrlArchitecturePage {
    public function register(): void { add_action('admin_menu', array($this, 'menu')); }
    public function menu(): void {
        AdminUi::registerPage('IndexFlow URL Architecture', 'dfsb-url-architecture', array($this, 'render'));
    }

    public function render(): void {
        if (!current_user_can('manage_options')) return;
        $draft = ''; $preview = null; $migration = null; $error = '';
        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
            check_admin_referer('indexflow_url_preview');
            try {
                $draft = $this->input('route_document');
                $service = $this->service($this->decode($draft));
                $preview = $service->preview();
                $preview['governed_storage_plan'] = (new RouteDocumentStore())->plan($this->decode($draft));
                $preview['wordpress_rewrite_plan'] = (new WordPressRouteResolver($service, (string)parse_url((string)get_option('home'), PHP_URL_PATH)))->rewritePlan();
                $old = $this->input('previous_document');
                if ('' !== trim($old)) $migration = $this->service($this->decode($old))->planMigration($service);
            } catch (\InvalidArgumentException $e) { $error = $e->getMessage(); }
        }
        echo '<div class="wrap">';
        AdminUi::renderHeader('dfsb-url-architecture', 'IndexFlow — URL Architecture', 'Preview a route document using real WordPress terms, translated pages and collision checks.');
        echo '<div class="notice notice-info inline"><p>Native first. Published routes only. Preview does not save, register rewrites, publish SEO or authorize execution.</p></div>';
        echo '<p>Global scope: document version and the current site origin. Profile scope: Native / Aliased / Published SEO strategy, locale archive bases, provider/query binding and ordered dimensions. Route scope: native Page/CPT or virtual template, Draft / Approved / Published / Retired and SEO owner.</p>';
        if ($error) echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html($error) . '</p></div>';
        echo '<form method="post">'; wp_nonce_field('indexflow_url_preview');
        echo '<p><label for="indexflow-route-document"><strong>Proposed route document (JSON, maximum 32 KB)</strong></label></p>';
        echo '<textarea class="large-text code" id="indexflow-route-document" name="route_document" rows="18" required maxlength="32768" spellcheck="false">' . esc_textarea($draft ?: '{"version":1,"profiles":{},"routes":[]}') . '</textarea>';
        echo '<p><label for="indexflow-previous-document">Previous document for migration comparison (optional)</label></p>';
        echo '<textarea class="large-text code" id="indexflow-previous-document" name="previous_document" rows="6" maxlength="32768" spellcheck="false"></textarea>';
        echo '<p><button class="button button-primary" type="submit">Validate and preview</button></p></form>';
        if (is_array($preview)) {
            echo '<h2>Route, canonical, hreflang and rewrite candidates</h2><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html(wp_json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
        }
        if (is_array($migration)) echo '<h2>Migration plan — no redirects applied</h2><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html(wp_json_encode($migration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '<h2>Governed storage</h2><p>Use the authorized MAD4B routes-plan → routes-apply → routes-verify operations and central mutation-undo. The plan binds the stored revision, proposed document, live dependencies and migration. Storage does not activate routes or SEO. This screen exposes no direct write endpoint.</p></div>';
    }

    private function input(string $key): string {
        $raw = $_POST[$key] ?? '';
        if (!is_string($raw) || strlen($raw) > 65536) throw new \InvalidArgumentException('route_document_too_large');
        $value = wp_unslash($raw);
        if (strlen($value) > 32768) throw new \InvalidArgumentException('route_document_too_large');
        return $value;
    }

    private function decode(string $json): array {
        $value = json_decode($json, true, 16);
        if (!is_array($value) || JSON_ERROR_NONE !== json_last_error()) throw new \InvalidArgumentException('invalid_route_json');
        return $value;
    }

    private function service(array $document): ManagedUrlArchitecture {
        return RouteDocumentStore::architecture($document);
    }
}
