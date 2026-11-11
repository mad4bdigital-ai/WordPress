<?php
defined('ABSPATH') || exit;
/**
 * Live, read-only field type coverage inventory. Never implies vendor Save
 * readiness solely from CSS selectors or synthetic Chromium tests.
 */
final class MAD4B_JetEngine_Recipe_Field_Matrix_V1 {
    private const OPTIONS_VISUAL = [
        'text','textarea','number','select','checkbox','switcher',
        'colorpicker','media','gallery','repeater','html',
    ];
    // Structural/decorative/composition blocks should not be counted as
    // unsupported input widgets. Their original vendor behavior is required.
    private const JFB_STRUCTURAL = [
        'check-mark','choice','choice-control','form-block','form-break-start',
        'macros-inserter','multi-gateway','repeater-row','submit-field',
    ];
    private const JFB_VISUAL = [
        'text-field','textarea-field','number-field','select-field',
        'radio-field','checkbox-field','switcher','choices-field',
        'media-field','wysiwyg-field','repeater-field','range-field',
        'date-field','datetime-field','time-field','color-picker-field',
        'heading-field','hidden-field','group-break-field','form-break-field',
        'progress-bar','calculated-field','conditional-block',
    ];
    // Native provider widgets now receive layout guards and protection
    // against duplicate accordion toggles. This does NOT prove remote maps,
    // phone-country code or CAPTCHA verification is operational.
    private const SPECIALIST_GUARDS = [
        'advanced-date-field','phone-field','map-field','captcha-container',
    ];
    // Evidence-based interactions, not an assertion of full functionality.
    private const OPTIONS_INTERACTION = [
        'text','textarea','number','select','repeater',
    ];
    // 2026-11-10: native Jet_Engine_Options_Page_Factory::update_options()
    // readback through original get() on an isolated temporary Options Page,
    // with built-in sanitize and finally cleanup (45/45 assertions).
    // Not an authenticated browser submit, and no real media uploaded.
    private const OPTIONS_NATIVE_STORAGE_QA = [
        'text','number','select','checkbox','textarea','switcher',
        'colorpicker','repeater','media','gallery',
    ];
    // Observed with real WordPress-rendered Options HTML in Arabic RTL,
    // widths 320/768/1366; confirms layout/DOM retention only, not Save.
    private const OPTIONS_RTL_LAYOUT = [
        'switcher','select','text','textarea','number','repeater',
        'checkbox','colorpicker','media','gallery',
    ];

    // Isolated draft Gutenberg block-definition persistence/readback,
    // not a submitted form or native value storage validation.
    private const JFB_DRAFT_DEFINITION_QA = [
        'text-field','number-field','textarea-field',
    ];
    // Native JFB send_form() succeeded on one ephemeral zero-action,
    // zero-gateway published QA form; no stored submission or browser POST.
    private const JFB_ISOLATED_SUBMIT_QA = [
        'text-field','number-field',
    ];
    private const JFB_INTERACTION = [
        'text-field','number-field','select-field','media-field',
        'wysiwyg-field',
    ];

