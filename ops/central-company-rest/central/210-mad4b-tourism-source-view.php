<?php
/**
 * Plugin Name: MAD4B Tourism Source View (Staging Only)
 * Description: Safe company-source display for owner-classified old tour demos.
 * Version: 0.1.0
 *
 * This adapter NEVER changes a native tour post or WooCommerce/JetEngine rate.
 * Only a verified, already-published central Content Hub source is rendered.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tourism_Source_View_V1 {
    const CONTRACT='mad4b.tourism.source-view.v1';
    const HOST='tours.dev.mad4b.com';
    const OWNER='tourism-almallahgroup';
    const SETTINGS='mad4b_tourism_source_view_pages_v1';
    const SOURCE_TO_NATIVE=[24900=>60617,41909=>60745];
    const SOURCE_TO_HUB=[24900=>67483,41909=>67484];

    public static function enabled(): bool {
        return wp_get_environment_type()==='staging'
            && strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))===self::HOST
            && class_exists('MAD4B_Hub_Nile_Publisher_V1');
    }
    private static function source(int $id): ?array {
        if(!self::enabled()||!isset(self::SOURCE_TO_HUB[$id]))return null;
        $hubId=self::SOURCE_TO_HUB[$id];
        $post=get_post($hubId);
        if(!$post||$post->post_type!=='mad4b_hub_nile'||$post->post_status!=='publish')return null;
        $expected=[
            '_mad4b_hub_source_id'=>(string)$id,
            '_mad4b_hub_source_kind'=>'nile_tour',
            '_mad4b_hub_source_company'=>self::OWNER,
            '_mad4b_hub_reuse_policy_state'=>'native_legacy_demo_source_authority',
            '_mad4b_hub_authoritative_source'=>self::OWNER,
            '_mad4b_hub_bookable'=>'no',
            '_mad4b_hub_commerce_approved'=>'no',
        ];
        foreach($expected as $k=>$v)if((string)get_post_meta($hubId,$k,true)!==$v)return null;
        $json=(string)get_post_meta($hubId,'_mad4b_hub_source_payload_json',true);
        if($json===''||strlen($json)>120000)return null;
        $payload=json_decode($json,true);
        if(!is_array($payload)||($payload['source_company']??null)!==self::OWNER||
            ($payload['source_tour_id']??null)!==$id ||
            ($payload['import_status']??null)!=='reference_only' ||
            ($payload['publish_allowed']??true)!==false)return null;
        $language=$payload['translation_source']['language_code']??'';
        if(($id===24900&&$language!=='en')||($id===41909&&$language!=='ar'))return null;
        $runList=$payload['program_reference_snapshot']['cruise_run_variants']??[];
        if(!is_array($runList)||count($runList)!==2)return null;
        foreach($runList as $run){
            if(!is_array($run))return null;
            foreach(['program','origin','day','time'] as $field){
                if(!is_string($run[$field.'_label']??null)||$run[$field.'_label']==='')
                    return null;
            }
        }
        return $payload;
    }
    public static function render($atts=[]): string {
        if(!self::enabled())return '';
        $args=shortcode_atts(['source_id'=>''],$atts,'mad4b_tourism_nile');
        $id=filter_var($args['source_id'],FILTER_VALIDATE_INT);
        $data=$id?self::source((int)$id):null;
        if(!$data)return '<p role="status">'.esc_html__('Verified source content is temporarily unavailable.','mad4b').'</p>';
        $arabic=$id===41909;
        $lang=$arabic?'ar':'en';
        $title=sanitize_text_field((string)$data['title']);
        $summary=trim((string)($data['summary']??''));
        $programs=$data['program_reference_snapshot']['cruise_run_variants'];
        $labels=$arabic?[
            'headline'=>'البرامج المتاحة في المصدر',
            'day'=>'يوم الانطلاق','from'=>'مدينة الانطلاق','time'=>'التوقيت',
            'note'=>'هذه صفحة معلومات الرحلة المعتمدة من مصدر الشركة. الأسعار والحجز غير متاحين حتى مراجعة شروط البيع.',
        ]:[
            'headline'=>'Source itinerary options',
            'day'=>'Departure day','from'=>'Departure city','time'=>'Time',
            'note'=>'This page presents verified tour information from the company source. Rates and booking are unavailable pending commercial review.',
        ];
        ob_start();
        ?>
        <style>
        .mad4b-nile-view{max-width:1120px;margin:clamp(24px,5vw,62px) auto;padding:0 20px;font-family:inherit;color:var(--ast-global-color-3,inherit);box-sizing:border-box}
        .mad4b-nile-view *{box-sizing:border-box}
        .mad4b-nile-view__header{border-bottom:1px solid var(--ast-global-color-7,#ddd);padding-bottom:clamp(20px,4vw,36px);margin-bottom:28px}
        .mad4b-nile-view__title{font-size:clamp(1.65rem,3.6vw,2.8rem);font-weight:700;line-height:1.2;margin:0 0 16px;color:var(--e-global-color-primary,var(--ast-global-color-2,inherit))}
        .mad4b-nile-view__summary{font-size:clamp(1rem,1.5vw,1.18rem);line-height:1.85;max-width:780px}
        .mad4b-nile-view__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,290px),1fr));gap:18px}
        .mad4b-nile-view__card{border:1px solid var(--ast-global-color-7,#ddd);border-radius:14px;padding:clamp(18px,3vw,26px);background:var(--ast-global-color-5,#fff);box-shadow:0 4px 14px rgba(0,0,0,.045)}
        .mad4b-nile-view__card h3{margin:0 0 20px;font-size:1.12rem;line-height:1.45;color:var(--e-global-color-primary,var(--ast-global-color-2,inherit))}
        .mad4b-nile-view__facts{margin:0;display:grid;grid-template-columns:minmax(105px,37%) 1fr;gap:10px;font-size:.98rem;line-height:1.6}
        .mad4b-nile-view__facts dt{font-weight:600;opacity:.75}.mad4b-nile-view__facts dd{margin:0}
        .mad4b-nile-view__notice{margin-top:26px;padding:16px 20px;border-radius:10px;background:var(--ast-global-color-6,#f5f5f5);border-inline-start:4px solid var(--e-global-color-primary,var(--ast-global-color-0,#555));line-height:1.8}
        @media(max-width:600px){.mad4b-nile-view{padding:0 14px;margin:20px auto}.mad4b-nile-view__grid{grid-template-columns:1fr}.mad4b-nile-view__facts{grid-template-columns:42% 1fr}}
        </style>
        <article class="mad4b-nile-view" lang="<?php echo esc_attr($lang); ?>" dir="<?php echo $arabic?'rtl':'ltr'; ?>" data-source-owner="<?php echo esc_attr(self::OWNER); ?>" data-verified-source-tour="<?php echo esc_attr((string)$id); ?>">
          <header class="mad4b-nile-view__header">
            <h1 class="mad4b-nile-view__title"><?php echo esc_html($title); ?></h1>
            <p class="mad4b-nile-view__summary"><?php echo esc_html($summary); ?></p>
          </header>
          <section aria-label="<?php echo esc_attr($labels['headline']); ?>">
            <h2><?php echo esc_html($labels['headline']); ?></h2>
            <div class="mad4b-nile-view__grid">
            <?php foreach($programs as $program): ?>
              <section class="mad4b-nile-view__card">
                <h3><?php echo esc_html($program['program_label']); ?></h3>
                <dl class="mad4b-nile-view__facts">
                  <dt><?php echo esc_html($labels['from']); ?></dt><dd><?php echo esc_html($program['origin_label']); ?></dd>
                  <dt><?php echo esc_html($labels['day']); ?></dt><dd><?php echo esc_html($program['day_label']); ?></dd>
                  <dt><?php echo esc_html($labels['time']); ?></dt><dd><?php echo esc_html($program['time_label']); ?></dd>
                </dl>
              </section>
            <?php endforeach; ?>
            </div>
          </section>
          <p class="mad4b-nile-view__notice" role="note"><?php echo esc_html($labels['note']); ?></p>
        </article>
        <?php
        return (string)ob_get_clean();
    }
    public static function exclude_native_demo_from_tour_lists(WP_Query $query): void {
        if(!self::enabled()||is_admin())return;
        $types=(array)$query->get('post_type');
        if(!in_array('tours-and-activities',$types,true))return;
        $excluded=array_map('intval',(array)$query->get('post__not_in'));
        $query->set('post__not_in',array_values(array_unique(array_merge($excluded,array_values(self::SOURCE_TO_NATIVE)))));
    }
    public static function redirect_legacy_source(): void {
        if(!self::enabled()||is_admin()||wp_doing_ajax()||
           !is_singular('tours-and-activities'))return;
        $native=(int)get_queried_object_id();
        $sourceId=array_search($native,self::SOURCE_TO_NATIVE,true);
        if(!$sourceId||!self::source((int)$sourceId))return;
        $pages=get_option(self::SETTINGS,[]);
        $pageId=(int)($pages[$sourceId]??0);
        $page=get_post($pageId);
        if(!$page||$page->post_type!=='page'||$page->post_status!=='publish')return;
        $url=get_permalink($pageId);
        if(!is_string($url)||!wp_http_validate_url($url))return;
        // Temporary, reversible redirect; don't cache forever or abandon old links.
        nocache_headers();
        wp_safe_redirect($url,302,'MAD4B Verified Tourism Source');
        exit;
    }
    public static function boot(): void {
        if(!self::enabled())return;
        add_shortcode('mad4b_tourism_nile',[__CLASS__,'render']);
        add_action('pre_get_posts',[__CLASS__,'exclude_native_demo_from_tour_lists'],12);
        add_action('template_redirect',[__CLASS__,'redirect_legacy_source'],2);
    }
}
add_action('init',['MAD4B_Tourism_Source_View_V1','boot'],25);
