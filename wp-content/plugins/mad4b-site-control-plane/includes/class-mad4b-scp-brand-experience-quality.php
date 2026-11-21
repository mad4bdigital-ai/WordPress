<?php
/**
 * MAD4B Brand-Aware Experience Quality — site-scoped, source-backed advisory.
 * Discovery is read-only; content & media changes belong to native Content Jobs.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Brand_Experience_Quality {
    const CONTRACT = 'mad4b.brand-experience-quality.v1';
    const MAX_TOURS = 500;

    private static function compact_text($value, $limit = 600) {
        $value = trim(preg_replace('/\s+/u',' ', html_entity_decode(wp_strip_all_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return mb_substr($value, 0, $limit);
    }

    public static function brand_context() {
        $profile = class_exists('MAD4B_SCP_Context_Authority', false)
            ? MAD4B_SCP_Context_Authority::profile() : array();
        if (!is_array($profile)) $profile = array();
        $scope = array(
            'site_uuid' => (string)($profile['site_uuid'] ?? ''),
            'brand_id' => (string)($profile['brand_id'] ?? ''),
            'brand_name' => (string)($profile['brand_name'] ?? ''),
            'revision' => absint($profile['revision'] ?? 0),
            'context_fingerprint' => (string)($profile['context_fingerprint'] ?? ''),
            'status' => sanitize_key((string)($profile['status'] ?? 'missing')),
        );
        $approved = array(); $assets = class_exists('MAD4B_SCP_Context_Authority', false)
            ? MAD4B_SCP_Context_Authority::assets() : array();
        foreach ((array)$assets as $asset) {
            if (!is_array($asset) || !in_array((string)($asset['category'] ?? ''),
                array('brand_strategy','tone_of_voice','editorial_guidelines'), true)) continue;
            if (($asset['status'] ?? '') !== 'ready' || ($asset['review_status'] ?? '') !== 'approved'
                || empty($asset['content_complete'])
                || !hash_equals((string)($asset['content_hash'] ?? 'invalid'),
                    (string)($asset['reviewed_content_hash'] ?? ''))) continue;
            $approved[(string)$asset['category']] = array(
                'asset_id' => (string)($asset['asset_id'] ?? ''),
                'content_hash' => (string)$asset['content_hash'],
                'excerpt' => self::compact_text($asset['content_excerpt'] ?? '', 1700),
            );
        }
        // Public-facing Company copy is evidence of positioning, not an approved
        // replacement for the Brand Context Strategy/Tone/Guidelines.
        $look = (array)get_option('look-and-feel', array());
        $presentation = array();
        foreach (array('mad4b_brand_promise','mad4b_brand_tagline',
            'mad4b_creative_platform','mad4b_hero_subtitle',
            'mad4b_campaign_body') as $key) {
            $value = self::compact_text($look[$key] ?? '', 260);
            if ($value !== '') $presentation[$key] = $value;
        }
        $approved_text = implode(' ', array_column($approved, 'excerpt'));
        $presentation_text = implode(' ', $presentation);
        $premium_pattern = '/\b(?:premium|luxur(?:y|ious)|private|exclusive|bespoke|tailor(?:ed|[- ]made)|personalized|high[- ]end)\b/i';
        $premium_authoritative = preg_match($premium_pattern, $approved_text) === 1;
        $premium_presentation = preg_match($premium_pattern, $presentation_text) === 1;
        $evidence = array(
            'contract' => self::CONTRACT,
            'scope' => $scope,
            'approved_asset_hashes' => array_map(static fn($a) => $a['content_hash'], $approved),
            'approved_categories' => array_keys($approved),
            'presentation_tokens' => $presentation,
            'positioning_evidence' => $premium_authoritative ? 'approved_brand_documents'
                : ($premium_presentation ? 'unapproved_presentation_tokens' : 'not_established'),
            'authority_level' => count($approved) === 3 ? 'approved_brand_context'
                : (count($approved) > 0 ? 'partial_approved_brand_context' : 'provisional'),
            'premium_signal' => (bool)($premium_authoritative || $premium_presentation),
        );
        $evidence['brand_evidence_sha256'] = hash('sha256', wp_json_encode($evidence, JSON_UNESCAPED_SLASHES));
        return $evidence;
    }

    private static function issue($type, $id, $code, $severity, $label, array $evidence) {
        return array('id'=>hash('sha256',get_current_blog_id().'|'.$type.'|'.$id.'|'.$code),
            'post_type'=>$type,'object_id'=>$id,'code'=>$code,'severity'=>$severity,
            'label'=>$label,'source'=>'mad4b-brand-experience',
            'source_evidence'=>$evidence);
    }

    private static function hook_repeats_title($title, $excerpt) {
        $title = mb_strtolower(self::compact_text($title, 300));
        $excerpt = mb_strtolower(self::compact_text($excerpt, 400));
        if ($title === '' || $excerpt === '' || mb_strlen($title) < 25) return false;
        if (mb_strpos(mb_substr($excerpt,0,170),$title)!==false) return true;
        // Handles lightweight punctuation and brand adjectives before a title.
        $words = preg_split('/[^\p{L}\p{N}]+/u',$title,-1,PREG_SPLIT_NO_EMPTY);
        if (count($words)<5) return false;
        $first = mb_substr($excerpt,0,150);
        $matched = 0;
        foreach ($words as $word) if (mb_strlen($word)>3 && mb_strpos($first,$word)!==false) ++$matched;
        return $matched >= max(5,(int)ceil(count($words)*.75));
    }

    private static function candidate_media($id) {
        $candidates = array(); $seen = array();
        $thumbnail = get_post_thumbnail_id($id);
        if ($thumbnail) $candidates[] = array('attachment_id'=>$thumbnail,
            'url'=>esc_url_raw((string)wp_get_attachment_url($thumbnail)), 'role'=>'featured');
        foreach (array('image_vertical','gallery','grid_image_1_middle_upper',
            'grid_image_2','grid_image_3_middle_bottom','grid_image_4_') as $key) {
            $raw = get_post_meta($id,$key,true);
            if (!is_string($raw)) continue;
            // Gallery sources can be a comma-separated list; no media fetch or OCR.
            foreach (array_slice(explode(',', $raw),0,6) as $part) {
                $part = trim($part);
                if (!preg_match('@^https?://@i',$part)) continue;
                $url=esc_url_raw($part);
                if (!$url || isset($seen[$url])) continue;
                $seen[$url]=true;
                $candidates[] = array('field'=>$key,'url'=>$url);
                if (count($candidates)>=10) break 2;
            }
        }
        return $candidates;
    }

    private static function source_id($id, $type) {
        if (!has_filter('wpml_object_id')) return $id;
        $lang=apply_filters('wpml_default_language', null);
        if (!is_string($lang)||$lang==='') return $id;
        $canonical=(int)apply_filters('wpml_object_id',$id,$type,false,$lang);
        return $canonical ?: $id;
    }

    public static function discover($cfg = null) {
        $brand = self::brand_context();
        $issues = array(); $evaluated=0; $partial=false;
        if ($brand['authority_level'] !== 'approved_brand_context' && $brand['scope']['site_uuid'] !== '') {
            $issues[]=self::issue('site',0,'brand_quality_context_review_needed','medium',
                'Brand-aware recommendations are provisional until current Strategy, Tone and Editorial Context are reviewed',
                array('brand_scope'=>$brand['scope'],'authority_level'=>$brand['authority_level'],
                    'approved_categories'=>$brand['approved_categories'],
                    'brand_evidence_sha256'=>$brand['brand_evidence_sha256'],
                    'review_guidance'=>'Review the exact site-bound Brand Context assets and their current revisions; do not promote presentation tokens into approved brand strategy.',
                    'review_only'=>true,'publication_authorized'=>false));
        }
        if (!post_type_exists('tours-and-activities')) return array('complete'=>true,
            'issues'=>[],'evaluated'=>0,'brand'=>$brand);
        $ids = get_posts(array('post_type'=>'tours-and-activities','post_status'=>'publish',
            'posts_per_page'=>self::MAX_TOURS+1,'fields'=>'ids','suppress_filters'=>true,
            'orderby'=>'ID','order'=>'ASC','no_found_rows'=>true));
        if (count($ids)>self::MAX_TOURS) {
            $partial=true; $ids=array_slice($ids,0,self::MAX_TOURS);
        }
        foreach ($ids as $post_id) {
            $id=(int)$post_id;
            if (self::source_id($id,'tours-and-activities')!==$id) continue;
            $post = get_post($id);
            if (!($post instanceof WP_Post)) continue;
            ++$evaluated;
            $title=self::compact_text($post->post_title,350);
            $excerpt=self::compact_text($post->post_excerpt,500);
            $title_contrast=(bool)$brand['premium_signal']
                && preg_match('/\b(?:budget|cheap|low[- ]cost)\b/i',$title)===1;
            $repeat=self::hook_repeats_title($title,$excerpt);
            $facts = array();
            foreach (array('highlight_rows','_description','itinerary_item_description') as $field) {
                $snippet=self::compact_text(get_post_meta($id,$field,true),420);
                if ($snippet !== '') $facts[$field]=$snippet;
            }
            $base = array(
                'source_facts'=>$facts,
                'brand_scope' => $brand['scope'],
                'brand_evidence_sha256' => $brand['brand_evidence_sha256'],
                'authority_level' => $brand['authority_level'],
                'approved_categories' => $brand['approved_categories'],
                'positioning_evidence' => $brand['positioning_evidence'],
                'source_language' => (string)(apply_filters('wpml_post_language_details',null,$id)['language_code'] ?? ''),
                'source_sha256'=>hash('sha256',wp_json_encode(array($title,$excerpt,get_post_modified_time('U',true,$id)))),
                'observed_title'=>$title,
                'observed_excerpt'=>mb_substr($excerpt,0,260),
                'review_only'=>true,'publication_authorized'=>false,
            );
            if ($title_contrast || $repeat) {
                $reasons=array();
                if ($title_contrast) $reasons[]='Title value-language may conflict with the observed brand positioning';
                if ($repeat) $reasons[]='Listing opener repeats the tour title instead of leading with specific experiences';
                $e=$base;
                $e['reasons']=$reasons;
                $e['review_guidance']='Verify the actual itinerary, audience and company brand guidance. Suggest an experience-first title and two-line listing hook. Preserve truthful affordability in factual details; never assert itinerary facts without source.';
                $e['quality_decision']='editorial_review_required';
                $issues[]=self::issue('tours-and-activities',$id,
                    'brand_listing_message_alignment',
                    ($title_contrast && in_array($brand['authority_level'],
                        array('approved_brand_context','partial_approved_brand_context'),true))
                    ? 'high':'medium',
                    implode('; ',$reasons),$e);
            }
            // A contextual media review candidate, NOT a claim that any image is AI.
            $experience = (bool)preg_match('/\b(?:snorkel(?:ing)?|dolphin(?:s)?|underwater|diving|coral|reef)\b/i',$title);
            if (!$experience) continue;
            $candidates=self::candidate_media($id);
            if (!$candidates) continue;
            $e=$base;
            $e['media_candidates']=$candidates;
            $e['media_source_sha256']=hash('sha256',wp_json_encode($candidates));
            // Image/meta updates may not advance post_modified, so include the
            // media fingerprint in the ContentJob source revision.
            $e['source_sha256']=hash('sha256',$base['source_sha256'].'|'.$e['media_source_sha256']);
            $e['authenticity']='unverified_visual_review_required';
            $e['review_guidance']='Inspect the real displayed images, compare each scene with the advertised tour location and activities, and request authentic destination photography if the imagery appears illustrative. Do not infer AI generation from a filename or metadata alone.';
            $e['quality_decision']='image_by_image_review_required';
            $issues[]=self::issue('tours-and-activities',$id,'brand_experiential_media_authenticity',
                'medium','Authenticity of experience-led underwater or wildlife tour imagery requires visual review',$e);
        }
        // An incomplete inventory cannot close issues for unvisited posts.
        return array('complete'=>!$partial,'issues'=>$issues,'evaluated'=>$evaluated,
            'brand'=>$brand,'partial'=>$partial);
    }

    public static function primary_color() {
        $kit=(int)get_option('elementor_active_kit',0);
        if (!$kit) return '#102D40';
        $colors=(array)get_post_meta($kit,'_elementor_page_settings',true);
        foreach ((array)($colors['system_colors']??[]) as $entry) {
            if (($entry['_id']??'')!=='primary') continue;
            $hex=(string)($entry['color']??'');
            if (!preg_match('/^#[a-f0-9]{6}$/iD',$hex))break;
            $luma = function($v) {
                $v=$v/255;
                return $v<=0.04045?$v/12.92:pow(($v+0.055)/1.055,2.4);
            };
            $r=$luma(hexdec(substr($hex,1,2)));
            $g=$luma(hexdec(substr($hex,3,2)));
            $b=$luma(hexdec(substr($hex,5,2)));
            $contrast=1.05/(0.05+0.2126*$r+0.7152*$g+0.0722*$b);
            return $contrast>=7 ? strtoupper($hex) : '#102D40';
        }
        return '#102D40';
    }

    public static function visual_qa_css() {
        if (is_admin() || wp_doing_ajax() ||
            !(is_front_page() || is_post_type_archive('tours-and-activities')
                || is_tax() || is_home())) return;
        $surface=self::primary_color();
        // Dynamic WPML text is native. Only its shared Elementor badge node is styled.
        echo '<style id="mad4b-brand-featured-contrast-v1" data-no-optimize="1">'
        .'.jet-listing-grid__item .elementor-element.elementor-element-1b772ff .jet-listing-dynamic-field__content'
        .'{color:#fff!important;background-color:'.esc_attr($surface).'!important;'
        .'padding:5px 11px!important;border-radius:7px!important;'
        .'display:inline-block!important;line-height:1.35!important;'
        .'text-shadow:none!important;box-shadow:0 2px 5px rgba(0,0,0,.18);}'
        .'</style>'."\n";
    }
}
// Visual QA remains advisory; native Elementor/JetEngine settings own card styles.
// Do not inject a second force-important brand/badge background layer.
