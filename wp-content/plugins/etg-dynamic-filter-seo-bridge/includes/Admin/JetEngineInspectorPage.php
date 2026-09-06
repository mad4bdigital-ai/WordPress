<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

use ETG\DynamicFilterSEOBridge\JetEngine\ListingIntegration;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSourceResolver;

final class JetEngineInspectorPage {
    const SLUG = 'etg-dfsb-jetengine-inspector';
    private $sources;
    private $previewContextProvider;

    public function __construct(ContentSourceResolver $sources, callable $previewContextProvider = null) {
        $this->sources = $sources;
        $this->previewContextProvider = $previewContextProvider;
    }

    public function register(): void { add_action('admin_menu', array($this, 'menu')); }
    public function menu(): void { add_options_page('ETG JetEngine Inspector', 'ETG JetEngine Inspector', 'manage_options', self::SLUG, array($this, 'render')); }

    public function render(): void {
        if (!current_user_can('manage_options')) { return; }
        $catalog = $this->sources->catalog();
        $fields = (array) ($catalog['field_discovery']['fields'] ?? array());
        $queries = (array) ($catalog['queries'] ?? array());
        $relations = (array) ($catalog['relations'] ?? array());
        $ccts = (array) ($catalog['field_discovery']['cct'] ?? array());
        $listing = (array) ($catalog['listing_context'] ?? array());
        $tabs = array('diagnostic' => 'Diagnostic Lab', 'queries' => 'Query Builder', 'relations' => 'Relations', 'fields' => 'Fields & CCT', 'recipes' => 'Context & Recipes');
        $tab = AdminUi::activeTab($tabs, 'diagnostic');

        $preview = isset($_GET['preview_url']) ? trim((string) wp_unslash($_GET['preview_url'])) : '';
        $context = array();
        $previewState = 'not_requested';
        if ('' !== $preview && $this->previewContextProvider) {
            try {
                $resolved = call_user_func($this->previewContextProvider, $preview);
                if (is_array($resolved) && $resolved) { $context = $resolved; $previewState = 'ready'; }
                else { $previewState = 'blocked'; }
            } catch (\Throwable $e) { $previewState = 'blocked'; }
        }
        $diagnostic = array();
        if (isset($_GET['inspect'])) { $tab = 'diagnostic'; $diagnostic = $this->sources->diagnose($this->sourceFromRequest(), $context); }
        ?>
        <div class="wrap etg-dfsb-admin etg-dfsb-jetengine-inspector">
            <?php AdminUi::renderHeader(self::SLUG, 'ETG JetEngine Inspector', 'Read-only discovery and source diagnostics for Listing Items, Components, Query Builder, Repeaters, Relations and CCT.', array(
                array('label' => 'Content Combination Builder', 'url' => AdminUi::pageUrl(DynamicContentPage::SLUG), 'primary' => true),
                array('label' => 'Usage Guide', 'url' => AdminUi::pageUrl(UsageGuidePage::SLUG)),
            )); ?>
            <div class="etg-status-grid">
                <div class="etg-status-card"><span class="etg-status-label">Authority</span><span class="etg-status-value"><span class="etg-badge etg-badge--readonly">READ ONLY</span></span><span class="etg-status-help">No writes or SEO mutation.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Queries</span><span class="etg-status-value"><?php echo esc_html((string) count($queries)); ?></span><span class="etg-status-help">JetEngine Query Builder.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Relations</span><span class="etg-status-value"><?php echo esc_html((string) count($relations)); ?></span><span class="etg-status-help">Parents / children / edge meta.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Fields</span><span class="etg-status-value"><?php echo esc_html((string) count($fields)); ?></span><span class="etg-status-help">Meta Box, Listing object and CCT.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">CCT</span><span class="etg-status-value"><?php echo esc_html((string) count($ccts)); ?></span><span class="etg-status-help">Custom Content Types.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Listing Context</span><span class="etg-status-value"><?php echo !empty($listing['available']) ? '<span class="etg-badge etg-badge--safe">AVAILABLE</span>' : '<span class="etg-badge etg-badge--warn">NOT ACTIVE</span>'; ?></span><span class="etg-status-help"><?php echo esc_html((string) ($listing['type'] ?? 'none')); ?></span></div>
            </div>
            <?php AdminUi::renderTabs(self::SLUG, $tabs, $tab); ?>
            <?php
            switch ($tab) {
                case 'queries': $this->renderQueries($queries); break;
                case 'relations': $this->renderRelations($relations); break;
                case 'fields': $this->renderFields($fields, $ccts); break;
                case 'recipes': $this->renderRecipes($listing); break;
                default: $this->renderDiagnostic($catalog, $queries, $relations, $preview, $previewState, $diagnostic); break;
            }
            ?>
        </div>
        <?php
    }

