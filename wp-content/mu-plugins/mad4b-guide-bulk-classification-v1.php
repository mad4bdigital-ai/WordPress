<?php
/**
 * Plugin Name: MAD4B Guide Days Bulk Classification
 * Description: Human-reviewed, source-backed bulk proposals for canonical Tour and Program guide days. No price changes.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Guide_Bulk_Classification_V1 {
    const AJAX_INDEX = 'mad4b_guide_bulk_index_v1';
    const AJAX_DETAIL = 'mad4b_guide_bulk_detail_v1';
    const AJAX_APPROVE = 'mad4b_guide_bulk_approve_v1';
    const NONCE = 'mad4b_guide_bulk_review_v1';
    const PAGE = 'mad4b-guide-bulk-days';
    private static string $hook='';

    public static function permitted(): bool {
        return class_exists('MAD4B_Guide_Service_Composition_V1',false)
            && MAD4B_Guide_Service_Composition_V1::pricingPermitted()
            && current_user_can('edit_posts');
    }
    private static function fail(string $code,int $status=400): never {
        wp_send_json_error(['reason'=>$code],$status);
    }
    private static function secured(): void {
        if (!self::permitted()) self::fail('guide_bulk_pricing_permission_required',403);
        if (!check_ajax_referer(self::NONCE,'nonce',false)) self::fail('guide_bulk_nonce_invalid',403);
    }

    /** One source per English WPML tour or shared cruise program. No translated duplicates. */
    public static function inventory(): array {
        if (!class_exists('MAD4B_Guide_Service_Composition_V1',false)) return [];
        $core='MAD4B_Guide_Service_Composition_V1';
        $ids=get_posts([
            'post_type'=>'tours-and-activities','post_status'=>'publish',
            'fields'=>'ids','numberposts'=>-1,'suppress_filters'=>true,
            'orderby'=>'ID','order'=>'ASC',
        ]);
        $seenTours=[];$sources=[];
        foreach ($ids as $rawId) {
            $tour=$core::canonical((int)$rawId,'tours-and-activities');
            if (!$tour || isset($seenTours[$tour]) ||
                get_post_type($tour)!=='tours-and-activities' ||
                get_post_status($tour)!=='publish') continue;
            $seenTours[$tour]=true;
            $mode=(string)get_post_meta($tour,'tour_mode',true);
            $programs=[];
            if ($mode==='cruise_tour') {
                $terms=wp_get_post_terms($tour,'programs',['fields'=>'ids']);
                if (!is_wp_error($terms)) $programs=$terms;
                $related=get_post_meta($tour,'related_program_terms',true);
                if (is_string($related)) $related=maybe_unserialize($related);
                if (is_array($related)) foreach ($related as $termId) {
                    if (is_scalar($termId) && ctype_digit((string)$termId)) {
                        $programs[]=(int)$termId;
                    }
                }
                $programs=array_values(array_unique(array_filter(array_map(
                    static fn($id)=>$core::canonical((int)$id,'programs'),$programs
                ))));
            }
            if (!$programs) $programs=[0];
            foreach ($programs as $program) {
                if ($program && !term_exists($program,'programs')) continue;
                $key=$program?'term:'.$program:'post:'.$tour;
                if (isset($sources[$key])) {
                    $sources[$key]['linked_tours']++;
                    continue;
                }
                $label=$program?get_term($program,'programs'):null;
                $name=$label instanceof WP_Term?$label->name:get_the_title($tour);
                $sources[$key]=[
                    'key'=>$key,'tour_id'=>$tour,'program_id'=>$program,
                    'kind'=>$program?'program':'tour','title'=>wp_strip_all_tags((string)$name),
                    'linked_tours'=>1,
                ];
            }
        }
        return array_values($sources);
    }

    private static function context(int $tour,int $program=0): array|WP_Error {
        if (!self::permitted()) return new WP_Error('guide_bulk_permission_denied');
        $core='MAD4B_Guide_Service_Composition_V1';
        if ($tour<1 || !current_user_can('edit_post',$tour) ||
            get_post_type($tour)!=='tours-and-activities' ||
            get_post_status($tour)!=='publish' ||
            $core::canonical($tour,'tours-and-activities')!==$tour) {
            return new WP_Error('guide_bulk_tour_invalid');
        }
        if ($program && (!term_exists($program,'programs') ||
            !current_user_can('edit_term',$program) ||
            $core::canonical($program,'programs')!==$program)) {
            return new WP_Error('guide_bulk_program_invalid');
        }
        return $core::source($tour,$program);
    }
    private static function plain(string $v): string {
        $v=html_entity_decode(wp_strip_all_tags($v),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $v=preg_replace('/\s+/u',' ',trim($v));
        return is_string($v)?$v:'';
    }
    private static function match(string $pattern,string $text): ?string {
        if (@preg_match($pattern,$text,$hit,PREG_OFFSET_CAPTURE)!==1) return null;
        $start=max(0,(int)$hit[0][1]-48);
        return mb_strcut($text,$start,165,'UTF-8');
    }
    /** Every suggestion carries a witnessed text span, never a free invented claim. */
    public static function classify(array $day,array $row): array {
        $title=self::plain((string)($row['title']??''));
        $desc=self::plain((string)($row['description']??''));
        $text=trim($title.' '.$desc);
        if ($text==='') return self::proposal('unknown','none','empty_day_text','');
        $negative=self::match('~\b(?:no\s+(?:tour\s+)?guide\s+(?:is\s+)?(?:required|provided|included|needed)|without\s+(?:a\s+)?guide|self[- ]guided|unguided)\b|(?:بدون\s+مرشد|لا\s+يوجد\s+مرشد)~iu',$text);
        $optional=self::match('~\b(?:optional|on\s+request)\s+(?:private\s+)?(?:tour\s+)?guid(?:e|ed)\b|\b(?:guide|guided\s+tour)\s*\(optional\)|(?:مرشد\s+اختياري)~iu',$text);
        $full=self::match('~\b(?:full[- ]day\s+(?:private\s+)?(?:guided\s+tour|guide|with\s+(?:a\s+)?guide)|(?:guided\s+tour|guide)\s+(?:for\s+the\s+)?full\s+day)\b~iu',$text);
        $half=self::match('~\b(?:half[- ]day\s+(?:private\s+)?(?:guided\s+tour|guide|with\s+(?:a\s+)?guide)|(?:guided\s+tour|guide)\s+(?:for\s+the\s+)?half\s+day)\b~iu',$text);
        $guided=self::match('~\b(?:guided\s+(?:tour|visit|excursion)|(?:private|personal|licensed|english[- ]speaking)\s+(?:tour\s+)?guide|guide\s+will\s+(?:take|accompany|escort)|egyptologist\s+guide|tour\s+guide)\b|(?:مع\s+مرشد\s+سياحي|جولة\s+برفقة\s+مرشد|visite\s+guidée|mit\s+Reiseleiter|visita\s+guiada)~iu',$text);
        $hasGuidance=(bool)($full||$half||$guided);
        if ($negative && ($hasGuidance||$optional)) {
            return self::proposal('unknown','conflict','guide_present_and_absent',$negative);
        }
        if ($full && $half) return self::proposal('unknown','conflict','full_and_half_both_mentioned',$full);
        if ($negative) return self::proposal('none','high','explicit_no_guide',$negative);
        if ($optional && !$full && !$half && !$guided) return self::proposal('optional','high','explicit_optional_guide',$optional);
        if ($optional && $hasGuidance) return self::proposal('unknown','conflict','optional_and_included_guidance',$optional);
        if ($full) return self::proposal('full_day','high','explicit_full_day_guided',$full);
        if ($half) return self::proposal('half_day','high','explicit_half_day_guided',$half);
        if ($guided) return self::proposal('full_day','medium','guide_mentioned_duration_unverified',$guided);
        $transfer=self::match('~\b(?:arrival|departure|airport\s+transfer|hotel\s+check[- ]in|free\s+day|day\s+at\s+leisure|transfer\s+to\s+(?:the\s+)?airport)\b~iu',$title);
        $sightseeing=(bool)self::match('~\b(?:pyramids|temple|museum|sightseeing|excursion|visit|tour|attractions|monument|west\s+bank|east\s+bank)\b~iu',$text);
        if ($transfer && !$sightseeing) return self::proposal('none','low','arrival_or_transfer_without_explicit_guide',$transfer);
        return self::proposal('unknown','none','guide_service_not_explicit',$title?:mb_substr($desc,0,100));
    }
    /**
     * Independent source proof for exactly ONE day. Both the published
     * Included services and the duration taxonomy must establish a full
     * guided one-day program. A mere activity title or mentions of a
     * guide are not enough to approve a billable full day.
     */
    private static function includedGuideDay(array $source,array $candidate): array {
        if ($source['source_kind']!=='post' ||
            $source['day_unit_basis']!=='explicit_single_day_duration_taxonomy' ||
            in_array($candidate['confidence'],['high','conflict'],true)) return $candidate;
        $duration=array_map(static fn($p)=>strtolower(trim((string)($p['label']??''))),
            (array)($source['duration_proof']??[]));
        $full=array_filter($duration,static fn($v)=>
            in_array($v,['1 day','one day','full-day','full day'],true));
        if (!$full || in_array('half day',$duration,true) ||
            in_array('half-day',$duration,true)) return $candidate;
        $includes=get_post_meta((int)$source['tour_id'],'includes',true);
        if (!is_array($includes)) return $candidate;
        $guide=null;$guided=null;
        foreach ($includes as $item) {
            if (!is_array($item))continue;
            $text=self::plain((string)($item['included_item']??''));
            if ($text==='')continue;
            if (preg_match('~\b(?:professional|licensed|egyptologist)\b.{0,55}\bguide\b~iu',$text)) {
                $guide=$text;
            }
            if (preg_match('~\bguided\s+(?:tour|visit|excursion|sightseeing)\b~iu',$text)) {
                $guided=$text;
            }
        }
        if ($guide===null || $guided===null) return $candidate;
        return self::proposal('full_day','high','guide_and_guided_visit_included_with_one_day_duration',
            'Duration: '.implode(', ',$duration).' | Included: '.$guide.' | '.$guided);
    }

    private static function proposal(string $status,string $confidence,string $reason,string $evidence): array {
        return [
            'suggested_status'=>$status,'confidence'=>$confidence,'reason'=>$reason,
            'evidence'=>mb_substr($evidence,0,180),
            'bulk_eligible'=>$confidence==='high' && in_array($status,['full_day','half_day','none','optional'],true),
        ];
    }
    public static function detail(int $tour,int $program=0): array|WP_Error {
        $source=self::context($tour,$program);
        if (is_wp_error($source)) return $source;
        $core='MAD4B_Guide_Service_Composition_V1';
        $saved=$core::saved($source);
        if (is_wp_error($saved) && $saved->get_error_code()!=='guide_coverage_not_reviewed') {
            return new WP_Error('guide_bulk_existing_review_stale',$saved->get_error_code());
        }
        $states=is_wp_error($saved)?[]:$saved['statuses'];
        $raw=$source['source_kind']==='term'
            ?get_term_meta($source['source_id'],'program_itinerary',true)
            :get_post_meta($source['source_id'],'itinerary_item',true);
        if (in_array($source['day_unit_basis'],['explicit_one_day_aggregated_stages', 'explicit_single_day_duration_taxonomy'],true)) {
            $stageText=[];
            foreach ((array)$raw as $stage) {
                if (!is_array($stage)) continue;
                $stageText[]=self::plain((string)($stage['title']??'')).' — '.
                    self::plain((string)($stage['description']??''));
            }
            $raw=['day-1'=>[
                'title'=>get_the_title($source['tour_id']),
                'description'=>mb_substr(implode(' | ',$stageText),0,14000)
            ]];
        }
        $drafts=class_exists('MAD4B_Guide_JetEngine_Inner_V1',false)
            ?MAD4B_Guide_JetEngine_Inner_V1::draftForSource($source):[];
        $rows=[];$high=0;$unresolved=0;
        foreach ($source['days'] as $key=>$day) {
            $proposal=self::classify($day,(array)($raw[$key]??[]));
            $proposal=self::includedGuideDay($source,$proposal);
            if ($proposal['bulk_eligible'] && ($states[$key]??'unknown')==='unknown') $high++;
            if (($states[$key]??'unknown')==='unknown') $unresolved++;
            $rows[]=$day+$proposal+[
                'saved_status'=>$states[$key]??'unknown',
                'jetengine_draft_status'=>$drafts[$key]??'unknown',
                'jetengine_draft_is_cost_approved'=>false,
            ];
        }
        return [
            'contract'=>'mad4b.guide-bulk-detail.v1',
            'tour_id'=>$tour,'program_id'=>$program,'source_key'=>$source['source_kind'].':'.$source['source_id'],
            'source_hash'=>$source['source_hash'],'source_kind'=>$source['source_kind'],
            'source_id'=>$source['source_id'],'day_unit_basis'=>$source['day_unit_basis'],
            'duration_proof'=>$source['duration_proof']??null,
            'revision'=>is_wp_error($saved)?0:(int)$saved['revision'],
            'half_day_factor'=>is_wp_error($saved)?null:$saved['half_day_factor'],
            'days'=>$rows,'day_count'=>count($rows),
            'high_new_candidates'=>$high,'unresolved_days'=>$unresolved,
            'review_state'=>$unresolved?'incomplete':'fully_reviewed',
            'commercial_price_changed'=>false,'checkout_ready'=>false,
        ];
    }
    public static function index(int $page=0,int $pageSize=12): array {
        $all=self::inventory();$page=max(0,min(9999,$page));$pageSize=max(1,min(25,$pageSize));
        $slice=array_slice($all,$page*$pageSize,$pageSize);
        $out=[];
        foreach ($slice as $entry) {
            if (!current_user_can('edit_post',$entry['tour_id']) ||
                ($entry['program_id'] && !current_user_can('edit_term',$entry['program_id']))) continue;
            $detail=self::detail($entry['tour_id'],$entry['program_id']);
            if (is_wp_error($detail)) {
                $out[]=$entry+[
                    'status'=>'blocked','block_reason'=>$detail->get_error_code(),
                    'high_new_candidates'=>0,'day_count'=>null,'unresolved_days'=>null,
                    'revision'=>0,'source_hash'=>null
                ];
                continue;
            }
            $out[]=$entry+[
                'status'=>$detail['review_state'],
                'block_reason'=>null,'day_count'=>$detail['day_count'],
                'unresolved_days'=>$detail['unresolved_days'],
                'high_new_candidates'=>$detail['high_new_candidates'],
                'revision'=>$detail['revision'],'source_hash'=>$detail['source_hash']
            ];
        }
        return [
            'contract'=>'mad4b.guide-bulk-index.v1','page'=>$page,'page_size'=>$pageSize,
            'total_sources'=>count($all),'total_pages'=>(int)ceil(count($all)/$pageSize),
            'sources'=>$out,'no_auto_write'=>true,'commercial_activation'=>false,
        ];
    }

    /** Read, reclassify and validate under lock. Client proposals are not trusted. */
    public static function approve(array $request): array|WP_Error {
        if (!self::permitted()) return new WP_Error('guide_bulk_permission_denied');
        $tour=filter_var($request['tour_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $program=filter_var($request['program_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if (!$tour || $program===false) return new WP_Error('guide_bulk_identifiers_invalid');
        $mode=(string)($request['mode']??'');
        if (!in_array($mode,['high_only','manual_review'],true)) return new WP_Error('guide_bulk_mode_invalid');
        $submittedHash=$request['source_hash']??'';
        if (!is_string($submittedHash)||!preg_match('/^[a-f0-9]{64}$/D',$submittedHash)) {
            return new WP_Error('guide_bulk_source_hash_invalid');
        }
        $revision=filter_var($request['revision']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>0]]);
        if ($revision===false || $revision===null) return new WP_Error('guide_bulk_revision_invalid');
        $core='MAD4B_Guide_Service_Composition_V1';
        $source=self::context((int)$tour,(int)$program);
        if (is_wp_error($source)) return $source;
        if (!hash_equals($source['source_hash'],$submittedHash)) {
            return new WP_Error('guide_bulk_source_changed_since_preview');
        }
        $lock='mad4b_guide_bulk_lock_'.substr(hash('sha256',$source['source_kind'].':'.$source['source_id']),0,32);
        $priorLock=get_option($lock,null);
        if (is_array($priorLock) && time()-(int)($priorLock['at']??time())>120) {
            // A failed PHP request must not lock source reviews forever.
            delete_option($lock);
        }
        if (!add_option($lock,['user'=>get_current_user_id(),'at'=>time()],'',false)) {
            return new WP_Error('guide_bulk_source_locked');
        }
        try {
            // Re-check after lock (same-day edits or another operator).
            $fresh=self::context((int)$tour,(int)$program);
            if (is_wp_error($fresh) ||
                $fresh['source_hash']!==$source['source_hash']) {
                return new WP_Error('guide_bulk_source_changed_during_review');
            }
            $old=$core::saved($fresh);
            if (is_wp_error($old) &&
                $old->get_error_code()!=='guide_coverage_not_reviewed') {
                return new WP_Error('guide_bulk_existing_coverage_conflict');
            }
            $actualRevision=is_wp_error($old)?0:(int)$old['revision'];
            if ($actualRevision!==$revision) return new WP_Error('guide_bulk_review_revision_conflict');
            $rows=self::detail((int)$tour,(int)$program);
            if (is_wp_error($rows)) return $rows;
            $existing=is_wp_error($old)?[]:$old['statuses'];
            $statuses=[];
            foreach ($fresh['days'] as $key=>$day) $statuses[$key]=$existing[$key]??'unknown';
            $changed=[];$evidence=[];
            $factor=is_wp_error($old)?null:$old['half_day_factor'];
            if ($mode==='high_only') {
                foreach ($rows['days'] as $day) {
                    $key=$day['key'];
                    if ($statuses[$key]!=='unknown' || !$day['bulk_eligible']) continue;
                    if ($day['suggested_status']==='half_day' && $factor===null) continue;
                    $statuses[$key]=$day['suggested_status'];
                    $changed[$key]=$day['suggested_status'];
                    $evidence[$key]=['reason'=>$day['reason'],'quote'=>$day['evidence'],
                        'classification'=>'explicit_high_confidence_text_review'];
                }
            } else {
                $chosen=$request['decisions']??null;
                if (!is_array($chosen) ||
                    array_diff(array_keys($chosen),array_keys($statuses)) ||
                    array_diff(array_keys($statuses),array_keys($chosen))) {
                    return new WP_Error('guide_bulk_manual_day_set_mismatch');
                }
                $rawFactor=$request['half_day_factor']??'';
                if ($rawFactor!=='') {
                    if (!is_scalar($rawFactor) || !preg_match('/^(?:0\.[0-9]{1,4}|1(?:\.0{1,4})?)$/D',(string)$rawFactor) ||
                        (float)$rawFactor<=0) return new WP_Error('guide_bulk_half_factor_invalid');
                    $factor=(float)$rawFactor;
                }
                $note=trim(sanitize_textarea_field((string)($request['review_note']??'')));
                $needsNote=false;
                $proposals=array_column($rows['days'],null,'key');
                foreach ($chosen as $key=>$status) {
                    if (!is_string($status) || !in_array($status,$core::STATES,true)) {
                        return new WP_Error('guide_bulk_day_status_invalid');
                    }
                    if ($statuses[$key]===$status) continue;
                    $day=$proposals[$key];
                    if (!$day['bulk_eligible'] || $day['suggested_status']!==$status) $needsNote=true;
                    $statuses[$key]=$status;$changed[$key]=$status;
                    $evidence[$key]=['reason'=>$day['reason'],'quote'=>$day['evidence'],
                        'classification'=>'operator_reviewed'];
                }
                if ($needsNote && mb_strlen($note)<12) {
                    return new WP_Error('guide_bulk_manual_evidence_note_required');
                }
                if ($note!=='') $evidence['_review_note']=mb_substr($note,0,500);
            }
            if (!$changed) return new WP_Error('guide_bulk_no_eligible_changes');
            // A delegated WP-CLI source review is NOT falsely described as
            // a personal approval in the admin browser. Record both channel
            // and evidence so reviewers know where the decision originated.
            $approvalChannel=(defined('WP_CLI') && WP_CLI)
                ?'delegated_wp_cli_operator':'wordpress_admin_ajax';
            $delegationNote=trim(sanitize_textarea_field(
                (string)($request['review_note']??'')));
            if ($mode==='high_only' && $delegationNote!=='') {
                if (mb_strlen($delegationNote)<12) {
                    return new WP_Error('guide_bulk_delegation_note_too_short');
                }
                $evidence['_delegation_note']=mb_substr($delegationNote,0,500);
            }
            if (in_array('half_day',$statuses,true) && $factor===null) {
                return new WP_Error('guide_bulk_half_day_factor_required');
            }
            $record=[
                'schema'=>1,'source_hash'=>$fresh['source_hash'],
                'statuses'=>$statuses,'half_day_factor'=>$factor,
                'revision'=>$actualRevision+1,
                'reviewed_by'=>get_current_user_id(),
                'reviewed_at'=>gmdate('c'),
                'status'=>in_array('unknown',$statuses,true)?'bulk_partial_review':'bulk_reviewed',
                'review_method'=>$mode,
                'approval_channel'=>$approvalChannel,
                'review_evidence'=>$evidence,
                'prior_review_hash'=>is_wp_error($old)?null:hash('sha256',wp_json_encode($old)),
                'source_classification_contract'=>'mad4b.guide-bulk.v1',
            ];
            $id=(int)$fresh['source_id'];
            $isTerm=$fresh['source_kind']==='term';
            $written=$isTerm
                ?update_term_meta($id,$core::META,$record)
                :update_post_meta($id,$core::META,$record);
            if ($written===false) return new WP_Error('guide_bulk_write_failed');
            $read=$core::saved($fresh);
            if (is_wp_error($read) || $read['revision']!==$record['revision'] ||
                $read['statuses']!==$record['statuses']) {
                return new WP_Error('guide_bulk_readback_failed');
            }
            $audit=[
                'contract'=>'mad4b.guide-bulk-audit.v1','source_hash'=>$fresh['source_hash'],
                'revision'=>$record['revision'],'operator_id'=>get_current_user_id(),
                'at'=>$record['reviewed_at'],'changes'=>$changed,
                'evidence'=>$evidence,'mode'=>$mode,
                'approval_channel'=>$approvalChannel,
            ];
            $auditKey='_mad4b_guide_day_review_audit_v1';
            if ($isTerm) add_term_meta($id,$auditKey,$audit);
            else add_post_meta($id,$auditKey,$audit);
            return [
                'source_key'=>$fresh['source_kind'].':'.$id,
                'revision'=>$record['revision'],
                'changes'=>$changed,'changed_days'=>count($changed),
                'unknown_remaining'=>count(array_filter($statuses,static fn($s)=>$s==='unknown')),
                'status'=>$record['status'],'checkout_ready'=>false,
                'retail_rate_changed'=>false,
            ];
        } finally {
            delete_option($lock);
        }
    }
    public static function adminMenu(): void {
        self::$hook=(string)add_submenu_page(
            'edit.php?post_type=tours-and-activities',
            'Bulk Guide Days Classification','Guide Days · Bulk Review',
            'edit_posts',self::PAGE,[__CLASS__,'page']);
    }
    public static function page(): void {
        if (!self::permitted()) wp_die(esc_html__('Company pricing permission required'),'403',['response'=>403]);
        echo '<div class="wrap" id="mad4b-guide-bulk-app">';
        echo '<h1>Guide Days · Bulk Classification</h1>';
        echo '<p>Review canonical Tour and reusable Cruise Program days once per source. No inherited WPML duplicates. Text evidence is a suggestion, not supplier confirmation. Unreviewed days stay unknown; no Rate publication or Checkout change.</p>';
        echo '<div class="notice notice-info"><p>Bulk approval only applies explicit HIGH-confidence evidence. A simple sightseeing description, transfer, or arrival is never treated as proof of a full day of guide service. Unknown stages require human review.</p></div>';
        echo '<div class="mad4b-guide-bulk-toolbar">';
        echo '<button type="button" id="mad4b-guide-reload" class="button">Refresh sources</button> ';
        echo '<button type="button" id="mad4b-guide-bulk-high" class="button button-primary">Approve HIGH confidence on selected sources</button> ';
        echo '<span id="mad4b-guide-progress" role="status" aria-live="polite"></span>';
        echo '</div><div id="mad4b-guide-list"></div>';
        echo '<div class="mad4b-guide-bulk-pages"><button type="button" id="mad4b-guide-prev" class="button">Previous</button> ';
        echo '<span id="mad4b-guide-page-label"></span> ';
        echo '<button type="button" id="mad4b-guide-next" class="button">Next</button></div>';
        echo '<div id="mad4b-guide-detail" hidden></div></div>';
    }
    public static function enqueue(string $hook): void {
        if ($hook!==self::$hook || !self::permitted()) return;
        $file=WPMU_PLUGIN_DIR.'/assets/mad4b-guide-bulk-v1.js';
        if (!is_readable($file)) return;
        wp_enqueue_script('mad4b-guide-bulk-v1',content_url('mu-plugins/assets/mad4b-guide-bulk-v1.js'),
            [],(string)filemtime($file),true);
        wp_add_inline_script('mad4b-guide-bulk-v1','window.MAD4BGuideBulkV1='.wp_json_encode([
            'url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce(self::NONCE),
            'indexAction'=>self::AJAX_INDEX,'detailAction'=>self::AJAX_DETAIL,
            'approveAction'=>self::AJAX_APPROVE,
        ]).';','before');
        wp_register_style('mad4b-guide-bulk-v1-style',false,[], '1.0.0');
        wp_enqueue_style('mad4b-guide-bulk-v1-style');
        wp_add_inline_style('mad4b-guide-bulk-v1-style',
            '#mad4b-guide-bulk-app .mad4b-guide-bulk-toolbar{display:flex;gap:8px;align-items:center;margin:14px 0 12px;flex-wrap:wrap}'.
            '#mad4b-guide-list table{width:100%;background:#fff}#mad4b-guide-list td{vertical-align:top}'.
            '#mad4b-guide-detail{background:#fff;padding:18px;border:1px solid #c3c4c7;margin:16px 0;max-width:1200px}'.
            '#mad4b-guide-detail table{width:100%}#mad4b-guide-detail select{max-width:190px}'.
            '#mad4b-guide-detail .mad4b-evidence{max-width:420px;word-break:break-word;font-size:12px;color:#555}'.
            '#mad4b-guide-detail textarea{width:100%;max-width:730px}#mad4b-guide-detail td{vertical-align:top}'.
            '#mad4b-guide-bulk-app .mad4b-guide-bulk-pages{margin-top:12px;display:flex;align-items:center;gap:10px}'.
            '#mad4b-guide-bulk-app .mad4b-guide-bulk-note{font-size:12px;color:#666}');
    }
    public static function ajaxIndex(): void {
        self::secured();
        $page=absint($_POST['page']??0);
        wp_send_json_success(self::index($page,12));
    }
    public static function ajaxDetail(): void {
        self::secured();
        $tour=absint($_POST['tour_id']??0);$program=absint($_POST['program_id']??0);
        $detail=self::detail($tour,$program);
        if (is_wp_error($detail)) self::fail($detail->get_error_code(),409);
        wp_send_json_success($detail);
    }
    public static function ajaxApprove(): void {
        self::secured();
        $payload=(string)wp_unslash($_POST['payload']??'');
        if (strlen($payload)>24000) self::fail('guide_bulk_request_too_large');
        $raw=json_decode($payload,true);
        if (!is_array($raw)) self::fail('guide_bulk_request_invalid');
        $result=self::approve($raw);
        if (is_wp_error($result)) self::fail($result->get_error_code(),409);
        wp_send_json_success($result);
    }
}
add_action('admin_menu',[MAD4B_Guide_Bulk_Classification_V1::class,'adminMenu'],38);
add_action('admin_enqueue_scripts',[MAD4B_Guide_Bulk_Classification_V1::class,'enqueue'],38);
add_action('wp_ajax_'.MAD4B_Guide_Bulk_Classification_V1::AJAX_INDEX,
    [MAD4B_Guide_Bulk_Classification_V1::class,'ajaxIndex']);
add_action('wp_ajax_'.MAD4B_Guide_Bulk_Classification_V1::AJAX_DETAIL,
    [MAD4B_Guide_Bulk_Classification_V1::class,'ajaxDetail']);
add_action('wp_ajax_'.MAD4B_Guide_Bulk_Classification_V1::AJAX_APPROVE,
    [MAD4B_Guide_Bulk_Classification_V1::class,'ajaxApprove']);
