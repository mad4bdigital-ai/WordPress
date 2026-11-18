<?php
/**
 * MAD4B Visual Content & Semantic SEO Intelligence.
 *
 * One contextual strategy engine; multiple composable, evidence-backed
 * strategies. Read-only recommendations. Publication/Alt writing are separate
 * host-authorized operations subject to the inherited review policy.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Visual_SEO_Strategy {
    const CONTRACT='mad4b.visual-semantic-seo-strategy.v1';
    const VERSION='1.0.0';
    const ALLOWED_ROLES=['featured','gallery','grid','vertical','inline'];
    const VISUAL_TYPES=['illustrative_composite','photograph','graphic','unknown'];
    const OBJECTS=['boat','dolphins','snorkelers','coral_reef','landmark','landscape','hotel','person','food','vehicle','architecture','wildlife','other'];
    const OBJECT_TOKENS=[
        'boat'=>['en'=>['boat','vessel','yacht'],'es'=>['embarcación','barco','yate']],
        'dolphins'=>['en'=>['dolphin'],'es'=>['delfin','delfín']],
        'snorkelers'=>['en'=>['snorkel','swimmer','diver'],'es'=>['snorkel','nadador','buceador']],
        'coral_reef'=>['en'=>['coral','reef'],'es'=>['coral','arrecife']],
        'landmark'=>['en'=>['landmark','monument'],'es'=>['monumento']],
        'landscape'=>['en'=>['landscape','scenery'],'es'=>['paisaje']],
        'hotel'=>['en'=>['hotel'],'es'=>['hotel']],
        'person'=>['en'=>['person','people'],'es'=>['persona','personas']],
        'food'=>['en'=>['food','meal'],'es'=>['comida']],
        'vehicle'=>['en'=>['vehicle','car'],'es'=>['vehículo','coche']],
        'architecture'=>['en'=>['building','architecture'],'es'=>['edificio','arquitectura']],
        'wildlife'=>['en'=>['wildlife','animal'],'es'=>['fauna','animal']],
    ];

    public static function catalog():array {
        return [
            'destination_relevance'=>['require'=>'source_taxonomy','purpose'=>'ground_destination_in_tour_context'],
            'visual_truthfulness'=>['require'=>'image_observation','purpose'=>'distinguish_visual_representation_from_location_or_photo_claim'],
            'multilingual_seo'=>['require'=>'wpml_attachment_family','purpose'=>'write_natural_language_specific_alt_drafts'],
            'brand_alignment'=>['require'=>'brand_context_snapshot','purpose'=>'apply_brand_review_and_style_inheritance'],
            'media_role'=>['require'=>'provider_field_contract','purpose'=>'respect_featured_gallery_grid_vertical_role'],
            'search_intent'=>['require'=>'tour_and_destination','purpose'=>'describe_real_customer_experience_without_stuffing'],
            'provider_adaptation'=>['require'=>'active_provider_configuration','purpose'=>'respect_wpml_rank_math_jetengine_meta_contracts'],
        ];
    }
    private static function invalid($code):WP_Error {
        return new WP_Error($code,'Strategy input, evidence or site state is inconsistent.');
    }
    private static function plain($value):string {
        return is_string($value)?trim($value):'';
    }
    private static function terms($id):array {
        $terms=get_the_terms($id,'destinations');
        $out=[];
        foreach(is_array($terms)?$terms:[] as $term) {
            if(!$term instanceof WP_Term)continue;
            $out[$term->slug]=[
                'term_id'=>(int)$term->term_id,
                'name'=>$term->name,
                'slug'=>$term->slug,
                'parent'=>(int)$term->parent,
            ];
        }
        return $out;
    }
    public static function inspect($post_id,$role='featured'):array {
        $post_id=absint($post_id);
        $post=get_post($post_id);
        if(!$post ||$post->post_status!=='publish'
           ||!in_array($role,self::ALLOWED_ROLES,true))
            return ['contract'=>self::CONTRACT,'ready'=>false,'reason'=>'source_or_role_invalid'];
        $site=MAD4B_SCP_Site_Profile::status();
        if(empty($site['site_uuid'])||empty($site['origin_match'])
            ||empty($site['deployment_binding_match'])||empty($site['environment_match']))
            return ['contract'=>self::CONTRACT,'ready'=>false,'reason'=>'site_profile_unverified'];
        $trid=apply_filters('wpml_element_trid',null,$post_id,'post_'.$post->post_type);
        $set=$trid?apply_filters('wpml_get_element_translations',[],$trid,'post_'.$post->post_type):[];
        $terms=self::terms($post_id);
        $provider=class_exists('MAD4B_SCP_Provider_Field_Intelligence',false)
            ?MAD4B_SCP_Provider_Field_Intelligence::locale_media_audit($post_id):[];
        $brand=MAD4B_SCP_Context_Authority::profile();
        $brand_context=MAD4B_SCP_Brand_Experience_Quality::brand_context();
        $review=MAD4B_SCP_Context_Authority::review_policy();
        return [
            'contract'=>self::CONTRACT,'ready'=>true,
            'strategy_version'=>self::VERSION,
            'site_uuid'=>$site['site_uuid'],
            'environment'=>$site['environment']??'',
            'brand_id'=>$brand['brand_id']??'',
            'brand_name'=>$brand['brand_name']??'',
            'brand_authority'=>$brand_context['authority_level']??'provisional',
            'brand_evidence_sha256'=>$brand_context['brand_evidence_sha256']??'',
            'post_id'=>$post_id,'post_type'=>$post->post_type,
            'source_title'=>$post->post_title,
            'media_role'=>$role,'source_destinations'=>$terms,
            'wpml_locales'=>array_keys((array)$set),
            'wpml_media_audit_ready'=>!empty($provider['complete']),
            'wpml_alt_mode'=>$provider['wpml_alt_translation_mode']??'unconfigured',
            'wpml_attachment_enabled'=>$provider['wpml_attachment_translatable']??false,
            'rank_math_title_mode'=>$provider['rank_math_title_translation_mode']??'unconfigured',
            'rank_math_description_mode'=>$provider['rank_math_description_translation_mode']??'unconfigured',
            'review_mode'=>$review['mode']??'human_only',
            'review_agent_id'=>$review['ai_agent_public_id']??'',
            'policy_inheritance'=>[
                ['layer'=>'global','source'=>'MAD4B Visual SEO Strategy v'.self::VERSION,'override_applied'=>false],
                ['layer'=>'company','source'=>'Site Profile / Brand Context','override_applied'=>false],
                ['layer'=>'content_type','source'=>'WP post type '.$post->post_type,'override_applied'=>false],
                ['layer'=>'taxonomy','source'=>'live destinations terms','override_applied'=>false],
                ['layer'=>'media_field','source'=>'WP/JetEngine provider field discovery '.$role,'override_applied'=>false],
                ['layer'=>'language_market','source'=>'WPML effective locale and Rank Math metadata','override_applied'=>false],
            ],
            'strategies'=>array_keys(self::catalog()),
            'proposed_text_written'=>false,'automatic_approval'=>false,
        ];
    }
    private static function contains($haystack,$needle):bool {
        return $needle!=='' && mb_stripos($haystack,$needle,0,'UTF-8')!==false;
    }
    public static function evaluate(array $input) {
        $expected=['post_id','media_role','destination_slug','image_sha256','gallery_sha256',
            'visual_representation','observed_objects','alt_drafts','market'];
        $keys=array_keys($input);sort($keys);sort($expected);
        if($keys!==$expected)return self::invalid('mad4b_visual_strategy_input_contract_invalid');
        $id=absint($input['post_id']);
        $role=(string)$input['media_role'];
        if(!in_array($role,self::ALLOWED_ROLES,true) ||$role!=='featured')
            return self::invalid('mad4b_visual_strategy_role_not_implemented');
        $context=self::inspect($id,$role);
        if(empty($context['ready'])||empty($context['wpml_media_audit_ready']))
            return self::invalid('mad4b_visual_strategy_context_not_ready');
        $slug=sanitize_title((string)$input['destination_slug']);
        if(!isset($context['source_destinations'][$slug]) ||
           !hash_equals((string)$context['source_destinations'][$slug]['slug'],(string)$input['destination_slug']))
            return self::invalid('mad4b_visual_strategy_destination_not_in_taxonomy');
        $destination=$context['source_destinations'][$slug];
        $type=(string)$input['visual_representation'];
        if(!in_array($type,self::VISUAL_TYPES,true))
            return self::invalid('mad4b_visual_strategy_visual_type_invalid');
        $objs=$input['observed_objects'];
        if(!is_array($objs)||!array_is_list($objs)||count($objs)<1||count($objs)>10
           ||count(array_unique($objs))!==count($objs)
           ||array_diff($objs,self::OBJECTS))
            return self::invalid('mad4b_visual_strategy_visual_evidence_invalid');
        $market=(string)$input['market'];
        if(!preg_match('/^(inherit|[a-z]{2})$/D',$market))
            return self::invalid('mad4b_visual_strategy_market_invalid');
        $gallery=(string)$input['gallery_sha256'];
        $image=(string)$input['image_sha256'];
        if(!preg_match('/^[a-f0-9]{64}$/D',$gallery)||
            !preg_match('/^[a-f0-9]{64}$/D',$image)||
            !hash_equals($gallery,hash('sha256',(string)get_post_meta($id,'gallery',true))))
            return self::invalid('mad4b_visual_strategy_gallery_drift');
        $trid=apply_filters('wpml_element_trid',null,$id,'post_tours-and-activities');
        $set=apply_filters('wpml_get_element_translations',[],$trid,'post_tours-and-activities');
        $drafts=$input['alt_drafts'];
        if(!is_array($drafts)||!$drafts||count($drafts)>15||!is_array($set))
            return self::invalid('mad4b_visual_strategy_locales_invalid');
        $known=array_keys($set);sort($known);
        $provided=array_keys($drafts);sort($provided);
        if($known!==$provided)
            return self::invalid('mad4b_visual_strategy_locales_must_cover_family');
        if($context['wpml_alt_mode']!=='translate'||!$context['wpml_attachment_enabled'])
            return self::invalid('mad4b_visual_strategy_provider_translation_not_ready');
        $results=[];$attachments=[];$seen_images=[];
        foreach($drafts as $lang=>$sentence){
            if(!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',(string)$lang)
                ||!is_string($sentence))return self::invalid('mad4b_visual_strategy_locale_text_invalid');
            $sentence=trim($sentence);
            if(mb_strlen($sentence)<45||mb_strlen($sentence)>170
               ||wp_strip_all_tags($sentence)!==$sentence
               ||preg_match('/https?:\\/\\//i',$sentence))
                return self::invalid('mad4b_visual_strategy_text_length_or_html');
            $post=(int)($set[$lang]->element_id??0);
            if($post<=0||get_post_status($post)!=='publish')
                return self::invalid('mad4b_visual_strategy_locale_post_missing');
            $locale_terms=self::terms($post);
            if(!isset($locale_terms[$slug]))
                return self::invalid('mad4b_visual_strategy_locale_destination_missing');
            $name=$locale_terms[$slug]['name'];
            if(!self::contains($sentence,$name))
                return self::invalid('mad4b_visual_strategy_destination_context_missing');
            if(substr_count(mb_strtolower($sentence),mb_strtolower($name))>2)
                return self::invalid('mad4b_visual_strategy_destination_repetition');
            if($type==='illustrative_composite' && !(
                ($lang==='en' && preg_match('/^Illustration\\b/u',$sentence))
                ||($lang==='es' && preg_match('/^Ilustraci[oó]n\\b/u',$sentence))
            ))return self::invalid('mad4b_visual_strategy_illustration_disclosure_missing');
            if($type!=='photograph'
                && preg_match('/\\b(?:photograph(?:ed|y)?|photo taken|captured at|fotograf[ií]a real|fotografi(?:ada|ado) en)\\b/iu',$sentence))
                return self::invalid('mad4b_visual_strategy_unverified_photography_claim');
            $matched=0;
            foreach($objs as $object) {
                foreach(self::OBJECT_TOKENS[$object][$lang]??[] as $token) {
                    if(self::contains($sentence,$token)){$matched++;break;}
                }
            }
            if($matched<min(2,count($objs)))
                return self::invalid('mad4b_visual_strategy_insufficient_visual_semantics');
            $attachment=get_post_thumbnail_id($post);
            $file=$attachment?get_attached_file($attachment):'';
            if(!$attachment||!is_file((string)$file)||
               !str_starts_with((string)get_post_mime_type($attachment),'image/'))
                return self::invalid('mad4b_visual_strategy_locale_image_missing');
            $actual=hash_file('sha256',$file);
            if(!hash_equals($image,$actual))
                return self::invalid('mad4b_visual_strategy_locale_image_sha_drift');
            $attachments[$lang]=['post_id'=>$post,'attachment_id'=>$attachment,
                'image_sha256'=>$actual,'alt_text_current'=>get_post_meta($attachment,'_wp_attachment_image_alt',true)];
            $results[$lang]=[
                'alt_text_draft'=>$sentence,
                'destination'=>$name,
                'observed_objects_matched'=>$matched,
                'rank_math_title_present'=>trim((string)get_post_meta($post,'rank_math_title',true))!=='',
                'review_required'=>true,
            ];
        }
        $layers=$context['policy_inheritance'];
        $strategies=[];
        foreach(self::catalog() as $key=>$definition){
            $strategies[$key]=[
                'status'=>'applied_to_proposal',
                'requirement'=>$definition['require'],
                'decision'=>$definition['purpose'],
                'source'=>match($key){
                    'destination_relevance'=>'WPML destinations taxonomy / '.$slug,
                    'visual_truthfulness'=>'signed visual observation, requires editorial confirmation',
                    'multilingual_seo'=>'WPML locale family and Alt configuration',
                    'brand_alignment'=>'Brand Context evidence '.$context['brand_evidence_sha256'],
                    'media_role'=>'featured image and provider media field',
                    'search_intent'=>'tour title and destination taxonomy',
                    default=>'WPML, WordPress, JetEngine and Rank Math configuration',
                },
                'automatic_approval'=>false
            ];
        }
        $canon=[
            'version'=>self::VERSION,'source_post_id'=>$id,
            'site_uuid'=>$context['site_uuid'],'image_sha256'=>$image,
            'gallery_sha256'=>$gallery,'taxonomy_slug'=>$slug,
            'brand_evidence_sha256'=>$context['brand_evidence_sha256'],
            'review_mode'=>$context['review_mode'],'role'=>$role,
            'representation'=>$type,'objects'=>$objs,'drafts'=>$drafts,
            'market'=>$market,
        ];
        return [
            'contract'=>self::CONTRACT,'status'=>'review_ready',
            'strategy_version'=>self::VERSION,'strategy_fingerprint'=>hash('sha256',wp_json_encode($canon,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
            'context'=>$context,
            'source_image_sha256'=>$image,'source_gallery_sha256'=>$gallery,
            'source_destination'=>$destination,
            'observed_visual_type'=>$type,'observed_objects'=>$objs,
            'observation_provenance'=>'agent_visual_observation_bound_to_image_sha',
            'observation_is_human_approved'=>false,
            'selected_strategies'=>$strategies,
            'policy_inheritance'=>$layers,
            'localized_alt_suggestions'=>$results,'attachments'=>$attachments,
            'review_mode_inherited'=>$context['review_mode'],
            'recommended_next_action'=>'human_or_policy_authorized_review_then_apply_and_verify',
            'physical_media_modified'=>false,
            'alt_text_written'=>false,
            'brand_review_completed'=>false,
            'publication_authorized'=>false,
        ];
    }
    public static function cli($args,$assoc){
        if($args || array_diff(array_keys($assoc),['post-id','role']))
            WP_CLI::error('Usage: wp mad4b visual-strategy --post-id=ID [--role=featured]');
        $r=self::inspect(absint($assoc['post-id']??0),(string)($assoc['role']??'featured'));
        WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        if(empty($r['ready']))WP_CLI::halt(3);
    }
}
if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b visual-strategy',[MAD4B_SCP_Visual_SEO_Strategy::class,'cli']);
