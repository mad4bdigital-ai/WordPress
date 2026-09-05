<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Context\FilterContextBuilder;
use ETG\DynamicFilterSEOBridge\Runtime\Readiness;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconciler;
use ETG\DynamicFilterSEOBridge\SEO\IndexingPolicy;
use ETG\DynamicFilterSEOBridge\Simulation\ScenarioSimulator;

final class OperationalPage {
    private $config;
    private $profiles;
    private $readiness;
    private $builder;
    private $policy;
    private $simulator;
    private $inventory;
    private $reconciler;

    public function __construct(
        Configuration $config,
        ProfileRegistry $profiles,
        Readiness $readiness,
        FilterContextBuilder $builder,
        IndexingPolicy $policy,
        ScenarioSimulator $simulator,
        RuntimeInventory $inventory,
        InventoryReconciler $reconciler
    ) {
        $this->config = $config;
        $this->profiles = $profiles;
        $this->readiness = $readiness;
        $this->builder = $builder;
        $this->policy = $policy;
        $this->simulator = $simulator;
        $this->inventory = $inventory;
        $this->reconciler = $reconciler;
    }

    public function register(): void {
        add_action('admin_init', array($this, 'settings'));
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_etg_dfsb_export_runtime_inventory', array($this, 'exportRuntimeInventory'));
        add_action('admin_post_etg_dfsb_export_inventory_reconciliation', array($this, 'exportInventoryReconciliation'));
    }

    public function settings(): void {
        register_setting('etg_dfsb', Configuration::OPTION_NAME, array('sanitize_callback' => array($this->config, 'sanitize')));
    }

