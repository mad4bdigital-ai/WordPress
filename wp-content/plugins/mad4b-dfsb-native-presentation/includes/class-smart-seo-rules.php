<?php
namespace MAD4B\DFSB\NativePresentation;

if (!defined('ABSPATH')) { exit; }

/**
 * Explainable, read-only SEO recommendation engine.
 * Proposes native JetSmartFilters rules; it never edits Rank Math.
 */
final class SmartSeoRules {
    const CONTRACT = 'mad4b.smart-seo-recommendations.v1';
    const OPTION = 'mad4b_jsf_smart_seo_drafts_v1';

    public static function classify(string $taxonomy,string $label=''): string {
        $text = strtolower($taxonomy.' '.$label);
        if (preg_match('/destination|location|cit(y|ies)|country|governorate|area/i',$text)) return 'destination';
        if (preg_match('/tour.type|activity.type|tour.style|categor(y|ies)|cruise|experience/i',$text)) return 'type';
        if (preg_match('/duration|length|days|night/i',$text)) return 'duration';
        if (preg_match('/language|guide/i',$text)) return 'language';
        return 'other';
    }

    public static function evaluate(array $group,string $siteLabel=''): array {
        $issues=array_values(array_unique((array)($group['reasons']??array())));
        $status=(string)($group['status']??'');
        $route=(array)($group['route']??array());
        $company=(array)($group['company_policy']??array());
        if (!empty($company['owner']) && ($company['standalone_allowed']??null)!==true)
            $issues[]='company_policy_standalone_disabled';
        $registry=(array)($group['mu_presentation_registry']??array());
        if (!empty($registry['entity_mismatch']))
            $issues[]='mu_presentation_registry_entity_mismatch';
        $policy=SmartSeoPolicyRegistry::effective((string)($group['provider']??''),
            (string)($group['query_id']??''));
        if (!$policy['enabled']) $issues[]='policy_source_disabled';
        if (!empty($company['owner']) && $policy['require_mu_source_binding']
            && empty($registry['matched'])) $issues[]='mu_source_binding_missing';
        $filters=(array)($group['filters']??array());
        $rendered=array_map('intval',(array)($group['rendered_filter_ids']??array()));
        $qualifiers=array();
        $rejections=array();
        foreach($filters as $f) {
            $id=(int)($f['id']??0);
            if (!$id || empty($f['eligible']) || !in_array($id,$rendered,true)) {
                if ($id) $rejections[$id]=(array)($f['reasons']??array('not_rendered_or_ineligible'));
                continue;
            }
            $kind=self::classify((string)($f['taxonomy']??''),(string)($f['title']??''));
            $termCount=0;
            if (function_exists('taxonomy_exists') && taxonomy_exists((string)$f['taxonomy'])) {
                $n=wp_count_terms(array('taxonomy'=>(string)$f['taxonomy'],'hide_empty'=>true));
                $termCount=is_wp_error($n)?0:(int)$n;
            }
            if (!$termCount) {
                $rejections[$id]=array('no_public_terms_with_published_content');
                continue;
            }
            $f['kind']=$kind;
            $f['public_terms_with_content']=$termCount;
            if (in_array($id,$policy['excluded_filter_ids'],true)
                || ($policy['allowed_filter_ids']
                && !in_array($id,$policy['allowed_filter_ids'],true))) {
                $rejections[$id]=array('policy_filter_excluded');
                continue;
            }
            $customWeights=$policy['weight_overrides'];
            $f['priority']=(int)($customWeights[$id]??self::kindWeight($kind))
                +(int)($f['widget_uses']??0);
            $qualifiers[]=$f;
        }
        usort($qualifiers,static fn($a,$b)=>($b['priority']<=>$a['priority']) ?: ($a['id']<=>$b['id']));
        // Candidate combinations are NOT proof of non-empty filtered results.
        // Bound the projected sitemap expansion before proposing a native rule.
        $chosen=array();
        $combinationBudget=max(1,(int)$policy['max_combinations']);
        $projected=1;
        foreach ($qualifiers as $candidate) {
            if (count($chosen)>=(int)$policy['max_filters']) break;
            $next=$projected*(1+max(0,(int)($candidate['options_count']??0)));
            if ($next-1>$combinationBudget) continue;
            $projected=$next;
            $chosen[]=$candidate;
        }
        $projectedCombinations=$projected-1;
        $score=0;
        if (($route['status']??'')==='inferred_archive_route' && (int)($group['route_http']??0)===200) $score+=25;
        if (!empty($group['public_widget_match'])) $score+=25;
        if ((string)($group['provider']??'')!==''
            && (string)($group['query_id']??'')!=='') $score+=15;
        if (count($chosen)>0) $score+=15;
        if (count($chosen)>=2) $score+=10;
        if ((int)($group['template_count']??0)>0) $score+=5;
        if (count((array)($group['languages']??array()))>0) $score+=5;
        $score=max(0,min(100,$score));
        if ($status!=='candidate_for_manual_rule_review') $issues[]='source_group_not_ready';
        if (count($chosen)<1) $issues[]='no_stable_published_taxonomy_filter';
        if (($route['status']??'')!=='inferred_archive_route') $issues[]='archive_route_not_confirmed';
        if (empty($group['public_widget_match'])) $issues[]='no_live_provider_query_match';
        if ((int)($group['route_http']??0)!==200) $issues[]='archive_http_not_200';
        $ready= !$issues && $score>=80;
        // Technical qualification != content or SEO approval.
        $contentVerified=false; // No real per-combination result evidence yet.
        $localeVerified=false;  // No per-language editorial sign-off yet.
        $indexReady=$ready && $contentVerified && $localeVerified;
        $id=sha1(implode('|',array(
            (string)($group['provider']??''),(string)($group['query_id']??''),
            (string)($route['url']??''),
        )));
        $siteLabel=sanitize_text_field($siteLabel!==''?$siteLabel:get_bloginfo('name'));
        $siteLabel=mb_substr($siteLabel,0,44);
        $primary=$chosen[0]??array();
        $kind=(string)($primary['kind']??'other');
        $main=(int)($primary['id']??0);
        $label=$main?'{{filter_'.$main.'}}':'';
        $postType=(string)($route['post_type']??'');
        $postObj=$postType!==''?get_post_type_object($postType):null;
        $collection=$postObj?sanitize_text_field((string)$postObj->labels->name):'Explore';
        $collection=mb_substr($collection,0,54);
        if ($kind==='destination') {
            $title=trim($label.' '.$collection.' | '.$siteLabel);
            $description=trim('Explore '.$collection.' in '.$label.'. Compare options and discover available experiences.');
        } elseif ($kind==='duration') {
            $title=trim($label.' '.$collection.' | '.$siteLabel);
            $description=trim('Explore '.$collection.' by duration: '.$label.'. Discover experiences that fit your trip.');
        } else {
            $title=trim($label.' '.$collection.' | '.$siteLabel);
            $description=trim('Explore '.$collection.' for '.$label.'. Compare experiences and choose what suits your plans.');
        }
        // Data-driven copy templates (EN as source). Others require native WPML
        // translation/term evidence; no blanket translation claim is made.
        $labels=array(
            'primary'=>$label,
            'secondary'=>isset($chosen[1])?'{{filter_'.$chosen[1]['id'].'}}':'',
            'collection'=>$collection,'site'=>$siteLabel,
        );
        $english=$policy['templates']['en']??array();
        $title=SmartSeoPolicyRegistry::formatTemplate((string)($english['title']??''),$labels,$title);
        $description=SmartSeoPolicyRegistry::formatTemplate((string)($english['description']??''),$labels,$description);
        $localized=array();
        foreach (SmartSeoPolicyRegistry::LANGUAGES as $locale) {
            $t=$policy['templates'][$locale]??array();
            if (!isset($t['title'],$t['description'])) continue;
            $localized[$locale]=array(
                'title'=>SmartSeoPolicyRegistry::formatTemplate((string)$t['title'],$labels,$title),
                'description'=>SmartSeoPolicyRegistry::formatTemplate((string)$t['description'],$labels,$description),
                'reviewed'=>false,
            );
        }
        // Keep macro IDs unchanged, native JSF resolves labels on matching URLs.
        $native=array(
            'url'=>trim((string)parse_url((string)($route['url']??''),PHP_URL_PATH),'/'),
            'provider'=>(string)($group['provider']??''),
            'query_id'=>(string)($group['query_id']??''),
            'filters'=>array_map(static fn($f)=>(string)$f['id'],$chosen),
            'title'=>$title,'description'=>$description,
        );
        $languages=array_values(array_unique((array)($group['languages']??array())));
        return array(
            'id'=>$id,'contract'=>self::CONTRACT,
            'source'=>$group['provider'].'/'.$group['query_id'],
            'source_status'=>$status,'score'=>$score,
            'technical_score'=>$score,
            'content_score'=>null,'seo_score'=>null,
            'index_ready'=>$indexReady,
            'publication_blockers'=>array_values(array_filter(array(
                !$contentVerified?'content_result_evidence_missing':null,
                !$localeVerified?'localized_seo_review_missing':null,
                $projectedCombinations>$combinationBudget?'combination_budget_exceeded':null,
            ))),
            'projected_combinations'=>$projectedCombinations,
            'max_combinations'=>$combinationBudget,
            'effective_policy'=>$policy,
            'localized_templates'=>$localized,
            'confidence'=>$score>=90?'high':($score>=70?'medium':'low'),
            'status'=>$ready?'qualified_draft':'blocked',
            'issues'=>array_values(array_unique($issues)),
            'excluded_filters'=>$rejections,
            'selected_filters'=>array_map(static fn($f)=>array(
                'id'=>(int)$f['id'],'taxonomy'=>$f['taxonomy'],
                'kind'=>$f['kind'],'public_terms_with_content'=>$f['public_terms_with_content'],
                'priority'=>$f['priority'],
            ),$chosen),
            'languages'=>$languages,
            'translation_review_required'=>count($languages)>1 || (!in_array('en',$languages,true) && count($languages)>0),
            'editorial_review_required'=>true,'indexing_approved'=>false,
            'auto_publish'=>false,'seo_publisher'=>'native_jetsmartfilters',
            'proposed_native_rule'=>$ready?$native:null,
        );
    }

