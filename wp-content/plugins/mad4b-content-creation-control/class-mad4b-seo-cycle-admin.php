<?php
defined('ABSPATH') || exit;
final class MAD4B_SEO_Cycle_Admin {
    const SLUG='mad4b-seo-cycle';
    public static function boot(){
        if(wp_get_environment_type()!=='staging')return;
        add_action('admin_menu',[__CLASS__,'menu'],100);
        add_action('admin_post_mad4b_seo_cycle_apply',[__CLASS__,'apply_form']);
    }
    public static function menu(){
        add_submenu_page('mad4b-shared-content-hub','SEO Content Lifecycle','SEO Lifecycle',
            'mad4b_manage_company_operations',self::SLUG,[__CLASS__,'page']);
    }
    public static function apply_form(){
        if(!MAD4B_SEO_Content_Lifecycle::can_write())wp_die('Permission denied');
        check_admin_referer('mad4b_seo_cycle_apply');
        $raw=wp_unslash($_POST);
        $kind=sanitize_key((string)($raw['target_kind']??''));
        $id=absint($raw['target_id']??0);
        $mode=sanitize_key((string)($raw['mode']??''));
        $fields=[];
        foreach(['title','description','focus_keyword'] as $key)
            if(isset($raw[$key]) && is_scalar($raw[$key]))
                $fields[$key]=sanitize_text_field((string)$raw[$key]);
        $input=['target_kind'=>$kind,'target_id'=>$id,'mode'=>$mode,
            'expected_sha256'=>sanitize_text_field((string)($raw['expected_sha256']??''))];
        if($mode==='seo_meta')$input['fields']=$fields;
        if($mode==='append_content')$input['content_append']=wp_kses_post((string)($raw['content_append']??''));
        $result=MAD4B_SEO_Content_Lifecycle::apply($input);
        $status=is_wp_error($result)?$result->get_error_code():'applied_verified';
        wp_safe_redirect(add_query_arg(['page'=>self::SLUG,'target_kind'=>$kind,
            'target_id'=>$id,'notice'=>$status],admin_url('admin.php')));
        exit;
    }
    public static function page(){
        if(!MAD4B_SEO_Content_Lifecycle::can_read())wp_die('Permission denied');
        $kind=sanitize_key((string)($_GET['target_kind']??'post'));
        $id=absint($_GET['target_id']??0);
        $input=['target_kind'=>$kind,'target_id'=>$id];
        $plan=$id?MAD4B_SEO_Content_Lifecycle::plan($input):null;
        $gen=$id?MAD4B_SEO_Content_Lifecycle::generate($input):null;
        ?>
        <div class="wrap" style="max-width:1250px;margin:22px 16px">
         <style>.mad4b-seo-box{background:#fff;border:1px solid #d8e1ea;border-radius:12px;padding:20px;margin:16px 0}.mad4b-seo-field{display:block;margin:12px 0;max-width:820px}.mad4b-seo-field input,.mad4b-seo-field textarea{width:100%;max-width:820px}.mad4b-seo-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.mad4b-seo-muted{color:#566373}.mad4b-seo-good{color:#147444}.mad4b-seo-bad{color:#9b311e}@media(max-width:800px){.mad4b-seo-grid{grid-template-columns:1fr}}</style>
         <h1>SEO Content Lifecycle <small style="font-size:13px;color:#627286">MAD4B + Rank Math · Staging</small></h1>
         <p>تحليل وتوليد ومعاينة وتحديث SEO لصفحة أو مقال أو مصطلح. لا يوجد نشر تلقائي، وتقييم Rank Math المحفوظ ليس تحليلًا جديدًا.</p>
         <?php if(isset($_GET['notice'])): ?><div class="notice notice-info inline"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['notice']))); ?></p></div><?php endif; ?>
         <section class="mad4b-seo-box">
           <form method="get">
             <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
             <label>Target <select name="target_kind"><option value="post" <?php selected($kind,'post'); ?>>Post / Page / Product</option><option value="term" <?php selected($kind,'term'); ?>>Taxonomy Term</option></select></label>
             <label>Object ID <input type="number" name="target_id" min="1" required value="<?php echo esc_attr($id?:''); ?>"></label>
             <button class="button button-primary">Analyze &amp; Generate</button>
           </form>
         </section>
         <?php if(is_wp_error($plan)): ?><div class="notice notice-warning inline"><p><?php echo esc_html($plan->get_error_code()); ?></p></div>
         <?php elseif(is_array($plan) && is_array($gen)): ?>
         <div class="mad4b-seo-grid">
           <section class="mad4b-seo-box">
             <h2>Rank Math &amp; Content Assessment</h2>
             <p><strong><?php echo esc_html($plan['target']['title']); ?></strong></p>
             <p class="mad4b-seo-muted">Type: <?php echo esc_html($plan['target']['object_type']); ?> · Language: <?php echo esc_html($plan['target']['language']?:'unknown'); ?> · Status: <?php echo esc_html($plan['target']['status']); ?></p>
             <p>Last saved Rank Math score: <strong><?php echo $plan['rank_math_score']===null?'Not scored':esc_html($plan['rank_math_score']); ?></strong> <span class="mad4b-seo-muted">(freshness unverified)</span></p>
             <p>Native Rank Math content analysis: <?php echo $plan['analysis_ability_available']?'Available':'Not registered in Abilities'; ?></p>
             <table class="widefat striped"><tbody>
             <?php foreach($plan['checks'] as $key=>$pass): ?>
               <tr><td><?php echo esc_html(str_replace('_',' ',ucwords($key))); ?></td>
                 <td class="<?php echo $pass?'mad4b-seo-good':'mad4b-seo-bad'; ?>"><?php echo $pass?'PASS':'Improve'; ?></td></tr>
             <?php endforeach; ?>
             </tbody></table>
           </section>
           <section class="mad4b-seo-box">
             <h2>Generated SEO Proposal</h2>
             <p class="mad4b-seo-muted">First-party deterministic proposal; review Brand Context and search intent before approval.</p>
             <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
               <input type="hidden" name="action" value="mad4b_seo_cycle_apply">
               <input type="hidden" name="target_kind" value="<?php echo esc_attr($kind); ?>">
               <input type="hidden" name="target_id" value="<?php echo esc_attr($id); ?>">
               <input type="hidden" name="mode" value="seo_meta">
               <input type="hidden" name="expected_sha256" value="<?php echo esc_attr($plan['target']['sha256']); ?>">
               <?php wp_nonce_field('mad4b_seo_cycle_apply'); ?>
               <label class="mad4b-seo-field">SEO Title<input name="title" maxlength="500" value="<?php echo esc_attr($gen['fields']['title']); ?>"></label>
               <label class="mad4b-seo-field">SEO Description<textarea name="description" rows="4" maxlength="500"><?php echo esc_textarea($gen['fields']['description']); ?></textarea></label>
               <label class="mad4b-seo-field">Focus Keyword<input name="focus_keyword" maxlength="500" value="<?php echo esc_attr($gen['fields']['focus_keyword']); ?>"></label>
               <?php if(MAD4B_SEO_Content_Lifecycle::can_write()): ?>
                 <button class="button button-primary">Apply via governed SEO route</button>
               <?php endif; ?>
             </form>
             <p class="mad4b-seo-muted">Post metadata requires the native `seo/update-meta` authorization. No raw post-meta fallback. Term metadata uses Staging-specific guarded readback.</p>
           </section>
         </div>
         <?php if($kind==='post' && $plan['target']['status']==='draft' && !$plan['target']['is_elementor'] && !$plan['target']['is_blocks']): ?>
         <section class="mad4b-seo-box">
           <h2>Enrich draft content</h2>
           <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
             <input type="hidden" name="action" value="mad4b_seo_cycle_apply">
             <input type="hidden" name="target_kind" value="post">
             <input type="hidden" name="target_id" value="<?php echo esc_attr($id); ?>">
             <input type="hidden" name="mode" value="append_content">
             <input type="hidden" name="expected_sha256" value="<?php echo esc_attr($plan['target']['sha256']); ?>">
             <?php wp_nonce_field('mad4b_seo_cycle_apply'); ?>
             <label class="mad4b-seo-field">Reviewed content to append<textarea name="content_append" rows="5" required></textarea></label>
             <?php if(MAD4B_SEO_Content_Lifecycle::can_write()): ?><button class="button button-secondary">Append to unpublished draft</button><?php endif; ?>
           </form>
         </section>
         <?php endif; ?>
         <?php endif; ?>
        </div>
        <?php
    }
}
