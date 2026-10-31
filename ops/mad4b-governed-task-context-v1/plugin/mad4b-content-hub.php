<?php
/**
 * Plugin Name: MAD4B Shared Content Hub (Central Controller)
 * Description: Central-only candidate discovery and cost-safe tenant pricing simulation.
 * Version: 0.1.0-dev
 * Author: MAD4B
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/includes/class-mad4b-hub-source-reader.php';
require_once __DIR__.'/includes/class-mad4b-hub-research-feed.php';
require_once __DIR__.'/includes/class-mad4b-hub-canonical-store.php';
require_once __DIR__.'/includes/class-mad4b-hub-usage-rights.php';
require_once __DIR__.'/includes/class-mad4b-hub-control-ingestion.php';
require_once __DIR__.'/includes/class-mad4b-hub-workflow-continuity.php';
require_once __DIR__.'/includes/class-mad4b-hub-workflow-operations.php';
require_once __DIR__.'/includes/class-mad4b-hub-workflow-origin.php';
require_once __DIR__.'/includes/class-mad4b-hub-unlinked-task-context.php';
require_once __DIR__.'/includes/class-mad4b-hub-semantic-legacy-cutover.php';
require_once __DIR__.'/includes/class-mad4b-hub-governed-single-flight.php';
require_once __DIR__.'/includes/class-mad4b-hub-bulk-jobs-queue.php';
require_once __DIR__.'/includes/class-mad4b-hub-provider-receipt-bridge.php';
require_once __DIR__.'/includes/class-mad4b-hub-queue-lifecycle.php';
require_once __DIR__.'/includes/class-mad4b-hub-adaptive-concurrency.php';
require_once __DIR__.'/includes/class-mad4b-hub-bulk-vps-runner.php';
require_once __DIR__.'/includes/class-mad4b-hub-dynamic-runtime.php';
require_once __DIR__.'/includes/class-mad4b-hub-six-stage-adapters.php';
require_once __DIR__.'/includes/class-mad4b-hub-ai-guarded-preflight.php';
require_once __DIR__.'/includes/class-mad4b-hub-ai-queue-pipeline.php';
require_once __DIR__.'/includes/class-mad4b-hub-chatgpt-work.php';
require_once __DIR__.'/includes/class-mad4b-hub-role-review.php';
require_once __DIR__.'/includes/class-mad4b-hub-review-language.php';
require_once __DIR__.'/includes/class-mad4b-hub-review-card-policy.php';
require_once __DIR__.'/includes/class-mad4b-hub-review-svg.php';
require_once __DIR__.'/includes/class-mad4b-hub-image-descriptive-metadata.php';
require_once __DIR__.'/includes/class-mad4b-hub-capture-parser.php';
require_once __DIR__.'/includes/class-mad4b-hub-capture-registry.php';
require_once __DIR__.'/includes/class-mad4b-hub-media-manifest.php';
require_once __DIR__.'/includes/class-mad4b-hub-publication-targets.php';
require_once __DIR__.'/includes/class-mad4b-hub-review-gallery.php';
require_once __DIR__.'/includes/class-mad4b-hub-review-frontend-preview.php';
require_once __DIR__.'/includes/class-mad4b-hub-review-ui.php';
require_once __DIR__.'/includes/class-mad4b-hub-executor-selection.php';
require_once __DIR__.'/includes/class-mad4b-hub-trigger-bus.php';
require_once __DIR__.'/includes/class-mad4b-hub-release-gate.php';
require_once __DIR__.'/includes/class-mad4b-hub-scraped-media-context.php';
require_once __DIR__.'/includes/class-mad4b-hub-execution-router.php';
require_once __DIR__.'/includes/class-mad4b-hub-cli-fallback.php';
final class MAD4B_Content_Hub {
    const CONTRACT='mad4b.content-hub-bootstrap.v1';
    private static function authorized_host() {
        if(getenv('MAD4B_CENTRAL_HUB_ENABLED')!=='1'
            || wp_get_environment_type()!=='staging'
            || !class_exists('MAD4B_SCP_Site_Profile'))return false;
        $p=MAD4B_SCP_Site_Profile::status();
        $path='/run/secrets/mad4b-hub-central-identity';
        if(!is_readable($path)||is_link($path))return false;
        $host=json_decode((string)file_get_contents($path),true);
        if(!is_array($host)||($host['contract']??'')!=='mad4b.hub.central-host-identity.v1'
           ||($host['role']??'')!=='central-controller-preview'
           ||($host['environment']??'')!==wp_get_environment_type()
           ||($host['can_distribute_external_content']??true)!==false
           ||($host['can_authorize_sales']??true)!==false
           ||($host['site_uuid']??'')!==($p['site_uuid']??'')
           ||($host['canonical_origin']??'')!==rtrim(home_url(),'/'))return false;
        return !empty($p['authority_ready'])&&!empty($p['same_origin_clone_protection'])
            && !empty($p['deployment_binding_match']);
    }
    public static function boot(){
        // No central menu, REST surface, publisher or credentials on customer sites.
        if(!self::authorized_host())return;
        MAD4B_Hub_Control_Ingestion::boot();
        MAD4B_Hub_Workflow_Continuity::boot();
        MAD4B_Hub_Workflow_Operations::boot();
        MAD4B_Hub_Workflow_Origin::boot();
        MAD4B_Hub_Bulk_Jobs_Queue::boot();
        MAD4B_Hub_Provider_Receipt_Bridge::boot();
        MAD4B_Hub_Queue_Lifecycle::boot();
        MAD4B_Hub_Adaptive_Concurrency::boot();
        MAD4B_Hub_Bulk_VPS_Runner::boot();
        MAD4B_Hub_Dynamic_Runtime_Resolver::boot();
        MAD4B_Hub_Six_Stage_Adapters::boot();
        MAD4B_Hub_AI_Guarded_Preflight::boot();
        MAD4B_Hub_AI_Queue_Pipeline::boot();
        MAD4B_Hub_ChatGPT_Work::boot();
        MAD4B_Hub_Capture_Registry::boot();
        MAD4B_Hub_Media_Manifest::boot();
        MAD4B_Hub_Publication_Targets::boot();
        MAD4B_Hub_Role_Review::boot();
        MAD4B_Hub_Review_Language::boot();
        MAD4B_Hub_Review_SVG::boot();
        MAD4B_Hub_Review_UI::boot();
        MAD4B_Hub_Executor_Selection::boot();
        MAD4B_Hub_Trigger_Bus::boot();
        MAD4B_Hub_Release_Gate::boot();
        MAD4B_Hub_Scraped_Media_Context::boot();
        MAD4B_Hub_Execution_Router::boot();
        MAD4B_Hub_CLI_Fallback::boot();
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('rest_api_init',[__CLASS__,'routes']);
    }
    public static function permission(){
        return self::authorized_host() && current_user_can('manage_options') && current_user_can('activate_plugins');
    }
    public static function menu(){
        add_menu_page('MAD4B Content Hub','Content Hub','manage_options',
            'mad4b-shared-content-hub',[__CLASS__,'page'],'dashicons-database',35);
        add_submenu_page('mad4b-shared-content-hub','Research Feed','Research → Hub',
            'manage_options','mad4b-hub-research-feed',
            ['MAD4B_Hub_Research_Feed','page']);
    }
    public static function routes(){
        register_rest_route('mad4b-hub/v1','/control-research/run',[
            'methods'=>'POST','callback'=>['MAD4B_Hub_Control_Ingestion','rest_run'],
            'permission_callback'=>['MAD4B_Hub_Control_Ingestion','allowed']]);
        register_rest_route('mad4b-hub/v1','/control-research/status',[
            'methods'=>'GET','callback'=>['MAD4B_Hub_Control_Ingestion','rest_status'],
            'permission_callback'=>[__CLASS__,'permission']]);
        register_rest_route('mad4b-hub/v1','/research-feed/run',[
            'methods'=>'POST','callback'=>['MAD4B_Hub_Research_Feed','rest_run'],
            'permission_callback'=>[__CLASS__,'permission']]);
        register_rest_route('mad4b-hub/v1','/research-feed/inbox',[
            'methods'=>'GET','callback'=>['MAD4B_Hub_Research_Feed','rest_inbox'],
            'permission_callback'=>[__CLASS__,'permission']]);
        register_rest_route('mad4b-hub/v1','/research-feed/candidate',[
            'methods'=>'GET','callback'=>['MAD4B_Hub_Research_Feed','rest_preview'],
            'permission_callback'=>[__CLASS__,'permission']]);
        register_rest_route('mad4b-hub/v1','/candidate',[
            'methods'=>'GET','callback'=>[__CLASS__,'candidate_rest'],
            'permission_callback'=>[__CLASS__,'permission']]);
        register_rest_route('mad4b-hub/v1','/status',[
            'methods'=>'GET','callback'=>[__CLASS__,'status_rest'],
            'permission_callback'=>[__CLASS__,'permission']]);
        register_rest_route('mad4b-hub/v1','/content-candidate',[
            'methods'=>'GET','callback'=>[__CLASS__,'content_candidate_rest'],
            'permission_callback'=>[__CLASS__,'permission']]);
    }
    public static function status_rest(){
        return rest_ensure_response([
            'contract'=>self::CONTRACT,'controller_enabled'=>self::authorized_host(),
            'content_source'=>'local_wordpress_cpt_snapshot',
            'canonical_research_store'=>'hub_database_staging_candidate',
            'control_mcp_ingestion_ability'=>'mad4b/control-research-to-hub',
            'canonical_research_store_publication'=>false,
            'site_profile'=>(class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::status()['site_uuid']:''),
            'source_types'=>['tours-and-activities','properties','transportations','tour-rates'],
            'tenant_sync_connected'=>false,'shared_price_publications'=>0,
            'research_feed_enabled'=>true,
            'research_inbox_mode'=>'staging_draft_review_only',
            'external_distribution_enabled'=>false,
            'commerce_price_replacement_enabled'=>false,
            'mutation_performed'=>false]);
    }
    public static function content_candidate_rest($request){
        $candidate=MAD4B_Hub_Source_Reader::content_candidate(absint($request->get_param('post_id')));
        if(is_wp_error($candidate))return $candidate;
        $r=rest_ensure_response($candidate);
        $r->header('Cache-Control','private, no-store');
        return $r;
    }
    public static function candidate_rest($request){
        $id=absint($request->get_param('rate_id'));
        $occ=sanitize_key((string)($request->get_param('occupancy')?:'double'));
        $candidate=MAD4B_Hub_Source_Reader::candidate($id,$occ);
        if(is_wp_error($candidate))return $candidate;
        $preview=['status'=>'not_requested'];
        $mode=sanitize_key((string)$request->get_param('model'));
        if($mode!==''){
            if(!class_exists('MAD4B_Tenant_Price_Engine'))return new WP_Error('mad4b_hub_tenant_engine_missing');
            $bps=absint($request->get_param('basis_points'));
            $p=MAD4B_SCP_Site_Profile::status();
            // Ephemeral, owner-visible ONLY. Never published, written or used by checkout.
            $offer=[
                'global_offer_id'=>$candidate['global_offer_id'],
                'source_version'=>$candidate['source_version'],
                'share_state'=>'approved',
                'allowed_tenants'=>[(string)$p['site_uuid']],
                'stop_sale'=>$candidate['source']['stop_sale'],
                'price_basis'=>'reference_retail',
                'amount_minor'=>$candidate['source']['amount_minor'],
                'currency'=>$candidate['source']['currency'],
            ];
            $use_saved=(string)$request->get_param('use_saved_policy')==='1';
            $policy=$use_saved
                ? (class_exists('MAD4B_Tenant_Sync')?MAD4B_Tenant_Sync::policy('tour'):null)
                : [
                    'enabled'=>true,'model'=>$mode,'basis_points'=>$bps,
                    'display_currency'=>$candidate['source']['currency'],
                    'fixed_fee_minor'=>0,'minimum_profit_minor'=>0,
                    'rounding_increment_minor'=>100,
                ];
            $preview=$policy===null
                ? ['status'=>'requires_review','reason'=>'tenant_policy_not_configured',
                    'checkout_authorized'=>false]
                : MAD4B_Tenant_Price_Engine::preview($offer,$policy,(string)$p['site_uuid']);
        }
        $response=rest_ensure_response([
            'contract'=>self::CONTRACT.'.candidate',
            'source_candidate'=>$candidate,'tenant_simulation'=>$preview,
            'simulation_approval_is_ephemeral'=>true,
            'source_share_state'=>'candidate_only',
            'shared_offer_published'=>false,
            'tenant_policy_saved'=>isset($use_saved) && $use_saved
                && isset($policy) && is_array($policy),
            'live_commerce_affected'=>false,
        ]);
        $response->header('Cache-Control','no-store, private');
        return $response;
    }
    public static function page(){
        if(!self::permission())wp_die('Central operator required.',403);
        $url=rest_url('mad4b-hub/v1/candidate');
        $nonce=wp_create_nonce('wp_rest');
        echo '<div class="wrap"><h1>MAD4B Shared Content Hub — Bootstrap</h1>';
        echo '<p>Central operator only. Explore published rate candidates and simulate a tenant markup. Nothing is published, synchronized or used by WooCommerce checkout.</p>';
        echo '<p>Source classification: existing tour-rate fields are <strong>reference retail</strong> until supplier cost is independently certified.</p>';
        echo '<label>Rate ID <input type="number" id="mad4b-hub-rate" min="1" value="61140"></label> ';
        echo '<label>Occupancy <select id="mad4b-hub-occ"><option value="double">Double</option><option value="single">Single</option><option value="triple">Triple</option></select></label> ';
        echo '<label>Markup (%) <input type="number" id="mad4b-hub-margin" min="0" max="300" step=".01" value="20"></label> ';
        echo '<label><input type="checkbox" id="mad4b-hub-use-saved"> Use saved tenant pricing policy</label> ';
        echo '<button type="button" class="button button-primary" id="mad4b-hub-view">Preview candidate</button>';
        echo '<pre style="max-width:940px;white-space:pre-wrap" id="mad4b-hub-result"></pre>';
        $js='(()=>{const b=document.getElementById("mad4b-hub-view"),o=document.getElementById("mad4b-hub-result");';
        $js.='const base='.wp_json_encode($url).',nonce='.wp_json_encode($nonce).';';
        $js.='b.onclick=async()=>{b.disabled=true;try{const id=document.getElementById("mad4b-hub-rate").value,occ=document.getElementById("mad4b-hub-occ").value,m=Number(document.getElementById("mad4b-hub-margin").value);';
        $js.='if(!Number.isFinite(m)||m<0||m>300)throw Error("Invalid markup");';
        $js.='const q=new URLSearchParams({rate_id:id,occupancy:occ,model:"markup",basis_points:String(Math.round(m*100)),use_saved_policy:document.getElementById("mad4b-hub-use-saved").checked?"1":"0"});';
        $js.='const r=await fetch(base+"?"+q,{credentials:"same-origin",cache:"no-store",headers:{"X-WP-Nonce":nonce}});o.textContent=JSON.stringify({http:r.status,data:await r.json()},null,2);';
        $js.='}catch(e){o.textContent=String(e)}finally{b.disabled=false}}})();';
        echo '<script>'.$js.'</script></div>';
    }
}
add_action('plugins_loaded',['MAD4B_Content_Hub','boot'],90);
