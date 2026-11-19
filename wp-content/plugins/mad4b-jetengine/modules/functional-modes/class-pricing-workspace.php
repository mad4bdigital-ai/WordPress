<?php
/**
 * MAD4B Pricing Workspace 1.0
 *
 * A single, compact admin navigation for existing pricing engines.
 * Tabs link to their owners' native screens. No form re-parenting,
 * proxy saves, iframe, or alternate price calculation.
 */
defined('ABSPATH') || exit;
final class MAD4B_Pricing_Workspace_V1 {
    public const SLUG='mad4b-pricing-workspace';
    public static function permitted():bool {
        return current_user_can('commerce_manage_pricing')
            && (current_user_can('manage_woocommerce') || (current_user_can('mad4b_manage_company_operations') && current_user_can('mad4b_work_company_tasks')));
    }
    public static function boot():void {
        if(!is_admin())return;
        add_action('admin_menu',[__CLASS__,'menu'],60);
        add_action('admin_notices',[__CLASS__,'shared_tabs'],2);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],12);
        add_action('admin_bar_menu',[__CLASS__,'admin_bar'],79);
    }
    public static function menu():void {
        if(!self::permitted())return;
        add_menu_page('Pricing Workspace','Pricing Workspace',
            'commerce_manage_pricing',self::SLUG,[__CLASS__,'page'],
            'dashicons-chart-area',55);
        add_submenu_page(self::SLUG,'Pricing Workspace · Overview','Overview',
            'commerce_manage_pricing',self::SLUG,[__CLASS__,'page']);
    }
    public static function native_rate_generation():bool {
        return class_exists('Shared_Product_Rate_Generation')
            && function_exists('mad4b_rate_authoring_enabled')
            && mad4b_rate_authoring_enabled();
    }
    private static function registered(string $slug):bool {
        global $menu,$submenu;
        foreach((array)$menu as $row)
            if(($row[2]??null)===$slug && current_user_can((string)$row[1]))
                return true;
        foreach((array)$submenu as $items)foreach((array)$items as $row)
            if(($row[2]??null)===$slug && current_user_can((string)$row[1]))
                return true;
        return false;
    }
    public static function tabs():array {
        $items=[['id'=>'overview','label'=>'Overview','icon'=>'dashicons-dashboard',
            'href'=>admin_url('admin.php?page='.self::SLUG)]];
        if(self::native_rate_generation())
            $items[]=['id'=>'generator','label'=>'Rate Generator','icon'=>'dashicons-editor-table',
                'href'=>admin_url('admin.php?page=wc-settings&tab=rate_generation')];
        if(self::registered('etg-dynamic-pricing-controls'))
            $items[]=['id'=>'controls','label'=>'Pricing Controls','icon'=>'dashicons-admin-settings',
                'href'=>admin_url('admin.php?page=dynamic-pricing-controls')];
        if(self::registered('mad4b-pricing-lab'))
            $items[]=['id'=>'lab','label'=>'Pricing Lab','icon'=>'dashicons-chart-line',
                'href'=>admin_url('admin.php?page=mad4b-pricing-lab')];
        if(self::registered('mad4b-policy-bindings'))
            $items[]=['id'=>'policies','label'=>'Policy Bindings','icon'=>'dashicons-networking',
                'href'=>admin_url('admin.php?page=mad4b-policy-bindings')];
        return $items;
    }
    public static function extras():array {
        $pages=[];
        foreach([
            'wayfa-pricing-products'=>'Products & Readiness',
            'mad4b-pricing-workbench'=>'Pricing Workbench',
            'booking-commercial-tier-presets'=>'Commercial Tier Presets',
            'booking-experience-market-pricing'=>'Market Pricing',
            'mad4b-age-occupancy'=>'Age & Occupancy',
            'mad4b-pricing-currencies'=>'Currencies & Markets',
        ] as $slug=>$label)
            if(self::registered($slug))
                $pages[]=['label'=>$label,
                    'href'=>admin_url('admin.php?page='.$slug)];
        if(function_exists('shared_product_financial_override')
          &&self::registered('booking-experience-pricing-presets'))
            $pages[]=['label'=>'Native Preset Library',
                'href'=>admin_url('admin.php?page=booking-experience-pricing-presets')];
        $type=get_post_type_object('tour-rates');
        if($type&&current_user_can($type->cap->edit_posts))
            $pages[]=['label'=>'Tour Rate Records',
                'href'=>admin_url('edit.php?post_type=tour-rates')];
        return $pages;
    }
    public static function active():?string {
        $slug=sanitize_key((string)($_GET['page']??''));
        if($slug===self::SLUG)return 'overview';
        if(in_array($slug,['dynamic-pricing-controls',
            'etg-dynamic-pricing-controls'],true))return 'controls';
        if($slug==='mad4b-pricing-lab')return 'lab';
        if($slug==='mad4b-policy-bindings')return 'policies';
        // Configuration lives under More tools; no extra primary tab.
        if($slug==='mad4b-pricing-currencies')return 'currencies';
        if($slug==='mad4b-rate-generation'||
            ($slug==='wc-settings'
                &&sanitize_key((string)($_GET['tab']??''))==='rate_generation'))
            return 'generator';
        return null;
    }
    public static function is_pricing_context():bool {
        if(self::active()!==null)return true;
        $slug=sanitize_key((string)($_GET['page']??''));
        return in_array($slug,[
            'wayfa-pricing-products','mad4b-pricing-workbench',
            'booking-commercial-tier-presets','booking-experience-market-pricing',
            'mad4b-age-occupancy','booking-experience-pricing-presets'
        ],true);
    }
    public static function assets():void {
        if(!self::permitted()||!self::is_pricing_context())return;
        $dir=__DIR__.'/assets/';
        wp_enqueue_style('mad4b-unified-pricing-workspace',
            plugins_url('assets/pricing-workspace.css',__FILE__),[],
            (string)filemtime($dir.'pricing-workspace.css'));
        wp_enqueue_script('mad4b-unified-pricing-workspace',
            plugins_url('assets/pricing-workspace.js',__FILE__),[],
            (string)filemtime($dir.'pricing-workspace.js'),true);
        if(sanitize_key((string)($_GET['page']??''))==='mad4b-pricing-currencies')
            wp_enqueue_script('mad4b-currency-configuration',
                plugins_url('assets/currency-configuration.js',__FILE__),[],
                (string)filemtime($dir.'currency-configuration.js'),true);
    }
    public static function render_tabs():void {
        $active=self::active();
        $tabs=self::tabs();
        $more=self::extras();
        echo '<section class="mad4b-pricing-unified-shell" aria-label="MAD4B Pricing Workspace">';
        echo '<div class="mad4b-pricing-unified-header"><div><strong>Pricing Workspace</strong>';
        echo '<small>One workspace · existing native pricing engines</small></div>';
        if($active!=='overview')
            echo '<a class="mad4b-pricing-unified-home" href="'.esc_url(
                admin_url('admin.php?page='.self::SLUG)).'">Workspace overview</a>';
        echo '</div>';
        echo '<nav class="mad4b-pricing-unified-nav" aria-label="Pricing tools"><div class="mad4b-pricing-unified-tabstrip">';
        foreach($tabs as $tab){
            $on=$tab['id']===$active;
            echo '<a class="mad4b-pricing-unified-tab'.($on?' is-current':'').'"'.
                ($on?' aria-current="page"':'').
                ' href="'.esc_url($tab['href']).'">'.esc_html($tab['label']).'</a>';
        }
        echo '</div>';
        if($more){
            echo '<details class="mad4b-pricing-unified-more"><summary>More tools</summary><div>';
            foreach($more as $extra)
                echo '<a href="'.esc_url($extra['href']).'">'.
                    esc_html($extra['label']).'</a>';
            echo '</div></details>';
        }
        echo '</nav></section>';
    }
    public static function shared_tabs():void {
        if(!self::permitted()||!self::is_pricing_context())return;
        self::render_tabs();
    }
    public static function page():void {
        if(!self::permitted())wp_die('Pricing Workspace permission required',403);
        $url=static fn($slug)=>admin_url('admin.php?page='.$slug);
        echo '<div class="wrap mad4b-pricing-workspace-page"><h1>Pricing Workspace</h1>';
        echo '<p class="description">Use one set of tabs to manage Rate sources, dynamic controls, strategy drafts and policy inheritance. Each tool keeps its original permissions, saves and pricing engine.</p>';
        echo '<div class="mad4b-pricing-unified-cards">';
        $cards=[
            ['Rate Generator','Create unpublished Tour, accommodation and transport Rate drafts, or inspect a native quote.',self::native_rate_generation()
                ?admin_url('admin.php?page=wc-settings&tab=rate_generation'):null],
            ['Reviewed Rate Sources','Inspect editorially approved Property/Unit intake and its unapproved source prices before authoring any Rate.',
                admin_url('admin.php?page=mad4b-property-intake&tab=review')],
            ['Currencies & Markets','Manage the JetEngine currency catalog, WooCommerce-recognized currencies, WCML display scope and optional new-Rate currency allowlist.',self::registered('mad4b-pricing-currencies')
                ?$url('mad4b-pricing-currencies'):null],
            ['Company Country Scope','Review operating countries, Combined Tour publication holds and retained Tour Rates; changes use the Service Activation owner.',
                class_exists('MAD4B_Company_Country_Operating_Scope_V1')
                ?MAD4B_Company_Country_Operating_Scope_V1::link():null],
            ['Pricing Controls','Turn on the engine and configure JetEngine targeting dimensions.',self::registered('etg-dynamic-pricing-controls')
                ?$url('dynamic-pricing-controls'):null],
            ['Pricing Lab','Create versioned strategies; preview effects on eligible Tours and Rates.',self::registered('mad4b-pricing-lab')
                ?$url('mad4b-pricing-lab'):null],
            ['Policy Bindings','Bind draft policies to Term, Tour, Rate or Global scope; set inheritance priority.',self::registered('mad4b-policy-bindings')
                ?$url('mad4b-policy-bindings'):null],
        ];
        foreach($cards as [$title,$desc,$link]){
            echo '<article class="mad4b-pricing-unified-card"><h2>'.
                esc_html($title).'</h2><p>'.esc_html($desc).'</p>';
            if($link)echo '<a class="button" href="'.esc_url($link).'">Open '.esc_html($title).'</a>';
            else echo '<span class="mad4b-pricing-unified-unavailable">Not registered or unavailable</span>';
            echo '</article>';
        }
        echo '</div>';
        if(class_exists('MAD4B_Property_Intake_Admin_V1')&&function_exists('mad4b_intake_rate_handoff_v1')){
            $intakes=MAD4B_Property_Intake_Admin_V1::records();
            if(is_array($intakes)){
                $pending=[];
                foreach($intakes as $id=>$review){
                    if(($review['state']??'')!=='CONTENT_REVIEW_APPROVED')continue;
                    $quote=mad4b_intake_rate_handoff_v1($review);
                    if(is_wp_error($quote))continue;
                    $pending[]=[$id,$review,$quote];
                }
                if($pending){
                    echo '<section class="mad4b-pricing-source-handoff"><h2>Reviewed Property &amp; Unit Sources</h2>';
                    echo '<p>Content approval is already recorded. All listed amounts are unapproved price observations, not commercial Rates.</p>';
                    foreach($pending as [$id,$review,$quote]){
                        echo '<article class="mad4b-pricing-source-card"><div><strong>'.esc_html($review['plan']['proposed_title']).'</strong>';
                        echo '<small>'.absint($quote['count']).' price observations · '.esc_html($quote['status']).'</small>';
                        if($quote['blockers'])echo '<p>Remaining: '.esc_html(implode(' · ',$quote['blockers'])).'</p>';
                        echo '</div><a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-hub-master-review&intake_id='.$id)).'">View source review</a></article>';
                    }
                    echo '</section>';
                }
            }
        }
        if(function_exists('mad4b_property_rate_source_audit_v1')){
            $audit=mad4b_property_rate_source_audit_v1(200);
            if(!is_wp_error($audit)){
                $counts=$audit['counts'];
                echo '<section class="mad4b-pricing-source-handoff"><h2>Saved Property Rate Readiness</h2>';
                echo '<p>This is a diagnostic of saved Rate records, not proof of supplier approval or availability.</p>';
                echo '<div class="mad4b-pricing-rate-metrics">';
                foreach([['Total Rates',$counts['total']],['Draft Rates',$counts['draft']],['QA-only',$counts['qa_only']],['Unit not published',$counts['unit_not_published']],['Missing stop-sale value',$counts['missing_explicit_stop_sale']]] as $metric)
                    echo '<div><strong>'.absint($metric[1]).'</strong><span>'.esc_html($metric[0]).'</span></div>';
                echo '</div><details class="mad4b-pricing-source-details"><summary>Inspect saved Rate source blockers</summary>';
                echo '<div class="mad4b-pricing-source-list">';
                foreach($audit['rows'] as $rate){
                    echo '<article><a href="'.esc_url(admin_url('post.php?post='.$rate['rate_id'].'&action=edit')).'">'.esc_html($rate['title']).'</a>';
                    echo '<span>Rate #'.absint($rate['rate_id']).' · '.esc_html($rate['status']).' · '.esc_html(implode(', ',$rate['blockers'])).'</span></article>';
                }
                echo '</div></details></section>';
            }
        }
        if(function_exists('mad4b_tour_stay_commercial_readiness_v1')){
            echo '<section class="mad4b-pricing-source-handoff mad4b-native-stay-readiness"><h2>Tour → Property Stay Pricing Readiness</h2>';
            echo '<p>Audit the original BookingExperience Tour Rate stay plans; a legacy Property reference is not evidence of a sellable stay or an included price.</p>';
            $run=isset($_GET['stay_audit'])&&$_GET['stay_audit']==='1';
            if(!$run){
                $url=add_query_arg(['page'=>'mad4b-pricing-workspace','stay_audit'=>1],admin_url('admin.php'));
                echo '<a class="button button-secondary" href="'.esc_url($url).'">Run native stay policy audit</a>';
            }else{
                $data=mad4b_tour_stay_commercial_readiness_v1(250);
                if(is_wp_error($data)){
                    echo '<p class="notice notice-error inline">'.esc_html($data->get_error_code()).'</p>';
                }else{
                    $sum=$data['summary'];
                    echo '<div class="mad4b-pricing-rate-metrics">';
                    foreach([['Published Tour Rates',$sum['published_tour_rates']],
                        ['Marked with stays',$sum['marked_with_accommodation']],
                        ['No eligible property',$sum['no_eligible_properties']],
                        ['Invalid Property references',$sum['invalid_legacy_property_reference']],
                        ['Inquiry policy',$sum['inquiry_policy']],
                        ['Matched Property Rate candidates',$sum['candidate_published_property_rates']]] as $metric)
                        echo '<div><strong>'.absint($metric[1]).'</strong><span>'.esc_html($metric[0]).'</span></div>';
                    echo '</div><details class="mad4b-pricing-source-details"><summary>Inspect native Tour Rate blockers</summary>';
                    echo '<div class="mad4b-pricing-source-list">';
                    foreach($data['rows'] as $record){
                        $link=admin_url('post.php?post='.absint($record['tour_rate_id']).'&action=edit');
                        echo '<article><a href="'.esc_url($link).'">Tour Rate #'.absint($record['tour_rate_id']).'</a>';
                        echo '<span>Tour #'.absint($record['tour_id']).' · '.esc_html($record['native_mode']).' · '.
                             esc_html(implode(', ',$record['blockers'])).'</span></article>';
                    }
                    echo '</div></details>';
                }
                $clear=admin_url('admin.php?page=mad4b-pricing-workspace');
                echo '<p><a href="'.esc_url($clear).'">Close audit</a></p>';
            }
            echo '</section>';
        }
        echo '<p class="mad4b-pricing-unified-boundary"><strong>Scope:</strong> this Workspace never approves source prices or activates checkout. Use the native Rate Generator only after supplier evidence, Unit identity, fee coverage and inventory are verified.</p>';
        echo '</div>';
    }
    public static function admin_bar($bar):void {
        if(!self::permitted())return;
        $bar->add_node([
            'id'=>'mad4b-pricing-workspace',
            'title'=>'Pricing Workspace',
            'href'=>admin_url('admin.php?page='.self::SLUG),
            'meta'=>['title'=>'One workspace for company pricing tools'],
        ]);
        // Keep the toolbar short. Navigation belongs in the five main tabs.
    }
}
