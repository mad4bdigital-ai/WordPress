<?php
defined('ABSPATH') || exit;

/**
 * Central Staging-only inbound research queue.
 * Keeps test-only drafts out of the published tenant Content Hub catalog.
 */
final class MAD4B_Hub_Research_Feed {
    const CONTRACT = 'mad4b.hub.research-feed.v1';
    const META = '_mad4b_hub_research_inbox_v1';
    const LAST_OPTION = 'mad4b_hub_research_e2e_last_v1';

    private static function allowed() {
        return wp_get_environment_type() === 'staging'
            && class_exists('MAD4B_Content_Hub')
            && MAD4B_Content_Hub::permission()
            && class_exists('MAD4B_SCP_Research_Groups')
            && class_exists('MAD4B_SCP_Research_Task_Bridge')
            && class_exists('MAD4B_SCP_Firecrawl_Provider');
    }

    private static function err($code, $message) {
        return new WP_Error($code,$message,array('status'=>403));
    }

    public static function preview($id) {
        if(!self::allowed())return self::err('hub_research_admin_required','Central Staging administrator required');
        $id=absint($id);
        $post=get_post($id);
        if(!$post || $post->post_type!=='tours-and-activities' || $post->post_status!=='draft'
            || get_post_meta($id,'_mad4b_is_test_data',true)!=='1')
            return self::err('hub_research_test_draft_required','Only isolated Staging test drafts can enter this preview');
        $queue=get_post_meta($id,self::META,true);
        if(!is_array($queue)||($queue['contract']??'')!==self::CONTRACT)
            return self::err('hub_research_not_queued','Draft has not entered the Hub review inbox');
        $group_uid=(string)get_post_meta($id,MAD4B_SCP_Research_Task_Bridge::BINDING_META,true);
        if($group_uid===''||$group_uid!==($queue['research_group_uid']??''))
            return self::err('hub_research_binding_drift','Research group binding differs from queued evidence');
        return array(
            'contract'=>self::CONTRACT,'queue_state'=>'awaiting_editor_review',
            'post_id'=>$id,'post_status'=>'draft',
            'post_type'=>$post->post_type,'title'=>$post->post_title,
            'excerpt'=>$post->post_excerpt,'source_url'=>$queue['source_url'],
            'source_sha256'=>$queue['source_sha256'],
            'research_group_uid'=>$group_uid,'research_record_uid'=>$queue['research_record_uid'],
            'candidate_version'=>$queue['candidate_version'],
            'captured_at'=>$queue['captured_at'],
            'source_provider'=>'firecrawl',
            'test_only'=>true,'eligible_for_public_catalog'=>false,
            'external_distribution_enabled'=>false,
            'publish_authorized'=>false,'commerce_mutation_performed'=>false,
            'mutation_performed'=>false,
        );
    }

    public static function inbox() {
        if(!self::allowed())return self::err('hub_research_admin_required','Central Staging administrator required');
        $posts=get_posts(array('post_type'=>'tours-and-activities','post_status'=>'draft',
            'meta_key'=>self::META,'posts_per_page'=>25,'orderby'=>'modified','order'=>'DESC',
            'suppress_filters'=>true));
        $items=array();
        foreach($posts as $p) {
            $view=self::preview((int)$p->ID);
            if(!is_wp_error($view))$items[]=$view;
        }
        return array('contract'=>self::CONTRACT.'.inbox','items'=>$items,
            'count'=>count($items),'source'=>'staging_research_evidence',
            'published_count'=>0,'tenant_distribution_enabled'=>false,
            'read_only'=>true,'mutation_performed'=>false);
    }

