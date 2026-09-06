<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
use ETG\DynamicFilterSEOBridge\Identifiers\PresentationToken;
use ETG\DynamicFilterSEOBridge\JetEngine\ListingIntegration;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSourceResolver;
use ETG\DynamicFilterSEOBridge\Presentation\InventoryContentCatalog;

final class DynamicContentPage {
    const SLUG = 'etg-dfsb-dynamic-content';

    private $inventory;
    private $catalog;
    private $slots;
    private $profiles;
    private $sources;

    public function __construct(RuntimeInventory $inventory, InventoryContentCatalog $catalog, ContentSlotRegistry $slots, ProfileRegistry $profiles, ContentSourceResolver $sources = null) {
        $this->inventory = $inventory;
        $this->catalog = $catalog;
        $this->slots = $slots;
        $this->profiles = $profiles;
        $this->sources = $sources;
    }

    public function register(): void {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_etg_dfsb_save_dynamic_slot', array($this, 'save'));
        add_action('admin_post_etg_dfsb_delete_dynamic_slot', array($this, 'delete'));
    }

    public function menu(): void {
        add_options_page('ETG Dynamic Content', 'ETG Dynamic Content', 'manage_options', self::SLUG, array($this, 'render'));
    }

    public function save(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', 403); }
        check_admin_referer('etg_dfsb_save_dynamic_slot');
        $snapshot = $this->inventory->collect();
        $sources = $this->postedSources();
        if ($this->sources) { $sources = $this->sources->normalizeSources($sources); }
        $slot = array(
            'id' => $this->post('slot_id'),
            'label' => $this->post('label'),
            'enabled' => isset($_POST['enabled']) ? '1' : '0',
            'type' => $this->post('type', 'text'),
            'template' => $this->post('template'),
            'fallback' => $this->post('fallback'),
            'prefix' => $this->post('prefix'),
            'suffix' => $this->post('suffix'),
            'max_length' => $this->post('max_length', '0'),
            'sources' => array_values($sources),
            'fallback_chain' => $this->post('fallback_chain'),
            'source_inventory_fingerprint' => (string) ($snapshot['snapshot_fingerprint'] ?? ''),
        );
        $result = $this->slots->save($slot);
        $url = AdminUi::pageUrl(self::SLUG, array(
            'tab' => 'editor',
            'saved' => !empty($result['saved']) ? '1' : '0',
            'reason' => (string) ($result['reason'] ?? ''),
            'slot' => (string) ($result['slot']['id'] ?? ''),
        ));
        wp_safe_redirect($url);
        exit;
    }

    public function delete(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', 403); }
        check_admin_referer('etg_dfsb_delete_dynamic_slot');
        $id = isset($_POST['slot_id']) ? sanitize_key(wp_unslash((string) $_POST['slot_id'])) : '';
        $wasDefault = $this->slots->isDefault($id);
        $deleted = $this->slots->delete($id);
        wp_safe_redirect(AdminUi::pageUrl(self::SLUG, array('tab' => 'slots', 'deleted' => $deleted ? '1' : '0', 'restored' => $deleted && $wasDefault ? '1' : '0')));
        exit;
    }

