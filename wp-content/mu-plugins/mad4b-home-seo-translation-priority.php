<?php
/**
 * Plugin Name: MAD4B Home SEO & Priority Translation Alerts
 * Description: Native Rank Math homepage controls and priority-ranked WPML translation gaps in MAD4B Content Readiness.
 * Version: 1.2.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Home_SEO_Translation_Priority {
    const STORE = 'mad4b_home_translation_alerts_v1';
    const REV = 'mad4b_home_seo_translation_source_v1';
    const HOOK = 'mad4b_home_translation_priority_hourly_v1';
    const SOURCE = 'mad4b-home-translation';
    private static $busy = false;

    private static function home(): int {
        $id = (int) get_option('page_on_front');
        return $id > 0 && get_post_status($id) === 'publish' ? $id : 0;
    }
    private static function languages(): array {
        $data = (array)get_option('icl_sitepress_settings', []);
        $active = (array)($data['active_languages'] ?? []);
        $default = (string)($data['default_language'] ?? 'en');
        return array_values(array_filter($active, static fn($v) =>
            is_string($v) && preg_match('/^[a-z]{2}$/D', $v) && $v !== $default));
    }
    private static function option_text(string $key): string {
        $options = get_option('look-and-feel', []);
        return is_array($options) && is_scalar($options[$key] ?? null)
            ? trim((string)$options[$key]) : '';
    }
    private static function digest(string $area): string {
        if ($area === 'seo') {
            $text = [self::option_text('mad4b_home_seo_title'),
                self::option_text('mad4b_home_seo_description')];
        } else {
            $text = [self::option_text('mad4b_hero_headline'),
                self::option_text('mad4b_hero_subtitle')];
        }
        return hash('sha256', wp_json_encode($text, JSON_UNESCAPED_UNICODE));
    }
    private static function localized_id(int $source, string $type, string $lang): int {
        if (!has_filter('wpml_object_id')) return 0;
        $id = (int)apply_filters('wpml_object_id', $source, $type, false, $lang);
        if ($id === $source || $id < 1 || get_post_status($id) !== 'publish') return 0;
        $details = apply_filters('wpml_post_language_details', null, $id);
        return is_array($details) && ($details['language_code'] ?? '') === $lang ? $id : 0;
    }
    private static function string_translation_present(string $field, string $lang): bool {
        global $wpdb;
        $strings = $wpdb->prefix . 'icl_strings';
        $translations = $wpdb->prefix . 'icl_string_translations';
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $strings WHERE context=%s AND name=%s LIMIT 1",
            'mad4b-home-first-screen', $field
        ));
        if (!$id) return false;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status,value FROM $translations WHERE string_id=%d AND language=%s LIMIT 1",
            $id, $lang), ARRAY_A);
        return is_array($row) && (int)$row['status'] === 10
            && trim((string)$row['value']) !== '';
    }
    private static function hero_ready(string $lang): bool {
        $id = self::localized_id(41727, 'elementor_library', $lang);
        if (!$id) return false;
        $nodes = json_decode((string)get_post_meta($id, '_elementor_data', true), true);
        if (!is_array($nodes)) return false;
        $hit = [];
        $walk = static function($items) use (&$walk,&$hit): void {
            foreach ((array)$items as $node) {
                if (!is_array($node)) continue;
                $id = (string)($node['id'] ?? '');
                if ($id === 'ea1eb12' || $id === 'ab47c1d') {
                    $settings = (array)($node['settings'] ?? []);
                    $binding = (string)($settings['__dynamic__']['title'] ?? '');
                    $hit[$id] = str_contains($binding, 'name="mad4b-home-hero-copy"')
                        && ($settings['hide_mobile'] ?? '') !== 'hidden-mobile';
                }
                $walk((array)($node['elements'] ?? []));
            }
        };
        $walk($nodes);
        return !empty($hit['ea1eb12']) && !empty($hit['ab47c1d'])
            && self::string_translation_present('mad4b_hero_headline', $lang)
            && self::string_translation_present('mad4b_hero_subtitle', $lang);
    }
    private static function seo_target_hash(string $lang): string {
        $page = self::localized_id(self::home(), 'page', $lang);
        if (!$page) return '';
        $title = trim((string)get_post_meta($page, 'rank_math_title', true));
        $desc = trim((string)get_post_meta($page, 'rank_math_description', true));
        if ($title === '' || $desc === '') return '';
        return hash('sha256', wp_json_encode([$title, $desc], JSON_UNESCAPED_UNICODE));
    }
    public static function scan(bool $store = false): array {
        $home = self::home();
        if (!$home) return ['contract'=>'mad4b.home-translation-priority.v1',
            'error'=>'published_front_page_required', 'items'=>[]];
        $prior = (array)get_option(self::STORE, []);
        $out = []; $now = gmdate('c');
        foreach (self::languages() as $lang) {
            foreach (['hero','seo'] as $area) {
                $code = 'home_copy_'.$area.'_'.$lang;
                $fingerprint = hash('sha256', home_url('/').'|'.$code);
                $source_hash = self::digest($area);
                $prev = (array)($prior[$code] ?? []);
                $target_hash = $area === 'seo' ? self::seo_target_hash($lang) : '';
                $ready = $area === 'hero' ? self::hero_ready($lang) : $target_hash !== '';
                // A new source revision requires human re-review. Existing
                // translations cannot silently satisfy a changed English source.
                $changed = $prev && ($prev['source_sha256'] ?? '') !== $source_hash;
                $need = !$ready || ($changed && $area === 'seo');
                if ($area === 'seo' && !$changed && ($prev['state'] ?? '') === 'open'
                    && ($prev['source_sha256'] ?? '') === $source_hash) {
                    $baseline = (string)($prev['target_at_open'] ?? '');
                    $need = ($target_hash === '' || $target_hash === $baseline);
                }
                $status = $need ? 'open' : 'resolved';
                $priority = $area === 'hero' ? 100 : 90;
                $record = [
                    'code'=>$code, 'area'=>$area, 'lang'=>$lang,
                    'priority'=>$priority,'tier'=>$area==='hero'?'P0':'P1',
                    'severity'=>'high','state'=>$status,
                    'source_post_id'=>$home,
                    'target_post_id'=>self::localized_id($home,'page',$lang),
                    'source_sha256'=>$source_hash,
                    'target_sha256'=>$target_hash,
                    'target_at_open'=>($status==='open' && $changed)
                        ? $target_hash : (string)($prev['target_at_open'] ?? $target_hash),
                    'first_seen'=>$prev['first_seen'] ?? $now,
                    'last_seen'=>$now,
                    'job_id'=>(!$changed ? ($prev['job_id'] ?? '') : ''),
                    'reason'=>$area==='hero'
                        ? 'Homepage hero title/subtitle require matching WPML strings and translated Elementor bindings'
                        : 'Homepage Rank Math SEO title and description need an updated reviewed locale version',
                ];
                $out[$code] = $record;
            }
        }
        uasort($out, static fn($a,$b)=>($a['state'] === $b['state'] ? 0 : ($a['state']==='open'?-1:1))
            ?: ($b['priority'] <=> $a['priority']) ?: strcmp($a['lang'],$b['lang']));
        if ($store && $out !== $prior) update_option(self::STORE, $out, false);
        return ['contract'=>'mad4b.home-translation-priority.v1',
            'site'=>home_url('/'),
            'total'=>count($out),
            'open'=>count(array_filter($out,static fn($r)=>$r['state']==='open')),
            'items'=>$out, 'stored'=>$store,
            'native_auto_publish'=>false];
    }
    public static function external_issues($rows) {
        if (!is_array($rows)) return $rows;
        $state = (array)get_option(self::STORE, []);
        foreach ($state as $item) {
            if (!is_array($item) || ($item['state'] ?? '') !== 'open') continue;
            $code = (string)($item['code'] ?? '');
            if (!preg_match('/^home_copy_(?:hero|seo)_(?:es|fr|de|it|ar)$/D', $code)) continue;
            $home = self::home();if (!$home) continue;
            $priority = (int)$item['priority'];
            if (!in_array($priority,[90,100],true))continue;
            $rows[] = [
                'id'=>hash('sha256', get_current_blog_id().'|page|'.$home.'|'.$code),
                'post_type'=>'page','object_id'=>$home,
                'code'=>$code,'severity'=>'high',
                'source'=>self::SOURCE,'priority'=>$priority,
                'label'=>($priority===100?'P0 · ':'P1 · ')
                    . 'Homepage '.($priority===100?'Hero':'SEO').' translation ['
                    .$item['lang'].'] pending; MAD4B source revision requires review',
            ];
        }
        return $rows;
    }
    public static function seo_changed($old, $new): void {
        if (self::$busy || !is_array($old) || !is_array($new)) return;
        $keys = [
            'mad4b_home_seo_title'=>'rank_math_title',
            'mad4b_home_seo_description'=>'rank_math_description',
        ];
        $changed = [];
        foreach ($keys as $key=>$meta)
            if (($old[$key] ?? '') !== ($new[$key] ?? ''))$changed[$key]=$meta;
        if (!$changed) {
            foreach (['mad4b_hero_headline','mad4b_hero_subtitle'] as $key)
                if (($old[$key] ?? '') !== ($new[$key] ?? '')) {
                    // Hero owner already purges local/translated homepage pages.
                    wp_schedule_single_event(time()+20, self::HOOK);
                    break;
                }
            return;
        }
        $home = self::home(); if (!$home) return;
        self::$busy = true;
        try {
            foreach($changed as $key=>$meta) {
                $value = isset($new[$key]) && is_scalar($new[$key])
                    ? sanitize_text_field((string)$new[$key]):'';
                // Avoid unexpectedly deleting curated SEO metadata on an
                // empty JetEngine editor submission.
                if ($value !== '') update_post_meta($home,$meta,$value);
            }
            clean_post_cache($home);
            do_action('litespeed_purge_post',$home);
            do_action('litespeed_purge_url',home_url('/'));
            self::purge_home_origin();
            self::scan(true);
        } finally {self::$busy=false;}
    }
    private static function purge_home_origin(): void {
        $settings = (array)get_option('icl_sitepress_settings',[]);
        $paths=['/'];
        foreach(self::languages() as $lang) {
            if((int)($settings['language_negotiation_type']??0)===1)
                $paths[]='/'.$lang.'/';
        }
        foreach($paths as $path)do_action('litespeed_purge_url',home_url($path));
        $socket=WP_CONTENT_DIR.'/.mad4b-purge/origin.sock';
        if(!file_exists($socket))return;
        $handle=@stream_socket_client('unix://'.$socket,$errno,$err,2);
        if(!$handle)return;
        stream_set_timeout($handle,4);
        $payload=wp_json_encode($paths)."\n";
        if(fwrite($handle,$payload)===strlen($payload))@fgets($handle,4096);
        fclose($handle);
    }
    public static function target_edit($mid,$id,$key) {
        if (!in_array($key,['rank_math_title','rank_math_description','_elementor_data'],true))return;
        // Refresh only under an authenticated edit, never every frontend hit.
        // Covers both classic wp-admin edits and authenticated REST editor writes.
        if (!current_user_can('edit_post',(int)$id))return;
        if ((int)$id===self::home() && in_array($key,
            ['rank_math_title','rank_math_description'],true)) {
            $field=$key==='rank_math_title'
                ? 'mad4b_home_seo_title' : 'mad4b_home_seo_description';
            $actual=trim((string)get_post_meta((int)$id,$key,true));
            $option=get_option('look-and-feel',[]);
            if ($actual!=='' && is_array($option) &&
                ($option[$field]??'')!==$actual && !self::$busy) {
                self::$busy=true;
                try {
                    $option[$field]=$actual;
                    update_option('look-and-feel',$option,false);
                    self::scan(true);
                    self::purge_home_origin();
                } finally { self::$busy=false; }
            }
            return;
        }
        $languages=self::languages();
        foreach($languages as $lang) {
            $source = $key === '_elementor_data' ? 41727 : self::home();
            $type = $key === '_elementor_data' ? 'elementor_library':'page';
            if ((int)$id===self::localized_id($source,$type,$lang)) {
                if (!wp_next_scheduled(self::HOOK))wp_schedule_single_event(time()+20,self::HOOK);
                return;
            }
        }
    }
    public static function register_cron(): void {
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+1200,'hourly',self::HOOK);
    }
    public static function dashboard_notice(): void {
        if(!current_user_can('manage_options'))return;
        $screen=function_exists('get_current_screen')?get_current_screen():null;
        if(!$screen || !in_array($screen->id,['dashboard','settings_page_look-and-feel','toplevel_page_mad4b-home-translation'],true))return;
        $open=array_filter((array)get_option(self::STORE,[]),static fn($r)=>is_array($r)&&($r['state']??'')==='open');
        if(!$open)return;
        $link=esc_url(admin_url('tools.php?page=mad4b-home-translation'));
        echo '<div class="notice notice-warning"><p><strong>MAD4B Translation · P0/P1:</strong> '
            .count($open).' homepage language work items need review. <a href="'.$link.'">Priority queue</a>.</p></div>';
    }
    public static function menu(): void {
        add_management_page('MAD4B Home Translation Alerts',
            'MAD4B Home Translation','manage_options','mad4b-home-translation',
            [self::class,'admin_page']);
    }
    public static function admin_page(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $status=self::scan(false);
        echo '<div class="wrap"><h1>MAD4B · Homepage Translation Priority</h1>';
        echo '<p>Rank Math SEO fields are edited in JetEngine → Look and Feel → Home Page. P0 = visible Hero; P1 = homepage SEO. Native ContentJobs require an authorized central actor. No automatic translation publication.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="mad4b_home_translation_scan">';
        wp_nonce_field('mad4b_home_translation_scan');
        submit_button('Refresh priority alerts','secondary');
        echo '</form>';
        echo '<table class="widefat striped"><thead><tr><th>Priority</th><th>Language</th><th>Area</th><th>Status</th><th>Native job</th></tr></thead><tbody>';
        foreach ($status['items'] as $row) {
            echo '<tr><td>'.esc_html($row['tier'].' / '.$row['priority']).'</td><td>'.esc_html($row['lang']).'</td><td>'.esc_html($row['area'])
                .'</td><td>'.esc_html($row['state']).'</td><td>'.esc_html((string)($row['job_id']?:'Awaiting authorized dispatch')).'</td></tr>';
        }
        echo '</tbody></table><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="mad4b_home_translation_dispatch">';
        wp_nonce_field('mad4b_home_translation_dispatch');
        submit_button('Create prioritized native ContentJobs (authorized only)','primary');
        echo '</form></div>';
    }
    public static function admin_scan(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('mad4b_home_translation_scan');
        self::scan(true);
        wp_safe_redirect(admin_url('tools.php?page=mad4b-home-translation'));exit;
    }
    public static function dispatch(int $limit=3) {
        if(!class_exists('MAD4B_SCP_Policy') || MAD4B_SCP_Policy::can_mutate()!==true)
            return new WP_Error('home_translation_authorized_actor_required');
        if(!class_exists('MAD4B_SCP_Content_Jobs') || !class_exists('MAD4B_SCP_Operational_Integrity')
            || !class_exists('MAD4B_SCP_Schema'))return new WP_Error('native_content_jobs_unavailable');
        $proof=MAD4B_SCP_Operational_Integrity::capture();
        if(is_wp_error($proof))return $proof;
        $scope=(array)($proof['scope']??[]);
        if(empty($scope['site_uuid'])||empty($scope['brand_ref']))return new WP_Error('native_scope_unavailable');
        $t=MAD4B_SCP_Schema::tables();global $wpdb;
        $alerts=(array)get_option(self::STORE,[]);$created=[];$limit=max(1,min(5,$limit));
        foreach($alerts as $code=>$item) {
            if(count($created)>=$limit)break;
            if(($item['state']??'')!=='open'||!empty($item['job_id']))continue;
            $token=hash('sha256',home_url('/').'|'.$code.'|'.$item['source_sha256']);
            $needle='homecopy-ref:'.$token;
            $matched=$wpdb->get_col($wpdb->prepare(
                "SELECT job_id FROM {$t['content_jobs']} WHERE site_uuid=%s AND brand_id=%s AND subject LIKE %s LIMIT 2",
                $scope['site_uuid'],$scope['brand_ref'],'%'.$wpdb->esc_like($needle).'%'));
            if(count($matched)>1){$created[$code]='DUPLICATE_NEEDS_REVIEW';continue;}
            if($matched){$alerts[$code]['job_id']=$matched[0];$created[$code]='RECONCILED';continue;}
            $r=MAD4B_SCP_Content_Jobs::create_job([
                'brand_id'=>$scope['brand_ref'],
                'subject'=>'['.$item['tier'].':'.$item['priority'].'] Homepage '.$item['area']
                    .' translation ['.$item['lang'].'] '.$needle,
                'language'=>$item['lang'],'country'=>'global','content_type'=>'translation',
                'target_post_type'=>'page','automation_level'=>'assisted',
                'reason'=>$item['reason'].'; priority '.$item['priority']
                    .'; English digest '.$item['source_sha256']
                    .'; preserve human WPML and Rank Math review; never auto-publish.'
            ]);
            if(is_wp_error($r)){$created[$code]=$r->get_error_code();break;}
            $job=$r['job']['job_id']??'';
            if(!$job){$created[$code]='MISSING_NATIVE_RECEIPT';break;}
            $alerts[$code]['job_id']=$job;
            $created[$code]='CREATED';
        }
        if($created)update_option(self::STORE,$alerts,false);
        return ['results'=>$created,'published'=>false,'priority_ordered'=>true];
    }
    public static function admin_dispatch(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('mad4b_home_translation_dispatch');
        $out=self::dispatch(3);
        $msg=is_wp_error($out)?$out->get_error_code():wp_json_encode($out);
        set_transient('mad4b_home_translation_last_dispatch',sanitize_text_field($msg),90);
        wp_safe_redirect(admin_url('tools.php?page=mad4b-home-translation'));exit;
    }
    public static function cli($args): void {
        $cmd=$args[0]??'status';
        if($cmd==='status')$out=self::scan(false);
        elseif($cmd==='scan')$out=self::scan(true);
        elseif($cmd==='dispatch')$out=self::dispatch(3);
        else \WP_CLI::error('status|scan|dispatch');
        if(is_wp_error($out))\WP_CLI::error($out->get_error_code());
        \WP_CLI::line(wp_json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    public static function boot(): void {
        add_action('init',[self::class,'register_cron'],60);
        add_action(self::HOOK,static function(){self::scan(true);});
        add_action('update_option_look-and-feel',[self::class,'seo_changed'],105,2);
        add_action('updated_post_meta',[self::class,'target_edit'],60,3);
        add_action('added_post_meta',[self::class,'target_edit'],60,3);
        add_filter('mad4b/content_readiness/external_issues',[self::class,'external_issues'],40,1);
        add_action('admin_menu',[self::class,'menu'],90);
        add_action('admin_notices',[self::class,'dashboard_notice'],18);
        add_action('admin_post_mad4b_home_translation_scan',[self::class,'admin_scan']);
        add_action('admin_post_mad4b_home_translation_dispatch',[self::class,'admin_dispatch']);
        if(defined('WP_CLI')&&WP_CLI)\WP_CLI::add_command('mad4b-home-translation',[self::class,'cli']);
    }
}
MAD4B_Home_SEO_Translation_Priority::boot();
