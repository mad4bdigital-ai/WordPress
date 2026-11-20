<?php
/**
 * Plugin Name: MAD4B Guide Service Composition
 * Description: Explicit itinerary guide-day service coverage and safe package cost shadow; no retail price authority.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Guide_Service_Composition_V1 {
    public const META = '_mad4b_guide_day_coverage_v1';
    public const RATE_META = '_mad4b_guide_rate_inclusion_v1';
    public const STATES = ['unknown','full_day','half_day','none','optional'];

    public static function pricingPermitted(): bool {
        return current_user_can('manage_woocommerce') ||
            (class_exists('MAD4B_Lifecycle_Access') &&
             MAD4B_Lifecycle_Access::can('commerce_manage_pricing'));
    }

    public static function canonical(int $id, string $kind): int {
        if ($id <= 0) return 0;
        $en = apply_filters('wpml_object_id', $id, $kind, false, 'en');
        return is_numeric($en) && (int)$en > 0 ? (int)$en : $id;
    }

    private static function tour(int $id): int|WP_Error {
        if ($id < 1 || get_post_type($id) !== 'tours-and-activities') {
            return new WP_Error('guide_tour_not_found');
        }
        $canonical=self::canonical($id,'tours-and-activities');
        if (get_post_type($canonical)!=='tours-and-activities') {
            return new WP_Error('guide_canonical_tour_unavailable');
        }
        return $canonical;
    }

    private static function programs(int $tour): array {
        $ids=wp_get_post_terms($tour,'programs',['fields'=>'ids']);
        if (is_wp_error($ids)) $ids=[];
        $extra=get_post_meta($tour,'related_program_terms',true);
        if (is_array($extra)) foreach ($extra as $id) if (is_scalar($id) && ctype_digit((string)$id)) $ids[]=(int)$id;
        $result=[];
        foreach ($ids as $id) {
            $canonical=self::canonical((int)$id,'programs');
            if ($canonical > 0 && term_exists($canonical,'programs')) $result[$canonical]=true;
        }
        return array_keys($result);
    }

    /**
     * Day identities and hash are derived only from the saved itinerary.
     * Text is NOT used to infer guide coverage. Structure edits invalidate review.
     */
    public static function source(int $tour, int $program=0): array|WP_Error {
        $tour=self::tour($tour);
        if (is_wp_error($tour)) return $tour;
        $isCruise=(string)get_post_meta($tour,'tour_mode',true)==='cruise_tour';
        $programs=$isCruise?self::programs($tour):[];
        if (!$isCruise && $program) return new WP_Error('guide_program_not_cruise');
        if ($isCruise && !$program && count($programs)>1) return new WP_Error('guide_program_selection_required');
        if ($isCruise && !$program && count($programs)===1) $program=$programs[0];
        if ($program) {
            $program=self::canonical($program,'programs');
            if (!in_array($program,$programs,true)) return new WP_Error('guide_program_not_linked_to_tour');
            $raw=get_term_meta($program,'program_itinerary',true);
            $owner='term';$id=$program;$kind='programs';
        } else {
            $raw=get_post_meta($tour,'itinerary_item',true);
            $owner='post';$id=$tour;$kind='tours-and-activities';
        }
        if (!is_array($raw) || !$raw || count($raw)>60) return new WP_Error('guide_itinerary_structure_unavailable');
        $days=[];$signatures=[];$explicitDays=0;
        foreach ($raw as $key=>$row) {
            if (!is_array($row)) continue;
            if ($owner==='post' && !preg_match('/^item-[0-9]+$/D',(string)$key)) continue;
            if ($owner==='term' && !preg_match('/^(?:item-)?[a-zA-Z0-9_-]{1,64}$/D',(string)$key)) continue;
            $number=is_scalar($row['number']??null)?trim((string)$row['number']):'';
            $title=is_scalar($row['title']??null)?trim(wp_strip_all_tags((string)$row['title'])):'';
            if (strlen($title)>240) $title=substr($title,0,240);
            $day=(string)$key;
            $days[$day]=['key'=>$day,'number'=>$number,'title'=>$title];
            // Repeater row numbers are not day numbers: a one-day desert
            // excursion may contain many hourly stops. Require an explicit
            // "Day/Jour/Día/Tag/Giorno N" label on EVERY multi-row stage.
            $unescaped=html_entity_decode($title,ENT_QUOTES|ENT_HTML5,'UTF-8');
            if (preg_match('/\b(?:day|jour|d[ií]a|tag|giorno)\s*[:#\-]?\s*[0-9]{1,3}\b/iu',$unescaped)
                || preg_match('/اليوم\s*(?:[0-9٠-٩]+|الأول|الثاني|الثالث|الرابع|الخامس|السادس|السابع|الثامن|التاسع|العاشر)/u',$unescaped)) {
                ++$explicitDays;
            }
            // The classifier and a past human review both depend on the
            // description, not just the title. Description edits invalidate.
            $description=is_scalar($row['description']??null)
                ?(string)$row['description']:'';
            $signatures[]=[$day,$number,$title,
                hash('sha256',wp_json_encode($description))];
        }
        if (!$days) return new WP_Error('guide_itinerary_days_missing');
        $basis=count($days)>1?'explicit_day_labels':'single_itinerary_row';
        if (count($days)>1 && $explicitDays!==count($days)) {
            // Repeater order is not duration. Group all hourly stops as one
            // guide day ONLY when the Tour title explicitly says one day,
            // half day, full day, or a bounded number of hours.
            $title=html_entity_decode(wp_strip_all_tags((string)get_the_title($tour)),
                ENT_QUOTES|ENT_HTML5,'UTF-8');
            $oneDay=$owner==='post' && $explicitDays===0 &&
                preg_match('/\b(?:half[- ]day|full[- ]day|(?:1|one|single)[- ]day|(?:[1-9]|1[0-9])[- ]hours?)(?:\b|$)/iu',$title);
            if (!$oneDay) return new WP_Error('guide_itinerary_day_units_unverified');
            $days=['day-1'=>[
                'key'=>'day-1','number'=>'1',
                'title'=>'Single-day itinerary ('.count($days).' activity stages)',
            ]];
            $basis='explicit_one_day_aggregated_stages';
            $signatures[]=['single_day_source_title',$title];
        }
        return [
            'tour_id'=>$tour,'program_id'=>$program?:null,
            'source_kind'=>$owner,'source_id'=>$id,'source_taxonomy'=>$kind,
            'days'=>$days, 'source_hash'=>hash('sha256',wp_json_encode($signatures)),
            'day_unit_basis'=>$basis,
            'source_scope'=>'canonical_en_wpml_family','program_selection_required'=>false,
        ];
    }

    public static function saved(array $source): array|WP_Error {
        $record=$source['source_kind']==='term'
            ?get_term_meta($source['source_id'],self::META,true)
            :get_post_meta($source['source_id'],self::META,true);
        if (!is_array($record) || ($record['schema']??null)!==1) {
            return new WP_Error('guide_coverage_not_reviewed');
        }
        if (($record['source_hash']??null)!==$source['source_hash']) {
            return new WP_Error('guide_coverage_source_changed');
        }
        $statuses=$record['statuses']??null;
        if (!is_array($statuses) ||
            array_diff(array_keys($statuses),array_keys($source['days'])) ||
            array_diff(array_keys($source['days']),array_keys($statuses))) {
            return new WP_Error('guide_coverage_days_mismatch');
        }
        foreach ($statuses as $value) {
            if (!is_string($value) || !in_array($value,self::STATES,true)) {
                return new WP_Error('guide_coverage_status_invalid');
            }
        }
        $factor=$record['half_day_factor']??null;
        if ($factor!==null && (!is_numeric($factor) || (float)$factor<=0 || (float)$factor>1)) {
            return new WP_Error('guide_half_day_factor_invalid');
        }
        return $record;
    }

    /** Server-only internal cost view; never a replacement for a retail quote. */
    public static function guide(int $tour,string|int $language,int $program=0,int $guides=1): array|WP_Error {
        if (!class_exists('MAD4B_Guide_Daily_Base_Cost_V1',false)) return new WP_Error('guide_daily_base_missing');
        if ($guides<1 || $guides>30) return new WP_Error('guide_count_invalid');
        $source=self::source($tour,$program);
        if (is_wp_error($source)) return $source;
        $record=self::saved($source);
        if (is_wp_error($record)) return $record;
        $statuses=$record['statuses'];
        $unknown=array_keys(array_filter($statuses,static fn($s)=>$s==='unknown'));
        if ($unknown) return new WP_Error('guide_days_unreviewed',implode(',',array_slice($unknown,0,15)));
        $full=count(array_filter($statuses,static fn($s)=>$s==='full_day'));
        $half=count(array_filter($statuses,static fn($s)=>$s==='half_day'));
        $optional=count(array_filter($statuses,static fn($s)=>$s==='optional'));
        $factor=$record['half_day_factor']??null;
        if ($half && $factor===null) return new WP_Error('guide_half_day_policy_required');
        // If every day is explicitly without a mandatory guide, the cost
        // is exactly zero. This must not require inventing an unavailable
        // language choice; an optional guide is priced in its own quote.
        if ($full===0 && $half===0) {
            $zero=[
                'contract'=>'mad4b.guide-cost-component.v1',
                'component_key'=>'guide:'.$source['source_kind'].':'.$source['source_id'].':not_required',
                'tour_id'=>$source['tour_id'],'program_id'=>$source['program_id'],
                'supplier'=>'self','currency'=>'EGP',
                'amount_type'=>'internal_cost_not_sell',
                'language_code'=>null,'tier'=>'not_required',
                'unit_cost'=>0,'full_days'=>0,'half_days'=>0,'half_day_factor'=>null,
                'optional_days_excluded'=>$optional,
                'effective_guide_days'=>0,'guide_count'=>0,'cost'=>0,
                'source_hash'=>$source['source_hash'],
                'source_record_revision'=>(int)($record['revision']??0),
                'not_price_authority'=>true,'apply_to_retail_total'=>false,'checkout_ready'=>false,
            ];
            $zero['cost_fingerprint']=hash('sha256',wp_json_encode($zero));
            return $zero;
        }
        // A service-bearing day must always verify the chosen taxonomy
        // identity; unknown terms are never mapped into "other".
        $code=MAD4B_Guide_Daily_Base_Cost_V1::identity($language);
        if (is_wp_error($code)) return $code;
        $selected=is_int($language)
            ? get_term($language,'guide-languages')
            : get_term_by('slug',$language,'guide-languages');
        $selectedId=$selected instanceof WP_Term
            ?self::canonical((int)$selected->term_id,'guide-languages'):0;
        $assigned=wp_get_post_terms((int)$source['tour_id'],'guide-languages',['fields'=>'ids']);
        if (!$selectedId || is_wp_error($assigned) ||
            !in_array($selectedId,array_map(static fn($id)=>
                self::canonical((int)$id,'guide-languages'),(array)$assigned),true)) {
            return new WP_Error('guide_language_not_offered_by_tour');
        }
        $base=MAD4B_Guide_Daily_Base_Cost_V1::read();
        if (is_wp_error($base)) return $base;
        $tier=$code==='ar'?'ar':($code==='en'?'en':'other');
        $units=$full+($half*(float)($factor??0));
        $cost=round($units*(int)$base['amounts'][$tier]*$guides,2);
        $receipt=[
            'contract'=>'mad4b.guide-cost-component.v1',
            'component_key'=>'guide:'.$source['source_kind'].':'.$source['source_id'].':'.$code,
            'tour_id'=>$source['tour_id'],'program_id'=>$source['program_id'],
            'supplier'=>'self','currency'=>'EGP',
            'amount_type'=>'internal_cost_not_sell',
            'language_code'=>$code,'tier'=>$tier,
            'unit_cost'=>(int)$base['amounts'][$tier],
            'full_days'=>$full,'half_days'=>$half,'half_day_factor'=>$half?(float)$factor:null,
            'optional_days_excluded'=>$optional,
            'effective_guide_days'=>$units,'guide_count'=>$guides,
            'cost'=>$cost,'source_hash'=>$source['source_hash'],
            'source_record_revision'=>(int)($record['revision']??0),
            'not_price_authority'=>true,'apply_to_retail_total'=>false,'checkout_ready'=>false,
        ];
        $receipt['cost_fingerprint']=hash('sha256',wp_json_encode($receipt));
        return $receipt;
    }

    /**
     * Only an explicit, attributable, source-hash-stable owner review can
     * classify a Tour Rate as already including Guide cost. Never infer from
     * retail amount or free-text inclusions.
     */
    public static function guideLanguageOffered(int $tour,string $slug): bool {
        if (!preg_match('/^[a-z0-9-]{2,64}$/D',$slug)) return false;
        $source=self::tour($tour);
        if (is_wp_error($source)) return false;
        $term=get_term_by('slug',$slug,'guide-languages');
        if (!($term instanceof WP_Term)) return false;
        $canonical=self::canonical((int)$term->term_id,'guide-languages');
        $assigned=wp_get_post_terms($source,'guide-languages',['fields'=>'ids']);
        return !is_wp_error($assigned) &&
            in_array($canonical,array_map(static fn($id)=>
                self::canonical((int)$id,'guide-languages'),(array)$assigned),true);
    }

    public static function ratePricingHash(int $rate): string {
        $snapshot=['rate_id'=>$rate,'post_status'=>get_post_status($rate)];
        foreach (['related_tour_id','related_program_term_id','related_package_term_id',
                  'unit_type','base_currency','single_price','double_price','triple_price',
                  'stop_sale'] as $key) {
            $snapshot[$key]=get_post_meta($rate,$key,true);
        }
        return hash('sha256',wp_json_encode($snapshot));
    }
    public static function rateReceipt(int $rate): array|WP_Error {
        if (get_post_type($rate)!=='tour-rates') return new WP_Error('guide_rate_type_invalid');
        $record=get_post_meta($rate,self::RATE_META,true);
        if (!is_array($record)||($record['schema']??null)!==1 ||
            !in_array($record['status']??'', ['included','excluded','partial'],true) ||
            !is_string($record['evidence']??null) ||
            strlen($record['evidence'])<10 || strlen($record['evidence'])>500 ||
            (int)($record['reviewer']??0)<1) {
            return new WP_Error('guide_rate_inclusion_unreviewed');
        }
        if (($record['rate_pricing_hash']??'')!==self::ratePricingHash($rate)) {
            return new WP_Error('guide_rate_pricing_changed');
        }
        $tour=(int)get_post_meta($rate,'related_tour_id',true);
        $program=(int)get_post_meta($rate,'related_program_term_id',true);
        $source=self::source($tour,$program);
        if (is_wp_error($source)||($record['source_hash']??null)!==$source['source_hash']) {
            return new WP_Error('guide_rate_itinerary_changed');
        }
        if ($record['status']==='included') {
            $baseline=$record['baseline_language']??'';
            if (!is_string($baseline)||$baseline==='') {
                return new WP_Error('guide_rate_baseline_language_required');
            }
            if (!class_exists('MAD4B_Guide_Daily_Base_Cost_V1',false) ||
                is_wp_error(MAD4B_Guide_Daily_Base_Cost_V1::identity($baseline)) ||
                !self::guideLanguageOffered($tour,$baseline)) {
                return new WP_Error('guide_rate_baseline_language_invalid');
            }
        }
        return $record;
    }

    public static function rateBox($post): void {
        if (!($post instanceof WP_Post)) return;
        $review=self::rateReceipt((int)$post->ID);
        $record=is_wp_error($review)?[]:$review;
        wp_nonce_field('mad4b_guide_rate_inclusion_save','mad4b_guide_rate_inclusion_nonce');
        echo '<p>Does this Rate already include the Guide COST? This is an evidence record, not an instruction to change published prices.</p>';
        echo '<p><label>Guide coverage <select name="mad4b_guide_rate_status">';
        foreach (['unknown'=>'Not reviewed (default)','included'=>'Guide already included',
                  'excluded'=>'Guide excluded from Rate','partial'=>'Some guide service already included'] as $value=>$label) {
            echo '<option value="'.esc_attr($value).'"'.selected($record['status']??'unknown',$value,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>Baseline Guide Language (required when included) <select name="mad4b_guide_rate_baseline">';
        echo '<option value="">Choose language</option>';
        $terms=get_terms(['taxonomy'=>'guide-languages','hide_empty'=>false]);
        if (is_array($terms)) foreach ($terms as $term) {
            if (!($term instanceof WP_Term)||str_starts_with($term->slug,'qa-')) continue;
            echo '<option value="'.esc_attr($term->slug).'"'.selected($record['baseline_language']??'',$term->slug,false).'>'.esc_html($term->name).'</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>Evidence (supplier contract, approved cost breakdown or source):';
        echo '<textarea name="mad4b_guide_rate_evidence" rows="3" style="display:block;width:100%">'.esc_textarea($record['evidence']??'').'</textarea></label></p>';
        echo '<p class="description">Review is invalidated automatically when Rate prices/relations/itinerary change. Unknown or partial inclusion cannot authorize a second Guide charge.</p>';
        if (is_wp_error($review)) {
            echo '<p><strong>Current evidence:</strong> '.esc_html($review->get_error_code()).'</p>';
        }
    }
    public static function rateBoxRegister(): void {
        if (!self::pricingPermitted()) return;
        add_meta_box('mad4b-rate-guide-inclusion','MAD4B · Guide Inclusion Evidence',
            [__CLASS__,'rateBox'],'tour-rates','normal','default');
    }
    public static function rateSave(int $rate): void {
        if (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) return;
        if (wp_is_post_revision($rate)||!self::pricingPermitted() ||
            !current_user_can('edit_post',$rate) ||
            !isset($_POST['mad4b_guide_rate_inclusion_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_guide_rate_inclusion_nonce'])),
                'mad4b_guide_rate_inclusion_save')) return;
        $state=sanitize_key((string)wp_unslash($_POST['mad4b_guide_rate_status']??'unknown'));
        if ($state==='unknown') {
            delete_post_meta($rate,self::RATE_META);
            return;
        }
        if (!in_array($state,['included','excluded','partial'],true)) return;
        $evidence=trim(sanitize_textarea_field(wp_unslash($_POST['mad4b_guide_rate_evidence']??'')));
        if (strlen($evidence)<10||strlen($evidence)>500) return;
        $tour=(int)get_post_meta($rate,'related_tour_id',true);
        $program=(int)get_post_meta($rate,'related_program_term_id',true);
        $source=self::source($tour,$program);
        if (is_wp_error($source)) return;
        $baseline=sanitize_key((string)wp_unslash($_POST['mad4b_guide_rate_baseline']??''));
        if ($state==='included'&&($baseline==='' ||
            !class_exists('MAD4B_Guide_Daily_Base_Cost_V1',false) ||
            is_wp_error(MAD4B_Guide_Daily_Base_Cost_V1::identity($baseline)) ||
            !self::guideLanguageOffered($tour,$baseline))) return;
        $record=['schema'=>1,'status'=>$state,
            'baseline_language'=>$state==='included'?$baseline:null,
            'source_hash'=>$source['source_hash'],
            'rate_pricing_hash'=>self::ratePricingHash($rate),
            'evidence'=>$evidence,'reviewer'=>get_current_user_id(),
            'reviewed_at'=>gmdate('c')];
        update_post_meta($rate,self::RATE_META,$record);
    }

    public static function breakdown(int $tour,string|int $language,int $program=0,int $guides=1,int $rate=0,string|int|null $baseline=null): array {
        $guide=self::guide($tour,$language,$program,$guides);
        $baselineChoice=$baseline;
        $rateReceipt=null;
        $rateState='not_selected';
        if ($rate) {
            $canonical=self::tour($tour);
            $related=(int)get_post_meta($rate,'related_tour_id',true);
            if (is_wp_error($canonical) || get_post_type($rate)!=='tour-rates' ||
                self::canonical($related,'tours-and-activities')!==$canonical) {
                $rateState='rate_tour_mismatch';
            } else {
                $marked=self::rateReceipt($rate);
                $rateState=is_wp_error($marked)
                    ?'unknown:'.$marked->get_error_code():$marked['status'];
                if (!is_wp_error($marked)) {
                    $rateReceipt=$marked;
                    if ($marked['status']==='included') {
                        // The reviewed Rate's original language is the
                        // baseline, never the client/request override.
                        $baselineChoice=$marked['baseline_language'];
                    }
                }
            }
        }
        $baselineGuide=$baselineChoice===null?null:self::guide($tour,$baselineChoice,$program,$guides);
        $ok=!is_wp_error($guide);
        $incremental=null;$incrementalState='no_existing_rate_context';
        if ($rate) {
            if ($rateState==='excluded' && $ok) {
                $incremental=$guide['cost'];
                $incrementalState='guide_excluded_full_cost_reference';
            } elseif ($rateState==='included' && $ok && is_array($baselineGuide)) {
                $incremental=round($guide['cost']-$baselineGuide['cost'],2);
                $incrementalState='guide_included_language_delta_reference';
            } else {
                $incrementalState='rate_inclusion_or_baseline_unverified';
            }
        }
        $base=[
            'contract'=>'mad4b.package-cost-breakdown.guide-shadow.v1',
            'tour_id'=>$tour,'program_id'=>$program?:null,'rate_id'=>$rate?:null,
            'guide'=>$ok?$guide:null,'guide_state'=>$ok?'cost_resolved':$guide->get_error_code(),
            'guide_cost_egp'=>$ok?$guide['cost']:null,
            'baseline_language_code'=>is_array($baselineGuide)?$baselineGuide['language_code']:null,
            'guide_incremental_cost_reference_egp'=>$incremental,
            'guide_incremental_cost_state'=>$incrementalState,
            'baseline_cost_egp'=>is_array($baselineGuide)?$baselineGuide['cost']:null,
            'guide_cost_delta_egp'=>($ok && is_array($baselineGuide))
                ?round($guide['cost']-$baselineGuide['cost'],2):null,
            'guide_delta_state'=>$baselineChoice===null?'no_baseline_provided':
                (is_wp_error($baselineGuide)?$baselineGuide->get_error_code():
                  ($ok?'internal_cost_difference_only':'guide_cost_unresolved')),
            'existing_rate_guide_inclusion'=>$rateState,
            'other_cost_components'=>[
                'accommodation'=>'provider_cost_quote_required',
                'transportation'=>'provider_cost_quote_required',
                'extras'=>'provider_cost_quote_required',
            ],
            'total_component_cost'=>null,
            'total_component_cost_currency'=>'EGP',
            'rate_generation_ready'=>false,
            'retail_price_changed'=>false,
            'checkout_ready'=>false,
        ];
        $base['breakdown_fingerprint']=hash('sha256',wp_json_encode($base));
        return $base;
    }

    /**
     * New dynamic Rate pipeline: NEVER use a historical Tour Rate as the
     * basis for component costs. Leave the candidate blocked until every
     * supplier/domain has a signed COST quote and a pricing policy is known.
     * Previewing the guide line alone cannot create even a sellable draft.
     */
    public static function generationPlan(int $tour,string|int $language,int $program=0,int $guides=1): array {
        $breakdown=self::breakdown($tour,$language,$program,$guides);
        $missing=[];
        if ($breakdown['guide']===null) {
            $missing[]=$breakdown['guide_state'];
        }
        foreach (array_keys($breakdown['other_cost_components']) as $kind) {
            $missing[]='component_cost_not_certified:'.$kind;
        }
        $missing[]='component_profit_policy_not_certified';
        $canonical=self::tour($tour);
        $base=(is_wp_error($canonical)?0:$canonical);
        $legacy=$base?(string)get_post_meta($base,'shared_product_modifier_rules',true):'';
        if ($legacy!=='') {
            $decoded=json_decode($legacy,true);
            if (is_array($decoded)&&!empty($decoded['languages'])) {
                $missing[]='legacy_guide_language_modifier_review_required';
            }
        }
        return [
            'contract'=>'mad4b.package.component-cost-generation-preflight.v1',
            'source_mode'=>'component_cost_only',
            'tour_id'=>$tour,'program_id'=>$program?:null,
            'guide_language_requested'=>$language,
            'guide_cost_receipt'=>$breakdown['guide'],
            'guide_cost_state'=>$breakdown['guide_state'],
            'other_components'=>$breakdown['other_cost_components'],
            'missing_dependencies'=>$missing,
            'generation_state'=>$missing?'blocked_missing_certified_inputs':'ready',
            'native_rate_generator_invoked'=>false,
            'historical_retail_rate_used_as_cost'=>false,
            'legacy_rate_price_copied'=>false,
            'new_rate_created'=>false,
            'new_rate_default_status'=>'draft',
            'stop_sale_required'=>true,
            'checkout_ready'=>false,
        ];
    }

    private static function form(array $source): void {
        $record=self::saved($source);
        $statuses=is_wp_error($record)?[]:$record['statuses'];
        $factor=is_wp_error($record)?'':($record['half_day_factor']??'');
        wp_nonce_field('mad4b_guide_day_save','mad4b_guide_day_nonce');
        echo '<p><strong>Guide Service by Itinerary Day</strong>. No day is assumed to require a guide. Changes to the itinerary invalidate the current review.</p>';
        echo '<p>Source: '.esc_html($source['source_kind'].':'.$source['source_id']).'; Days: '.count($source['days']).'</p>';
        echo '<input type="hidden" name="mad4b_guide_day_source_hash" value="'.esc_attr($source['source_hash']).'">';
        echo '<table class="widefat striped"><thead><tr><th>Day</th><th>Guide service</th></tr></thead><tbody>';
        foreach ($source['days'] as $key=>$day) {
            echo '<tr><td>'.esc_html(($day['number']!==''?$day['number'].' · ':'').$day['title']).'</td><td>';
            echo '<select name="mad4b_guide_day_status['.esc_attr($key).']">';
            foreach (['unknown'=>'Not reviewed','full_day'=>'Full guided day',
                'half_day'=>'Half guided day','none'=>'No guide','optional'=>'Optional guide'] as $state=>$label) {
                echo '<option value="'.esc_attr($state).'"'.selected($statuses[$key]??'unknown',$state,false).'>'.esc_html($label).'</option>';
            }
            echo '</select></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><label>Half-day cost factor (leave blank until approved): <input type="number" name="mad4b_guide_half_factor" min="0.01" max="1" step="0.01" value="'.esc_attr((string)$factor).'"></label></p>';
        echo '<p class="description">Full day = 1 unit. Optional services are excluded from base. An unreviewed day or half-day without an approved factor prevents a complete guide cost. Language defaults remain editable in Form #6.</p>';
    }

    public static function box($post): void {
        if (!($post instanceof WP_Post)) return;
        $canonical=self::canonical((int)$post->ID,'tours-and-activities');
        if ($canonical!==(int)$post->ID) {
            echo '<p>Guide day coverage belongs to the canonical Tour source. <a href="'.esc_url(get_edit_post_link($canonical)).'">Edit the source-language Tour</a>.</p>';
            return;
        }
        $source=self::source((int)$post->ID);
        if (is_wp_error($source)) {
            echo '<p>Guide service mapping requires an explicit itinerary source: '.esc_html($source->get_error_code()).'.</p>';
            if ($source->get_error_code()==='guide_program_selection_required') {
                foreach (self::programs((int)$post->ID) as $id) {
                    echo '<p><a href="'.esc_url(get_edit_term_link($id,'programs')).'">Review program '.(int)$id.'</a></p>';
                }
            }
            return;
        }
        if ($source['source_kind']==='term') {
            echo '<p>Guide coverage belongs to the linked reusable Program. <a href="'.esc_url(get_edit_term_link($source['source_id'],'programs')).'">Edit Guide Coverage on the Program</a>.</p>';
            return;
        }
        self::form($source);
    }
    public static function register(): void {
        if (!self::pricingPermitted() || !current_user_can('edit_posts')) return;
        add_meta_box('mad4b-guide-itinerary-service','MAD4B · Guide Service Cost Days',
            [__CLASS__,'box'],'tours-and-activities','normal','default');
    }

    private static function store(array $source): bool {
        if (!isset($_POST['mad4b_guide_day_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_guide_day_nonce'])),'mad4b_guide_day_save')) return false;
        $hash=sanitize_text_field(wp_unslash($_POST['mad4b_guide_day_source_hash']??''));
        if (!hash_equals($source['source_hash'],$hash)) return false;
        $submitted=wp_unslash($_POST['mad4b_guide_day_status']??[]);
        if (!is_array($submitted) || array_diff(array_keys($submitted),array_keys($source['days'])) ||
            array_diff(array_keys($source['days']),array_keys($submitted))) return false;
        $clean=[];
        foreach ($submitted as $key=>$value) {
            if (!is_string($value) || !in_array($value,self::STATES,true)) return false;
            $clean[(string)$key]=$value;
        }
        $factorString=trim((string)wp_unslash($_POST['mad4b_guide_half_factor']??''));
        $factor=null;
        if ($factorString!=='') {
            if (!preg_match('/^(?:0\.[0-9]{1,4}|1(?:\.0{1,4})?)$/D',$factorString) ||
                (float)$factorString<=0) return false;
            $factor=(float)$factorString;
        }
        $previous=self::saved($source);
        $revision=is_wp_error($previous)?1:((int)($previous['revision']??0)+1);
        $record=[
            'schema'=>1,'source_hash'=>$source['source_hash'],
            'statuses'=>$clean,'half_day_factor'=>$factor,'revision'=>$revision,
            'reviewed_by'=>get_current_user_id(),'reviewed_at'=>gmdate('c'),
            'status'=>'explicit_human_review',
        ];
        if ($source['source_kind']==='term') update_term_meta($source['source_id'],self::META,$record);
        else update_post_meta($source['source_id'],self::META,$record);
        return true;
    }
    public static function saveTour(int $postId): void {
        if (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) return;
        if (wp_is_post_revision($postId) || !self::pricingPermitted() ||
            !current_user_can('edit_post',$postId)) return;
        if (self::canonical($postId,'tours-and-activities')!==$postId) return;
        $source=self::source($postId);
        if (is_wp_error($source)||$source['source_kind']!=='post') return;
        self::store($source);
    }
    public static function termEditor($term): void {
        if (!($term instanceof WP_Term) || !self::pricingPermitted() ||
            !current_user_can('edit_term',$term->term_id)) return;
        if (self::canonical($term->term_id,'programs')!==(int)$term->term_id) return;
        $raw=get_term_meta($term->term_id,'program_itinerary',true);
        if (!is_array($raw)||!$raw) {
            echo '<tr class="form-field"><th>Guide service</th><td>Structured program itinerary missing. Cannot assign days.</td></tr>';
            return;
        }
        // Source construction must verify Tour relationship; a detached program
        // cannot receive a cost-bearing coverage contract.
        $tours=get_posts(['post_type'=>'tours-and-activities','post_status'=>['publish','draft'],
            'fields'=>'ids','numberposts'=>-1,'suppress_filters'=>true]);
        $source=null;
        foreach ($tours as $tour) {
            if (!in_array((int)$term->term_id,self::programs((int)$tour),true)) continue;
            $found=self::source((int)$tour,(int)$term->term_id);
            if (!is_wp_error($found)) {$source=$found;break;}
        }
        if (!$source) {
            echo '<tr class="form-field"><th>Guide service</th><td>Program source not linked to a reviewable Tour.</td></tr>';
            return;
        }
        echo '<tr class="form-field"><th><label>Guide service days</label></th><td>';
        self::form($source);
        echo '</td></tr>';
    }
    public static function saveTerm(int $termId): void {
        if (!self::pricingPermitted() || !current_user_can('edit_term',$termId) ||
            self::canonical($termId,'programs')!==$termId) return;
        $tours=get_posts(['post_type'=>'tours-and-activities','post_status'=>['publish','draft'],
            'fields'=>'ids','numberposts'=>-1,'suppress_filters'=>true]);
        foreach ($tours as $tour) {
            if (!in_array($termId,self::programs((int)$tour),true)) continue;
            $source=self::source((int)$tour,$termId);
            if (!is_wp_error($source)) self::store($source);
            return;
        }
    }

    /** Private pricing inspector; no raw cost becomes part of public form rendering. */
    public static function rest(): void {
        register_rest_route('mad4b-guide/v1','/generation-preflight',[
            'methods'=>'GET',
            'permission_callback'=>static function(){
                return self::pricingPermitted();
            },
            'args'=>[
                'tour_id'=>['required'=>true,'type'=>'integer','minimum'=>1],
                'language'=>['required'=>true,'type'=>'string'],
                'program_id'=>['type'=>'integer','minimum'=>0],
                'guide_count'=>['type'=>'integer','minimum'=>1,'maximum'=>30],
            ],
            'callback'=>static function(WP_REST_Request $r){
                if (!current_user_can('edit_post',(int)$r['tour_id'])) {
                    return new WP_Error('guide_plan_forbidden','Not permitted',['status'=>403]);
                }
                $out=self::generationPlan((int)$r['tour_id'],(string)$r['language'],
                    (int)($r['program_id']??0),(int)($r['guide_count']??1));
                $response=new WP_REST_Response($out);
                $response->header('Cache-Control','private, no-store, max-age=0');
                return $response;
            }
        ]);
        register_rest_route('mad4b-guide/v1','/package-cost',[
            'methods'=>'GET',
            'permission_callback'=>static function(){
                return self::pricingPermitted();
            },
            'args'=>[
                'tour_id'=>['required'=>true,'type'=>'integer','minimum'=>1],
                'language'=>['required'=>true,'type'=>'string'],
                'program_id'=>['type'=>'integer','minimum'=>0],
                'guide_count'=>['type'=>'integer','minimum'=>1,'maximum'=>30],
                'rate_id'=>['type'=>'integer','minimum'=>0],
                'baseline_language'=>['type'=>'string'],
            ],
            'callback'=>static function(WP_REST_Request $r){
                if (!current_user_can('edit_post',(int)$r['tour_id'])) {
                    return new WP_Error('guide_cost_tour_forbidden','Not permitted',['status'=>403]);
                }
                $out=self::breakdown((int)$r['tour_id'],(string)$r['language'],
                    (int)($r['program_id']??0),(int)($r['guide_count']??1),
                    (int)($r['rate_id']??0),
                    isset($r['baseline_language'])?(string)$r['baseline_language']:null);
                $response=new WP_REST_Response($out);
                $response->header('Cache-Control','private, no-store, max-age=0');
                return $response;
            },
        ]);
    }
}
add_action('add_meta_boxes',[MAD4B_Guide_Service_Composition_V1::class,'register'],35);
add_action('add_meta_boxes',[MAD4B_Guide_Service_Composition_V1::class,'rateBoxRegister'],36);
add_action('save_post_tour-rates',[MAD4B_Guide_Service_Composition_V1::class,'rateSave'],55);
add_action('save_post_tours-and-activities',[MAD4B_Guide_Service_Composition_V1::class,'saveTour'],55);
add_action('programs_edit_form_fields',[MAD4B_Guide_Service_Composition_V1::class,'termEditor'],35);
add_action('edited_programs',[MAD4B_Guide_Service_Composition_V1::class,'saveTerm'],35);
add_action('rest_api_init',[MAD4B_Guide_Service_Composition_V1::class,'rest'],35);
add_filter('mad4b_company_reconcile_component_readiness',static function($providers){
    if (!is_array($providers)) $providers=[];
    $providers['guide_composition']=[
        'contract'=>'mad4b.guide-composition.readiness.v1',
        'itinerary_service_source'=>'explicit_day_status',
        'guide_unit_basis'=>'EGP per guided day',
        'supplier'=>'self','programs_supported'=>true,
        'package_breakdown_available'=>true,
        'full_package_cost_ready'=>false,
        'native_rate_price_modified'=>false,
        'checkout_ready'=>false,
    ];
    return $providers;
},36);

