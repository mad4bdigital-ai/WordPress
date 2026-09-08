<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryProfilePlanner;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;

final class InventoryControlPage {
    const SLUG = 'etg-dfsb-inventory-control';
    private $config;
    private $profiles;
    private $inventory;
    private $planner;

    public function __construct(Configuration $config, ProfileRegistry $profiles, RuntimeInventory $inventory, InventoryProfilePlanner $planner) {
        $this->config=$config;$this->profiles=$profiles;$this->inventory=$inventory;$this->planner=$planner;
    }
    public function register(): void { add_action('admin_menu',array($this,'menu')); add_action('admin_post_etg_dfsb_apply_inventory_profile_plan',array($this,'apply')); }
    public function menu(): void { add_options_page('ETG Inventory Control','ETG Inventory Control','manage_options',self::SLUG,array($this,'render')); }

    public function render(): void {
        if(!current_user_can('manage_options')){return;}
        $snapshot=$this->inventory->collect();
        $plan=$this->planner->plan($snapshot,$this->profiles->all());
        $config=$this->config->all();
        $revision=$this->config->revision();
        $globalOn=!empty($config['enabled']);
        $proposals=(array)($plan['proposals']??array());
        $readyCount=0;foreach($proposals as $proposal){if(!empty($proposal['safe_to_apply'])){$readyCount++;}}
        $tabs=array('overview'=>'Overview','plans'=>'Profile Plans','safety'=>'Safety Contract');
        $tab=AdminUi::activeTab($tabs,'overview');
        if(isset($_GET['profile'])||isset($_GET['applied'])||isset($_GET['error'])){$tab='plans';}
        ?>
        <div class="wrap etg-dfsb-admin etg-dfsb-inventory-control">
            <?php AdminUi::renderHeader(self::SLUG,'ETG Inventory Control','Review Runtime Inventory evidence and apply only fail-closed structural profile bindings. This surface never grants combination or publication authority.',array(
                array('label'=>'Reconcile Current Inventory','url'=>AdminUi::pageUrl('etg-filter-seo',array('tab'=>'reconciliation','etg_reconcile_runtime_inventory'=>'1')),'primary'=>true),
                array('label'=>'Dynamic Content','url'=>AdminUi::pageUrl(DynamicContentPage::SLUG)),
            )); ?>
            <?php if(isset($_GET['applied'])):?><div class="notice notice-success is-dismissible"><p>Inventory profile patch applied in disabled/fail-closed mode. Re-run Reconciliation before any activation decision.</p></div><?php endif; ?>
            <?php if(isset($_GET['error'])):?><div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field((string)wp_unslash($_GET['error']))); ?></p></div><?php endif; ?>
            <div class="etg-status-grid">
                <div class="etg-status-card"><span class="etg-status-label">Global Bridge</span><span class="etg-status-value"><span class="etg-badge <?php echo $globalOn?'etg-badge--danger':'etg-badge--safe'; ?>"><?php echo $globalOn?'ON · APPLY LOCKED':'OFF · SAFE PLANNING'; ?></span></span><span class="etg-status-help">Structural apply is only available while Global is OFF.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Profiles Observed</span><span class="etg-status-value"><?php echo esc_html((string)count($proposals)); ?></span><span class="etg-status-help"><?php echo esc_html((string)$readyCount); ?> safe verified proposals.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Inventory Fingerprint</span><span class="etg-status-value"><code><?php echo esc_html(substr((string)($plan['snapshot_fingerprint']??''),0,16)); ?></code></span><span class="etg-status-help">Must remain unchanged between review and apply.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Config Revision</span><span class="etg-status-value"><code><?php echo esc_html(substr($revision,0,16)); ?></code></span><span class="etg-status-help">Concurrency guard prevents stale plan writes.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Authority</span><span class="etg-status-value"><span class="etg-badge etg-badge--readonly">NON-AUTHORIZING PLAN</span></span><span class="etg-status-help">No taxonomy set or combination is approved here.</span></div>
            </div>
            <?php AdminUi::renderTabs(self::SLUG,$tabs,$tab); ?>
            <?php if('overview'===$tab):$this->renderOverview($proposals,$readyCount,$globalOn);elseif('safety'===$tab):$this->renderSafety();else:$this->renderPlans($plan,$proposals,$revision,$globalOn);endif; ?>
        </div>
        <?php
    }

    private function renderOverview(array $proposals,int $readyCount,bool $globalOn):void{
        ?>
        <div class="etg-overview-grid">
            <article class="etg-overview-card"><h3>Review Profile Plans</h3><p><?php echo esc_html((string)count($proposals)); ?> profiles are visible; <?php echo esc_html((string)$readyCount); ?> currently satisfy the safe-to-apply structural contract.</p><a class="button button-primary" href="<?php echo esc_url(AdminUi::pageUrl(self::SLUG,array('tab'=>'plans'))); ?>">Open Plans</a></article>
            <article class="etg-overview-card"><h3>Reconcile First</h3><p>Refresh provider/query/Post Type evidence before applying any disabled-profile structural patch.</p><a class="button" href="<?php echo esc_url(AdminUi::pageUrl('etg-filter-seo',array('tab'=>'reconciliation','etg_reconcile_runtime_inventory'=>'1'))); ?>">Run Reconciliation</a></article>
            <article class="etg-overview-card"><h3>Safety Boundary</h3><p>Apply remains <?php echo $globalOn?'locked because Global is ON':'available only for individually safe proposals while Global stays OFF'; ?>.</p><a class="button" href="<?php echo esc_url(AdminUi::pageUrl(self::SLUG,array('tab'=>'safety'))); ?>">Read Contract</a></article>
        </div>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Proposal Summary</h2><p class="description">Use this table to choose which profile needs attention before opening full evidence.</p></div></div><div class="etg-panel__body"><table class="widefat striped etg-profile-summary-table"><thead><tr><th>Profile</th><th>Status</th><th>Safe to apply</th><th>Blocking reasons</th><th></th></tr></thead><tbody>
        <?php if(!$proposals):?><tr><td colspan="5">No profile proposals are available from the current Runtime Inventory.</td></tr><?php else:foreach($proposals as $profileId=>$proposal):?><tr><td><code><?php echo esc_html((string)$profileId); ?></code></td><td><?php echo esc_html((string)($proposal['status']??'unknown')); ?></td><td><span class="etg-badge <?php echo !empty($proposal['safe_to_apply'])?'etg-badge--safe':'etg-badge--warn'; ?>"><?php echo !empty($proposal['safe_to_apply'])?'YES':'NO'; ?></span></td><td><?php echo esc_html(implode(', ',(array)($proposal['blocking_reasons']??array()))); ?></td><td><a class="button button-small" href="<?php echo esc_url(AdminUi::pageUrl(self::SLUG,array('tab'=>'plans','profile'=>$profileId))); ?>">Review</a></td></tr><?php endforeach;endif; ?>
        </tbody></table></div></section>
        <?php
    }

    private function renderPlans(array $plan,array $proposals,string $revision,bool $globalOn):void{
        if(!$proposals){echo '<div class="etg-empty">No profile proposals are available from the current Runtime Inventory.</div>';return;}
        $focus=isset($_GET['profile'])?sanitize_key((string)wp_unslash($_GET['profile'])):'';
        foreach($proposals as $profileId=>$proposal){if($focus&&$focus!==$profileId){continue;}$this->renderPlan($plan,$profileId,(array)$proposal,$revision,$globalOn);}
        if($focus){echo '<p><a class="button" href="'.esc_url(AdminUi::pageUrl(self::SLUG,array('tab'=>'plans'))).'">Show all profile plans</a></p>';}
    }

    private function renderPlan(array $plan,string $profileId,array $proposal,string $revision,bool $globalOn):void{
        $safe=!empty($proposal['safe_to_apply']);$candidates=(array)($proposal['taxonomy_candidates']??array());$routeEvidence=(array)($proposal['route_evidence']??array());$route=array();foreach($routeEvidence as $candidateRoute){if(is_array($candidateRoute)&&!empty($candidateRoute['resolved'])){$route=$candidateRoute;break;}}if(!$route&&$routeEvidence){$first=reset($routeEvidence);$route=is_array($first)?$first:array();}$configuredCount=0;foreach($candidates as $candidate){if(!empty($candidate['configured'])){$configuredCount++;}}
        ?>
        <section id="profile-<?php echo esc_attr($profileId); ?>" class="etg-panel" data-etg-collapsible="1"><div class="etg-panel__head"><div><h2>Profile: <code><?php echo esc_html($profileId); ?></code></h2><p class="description"><?php echo esc_html((string)count($candidates)); ?> taxonomy candidates · <?php echo esc_html((string)$configuredCount); ?> configured</p></div><span class="etg-badge <?php echo $safe?'etg-badge--safe':'etg-badge--warn'; ?>"><?php echo esc_html(strtoupper((string)($proposal['status']??'unknown'))); ?></span></div><div class="etg-panel__body">
            <?php if(!empty($proposal['blocking_reasons'])):?><div class="notice notice-error inline"><p><strong>Blocked:</strong> <?php echo esc_html(implode(', ',(array)$proposal['blocking_reasons'])); ?></p></div><?php endif; ?>
            <h3>Verified Route Evidence</h3>
            <?php if($route):?><div class="etg-route-grid"><div class="etg-route-item"><span>Status</span><strong><?php echo !empty($route['resolved'])?'Verified':'Unresolved'; ?></strong></div><div class="etg-route-item"><span>Provider</span><code><?php echo esc_html((string)($route['provider']??'')); ?></code></div><div class="etg-route-item"><span>Provider Query ID</span><code><?php echo esc_html((string)($route['provider_query_id']??'')); ?></code></div><div class="etg-route-item"><span>Query Builder ID</span><code><?php echo esc_html((string)($route['query_builder_query_id']??'')); ?></code></div><div class="etg-route-item"><span>Internal Locator</span><code><?php echo esc_html((string)($route['query_builder_internal_id']??'')); ?></code><small class="description">Evidence only</small></div><div class="etg-route-item"><span>Query Type</span><code><?php echo esc_html((string)($route['query_type']??'')); ?></code></div><div class="etg-route-item"><span>Post Types</span><code><?php echo esc_html(implode(', ',(array)($route['post_types']??array()))); ?></code></div><div class="etg-route-item"><span>Reason</span><code><?php echo esc_html((string)($route['reason']??'')); ?></code></div></div><?php endif; ?>
            <details class="etg-details"><summary>Raw route evidence</summary><pre><?php echo esc_html(wp_json_encode($routeEvidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); ?></pre></details>
            <h3>Safe Structural Changes</h3>
            <?php if(empty($proposal['changes'])):?><p><span class="etg-badge etg-badge--safe">No structural changes required</span></p><?php else:?><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Field</th><th>Current</th><th>Proposed</th></tr></thead><tbody><?php foreach((array)$proposal['changes'] as $change):?><tr><td><code><?php echo esc_html((string)$change['field']); ?></code></td><td><code><?php echo esc_html(wp_json_encode($change['from'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); ?></code></td><td><code><?php echo esc_html(wp_json_encode($change['to'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); ?></code></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="etg_dfsb_apply_inventory_profile_plan"><input type="hidden" name="profile_id" value="<?php echo esc_attr($profileId); ?>"><input type="hidden" name="snapshot_fingerprint" value="<?php echo esc_attr((string)($plan['snapshot_fingerprint']??'')); ?>"><input type="hidden" name="config_revision" value="<?php echo esc_attr($revision); ?>"><?php wp_nonce_field('etg_dfsb_apply_inventory_profile_plan'); ?>
                <h3>Optional Content Taxonomies</h3><p class="description">Opt-in adds a disabled-profile taxonomy rule for presentation/filter context only. It is not added to indexing authority.</p>
                <div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Use</th><th>Taxonomy</th><th>Role</th><th>Configured</th><th>Attached Post Types</th></tr></thead><tbody><?php foreach($candidates as $taxonomy=>$candidate):?><tr><td><?php if(empty($candidate['configured'])):?><input type="checkbox" name="taxonomy_select[]" value="<?php echo esc_attr((string)$taxonomy); ?>"><?php else:?><span class="etg-badge etg-badge--safe">In profile</span><?php endif; ?></td><td><code><?php echo esc_html((string)$taxonomy); ?></code><br><span class="description"><?php echo esc_html((string)($candidate['label']??'')); ?></span></td><td><?php if(empty($candidate['configured'])):?><input type="text" name="taxonomy_role[<?php echo esc_attr((string)$taxonomy); ?>]" value="<?php echo esc_attr((string)($candidate['suggested_role']??$taxonomy)); ?>"><?php else:?><code><?php echo esc_html((string)($candidate['suggested_role']??$taxonomy)); ?></code><?php endif; ?></td><td><?php echo !empty($candidate['configured'])?'Yes':'No'; ?></td><td><code><?php echo esc_html(implode(', ',(array)($candidate['attached_post_types']??array()))); ?></code></td></tr><?php endforeach; ?></tbody></table></div>
                <label class="etg-confirm"><input type="checkbox" name="confirm_fail_closed" value="1" required><span><strong>Fail-closed confirmation</strong><br>I understand this action forces the profile disabled, keeps Global OFF, and invalidates prior publication-verification evidence.</span></label>
                <div class="etg-submit-row"><span class="description"><?php echo $globalOn?'Global is ON, so apply is locked.':($safe?'Proposal is eligible for disabled-profile apply.':'Resolve the blocking evidence before apply.'); ?></span><button type="submit" class="button button-primary" <?php disabled($globalOn||!$safe); ?>>Apply Safe Disabled Profile Patch</button></div>
            </form>
        </div></section>
        <?php
    }

    private function renderSafety():void{
        ?>
        <section class="etg-panel"><div class="etg-panel__head"><div><h2>Inventory Safety Contract</h2><p class="description">What this page can and cannot change.</p></div></div><div class="etg-panel__body"><table class="widefat striped"><thead><tr><th>Capability</th><th>Allowed here?</th><th>Rule</th></tr></thead><tbody>
            <tr><td>Observe Runtime Inventory</td><td><span class="etg-badge etg-badge--safe">YES</span></td><td>Read-only evidence.</td></tr>
            <tr><td>Apply verified structural route/Post Type bindings</td><td><span class="etg-badge etg-badge--warn">BOUNDED</span></td><td>Global must be OFF; profile is forced disabled.</td></tr>
            <tr><td>Add optional presentation taxonomy rules</td><td><span class="etg-badge etg-badge--warn">BOUNDED</span></td><td>Does not add allowed taxonomy sets or indexable combinations.</td></tr>
            <tr><td>Enable profile or Global</td><td><span class="etg-badge etg-badge--danger">NO</span></td><td>Never performed here.</td></tr>
            <tr><td>Authorize indexing / sitemap / canonical</td><td><span class="etg-badge etg-badge--danger">NO</span></td><td>Separate Publication governance.</td></tr>
        </tbody></table></div></section>
        <?php
    }

    public function apply(): void {
        if(!current_user_can('manage_options')){wp_die('Forbidden',403);}check_admin_referer('etg_dfsb_apply_inventory_profile_plan');if(empty($_POST['confirm_fail_closed'])){$this->redirectError('Explicit fail-closed confirmation is required.');}$config=$this->config->all();if(!empty($config['enabled'])){$this->redirectError('Global bridge must be OFF before applying an Inventory profile patch.');}$expectedRevision=isset($_POST['config_revision'])?sanitize_text_field(wp_unslash((string)$_POST['config_revision'])):'';if(''===$expectedRevision||!hash_equals($expectedRevision,$this->config->revision())){$this->redirectError('Configuration changed after the proposal was generated. Refresh and review again.');}
        $snapshot=$this->inventory->collect();$expectedFingerprint=isset($_POST['snapshot_fingerprint'])?sanitize_text_field(wp_unslash((string)$_POST['snapshot_fingerprint'])):'';$actualFingerprint=(string)($snapshot['snapshot_fingerprint']??'');if(''===$expectedFingerprint||!hash_equals($expectedFingerprint,$actualFingerprint)){$this->redirectError('Runtime Inventory changed after the proposal was generated. Refresh and review again.');}
        $profileId=isset($_POST['profile_id'])?sanitize_key(wp_unslash((string)$_POST['profile_id'])):'';$plan=$this->planner->plan($snapshot,$this->profiles->all());$proposal=(array)($plan['proposals'][$profileId]??array());if(!$proposal||empty($proposal['safe_to_apply'])){$this->redirectError('The selected profile does not have a safe verified Inventory proposal.');}
        $rawProfiles=json_decode((string)($config['profiles_json']??''),true);if(!is_array($rawProfiles)){$this->redirectError('Surface Profiles JSON is unavailable.');}$index=null;foreach($rawProfiles as $i=>$profile){if(is_array($profile)&&$profileId===sanitize_key((string)($profile['id']??''))){$index=$i;break;}}if(null===$index){$this->redirectError('Profile no longer exists.');}
        $raw=(array)$rawProfiles[$index];$proposed=(array)($proposal['proposed_profile']??array());foreach(array('post_types','require_post_type_binding','post_type_authority','routes') as $field){if(array_key_exists($field,$proposed)){$raw[$field]=$proposed[$field];}}if(empty($raw['archive_paths'])&&!empty($proposed['archive_paths'])){$raw['archive_paths']=$proposed['archive_paths'];}$raw['enabled']=false;
        $rules=is_array($raw['taxonomy_rules']??null)?$raw['taxonomy_rules']:array();$priority=0;foreach($rules as $rule){if(is_array($rule)){$priority=max($priority,(int)($rule['priority']??0));}}$selected=isset($_POST['taxonomy_select'])?(array)wp_unslash($_POST['taxonomy_select']):array();$roles=isset($_POST['taxonomy_role'])&&is_array($_POST['taxonomy_role'])?wp_unslash($_POST['taxonomy_role']):array();$candidates=(array)($proposal['taxonomy_candidates']??array());foreach(array_slice($selected,0,20) as $taxonomy){$taxonomy=sanitize_key((string)$taxonomy);if(''===$taxonomy||!isset($candidates[$taxonomy])||!empty($candidates[$taxonomy]['configured'])){continue;}$role=sanitize_key((string)($roles[$taxonomy]??$candidates[$taxonomy]['suggested_role']??$taxonomy));if(''===$role){$role=$taxonomy;}$priority+=10;$rules[$taxonomy]=array('role'=>$role,'priority'=>$priority,'gallery_priority'=>$priority,'index_single'=>false,'min_results'=>3,'required_meta_key'=>'','required_meta_values'=>array(),'meta_constraint_scope'=>'single','field_map'=>array());}$raw['taxonomy_rules']=$rules;
        $publication=is_array($raw['publication']??null)?$raw['publication']:array();foreach(array('elementor_content_verified','provider_observation_verified','result_count_parity_verified') as $flag){$publication[$flag]=false;}foreach(array('elementor_verification_evidence_id','provider_observation_evidence_id','result_count_parity_evidence_id') as $field){$publication[$field]='';}$raw['publication']=$publication;$rawProfiles[$index]=$raw;
        $config['enabled']=false;$config['profiles_json']=wp_json_encode($rawProfiles,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$saved=update_option(Configuration::OPTION_NAME,$this->config->sanitize($config),false);if(false===$saved&&$this->config->revision()===$expectedRevision){$this->redirectError('WordPress did not persist the Inventory profile patch.');}wp_safe_redirect(add_query_arg(array('page'=>self::SLUG,'applied'=>'1','profile'=>$profileId),admin_url('options-general.php')));exit;
    }

    private function redirectError(string $message): void {wp_safe_redirect(add_query_arg(array('page'=>self::SLUG,'error'=>$message),admin_url('options-general.php')));exit;}
}