    public function menu(): void {
        add_options_page('ETG Filter SEO', 'ETG Filter SEO', 'manage_options', 'etg-filter-seo', array($this, 'render'));
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tabs = $this->tabs();
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'overview';
        if (!isset($tabs[$tab])) {
            $tab = 'overview';
        }

        $config = $this->config->all();
        $readiness = $this->readiness->report();
        $previewUri = '';
        $preview = null;
        $scenarioRaw = $this->defaultScenario();
        $scenarioResult = null;
        $discovery = null;
        $runtimeInventory = null;
        $reconciliation = null;
        $blueprintPostType = '';
        $blueprintTaxonomies = array();
        $blueprint = null;

        if ('discovery' === $tab) {
            $discovery = $this->profiles->discovery();
            $blueprintPostType = isset($_GET['etg_blueprint_post_type']) ? sanitize_key((string) wp_unslash($_GET['etg_blueprint_post_type'])) : '';
            $blueprintTaxonomies = isset($_GET['etg_blueprint_taxonomies'])
                ? preg_split('/[\r\n,]+/', wp_unslash((string) $_GET['etg_blueprint_taxonomies']))
                : array();
            if (isset($_GET['etg_build_blueprint'])) {
                $blueprint = $this->profiles->blueprint($blueprintPostType, (array) $blueprintTaxonomies);
            }
        }

        if ('inventory' === $tab && isset($_GET['etg_show_runtime_inventory'])) {
            $runtimeInventory = $this->inventory->collect();
        }

        if ('reconciliation' === $tab && isset($_GET['etg_reconcile_runtime_inventory'])) {
            $reconciliation = $this->reconciler->analyze($this->inventory->collect(), $this->profiles->all());
        }

        if ('inspector' === $tab) {
            $previewUri = isset($_GET['etg_preview_url']) ? wp_unslash((string) $_GET['etg_preview_url']) : '';
            if ('' !== $previewUri) {
                $context = $this->builder->build($previewUri);
                $preview = array('context' => $this->safeContext($context), 'indexing' => $this->policy->decide($context));
            }
        }

        if ('simulation' === $tab) {
            $scenarioRaw = isset($_GET['etg_scenario_json']) ? wp_unslash((string) $_GET['etg_scenario_json']) : $this->defaultScenario();
            if (isset($_GET['etg_run_scenario'])) {
                $decoded = json_decode($scenarioRaw, true);
                $scenarioResult = is_array($decoded)
                    ? $this->simulator->run($decoded)
                    : array('contract' => 'etg.dfsb.simulation.v1', 'synthetic' => true, 'error' => 'invalid_scenario_json');
            }
        }
        ?>
        <div class="wrap etg-dfsb-admin">
            <h1>ETG Dynamic Filter SEO Bridge</h1>
            <?php $this->adminStyles(); ?>
            <?php $this->statusBar($config, $readiness); ?>
            <?php $this->renderNotices($readiness); ?>
            <?php $this->renderTabs($tabs, $tab); ?>

            <div class="etg-tab-panel">
                <?php
                switch ($tab) {
                    case 'settings':
                        $this->renderSettings($config);
                        break;
                    case 'discovery':
                        $this->renderDiscovery($discovery, $blueprintPostType, $blueprintTaxonomies, $blueprint);
                        break;
                    case 'inventory':
                        $this->renderInventory($runtimeInventory);
                        break;
                    case 'reconciliation':
                        $this->renderReconciliation($reconciliation);
                        break;
                    case 'inspector':
                        $this->renderInspector($previewUri, $preview);
                        break;
                    case 'simulation':
                        $this->renderSimulation($scenarioRaw, $scenarioResult);
                        break;
                    case 'overview':
                    default:
                        $this->renderOverview($config, $readiness);
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function tabs(): array {
        return array(
            'overview' => 'Overview',
            'settings' => 'Configuration',
            'discovery' => 'Discovery',
            'inventory' => 'Runtime Inventory',
            'reconciliation' => 'Reconciliation',
            'inspector' => 'URL Inspector',
            'simulation' => 'Scenario Lab',
        );
    }

    private function renderTabs(array $tabs, string $active): void {
        echo '<nav class="nav-tab-wrapper etg-tabs" aria-label="ETG Filter SEO sections">';
        foreach ($tabs as $slug => $label) {
            $url = add_query_arg(array('page' => 'etg-filter-seo', 'tab' => $slug), admin_url('options-general.php'));
            $class = 'nav-tab' . ($active === $slug ? ' nav-tab-active' : '');
            printf('<a class="%1$s" href="%2$s">%3$s</a>', esc_attr($class), esc_url($url), esc_html($label));
        }
        echo '</nav>';
    }

    private function statusBar(array $config, array $readiness): void {
        $globalOn = !empty($config['enabled']);
        $status = strtoupper((string) ($readiness['status'] ?? 'unknown'));
        $revision = (string) ($readiness['configuration_revision'] ?? $this->config->revision());
        $profileCount = (int) ($readiness['profile_count'] ?? count($this->profiles->all()));
        ?>
        <div class="etg-status-grid">
            <div class="etg-status-card">
                <span class="etg-status-label">Global bridge</span>
                <strong class="etg-badge <?php echo $globalOn ? 'etg-badge-danger' : 'etg-badge-safe'; ?>"><?php echo $globalOn ? 'ON' : 'OFF'; ?></strong>
                <?php $this->helpTip('Master kill switch. OFF keeps every profile non-operational even when a profile JSON entry has enabled=true. Turn ON only after runtime evidence and explicit approval.'); ?>
            </div>
            <div class="etg-status-card">
                <span class="etg-status-label">Readiness</span>
                <strong><?php echo esc_html($status); ?></strong>
                <?php $this->helpTip('Aggregated dependency, capability and configuration readiness. Ready does not itself authorize Production indexing.'); ?>
            </div>
            <div class="etg-status-card">
                <span class="etg-status-label">Config revision</span>
                <code><?php echo esc_html($revision); ?></code>
                <?php $this->helpTip('Short deterministic fingerprint of the normalized effective configuration. Use it to correlate evidence with the exact settings snapshot.'); ?>
            </div>
            <div class="etg-status-card">
                <span class="etg-status-label">Profiles</span>
                <strong><?php echo esc_html((string) $profileCount); ?></strong>
                <?php $this->helpTip('Number of normalized Surface Profiles currently visible to the runtime. Profile count is not the number of indexable pages.'); ?>
            </div>
        </div>
        <?php if (!$globalOn): ?>
            <div class="notice notice-success inline etg-safety-notice"><p><strong>Safe mode:</strong> Global bridge is OFF. Discovery, Runtime Inventory, Reconciliation, URL Inspector and Scenario Lab remain available without authorizing indexing.</p></div>
        <?php else: ?>
            <div class="notice notice-error inline etg-safety-notice"><p><strong>Production-impacting mode:</strong> Global bridge is ON. Eligible enabled profiles may affect canonical, robots, metadata and dynamic content. Verify evidence before changing settings.</p></div>
        <?php endif;
    }

    private function renderNotices(array $readiness): void {
        if (!empty($readiness['missing_dependencies'])) {
            printf('<div class="notice notice-error inline"><p><strong>Missing dependencies:</strong> %s</p></div>', esc_html(implode(', ', $readiness['missing_dependencies'])));
        }
        if (!empty($readiness['missing_capabilities'])) {
            printf('<div class="notice notice-error inline"><p><strong>Missing capabilities:</strong> %s</p></div>', esc_html(implode(', ', $readiness['missing_capabilities'])));
        }
        if (!empty($readiness['configuration_errors'])) {
            printf('<div class="notice notice-error inline"><p><strong>Configuration errors:</strong> %s</p></div>', esc_html(implode(', ', $readiness['configuration_errors'])));
        }
    }

    private function renderOverview(array $config, array $readiness): void {
        $globalOn = !empty($config['enabled']);
        ?>
        <div class="etg-card">
            <h2>Operational flow</h2>
            <p>This page is separated into task-focused tabs. The safe operational sequence is:</p>
            <ol class="etg-flow">
                <li><strong>Configuration</strong> — keep the master kill switch OFF while preparing authority.</li>
                <li><strong>Discovery</strong> — observe registered Post Types and taxonomies; never treat discovery as authority.</li>
                <li><strong>Runtime Inventory</strong> — collect bounded Post Type, taxonomy, WPML and JetEngine Query Builder evidence.</li>
                <li><strong>Reconciliation</strong> — compare runtime evidence with configured profiles without mutation.</li>
                <li><strong>URL Inspector</strong> — explain a real URL and its indexing decision.</li>
                <li><strong>Scenario Lab</strong> — challenge policy with synthetic, non-authorizing scenarios.</li>
            </ol>
        </div>
        <div class="etg-card">
            <h2>Current safety boundary</h2>
            <p><strong>Global bridge:</strong> <?php echo $globalOn ? '<span class="etg-badge etg-badge-danger">ON</span>' : '<span class="etg-badge etg-badge-safe">OFF</span>'; ?></p>
            <p><strong>Readiness:</strong> <?php echo esc_html(strtoupper((string) ($readiness['status'] ?? 'unknown'))); ?></p>
            <p>Runtime evidence is read-only. It must not be copied automatically into profile authority. Exact archive paths, exact provider/query routes, taxonomy sets and exact combinations remain operator-controlled.</p>
        </div>
        <?php
    }

    private function renderSettings(array $config): void {
        ?>
        <form method="post" action="options.php" class="etg-settings-form">
            <?php settings_fields('etg_dfsb'); ?>

            <div class="etg-card etg-card-critical">
                <h2>Master safety</h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->checkboxRow(
                        'Global enabled (master kill switch)',
                        'enabled',
                        $config,
                        'Controls whether the bridge may govern requests at all. OFF means every request remains outside bridge authority regardless of profile-level enabled flags.'
                    );
                    ?>
                </table>
            </div>

            <div class="etg-card">
                <h2>Surface Profile authority</h2>
                <p>Profiles are the authoritative bounded registry. Editing this JSON can change route, taxonomy, content and indexing authority once the Global bridge is ON.</p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->rawTextareaRow(
                        'Surface Profiles JSON (authoritative)',
                        'profiles_json',
                        $config,
                        22,
                        'Primary profile registry. Each enabled profile can define exact archive paths, provider/query routes, Post Type rules, taxonomy sets, result thresholds and exact combinations. Invalid or oversized JSON preserves the previous valid snapshot.'
                    );
                    ?>
                </table>
            </div>

            <div class="etg-card">
                <h2>Legacy compatibility and URL parsing</h2>
                <p>These fields exist for compatibility with the migrated Tours profile. New profile authority should be expressed explicitly inside Surface Profiles JSON.</p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->textRow('Legacy archive slugs', 'archive_slugs', $config, 'Compatibility archive slugs inherited only where the profile explicitly allows global-default inheritance. They do not create exact archive-path authority for new profiles.');
                    $this->textRow('Legacy providers', 'providers', $config, 'Compatibility provider names. Provider arrays do not create Cartesian route authority; exact provider/query pairs must exist in routes[].');
                    $this->textRow('Legacy query IDs', 'query_ids', $config, 'Compatibility Query Builder identities. Query IDs are not trusted as Post Type authority until runtime evidence resolves the exact JetEngine query.');
                    $this->textRow('Legacy allowed taxonomies', 'allowed_taxonomies', $config, 'Parser compatibility list. A taxonomy being known here does not authorize an indexing shape; the matched profile still needs explicit taxonomy rules and allowed_taxonomy_sets.');
                    $this->numberRow('Legacy maximum filters', 'max_filters', $config, 'Maximum filter depth accepted by the inherited legacy policy. Profile-specific max_filters takes precedence for explicit profiles.');
                    $this->textRow('Allowed functional query params', 'allowed_query_params', $config, 'Functional query-string keys that may be tolerated by the URL-state parser. Unknown state-bearing parameters fail closed.');
                    $this->textRow('Tracking query params', 'tracking_query_params', $config, 'Tracking-only parameters such as gclid/fbclid. They are ignored for index eligibility and stripped from canonical state where applicable.');
                    ?>
                </table>
            </div>

            <div class="etg-card">
                <h2>Result-count and indexing gates</h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->checkboxRow('Use built-in JetEngine count authority', 'enable_jet_engine_result_count_adapter', $config, 'Uses the verified JetEngine Query Builder and filtered request lifecycle to obtain an authoritative filtered result count.');
                    $this->checkboxRow('Trust legacy numeric count hook', 'trust_legacy_result_count', $config, 'Allows the legacy numeric result-count hook to be treated as authoritative. Keep OFF unless that external hook has separately proven its filtered-count integrity.');
                    $this->checkboxRow('Require authoritative result count for index', 'require_result_count_for_index', $config, 'When ON, indexing cannot proceed without a trusted filtered result count. This is a core fail-closed safety gate.');
                    $this->numberRow('Legacy minimum results: location', 'min_results_location', $config, 'Minimum authoritative filtered results for the inherited one-filter location policy. Explicit profile depth thresholds can override this compatibility fallback.');
                    $this->numberRow('Legacy minimum results: pair', 'min_results_pair', $config, 'Minimum authoritative filtered results for the inherited two-filter policy.');
                    $this->numberRow('Legacy minimum results: triple', 'min_results_triple', $config, 'Minimum authoritative filtered results for the inherited three-filter policy.');
                    $this->checkboxRow('Legacy index single tour type', 'index_single_tour_type', $config, 'Compatibility policy flag for a single Tour Type. It can change the taxonomy rule policy but cannot create an allowed taxonomy set or exact combination authority.');
                    $this->textRow('Legacy indexable location levels', 'indexable_location_levels', $config, 'Allowed values for the legacy location_level term constraint, for example city or landmark.');
                    $this->checkboxRow('Legacy require exact combination approval', 'require_exact_combination_approval', $config, 'Requires exact combination approval in the inherited legacy policy. Global combinations cannot synthesize profile-local combination authority.');
                    $this->textareaRow('Legacy indexable combinations', 'indexable_combinations', $config, 'Compatibility combination list. New controlled-growth authority belongs in the matched profile and remains language/profile bound.');
                    ?>
                </table>
            </div>

            <div class="etg-card">
                <h2>Content and canonical policy</h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->checkboxRow('Global content readiness fallback', 'require_content_readiness', $config, 'Requires content-readiness evidence where a profile does not define its own content policy. Thin or incomplete content remains noindex.');
                    $this->checkboxRow('Global meta description fallback', 'require_meta_description', $config, 'Requires a usable meta description where the matched profile does not override this fallback.');
                    $this->numberRow('Global minimum content characters fallback', 'min_content_chars', $config, 'Minimum deduplicated content corpus length used when the profile does not set its own threshold.');
                    $this->selectRow(
                        'Global canonical fallback',
                        'canonical_mode',
                        $config,
                        array('filtered' => 'Filtered URL', 'archive' => 'Language-aware archive URL'),
                        'Fallback canonical behavior when a profile does not define canonical_mode. Filtered preserves the governed filtered URL; archive points to the language-aware archive.'
                    );
                    ?>
                </table>
            </div>

            <div class="etg-card">
                <h2>Diagnostics</h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->checkboxRow('Diagnostics enabled', 'diagnostics_enabled', $config, 'Enables bounded diagnostic evidence exposed by the plugin. This does not authorize indexing.');
                    $this->checkboxRow('Log decisions to PHP error log', 'log_decisions', $config, 'Writes indexing decision diagnostics to the PHP error log. Enable only when needed because Production traffic can create substantial log volume.');
                    ?>
                </table>
            </div>

            <div class="etg-save-row">
                <?php submit_button('Save Configuration', 'primary', 'submit', false); ?>
                <?php $this->helpTip('Persists the complete ETG configuration. If Global enabled is checked, the saved configuration can immediately affect eligible Production requests. Review the master safety state before saving.'); ?>
            </div>
        </form>
        <?php
    }

    private function renderDiscovery($discovery, string $blueprintPostType, array $blueprintTaxonomies, $blueprint): void {
        $postTypes = is_array($discovery) ? (array) ($discovery['post_types'] ?? array()) : array();
        $taxonomies = is_array($discovery) ? (array) ($discovery['taxonomies'] ?? array()) : array();
        ?>
        <div class="etg-card">
            <h2>Profile Discovery <span class="etg-badge etg-badge-readonly">READ-ONLY</span></h2>
            <p>Shows registered public/runtime-visible Post Types and taxonomies. Discovery never grants route, taxonomy-set, combination or indexing authority.</p>
            <p><strong>Observed Post Types:</strong> <?php echo esc_html((string) count($postTypes)); ?> &nbsp; <strong>Observed Taxonomies:</strong> <?php echo esc_html((string) count($taxonomies)); ?></p>
            <pre class="etg-json"><?php echo esc_html(wp_json_encode($discovery, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
        </div>

        <div class="etg-card">
            <h2>Build Disabled Profile Blueprint</h2>
            <p>Creates an in-memory disabled proposal only. Archive paths, routes, taxonomy sets and exact combinations remain empty by design.</p>
            <form method="get" class="etg-inline-form">
                <input type="hidden" name="page" value="etg-filter-seo" />
                <input type="hidden" name="tab" value="discovery" />
                <label>
                    <span class="etg-field-label">Post Type</span>
                    <?php $this->helpTip('Exact registered Post Type slug to use as the structural subject of the disabled blueprint. Discovery of a Post Type does not authorize it.'); ?>
                    <input class="regular-text code" type="text" name="etg_blueprint_post_type" value="<?php echo esc_attr($blueprintPostType); ?>" placeholder="tours-and-activities" />
                </label>
                <label>
                    <span class="etg-field-label">Taxonomies</span>
                    <?php $this->helpTip('Comma-separated taxonomy slugs to include as non-authorizing blueprint structure. Only taxonomies attached to the selected Post Type should be used.'); ?>
                    <input class="regular-text code" type="text" name="etg_blueprint_taxonomies" value="<?php echo esc_attr(implode(', ', (array) $blueprintTaxonomies)); ?>" placeholder="location_jet, tour-types_jet" />
                </label>
                <span class="etg-action-wrap">
                    <button type="submit" class="button button-secondary" name="etg_build_blueprint" value="1">Build Disabled Blueprint</button>
                    <?php $this->helpTip('Builds a disabled, non-authorizing JSON proposal in memory. It does not save or enable a profile.'); ?>
                </span>
            </form>
            <?php if (null !== $blueprint): ?>
                <pre class="etg-json"><?php echo esc_html(wp_json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderInventory($runtimeInventory): void {
        ?>
        <div class="etg-card">
            <h2>Runtime Inventory <span class="etg-badge etg-badge-readonly">READ-ONLY</span></h2>
            <p>Collects bounded structural evidence for Post Types, taxonomy relations, WPML languages, translated archive paths and JetEngine Query Builder identities. Raw Query Builder arguments are not exported.</p>
            <div class="etg-actions">
                <form method="get">
                    <input type="hidden" name="page" value="etg-filter-seo" />
                    <input type="hidden" name="tab" value="inventory" />
                    <input type="hidden" name="etg_show_runtime_inventory" value="1" />
                    <button type="submit" class="button button-secondary">Generate Runtime Inventory</button>
                    <?php $this->helpTip('Collects the current bounded runtime snapshot in memory and displays it below. No profile, term, route or option is written.'); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="etg_dfsb_export_runtime_inventory" />
                    <?php wp_nonce_field('etg_dfsb_export_runtime_inventory'); ?>
                    <button type="submit" class="button button-secondary">Download Inventory JSON</button>
                    <?php $this->helpTip('Downloads the same read-only runtime evidence as JSON for review, hashing and reconciliation. The file itself is non-authorizing evidence.'); ?>
                </form>
            </div>
            <?php if (null !== $runtimeInventory): ?>
                <?php $this->inventorySummary($runtimeInventory); ?>
                <pre class="etg-json etg-json-scroll"><?php echo esc_html(wp_json_encode($runtimeInventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    private function inventorySummary(array $inventory): void {
        $contract = (string) ($inventory['contract'] ?? 'unknown');
        $complete = !empty($inventory['evidence_complete']);
        $errors = (array) ($inventory['availability_errors'] ?? array());
        $safeClass = $complete && empty($errors) ? 'etg-badge-safe' : 'etg-badge-danger';
        ?>
        <div class="etg-summary-row">
            <span><strong>Contract:</strong> <code><?php echo esc_html($contract); ?></code></span>
            <span><strong>Evidence:</strong> <span class="etg-badge <?php echo esc_attr($safeClass); ?>"><?php echo $complete && empty($errors) ? 'COMPLETE' : 'INCOMPLETE'; ?></span></span>
            <span><strong>Availability errors:</strong> <?php echo esc_html((string) count($errors)); ?></span>
        </div>
        <?php
    }

    private function renderReconciliation($reconciliation): void {
        ?>
        <div class="etg-card">
            <h2>Inventory Reconciliation <span class="etg-badge etg-badge-readonly">READ-ONLY</span></h2>
            <p>Compares the current bounded runtime inventory with configured Profiles. Findings may block or require operator review, but no profile is enabled, disabled or rewritten automatically.</p>
            <div class="etg-actions">
                <form method="get">
                    <input type="hidden" name="page" value="etg-filter-seo" />
                    <input type="hidden" name="tab" value="reconciliation" />
                    <input type="hidden" name="etg_reconcile_runtime_inventory" value="1" />
                    <button type="submit" class="button button-secondary">Reconcile Current Inventory</button>
                    <?php $this->helpTip('Collects a fresh runtime inventory and compares it with the current profile registry. It reports drift and disabled candidates without mutation.'); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="etg_dfsb_export_inventory_reconciliation" />
                    <?php wp_nonce_field('etg_dfsb_export_inventory_reconciliation'); ?>
                    <button type="submit" class="button button-secondary">Download Reconciliation JSON</button>
                    <?php $this->helpTip('Downloads the read-only reconciliation result for audit/review. It does not apply findings or copy discovered authority into profiles.'); ?>
                </form>
            </div>
            <?php if (null !== $reconciliation): ?>
                <pre class="etg-json etg-json-scroll"><?php echo esc_html(wp_json_encode($reconciliation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderInspector(string $previewUri, $preview): void {
        ?>
        <div class="etg-card">
            <h2>Preview / Explain Real URL <span class="etg-badge etg-badge-readonly">READ-ONLY</span></h2>
            <p>Builds the real request context and explains the indexing decision without changing settings.</p>
            <form method="get" class="etg-inline-form etg-inline-form-wide">
                <input type="hidden" name="page" value="etg-filter-seo" />
                <input type="hidden" name="tab" value="inspector" />
                <label class="etg-grow">
                    <span class="etg-field-label">URL or path</span>
                    <?php $this->helpTip('A real site path or absolute URL to evaluate through the bridge context builder and indexing policy. This is an explanation tool, not an activation action.'); ?>
                    <input type="text" class="regular-text code etg-wide-input" name="etg_preview_url" value="<?php echo esc_attr($previewUri); ?>" placeholder="/it/tours-and-activities/jsf/... or https://example.com/..." />
                </label>
                <span class="etg-action-wrap">
                    <button type="submit" class="button button-secondary">Explain URL</button>
                    <?php $this->helpTip('Evaluates the supplied URL against the current configuration and runtime observations, then displays a sanitized context and indexing decision.'); ?>
                </span>
            </form>
            <?php if (null !== $preview): ?>
                <pre class="etg-json"><?php echo esc_html(wp_json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderSimulation(string $scenarioRaw, $scenarioResult): void {
        ?>
        <div class="etg-card">
            <h2>Synthetic Scenario Lab <span class="etg-badge etg-badge-synthetic">SYNTHETIC</span></h2>
            <p>Challenges the real IndexingPolicy with bounded synthetic inputs. Simulation is non-mutating and is never runtime acceptance evidence.</p>
            <form method="get">
                <input type="hidden" name="page" value="etg-filter-seo" />
                <input type="hidden" name="tab" value="simulation" />
                <label>
                    <span class="etg-field-label">Scenario JSON</span>
                    <?php $this->helpTip('Synthetic profile/language/filter/result-count inputs used to challenge policy behavior. Values here do not represent or modify live WordPress data.'); ?>
                    <textarea class="large-text code" rows="16" name="etg_scenario_json"><?php echo esc_textarea($scenarioRaw); ?></textarea>
                </label>
                <div class="etg-action-wrap">
                    <button type="submit" class="button button-secondary" name="etg_run_scenario" value="1">Run Synthetic Scenario</button>
                    <?php $this->helpTip('Runs a non-mutating policy simulation and marks the result synthetic=true. Passing a scenario does not authorize a Production profile.'); ?>
                </div>
            </form>
            <?php if (null !== $scenarioResult): ?>
                <pre class="etg-json"><?php echo esc_html(wp_json_encode($scenarioResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    public function exportRuntimeInventory(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 'Forbidden', array('response' => 403));
        }
        check_admin_referer('etg_dfsb_export_runtime_inventory');
        $payload = $this->inventory->collect();
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="etg-dfsb-runtime-inventory.json"');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function exportInventoryReconciliation(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 'Forbidden', array('response' => 403));
        }
        check_admin_referer('etg_dfsb_export_inventory_reconciliation');
        $payload = $this->reconciler->analyze($this->inventory->collect(), $this->profiles->all());
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="etg-dfsb-inventory-reconciliation.json"');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function defaultScenario(): string {
        $profiles = $this->profiles->all();
        $first = $profiles ? (array) reset($profiles) : array();
        $profileId = (string) ($first['id'] ?? 'tours');
        $rules = (array) ($first['taxonomy_rules'] ?? array());
        $filters = array();
        foreach (array_slice(array_keys($rules), 0, 2) as $taxonomy) {
            $filters[$taxonomy] = 'example';
        }
        $scenario = array(
            'profile_id' => $profileId,
            'language' => 'en',
            'filters' => $filters,
            'result_count' => 10,
            'result_count_authoritative' => true,
            'runtime_ready' => true,
            'provider_match' => true,
            'content_ready' => true,
        );
        if (!empty($first['require_post_type_binding']) && !empty($first['post_types'])) {
            $scenario['post_type'] = (string) reset($first['post_types']);
        }
        return (string) wp_json_encode($scenario, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function textRow(string $label, string $key, array $config, string $help): void {
        $value = implode(', ', (array) ($config[$key] ?? array()));
        echo '<tr><th scope="row">' . esc_html($label) . ' ';
        $this->helpTip($help);
        printf('</th><td><input class="regular-text code" type="text" name="%1$s[%2$s]" value="%3$s" /></td></tr>', esc_attr(Configuration::OPTION_NAME), esc_attr($key), esc_attr($value));
    }

    private function textareaRow(string $label, string $key, array $config, string $help): void {
        $value = implode("\n", (array) ($config[$key] ?? array()));
        echo '<tr><th scope="row">' . esc_html($label) . ' ';
        $this->helpTip($help);
        printf('</th><td><textarea class="large-text code" rows="5" name="%1$s[%2$s]">%3$s</textarea></td></tr>', esc_attr(Configuration::OPTION_NAME), esc_attr($key), esc_textarea($value));
    }

    private function rawTextareaRow(string $label, string $key, array $config, int $rows, string $help): void {
        $value = (string) ($config[$key] ?? '');
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = (string) wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        echo '<tr><th scope="row">' . esc_html($label) . ' ';
        $this->helpTip($help);
        printf('</th><td><textarea class="large-text code etg-profile-json" rows="%4$d" name="%1$s[%2$s]">%3$s</textarea></td></tr>', esc_attr(Configuration::OPTION_NAME), esc_attr($key), esc_textarea($value), $rows);
    }

    private function numberRow(string $label, string $key, array $config, string $help): void {
        echo '<tr><th scope="row">' . esc_html($label) . ' ';
        $this->helpTip($help);
        printf('</th><td><input type="number" min="0" name="%1$s[%2$s]" value="%3$d" /></td></tr>', esc_attr(Configuration::OPTION_NAME), esc_attr($key), (int) ($config[$key] ?? 1));
    }

    private function checkboxRow(string $label, string $key, array $config, string $help): void {
        echo '<tr><th scope="row">' . esc_html($label) . ' ';
        $this->helpTip($help);
        printf('</th><td><input type="hidden" name="%1$s[%2$s]" value="0" /><label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> Enabled</label></td></tr>', esc_attr(Configuration::OPTION_NAME), esc_attr($key), checked(!empty($config[$key]), true, false));
    }

    private function selectRow(string $label, string $key, array $config, array $options, string $help): void {
        echo '<tr><th scope="row">' . esc_html($label) . ' ';
        $this->helpTip($help);
        printf('</th><td><select name="%1$s[%2$s]">', esc_attr(Configuration::OPTION_NAME), esc_attr($key));
        foreach ($options as $value => $optionLabel) {
            printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($value), selected((string) ($config[$key] ?? ''), (string) $value, false), esc_html($optionLabel));
        }
        echo '</select></td></tr>';
    }

    private function helpTip(string $text): void {
        printf(
            '<span class="etg-help" tabindex="0" aria-label="%1$s">?<span class="etg-help-bubble" role="tooltip">%2$s</span></span>',
            esc_attr('Help: ' . $text),
            esc_html($text)
        );
    }

    private function adminStyles(): void {
        ?>
        <style>
            .etg-dfsb-admin{max-width:1440px}.etg-tabs{margin:18px 0 0}.etg-tab-panel{padding-top:18px}.etg-status-grid{display:grid;grid-template-columns:repeat(4,minmax(170px,1fr));gap:12px;margin:16px 0}.etg-status-card,.etg-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 18px;box-sizing:border-box}.etg-status-card{display:flex;align-items:center;gap:8px;min-height:62px}.etg-status-label{color:#50575e;margin-right:auto}.etg-card{margin:0 0 16px}.etg-card h2{margin-top:0}.etg-card-critical{border-left:4px solid #d63638}.etg-badge{display:inline-block;border-radius:999px;padding:3px 9px;font-size:11px;line-height:1.5;font-weight:700;letter-spacing:.04em}.etg-badge-safe{background:#edfaef;color:#116329}.etg-badge-danger{background:#fcf0f1;color:#8a2424}.etg-badge-readonly{background:#eef4ff;color:#174ea6}.etg-badge-synthetic{background:#f5efff;color:#5b2d90}.etg-safety-notice{margin:0 0 18px!important}.etg-json{white-space:pre-wrap;max-width:1180px;background:#f6f7f7;padding:16px;border:1px solid #dcdcde;border-radius:4px;overflow:auto}.etg-json-scroll{max-height:620px}.etg-profile-json{min-height:420px}.etg-actions,.etg-inline-form{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap}.etg-actions form{display:flex;align-items:center;gap:7px}.etg-inline-form label{display:flex;flex-direction:column;gap:6px}.etg-inline-form-wide{align-items:flex-end}.etg-grow{flex:1 1 640px}.etg-wide-input{width:100%!important;max-width:none!important}.etg-field-label{font-weight:600}.etg-action-wrap{display:inline-flex;align-items:center;gap:7px}.etg-save-row{display:flex;align-items:center;gap:8px;margin:16px 0 28px}.etg-summary-row{display:flex;gap:18px;flex-wrap:wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:10px 12px;margin:14px 0;border-radius:4px}.etg-flow li{margin-bottom:8px}.etg-help{position:relative;display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border:1px solid #8c8f94;border-radius:50%;font-size:12px;font-weight:700;color:#50575e;background:#fff;cursor:help;vertical-align:middle;box-sizing:border-box}.etg-help:focus{outline:2px solid #2271b1;outline-offset:2px}.etg-help-bubble{display:none;position:absolute;z-index:9999;left:24px;top:-8px;width:320px;max-width:70vw;padding:10px 12px;border-radius:4px;background:#1d2327;color:#fff;font-size:12px;font-weight:400;line-height:1.45;box-shadow:0 4px 16px rgba(0,0,0,.2);text-align:left}.etg-help:hover .etg-help-bubble,.etg-help:focus .etg-help-bubble,.etg-help:focus-within .etg-help-bubble{display:block}.form-table th{width:285px}.form-table td{vertical-align:top}@media(max-width:900px){.etg-status-grid{grid-template-columns:1fr 1fr}.etg-help-bubble{left:auto;right:24px}}@media(max-width:600px){.etg-status-grid{grid-template-columns:1fr}.etg-tabs{display:flex;overflow-x:auto}.etg-tabs .nav-tab{white-space:nowrap}.form-table th{width:auto}.etg-help-bubble{width:260px}}
        </style>
        <?php
    }

    private function safeContext(array $context): array {
        foreach ((array) ($context['terms'] ?? array()) as $role => $term) {
            if (is_array($term)) {
                unset($term['description'], $term['short_description'], $term['gallery_ids']);
                $context['terms'][$role] = $term;
            }
        }
        if (isset($context['combo']['gallery_ids'])) {
            $context['combo']['gallery_ids'] = array_slice((array) $context['combo']['gallery_ids'], 0, 10);
        }
        if (isset($context['profile']['indexable_combinations'])) {
            $context['profile']['indexable_combinations'] = array_slice((array) $context['profile']['indexable_combinations'], 0, 20);
        }
        return $context;
    }
}
