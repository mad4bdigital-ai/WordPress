<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\InventoryContentCatalog;

final class UsageGuidePage {
    const SLUG = 'etg-dfsb-usage-guide';

    private $inventory;
    private $catalog;
    private $slots;
    private $profiles;

    public function __construct(RuntimeInventory $inventory, InventoryContentCatalog $catalog, ContentSlotRegistry $slots, ProfileRegistry $profiles) {
        $this->inventory = $inventory;
        $this->catalog = $catalog;
        $this->slots = $slots;
        $this->profiles = $profiles;
    }

    public function register(): void { add_action('admin_menu', array($this, 'menu')); }
    public function menu(): void { add_options_page('ETG Usage Guide', 'ETG Usage Guide', 'manage_options', self::SLUG, array($this, 'render')); }

    public function render(): void {
        if (!current_user_can('manage_options')) { return; }
        $snapshot = $this->inventory->collect();
        $catalog = $this->catalog->build($snapshot, $this->profiles->all());
        $slots = $this->slots->all();
        $profiles = $this->profiles->all();
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'quick-start';
        $tabs = array(
            'quick-start'=>'Quick Start',
            'dynamic-tags'=>'Dynamic Tags',
            'shortcodes'=>'Shortcodes',
            'tokens'=>'Tokens',
            'slots'=>'Content Slots',
            'jetengine'=>'JetEngine',
            'recipes'=>'Recipes',
            'safety'=>'Safety & SEO',
        );
        if (!isset($tabs[$tab])) { $tab = 'quick-start'; }
        ?>
        <div class="wrap etg-dfsb-admin etg-dfsb-usage-guide">
            <div class="etg-page-head"><div><h1>ETG Usage Guide</h1><p>In-product reference for Elementor Dynamic Tags, shortcodes, Runtime Inventory tokens, Dynamic Content and JetEngine content sources. This page is documentation only and never grants profile, indexing or publication authority.</p></div><div class="etg-actions">
                <a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>'etg-dfsb-dynamic-content'), admin_url('options-general.php'))); ?>">Dynamic Content</a>
                <a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>'etg-dfsb-jetengine-inspector'), admin_url('options-general.php'))); ?>">JetEngine Inspector</a>
                <a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>'etg-dfsb-inventory-control'), admin_url('options-general.php'))); ?>">Inventory Control</a>
            </div></div>
            <div class="etg-status-grid">
                <div class="etg-status-card"><span class="etg-status-label">Guide Authority</span><span class="etg-status-value"><span class="etg-badge etg-badge--readonly">DOCUMENTATION ONLY</span></span><span class="etg-status-help">No writes, activation or SEO mutation.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Profiles</span><span class="etg-status-value"><?php echo esc_html((string) count($profiles)); ?></span><span class="etg-status-help">Current configured surface profiles.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Available Slots</span><span class="etg-status-value"><?php echo esc_html((string) count($slots)); ?></span><span class="etg-status-help">Built-in, customized and custom slots.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Runtime Tokens</span><span class="etg-status-value"><?php echo esc_html((string) count((array) ($catalog['tokens'] ?? array()))); ?></span><span class="etg-status-help">Current allowlisted Inventory-derived tokens.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Inventory Fingerprint</span><span class="etg-status-value"><code><?php echo esc_html(substr((string) ($snapshot['snapshot_fingerprint'] ?? ''), 0, 16)); ?></code></span><span class="etg-status-help">Runtime snapshot reflected by this guide.</span></div>
            </div>
            <nav class="nav-tab-wrapper etg-guide-tabs" aria-label="ETG Usage Guide sections"><?php foreach ($tabs as $key=>$label): ?><a class="nav-tab <?php echo $tab===$key?'nav-tab-active':''; ?>" href="<?php echo esc_url(add_query_arg(array('page'=>self::SLUG,'tab'=>$key), admin_url('options-general.php'))); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav>
            <?php
            switch ($tab) {
                case 'dynamic-tags': $this->renderDynamicTags(); break;
                case 'shortcodes': $this->renderShortcodes(); break;
                case 'tokens': $this->renderTokens($catalog); break;
                case 'slots': $this->renderSlots($slots); break;
                case 'jetengine': $this->renderJetEngine(); break;
                case 'recipes': $this->renderRecipes(); break;
                case 'safety': $this->renderSafety(); break;
                default: $this->renderQuickStart(); break;
            }
            ?>
        </div>
        <?php
    }

    private function renderQuickStart(): void {
        $steps = array(
            array('1','Inventory','Run Runtime Inventory / Reconciliation and verify provider, exact Query ID and Post Type binding.'),
            array('2','Inspect JetEngine','Open JetEngine Inspector to discover Query Builder IDs, Relations, CCTs, Meta/Repeater/Media fields and current Listing context.'),
            array('3','Keep authority closed','For dark validation keep Global OFF and the target profile disabled. Dynamic Content does not authorize indexing.'),
            array('4','Choose the output','Use a direct Dynamic Tag for simple values, a Content Slot for composed sources, or the ETG JetEngine macros/context for Listing/Query Builder.'),
            array('5','Preview','Use the same-site Preview Filter URL. Preview evidence remains synthetic/presentation-only.'),
            array('6','Validate live filters','Verify text, media, result count and reset behavior while URL/history and SEO authority remain unchanged.'),
        ); ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Quick Start</h2><p class="description">Discovery → composition → safe live validation.</p></div></div><div class="etg-panel__body"><div class="etg-guide-step-grid"><?php foreach($steps as $step): ?><div class="etg-guide-step"><span class="etg-guide-step__number"><?php echo esc_html($step[0]); ?></span><div><h3><?php echo esc_html($step[1]); ?></h3><p><?php echo esc_html($step[2]); ?></p></div></div><?php endforeach; ?></div></div></section>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Fastest Elementor Setup</h2></div></div><div class="etg-panel__body"><table class="widefat striped"><thead><tr><th>Need</th><th>Use</th><th>Where</th></tr></thead><tbody>
            <tr><td>Archive heading</td><td><strong>ETG Filter Title</strong></td><td>Heading → Dynamic Tags</td></tr>
            <tr><td>Archive introduction</td><td><strong>ETG Filter Intro</strong></td><td>Text / HTML → Dynamic Tags</td></tr>
            <tr><td>Selected term content</td><td><strong>ETG Filter Term Field / Term Section</strong></td><td>Text / HTML → Dynamic Tags</td></tr>
            <tr><td>Result summary</td><td><strong>ETG Filter Result Summary</strong></td><td>Heading / Text → Dynamic Tags</td></tr>
            <tr><td>Image</td><td><strong>ETG Content Slot Image</strong> or <strong>ETG Filter Image</strong></td><td>Native Elementor image control</td></tr>
            <tr><td>Gallery</td><td><strong>ETG Content Slot Gallery</strong> or <strong>ETG Filter Gallery</strong></td><td>Native Elementor gallery control</td></tr>
            <tr><td>Live image/background</td><td><code>[etg_dynamic_image]</code> / <code>[etg_dynamic_background]</code></td><td>Shortcode/HTML container with explicit ETG media bridge</td></tr>
            <tr><td>JetEngine Listing/Query data</td><td><strong>Content Slot Source Builder</strong></td><td>listing / repeater / query / relation / relation_meta</td></tr>
        </tbody></table></div></section><?php
    }

    private function renderDynamicTags(): void {
        $tags = array(
            array('ETG Filter Title','Text','Generated archive/filter title','Yes','No'),
            array('ETG Filter Intro','Text / HTML','Generated introduction','Yes','No'),
            array('ETG Filter Result Summary','Text','Presentation result summary','Yes','No'),
            array('ETG Filter Keyword','Text','Presentation keyword','Yes','No'),
            array('ETG Filter Archive URL','URL','Governed archive URL','Server','No'),
            array('ETG Filter Current URL','URL','Current request URL','Server','No'),
            array('ETG Inventory Value','Text / URL','Allowlisted Runtime Inventory token','Yes','No'),
            array('ETG Content Slot','Text / HTML / URL','Configured Dynamic Content slot','Yes','No'),
            array('ETG Content Slot Image','Image','Image Content Slot with ordered source fallback','Server / preview','No'),
            array('ETG Content Slot Gallery','Gallery','Gallery Content Slot with ordered source fallback','Server / preview','No'),
            array('ETG Filter Term Field','Text / URL','Selected role field','Yes','No'),
            array('ETG Filter Term Section','HTML','Selected term description section','Yes','No'),
            array('ETG Filter Image','Image','Filter-composed image','Server / preview','No'),
            array('ETG Filter Image URL','URL','Filter image URL','Explicit target path','No'),
            array('ETG Filter Gallery','Gallery','Filter-composed gallery','Server / preview','No'),
        ); ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Elementor Dynamic Tags</h2><p class="description">Look for <strong>ETG Filter SEO</strong> in Elementor Dynamic Tags.</p></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Tag</th><th>Output</th><th>Use</th><th>AJAX/live</th><th>SEO authority</th></tr></thead><tbody><?php foreach($tags as $tag): ?><tr><td><strong><?php echo esc_html($tag[0]); ?></strong></td><td><?php echo esc_html($tag[1]); ?></td><td><?php echo esc_html($tag[2]); ?></td><td><?php echo esc_html($tag[3]); ?></td><td><span class="etg-badge etg-badge--readonly"><?php echo esc_html($tag[4]); ?></span></td></tr><?php endforeach; ?></tbody></table></div>
        <div class="notice notice-info inline"><p><strong>Live media boundary:</strong> Native Elementor Image/Gallery Dynamic Tags remain server/editor values. For transient JetSmartFilters media replacement use the explicit <code>etg_dynamic_image</code>, <code>etg_dynamic_background</code> or <code>etg_dynamic_gallery</code> helpers. The browser bridge only touches elements carrying ETG media binding attributes and emits <code>etg-dfsb/media-updated</code> for slider/gallery adapters.</p></div></div></section><?php
    }

    private function renderShortcodes(): void {
        $items = array(
            array('[etg_filter_h1]','Filter title / H1','No attributes'),
            array('[etg_filter_intro]','Filter introduction','No attributes'),
            array('[etg_filter_sections]','Generated term sections','No attributes'),
            array('[etg_filter_gallery mode="combined" limit="9" size="large"]','Rendered filter gallery','mode, limit, size'),
            array('[etg_filter_keyword]','Presentation keyword','No attributes'),
            array('[etg_filter_breadcrumb_context]','Selected-term breadcrumb labels','No attributes'),
            array('[etg_filter_term role="location" field="description" autop="1" size="full"]','Selected term field','role or taxonomy, field, autop, size'),
            array('[etg_filter_term_section role="location" field="description" heading="1" heading_level="2"]','Term description section','role/taxonomy, field, heading, heading_level, class'),
            array('[etg_dynamic_content id="hero_title" live="1"]','Text/HTML/URL Content Slot','id, group, live'),
            array('[etg_dynamic_value token="termmeta:location:HeroImage" format="text" live="1"]','Exact-case presentation token','token, format, group, live'),
            array('[etg_dynamic_rows id="cards" source="related_tours" format="json"]','Content Slot source rows','id, source, format=json|count'),
            array('[etg_dynamic_image id="hero_image" live="1" alt="Cairo tours"]','Live image Content Slot','id, group, live, alt, class, loading'),
            array('[etg_dynamic_background id="hero_image" live="1" class="hero"]Hero content[/etg_dynamic_background]','Live background image Content Slot','id, group, live, class'),
            array('[etg_dynamic_gallery id="hero_gallery" live="1" limit="12"]','Gallery payload bridge for Swiper/JetEngine/custom adapter','id, group, live, limit, class'),
        ); ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Shortcodes</h2><p class="description">Dynamic Tags remain the Elementor-first path; these helpers cover non-Dynamic-Tag and transient media use cases.</p></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Shortcode</th><th>Purpose</th><th>Attributes</th><th></th></tr></thead><tbody><?php foreach($items as $i=>$item): $id='etg-shortcode-'.$i; ?><tr><td><code id="<?php echo esc_attr($id); ?>" class="etg-copy-source"><?php echo esc_html($item[0]); ?></code></td><td><?php echo esc_html($item[1]); ?></td><td><?php echo esc_html($item[2]); ?></td><td><button type="button" class="button button-small etg-copy-button" data-etg-copy-target="<?php echo esc_attr($id); ?>">Copy</button></td></tr><?php endforeach; ?></tbody></table></div>
        <p class="description"><code>live="1"</code> emits a presentation-only live binding. <code>group="provider/query-id"</code> preserves the exact case-sensitive Query ID. Meta token suffixes are also exact-case, e.g. <code>HeroImage</code> is not rewritten to <code>heroimage</code>.</p></div></section><?php
    }

    private function renderTokens(array $catalog): void {
        $tokens=(array)($catalog['tokens']??array()); ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Runtime Inventory Tokens</h2><p class="description">Generated from the current Runtime Inventory and profile configuration.</p></div></div><div class="etg-panel__body"><div class="etg-token-toolbar"><span><strong><?php echo esc_html((string)count($tokens)); ?></strong> allowlisted tokens</span><input id="etg-guide-token-search" type="search" placeholder="Search token, label, type or source…" aria-label="Search guide tokens"></div><div class="etg-table-scroll"><table class="widefat striped" id="etg-guide-token-table"><thead><tr><th>Token</th><th>Label</th><th>Type</th><th>Source</th><th></th></tr></thead><tbody><?php foreach($tokens as $token=>$meta): $id='etg-token-'.substr(md5((string)$token),0,12);$search=strtolower((string)$token.' '.(string)($meta['label']??'').' '.(string)($meta['type']??'').' '.(string)($meta['source']??'')); ?><tr data-etg-search="<?php echo esc_attr($search); ?>"><td><code id="<?php echo esc_attr($id); ?>" class="etg-copy-source"><?php echo esc_html((string)$token); ?></code></td><td><?php echo esc_html((string)($meta['label']??'')); ?></td><td><?php echo esc_html(strtoupper((string)($meta['type']??'text'))); ?></td><td><?php echo esc_html((string)($meta['source']??'')); ?></td><td><button type="button" class="button button-small etg-copy-button" data-etg-copy-target="<?php echo esc_attr($id); ?>">Copy</button></td></tr><?php endforeach; ?></tbody></table></div></div></section><?php
    }

    private function renderSlots(array $slots): void { ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Content Slots</h2><p class="description">Slots compose filter tokens and JetEngine sources into reusable presentation content.</p></div><div class="etg-actions"><a class="button button-primary" href="<?php echo esc_url(add_query_arg(array('page'=>'etg-dfsb-dynamic-content'),admin_url('options-general.php'))); ?>">Open Dynamic Content</a></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Slot</th><th>Type</th><th>Origin</th><th>Status</th><th>Elementor / shortcode</th></tr></thead><tbody><?php foreach($slots as $slot): $type=(string)($slot['type']??'text');$id=(string)($slot['id']??''); ?><tr><td><strong><?php echo esc_html((string)($slot['label']??'')); ?></strong><br><code><?php echo esc_html($id); ?></code></td><td><?php echo esc_html(strtoupper($type)); ?></td><td><?php echo esc_html(str_replace('_',' ',(string)($slot['origin']??'custom'))); ?></td><td><span class="etg-badge <?php echo !empty($slot['enabled'])?'etg-badge--safe':'etg-badge--warn'; ?>"><?php echo !empty($slot['enabled'])?'Enabled':'Disabled'; ?></span></td><td><?php if('image'===$type): ?><code>ETG Content Slot Image</code><br><code>[etg_dynamic_image id=&quot;<?php echo esc_attr($id); ?>&quot;]</code><?php elseif('gallery'===$type): ?><code>ETG Content Slot Gallery</code><br><code>[etg_dynamic_gallery id=&quot;<?php echo esc_attr($id); ?>&quot;]</code><?php else: ?><code>ETG Content Slot</code><br><code>[etg_dynamic_content id=&quot;<?php echo esc_attr($id); ?>&quot;]</code><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><p class="description">Built-in defaults do not silently write options. A customized built-in is an explicit override and can be reset.</p></div></section><?php
    }

    private function renderJetEngine(): void {
        $macros=array(
            array('%etg_content_slot|hero_title%','Resolved scalar Content Slot'),
            array('%etg_slot_rows|cards|related_tours%','Content Slot source rows JSON'),
            array('%etg_filter_value|result_count%','Presentation token'),
            array('%etg_filter_term_id|location%','First selected term ID'),
            array('%etg_filter_term_ids|location|,%','All selected term IDs'),
            array('%etg_filter_term_slug|location%','First selected term slug'),
            array('%etg_filter_term_slugs|location|,%','All selected term slugs'),
            array('%etg_filter_term_meta|location|HeroImage%','Exact-case meta value from first selected term'),
            array('%etg_filter_term_meta_values|location|RegionCode|,%','Exact-case meta values from all selected terms'),
        ); ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>JetEngine Native Integration</h2><p class="description">Use ETG as a read-only presentation/context provider rather than duplicating JetEngine query, relation or listing engines.</p></div><div class="etg-actions"><a class="button button-primary" href="<?php echo esc_url(add_query_arg(array('page'=>'etg-dfsb-jetengine-inspector'),admin_url('options-general.php'))); ?>">Open JetEngine Inspector</a></div></div><div class="etg-panel__body">
        <table class="widefat striped"><thead><tr><th>Capability</th><th>ETG surface</th><th>Notes</th></tr></thead><tbody>
            <tr><td>Current Listing / Component object</td><td><code>listing_field</code>, <code>listing_meta</code></td><td>Uses JetEngine current-object context and supports Posts, Terms, Users, CCT-like rows and nested paths.</td></tr>
            <tr><td>Repeater</td><td><code>repeater</code></td><td>Current listing meta or selected taxonomy term meta; Dynamic Repeater bridge can consume source rows.</td></tr>
            <tr><td>Query Builder</td><td><code>query</code></td><td>Bounded to 100 items, request-cached and recursion-guarded.</td></tr>
            <tr><td>Relations</td><td><code>relation</code></td><td>Parents/children with Posts, Terms, Users and CCT hydration.</td></tr>
            <tr><td>Relation edge metadata</td><td><code>relation_meta</code></td><td>Reads metadata stored on the relation edge separately from object meta.</td></tr>
            <tr><td>CCT / Meta Box discovery</td><td>Inspector + Source Builder</td><td>Shows bounded field catalog including repeater/media/gallery classifications.</td></tr>
            <tr><td>Listing context</td><td><code>ETG Filter Context</code></td><td>Presentation-only object includes title, intro, provider/query and selected term IDs/slugs.</td></tr>
        </tbody></table>
        <h3>Query Builder / Listing Macros</h3><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Macro</th><th>Purpose</th></tr></thead><tbody><?php foreach($macros as $macro): ?><tr><td><code><?php echo esc_html($macro[0]); ?></code></td><td><?php echo esc_html($macro[1]); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <div class="notice notice-info inline"><p><strong>Identity rule:</strong> Query IDs and Meta/Field keys preserve exact case. Use the value shown by JetEngine Inspector; do not normalize <code>myGrid</code> to <code>mygrid</code> or <code>HeroImage</code> to <code>heroimage</code>.</p></div>
        </div></section><?php
    }

    private function renderRecipes(): void {
        $recipes=array(
            array('Dynamic Tours Archive Hero',array('Heading → ETG Filter Title','Intro → ETG Filter Intro','Result line → ETG Filter Result Summary','Image slot → ETG Content Slot Image','For transient filter changes use [etg_dynamic_image id="hero_image" live="1"] or [etg_dynamic_background ...].')),
            array('JetEngine Listing Grid + JetSmartFilters',array('Keep the Listing Grid provider/query native to JetEngine/JetSmartFilters.','Use ETG Filter Context or ETG macros only for supplementary content/query arguments.','Use exact provider/query identity from Inspector when scoping live ETG content.','Do not create a second ETG list renderer; JetEngine remains the item renderer.')),
            array('Query Builder Slider',array('Create/read a normal JetEngine Query Builder query.','Use %etg_filter_term_ids|location|,% or %etg_filter_term_slugs|location|,% in supported query arguments.','Use the query as the data source for JetEngine/Elementor slider/listing.','For a separate image gallery payload use [etg_dynamic_gallery id="hero_gallery" live="1"] and listen for etg-dfsb/media-updated.')),
            array('Relation Cards + Relation Meta',array('Create a Content Slot source type relation for parents/children.','Set relation ID, direction and Field/Path for related object fields.','Add a relation_meta source for edge attributes such as order, label, price or featured flags.','Use Dynamic Repeater or %etg_slot_rows|SLOT|ALIAS% to consume rows.')),
            array('CCT Cards / Slider',array('Open JetEngine Inspector → CCTs and Fields.','Use a JetEngine Query Builder CCT query as a query source, or a Relation source whose object type is cct::slug.','Reference exact CCT field paths; media/gallery fields are normalized to attachment IDs when possible.','Render with JetEngine Listing/Component or a Content Slot media helper.')),
            array('Repeater-driven Slider',array('Create a repeater source and enter the exact Meta / Repeater Key.','Set Field / Path to the repeater subfield needed by the card.','Dynamic Repeater bridge class: etg-source--SLOT_ID--SOURCE_ALIAS.','For media fields use image/gallery aggregate instead of parsing JSON manually.')),
            array('Multi-provider Page',array('Use explicit group="provider/query-id" when more than one JetSmartFilters group exists.','Query ID is case-sensitive end-to-end.','Auto mode resolves pretty-URL group first, then one active group, then one available group.','Any remaining ambiguity fails closed.')),
        ); ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Recipes</h2><p class="description">Practical Elementor + JetEngine + JetSmartFilters composition patterns.</p></div></div><div class="etg-panel__body"><div class="etg-guide-recipe-grid"><?php foreach($recipes as $recipe): ?><article class="etg-guide-recipe"><h3><?php echo esc_html($recipe[0]); ?></h3><ol><?php foreach($recipe[1] as $line): ?><li><?php echo esc_html($line); ?></li><?php endforeach; ?></ol></article><?php endforeach; ?></div></div></section><?php
    }

    private function renderSafety(): void { ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Safety & SEO Authority</h2><p class="description">Presentation and JetEngine content access remain separate from indexing/publication authority.</p></div></div><div class="etg-panel__body"><table class="widefat striped"><thead><tr><th>Surface</th><th>Can render/read content?</th><th>Can enable profile?</th><th>Can authorize SEO/indexing?</th><th>Can mutate browser history?</th></tr></thead><tbody>
            <tr><td>Usage Guide / JetEngine Inspector</td><td>Documentation/read-only inspection</td><td>No</td><td>No</td><td>No</td></tr>
            <tr><td>Dynamic Tags / Shortcodes</td><td>Yes</td><td>No</td><td>No</td><td>No</td></tr>
            <tr><td>Dynamic Content / JetEngine sources</td><td>Yes, bounded read/composition</td><td>No</td><td>No</td><td>No</td></tr>
            <tr><td>AJAX Presentation / Live Media</td><td>Yes, supported states only</td><td>No</td><td>No</td><td>No</td></tr>
            <tr><td>Inventory Control</td><td>Structural planning/apply only</td><td>Forces profile disabled</td><td>No</td><td>No</td></tr>
            <tr><td>Publication / Indexing policy</td><td>Separate governed surface</td><td>Separate authorization</td><td>Only after all explicit gates</td><td>Not derived from AJAX state</td></tr>
        </tbody></table><div class="notice notice-warning inline"><p><strong>Alpha13 invariant:</strong> AJAX state remains <code>authorizing=false</code>, <code>url_authority=false</code> and <code>seo_mutation=false</code>. JetEngine Query/Relation/CCT reads, media events and helper shortcodes cannot grant publication authority.</p></div></div></section><?php
    }
}
