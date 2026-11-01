<?php
namespace DynamicFilterSEOBridge\SEO;

use DynamicFilterSEOBridge\Runtime\BootGuard;

/**
 * Operator-only readback of SEO-source drift, safe while DFSB full boot is
 * held. Never registers public filters, changes options or loads the provider.
 */
final class SafeBootSeoAudit {
    public static function inspect(string $postType='tours-and-activities'): array {
        $safe=BootGuard::status();
        $release=BootGuard::releaseReadiness();
        $identity=SiteSeoSources::identity(false);
        $proposed=SiteSeoSources::archiveInfo($postType,false);
        $rank=(array)get_option('rank-math-options-titles',array());
        $rawName=SiteSeoSources::plain((string)($rank['knowledgegraph_name']??''));
        $rawWebsite=SiteSeoSources::plain((string)($rank['website_name']??''));
        $rawLogo=(string)($rank['knowledgegraph_logo']??'');
        $rawLogoHost=(string)wp_parse_url($rawLogo,PHP_URL_HOST);
        $homeHost=(string)wp_parse_url((string)$identity['url'],PHP_URL_HOST);
        $archiveSettings=(string)($rank['pt_'.$postType.'_description']??'');
        $breadcrumbs=self::inspectArchiveBreadcrumbs();
        $rankAvailable=class_exists('RankMath') || defined('RANK_MATH_VERSION');
        $descriptionMissingInSource=$archiveSettings===''||str_contains($archiveSettings,'%excerpt%');
        $rows=array(
            array('id'=>'rank_math_organization_brand','status'=>$rawName!=='' && $rawName===$identity['name']?'pass':'drift',
                'expected'=>$identity['name'],'observed'=>$rawName,'owner'=>'Rank Math / WordPress Site Title'),
            array('id'=>'rank_math_site_name','status'=>$rawWebsite!=='' && $rawWebsite===$identity['name']?'pass':'drift',
                'expected'=>$identity['name'],'observed'=>$rawWebsite,'owner'=>'Rank Math / WordPress Site Title'),
            array('id'=>'rank_math_legacy_logo_host','status'=>$rawLogoHost!==''&&strcasecmp($rawLogoHost,$homeHost)===0?'pass':($rawLogo===''?'missing':'drift'),
                'expected'=>$homeHost,'observed'=>$rawLogoHost,'owner'=>'Rank Math / company media ID'),
            array('id'=>'rank_math_archive_description','status'=>$descriptionMissingInSource?'missing':'configured',
                'expected'=>(string)($proposed['description']??''),'observed'=>$archiveSettings,'owner'=>'Rank Math / JetEngine archive description'),
            array('id'=>'jetblocks_breadcrumb_schema','status'=>!$rankAvailable?'single_native_source':($breadcrumbs['emitting_count']===0?'pass':'duplicate_risk'),
                'expected'=>'0 extra JetBlocks JSON-LD emitters','observed'=>(string)$breadcrumbs['emitting_count'],'owner'=>'JetBlocks visual / Rank Math structured data'),
            array('id'=>'minimum_php_compatibility','status'=>version_compare(PHP_VERSION,'8.1','>=')?'pass':'blocked',
                'expected'=>'PHP 8.1 or newer (array_is_list)','observed'=>PHP_VERSION,'owner'=>'WordPress plugin requirements'),
            array('id'=>'safe_boot_integrity','status'=>!empty($safe['effective_hold'])?'held':'verify_runtime',
                'expected'=>'Never bypass package integrity','observed'=>(string)$release['reason'],'owner'=>'DFSB release attestation'),
        );
        return array('contract'=>'dfsb.seo.safe-boot-readback.v1','read_only'=>true,
            'authorizing'=>false,'publication_permitted'=>false,
            'safe_boot_held'=>!empty($safe['effective_hold']),
            'release_ready'=>!empty($release['ready']),
            'identity'=>$identity,'archive'=>$proposed,
            'breadcrumbs'=>$breadcrumbs,'checks'=>$rows);
    }

    private static function inspectArchiveBreadcrumbs(): array {
        $ids=get_posts(array('post_type'=>'elementor_library','post_status'=>'publish',
            'posts_per_page'=>80,'fields'=>'ids','no_found_rows'=>true,
            'meta_query'=>array(array('key'=>'_elementor_template_type','value'=>'archive')),
            'suppress_filters'=>true));
        $count=0;$templates=array();
        foreach((array)$ids as $id){
            $elements=json_decode((string)get_post_meta((int)$id,'_elementor_data',true),true);
            if(!is_array($elements))continue;
            $active=0;
            $walk=static function($parts,int $depth=0)use(&$walk,&$active): void {
                if($depth>22 || !is_array($parts))return;
                foreach($parts as $part){
                    if(!is_array($part))continue;
                    if(($part['widgetType']??'')==='jet-breadcrumbs'){
                        $settings=(array)($part['settings']??array());
                        // JetBlocks Elementor control defaults to yes.
                        $setting=$settings['enable_json_ld']??'yes';
                        if(filter_var($setting,FILTER_VALIDATE_BOOLEAN))$active++;
                    }
                    $walk($part['elements']??array(),$depth+1);
                }
            };
            $walk($elements);
            if($active>0){$templates[]=(int)$id;$count+=$active;}
        }
        return array('archive_templates_scanned'=>count((array)$ids),
            'emitting_count'=>$count,'templates'=>$templates,'scope'=>'published_archive_templates_limited_to_80');
    }
}