/**
 * Existing native Rate Generator remains authoritative for retail amounts.
 * Add a non-mutating guide-day preflight to its inspector. Do not infer a
 * guide language from the UI/site locale or copy old retail rates to cost.
 */
add_filter('mad4b/rate_generator/plan',static function($hint,$plan,$input){
    if (!is_array($hint) || !is_array($plan) || !is_array($input) ||
        ($plan['kind']??'')!=='tour') return $hint;
    $tour=(int)($plan['parent_id']??0);
    $program=(int)($input['program_term_id']??0);
    $s=MAD4B_Guide_Service_Composition_V1::source($tour,$program);
    $state=is_wp_error($s)?$s->get_error_code():'guide_day_coverage_unreviewed';
    $rows=null;
    if (!is_wp_error($s)) {
        $saved=MAD4B_Guide_Service_Composition_V1::saved($s);
        if (is_wp_error($saved)) $state=$saved->get_error_code();
        else {
            $counts=array_count_values($saved['statuses']);
            $rows=['full_days'=>$counts['full_day']??0,'half_days'=>$counts['half_day']??0,
                'none_days'=>$counts['none']??0,'optional_days'=>$counts['optional']??0,
                'unreviewed_days'=>$counts['unknown']??0,
                'half_day_factor'=>$saved['half_day_factor']];
            $state=($rows['unreviewed_days']===0 &&
                ($rows['half_days']===0 || $rows['half_day_factor']!==null))
                ?'guide_days_reviewed':'guide_days_incomplete';
        }
    }
    $hint['guide_cost_preflight']=[
        'contract'=>'mad4b.guide.rate-generation-preflight.v1',
        'state'=>$state,
        'itinerary_source_kind'=>is_wp_error($s)?null:$s['source_kind'],
        'itinerary_source_id'=>is_wp_error($s)?null:$s['source_id'],
        'itinerary_source_hash'=>is_wp_error($s)?null:$s['source_hash'],
        'day_counts'=>$rows,
        'guide_language_not_selected_for_generated_rate'=>true,
        'tour_rate_guide_inclusion_unverified'=>true,
        'full_component_cost_missing'=>true,
        'component_cost_based_generation_allowed'=>false,
        'retail_amount_modified'=>false,
        'draft_fingerprint_modified'=>false,
    ];
    return $hint;
},25,3);