    private function renderDiagnostic(array $catalog, array $queries, array $relations, string $preview, string $previewState, array $diagnostic): void {
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Source Diagnostic Lab</h2><p class="description">Test one source structurally, optionally against the same-site filtered URL. Preview evidence remains synthetic and non-authorizing.</p></div></div><div class="etg-panel__body">
            <form method="get"><input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>"><input type="hidden" name="tab" value="diagnostic"><input type="hidden" name="inspect" value="1">
            <table class="form-table" role="presentation">
                <tr><th>Preview Filter URL</th><td><input name="preview_url" class="large-text code" value="<?php echo esc_attr($preview); ?>" placeholder="/tours-and-activities/jsf/jet-engine:.../"><?php if ('not_requested' !== $previewState): ?> <span class="etg-badge <?php echo 'ready' === $previewState ? 'etg-badge--safe' : 'etg-badge--warn'; ?>"><?php echo esc_html(strtoupper($previewState)); ?></span><?php endif; ?></td></tr>
                <tr><th>Source Type</th><td><select name="source_type"><?php foreach ((array) ($catalog['source_types'] ?? array()) as $key => $label): ?><option value="<?php echo esc_attr((string) $key); ?>" <?php selected($this->get('source_type', 'listing_field'), $key); ?>><?php echo esc_html((string) $key . ' — ' . $label); ?></option><?php endforeach; ?></select></td></tr>
                <tr><th>Alias / Role</th><td><input name="alias" value="<?php echo esc_attr($this->get('alias', 'inspect')); ?>" placeholder="hero_image"> <input name="role" value="<?php echo esc_attr($this->get('role')); ?>" placeholder="location"></td></tr>
                <tr><th>Field / Meta Key</th><td><input name="field" class="regular-text code" value="<?php echo esc_attr($this->get('field')); ?>" placeholder="title / HeroImage / meta:price"> <input name="meta_key" class="regular-text code" value="<?php echo esc_attr($this->get('meta_key')); ?>" placeholder="HeroImage / repeater / relation meta"></td></tr>
                <tr><th>Query / Relation</th><td><select name="query_id"><option value="0">— Query —</option><?php foreach ($queries as $id => $label): ?><option value="<?php echo esc_attr((string) $id); ?>" <?php selected((int) $this->get('query_id', '0'), (int) $id); ?>><?php echo esc_html($id . ' · ' . $label); ?></option><?php endforeach; ?></select> <select name="relation_id"><option value="0">— Relation —</option><?php foreach ($relations as $id => $meta): ?><option value="<?php echo esc_attr((string) $id); ?>" <?php selected((int) $this->get('relation_id', '0'), (int) $id); ?>><?php echo esc_html($id . ' · ' . (string) ($meta['label'] ?? '')); ?></option><?php endforeach; ?></select> <select name="direction"><option value="children" <?php selected($this->get('direction', 'children'), 'children'); ?>>Children</option><option value="parents" <?php selected($this->get('direction', 'children'), 'parents'); ?>>Parents</option></select></td></tr>
                <tr><th>Aggregate / Limit</th><td><select name="aggregate"><?php foreach ((array) ($catalog['aggregates'] ?? array()) as $aggregate): ?><option value="<?php echo esc_attr((string) $aggregate); ?>" <?php selected($this->get('aggregate', 'first'), $aggregate); ?>><?php echo esc_html((string) $aggregate); ?></option><?php endforeach; ?></select> <input type="number" min="1" max="100" name="limit" value="<?php echo esc_attr($this->get('limit', '20')); ?>"></td></tr>
            </table><?php submit_button('Inspect Source', 'secondary'); ?></form>
            <?php if ($diagnostic): ?><div class="etg-mini-card"><h3>Diagnostic Result</h3><table class="widefat striped"><tbody><?php foreach (array('available', 'reason', 'row_count', 'sample') as $key): ?><tr><th><?php echo esc_html($key); ?></th><td><code><?php echo esc_html(is_bool($diagnostic[$key] ?? null) ? (($diagnostic[$key] ?? false) ? 'true' : 'false') : (string) ($diagnostic[$key] ?? '')); ?></code></td></tr><?php endforeach; ?><tr><th>media_ids</th><td><code><?php echo esc_html(implode(',', (array) ($diagnostic['media_ids'] ?? array()))); ?></code></td></tr></tbody></table></div><?php endif; ?>
        </div></section>
        <?php
    }

