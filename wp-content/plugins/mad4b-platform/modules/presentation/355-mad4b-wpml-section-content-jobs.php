<?php
/**
 * Plugin Name: MAD4B WPML New Section to ContentJob
 * Description: Detect newly added default-language Elementor sections across pages/templates and reconcile native ContentJobs after existing layout engines finish.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_WPML_Section_Content_Jobs {
    const OUTBOX = 'mad4b_wpml_section_jobs_outbox_v1';
    const EVENT = 'mad4b_wpml_section_jobs_reconcile_v1';
    const SCAN_EVENT = 'mad4b_wpml_section_jobs_periodic_v1';
    const LIMIT=80;
    private static $busy=false;
    private static $text_keys=['title','subtitle','heading','subheading','description','text','editor','content','button_text','label','caption','placeholder','message','html','before_text','after_text','cta_text'];
    public static function enabled(): bool {
        // On ordinary company sites the local outbox remains active even when
        // native central ContentJobs are delegated to a separate host.
        return defined('ICL_SITEPRESS_VERSION') &&
            function_exists('wpml_get_language_information');
    }
    private static function language(int $id): string {
        $details=apply_filters('wpml_post_language_details',null,$id);
        return is_array($details)?(string)($details['language_code']??''):'';
    }
    private static function default_language(): string {
        return (string)apply_filters('wpml_default_language','en');
    }
    private static function active_languages(): array {
        $rows=apply_filters('wpml_active_languages',null,'skip_missing=0');
        return is_array($rows)?array_keys($rows):[];
    }
    private static function layout(int $id): array {
        $raw=get_post_meta($id,'_elementor_data',true);
        $decoded=is_string($raw)?json_decode($raw,true):$raw;
        return is_array($decoded)?$decoded:[];
    }
    public static function has_static_text(array $node): bool {
        $settings=(array)($node['settings']??[]);
        $dynamic=(array)($settings['__dynamic__']??[]);
        foreach(self::$text_keys as $key){
            if(array_key_exists($key,$dynamic)||empty($settings[$key])||!is_scalar($settings[$key]))continue;
            $value=(string)$settings[$key];
            $clean=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags(strip_shortcodes($value))));
            if(strlen($clean)<5||preg_match('/^(?:https?:|#[0-9a-f]+$|\d[\d .,:-]*)/i',$clean)||
                str_contains($clean,'{{')||str_contains($clean,'[%'))continue;
            if(preg_match('/\p{L}{3,}/u',$clean))return true;
        }
        foreach((array)($node['elements']??[]) as $child)
            if(is_array($child)&&self::has_static_text($child))return true;
        return false;
    }
    private static function indexed_nodes(array $nodes,array &$index): void {
        foreach($nodes as $node){
            if(!is_array($node))continue;
            $id=(string)($node['id']??'');
            if($id!=='')$index[$id]=$node;
            self::indexed_nodes((array)($node['elements']??[]),$index);
        }
    }
    private static function translatable_payload(array $node): array {
        $settings=(array)($node['settings']??[]);
        $dynamic=(array)($settings['__dynamic__']??[]);
        $values=[];
        foreach(self::$text_keys as $key){
            if(isset($dynamic[$key])||!isset($settings[$key])||!is_scalar($settings[$key]))continue;
            $value=trim(preg_replace('/\\s+/u',' ',wp_strip_all_tags(strip_shortcodes((string)$settings[$key]))));
            if(strlen($value)>4&&preg_match('/\\p{L}{3,}/u',$value) &&
                !str_contains($value,'{{'))$values[$key]=$value;
        }
        foreach((array)($node['elements']??[]) as $child)
            if(is_array($child)){
                $inner=self::translatable_payload($child);
                if($inner)$values[(string)($child['id']??'child')]= $inner;
            }
        return $values;
    }
    private static function ids(array $nodes,array &$ids): void {
        foreach($nodes as $n){
            if(!is_array($n))continue;
            $id=(string)($n['id']??'');
            if(preg_match('/^[a-zA-Z0-9]{5,18}$/D',$id))$ids[$id]=true;
            self::ids((array)($n['elements']??[]),$ids);
        }
    }
    private static function absent(array $nodes,array $target_ids,array &$missing): void {
        foreach($nodes as $n){
            if(!is_array($n))continue;
            $id=(string)($n['id']??'');
            if($id!==''&&!isset($target_ids[$id])){
                // One task per source/locale revision, but preserve precise
                // missing node identities for auditable translation work.
                $missing[$id]=['text'=>self::has_static_text($n),
                    'type'=>(string)($n['widgetType']??$n['elType']??'')];
                continue;
            }
            self::absent((array)($n['elements']??[]),$target_ids,$missing);
        }
    }
    public static function compare(array $src,array $dst): array {
        $target=[];self::ids($dst,$target);
        $missing=[];self::absent($src,$target,$missing);
        $static=array_keys(array_filter($missing,static fn($row)=>$row['text']===true));
        $struct=array_keys(array_filter($missing,static fn($row)=>$row['text']===false));
        sort($static);sort($struct);
        return ['missing_text_sections'=>$static,'missing_structure_only'=>$struct,
            'missing_count'=>count($missing)];
    }
    private static function localized_sections_verified(array $job): bool {
        $source=(int)($job['post_id']??0);
        $target=(int)($job['target_id']??0);
        if(!$source||!$target||get_post_status($target)!=='publish')return false;
        $src=[];$dst=[];
        self::indexed_nodes(self::layout($source),$src);
        self::indexed_nodes(self::layout($target),$dst);
        foreach((array)($job['missing_text_sections']??[]) as $id) {
            $key=(string)$id;
            if(!isset($src[$key])||!isset($dst[$key]))return false;
            $en=self::translatable_payload($src[$key]);
            $translation=self::translatable_payload($dst[$key]);
            // Do not use an English duplicate as evidence of completed
            // language-specific rendering, even when structure exists.
            if(!$translation||$translation===$en)return false;
        }
        foreach((array)($job['missing_structure_only']??[]) as $id)
            if(!isset($dst[(string)$id]))return false;
        return true;
    }
    private static function translations(int $id,string $type): array {
        $trid=apply_filters('wpml_element_trid',null,$id,'post_'.$type);
        if(!$trid)return [];
        $rows=apply_filters('wpml_get_element_translations',null,$trid,'post_'.$type);
        $out=[];
        foreach((array)$rows as $lang=>$info)
            if(is_object($info)&&!empty($info->element_id))$out[$lang]=(int)$info->element_id;
        return $out;
    }
    private static function source(int $id): bool {
        $p=get_post($id);
        $shared_templates=['elementor_library','elementor-hf','jet-engine',
            'jet-smart-filters','jet-woo-builder'];
        if(!$p||$p->post_status!=='publish'||
            (!is_post_type_viewable($p->post_type)&&
             !in_array($p->post_type,$shared_templates,true)))return false;
        if(self::language($id)!==self::default_language())return false;
        if(function_exists('mad4b_wpml_auto_sync_allowed_v1') &&
            !mad4b_wpml_auto_sync_allowed_v1($id))return false;
        return (bool)self::layout($id);
    }
    public static function analyze(int $id): array {
        if(!self::enabled()||!self::source($id))return [];
        $p=get_post($id);$tree=self::layout($id);
        $translations=self::translations($id,$p->post_type);
        $index=[];self::indexed_nodes($tree,$index);
        $results=[];
        foreach(self::active_languages() as $lang){
            if($lang===self::default_language())continue;
            if(!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D',$lang))continue;
            $target=$translations[$lang]??0;
            if($target && function_exists('mad4b_wpml_auto_sync_allowed_v1') &&
                !mad4b_wpml_auto_sync_allowed_v1($id,$target))continue;
            $targetpost=$target?get_post($target):null;
            $dst=$targetpost?self::layout($target):[];
            $diff=self::compare($tree,$dst);
            if(!$diff['missing_count'])continue;
            // Translation not yet created: only text-bearing source components
            // require content work; purely dynamic listings remain structural.
            // Revision identity comes from missing text and node structure,
            // not CSS, colors, global fonts or layout-only design revisions.
            $payload=[];
            foreach($diff['missing_text_sections'] as $node_id)
                if(isset($index[(string)$node_id]))
                    $payload[(string)$node_id]=self::translatable_payload($index[(string)$node_id]);
            $digest=hash('sha256',wp_json_encode([
                'missing_text'=>$payload,'missing_structure'=>$diff['missing_structure_only']
            ]));
            $token=hash('sha256',get_current_blog_id().'|'.$id.'|'.$lang.'|'.$digest);
            $results[$token]=[
              'token'=>$token,'post_id'=>$id,'type'=>$p->post_type,
              'target_id'=>$target,'language'=>$lang,'source_sha256'=>$digest,
              'missing_text_sections'=>$diff['missing_text_sections'],
              'missing_structure_only'=>$diff['missing_structure_only'],
              'state'=>count($diff['missing_text_sections'])?'AWAITING_CONTENT_JOB':'STRUCTURE_ONLY',
              'first_observed'=>gmdate('c')
            ];
        }
        return $results;
    }
    private static function outbox(): array {
        $map=get_option(self::OUTBOX,[]);
        return is_array($map)?$map:[];
    }
    public static function reconcile(int $id,bool $store=false): array {
        $fresh=self::analyze($id);
        $prior=self::outbox();$changed=false;$resolved=0;
        foreach($prior as $token=>$job){
            if((int)($job['post_id']??0)!==$id)continue;
            // The original section job predates creation of the locale
            // template; target_id was 0 at intake. Resolve the exact current
            // WPML family identity before readback, never mark resolved from a
            // missing or cross-language translation.
            $lang=(string)($job['language']??'');
            $type=(string)($job['type']??get_post_type($id));
            $linked=$lang&&$type?(int)apply_filters('wpml_object_id',
                $id,$type,false,$lang):0;
            $native=$linked?get_post($linked):null;
            $trid=(int)apply_filters('wpml_element_trid',null,$id,'post_'.$type);
            $tridLocal=$linked?(int)apply_filters('wpml_element_trid',
                null,$linked,'post_'.$type):0;
            $details=$linked?apply_filters('wpml_post_language_details',null,$linked):null;
            if($native&&$native->post_type===$type&&$trid&&$tridLocal===$trid&&
               is_array($details)&&($details['language_code']??'')===$lang&&
               (int)($job['target_id']??0)!==$linked){
                $prior[$token]['target_id']=$linked;
                $job=$prior[$token];
                $changed=true;
            }
            if(!isset($fresh[$token])&&in_array(($job['state']??''),[
                'AWAITING_CONTENT_JOB','AWAITING_LAYOUT_JOB','WAITING_AUTHORIZED_ACTOR',
                'JOB_CREATED','JOB_RECONCILED','NEEDS_TRANSLATION_READBACK'],true)) {
                $same_revision=false;
                foreach($fresh as $other){
                    if((int)($other['post_id']??0)===$id &&
                        ($other['language']??'')===($job['language']??'')){
                        $same_revision=true;break;
                    }
                }
                $prior[$token]['state']=self::localized_sections_verified($job)
                    ?'RESOLVED_VERIFIED_BY_ELEMENTOR_READBACK'
                    :($same_revision?'SUPERSEDED_BY_CHANGED_EN_SOURCE'
                      :'NEEDS_TRANSLATION_READBACK');
                $prior[$token]['updated_at']=gmdate('c');$changed=true;$resolved++;
            }
        }
        $new=0;
        foreach($fresh as $token=>$row){
            if(isset($prior[$token])){
                $row=array_merge($row,array_intersect_key($prior[$token],array_flip([
                    'first_observed','job_id','attempts','error','state','uncertain'
                ])));
                if(!empty($row['job_id']))$row['state']='JOB_CREATED';
            }else{$new++;}
            if(!isset($prior[$token])||$prior[$token]!==$row){
                $prior[$token]=$row;$changed=true;
            }
        }
        if($store&&$changed){
            if(count($prior)>1500) $prior=array_slice($prior,-1500,null,true);
            update_option(self::OUTBOX,$prior,false);
        }
        return ['source_id'=>$id,'new'=>$new,'resolved'=>$resolved,
            'issues'=>count($fresh),'pending_text'=>count(array_filter($fresh,
                static fn($j)=>count($j['missing_text_sections'])>0)),
            'pending_layout'=>count(array_filter($fresh,
                static fn($j)=>count($j['missing_text_sections'])===0)),
            'stored'=>$store,'source'=>home_url('/')];
    }
    private static function native_scope() {
        if(!class_exists('MAD4B_SCP_Content_Jobs'))
            return new WP_Error('content_job_provider_not_enrolled');
        if(!class_exists('MAD4B_SCP_Policy')||MAD4B_SCP_Policy::can_mutate()!==true)
            return new WP_Error('content_job_actor_not_authorized');
        if(!class_exists('MAD4B_SCP_Operational_Integrity')||!class_exists('MAD4B_SCP_Schema'))
            return new WP_Error('content_job_provider_not_ready');
        $scope=MAD4B_SCP_Operational_Integrity::capture();
        return is_wp_error($scope)?$scope:(array)($scope['scope']??[]);
    }
    public static function dispatch(int $limit=2) {
        $scope=self::native_scope();
        if(is_wp_error($scope))return $scope;
        if(empty($scope['site_uuid'])||empty($scope['brand_ref']))
            return new WP_Error('content_job_native_scope_missing');
        $tables=MAD4B_SCP_Schema::tables();
        if(empty($tables['content_jobs']))return new WP_Error('content_job_table_missing');
        $out=self::outbox();$results=[];$limit=max(1,min(5,$limit));
        global $wpdb;
        foreach($out as $token=>$job){
            if(count($results)>=$limit)break;
            if(!in_array($job['state']??'',
                ['AWAITING_CONTENT_JOB','AWAITING_LAYOUT_JOB','WAITING_AUTHORIZED_ACTOR'],true)||
                !empty($job['uncertain']))continue;
            $id=(int)($job['post_id']??0);
            // Fresh exact post/locale readback prevents stale tasks after sync.
            $fresh=self::analyze($id);
            if(!isset($fresh[$token]))continue;
            $is_text=!empty($fresh[$token]['missing_text_sections']);
            $subject='Elementor section '.($is_text?'translation':'layout parity').' '.$job['type'].' #'.$id.
                     ' ['.$job['language'].'] sectionjob-ref:'.$token;
            $hits=$wpdb->get_col($wpdb->prepare(
                "SELECT job_id FROM {$tables['content_jobs']}
                  WHERE site_uuid=%s AND brand_id=%s AND subject LIKE %s LIMIT 3",
                $scope['site_uuid'],$scope['brand_ref'],
                '%'.$wpdb->esc_like('sectionjob-ref:'.$token).'%'));
            if(count((array)$hits)>1){$results[$token]='DUPLICATE_REQUIRES_REVIEW';continue;}
            if($hits){
                $out[$token]['job_id']=$hits[0];$out[$token]['state']='JOB_RECONCILED';
                $results[$token]='RECONCILED';continue;
            }
            $r=MAD4B_SCP_Content_Jobs::create_job([
                'brand_id'=>$scope['brand_ref'],'subject'=>$subject,
                'language'=>$job['language'],'country'=>'global',
                'content_type'=>$is_text?'translation':'layout','target_post_type'=>$job['type'],
                'automation_level'=>'assisted','reason'=>'New source-language Elementor section(s) still missing after existing 301/349 structure sync. Revision digest '.
                  $job['source_sha256'].'; source post '.$id.'; missing node IDs '.
                  implode(',',array_slice($is_text?$job['missing_text_sections']:$job['missing_structure_only'],0,22)).
                  '; preserve locale content; '.($is_text?'native WPML translation review':'layout-only parity; do not request text translation').' before publishing.'
            ]);
            if(is_wp_error($r)){
                $out[$token]['state']='NATIVE_RESULT_UNCERTAIN';
                $out[$token]['uncertain']=true;
                $out[$token]['error']=$r->get_error_code();
                $results[$token]=$r->get_error_code();continue;
            }
            $created=$r['job']??[];
            if(empty($created['job_id'])){
                $out[$token]['state']='NATIVE_RESULT_UNCERTAIN';$out[$token]['uncertain']=true;
                $out[$token]['error']='missing_readback';
                $results[$token]='MISSING_READBACK';continue;
            }
            $out[$token]['job_id']=$created['job_id'];$out[$token]['state']='JOB_CREATED';
            $results[$token]='CREATED';
        }
        if($results)update_option(self::OUTBOX,$out,false);
        return ['attempts'=>count($results),'results'=>$results,'published'=>false];
    }
    /**
     * Original Section ContentJobs owner: fulfil an existing translation job
     * with editor-provided literal translations and native WPML/Elementor
     * layout refs. This never generates translations or publishes content.
     */
    public static function create_localized_template_draft(
        int $source_id,string $lang,array $translations,string $expected_source_sha
    ):array|WP_Error {
        if(!self::enabled()||!self::source($source_id)||
           !class_exists('MAD4B_SCP_Policy')||
           !MAD4B_SCP_Policy::can_mutate()||!current_user_can('manage_options'))
            return new WP_Error('wpml_section_localization_actor_not_authorized');
        $source=get_post($source_id);
        if(!$source||!in_array($source->post_type,['jet-engine','elementor_library'],true)||
           !class_exists('MAD4B_WPML_Translation_Need_V1')||
           !class_exists('MAD4B_WPML_Structural_Sync_V2'))
            return new WP_Error('wpml_section_localization_source_invalid');
        $policy=MAD4B_WPML_Design_Reconciliation_V1::required_languages_readback();
        if(!$policy['consistent']||!in_array($lang,$policy['required'],true)||
           $lang===$policy['default']||
           (int)apply_filters('wpml_object_id',$source_id,$source->post_type,false,$lang)>0)
            return new WP_Error('wpml_section_localization_existing_or_invalid_locale');
        $classification=MAD4B_WPML_Translation_Need_V1::source($source_id);
        if(is_wp_error($classification)||
           ($classification['classification']??'')!=='translation_required'||
           ($classification['audited_source_sha256']??'')!==$expected_source_sha)
            return new WP_Error('wpml_section_localization_source_revision_or_classification');
        $visible=(int)($classification['visible_literals']??0);
        if(!$visible||$visible>32||count($translations)!==$visible)
            return new WP_Error('wpml_section_localization_incomplete_literal_count');
        $existing_jobs=self::outbox();$job_id='';
        foreach($existing_jobs as $job){
            if((int)($job['post_id']??0)===$source_id &&
               ($job['language']??'')===$lang &&
               ($job['state']??'')==='JOB_CREATED'&&!empty($job['job_id'])){
                $job_id=(string)$job['job_id'];break;
            }
        }
        if($job_id===''||!class_exists('MAD4B_SCP_Content_Jobs'))
            return new WP_Error('wpml_section_localization_native_contentjob_missing');
        $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
        if(is_wp_error($job)||!in_array($job['job']['state']??'',
            ['NEW','QUEUED','RUNNING'],true))
            return new WP_Error('wpml_section_localization_native_job_not_ready');
        $source_tree=self::layout($source_id);
        if(!$source_tree||get_post_meta($source_id,'_elementor_edit_mode',true)!=='builder')
            return new WP_Error('wpml_section_localization_builder_source_missing');
        $seed=$source_tree;$found=[];
        $walk=static function(&$nodes)use(&$walk,$translations,&$found){
            foreach($nodes as &$node){
                if(!is_array($node))continue;
                $widget=(string)($node['id']??'');
                foreach((array)($node['settings']??[]) as $field=>$value){
                    $key=$widget.'|'.$field;
                    if(!isset($translations[$key]))continue;
                    $entry=$translations[$key];
                    if(!is_array($entry)||!is_string($value)||
                       isset($node['settings']['__dynamic__'][$field])||
                       ($entry['source']??null)!==$value||
                       !is_string($entry['translated']??null)||
                       trim($entry['translated'])===''||
                       $entry['translated']===$value||
                       preg_match('/<[^>]+>/',$entry['translated'])||
                       strlen($entry['translated'])>500)
                        throw new RuntimeException('INVALID_LITERAL_'.$key);
                    if(isset($found[$key]))throw new RuntimeException('DUPLICATE_WIDGET_'.$key);
                    $found[$key]=true;
                    $node['settings'][$field]=$entry['translated'];
                }
                if(!empty($node['elements']))$walk($node['elements']);
            }
        };
        try{$walk($seed);}catch(Throwable $e){
            return new WP_Error('wpml_section_localization_literal_payload_invalid');
        }
        if(count($found)!==$visible||count($found)!==count($translations))
            return new WP_Error('wpml_section_localization_literals_unresolved');
        $trid=(int)apply_filters('wpml_element_trid',null,$source_id,
            'post_'.$source->post_type);
        if(!$trid)return new WP_Error('wpml_section_localization_source_trid_missing');
        // Builder markup is authoritative in _elementor_data. The source has
        // a multi-MB legacy rendered post_content cache; do not clone that
        // English HTML into four public locale templates.
        $new=wp_insert_post(wp_slash([
            'post_type'=>$source->post_type,'post_status'=>'draft',
            'post_author'=>get_current_user_id(),'post_title'=>$source->post_title,
            'post_content'=>'','post_excerpt'=>'',
            'comment_status'=>'closed','ping_status'=>'closed'
        ]),true);
        if(is_wp_error($new)||!$new)
            return new WP_Error('wpml_section_localization_draft_insert_failed');
        $target=(int)$new;
        do_action('wpml_set_element_language_details',[
            'element_id'=>$target,'element_type'=>'post_'.$source->post_type,
            'trid'=>$trid,'language_code'=>$lang,
            'source_language_code'=>$policy['default']]);
        $target_trid=(int)apply_filters('wpml_element_trid',null,$target,
            'post_'.$source->post_type);
        if($target_trid!==$trid||self::language($target)!==$lang)
            return new WP_Error('wpml_section_localization_link_readback_failed',
                'Draft retained for reconciliation',['draft_id'=>$target]);
        $issues=[];$changed=0;
        $planned=MAD4B_WPML_Structural_Sync_V2::plan(
            $source_tree,$seed,$lang,$source_id,$target,$issues,$changed);
        if($issues)
            return new WP_Error('wpml_section_localization_plan_needs_review',
                'Draft retained, no content publication',
                ['draft_id'=>$target,'issues'=>array_slice($issues,0,25)]);
        foreach(['_listing_data','_listing_type','_elementor_edit_mode',
                 '_elementor_template_type','_elementor_page_settings'] as $key){
            if(!metadata_exists('post',$source_id,$key))continue;
            $value=get_post_meta($source_id,$key,true);
            if(!add_post_meta($target,$key,is_string($value)?wp_slash($value):$value,true))
                return new WP_Error('wpml_section_localization_meta_copy_failed',
                    $key,['draft_id'=>$target]);
        }
        $json=wp_json_encode($planned,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json)||json_decode($json,true)!==$planned||
           !add_post_meta($target,'_elementor_data',wp_slash($json),true))
            return new WP_Error('wpml_section_localization_elementor_write_failed',
                'Draft retained',['draft_id'=>$target]);
        $payload_hash=hash('sha256',wp_json_encode($translations,JSON_UNESCAPED_UNICODE));
        foreach([
            '_mad4b_wpml_editorial_source_id'=>$source_id,
            '_mad4b_wpml_editorial_source_sha256'=>$expected_source_sha,
            '_mad4b_wpml_editorial_translation_payload_sha256'=>$payload_hash,
            '_mad4b_wpml_editorial_contentjob_id'=>$job_id,
            '_mad4b_wpml_editorial_contract'=>'mad4b.wpml.section-editorial-draft.v1'
        ] as $key=>$value)add_post_meta($target,$key,$value,true);
        $stored=self::layout($target);
        if($stored!==$planned||get_post_status($target)!=='draft')
            return new WP_Error('wpml_section_localization_draft_readback_failed',
                'Draft retained',['draft_id'=>$target]);
        $state=[
          'contract'=>'mad4b.wpml.section-editorial-draft.v1',
          'source_id'=>$source_id,'draft_id'=>$target,'language'=>$lang,
          'source_sha256'=>$expected_source_sha,
          'translation_payload_sha256'=>$payload_hash,
          'translated_literal_count'=>$visible,'native_content_job_id'=>$job_id,
          'wpml_link_verified'=>true,'native_ref_plan_issues'=>count($issues),
          'source_legacy_post_content_bytes_skipped'=>strlen((string)$source->post_content),
          'status'=>'draft','published'=>false,
          'production_mutation_performed'=>false
        ];
        return $state;
    }

    /**
     * Existing WPML ContentJob source 355: publish a localized authored
     * Elementor template only after exact content, native provider refs,
     * generated CSS and WPML family identity readback. Does not complete the
     * commercial ContentJob or claim frontend interaction/browser proof.
     */
    public static function publish_localized_template(
        int $source_id,string $lang,array $translations
    ):array|WP_Error {
        if(!self::enabled()||!self::source($source_id)||
           !class_exists('MAD4B_SCP_Policy')||
           !MAD4B_SCP_Policy::can_mutate()||!current_user_can('manage_options'))
            return new WP_Error('wpml_section_publish_operator_not_authorized');
        $source=get_post($source_id);
        if(!$source)return new WP_Error('wpml_section_publish_source_missing');
        $policy=MAD4B_WPML_Design_Reconciliation_V1::required_languages_readback();
        if(!$policy['consistent']||!in_array($lang,$policy['required'],true)||
           $lang===$policy['default'])
            return new WP_Error('wpml_section_publish_locale_invalid');
        $target_id=(int)apply_filters('wpml_object_id',$source_id,
            $source->post_type,false,$lang);
        $target=$target_id?get_post($target_id):null;
        $trid=(int)apply_filters('wpml_element_trid',null,$source_id,
            'post_'.$source->post_type);
        if(!$target||$target->post_status!=='draft'||
           $target->post_type!==$source->post_type||
           self::language($target_id)!==$lang||
           !$trid||(int)apply_filters('wpml_element_trid',null,$target_id,
             'post_'.$source->post_type)!==$trid)
            return new WP_Error('wpml_section_publish_draft_or_trid_invalid');
        $analysis=MAD4B_WPML_Translation_Need_V1::source($source_id);
        if(is_wp_error($analysis)||
           ($analysis['classification']??'')!=='translation_required'||
           (int)($analysis['visible_literals']??0)!==count($translations)||
           (string)get_post_meta($target_id,
              '_mad4b_wpml_editorial_source_sha256',true)!==
              (string)$analysis['audited_source_sha256']||
           get_post_meta($target_id,'_mad4b_wpml_editorial_contract',true)!==
               'mad4b.wpml.section-editorial-draft.v1')
            return new WP_Error('wpml_section_publish_source_provenance_changed');
        $hash=hash('sha256',wp_json_encode($translations,JSON_UNESCAPED_UNICODE));
        if(!hash_equals((string)get_post_meta($target_id,
                '_mad4b_wpml_editorial_translation_payload_sha256',true),$hash))
            return new WP_Error('wpml_section_publish_translation_digest_changed');
        $src=self::layout($source_id);$local=self::layout($target_id);
        $observed=[];$index=static function($nodes,&$map)use(&$index){
            foreach((array)$nodes as $node){
                if(!is_array($node))continue;
                $id=(string)($node['id']??'');
                if($id!==''){
                    if(isset($map[$id]))$map['__duplicate_widget__']=true;
                    $map[$id]=(array)($node['settings']??[]);
                }
                if(!empty($node['elements']))$index($node['elements'],$map);
            }
        };
        $left=[];$right=[];$index($src,$left);$index($local,$right);
        if(isset($left['__duplicate_widget__'])||isset($right['__duplicate_widget__'])||
           count($translations)===0)
            return new WP_Error('wpml_section_publish_widget_index_invalid');
        foreach($translations as $key=>$value){
            $parts=explode('|',(string)$key,2);
            if(count($parts)!==2||!is_array($value))return new WP_Error(
                'wpml_section_publish_translation_key_invalid');
            [$widget,$field]=$parts;
            if(($left[$widget][$field]??null)!==($value['source']??null)||
               ($right[$widget][$field]??null)!==($value['translated']??null)||
               ($value['source']??'')===($value['translated']??''))
                return new WP_Error('wpml_section_publish_literal_not_localized');
            $observed[$key]=true;
        }
        if(count($observed)!==count($translations))
            return new WP_Error('wpml_section_publish_literal_count_mismatch');
        $graph=class_exists('MAD4B_Hub_Field_Fusion_V1')
            ?MAD4B_Hub_Field_Fusion_V1::semantic_shortcode_graph([$target_id]):null;
        if(!is_array($graph)||
           ($graph['listing_reference_validation']['invalid_count']??1)!==0||
           !empty($graph['coverage']['truncated'])||
           ($graph['unregistered_or_uncallable_used_tag_count']??0)>0)
            return new WP_Error('wpml_section_publish_native_dependencies_unverified');
        if(str_contains((string)get_post_meta($target_id,'_elementor_data',true),
                'mad4b-wpml-needs-translation'))
            return new WP_Error('wpml_section_publish_hidden_pending_widgets');
        if(!class_exists('Elementor\Core\Files\CSS\Post'))
            return new WP_Error('wpml_section_publish_css_owner_unavailable');
        $css=new Elementor\Core\Files\CSS\Post($target_id);
        $path=$css->get_path();
        $meta=get_post_meta($target_id,'_elementor_css',true);
        $compiled=$css->get_content();
        if(!is_array($meta)||($meta['status']??'')!=='file'||
           !is_file($path)||strlen($compiled)<100||
           hash('sha256',$compiled)!==hash_file('sha256',$path))
            return new WP_Error('wpml_section_publish_css_native_readback_failed');
        $job_id=(string)get_post_meta($target_id,
            '_mad4b_wpml_editorial_contentjob_id',true);
        $job=$job_id!==''?MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]):null;
        if(is_wp_error($job)||!is_array($job)||
           !in_array($job['job']['state']??'',['NEW','QUEUED','RUNNING'],true))
            return new WP_Error('wpml_section_publish_existing_contentjob_missing');
        $changed=wp_update_post(['ID'=>$target_id,'post_status'=>'publish'],true);
        if(is_wp_error($changed)||get_post_status($target_id)!=='publish')
            return new WP_Error('wpml_section_publish_native_wp_status_failed');
        $family=MAD4B_WPML_Design_Reconciliation_V1::family_coverage_readback($source_id);
        $locale=$family['by_language'][$lang]??[];
        if(($locale['state']??'')!=='PUBLISHED'||
           !empty($locale['missing_native_configuration'])||
           !empty($locale['unresolved_reference_count'])){
            wp_update_post(['ID'=>$target_id,'post_status'=>'draft']);
            return new WP_Error('wpml_section_publish_family_readback_rollback');
        }
        $cssAfter=class_exists('MAD4B_Elementor_CSS_Native_Reconcile_CLI')
            ?MAD4B_Elementor_CSS_Native_Reconcile_CLI::after_wpml_publication($target_id)
            :new WP_Error('mad4b_original_css_owner_not_loaded');
        if(is_wp_error($cssAfter)){
            wp_update_post(['ID'=>$target_id,'post_status'=>'draft']);
            return new WP_Error('wpml_section_publish_css_readback_draft_restored',
                $cssAfter->get_error_code());
        }
        $receipt=[
            'contract'=>'mad4b.wpml.section-editorial-publish.v1',
            'source_id'=>$source_id,'target_id'=>$target_id,'language'=>$lang,
            'translated_literal_count'=>count($translations),
            'wpml_family_readback'=>'PASS','native_listing_validation'=>'PASS',
            'compiled_css_sha256'=>$cssAfter['expected_sha256'],
            'css_after_publication'=>$cssAfter,
            'content_job_id'=>$job_id,'content_job_completed'=>false,
            'frontend_interaction_verified'=>false,'status'=>'publish',
            'production_mutation_performed'=>false
        ];
        $receipt['receipt_sha256']=hash('sha256',
            wp_json_encode($receipt,JSON_UNESCAPED_UNICODE));
        add_post_meta($target_id,'_mad4b_wpml_editorial_publish_receipt_sha256',
            $receipt['receipt_sha256'],true);
        do_action('litespeed_purge_post',$target_id);
        return $receipt;
    }

    /**
     * Original ContentJob owner: exact limited translations inside a current
     * published WPML sibling. Never bulk-copy the default language Elementor
     * tree, preserve local overrides and dynamic metadata/conditions.
     */
    public static function localize_existing_widget_literals(int $source,
        int $target,array $literal_map):array|WP_Error {
        if(!self::enabled()||!self::source($source)||
           !class_exists('MAD4B_SCP_Policy')||
           !MAD4B_SCP_Policy::can_mutate()||
           !current_user_can('edit_post',$target))
           return new WP_Error('mad4b_wpml_existing_locale_actor_denied');
        $a=get_post($source);$b=get_post($target);
        $language=self::language($target);
        if(!$a||!$b||$a->post_type!==$b->post_type||
           $b->post_status!=='publish'||
           !$language||$language===self::default_language()||
           (int)apply_filters('wpml_object_id',$source,$a->post_type,
               false,$language)!==$target||
           !function_exists('mad4b_wpml_auto_sync_allowed_v1')||
           !mad4b_wpml_auto_sync_allowed_v1($source,$target))
           return new WP_Error('mad4b_wpml_existing_locale_identity_invalid');
        if(!$literal_map||count($literal_map)>10)
           return new WP_Error('mad4b_wpml_existing_locale_literal_scope_invalid');
        $source_tree=self::layout($source);
        $target_tree=self::layout($target);
        $source_index=[];$index=function($nodes)use(&$index,&$source_index){
            foreach((array)$nodes as $node){
                if(!is_array($node))continue;
                $id=(string)($node['id']??'');
                if($id!=='')$source_index[$id]=(array)($node['settings']??[]);
                if(!empty($node['elements']))$index($node['elements']);
            }
        };
        $index($source_tree);
        $changed=0;$found=[];$out=$target_tree;
        $walk=function(&$nodes)use(&$walk,$literal_map,$source_index,
            &$changed,&$found){
            foreach($nodes as &$node){
                if(!is_array($node))continue;
                $id=(string)($node['id']??'');
                foreach((array)($node['settings']??[]) as $key=>$existing){
                    $token=$id.'|'.$key;
                    if(!array_key_exists($token,$literal_map))continue;
                    if(isset($found[$token]))throw new RuntimeException('DUPLICATE_NODE');
                    $found[$token]=true;
                    $entry=$literal_map[$token];
                    $source_value=$source_index[$id][$key]??null;
                    if(!is_array($entry)||!is_string($source_value)||
                       $source_value!==($entry['source']??null)||
                       !is_string($existing)||
                       $existing!==$source_value||
                       !is_string($entry['translated']??null)||
                       trim($entry['translated'])===''||
                       $entry['translated']===$source_value||
                       strlen($entry['translated'])>350||
                       preg_match('/<[^>]+>/', $entry['translated'])||
                       isset($node['settings']['__dynamic__'][$key])||
                       ($node['settings']['mad4b_wpml_sync_mode']??'')==='detach')
                       throw new RuntimeException('CHANGED_OR_OWNED_LOCALE_TEXT');
                    $node['settings'][$key]=$entry['translated'];$changed++;
                }
                if(!empty($node['elements']))$walk($node['elements']);
            }
        };
        try{$walk($out);}catch(Throwable $e){
            return new WP_Error('mad4b_wpml_existing_locale_preservation_denied',
                $e->getMessage());
        }
        if($changed!==count($literal_map)||count($found)!==count($literal_map))
            return new WP_Error('mad4b_wpml_existing_locale_unmatched_literals');
        $raw=(string)get_post_meta($target,'_elementor_data',true);
        $before=hash('sha256',$raw);
        $backup='mad4b_wpml_existing_locale_text_'.$target.'_'.substr($before,0,18);
        if(get_option($backup,null)===null&&!add_option($backup,
            ['source_id'=>$source,'target_id'=>$target,'original'=>$raw,
             'language'=>$language,'at'=>gmdate('c')],'',false))
            return new WP_Error('mad4b_wpml_existing_locale_backup_failed');
        $json=wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json)||json_decode($json,true)!==$out||
           !update_post_meta($target,'_elementor_data',wp_slash($json)))
            return new WP_Error('mad4b_wpml_existing_locale_write_failed');
        if(self::layout($target)!==$out){
            update_post_meta($target,'_elementor_data',wp_slash($raw));
            return new WP_Error('mad4b_wpml_existing_locale_readback_rollback');
        }
        clean_post_cache($target);
        do_action('litespeed_purge_post',$target);
        return ['contract'=>'mad4b.wpml.existing-locale-widget-literals.v1',
            'source_id'=>$source,'target_id'=>$target,'language'=>$language,
            'literal_fields_changed'=>$changed,'backup_option'=>$backup,
            'before_sha256'=>$before,
            'after_sha256'=>hash('sha256',(string)get_post_meta($target,'_elementor_data',true)),
            'readback_verified'=>true,
            'unrelated_elementor_widgets_preserved'=>true,
            'production_mutation_performed'=>false];
    }

    public static function request($id): void {
        $id=(int)$id;
        if(self::$busy||!self::enabled())return;
        if(!self::source($id)){
            $p=get_post($id);
            if(!$p||self::language($id)===self::default_language())return;
            // Readback of an edited translation must rescan its original English
            // source, otherwise native job closure might remain stale.
            $original=(int)apply_filters('wpml_object_id',$id,$p->post_type,false,
                self::default_language());
            if(!$original||!self::source($original))return;
            $id=$original;
        }
        $queue=get_option('mad4b_wpml_section_jobs_queue_v1',[]);
        if(!is_array($queue))$queue=[];
        $queue[$id]=time();
        if(count($queue)>self::LIMIT)$queue=array_slice($queue,-self::LIMIT,null,true);
        update_option('mad4b_wpml_section_jobs_queue_v1',$queue,false);
        if(!wp_next_scheduled(self::EVENT))wp_schedule_single_event(time()+125,self::EVENT);
    }
    public static function meta_change($mid,$post_id,$key): void {
        if($key==='_elementor_data')self::request($post_id);
    }
    public static function drain(): void {
        if(!self::enabled())return;
        $queue=get_option('mad4b_wpml_section_jobs_queue_v1',[]);
        if(!is_array($queue)||!$queue)return;
        $batch=array_slice($queue,0,8,true);
        foreach($batch as $id=>$at){
            self::reconcile((int)$id,true);
            unset($queue[$id]);
        }
        update_option('mad4b_wpml_section_jobs_queue_v1',$queue,false);
        if($queue&&!wp_next_scheduled(self::EVENT))
            wp_schedule_single_event(time()+60,self::EVENT);
        // Normal WP-Cron does not impersonate the site owner.
        // Dispatch happens only for a genuinely authorized principal.
        if(class_exists('MAD4B_SCP_Policy')&&MAD4B_SCP_Policy::can_mutate()===true)
            self::dispatch(2);
    }
    public static function eligible_types(): array {
        $types=get_post_types(['public'=>true],'names');
        foreach(['elementor_library','elementor-hf','jet-engine','jet-smart-filters',
                 'jet-woo-builder'] as $type)
            if(post_type_exists($type))$types[]=$type;
        return array_values(array_unique($types));
    }
    public static function daily(): void {
        if(!self::enabled())return;
        $offset=(int)get_option('mad4b_wpml_section_jobs_scan_offset_v1',0);
        $posts=get_posts(['post_type'=>self::eligible_types(),'post_status'=>'publish','posts_per_page'=>45,
            'offset'=>$offset,'orderby'=>'ID','order'=>'ASC','fields'=>'ids',
            'suppress_filters'=>true,'meta_query'=>[['key'=>'_elementor_data','compare'=>'EXISTS']]]);
        foreach($posts as $id)if(self::source((int)$id))self::reconcile((int)$id,true);
        update_option('mad4b_wpml_section_jobs_scan_offset_v1',
            count($posts)===45?$offset+45:0,false);
    }
    public static function admin_authorized(): void {
        if(!self::enabled()||!current_user_can('manage_options'))return;
        if(!class_exists('MAD4B_SCP_Policy')||MAD4B_SCP_Policy::can_mutate()!==true)return;
        $key='mad4b_wpml_section_jobs_last_admin_dispatch_v1';
        if(time()-(int)get_transient($key)<120)return;
        set_transient($key,time(),120);
        self::dispatch(2);
    }
    public static function cli($args): void {
        $mode=$args[0]??'status';
        if($mode==='status'){
            $jobs=self::outbox();$counts=[];
            foreach($jobs as $j){$code=$j['state']??'unknown';$counts[$code]=($counts[$code]??0)+1;}
            \WP_CLI::line(wp_json_encode(['contract'=>'mad4b.wpml-section-contentjobs.v1',
                'stored'=>count($jobs),'states'=>$counts,
                'queued_source_changes'=>count((array)get_option('mad4b_wpml_section_jobs_queue_v1',[])),
                'native_auto_publish'=>false]));return;
        }
        if($mode==='preview'){
            $id=(int)($args[1]??0);
            if($id<1)\WP_CLI::error('Specify a source post ID');
            \WP_CLI::line(wp_json_encode(['reconcile'=>self::reconcile($id,false),
                'missing_sections'=>self::analyze($id)]));return;
        }
        if($mode==='scan'){
            $id=(int)($args[1]??0);
            if($id<1)\WP_CLI::error('Specify a source post ID');
            \WP_CLI::line(wp_json_encode(self::reconcile($id,true)));return;
        }
        if($mode==='dispatch'){
            $r=self::dispatch(2);if(is_wp_error($r))\WP_CLI::error($r->get_error_code());
            \WP_CLI::line(wp_json_encode($r));return;
        }
        \WP_CLI::error('Usage: wp mad4b-section-jobs status|preview <id>|scan <id>|dispatch');
    }
    public static function boot(): void {
        add_action('updated_post_meta',[__CLASS__,'meta_change'],110,3);
        add_action('added_post_meta',[__CLASS__,'meta_change'],110,3);
        add_action('elementor/editor/after_save',
            static function($id){self::request((int)$id);},115,2);
        add_action('save_post',static function($id){self::request($id);},116);
        add_action(self::EVENT,[__CLASS__,'drain']);
        add_action(self::SCAN_EVENT,[__CLASS__,'daily']);
        add_action('init',static function(){
            // Do not schedule a cron record into an uninstalled tenant.
            if(function_exists('is_blog_installed') && !is_blog_installed())return;
            if(!wp_next_scheduled(self::SCAN_EVENT))
                wp_schedule_event(time()+1200,'hourly',self::SCAN_EVENT);
        });
        add_action('admin_init',[__CLASS__,'admin_authorized'],90);
        if(defined('WP_CLI')&&WP_CLI)\WP_CLI::add_command('mad4b-section-jobs',[__CLASS__,'cli']);
    }
}
add_action('plugins_loaded',['MAD4B_WPML_Section_Content_Jobs','boot'],85);