    public static function run() {
        if(!self::allowed())return self::err('hub_research_admin_required','Central Staging administrator required');
        $existing=absint(get_option(self::LAST_OPTION,0));
        if($existing) {
            $view=self::preview($existing);
            if(!is_wp_error($view))return array('reused'=>true,'inbox_candidate'=>$view);
        }
        $site=MAD4B_SCP_Site_Profile::status();
        if(empty($site['authority_ready'])||empty($site['deployment_binding_match']))
            return self::err('hub_research_site_profile_unready','Current central site profile not verified');
        $site_uuid=(string)($site['site_uuid']??'');
        $captured=MAD4B_SCP_Firecrawl_Provider::scrape_owned_source(home_url('/'));
        if(is_wp_error($captured))return $captured;
        $task='hub-content-e2e-'.gmdate('Ymd');
        $group=MAD4B_SCP_Research_Groups::group($task,$site_uuid,'en');
        if(is_wp_error($group))return $group;

        $markdown=(string)$captured['markdown'];
        $plain=trim(wp_strip_all_tags($markdown));
        $summary=trim(substr(preg_replace('/\s+/u',' ',$plain),0,900));
        $research=MAD4B_SCP_Research_Groups::add_record(
            (int)$group['id'],'firecrawl','en',(string)$captured['source_url'],
            array('source_sha256'=>$captured['source_sha256'],
                'source_title'=>get_bloginfo('name'),
                'scraped_markdown'=>substr($markdown,0,50000),
                'captured_at'=>$captured['captured_at'],
                'source_kind'=>'site_owned_public_homepage')
        );
        if(is_wp_error($research))return $research;
        $draft=wp_insert_post(array(
            'post_type'=>'tours-and-activities','post_status'=>'draft',
            'post_title'=>'MAD4B Staging E2E — Research Content Review',
            'post_excerpt'=>$summary,
            'post_content'=>wp_kses_post('<p>'.esc_html(substr($plain,0,1800)).'</p>'),
            'post_author'=>get_current_user_id(),
            'meta_input'=>array('_mad4b_is_test_data'=>'1',
                '_mad4b_data_classification'=>'test_only',
                '_mad4b_hub_preview_only'=>'1',
                '_mad4b_research_source_url'=>$captured['source_url'])
        ),true);
        if(is_wp_error($draft))return $draft;
        $binding=MAD4B_SCP_Research_Task_Bridge::bind($task,$site_uuid,$draft,'en');
        if(is_wp_error($binding)){wp_delete_post($draft,true);return $binding;}
        $receipt=array('contract'=>self::CONTRACT,
            'source_url'=>$captured['source_url'],
            'source_sha256'=>$captured['source_sha256'],
            'research_group_uid'=>$group['group_uid'],
            'research_record_uid'=>$research['record_uid'],
            'captured_at'=>$captured['captured_at'],
            'candidate_version'=>hash('sha256',$captured['source_sha256'].'|'.$draft.'|'.$group['group_uid']));
        if(!update_post_meta($draft,self::META,$receipt)){
            wp_delete_post($draft,true);
            return self::err('hub_research_queue_write_failed','Could not save inbound candidate');
        }
        update_option(self::LAST_OPTION,$draft,false);
        $view=self::preview($draft);
        if(is_wp_error($view))return $view;
        return array('reused'=>false,'firecrawl_http'=>$captured['http_status'],
            'source_markdown_bytes'=>$captured['length'],'inbox_candidate'=>$view);
    }

    public static function rest_run() {
        $out=self::run();
        return is_wp_error($out)?$out:rest_ensure_response($out);
    }
    public static function rest_inbox() {
        $out=self::inbox();
        return is_wp_error($out)?$out:rest_ensure_response($out);
    }
    public static function rest_preview($request) {
        $out=self::preview($request->get_param('post_id'));
        return is_wp_error($out)?$out:rest_ensure_response($out);
    }
    public static function page() {
        if(!self::allowed())wp_die('Central Staging administrator required',403);
        $nonce=wp_create_nonce('wp_rest');
        $url=rest_url('mad4b-hub/v1/research-feed/run');
        echo '<div class="wrap"><h1>Research → Content Hub (Staging)</h1>';
        echo '<p>One-click Firecrawl capture of this site, persisted research evidence, an isolated draft and a Hub review inbox. Nothing is distributed or published.</p>';
        echo '<button type="button" id="mad4b-hub-e2e" class="button button-primary">Run source-to-Hub E2E test</button>';
        echo '<p><a href="'.esc_url(rest_url('mad4b-hub/v1/research-feed/inbox')).'">Inbox API (administrator only)</a></p>';
        echo '<pre id="mad4b-hub-e2e-output" style="white-space:pre-wrap;max-width:1050px"></pre>';
        echo '<script>(function(){var b=document.getElementById("mad4b-hub-e2e"),o=document.getElementById("mad4b-hub-e2e-output");b.onclick=async function(){b.disabled=true;o.textContent="Running...";try{var r=await fetch('.
            wp_json_encode($url).',{method:"POST",credentials:"same-origin",headers:{"X-WP-Nonce":'.wp_json_encode($nonce).'}});o.textContent=JSON.stringify({http:r.status,data:await r.json()},null,2);}catch(e){o.textContent=String(e)}finally{b.disabled=false}}})();</script></div>';
    }
}
