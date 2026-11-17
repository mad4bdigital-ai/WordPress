<?php
/**
 * MAD4B Pricing Lab v1 — group targeting, source-backed dry-run, native
 * Booking Experience draft assignments, revision history and rollback.
 *
 * Important: "Stage" produces disabled, unapproved native rows, NOT live
 * checkout policies. Existing prices and site pricing settings remain intact.
 */
defined('ABSPATH') || exit;
final class MAD4B_JetEngine_Pricing_Lab_V1 {
    public const ACTION='mad4b_jetengine_pricing_lab_v1';
    private const OPTION='mad4b_jetengine_pricing_lab_presets_v1';
    private const HISTORY='mad4b_jetengine_pricing_lab_receipts_v1';
    private const MAX_PRESETS=30;
    private const MAX_STAGED_RATES=60;
    private const MAX_TOURS=200;
    private const PRESET_NATIVE='booking_experience_pricing_preset_rows_v1';
    private static function permitted(): bool {
        // MAD4B Master Admins can manage company pricing without broad
        // WordPress settings access. Do not require manage_options.
        return current_user_can('commerce_manage_pricing')
            && current_user_can('manage_woocommerce');
    }
    public static function boot(): void {
        if (!is_admin()) return;
        add_action('admin_menu',[__CLASS__,'menu'],99);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
        add_action('wp_ajax_'.self::ACTION,[__CLASS__,'ajax']);
    }
    public static function menu(): void {
        if(!self::permitted())return;
        global $menu;
        // Prefer an existing company pricing workspace with a commercial
        // capability, and fall back to WooCommerce, which exists at both
        // companies. Avoid the MAD4B JetEngine parent: it requires the
        // unrelated manage_options capability and hides this menu.
        $parent='mad4b-pricing-workspace';
        if(!MAD4B_Pricing_Workspace_V1::permitted())
            return;
        add_submenu_page($parent,'MAD4B Pricing Lab','Pricing Lab',
            'commerce_manage_pricing','mad4b-pricing-lab',[__CLASS__,'page']);
    }
    private static function registered_menu_page(string $slug): bool {
        global $menu,$submenu;
        foreach((array)$menu as $item)
            if(($item[2]??'')===$slug && current_user_can((string)$item[1]))
                return true;
        foreach((array)$submenu as $subitems)foreach((array)$subitems as $item)
            if(($item[2]??'')===$slug && current_user_can((string)$item[1]))
                return true;
        return false;
    }
    private static function overview_route(): ?array {
        return ['slug'=>'mad4b-pricing-workspace',
            'label'=>'Pricing Workspace',
            'url'=>admin_url('admin.php?page=mad4b-pricing-workspace')];
    }
    private static function library_route(): ?array {
        // Wayfatours redirects the Native Preset Library to a missing-provider
        // notice when financial inheritance is absent. Never publish a
        // misleading shortcut to that URL.
        if(function_exists('shared_product_financial_override')
            && self::registered_menu_page('booking-experience-pricing-presets')) {
            return ['slug'=>'booking-experience-pricing-presets',
                'label'=>'Native Preset Library',
                'url'=>admin_url('admin.php?page=booking-experience-pricing-presets')];
        }
        if(self::registered_menu_page('booking-commercial-tier-presets')) {
            return ['slug'=>'booking-commercial-tier-presets',
                'label'=>'Commercial Tier Presets',
                'url'=>admin_url('admin.php?page=booking-commercial-tier-presets')];
        }
        return null;
    }
    private static function workspace_links(): array {
        $routes=[];
        $overview=self::overview_route();
        if($overview)$routes[]=$overview;
        $routes[]=['slug'=>'mad4b-pricing-lab',
            'label'=>'Pricing Lab · Group strategy',
            'url'=>admin_url('admin.php?page=mad4b-pricing-lab')];
        if(self::registered_menu_page('mad4b-policy-bindings'))
            $routes[]=['slug'=>'mad4b-policy-bindings',
                'label'=>'Policy Bindings · inheritance',
                'url'=>admin_url('admin.php?page=mad4b-policy-bindings')];
        // JetEngine options pages have a different dynamic alias between the
        // companies. The company Settings capability is required even when
        // an alias is not present in wp-admin's main submenu registry.
        if(self::registered_menu_page('etg-dynamic-pricing-controls'))
            $routes[]=['slug'=>'dynamic-pricing-controls',
                'label'=>'Dynamic Pricing Controls',
                'url'=>admin_url('admin.php?page=dynamic-pricing-controls')];
        $type=get_post_type_object('tour-rates');
        if($type && current_user_can($type->cap->edit_posts))
            $routes[]=['slug'=>'edit.php?post_type=tour-rates',
                'label'=>'Tour Rate Records',
                'url'=>admin_url('edit.php?post_type=tour-rates')];
        $library=self::library_route();
        if($library)$routes[]=$library;
        foreach([
            'booking-experience-market-pricing'=>'Market Pricing',
            'mad4b-age-occupancy'=>'Age & Occupancy'
        ] as $slug=>$label)
            if(self::registered_menu_page($slug))
                $routes[]=['slug'=>$slug,'label'=>$label,
                    'url'=>admin_url('admin.php?page='.$slug)];
        return $routes;
    }
    public static function admin_bar($wp_admin_bar): void {
        if(!self::permitted()||!is_admin())return;
        $routes=self::workspace_links();
        $root='mad4b-pricing-workspace';
        $wp_admin_bar->add_node([
            'id'=>$root,
            'title'=>'Pricing Workspace',
            'href'=>admin_url('admin.php?page=mad4b-pricing-lab'),
            'meta'=>['title'=>'Pricing Workspace · switch between pricing tools'],
        ]);
        $slug=sanitize_key((string)($_GET['page']??''));
        foreach($routes as $entry) {
            $id='mad4b-pricing-nav-'.sanitize_html_class($entry['slug']);
            $active=$slug===$entry['slug']
                || ($slug==='etg-dynamic-pricing-controls'
                    && $entry['slug']==='dynamic-pricing-controls');
            $wp_admin_bar->add_node([
                'parent'=>$root,'id'=>$id,
                'title'=>$entry['label'],
                'href'=>$entry['url'],
                'meta'=>[
                    'title'=>$entry['label'],
                    'class'=>$active?'mad4b-pricing-workspace-current':'',
                ],
            ]);
        }
    }
    public static function entry_link(): void {
        if(!self::permitted()) return;
        $current=sanitize_key((string)($_GET['page']??''));
        if(!in_array($current,['dynamic-pricing-controls','etg-dynamic-pricing-controls'],true))return;
        echo '<div class="notice notice-info" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap"><p><strong>Pricing workspace:</strong> Native JetEngine controls and group-based Pricing Lab share the same source pricing engines.</p><p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=mad4b-pricing-lab')).'">Open Pricing Lab</a></p></div>';
    }
    public static function assets(): void {
        if(!self::permitted()
            || sanitize_key((string)($_GET['page']??''))!=='mad4b-pricing-lab') return;
        $dir=__DIR__.'/assets/';
        $url=plugins_url('mad4b-jetengine/modules/functional-modes/assets/');
        wp_enqueue_style('mad4b-pricing-lab',$url.'pricing-lab.css',[],
            (string)filemtime($dir.'pricing-lab.css'));
        if(current_user_can('manage_options')){
            wp_enqueue_style('mad4b-pricing-lab-quote-testing',$url.'pricing-lab-quote-testing.css',
                ['mad4b-pricing-lab'],(string)filemtime($dir.'pricing-lab-quote-testing.css'));
            wp_enqueue_script('mad4b-pricing-lab-quote-testing',$url.'pricing-lab-quote-testing.js',
                [],(string)filemtime($dir.'pricing-lab-quote-testing.js'),true);
        }
        wp_enqueue_script('mad4b-pricing-lab',$url.'pricing-lab.js',
            ['mad4b-pricing-context-help'],
            (string)filemtime($dir.'pricing-lab.js'),true);
        wp_add_inline_script('mad4b-pricing-lab','window.MAD4BPricingLab='.wp_json_encode([
            'endpoint'=>admin_url('admin-ajax.php'),'action'=>self::ACTION,
            'nonce'=>wp_create_nonce(self::ACTION),
            'native_presets_url'=>self::library_route()['url']??null,
            'native_presets_label'=>self::library_route()['label']??'',
            'overview_url'=>self::overview_route()['url']??null,
            'overview_label'=>self::overview_route()['label']??'',
            'pricing_url'=>self::registered_menu_page('etg-dynamic-pricing-controls')
                ?admin_url('admin.php?page=dynamic-pricing-controls'):null
        ]).';','before');
    }
    public static function page(): void {
        if(!self::permitted())wp_die('Forbidden');
        echo '<div class="wrap mad4b-pricing-lab-page"><h1>MAD4B Pricing Lab</h1>';
        echo '<p>Design a group, compare published prices, save a versioned Draft and stage disabled Booking Experience assignments for review. Nothing here directly alters Tour Rate prices or activates checkout rules.</p>';
        if(current_user_can('manage_options')){
            echo '<p><a class="button button-secondary" href="#mad4b-pricing-lab-quote-testing">Open Quote Testing · Tour / Accommodation</a></p>';
        }
        echo '<div id="mad4b-pricing-lab-root" aria-live="polite"><p>Loading Pricing Lab…</p></div>';
        // Diagnostics live in Pricing Lab, never outside the JetEngine options
        // Workspace or below its original Save button.
        if(current_user_can('manage_options')){
            echo '<section id="mad4b-pricing-lab-quote-testing" class="mad4b-lab-quotes" aria-labelledby="mad4b-lab-quotes-title">';
            echo '<header class="mad4b-lab-quotes-header"><div><p class="mad4b-lab-quotes-eyebrow">PRICING LAB · READ-ONLY</p>';
            echo '<h2 id="mad4b-lab-quotes-title">Quote Testing</h2>';
            echo '<p>Run the original server-side pricing engines against saved policies. These diagnostics never approve rates, write Orders or activate checkout.</p></div>';
            echo '<span class="mad4b-lab-quotes-label">Saved prices only</span></header>';
            echo '<div role="tablist" aria-label="Quote engine">';
            echo '<button type="button" class="mad4b-lab-quotes-tab" id="mad4b-lab-tour-tab" role="tab" aria-controls="mad4b-lab-tour-panel" aria-selected="true" tabindex="0" data-quote-kind="tour">Tour Quote</button>';
            echo '<button type="button" class="mad4b-lab-quotes-tab" id="mad4b-lab-stay-tab" role="tab" aria-controls="mad4b-lab-stay-panel" aria-selected="false" tabindex="-1" data-quote-kind="stay">Accommodation Quote</button></div>';
            echo '<div id="mad4b-lab-tour-panel" class="mad4b-lab-quotes-panel" role="tabpanel" aria-labelledby="mad4b-lab-tour-tab"></div>';
            echo '<div id="mad4b-lab-stay-panel" class="mad4b-lab-quotes-panel" role="tabpanel" aria-labelledby="mad4b-lab-stay-tab" hidden></div>';
            echo '</section>';
        }
        echo '</div>';
    }
    private static function presets(): array {
        $p=get_option(self::OPTION,[]);
        return is_array($p)?array_values(array_filter($p,'is_array')):[];
    }
    private static function receipts(): array {
        $h=get_option(self::HISTORY,[]);
        return is_array($h)?array_values(array_filter($h,'is_array')):[];
    }
    private static function template_list(): array {
        if(!function_exists('BookingExperience\pricing_preset_templates'))return [];
        $raw=BookingExperience\pricing_preset_templates();
        $out=[];
        foreach($raw as $id=>$row){
            if(!is_array($row))continue;
            $out[]=['id'=>(string)$id,'label'=>(string)($row['label']??$id),
                'strategy'=>(string)($row['strategy']??'unchanged'),
                'value'=>$row['value']??'', 'currency'=>(string)($row['currency']??''),
                'revision'=>(int)($row['revision']??1),
                'kind'=>(string)($row['kind']??'builtin')];
        }
        return $out;
    }
    public static function load(): array {
        $resolver='MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
        $languages=array_keys($resolver::languages());
        $language=MAD4B_JetEngine_Pricing_Dimensions_V1::language_policy();
        $current=[$language['default_language']];
        return [
            'presets'=>self::presets(),
            'history'=>array_slice(array_reverse(self::receipts()),0,40),
            'catalog'=>MAD4B_JetEngine_Strategy_Schema_Bridge_V1::catalog($current),
            'languages'=>$resolver::languages(),
            'pricing_language'=>$language,
            'currency_choices'=>MAD4B_Pricing_Currency_Configuration_V1::authoring_choices(),
            'currency_source'=>MAD4B_JetEngine_Currency_Glossary_V1::diagnostics(),
            'native_templates'=>self::template_list(),
            'native_preset_state'=>function_exists('BookingExperience\pricing_preset_settings')
                ? BookingExperience\pricing_preset_settings()
                : ['enabled'=>false,'composition'=>'single'],
            'native_assignment_count'=>function_exists('BookingExperience\pricing_preset_rows')
                ?count(BookingExperience\pricing_preset_rows()):0,
            'commercial_authority'=>'disabled_unapproved_native_assignments_only',
            'max_staged_rates'=>self::MAX_STAGED_RATES
        ];
    }
    private static function valid_config($raw) {
        if(!is_array($raw))return new WP_Error('lab_invalid_payload');
        $title=sanitize_text_field((string)($raw['title']??''));
        if(strlen($title)<3||strlen($title)>210)
            return new WP_Error('lab_title_invalid','A descriptive Preset name is required');
        $source='MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
        // New policies always inherit canonical WPML content by default.
        // Display-language and market dimensions are opt-in per target group.
        // Existing explicitly saved legacy records retain their own scopes.
        $scopeMode=$raw['language_scope']??'inherit_default';
        if(!is_string($scopeMode))
            return new WP_Error('lab_language_scope_invalid');
        if(!in_array($scopeMode,['inherit_default','target_groups','legacy'],true))
            return new WP_Error('lab_language_scope_invalid');
        $policy=MAD4B_JetEngine_Pricing_Dimensions_V1::language_policy();
        $authoring=$policy['default_language'];
        $known=$source::languages();
        $groups=[];
        $markets=[];
        if($scopeMode==='legacy'){
            $languages=$source::selected_languages((array)($raw['languages']??['en']));
            $validationLanguages=$languages;
            $authoring=$languages[0]??$authoring;
            $markets=$source::select_markets((array)($raw['markets']??[]));
            if(count($markets)>6)return new WP_Error('lab_markets_too_many');
        }else{
            $languages=array_keys($known);
            $validationLanguages=[$authoring];
            if($scopeMode==='target_groups'){
                $input=$raw['target_groups']??null;
                if(!is_array($input)||!count($input)||count($input)>6)
                    return new WP_Error('lab_target_groups_required',
                        'Define between one and six language/market groups');
                foreach($input as $group){
                    if(!is_array($group))
                        return new WP_Error('lab_group_invalid');
                    if(!isset($group['title'])||!is_string($group['title']))
                        return new WP_Error('lab_group_title_required');
                    $title=substr(sanitize_text_field($group['title']),0,90);
                    if(strlen($title)<2)
                        return new WP_Error('lab_group_title_required');
                    $display=$group['display_languages']??[];
                    $countries=$group['markets']??[];
                    if(!is_array($display)||!is_array($countries)
                        ||count($display)>count($known)||count($countries)>6)
                        return new WP_Error('lab_group_dimension_invalid');
                    $locales=[];
                    foreach($display as $code){
                        if(!is_string($code)||!isset($known[$code]))
                            return new WP_Error('lab_unknown_display_language');
                        $locales[$code]=$code;
                    }
                    foreach($countries as $country)
                        if(!is_string($country))
                            return new WP_Error('lab_market_group_invalid');
                    $countryIds=$source::select_markets($countries);
                    if(count($countryIds)!==count(array_unique($countries)))
                        return new WP_Error('lab_market_group_invalid');
                    if(!$locales&&!$countryIds)
                        return new WP_Error('lab_unrestricted_target_group',
                            'Choose display languages or markets, or use default inheritance');
                    $groups[]=[
                        'title'=>$title,
                        'display_languages'=>array_values($locales),
                        'markets'=>$countryIds,
                    ];
                }
            }
        }
        if(!$languages||count($languages)>8)return new WP_Error('lab_languages_invalid');
        $logic=in_array(($raw['logic']??''),['and','or'],true)?$raw['logic']:'and';
        $rules=$raw['rules']??[];
        if(!is_array($rules) || count($rules)<1)
            return new WP_Error('lab_conditions_required','Choose at least one group condition');
        $catalog=MAD4B_JetEngine_Strategy_Schema_Bridge_V1::catalog($validationLanguages);
        $validated=MAD4B_JetEngine_Strategy_Schema_Bridge_V1::rules($rules,$catalog);
        if(is_wp_error($validated))return $validated;
        $template=sanitize_key((string)($raw['template_id']??''));
        $templates=function_exists('BookingExperience\pricing_preset_templates')
            ?BookingExperience\pricing_preset_templates():[];
        if(!isset($templates[$template]))return new WP_Error('lab_template_unknown','Choose a native Booking Experience template');
        $t=$templates[$template];
        $strategy=(string)$t['strategy'];
        $value=$raw['value']??$t['value'];
        if($value==='')$value=$t['value'];
        $currency=strtoupper(trim((string)($raw['currency']??$t['currency'])));
        if($strategy==='unchanged'){$value='';$currency='';}
        elseif(!is_scalar($value)||!is_numeric($value)
            || !is_finite((float)$value)||abs((float)$value)>10000000)
            return new WP_Error('lab_value_invalid');
        if($value!==''){
            $value=round((float)$value,4);
            if(($strategy==='percent' && ($value<=0||$value>300))
                ||(in_array($strategy,['fixed','floor','cap'],true) && $value<=0))
                return new WP_Error('lab_value_out_of_bounds');
        }
        if(in_array($strategy,['unchanged','percent'],true))$currency='';
        elseif($value!=='' && (!preg_match('/^[A-Z]{3}$/D',$currency)
            ||!array_key_exists($currency,get_woocommerce_currencies())
            ||!MAD4B_Pricing_Currency_Configuration_V1::authoring_allowed($currency)))
            return new WP_Error('lab_currency_invalid',
                'Select a currency listed in the JetEngine Currencies Glossary and supported by WooCommerce');
        $audience=in_array(($raw['audience']??''),['general','egyptians'],true)
            ?$raw['audience']:'general';
        $occupancy=in_array(($raw['occupancy']??''),['single','double','triple'],true)
            ?$raw['occupancy']:'double';
        return [
            'title'=>$title,'languages'=>$languages,'markets'=>$markets,
            'language_scope'=>$scopeMode,'authoring_language'=>$authoring,
            'target_groups'=>$groups,
            'logic'=>$logic,'rules'=>$validated,'audience'=>$audience,
            'occupancy'=>$occupancy,'template_id'=>$template,
            'template_revision'=>(int)$t['revision'],
            'strategy'=>$strategy,'value'=>$value,'currency'=>$currency,
            'note'=>substr(sanitize_text_field((string)($raw['note']??'')),0,250),
            'apply_mode'=>'staged_native_drafts_only',
        ];
    }
    private static function find_index(array $rows,string $id): int {
        foreach($rows as $i=>$row)if(($row['id']??'')===$id)return $i;
        return -1;
    }
    private static function record(string $type,array $record,array $extra=[]): void {
        $history=self::receipts();
        $history[]=[
            'event'=>$type,'id'=>$record['id'],'revision'=>$record['revision'],
            'status'=>$record['status'],'at'=>gmdate('c'),
            'actor'=>get_current_user_id(),
            'changes'=>$extra
        ];
        if(count($history)>120)$history=array_slice($history,-120);
        update_option(self::HISTORY,$history,false);
    }
    public static function save($raw,string $id='',int $expected=0) {
        $valid=self::valid_config($raw);
        if(is_wp_error($valid))return $valid;
        $rows=self::presets();
        $i=$id!==''?self::find_index($rows,$id):-1;
        if($i>=0){
            $old=$rows[$i];
            if((int)$old['revision']!==$expected)return new WP_Error('lab_stale_version');
            if($old['status']!=='draft')return new WP_Error('lab_staged_must_rollback_first');
            $valid['id']=$old['id'];
            $valid['revision']=(int)$old['revision']+1;
            $valid['created_at']=$old['created_at'];
        }else{
            if($id!=='')return new WP_Error('lab_missing_preset');
            if(count($rows)>=self::MAX_PRESETS)return new WP_Error('lab_library_full');
            $valid['id']='pl-'.str_replace('-','',wp_generate_uuid4());
            $valid['revision']=1;
            $valid['created_at']=gmdate('c');
        }
        $valid['updated_at']=gmdate('c');
        $valid['status']='draft';
        $valid['staged_rows']=[];
        $valid['stage_fingerprint']='';
        $valid['stage_scope_hash']='';
        if($i>=0)$rows[$i]=$valid;else $rows[]=$valid;
        if(!update_option(self::OPTION,$rows,false))return new WP_Error('lab_save_failed');
        self::record($i>=0?'update_draft':'create_draft',$valid);
        return $valid;
    }
    private static function scope($preset) {
        $bridge='MAD4B_JetEngine_Strategy_Schema_Bridge_V1';
        $mode=$preset['language_scope']??'legacy';
        $canonical=$mode==='legacy'
            ?null:($preset['authoring_language']??null);
        if($mode==='target_groups'){
            $languages=$preset['languages'];
            $results=[];$combined=[];
            foreach($preset['target_groups']??[] as $group) {
                $targetLanguages=$group['display_languages']?:$languages;
                $match=$bridge::matching_ids($preset['rules'],$preset['logic'],
                    $targetLanguages,$group['markets'],$canonical);
                if(is_wp_error($match))return $match;
                foreach($match['ids'] as $id)$combined[(int)$id]=(int)$id;
                $results[]=[
                    'title'=>$group['title'],
                    'display_languages'=>$group['display_languages'],
                    'markets'=>$group['markets'],
                    'matched'=>count($match['ids']),
                    'canonical_tours_count'=>$match['canonical_tours_count']??0,
                    'missing_published_tour_translations'=>count(
                        $match['missing_published_tour_translations']??[])
                ];
            }
            return ['ids'=>array_values($combined),'matched'=>count($combined),
                'languages'=>$languages,'markets'=>[],
                'canonical_language'=>$canonical,'group_matches'=>$results,
                'language_mode'=>'explicit_target_groups',
                'unmapped_taxonomy_translations'=>[]];
        }
        $result=$bridge::matching_ids(
            $preset['rules'],$preset['logic'],$preset['languages'],
            $preset['markets'],$canonical);
        if(is_wp_error($result))return $result;
        $result['ids']=array_values(array_unique(array_map('absint',$result['ids'])));
        return $result;
    }
    private static function rate_ids(array $tourIds): array {
        if(!$tourIds)return [];
        global $wpdb;
        $ids=implode(',',array_slice(array_map('absint',$tourIds),0,6000));
        $rows=$wpdb->get_results(
            "SELECT p.ID rate,CAST(pm.meta_value AS UNSIGNED) tour
             FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
             WHERE p.post_type='tour-rates' AND p.post_status='publish'
               AND pm.meta_key='related_tour_id'
               AND pm.meta_value IN ({$ids})
             ORDER BY pm.post_id ASC LIMIT 1200",ARRAY_A);
        $byTour=[];
        foreach((array)$rows as $row){
            $t=(int)$row['tour'];$rate=(int)$row['rate'];
            if(!in_array($t,$tourIds,true))continue;
            $byTour[$t][$rate]=$rate;
        }
        return $byTour;
    }
    private static function calculate(float $price,array $preset,string $currency) {
        if(!function_exists('BookingExperience\pricing_preset_calculate'))
            return new WP_Error('lab_calculator_missing');
        $result=BookingExperience\pricing_preset_calculate(
            $price,['strategy'=>$preset['strategy'],
                'value'=>$preset['value'],'currency'=>$preset['currency']],
            $currency
        );
        return $result;
    }
    public static function preview($raw) {
        $preset=is_array($raw)&&isset($raw['revision'])&&isset($raw['id'])
            ?$raw:self::valid_config($raw);
        if(is_wp_error($preset))return $preset;
        // Revalidate saved records too: JetEngine term IDs and templates
        // might have changed since the Preset was originally authored.
        $validated=self::valid_config($preset);
        if(is_wp_error($validated))return $validated;
        $preset=array_replace($preset,$validated);
        $scope=self::scope($preset);
        if(is_wp_error($scope))return $scope;
        $tourIds=$scope['ids'];
        $rates=self::rate_ids($tourIds);
        $allRateIds=[];
        foreach($rates as $group)foreach($group as $rate)$allRateIds[]=$rate;
        $totalRates=count($allRateIds);
        $overlimit=$totalRates>self::MAX_STAGED_RATES
            || count($tourIds)>self::MAX_TOURS;
        $allRateIds=array_slice($allRateIds,0,self::MAX_STAGED_RATES);
        $existing=function_exists('BookingExperience\pricing_preset_rows')
            ?BookingExperience\pricing_preset_rows():[];
        $conflicted=[];
        foreach($existing as $entry) {
            if(!is_array($entry))continue;
            if(($entry['scope']??'')==='product'
                &&($entry['dimension']??'')==='general')
                $conflicted[(int)($entry['target']??0)]=true;
        }
        $sample=[];$eligible=[];$conflicts=[];$summedBefore=0.0;$summedAfter=0.0;
        foreach($allRateIds as $rate){
            $tour=(int)get_post_meta($rate,'related_tour_id',true);
            $q=function_exists('pricing_audience_quote')
                ?pricing_audience_quote($rate,$preset['audience'],$preset['occupancy'])
                :['status'=>'unavailable','reason'=>'quote_engine_missing'];
            $issue=null;$before=null;$after=null;$currency=null;
            if(($q['status']??'')!=='available')$issue=(string)($q['reason']??'source_unavailable');
            else {
                $currency=(string)$q['source_currency'];
                $before=(float)$q['final'];
                $adjusted=self::calculate($before,$preset,$currency);
                if(is_wp_error($adjusted))$issue=$adjusted->get_error_code();
                else $after=(float)$adjusted;
                if(!empty($q['discounted']) && $preset['strategy']!=='unchanged'
                    && !in_array($preset['strategy'],['floor','cap'],true))$issue='source_sale_stacking_review';
                if(isset($conflicted[$rate]))$issue='existing_native_preset_assignment';
                $controller=get_post_meta($rate,'_mad4b_rate_price_group_controller_v1',true);
                if(is_array($controller)&&!empty($controller['managed']))$issue='rate_level_override_review';
            }
            if($issue){
                $conflicts[]=['tour_id'=>$tour,'rate_id'=>$rate,'reason'=>$issue];
            }else{
                $eligible[]=$rate;
                $summedBefore+=$before;
                $summedAfter+=$after;
            }
            // Source lineage for this exact dry-run, not a different
            // direct Tour-price source arbitrator. pricing_audience_quote()
            // selects the Rate's regular or *valid active sale* amount
            // after checking published/stop-sale/current validity.
            $audiencePrefix=$preset['audience']==='egyptians'?'egyptians_':'';
            $effectiveRole=($q['status']??'')==='available'
                && !empty($q['discounted'])?'active_rate_sale':'rate_regular';
            $sample[]=[
                'tour_id'=>$tour,'tour'=>sanitize_text_field(get_the_title($tour)),
                'rate_id'=>$rate,'rate'=>sanitize_text_field(get_the_title($rate)),
                'currency'=>$currency,'before'=>$before,'illustrative_after'=>$after,
                'difference'=>$before!==null && $after!==null?round($after-$before,2):null,
                'status'=>$issue?'review_needed':'eligible_for_staging',
                'reason'=>$issue,
                'reference'=>[
                    'source'=>'published_tour_rate',
                    'amount_role'=>($q['status']??'')==='available'?$effectiveRole:null,
                    'regular'=>($q['status']??'')==='available'?(float)$q['regular']:null,
                    'sale'=>($q['status']??'')==='available'&&$q['sale']!==null
                        ?(float)$q['sale']:null,
                    'effective'=>($q['status']??'')==='available'?$before:null,
                    'currency'=>$currency,
                    'audience'=>$preset['audience'],
                    'occupancy'=>$preset['occupancy'],
                    'meta_field'=>$audiencePrefix.$preset['occupancy'].
                        (!empty($q['discounted'])?'_sale_price':'_price'),
                    'validity_basis'=>'server_current_time',
                    'not_checkout_quote'=>true,
                ],
            ];
        }
        // Fingerprint includes scope and immutable source values that informed
        // the preview. A change requires a fresh dry-run before staging.
        $fingerprint=hash('sha256',wp_json_encode([
            'preset'=>$preset,'ids'=>$tourIds,'rows'=>$sample,'total_rates'=>$totalRates,
            'template_revision'=>$preset['template_revision'],
            'native_rows'=>hash('sha256',wp_json_encode($existing)),
        ]));
        return [
            'status'=>'dry_run','scope_count'=>count($tourIds),
            'tour_ids'=>$tourIds,'total_linked_rates'=>$totalRates,
            'examined_rates'=>count($sample),'eligible_rates'=>$eligible,
            'conflicts'=>$conflicts,'rows'=>$sample,
            'truncated'=>$overlimit,
            // The native Booking Experience row schema cannot express
            // display-language/market group predicates. Never stage a
            // product-only assignment that could later widen commercial
            // eligibility if a reviewer enables the optional provider.
            'can_stage'=>!$overlimit && count($eligible)>0
                && ($preset['language_scope']??'legacy')!=='target_groups',
            'excluded_rates'=>count($conflicts),
            'exclusion_policy'=>'ineligible_or_customized_rates_are_skipped_not_rewritten',
            'sum_before'=>$summedBefore,'sum_after'=>$summedAfter,
            'total_mixed_currencies'=>count(array_unique(array_filter(
                array_column($sample,'currency'))))>1,
            'digest'=>$fingerprint,'terms'=>count($preset['rules']),
            'pricing_engine'=>'BookingExperience native preset calculator',
            'targeting_engine'=>'MAD4B Strategy Schema Bridge',
            'language_scope'=>$preset['language_scope']??'legacy',
            'canonical_language'=>$scope['canonical_language']??null,
            'group_matches'=>$scope['group_matches']??[],
            'unmapped_taxonomy_translations'=>$scope['unmapped_taxonomy_translations']??[],
            'missing_published_tour_translations'=>$scope['missing_published_tour_translations']??[],
            'quote_authority'=>false,'preview_only'=>true,'writes'=>0,
            'native_preset_layer_enabled'=>function_exists('BookingExperience\pricing_preset_settings')
                ?(bool)BookingExperience\pricing_preset_settings()['enabled']:false,
            'note'=>'No live price, checkout, coupon or campaign is changed. A staged assignment remains disabled until separately approved in the native Booking Experience provider.',
        ];
    }
    private static function native_rows(): array {
        $rows=get_option(self::PRESET_NATIVE,[]);
        return is_array($rows)?array_values($rows):[];
    }
    private static function native_recipe(array $preset,int $rate): array {
        return [
            'id'=>'mad4bpl_'.substr(hash('sha256',$preset['id']),0,12).'_'.$rate,
            'enabled'=>false,'approved'=>false,
            'scope'=>'product','target'=>(string)$rate,
            'dimension'=>'general','selector'=>'',
            'strategy'=>$preset['strategy'],'value'=>$preset['value'],
            'currency'=>$preset['currency'],'additive_approved'=>false,
            'note'=>'MAD4B Pricing Lab staged draft: '.$preset['title'],
            'template_id'=>$preset['template_id'],
            'template_revision'=>(int)$preset['template_revision'],
            'selector_revision'=>''
        ];
    }
    public static function stage(string $id,int $revision,string $digest) {
        $rows=self::presets();$index=self::find_index($rows,$id);
        if($index<0)return new WP_Error('lab_unknown_preset');
        $preset=$rows[$index];
        if((int)$preset['revision']!==$revision||$preset['status']!=='draft')
            return new WP_Error('lab_stale_preset');
        $plan=self::preview($preset);
        if(is_wp_error($plan))return $plan;
        if(!hash_equals($plan['digest'],$digest))
            return new WP_Error('lab_stale_preview','Scope or source rates changed; refresh dry run');
        if(($preset['language_scope']??'legacy')==='target_groups')
            return new WP_Error('lab_group_native_scope_unsupported',
                'Grouped display-language or market policies need a native scope-aware pricing provider before commercial staging');
        if(!$plan['can_stage'])return new WP_Error('lab_requires_review',
            'Resolve conflicts or narrow scope before staging');
        $existing=self::native_rows();
        $newRows=[];
        foreach($plan['eligible_rates'] as $rate){
            $record=self::native_recipe($preset,$rate);
            if(!function_exists('BookingExperience\pricing_preset_validate_row'))
                return new WP_Error('lab_native_validator_missing');
            $validated=BookingExperience\pricing_preset_validate_row($record);
            if(is_wp_error($validated))return $validated;
            $newRows[]=$validated;
        }
        $merged=array_merge($existing,$newRows);
        $checked=BookingExperience\pricing_preset_validate_rows($merged);
        if(is_wp_error($checked))return $checked;
        // All rows are disabled, even if the native optional layer was already
        // active. Nothing materializes a payable discount from staging.
        if(!update_option(self::PRESET_NATIVE,$checked,false))
            return new WP_Error('lab_native_stage_failed');
        $preset['status']='staged_for_native_review';
        $preset['revision']++;
        $preset['updated_at']=gmdate('c');
        $preset['staged_rows']=array_column($newRows,'id');
        $preset['stage_fingerprint']=hash('sha256',wp_json_encode($newRows));
        $preset['stage_scope_hash']=$digest;
        $rows[$index]=$preset;
        if(!update_option(self::OPTION,$rows,false))
            return new WP_Error('lab_staging_metadata_failed',
                'Native draft rows were staged but receipt update failed; reconcile before continuing');
        self::record('stage_native_disabled',$preset,[
            'native_rate_assignments'=>count($newRows),'group_tours'=>$plan['scope_count'],
            'digest'=>$digest,'no_commercial_activation'=>true,
        ]);
        return ['preset'=>$preset,'staged'=>count($newRows),
            'native_assignments_enabled'=>false,'published_prices_changed'=>false];
    }
    public static function rollback(string $id,int $revision) {
        $rows=self::presets();$index=self::find_index($rows,$id);
        if($index<0)return new WP_Error('lab_unknown_preset');
        $preset=$rows[$index];
        if((int)$preset['revision']!==$revision
            ||$preset['status']!=='staged_for_native_review')
            return new WP_Error('lab_stale_rollback');
        $ids=array_fill_keys((array)$preset['staged_rows'],true);
        $existing=self::native_rows();$managed=[];$kept=[];
        foreach($existing as $row){
            if(isset($ids[$row['id']??'']))$managed[]=$row;
            else $kept[]=$row;
        }
        if(count($managed)!==count($ids)
            ||hash('sha256',wp_json_encode($managed))!==$preset['stage_fingerprint'])
            return new WP_Error('lab_native_rows_modified',
                'One or more native assignments changed; manual reconciliation required');
        if(!update_option(self::PRESET_NATIVE,$kept,false))
            return new WP_Error('lab_native_rollback_failed');
        $preset['status']='draft';$preset['revision']++;
        $preset['updated_at']=gmdate('c');
        $preset['staged_rows']=[];$preset['stage_fingerprint']='';$preset['stage_scope_hash']='';
        $rows[$index]=$preset;
        if(!update_option(self::OPTION,$rows,false))
            return new WP_Error('lab_rollback_metadata_failed');
        self::record('rollback_staged_drafts',$preset,[
            'removed_native_drafts'=>count($managed),
            'original_source_rates_untouched'=>true
        ]);
        return ['preset'=>$preset,'removed'=>count($managed),
            'published_prices_changed'=>false];
    }
    public static function ajax(): void {
        if(!self::permitted())
            wp_send_json_error(['message'=>'Forbidden'],403);
        check_ajax_referer(self::ACTION,'nonce');
        $op=sanitize_key((string)($_POST['operation']??'load'));
        $raw=isset($_POST['data'])&&is_string($_POST['data'])
            ?wp_unslash($_POST['data']):'{}';
        if(strlen($raw)>10000)wp_send_json_error(['message'=>'Request too large'],400);
        $data=json_decode($raw,true);
        if(!is_array($data))wp_send_json_error(['message'=>'Invalid payload'],400);
        if($op==='load')$result=self::load();
        elseif($op==='catalog'){
            $resolver='MAD4B_JetEngine_Tour_Preview_Filter_Registry_V1';
            $language=$resolver::selected_languages((array)($data['languages']??['en']));
            $result=MAD4B_JetEngine_Strategy_Schema_Bridge_V1::catalog($language);
        }
        elseif($op==='preview')$result=self::preview($data);
        elseif($op==='save')$result=self::save($data['config']??[],
            sanitize_text_field((string)($data['id']??'')),absint($data['revision']??0));
        elseif($op==='stage'){
            if(($data['confirm']??'')!=='stage_disabled_native_drafts')
                wp_send_json_error(['message'=>'Confirm disabled native assignment staging'],400);
            $result=self::stage(sanitize_text_field((string)($data['id']??'')),
                absint($data['revision']??0),
                sanitize_text_field((string)($data['digest']??'')));
        }elseif($op==='rollback')$result=self::rollback(
            sanitize_text_field((string)($data['id']??'')),absint($data['revision']??0));
        else wp_send_json_error(['message'=>'Unknown Pricing Lab operation'],400);
        if(is_wp_error($result))wp_send_json_error(
            ['code'=>$result->get_error_code(),'message'=>$result->get_error_message()],409);
        wp_send_json_success($result);
    }
}
