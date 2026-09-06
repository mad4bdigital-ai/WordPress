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

    public function __construct( RuntimeInventory $inventory, InventoryContentCatalog $catalog, ContentSlotRegistry $slots, ProfileRegistry $profiles ) {
        $this->inventory = $inventory;
        $this->catalog   = $catalog;
        $this->slots     = $slots;
        $this->profiles  = $profiles;
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'menu' ) );
    }

    public function menu(): void {
        add_options_page( 'ETG Usage Guide', 'ETG Usage Guide', 'manage_options', self::SLUG, array( $this, 'render' ) );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $snapshot = $this->inventory->collect();
        $catalog  = $this->catalog->build( $snapshot, $this->profiles->all() );
        $slots    = $this->slots->all();
        $profiles = $this->profiles->all();
        $tab      = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'quick-start';
        $tabs     = array(
            'quick-start' => 'Quick Start',
            'dynamic-tags' => 'Dynamic Tags',
            'shortcodes' => 'Shortcodes',
            'tokens' => 'Tokens',
            'slots' => 'Content Slots',
            'recipes' => 'Recipes',
            'safety' => 'Safety & SEO',
        );
        if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'quick-start'; }

        ?>
        <div class="wrap etg-dfsb-admin etg-dfsb-usage-guide">
            <div class="etg-page-head">
                <div>
                    <h1>ETG Usage Guide</h1>
                    <p>In-product reference for Elementor Dynamic Tags, shortcodes, Runtime Inventory tokens and Dynamic Content slots. This page is documentation only and never grants profile, indexing or publication authority.</p>
                </div>
                <div class="etg-actions">
                    <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'etg-dfsb-dynamic-content' ), admin_url( 'options-general.php' ) ) ); ?>">Dynamic Content</a>
                    <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'etg-dfsb-inventory-control' ), admin_url( 'options-general.php' ) ) ); ?>">Inventory Control</a>
                </div>
            </div>

            <div class="etg-status-grid">
                <div class="etg-status-card"><span class="etg-status-label">Guide Authority</span><span class="etg-status-value"><span class="etg-badge etg-badge--readonly">DOCUMENTATION ONLY</span></span><span class="etg-status-help">No writes, activation or SEO mutation.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Profiles</span><span class="etg-status-value"><?php echo esc_html( (string) count( $profiles ) ); ?></span><span class="etg-status-help">Current configured surface profiles.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Available Slots</span><span class="etg-status-value"><?php echo esc_html( (string) count( $slots ) ); ?></span><span class="etg-status-help">Built-in, customized and custom slots.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Runtime Tokens</span><span class="etg-status-value"><?php echo esc_html( (string) count( (array) ( $catalog['tokens'] ?? array() ) ) ); ?></span><span class="etg-status-help">Current allowlisted Inventory-derived tokens.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Inventory Fingerprint</span><span class="etg-status-value"><code><?php echo esc_html( substr( (string) ( $snapshot['snapshot_fingerprint'] ?? '' ), 0, 16 ) ); ?></code></span><span class="etg-status-help">Shows which runtime snapshot this guide reflects.</span></div>
            </div>

            <nav class="nav-tab-wrapper etg-guide-tabs" aria-label="ETG Usage Guide sections">
                <?php foreach ( $tabs as $key => $label ) : ?>
                    <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $key ), admin_url( 'options-general.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            switch ( $tab ) {
                case 'dynamic-tags': $this->renderDynamicTags(); break;
                case 'shortcodes': $this->renderShortcodes(); break;
                case 'tokens': $this->renderTokens( $catalog ); break;
                case 'slots': $this->renderSlots( $slots ); break;
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
            array( '1', 'Inventory', 'Run Runtime Inventory / Reconciliation and verify the provider, Query ID and Post Type binding before using presentation surfaces.' ),
            array( '2', 'Keep authority closed', 'For dark validation keep Global OFF and the target profile disabled. Documentation and Dynamic Content do not authorize indexing.' ),
            array( '3', 'Choose the output', 'Use a direct Elementor Dynamic Tag for common values, a Content Slot for composed presentation, or a shortcode where Elementor Dynamic Tags are unavailable.' ),
            array( '4', 'Preview', 'Use the same-site Editor Preview URL inside ETG tags when needed. Preview context is evidence-only and never becomes SEO authority.' ),
            array( '5', 'Validate live filters', 'Confirm JetSmartFilters updates visible text, reset behavior and result count while URL/history and SEO metadata remain unchanged.' ),
        );
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Quick Start</h2><p class="description">Recommended product workflow from discovery to safe presentation.</p></div></div><div class="etg-panel__body">
            <div class="etg-guide-step-grid">
                <?php foreach ( $steps as $step ) : ?><div class="etg-guide-step"><span class="etg-guide-step__number"><?php echo esc_html( $step[0] ); ?></span><div><h3><?php echo esc_html( $step[1] ); ?></h3><p><?php echo esc_html( $step[2] ); ?></p></div></div><?php endforeach; ?>
            </div>
        </section>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Fastest Elementor Setup</h2></div></div><div class="etg-panel__body">
            <table class="widefat striped"><thead><tr><th>Need</th><th>Use</th><th>Where</th></tr></thead><tbody>
                <tr><td>Archive heading</td><td><strong>ETG Filter Title</strong></td><td>Heading → Dynamic Tags</td></tr>
                <tr><td>Archive introduction</td><td><strong>ETG Filter Intro</strong></td><td>Text / HTML → Dynamic Tags</td></tr>
                <tr><td>Selected term content</td><td><strong>ETG Filter Term Field / Term Section</strong></td><td>Text / HTML → Dynamic Tags</td></tr>
                <tr><td>Result summary</td><td><strong>ETG Filter Result Summary</strong></td><td>Heading / Text → Dynamic Tags</td></tr>
                <tr><td>Image</td><td><strong>ETG Filter Image</strong></td><td>Native Elementor image controls</td></tr>
                <tr><td>Gallery</td><td><strong>ETG Filter Gallery</strong></td><td>Native Elementor gallery controls</td></tr>
                <tr><td>Composed custom content</td><td><strong>ETG Content Slot</strong></td><td>Create/edit under ETG Dynamic Content</td></tr>
            </tbody></table>
        </section>
        <?php
    }

    private function renderDynamicTags(): void {
        $tags = array(
            array( 'ETG Filter Title', 'Text', 'Generated archive/filter title', 'Yes', 'No' ),
            array( 'ETG Filter Intro', 'Text / HTML', 'Generated introduction', 'Yes', 'No' ),
            array( 'ETG Filter Result Summary', 'Text', 'Presentation result summary', 'Yes', 'No' ),
            array( 'ETG Filter Keyword', 'Text', 'Presentation keyword', 'Yes', 'No' ),
            array( 'ETG Filter Archive URL', 'URL', 'Governed archive URL', 'Server', 'No' ),
            array( 'ETG Filter Current URL', 'URL', 'Current request URL', 'Server', 'No' ),
            array( 'ETG Inventory Value', 'Text / URL', 'Allowlisted Runtime Inventory token', 'Yes', 'No' ),
            array( 'ETG Content Slot', 'Text / HTML / URL', 'Configured Dynamic Content slot', 'Yes', 'No' ),
            array( 'ETG Filter Term Field', 'Text / URL', 'Selected role field such as name, description, slug, SEO fields, image URL/ID or count', 'Yes', 'No' ),
            array( 'ETG Filter Term Section', 'HTML', 'Selected term description section with optional heading', 'Yes', 'No' ),
            array( 'ETG Filter Image', 'Image', 'Native Elementor image value', 'Server / preview', 'No' ),
            array( 'ETG Filter Image URL', 'URL', 'Selected filter image URL', 'Explicit target path', 'No' ),
            array( 'ETG Filter Gallery', 'Gallery', 'Native Elementor gallery value', 'Server / preview', 'No' ),
        );
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Elementor Dynamic Tags</h2><p class="description">Look for the <strong>ETG Filter SEO</strong> group in Elementor Dynamic Tags.</p></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Tag</th><th>Output</th><th>Use</th><th>AJAX/live</th><th>SEO authority</th></tr></thead><tbody>
            <?php foreach ( $tags as $tag ) : ?><tr><td><strong><?php echo esc_html( $tag[0] ); ?></strong></td><td><?php echo esc_html( $tag[1] ); ?></td><td><?php echo esc_html( $tag[2] ); ?></td><td><?php echo esc_html( $tag[3] ); ?></td><td><span class="etg-badge etg-badge--readonly"><?php echo esc_html( $tag[4] ); ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="notice notice-info inline"><p><strong>Elementor media note:</strong> Image and Gallery are native server/editor-preview values. Transient AJAX replacement of Elementor-managed media attributes remains a separate runtime-adapter boundary. Text/HTML live bindings and explicit governed URL targets are the supported Alpha13 live path.</p></div>
        </div></section>
        <?php
    }

    private function renderShortcodes(): void {
        $items = array(
            array( '[etg_filter_h1]', 'Filter title / H1', 'No attributes' ),
            array( '[etg_filter_intro]', 'Filter introduction', 'No attributes' ),
            array( '[etg_filter_sections]', 'Generated term sections', 'No attributes' ),
            array( '[etg_filter_gallery mode="combined" limit="9" size="large"]', 'Rendered gallery', 'mode, limit, size' ),
            array( '[etg_filter_keyword]', 'Presentation keyword', 'No attributes' ),
            array( '[etg_filter_breadcrumb_context]', 'Selected-term breadcrumb labels', 'No attributes' ),
            array( '[etg_filter_term role="location" field="description" autop="1" size="full"]', 'Selected term field', 'role or taxonomy, field, autop, size' ),
            array( '[etg_filter_term_section role="location" field="description" heading="1" heading_level="2"]', 'Term description section', 'role or taxonomy, field, heading, heading_level, class' ),
            array( '[etg_dynamic_content id="hero_title" live="1"]', 'Dynamic Content slot', 'id, group, live' ),
            array( '[etg_dynamic_value token="term:location:name" format="text" live="1"]', 'Allowlisted presentation token', 'token, format, group, live' ),
        );
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Shortcodes</h2><p class="description">Shortcodes remain supported alongside Dynamic Tags. Dynamic Tags are usually the cleaner Elementor-first workflow.</p></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Shortcode</th><th>Purpose</th><th>Attributes</th><th></th></tr></thead><tbody>
            <?php foreach ( $items as $i => $item ) : $id = 'etg-shortcode-' . $i; ?><tr><td><code id="<?php echo esc_attr( $id ); ?>" class="etg-copy-source"><?php echo esc_html( $item[0] ); ?></code></td><td><?php echo esc_html( $item[1] ); ?></td><td><?php echo esc_html( $item[2] ); ?></td><td><button type="button" class="button button-small etg-copy-button" data-etg-copy-target="<?php echo esc_attr( $id ); ?>">Copy</button></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <p class="description"><code>live="1"</code> emits the Alpha13 live presentation binding. Use <code>live="0"</code> for server-render-only output. <code>group="provider/query-id"</code> scopes live output on pages with multiple JetSmartFilters provider groups.</p>
        </div></section>
        <?php
    }

    private function renderTokens( array $catalog ): void {
        $tokens = (array) ( $catalog['tokens'] ?? array() );
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Runtime Inventory Tokens</h2><p class="description">This list is generated from the current Runtime Inventory and profile configuration; it is not a hard-coded generic catalog.</p></div></div><div class="etg-panel__body">
            <div class="etg-token-toolbar"><span><strong><?php echo esc_html( (string) count( $tokens ) ); ?></strong> allowlisted tokens</span><input id="etg-guide-token-search" type="search" placeholder="Search token, label, type or source…" aria-label="Search guide tokens"></div>
            <div class="etg-table-scroll"><table class="widefat striped" id="etg-guide-token-table"><thead><tr><th>Token</th><th>Label</th><th>Type</th><th>Source</th><th></th></tr></thead><tbody>
            <?php foreach ( $tokens as $token => $meta ) : $id = 'etg-token-' . substr( md5( (string) $token ), 0, 12 ); $search = strtolower( (string) $token . ' ' . (string) ( $meta['label'] ?? '' ) . ' ' . (string) ( $meta['type'] ?? '' ) . ' ' . (string) ( $meta['source'] ?? '' ) ); ?><tr data-etg-search="<?php echo esc_attr( $search ); ?>"><td><code id="<?php echo esc_attr( $id ); ?>" class="etg-copy-source"><?php echo esc_html( (string) $token ); ?></code></td><td><?php echo esc_html( (string) ( $meta['label'] ?? '' ) ); ?></td><td><?php echo esc_html( strtoupper( (string) ( $meta['type'] ?? 'text' ) ) ); ?></td><td><?php echo esc_html( (string) ( $meta['source'] ?? '' ) ); ?></td><td><button type="button" class="button button-small etg-copy-button" data-etg-copy-target="<?php echo esc_attr( $id ); ?>">Copy</button></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div></section>
        <?php
    }

    private function renderSlots( array $slots ): void {
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Content Slots</h2><p class="description">Slots compose one or more allowlisted tokens into reusable presentation content.</p></div><div class="etg-actions"><a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'etg-dfsb-dynamic-content' ), admin_url( 'options-general.php' ) ) ); ?>">Open Dynamic Content</a></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Slot</th><th>Type</th><th>Origin</th><th>Status</th><th>Elementor / shortcode</th></tr></thead><tbody>
            <?php foreach ( $slots as $slot ) : ?><tr><td><strong><?php echo esc_html( (string) ( $slot['label'] ?? '' ) ); ?></strong><br><code><?php echo esc_html( (string) ( $slot['id'] ?? '' ) ); ?></code></td><td><?php echo esc_html( strtoupper( (string) ( $slot['type'] ?? 'text' ) ) ); ?></td><td><?php echo esc_html( str_replace( '_', ' ', (string) ( $slot['origin'] ?? 'custom' ) ) ); ?></td><td><span class="etg-badge <?php echo ! empty( $slot['enabled'] ) ? 'etg-badge--safe' : 'etg-badge--warn'; ?>"><?php echo ! empty( $slot['enabled'] ) ? 'Enabled' : 'Disabled'; ?></span></td><td><code>ETG Content Slot</code><br><code>[etg_dynamic_content id=&quot;<?php echo esc_attr( (string) ( $slot['id'] ?? '' ) ); ?>&quot;]</code></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <p class="description">Built-in slots are defaults only; they do not silently write WordPress options. Customizing a built-in creates an override that can be reset back to the default.</p>
        </div></section>
        <?php
    }

    private function renderRecipes(): void {
        $recipes = array(
            array( 'Dynamic Tours Archive Hero', array( 'Heading → ETG Filter Title', 'Intro/Text → ETG Filter Intro', 'Results line → ETG Filter Result Summary', 'Background/Image → ETG Filter Image' ) ),
            array( 'Location Content Block', array( 'Heading → ETG Filter Term Field → role: location → field: name', 'Body → ETG Filter Term Section → role: location → field: description', 'Optional image → ETG Filter Image' ) ),
            array( 'Reusable Custom Hero', array( 'Create/edit slot hero_title under ETG Dynamic Content', 'Use allowlisted tokens such as {{term:location:name}} and {{result_count}}', 'Elementor → ETG Content Slot → hero_title', 'Shortcode fallback → [etg_dynamic_content id="hero_title"]' ) ),
            array( 'Multi-provider Page', array( 'Use group="provider/query-id" on live shortcodes', 'Use explicit data-etg-dfsb-group on governed custom bindings', 'Leave unscoped live bindings unused when more than one provider group exists; Alpha13 fails closed by design' ) ),
        );
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Recipes</h2><p class="description">Practical combinations for common Elementor / JetSmartFilters layouts.</p></div></div><div class="etg-panel__body"><div class="etg-guide-recipe-grid">
            <?php foreach ( $recipes as $recipe ) : ?><article class="etg-guide-recipe"><h3><?php echo esc_html( $recipe[0] ); ?></h3><ol><?php foreach ( $recipe[1] as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ol></article><?php endforeach; ?>
        </div></div></section>
        <?php
    }

    private function renderSafety(): void {
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Safety & SEO Authority</h2><p class="description">Presentation is deliberately separate from indexing/publication authority.</p></div></div><div class="etg-panel__body">
            <table class="widefat striped"><thead><tr><th>Surface</th><th>Can render content?</th><th>Can enable profile?</th><th>Can authorize SEO/indexing?</th><th>Can mutate browser history?</th></tr></thead><tbody>
                <tr><td>Usage Guide</td><td>Documentation only</td><td>No</td><td>No</td><td>No</td></tr>
                <tr><td>Dynamic Tags / Shortcodes</td><td>Yes</td><td>No</td><td>No</td><td>No</td></tr>
                <tr><td>Dynamic Content Slots</td><td>Yes</td><td>No</td><td>No</td><td>No</td></tr>
                <tr><td>AJAX Presentation</td><td>Yes, supported states only</td><td>No</td><td>No</td><td>No</td></tr>
                <tr><td>Inventory Control</td><td>Structural planning/apply only</td><td>Forces profile disabled</td><td>No</td><td>No</td></tr>
                <tr><td>Publication / Indexing policy</td><td>Separate governed surface</td><td>Separate authorization</td><td>Only after all explicit gates</td><td>Not derived from AJAX state</td></tr>
            </tbody></table>
            <div class="notice notice-warning inline"><p><strong>Alpha13 invariant:</strong> AJAX state remains <code>authorizing=false</code>, <code>url_authority=false</code> and <code>seo_mutation=false</code>. Unsupported meta/date/search/non-taxonomy state fails closed rather than being reinterpreted as authoritative content.</p></div>
        </div></section>
        <?php
    }
}