    private function renderQueries(array $queries): void {
        AdminUi::renderTableSearch('etg-query-search', 'etg-query-table', 'Search query ID or label…', count($queries) . ' queries');
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Query Builder</h2><p class="description">Use these exact IDs as Content Sources, or keep Query Builder native and inject ETG filter macros into query arguments.</p></div></div><div class="etg-panel__body"><table class="widefat striped" id="etg-query-table"><thead><tr><th>ID</th><th>Query</th></tr></thead><tbody><?php if (!$queries): ?><tr><td colspan="2">No Query Builder definitions detected.</td></tr><?php else: foreach ($queries as $id => $label): ?><tr data-etg-search="<?php echo esc_attr(strtolower((string) $id . ' ' . (string) $label)); ?>"><td><code><?php echo esc_html((string) $id); ?></code></td><td><?php echo esc_html((string) $label); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
        <?php
    }

    private function renderRelations(array $relations): void {
        AdminUi::renderTableSearch('etg-relation-search', 'etg-relation-table', 'Search relation, parent, child or meta…', count($relations) . ' relations');
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Relations</h2><p class="description">Relation Meta is distinct from related-object Meta and can be selected as <code>relation_meta</code>.</p></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped" id="etg-relation-table"><thead><tr><th>ID</th><th>Relation</th><th>Parent</th><th>Child</th><th>Edge Meta</th></tr></thead><tbody><?php if (!$relations): ?><tr><td colspan="5">No active relations detected.</td></tr><?php else: foreach ($relations as $id => $meta): $edge = implode(' ', array_keys((array) ($meta['meta_fields'] ?? array()))); ?><tr data-etg-search="<?php echo esc_attr(strtolower((string) $id . ' ' . (string) ($meta['label'] ?? '') . ' ' . (string) ($meta['parent_object'] ?? '') . ' ' . (string) ($meta['child_object'] ?? '') . ' ' . $edge)); ?>"><td><code><?php echo esc_html((string) $id); ?></code></td><td><?php echo esc_html((string) ($meta['label'] ?? '')); ?></td><td><code><?php echo esc_html((string) ($meta['parent_object'] ?? '')); ?></code></td><td><code><?php echo esc_html((string) ($meta['child_object'] ?? '')); ?></code></td><td><?php foreach ((array) ($meta['meta_fields'] ?? array()) as $key => $label): ?><code><?php echo esc_html((string) $key); ?></code> <?php echo esc_html((string) $label); ?><br><?php endforeach; ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div></section>
        <?php
    }