    private static function kindWeight(string $kind): int {
        return array('destination'=>50,'type'=>40,'duration'=>35,'language'=>5,'other'=>0)[$kind]??0;
    }

    public static function recommend(bool $probe=true): array {
        $report=DynamicQualification::scan($probe);
        $list=array();
        foreach ($report['groups'] as $group) $list[]=self::evaluate($group);
        usort($list,static fn($a,$b)=>($b['score']<=>$a['score']) ?: strcmp($a['id'],$b['id']));
        return array(
            'contract'=>self::CONTRACT,
            'source_contract'=>$report['contract'],
            'read_only'=>true,'native_rules_created'=>false,
            'native_seo_settings_changed'=>false,
            'site'=>$report['site'],'groups_discovered'=>$report['groups_discovered'],
            'templates_scanned'=>$report['templates_scanned'],
            'qualified'=>count(array_filter($list,static fn($r)=>$r['status']==='qualified_draft')),
            'recommendations'=>$list,
        );
    }

    /** Generate drafts in a separate WordPress option: never auto-enable JSF. */
    public static function saveDrafts(array $report): array {
        $valid=array();
        foreach ((array)($report['recommendations']??array()) as $rec) {
            if (($rec['status']??'')!=='qualified_draft' || empty($rec['proposed_native_rule'])) continue;
            $valid[$rec['id']]=array(
                'id'=>$rec['id'],'status'=>'draft',
                'score'=>$rec['score'],'confidence'=>$rec['confidence'],
                'source'=>$rec['source'],'native_rule'=>$rec['proposed_native_rule'],
                'languages'=>$rec['languages'],
                'editorial_review_required'=>true,
                'indexing_approved'=>false,
                'index_ready'=>false,'technical_score'=>$rec['technical_score'],
                'content_score'=>$rec['content_score'],'seo_score'=>$rec['seo_score'],
                'publication_blockers'=>$rec['publication_blockers'],
                'projected_combinations'=>$rec['projected_combinations'],
                'max_combinations'=>$rec['max_combinations'],
                'effective_policy'=>$rec['effective_policy'],
                'localized_templates'=>$rec['localized_templates'],
                'generated_at'=>gmdate('c'),
            );
        }
        $old=get_option(self::OPTION,array());
        if (!is_array($old)) $old=array();
        foreach ($valid as $id=>$new) {
            if (isset($old[$id]) && in_array(($old[$id]['status']??''),
                array('approved','published','staged_disabled'),true)) continue;
            $old[$id]=$new;
        }
        update_option(self::OPTION,$old,false);
        return array_values($old);
    }

    public static function drafts(): array {
        $v=get_option(self::OPTION,array());
        return is_array($v)?array_values($v):array();
    }
}
