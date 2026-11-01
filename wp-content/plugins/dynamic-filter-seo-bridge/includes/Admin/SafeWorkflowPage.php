<?php
namespace DynamicFilterSEOBridge\Admin;

use DynamicFilterSEOBridge\Config\Configuration;
use DynamicFilterSEOBridge\Config\ProfileRegistry;
use DynamicFilterSEOBridge\Diagnostics\BuildIdentity;
use DynamicFilterSEOBridge\Runtime\BootGuard;
use DynamicFilterSEOBridge\SEO\NativeFeaturePriority;

/**
 * Read-only operator landing screen, including during Safe Boot.
 * Absolutely no release/option mutation, provider invocation, or public SEO.
 */
final class SafeWorkflowPage {
    public const SLUG = 'dfsb-workflow';
    public function register(): void {
        add_action('admin_menu', array($this,'menu'), 9);
    }
    public function menu(): void {
        if (!current_user_can('manage_options')) { return; }
        add_menu_page('Filter SEO Bridge — Workflow','Filter SEO Bridge','manage_options',self::SLUG,array($this,'render'),'dashicons-filter',62);
    }
    public function facts(): array {
        $safe=BootGuard::status();$release=BootGuard::releaseReadiness();
        $config=new Configuration();$registry=new ProfileRegistry($config);$all=$registry->all();
        $profiles=array();foreach($all as $id=>$profile){
            if (!is_array($profile)) { continue; }
            $profiles[]=array('id'=>sanitize_key((string)($profile['id']??$id)),'enabled'=>!empty($profile['enabled']),
                'routes'=>count((array)($profile['routes']??array())),
                'approved'=>count((array)($profile['indexable_combinations']??array())));
        }
        $native=NativeFeaturePriority::inspect();
        $held=!empty($safe['effective_hold']);
        return array('safe_boot'=>$held,'reason'=>(string)($safe['reason']??''),
            'release_ready'=>!empty($release['ready']),
            'release_reason'=>(string)($release['reason']??''),
            'global_enabled'=>$config->enabled(),
            'profiles'=>$profiles,
            'rank_math'=>defined('RANK_MATH_VERSION')||class_exists('RankMath'),
            'jet_smart_filters'=>!empty($native['native_filter_plugin_available']),
            'jet_engine'=>function_exists('jet_engine'),
            'elementor'=>defined('ELEMENTOR_VERSION'),
            'wpml'=>defined('ICL_SITEPRESS_VERSION'),
            'native_ownership'=>(string)$native['overlapping_seo_owner'],
            'backend_ready'=>!$held,
            'seo_publication_allowed'=>!$held&&$config->enabled()&&!empty($release['ready']));
    }
    public function render(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Access denied','dynamic-filter-seo-bridge')); }
        $f=$this->facts();$profiles=$f['profiles'];$hasProfile=!empty($profiles);
        $readyProfiles=array_values(array_filter($profiles,static fn($x)=>$x['routes']>0));
        $steps=array(
            array('Setup','Discover JetSmartFilters, JetEngine, Elementor and Rank Math',true,'dfsb-inventory-control'),
            array('Configure','Select the archive, query and taxonomy bindings',!empty($readyProfiles),'dfsb-inventory-control'),
            array('Design','Bind H1, intro, gallery and background in Elementor',!empty($readyProfiles),'dfsb-dynamic-content'),
            array('Test','Verify direct URLs, AJAX counts, listings and responsive media',!empty($readyProfiles),'dfsb-control-center'),
            array('SEO Policy','Review indexable combinations and Rank Math ownership',!empty($readyProfiles),'dfsb-publication'),
            array('Activate & Monitor','Certify the package, stage, approve and monitor',!$f['safe_boot']&&$f['release_ready'],'dfsb-control-center'),
        );
        echo '<div class="wrap"><h1>Dynamic Filter SEO Bridge — Workflow</h1>';
        echo '<p>Read-only setup and release status. No preview, verification or administrative view can authorize SEO publication by itself.</p>';
        echo '<div style="border:1px solid #c3c4c7;background:#fff;padding:15px;max-width:1020px">';
        echo '<h2 style="margin-top:0">Release and access</h2>';
        echo '<p><strong>Safe Boot:</strong> '.esc_html($f['safe_boot']?'Held — full runtime protected':'Not held').'</p>';
        echo '<p><strong>Release manifest:</strong> '.esc_html($f['release_ready']?'Verified':'Not certified').'</p>';
        if ($f['safe_boot']) {echo '<p><strong>Reason:</strong> '.esc_html($f['reason']).'</p>';}
        if (!$f['release_ready']) {echo '<p><strong>Release blocker:</strong> '.esc_html($f['release_reason']).'</p>';}
        echo '<p><strong>Global publication:</strong> '.esc_html($f['seo_publication_allowed']?'Eligible for policy evaluation':'Not permitted').'</p>';
        echo '<p><strong>SEO ownership:</strong> '.esc_html($f['native_ownership']).' (Rank Math is the publishing layer).</p>';
        echo '</div>';
        echo '<p><a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page=dfsb-media-sources')).'">Configure Shared / Company Private Media</a></p>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=dfsb-context-audit')).'">Review dynamic CPT, Taxonomy and Provider contexts</a></p>';
        // Read-only authoritative SEO preview remains safe while full boot
        // is held. This never activates any public metadata hooks.
        $seoTypes=$f['safe_boot']?array('tours-and-activities'):(array)apply_filters('dfsb/seo/eligible_archive_post_types',array('tours-and-activities'));
        echo '<h2>SEO source preview (no publication)</h2>';
        echo '<table class="widefat striped" style="max-width:1020px"><thead><tr><th>Archive</th><th>SEO title fallback</th><th>Meta description fallback</th><th>Social image source</th></tr></thead><tbody>';
        $seoCount=0;
        foreach($seoTypes as $seoType){
            $seoType=sanitize_key((string)$seoType);
            if(!post_type_exists($seoType)||$seoCount++>=12)continue;
            $preview=\DynamicFilterSEOBridge\SEO\SiteSeoSources::archiveInfo($seoType,!$f['safe_boot']);
            if(!$preview)continue;
            echo '<tr><th>'.esc_html($preview['label']).'</th><td>'.esc_html($preview['title']).'</td><td>'.esc_html($preview['description']).'</td><td>'.esc_html($preview['image_url']).'</td></tr>';
        }
        echo '</tbody></table>';
        $siteIdentity=\DynamicFilterSEOBridge\SEO\SiteSeoSources::identity(!$f['safe_boot']);
        echo '<p><strong>Company SEO identity source:</strong> WordPress Site Title = '.esc_html($siteIdentity['name']).'; Site URL = '.esc_html($siteIdentity['url']).'. Verified media ID: '.esc_html((string)$siteIdentity['logo_id']).'.</p>';
        echo '<p><em>Rank Math outputs the final metadata after a certified full boot. Existing authored metadata always takes precedence, and JetSmartFilters keeps ownership of filter combinations. No indexability is changed here.</em></p>';
        // These checks only READ WordPress/Rank Math options and Elementor
        // serialized template settings; never load a blocked publication adapter.
        $audit=\DynamicFilterSEOBridge\SEO\SafeBootSeoAudit::inspect();
        echo '<h2>Safe Boot SEO source audit (read-only)</h2>';
        echo '<p>These checks compare WordPress company identity against Rank Math stored values and Elementor/JetBlocks schema owners. They do not repair options, change templates, clear Safe Boot or activate public SEO.</p>';
        echo '<table class="widefat striped" style="max-width:1020px"><thead><tr><th>Check</th><th>Status</th><th>Observed source</th><th>Expected authority</th></tr></thead><tbody>';
        foreach((array)($audit['checks']??array()) as $check){
            echo '<tr><th>'.esc_html(str_replace('_',' ',(string)($check['id']??''))).'</th><td>'.esc_html((string)($check['status']??'unknown')).'</td><td>'.esc_html((string)($check['observed']??'')).'</td><td>'.esc_html((string)($check['expected']??'')).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>Archive templates scanned:</strong> '.esc_html((string)($audit['breadcrumbs']['archive_templates_scanned']??0)).'; <strong>JetBlocks JSON-LD emitters:</strong> '.esc_html((string)($audit['breadcrumbs']['emitting_count']??0)).'. One Rank Math BreadcrumbList should be the final schema owner when Rank Math is active.</p>';
        echo '<p><em>Source drift may still be visible in public HTML during Safe Boot. Correct native plugin settings through their standard workflow or certify the package before activating Bridge hooks; audit is non-authorizing.</em></p>';
        echo '<h2>Provider discovery</h2><table class="widefat striped" style="max-width:1020px"><thead><tr><th scope="col">Provider</th><th scope="col">Detected</th></tr></thead><tbody>';
        foreach(array('JetSmartFilters'=>'jet_smart_filters','JetEngine'=>'jet_engine','Elementor'=>'elementor','Rank Math'=>'rank_math','WPML'=>'wpml')as $label=>$key){echo '<tr><th scope="row">'.esc_html($label).'</th><td>'.esc_html($f[$key]?'Available':'Not detected').'</td></tr>';}
        echo '</tbody></table><h2>Profiles</h2>';
        if (!$hasProfile) {echo '<p>No profiles configured.</p>';} else {
            echo '<table class="widefat striped" style="max-width:1020px"><thead><tr><th>Profile</th><th>Enabled</th><th>Routes</th><th>Approved SEO combinations</th></tr></thead><tbody>';
            foreach($profiles as $profile){echo '<tr><th>'.esc_html($profile['id']).'</th><td>'.esc_html($profile['enabled']?'Yes':'No').'</td><td>'.esc_html((string)$profile['routes']).'</td><td>'.esc_html((string)$profile['approved']).'</td></tr>';}
            echo '</tbody></table>';
        }
        echo '<h2>Guided lifecycle</h2><ol style="max-width:1020px">';
        foreach($steps as $s){
            [$title,$desc,$prepared,$slug]=$s;
            echo '<li style="margin-bottom:15px;padding:12px;background:#fff;border:1px solid #dcdcde"><strong>'.esc_html($title).'</strong> — '.esc_html($desc).'<p style="margin:6px 0">'.esc_html(!$prepared?'Needs prerequisites':($f['safe_boot']?'Read-only / release held':'Prepared for review')).'</p>';
            if(!$f['safe_boot']&&$prepared){echo '<a href="'.esc_url(admin_url('options-general.php?page='.$slug)).'">Open advanced tool</a>';}
            elseif($f['safe_boot']){echo '<span>Advanced tools locked by Safe Boot. Resolve and verify the release artifact before retrying full boot.</span>';}
            echo '</li>';
        }
        echo '</ol>';
        echo '<p><strong>Preview rule:</strong> when a verified bundle is booted, read-only presentation for configured profiles is independent of Global SEO publishing. While Safe Boot is held, only authorized external/lab preview can be used; this page never bypasses integrity checks.</p>';
        echo '<p><strong>Background gallery:</strong> ensure the archive template has healthy media or a native Elementor slideshow fallback. A filtered request must never erase the last valid image without an explicit Hide policy.</p>';
        echo '</div>';
    }
}
