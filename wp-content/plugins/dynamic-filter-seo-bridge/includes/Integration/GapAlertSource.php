<?php
namespace DynamicFilterSEOBridge\Integration;
use DynamicFilterSEOBridge\SEO\SafeBootSeoAudit;
use DynamicFilterSEOBridge\Runtime\BootGuard;

/** Site-wide, stable-fingerprint SEO issues for an explicit MAD4B scan only. */
final class GapAlertSource {
    public const CONTRACT='dfsb.readiness-gap-source.v1';
    public function register(): void {
        add_filter('mad4b/content_readiness/external_issues',array($this,'append'),15,2);
    }
    private function make(string $code,string $severity,string $label): array {
        return array('id'=>hash('sha256',get_current_blog_id().'|site|0|'.$code),
            'post_type'=>'site','object_id'=>0,'code'=>$code,'severity'=>$severity,
            'label'=>$label,'source'=>'dynamic-filter-seo-bridge');
    }
    public function append($existing,$policy=array()) {
        $issues=is_array($existing)?$existing:array();
        if(!class_exists('MAD4B_SCP_Content_Readiness_Alerts'))return $issues;
        // No POST, no task, no notification. SCP itself owns reconciliation.
        try {$audit=SafeBootSeoAudit::inspect();}
        catch(\Throwable $e){
            // MAD4B's discovery rejects incomplete external evidence rather
            // than reconciling an absent source as resolved.
            return new \WP_Error('dfsb_gap_scan_unavailable','Read-only SEO audit failed');
        }
        $statuses=array_column($audit['checks'],'status','id');
        if(($statuses['rank_math_organization_brand']??'')==='drift' ||
           ($statuses['rank_math_site_name']??'')==='drift'){
            $issues[]=$this->make('dfsb_brand_identity_drift','high',
                'Rank Math company identity conflicts with WordPress site title');
        }
        if(($statuses['rank_math_archive_description']??'')==='missing'){
            $issues[]=$this->make('dfsb_archive_meta_missing','medium',
                'Archive Meta Description source has no usable native value');
        }
        if(($statuses['jetblocks_breadcrumb_schema']??'')==='duplicate_risk'){
            $issues[]=$this->make('dfsb_breadcrumb_owner_conflict','medium',
                'JetBlocks archive breadcrumb JSON-LD may duplicate Rank Math');
        }
        if(!empty($audit['safe_boot_held']) && empty($audit['release_ready'])){
            $issues[]=$this->make('dfsb_release_not_certified','high',
                'DFSB installed source does not have release certification');
        }
        return $issues;
    }
}