/**
 * Record a verified language preference on Form #6's existing nonfinancial
 * submission only. Never use a browser amount, add a price, or expose the
 * self supplier's EGP cost to anonymous visitors.
 */
add_filter('fluentform/filter_insert_data',static function($insert) {
    if (!is_array($insert) || (int)($insert['form_id']??0)!==6) return $insert;
    $response=json_decode((string)($insert['response']??''),true);
    if (!is_array($response)) return $insert;
    $value=$response['guide_language']??null;
    if (!is_string($value) || !preg_match('/^[a-z0-9-]{2,64}$/D',$value)) return $insert;
    $submitted=[];
    if (is_string($_POST['data']??null)) {
        parse_str(wp_unslash($_POST['data']),$submitted);
    }
    $embedded=$_POST['__fluent_form_embded_post_id']??($submitted['__fluent_form_embded_post_id']??null);
    $tour=filter_var($embedded,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$tour || get_post_type((int)$tour)!=='tours-and-activities' ||
        get_post_status((int)$tour)!=='publish') return $insert;
    $intent=$response['_shared_tour_booking_intent_v2']??null;
    if (!is_array($intent) || (int)($intent['tour_id']??0)!==(int)$tour ||
        ($intent['checkout_ready']??true)!==false) return $insert;
    $identity=class_exists('MAD4B_Guide_Daily_Base_Cost_V1',false)
        ?MAD4B_Guide_Daily_Base_Cost_V1::identity($value)
        :new WP_Error('guide_cost_provider_missing');
    $record=['contract'=>'mad4b.form6.guide-preference.v1',
        'value'=>$value,'status'=>'requires_review','commercial_price_applied'=>false];
    if (is_wp_error($identity)) {
        $record['reason']=$identity->get_error_code();
    } else {
        $selected=get_term_by('slug',$value,'guide-languages');
        $selectedId=$selected&&!is_wp_error($selected)
            ?MAD4B_Guide_Service_Composition_V1::canonical((int)$selected->term_id,'guide-languages'):0;
        $assigned=wp_get_post_terms((int)$tour,'guide-languages',['fields'=>'ids']);
        $available=!is_wp_error($assigned) &&
            in_array($selectedId,array_map(static fn($id)=>
                MAD4B_Guide_Service_Composition_V1::canonical((int)$id,'guide-languages'),(array)$assigned),true);
        if (!$available) {
            $record['reason']='guide_language_not_offered_by_tour';
        } else {
            $record['language_code']=$identity;
            $record['canonical_term_id']=$selectedId;
            $record['status']='validated_preference_not_price';
        }
    }
    $response['_mad4b_guide_preference_v1']=$record;
    $insert['response']=wp_json_encode($response,JSON_UNESCAPED_UNICODE);
    return $insert;
},40,1);