    public function render(): void {
        if (!current_user_can('manage_options')) { return; }

        $snapshot = $this->inventory->collect();
        $catalog = $this->catalog->build($snapshot, $this->profiles->all());
        $sourceCatalog = $this->sources ? $this->sources->catalog() : array();
        $slots = $this->slots->all();
        $tabs = $this->tabs();
        $tab = AdminUi::activeTab($tabs, 'overview');
        if (isset($_GET['slot']) || isset($_GET['token'])) { $tab = 'editor'; }

        $edit = isset($_GET['slot']) ? $this->slots->get(sanitize_key((string) wp_unslash($_GET['slot']))) : array();
        $seed = isset($_GET['token']) ? PresentationToken::normalize(wp_unslash((string) $_GET['token'])) : '';
        if (!$edit && $seed && isset($catalog['tokens'][$seed])) {
            $edit = array(
                'id' => sanitize_key(str_replace(':', '_', $seed)),
                'label' => (string) $catalog['tokens'][$seed]['label'],
                'enabled' => true,
                'type' => (string) $catalog['tokens'][$seed]['type'],
                'template' => '{{' . $seed . '}}',
                'fallback' => '',
                'prefix' => '',
                'suffix' => '',
                'max_length' => 0,
                'sources' => array(),
                'fallback_chain' => array(),
                'origin' => 'custom',
            );
        }
        $edit = array_merge(array(
            'id' => '', 'label' => '', 'enabled' => true, 'type' => 'text', 'template' => '', 'fallback' => '', 'prefix' => '', 'suffix' => '', 'max_length' => 0, 'sources' => array(), 'fallback_chain' => array(), 'origin' => 'custom',
        ), $edit);

        $queryOptions = (array) ($sourceCatalog['queries'] ?? array());
        $relationOptions = (array) ($sourceCatalog['relations'] ?? array());
        $sourceTypes = (array) ($sourceCatalog['source_types'] ?? array());
        $aggregates = (array) ($sourceCatalog['aggregates'] ?? array());
        $roles = $this->roles($catalog);
        $metaTokens = $this->metaTokens($catalog);
        $metrics = $this->metrics($slots);

        ?>
        <div class="wrap etg-dfsb-admin etg-dfsb-dynamic-content">
            <?php AdminUi::renderHeader(self::SLUG, 'ETG Dynamic Content', 'Build reusable presentation combinations for Elementor, JetEngine Listings, Query Builder, Repeaters, Relations, CCT and live JetSmartFilters states.', array(
                array('label' => 'Create Slot', 'url' => AdminUi::pageUrl(self::SLUG, array('tab' => 'editor')), 'primary' => true),
                array('label' => 'JetEngine Inspector', 'url' => AdminUi::pageUrl(JetEngineInspectorPage::SLUG)),
            )); ?>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-<?php echo '1' === (string) $_GET['saved'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html('1' === (string) $_GET['saved'] ? 'Dynamic content slot saved.' : 'Slot was not saved: ' . sanitize_text_field((string) ($_GET['reason'] ?? 'unknown'))); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?><div class="notice notice-success is-dismissible"><p><?php echo !empty($_GET['restored']) ? 'Built-in slot override reset.' : 'Custom slot deleted.'; ?></p></div><?php endif; ?>

            <?php $this->renderStatus($metrics, $queryOptions, $relationOptions, $sourceCatalog); ?>
            <?php AdminUi::renderTabs(self::SLUG, $tabs, $tab); ?>

            <?php
            switch ($tab) {
                case 'slots':
                    $this->renderSlots($slots);
                    break;
                case 'editor':
                    $this->renderEditor($edit, $sourceTypes, $roles, $queryOptions, $relationOptions, $aggregates, $metaTokens);
                    break;
                case 'helpers':
                    $this->renderHelpers($edit);
                    break;
                case 'fields':
                    $this->renderFields($metaTokens, $sourceCatalog);
                    break;
                case 'tokens':
                    $this->renderTokens($catalog);
                    break;
                default:
                    $this->renderOverview($metrics);
                    break;
            }
            ?>
        </div>
        <?php
    }

    private function tabs(): array {
        return array(
            'overview' => 'Overview',
            'slots' => 'Slots',
            'editor' => 'Combination Editor',
            'helpers' => 'Helpers & Recipes',
            'fields' => 'Field Catalog',
            'tokens' => 'Runtime Tokens',
        );
    }

    private function renderStatus(array $metrics, array $queries, array $relations, array $sourceCatalog): void {
        ?>
        <div class="etg-status-grid">
            <div class="etg-status-card"><span class="etg-status-label">Authority</span><span class="etg-status-value"><span class="etg-badge etg-badge--readonly">PRESENTATION ONLY</span></span><span class="etg-status-help">Global/Profile/SEO remain separate.</span></div>
            <div class="etg-status-card"><span class="etg-status-label">Slots</span><span class="etg-status-value"><?php echo esc_html((string) $metrics['total']); ?></span><span class="etg-status-help"><?php echo esc_html($metrics['built_in'] . ' built-in · ' . $metrics['override'] . ' customized · ' . $metrics['custom'] . ' custom'); ?></span></div>
            <div class="etg-status-card"><span class="etg-status-label">Queries</span><span class="etg-status-value"><?php echo esc_html((string) count($queries)); ?></span><span class="etg-status-help">JetEngine Query Builder definitions.</span></div>
            <div class="etg-status-card"><span class="etg-status-label">Relations</span><span class="etg-status-value"><?php echo esc_html((string) count($relations)); ?></span><span class="etg-status-help">Parent/child and edge-meta sources.</span></div>
            <div class="etg-status-card"><span class="etg-status-label">Listing Context</span><span class="etg-status-value"><?php echo !empty($sourceCatalog['listing_context']['available']) ? '<span class="etg-badge etg-badge--safe">AVAILABLE</span>' : '<span class="etg-badge etg-badge--warn">PAGE CONTEXT</span>'; ?></span><span class="etg-status-help"><?php echo esc_html((string) ($sourceCatalog['listing_context']['type'] ?? 'none')); ?></span></div>
        </div>
        <?php
    }

    private function renderOverview(array $metrics): void {
        ?>
        <div class="etg-safety-strip"><span class="dashicons dashicons-shield-alt"></span><p><strong>Presentation boundary:</strong> combinations, sources and media helpers can render content but cannot enable profiles, authorize indexing, mutate Rank Math publication state or change browser history.</p></div>
        <div class="etg-overview-grid">
            <article class="etg-overview-card"><h3>Manage Slots</h3><p>Review built-in, customized and custom slots without loading the editor and field catalogs at the same time.</p><a class="button" href="<?php echo esc_url(AdminUi::pageUrl(self::SLUG, array('tab' => 'slots'))); ?>">Open Slots</a></article>
            <article class="etg-overview-card"><h3>Build a Combination</h3><p>Create text, HTML, URL, image, gallery or JSON outputs with ordered source fallbacks.</p><a class="button button-primary" href="<?php echo esc_url(AdminUi::pageUrl(self::SLUG, array('tab' => 'editor'))); ?>">Open Editor</a></article>
            <article class="etg-overview-card"><h3>Discover Fields</h3><p>Use bounded taxonomy and JetEngine discovery before typing case-sensitive field or Meta keys manually.</p><a class="button" href="<?php echo esc_url(AdminUi::pageUrl(self::SLUG, array('tab' => 'fields'))); ?>">Field Catalog</a></article>
            <article class="etg-overview-card"><h3>JetEngine Recipes</h3><p>Listing, Query Builder, Relation, Repeater, CCT, Dynamic Repeater and media binding helpers in one place.</p><a class="button" href="<?php echo esc_url(AdminUi::pageUrl(self::SLUG, array('tab' => 'helpers'))); ?>">Open Helpers</a></article>
        </div>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Workspace Summary</h2><p class="description">Current reusable content surface.</p></div></div><div class="etg-panel__body"><table class="widefat striped"><tbody>
            <tr><th>Total slots</th><td><?php echo esc_html((string) $metrics['total']); ?></td></tr>
            <tr><th>Enabled</th><td><?php echo esc_html((string) $metrics['enabled']); ?></td></tr>
            <tr><th>Built-in / Override / Custom</th><td><?php echo esc_html($metrics['built_in'] . ' / ' . $metrics['override'] . ' / ' . $metrics['custom']); ?></td></tr>
        </tbody></table></div></section>
        <?php
    }

    private function renderSlots(array $slots): void {
        AdminUi::renderTableSearch('etg-slot-search', 'etg-slot-table', 'Search slot, type, source or status…', count($slots) . ' slots');
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Content Slots</h2><p class="description">Text, HTML, URL, Image, Gallery and JSON outputs.</p></div><div class="etg-actions"><a class="button button-primary" href="<?php echo esc_url(AdminUi::pageUrl(self::SLUG, array('tab' => 'editor'))); ?>">Create Custom Slot</a></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped" id="etg-slot-table"><thead><tr><th>Slot</th><th>Type</th><th>Sources</th><th>Origin</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($slots as $slot): $origin = (string) ($slot['origin'] ?? 'custom'); ?>
            <tr data-etg-search="<?php echo esc_attr(strtolower((string) $slot['id'] . ' ' . (string) $slot['label'] . ' ' . (string) $slot['type'] . ' ' . $origin . ' ' . (!empty($slot['enabled']) ? 'enabled' : 'disabled'))); ?>">
                <td><strong><?php echo esc_html((string) $slot['label']); ?></strong><br><code><?php echo esc_html((string) $slot['id']); ?></code></td>
                <td><?php echo esc_html(strtoupper((string) $slot['type'])); ?></td>
                <td><?php echo esc_html((string) count((array) ($slot['sources'] ?? array()))); ?></td>
                <td><span class="etg-slot-origin etg-slot-origin--<?php echo esc_attr($origin); ?>"><?php echo esc_html(str_replace('_', ' ', $origin)); ?></span></td>
                <td><span class="etg-badge <?php echo !empty($slot['enabled']) ? 'etg-badge--safe' : 'etg-badge--warn'; ?>"><?php echo !empty($slot['enabled']) ? 'Enabled' : 'Disabled'; ?></span></td>
                <td><a class="button button-small" href="<?php echo esc_url(AdminUi::pageUrl(self::SLUG, array('tab' => 'editor', 'slot' => $slot['id']))); ?>">Edit</a><?php if ('built_in' !== $origin): ?> <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"><input type="hidden" name="action" value="etg_dfsb_delete_dynamic_slot"><input type="hidden" name="slot_id" value="<?php echo esc_attr((string) $slot['id']); ?>"><?php wp_nonce_field('etg_dfsb_delete_dynamic_slot'); ?><button class="button button-small" type="submit"><?php echo 'override' === $origin ? 'Reset' : 'Delete'; ?></button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div></div></section>
        <?php
    }

    private function renderEditor(array $edit, array $sourceTypes, array $roles, array $queryOptions, array $relationOptions, array $aggregates, array $metaTokens): void {
        $rows = array_values((array) $edit['sources']);
        while (count($rows) < 8) { $rows[] = array(); }
        ?>
        <div class="etg-editor-sections">
            <div class="etg-editor-main">
                <section id="etg-slot-editor" class="etg-panel"><div class="etg-panel__head"><div><h2><?php echo $edit['id'] ? 'Edit Content Combination' : 'Create Content Combination'; ?></h2><p class="description">Use <code>{{token}}</code>, <code>{{source:alias}}</code>, or <code>{{resolved}}</code>. Image/Gallery slots resolve ordered source fallbacks to normalized attachment IDs.</p></div></div><div class="etg-panel__body">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="etg_dfsb_save_dynamic_slot"><?php wp_nonce_field('etg_dfsb_save_dynamic_slot'); ?>
                    <h3 class="etg-kicker">Basic & Output</h3>
                    <table class="form-table" role="presentation">
                        <tr><th><label for="etg-slot-id">Slot ID</label></th><td><input id="etg-slot-id" name="slot_id" class="regular-text code" required value="<?php echo esc_attr((string) $edit['id']); ?>"></td></tr>
                        <tr><th><label for="etg-slot-label">Label</label></th><td><input id="etg-slot-label" name="label" class="regular-text" value="<?php echo esc_attr((string) $edit['label']); ?>"></td></tr>
                        <tr><th>Enabled</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($edit['enabled'])); ?>> Render this presentation slot</label></td></tr>
                        <tr><th><label for="etg-slot-type">Output Type</label></th><td><select id="etg-slot-type" name="type"><?php foreach (array('text' => 'Text', 'html' => 'HTML', 'url' => 'URL', 'image' => 'Native Image', 'gallery' => 'Native Gallery', 'json' => 'JSON / data helper') as $key => $label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected((string) $edit['type'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr>
                        <tr><th><label for="etg-slot-template">Template</label></th><td><textarea id="etg-slot-template" name="template" class="large-text code" rows="5"><?php echo esc_textarea((string) $edit['template']); ?></textarea><p class="description">Example: <code>{{source:location_intro}}</code> · image/gallery: <code>{{resolved}}</code>.</p></td></tr>
                    </table>

                    <h3 class="etg-kicker">Fallback & Wrapping</h3>
                    <table class="form-table" role="presentation">
                        <tr><th><label for="etg-slot-fallback-chain">Fallback Chain</label></th><td><input id="etg-slot-fallback-chain" name="fallback_chain" class="large-text code" value="<?php echo esc_attr(implode(',', (array) $edit['fallback_chain'])); ?>"><p class="description">Aliases in priority order, e.g. <code>style_image,location_image,related_image,query_image</code>.</p></td></tr>
                        <tr><th><label for="etg-slot-fallback">Static Fallback</label></th><td><textarea id="etg-slot-fallback" name="fallback" class="large-text" rows="2"><?php echo esc_textarea((string) $edit['fallback']); ?></textarea></td></tr>
                        <tr><th>Wrapper Text</th><td><input name="prefix" placeholder="Prefix" value="<?php echo esc_attr((string) $edit['prefix']); ?>"> <input name="suffix" placeholder="Suffix" value="<?php echo esc_attr((string) $edit['suffix']); ?>"></td></tr>
                        <tr><th><label for="etg-slot-max">Max Length</label></th><td><input id="etg-slot-max" type="number" min="0" max="20000" name="max_length" value="<?php echo esc_attr((string) $edit['max_length']); ?>"></td></tr>
                    </table>

                    <h3 class="etg-kicker">Source Builder</h3>
                    <p class="description">Only fields relevant to the selected source type are shown. Query/Relation/Repeater data stays read-only, bounded and lazy.</p>
                    <div class="etg-source-presets">
                        <button type="button" class="button" data-etg-source-preset="listing-image">+ Listing Image</button>
                        <button type="button" class="button" data-etg-source-preset="repeater-gallery">+ Repeater Gallery</button>
                        <button type="button" class="button" data-etg-source-preset="query-gallery">+ Query Gallery</button>
                        <button type="button" class="button" data-etg-source-preset="related-cards">+ Related Cards</button>
                    </div>
                    <div class="etg-table-scroll"><table class="widefat striped" id="etg-source-builder"><thead><tr><th>Alias</th><th>Type</th><th>Role</th><th>Field / Path</th><th>Meta / Repeater Key</th><th>Query</th><th>Relation</th><th>Dir</th><th>Aggregate</th><th>Limit</th><th></th></tr></thead><tbody>
                    <?php foreach ($rows as $index => $row): $this->renderSourceRow($row, $index, count((array) $edit['sources']), $sourceTypes, $roles, $queryOptions, $relationOptions, $aggregates); endforeach; ?>
                    </tbody></table></div>
                    <p><button type="button" class="button" id="etg-add-source">Add Source Row</button></p>
                    <div class="etg-sticky-actions"><a class="button" href="<?php echo esc_url(AdminUi::pageUrl(self::SLUG, array('tab' => 'slots'))); ?>">Back to Slots</a><?php submit_button($edit['id'] ? 'Save Content Combination' : 'Create Content Combination', 'primary', 'submit', false); ?></div>
                    </form>
                </div></section>
            </div>
            <aside class="etg-editor-sidebar">
                <div class="etg-mini-card"><h3>Output Helpers</h3><p><strong>Text/HTML:</strong> <code>ETG Content Slot</code></p><p><strong>Image:</strong> <code>ETG Content Slot Image</code></p><p><strong>Gallery:</strong> <code>ETG Content Slot Gallery</code></p></div>
                <div class="etg-mini-card"><h3>Fallback Rule</h3><p><code>{{resolved}}</code> follows aliases in Fallback Chain. First non-empty normalized result wins.</p></div>
                <div class="etg-mini-card"><h3>Case-sensitive identity</h3><p>Use the exact Query ID and exact Meta/Field key discovered by JetEngine Inspector. <code>HeroImage</code> and <code>heroimage</code> can be different.</p></div>
                <div class="etg-mini-card"><h3>Detected term Meta</h3><p><?php echo esc_html((string) count($metaTokens)); ?> keys available in the current bounded inventory.</p><a class="button" href="<?php echo esc_url(AdminUi::pageUrl(self::SLUG, array('tab' => 'fields'))); ?>">Browse Fields</a></div>
            </aside>
        </div>
        <?php
    }

    private function renderSourceRow(array $row, int $index, int $sourceCount, array $sourceTypes, array $roles, array $queryOptions, array $relationOptions, array $aggregates): void {
        $row = array_merge(array('alias' => '', 'type' => 'listing_field', 'role' => '', 'field' => '', 'meta_key' => '', 'query_id' => 0, 'relation_id' => 0, 'direction' => 'children', 'aggregate' => 'first', 'limit' => 20), $row);
        $hidden = $index >= $sourceCount && $index >= 2;
        ?>
        <tr class="etg-source-row" <?php echo $hidden ? 'hidden' : ''; ?>>
            <td><input name="source_alias[]" class="small-text code" value="<?php echo esc_attr((string) $row['alias']); ?>"></td>
            <td><select name="source_type[]"><?php foreach ($sourceTypes as $key => $label): ?><option value="<?php echo esc_attr((string) $key); ?>" <?php selected((string) $row['type'], (string) $key); ?>><?php echo esc_html((string) $key); ?></option><?php endforeach; ?></select></td>
            <td data-etg-source-field="role"><select name="source_role[]"><option value="">—</option><?php foreach ($roles as $role => $label): ?><option value="<?php echo esc_attr($role); ?>" <?php selected((string) $row['role'], $role); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td>
            <td data-etg-source-field="field"><input name="source_field[]" class="regular-text code" value="<?php echo esc_attr((string) $row['field']); ?>" placeholder="title / image / meta:key"></td>
            <td data-etg-source-field="meta"><input name="source_meta_key[]" class="regular-text code" value="<?php echo esc_attr((string) $row['meta_key']); ?>" placeholder="HeroImage / repeater_key"></td>
            <td data-etg-source-field="query"><select name="source_query_id[]"><option value="0">—</option><?php foreach ($queryOptions as $queryId => $label): ?><option value="<?php echo esc_attr((string) $queryId); ?>" <?php selected((int) $row['query_id'], (int) $queryId); ?>><?php echo esc_html($queryId . ' · ' . $label); ?></option><?php endforeach; ?></select></td>
            <td data-etg-source-field="relation"><select name="source_relation_id[]"><option value="0">—</option><?php foreach ($relationOptions as $relationId => $meta): ?><option value="<?php echo esc_attr((string) $relationId); ?>" <?php selected((int) $row['relation_id'], (int) $relationId); ?>><?php echo esc_html($relationId . ' · ' . (string) ($meta['label'] ?? '')); ?></option><?php endforeach; ?></select></td>
            <td data-etg-source-field="direction"><select name="source_direction[]"><option value="children" <?php selected((string) $row['direction'], 'children'); ?>>Children</option><option value="parents" <?php selected((string) $row['direction'], 'parents'); ?>>Parents</option></select></td>
            <td data-etg-source-field="aggregate"><select name="source_aggregate[]"><?php foreach ($aggregates as $aggregate): ?><option value="<?php echo esc_attr((string) $aggregate); ?>" <?php selected((string) $row['aggregate'], (string) $aggregate); ?>><?php echo esc_html((string) $aggregate); ?></option><?php endforeach; ?></select></td>
            <td data-etg-source-field="limit"><input type="number" min="1" max="100" name="source_limit[]" value="<?php echo esc_attr((string) $row['limit']); ?>" style="width:70px"></td>
            <td class="etg-source-action-cell"><button type="button" class="button button-small" data-etg-remove-source>Remove</button></td>
        </tr>
        <?php
    }

    private function renderHelpers(array $edit): void {
        ?>
        <section class="etg-panel" data-etg-collapsible="1"><div class="etg-panel__head"><div><h2>JetEngine Helper Tools</h2><p class="description">Use the same combinations across Elementor, Listing Items, Components, Dynamic Repeater and Query Builder-driven layouts.</p></div></div><div class="etg-panel__body"><table class="widefat striped"><tbody>
            <tr><th>JetEngine Macro — scalar slot</th><td><code>%etg_content_slot|YOUR_SLOT_ID%</code></td></tr>
            <tr><th>JetEngine Macro — rows source</th><td><code>%etg_slot_rows|YOUR_SLOT_ID|SOURCE_ALIAS%</code></td></tr>
            <tr><th>Dynamic Repeater bridge</th><td>Add CSS class <code><?php echo esc_html(ListingIntegration::repeaterMarker((string) ($edit['id'] ?: 'slot_id'), 'source_alias')); ?></code>.</td></tr>
            <tr><th>Listing / Component current object</th><td>Choose <code>listing_field</code> or <code>listing_meta</code>; ETG follows JetEngine current-object context.</td></tr>
            <tr><th>Repeater source</th><td>Choose <code>repeater</code>, set exact Meta / Repeater Key, then Field / Path for the subfield.</td></tr>
            <tr><th>Slider / Gallery</th><td>Use output <code>gallery</code> + aggregate <code>gallery</code> + <strong>ETG Content Slot Gallery</strong>.</td></tr>
            <tr><th>Image fallback</th><td>Use output <code>image</code> + aggregate <code>image</code>, order aliases in Fallback Chain, then <strong>ETG Content Slot Image</strong>.</td></tr>
        </tbody></table></div></section>
        <section class="etg-panel" data-etg-collapsible="1"><div class="etg-panel__head"><div><h2>Ready-to-use Recipes</h2></div></div><div class="etg-panel__body"><div class="etg-guide-recipe-grid">
            <article class="etg-guide-recipe"><h3>Query Builder Slider</h3><ol><li>Keep JetEngine Query Builder as the native data source.</li><li>Use ETG filter macros in query arguments.</li><li>Render with Listing Grid/Carousel.</li><li>Use a separate gallery slot only when you need a media payload outside the listing.</li></ol></article>
            <article class="etg-guide-recipe"><h3>Related Cards</h3><ol><li>Add <code>relation</code> source.</li><li>Select relation ID and direction.</li><li>Add <code>relation_meta</code> for edge-specific attributes.</li><li>Consume rows via Dynamic Repeater or slot rows macro.</li></ol></article>
            <article class="etg-guide-recipe"><h3>Repeater Gallery</h3><ol><li>Add <code>repeater</code> source.</li><li>Set exact repeater Meta key.</li><li>Set image subfield path.</li><li>Aggregate as <code>gallery</code>.</li></ol></article>
        </div></div></section>
        <?php
    }

    private function renderFields(array $metaTokens, array $sourceCatalog): void {
        $fields = (array) ($sourceCatalog['field_discovery']['fields'] ?? array());
        AdminUi::renderTableSearch('etg-field-search', 'etg-field-table', 'Search source, key, kind or path…', count($fields) . ' discovered fields');
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>JetEngine Field Discovery</h2><p class="description">Case-preserving, bounded discovery. Sensitive-looking keys remain intentionally omitted.</p></div><div class="etg-actions"><a class="button" href="<?php echo esc_url(AdminUi::pageUrl(JetEngineInspectorPage::SLUG, array('tab' => 'fields'))); ?>">Open Inspector</a></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped" id="etg-field-table"><thead><tr><th>Source</th><th>Key</th><th>Kind</th><th>Path / CCT</th></tr></thead><tbody>
        <?php if (!$fields): ?><tr><td colspan="4">No fields detected in the current admin context.</td></tr><?php else: foreach (array_slice($fields, 0, 300, true) as $field): $search = strtolower((string) ($field['source'] ?? '') . ' ' . (string) ($field['key'] ?? '') . ' ' . (string) ($field['kind'] ?? '') . ' ' . (string) ($field['path'] ?? '') . ' ' . (string) ($field['cct'] ?? '')); ?><tr data-etg-search="<?php echo esc_attr($search); ?>"><td><?php echo esc_html((string) ($field['source'] ?? '')); ?></td><td><code><?php echo esc_html((string) ($field['key'] ?? '')); ?></code></td><td><?php echo esc_html((string) ($field['kind'] ?? '')); ?></td><td><code><?php echo esc_html((string) ($field['path'] ?? '')); ?></code><?php if (!empty($field['cct'])): ?> · <?php echo esc_html((string) $field['cct']); ?><?php endif; ?></td></tr><?php endforeach; endif; ?>
        </tbody></table></div></div></section>
        <section class="etg-panel" data-etg-collapsible="1"><div class="etg-panel__head"><div><h2>Detected Taxonomy Meta Helpers</h2><p class="description">Use exact case when copying Meta keys into a <code>term_meta</code> source.</p></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Role</th><th>Meta Key</th><th>Token</th></tr></thead><tbody><?php if (!$metaTokens): ?><tr><td colspan="3">No term meta keys detected in the bounded inventory sample.</td></tr><?php else: foreach ($metaTokens as $meta): ?><tr><td><?php echo esc_html($meta['role']); ?></td><td><code><?php echo esc_html($meta['key']); ?></code></td><td><code><?php echo esc_html($meta['token']); ?></code></td></tr><?php endforeach; endif; ?></tbody></table></div></div></section>
        <?php
    }

    private function renderTokens(array $catalog): void {
        $tokens = (array) ($catalog['tokens'] ?? array());
        AdminUi::renderTableSearch('etg-token-search', 'etg-token-table', 'Search token, label, type or source…', count($tokens) . ' runtime tokens');
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Runtime Inventory Token Catalog</h2><p class="description">Existing filter/context tokens can be used directly or mixed with <code>{{source:alias}}</code>.</p></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped" id="etg-token-table"><thead><tr><th>Token</th><th>Label</th><th>Type</th><th>Source</th><th></th></tr></thead><tbody><?php foreach ($tokens as $token => $meta): $search = strtolower((string) $token . ' ' . (string) ($meta['label'] ?? '') . ' ' . (string) ($meta['type'] ?? '') . ' ' . (string) ($meta['source'] ?? '')); ?><tr data-etg-search="<?php echo esc_attr($search); ?>"><td><code><?php echo esc_html((string) $token); ?></code></td><td><?php echo esc_html((string) ($meta['label'] ?? '')); ?></td><td><?php echo esc_html((string) ($meta['type'] ?? '')); ?></td><td><?php echo esc_html((string) ($meta['source'] ?? '')); ?></td><td><a class="button button-small" href="<?php echo esc_url(AdminUi::pageUrl(self::SLUG, array('tab' => 'editor', 'token' => $token))); ?>">Use</a></td></tr><?php endforeach; ?></tbody></table></div></div></section>
        <?php
    }

    private function metrics(array $slots): array {
        $metrics = array('total' => count($slots), 'enabled' => 0, 'built_in' => 0, 'override' => 0, 'custom' => 0);
        foreach ($slots as $slot) {
            if (!empty($slot['enabled'])) { $metrics['enabled']++; }
            $origin = (string) ($slot['origin'] ?? 'custom');
            if ('built_in' === $origin) { $metrics['built_in']++; }
            elseif ('override' === $origin) { $metrics['override']++; }
            else { $metrics['custom']++; }
        }
        return $metrics;
    }

    private function postedSources(): array {
        $keys = array('source_alias', 'source_type', 'source_role', 'source_field', 'source_meta_key', 'source_query_id', 'source_relation_id', 'source_direction', 'source_aggregate', 'source_limit');
        $data = array();
        foreach ($keys as $key) { $data[$key] = isset($_POST[$key]) ? (array) wp_unslash($_POST[$key]) : array(); }
        $out = array();
        for ($i = 0; $i < ContentSlotRegistry::MAX_SOURCES; $i++) {
            $alias = sanitize_key((string) ($data['source_alias'][$i] ?? ''));
            if ('' === $alias) { continue; }
            $out[] = array(
                'alias' => $alias,
                'type' => (string) ($data['source_type'][$i] ?? 'listing_field'),
                'role' => (string) ($data['source_role'][$i] ?? ''),
                'field' => (string) ($data['source_field'][$i] ?? ''),
                'meta_key' => (string) ($data['source_meta_key'][$i] ?? ''),
                'query_id' => (int) ($data['source_query_id'][$i] ?? 0),
                'relation_id' => (int) ($data['source_relation_id'][$i] ?? 0),
                'direction' => (string) ($data['source_direction'][$i] ?? 'children'),
                'aggregate' => (string) ($data['source_aggregate'][$i] ?? 'first'),
                'limit' => (int) ($data['source_limit'][$i] ?? 20),
            );
        }
        return $out;
    }

    private function post(string $key, string $default = ''): string {
        return isset($_POST[$key]) ? wp_unslash((string) $_POST[$key]) : $default;
    }

    private function roles(array $catalog): array {
        $out = array();
        foreach (array_keys((array) ($catalog['tokens'] ?? array())) as $token) {
            if (0 !== strpos((string) $token, 'term:')) { continue; }
            $parts = explode(':', (string) $token, 3);
            if (3 !== count($parts)) { continue; }
            $role = sanitize_key($parts[1]);
            if ($role) { $out[$role] = ucwords(str_replace(array('_', '-'), ' ', $role)); }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private function metaTokens(array $catalog): array {
        $out = array();
        foreach ((array) ($catalog['tokens'] ?? array()) as $token => $meta) {
            if (0 !== strpos((string) $token, 'termmeta:')) { continue; }
            $parts = explode(':', (string) $token, 3);
            if (3 !== count($parts)) { continue; }
            $out[] = array('role' => $parts[1], 'key' => $parts[2], 'token' => $token);
        }
        return $out;
    }
}