    private function renderFields(array $fields, array $ccts): void {
        AdminUi::renderTableSearch('etg-inspector-field-search', 'etg-inspector-field-table', 'Search field, kind, source or CCT…', count($fields) . ' fields');
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Field Discovery</h2><p class="description">Case-preserving, bounded field inventory. Sensitive-looking keys are intentionally omitted.</p></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped" id="etg-inspector-field-table"><thead><tr><th>Source</th><th>Key</th><th>Kind</th><th>Path / CCT</th></tr></thead><tbody><?php if (!$fields): ?><tr><td colspan="4">No fields detected in the current admin context.</td></tr><?php else: foreach (array_slice($fields, 0, 300, true) as $field): $search = strtolower((string) ($field['source'] ?? '') . ' ' . (string) ($field['key'] ?? '') . ' ' . (string) ($field['kind'] ?? '') . ' ' . (string) ($field['path'] ?? '') . ' ' . (string) ($field['cct'] ?? '')); ?><tr data-etg-search="<?php echo esc_attr($search); ?>"><td><?php echo esc_html((string) ($field['source'] ?? '')); ?></td><td><code><?php echo esc_html((string) ($field['key'] ?? '')); ?></code></td><td><?php echo esc_html((string) ($field['kind'] ?? '')); ?></td><td><code><?php echo esc_html((string) ($field['path'] ?? '')); ?></code><?php if (!empty($field['cct'])): ?> · <?php echo esc_html((string) $field['cct']); ?><?php endif; ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div></section>
        <?php if ($ccts): ?><section class="etg-panel" data-etg-collapsible="1"><div class="etg-panel__head"><div><h2>CCT Definitions</h2><p class="description">Detected Custom Content Types and their field contracts.</p></div></div><div class="etg-panel__body"><pre class="etg-json etg-json-scroll"><?php echo esc_html(wp_json_encode($ccts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre></div></section><?php endif; ?>
        <?php
    }

    private function renderRecipes(array $listing): void {
        ?>
        <div class="etg-safety-strip"><span class="dashicons dashicons-database-view"></span><p><strong>Current listing context:</strong> <?php echo !empty($listing['available']) ? 'available' : 'not active in this admin request'; ?>. ETG follows JetEngine current-object context rather than replacing its renderer.</p></div>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>JetEngine Context & Recipes</h2></div></div><div class="etg-panel__body"><table class="widefat striped"><tbody>
            <tr><th>Query Builder — selected term</th><td><code>%etg_filter_term_id|location%</code></td></tr>
            <tr><th>Query Builder — multi-select IDs</th><td><code>%etg_filter_term_ids|location|,%</code></td></tr>
            <tr><th>Query Builder — multi-select slugs</th><td><code>%etg_filter_term_slugs|location|,%</code></td></tr>
            <tr><th>Query Builder — term meta</th><td><code>%etg_filter_term_meta|location|HeroImage%</code></td></tr>
            <tr><th>Query Builder — multi-term meta</th><td><code>%etg_filter_term_meta_values|location|RegionCode|,%</code></td></tr>
            <tr><th>Listing / Component context</th><td>Select <code>ETG Filter Context (presentation only)</code>.</td></tr>
            <tr><th>Dynamic Repeater rows</th><td><code><?php echo esc_html(ListingIntegration::repeaterMarker('slot_id', 'source_alias')); ?></code></td></tr>
            <tr><th>Slot rows JSON macro</th><td><code>%etg_slot_rows|slot_id|source_alias%</code></td></tr>
            <tr><th>Slider / Carousel</th><td>Use a Query Builder source in native Listing Grid/Carousel, or output a Slot as <code>gallery</code> and bind <strong>ETG Content Slot Gallery</strong>.</td></tr>
        </tbody></table></div></section>
        <?php
    }

    private function sourceFromRequest(): array {
        return array(
            'alias' => $this->get('alias', 'inspect'), 'type' => $this->get('source_type', 'listing_field'), 'role' => $this->get('role'), 'field' => $this->get('field'), 'meta_key' => $this->get('meta_key'), 'query_id' => (int) $this->get('query_id', '0'), 'relation_id' => (int) $this->get('relation_id', '0'), 'direction' => $this->get('direction', 'children'), 'aggregate' => $this->get('aggregate', 'first'), 'limit' => (int) $this->get('limit', '20'),
        );
    }

    private function get(string $key, string $default = ''): string {
        return isset($_GET[$key]) && is_scalar($_GET[$key]) ? trim((string) wp_unslash($_GET[$key])) : $default;
    }
}