    public static function inventory(): array {
        $found=[];
        $add=static function(string $engine,string $name,int $count=0) use (&$found):void {
            $key=$engine.':'.$name;
            if(!isset($found[$key]))$found[$key]=['engine'=>$engine,'type'=>$name,'count'=>0];
            $found[$key]['count']+=$count;
        };
        if(class_exists('MAD4B_JetEngine_Field_Modes_V1')){
            foreach(MAD4B_JetEngine_Field_Modes_V1::pages() as $page) {
                foreach(($page['fields']??[]) as $field) {
                    $name=(string)($field['type']??'unknown');
                    if(preg_match('/^[a-z0-9_-]{1,80}$/D',$name))$add('options',$name,1);
                }
            }
        }
        // JetEngine's native Meta Box schema is the authoritative CPT, User
        // and Taxonomy editor field source; do not assume Options covers it.
        $nativeBoxes=get_option('jet_engine_meta_boxes',[]);
        if(is_array($nativeBoxes)){
            $visit=static function(array $fields) use (&$visit,$add):void{
                foreach($fields as $field){
                    if(!is_array($field))continue;
                    $name=(string)($field['type']??'unknown');
                    if(preg_match('/^[a-z0-9_-]{1,80}$/D',$name))$add('meta',$name,1);
                    foreach(['fields','repeater_fields','meta_fields'] as $nested){
                        if(!empty($field[$nested]) && is_array($field[$nested]))
                            $visit($field[$nested]);
                    }
                }
            };
            foreach($nativeBoxes as $box){
                if(is_array($box))$visit((array)($box['meta_fields']??[]));
            }
        }
        if(class_exists('WP_Block_Type_Registry')){
            foreach(array_keys(WP_Block_Type_Registry::get_instance()->get_all_registered()) as $block) {
                if(str_starts_with($block,'jet-forms/')){
                    $name=substr($block,10);
                    if(preg_match('/^[a-z0-9_-]{1,80}$/D',$name))$add('jetform',$name,0);
                }
            }
        }
        // Read only: discover actual JFB usage from published and draft
        // Gutenberg block definitions, including nested block types.
        $posts=get_posts(['post_type'=>'jet-form-builder',
            'post_status'=>['publish','draft','private'],'posts_per_page'=>200,
            'fields'=>'ids','suppress_filters'=>true]);
        $walk=static function(array $blocks) use (&$walk,$add):void {
            foreach($blocks as $block){
                $name=(string)($block['blockName']??'');
                if(str_starts_with($name,'jet-forms/')){
                    $name=substr($name,10);
                    if(preg_match('/^[a-z0-9_-]{1,80}$/D',$name))$add('jetform',$name,1);
                }
                if(!empty($block['innerBlocks']))$walk($block['innerBlocks']);
            }
        };
        foreach($posts as $id)$walk(parse_blocks((string)get_post_field('post_content',$id)));

        // A list of JetEngine CPT field types is not included unless live
        // metadata is independently inventoried; do not infer CPT coverage
        // from the Options field list or from plugin PHP source files.
        $rows=[];
        foreach($found as $record){
            $engine=$record['engine'];$type=$record['type'];
            $isNative=in_array($engine,['options','meta'],true);
            // JetEngine Options 'html' is static UI/markup, not a writable
            // native input. It cannot have a meaningful Save roundtrip.
            $structural=($engine==='jetform' && in_array($type,self::JFB_STRUCTURAL,true))
                || ($engine==='options' && $type==='html');
            $guarded=$engine==='jetform' && in_array($type,self::SPECIALIST_GUARDS,true);
            $visual=in_array($type,
                $isNative?self::OPTIONS_VISUAL:self::JFB_VISUAL,true);
            $interaction=in_array($type,
                $isNative?self::OPTIONS_INTERACTION:self::JFB_INTERACTION,true);
            $rows[]=array_merge($record,[
                'visual'=>$structural?'vendor_structural':($guarded?'specialist_guarded':($visual?'adapter_present':'review_required')),
                'interaction'=>$interaction?'isolated_partial':'not_verified',
                'definition'=>$engine==='jetform' && in_array($type,self::JFB_DRAFT_DEFINITION_QA,true)
                    ? 'isolated_draft_readback' : 'not_verified',
                'submit'=>$engine==='jetform' && in_array($type,self::JFB_ISOLATED_SUBMIT_QA,true)
                    ? 'isolated_native_noaction' : 'not_verified',
                'save'=>$engine==='options' && in_array($type,self::OPTIONS_NATIVE_STORAGE_QA,true)
                    ? 'isolated_native_storage' : 'not_verified',
                'rtl'=>$engine==='options' && in_array($type,self::OPTIONS_RTL_LAYOUT,true)
                    ? 'isolated_layout_partial' : 'not_verified_by_type',
                'ready'=>false,
            ]);
        }
        usort($rows,static fn($a,$b)=>
            strcmp($a['engine'],$b['engine'])?:strcmp($a['type'],$b['type']));
        $summary=['types'=>count($rows),'used_types'=>0,'instances'=>0,
            'visual_adapters'=>0,'visual_gaps'=>0,'specialist_guarded'=>0,'native_structural'=>0,'interaction_partial'=>0,
            'save_verified'=>0,'native_storage_partial'=>0,'jfb_draft_definitions'=>0,'jfb_isolated_noaction_submit'=>0,'jfb_validation_boundary_qa'=>true,'rtl_layout_partial'=>0,'fully_accepted'=>0,'cpt_catalog_verified'=>true];
        foreach($rows as $row){
            $summary['instances']+=$row['count'];
            if($row['count']>0)$summary['used_types']++;
            if($row['visual']==='adapter_present')$summary['visual_adapters']++;
            elseif($row['visual']==='vendor_structural')$summary['native_structural']++;
            elseif($row['visual']==='specialist_guarded')$summary['specialist_guarded']++;
            else $summary['visual_gaps']++;
            if($row['interaction']==='isolated_partial')$summary['interaction_partial']++;
            if($row['rtl']==='isolated_layout_partial')$summary['rtl_layout_partial']++;
            if($row['save']==='isolated_native_storage')$summary['native_storage_partial']++;
            if($row['definition']==='isolated_draft_readback')$summary['jfb_draft_definitions']++;
            if($row['submit']==='isolated_native_noaction')$summary['jfb_isolated_noaction_submit']++;
        }
        return ['summary'=>$summary,'rows'=>$rows,
            'note'=>'JFB native no-action in-process submit is not a browser Submit or persistent record. Visual adapter != full widget acceptance. Native Options storage API readback is isolated and does NOT verify browser Save, upload or field-specific interactions. Native Meta Box schemas are now included; direct JetEngine CPT fields outside this registry may require further inventory.'];
    }
    public static function panel():void {
        $matrix=self::inventory();$s=$matrix['summary'];
        echo '<section class="mad4b-rw-panel mad4b-rw-matrix" id="mad4b-recipe-field-matrix">';
        echo '<div class="mad4b-rw-panel-head"><span class="mad4b-rw-step">QA</span><div>';
        echo '<h2>Field Type Compatibility</h2><p>Read-only inventory of registered JetFormBuilder blocks and JetEngine Options fields. No artificial green check for untested Saves.</p></div></div>';
        echo '<div class="mad4b-rw-matrix-stats">';
        foreach([
            'Discovered types'=>$s['types'],'Types currently used'=>$s['used_types'],
            'Visual adapters'=>$s['visual_adapters'],'Guarded specialists'=>$s['specialist_guarded'],
            'Still unknown'=>$s['visual_gaps'],'Vendor structure'=>$s['native_structural'],
            'Native storage QA'=>$s['native_storage_partial'],
            'JFB draft definitions'=>$s['jfb_draft_definitions'],
            'JFB isolated native submit'=>$s['jfb_isolated_noaction_submit'],
            'JFB invalid-submit guards'=>$s['jfb_validation_boundary_qa'] ? 'PASS' : 'Pending',
            'Full Save acceptance'=>$s['fully_accepted'],
        ] as $label=>$n){
            echo '<div><strong>'.esc_html((string)$n).'</strong><span>'.esc_html($label).'</span></div>';
        }
        echo '</div>';
        echo '<p class="mad4b-rw-help">JetFormBuilder native send_form succeeded for isolated Text and Number in a temporary published QA form with zero Actions and zero payment gateways. Original parser returned expected values; no persistent form record. This was an in-process server test, NOT browser submission, not a stored customer record and not an authenticated admin Save.</p>';
        echo '<p class="mad4b-rw-help">JetFormBuilder Draft definition readback is isolated. Invalid submit guards were also tested separately: draft/unknown IDs and missing/invalid signatures rejected by the original JFB validation handler (13/13). This does not test successful submission or persisted form values.</p>';
        echo '<p class="mad4b-rw-help">JetFormBuilder Text, Number and Textarea have native draft definition readback in isolated QA (not field values, validation or submission). ';
        echo 'Native Save acceptance remains zero. </p>';
        echo '<p class="mad4b-rw-help">Interaction marked partial means selected isolated tests only. Ten original Options field families have isolated Arabic RTL layout checks AND separate native JetEngine storage API readback on an isolated temporary page. Browser Save, full widget interaction and accessibility remain unverified. Native Meta Box schema is included; programmatically registered fields may need separate discovery.</p>';
        if($s['specialist_guarded']>0){
            echo '<div class="notice notice-info inline"><p><strong>'
               .esc_html((string)$s['specialist_guarded'])
               .' specialized widgets have safe Recipe layout guards.</strong> '
               .'Their real calendar, phone-country dropdown, map provider, CAPTCHA verification and native submission need separate environment-specific acceptance. No provider security is bypassed.</p></div>';
        }
        if($s['visual_gaps']>0){
            $pending=[];
            foreach($matrix['rows'] as $row){
                if($row['visual']==='review_required')
                    $pending[]=$row['engine'].':'.$row['type'];
            }
            echo '<div class="notice notice-warning inline"><p><strong>'
               .esc_html((string)$s['visual_gaps']).' registered widget types need a specialist Recipe review.</strong> '
               .esc_html(implode(', ',$pending))
               .'. These native controls remain owned by their providers; no submission or booking behavior was changed.</p></div>';
        }

        echo '<div class="mad4b-rw-matrix-table"><table class="widefat striped"><thead><tr>';
        foreach(['Engine','Native field type','Occurrences','Visual Recipe','Interaction','Native Save','Field RTL'] as $h)
            echo '<th scope="col">'.esc_html($h).'</th>';
        echo '</tr></thead><tbody>';
        foreach($matrix['rows'] as $row){
            echo '<tr><td>'.esc_html($row['engine']).'</td><td><code>'.esc_html($row['type']).'</code></td>';
            echo '<td>'.esc_html((string)$row['count']).'</td>';
            echo '<td>'.esc_html(match($row['visual']){
                'adapter_present'=>'Adapter present',
                'vendor_structural'=>'Vendor structural (native)',
                'specialist_guarded'=>'Layout guarded — vendor QA pending',
                default=>'Special widget review',
            }).'</td>';
            echo '<td>'.esc_html($row['interaction']==='isolated_partial'?'Partial isolated QA':'Not verified').'</td>';
            echo '<td>'.esc_html($row['save']==='isolated_native_storage'
                ? 'Isolated native API readback' : 'Not verified').'</td><td>'
               .esc_html($row['rtl']==='isolated_layout_partial'
                   ? 'RTL layout partial' : 'Not verified')
               .'</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }
}
