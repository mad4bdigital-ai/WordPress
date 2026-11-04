<?php
defined('ABSPATH') || exit;
final class MAD4B_SERP_Policy_Admin {
    const PAGE = 'mad4b-serp-policy';
    public static function boot() {
        if(wp_get_environment_type()!=='staging') return;
        add_action('admin_menu',[__CLASS__,'menu'],99);
        add_action('admin_post_mad4b_serp_policy_override',[__CLASS__,'save_form']);
    }
    public static function menu() {
        add_submenu_page('mad4b-shared-content-hub','SERP Evidence & Appearance','SERP Evidence',
            'mad4b_manage_company_operations',self::PAGE,[__CLASS__,'page']);
    }
    public static function save_form() {
        if(!MAD4B_SERP_Presentation_Policy::can_write())wp_die('Permission denied');
        check_admin_referer('mad4b_serp_policy_override');
        $raw=wp_unslash($_POST);
        $input=[
            'job_id'=>sanitize_text_field($raw['job_id']??''),
            'language'=>sanitize_text_field($raw['language']??''),
            'content_type'=>sanitize_text_field($raw['content_type']??''),
            'expected_revision'=>absint($raw['expected_revision']??0),
            'title_min'=>absint($raw['title_min']??30),
            'title_max'=>absint($raw['title_max']??60),
            'description_min'=>absint($raw['description_min']??110),
            'description_max'=>absint($raw['description_max']??160),
            'group_by_query'=>!empty($raw['group_by_query']),
            'show_superseded_history'=>!empty($raw['show_superseded_history']),
        ];
        $result=MAD4B_SERP_Presentation_Policy::save_override($input);
        $args=['page'=>self::PAGE,'job_id'=>$input['job_id']];
        $args['result']=is_wp_error($result)?$result->get_error_code():'saved';
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));
        exit;
    }
    private static function h($v) { return esc_html((string)$v); }
    public static function page() {
        if(!MAD4B_SERP_Presentation_Policy::allowed())wp_die('Permission denied');
        $job=isset($_GET['job_id'])?sanitize_text_field(wp_unslash($_GET['job_id'])):'';
        $post=isset($_GET['post_id'])?absint($_GET['post_id']):0;
        $policy=MAD4B_SERP_Presentation_Policy::policy($job?['job_id'=>$job]:[]);
        $e=(!is_wp_error($policy)&&isset($policy['effective']))?$policy['effective']:MAD4B_SERP_Presentation_Policy::defaults();
        $observations=$job?MAD4B_SERP_Presentation_Policy::observation(['job_id'=>$job]):null;
        $appearance=$post?MAD4B_SERP_Presentation_Policy::appearance(['post_id'=>$post]):null;
        $url=admin_url('admin.php');
        ?>
        <div class="wrap mad4b-serp-layout" dir="rtl">
        <style>
        .mad4b-serp-layout{max-width:1600px;margin:22px 0 40px 22px;color:#172437}
        .mad4b-serp-layout *{box-sizing:border-box}
        .mad4b-serp-top{border:1px solid #d7e1ec;background:#fff;border-radius:15px;padding:22px 24px;margin-bottom:16px}
        .mad4b-serp-top h1{font-size:24px;font-weight:720;line-height:1.4;margin:0 0 8px}
        .mad4b-serp-top p{margin:0;color:#586778;font-size:14px}
        .mad4b-serp-layout .ms-flex{display:grid;grid-template-columns:minmax(0,1fr) 272px;gap:16px;align-items:start}
        .mad4b-serp-layout .ms-main{min-width:0}
        .mad4b-serp-layout .ms-side{position:sticky;top:48px;display:flex;flex-direction:column;gap:12px}
        .mad4b-serp-layout .ms-panel{background:#fff;border:1px solid #dbe4ed;border-radius:14px;padding:19px 21px;margin-bottom:16px;box-shadow:0 1px 2px rgba(20,35,55,.025)}
        .mad4b-serp-layout .ms-panel h2{font-size:17px;font-weight:700;margin:0 0 12px}
        .mad4b-serp-layout .ms-en{color:#586779;font-weight:400;font-size:12px;direction:ltr;display:inline-block}
        .mad4b-serp-layout .ms-inline{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
        .mad4b-serp-layout .ms-tag{display:inline-flex;align-items:center;background:#f1f5fa;border:1px solid #dce5ee;border-radius:6px;padding:4px 8px;color:#344658;font-size:12px;max-width:100%;overflow-wrap:anywhere}
        .mad4b-serp-layout .ms-tag.warn{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
        .mad4b-serp-layout .ms-tag.ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
        .mad4b-serp-layout .ms-field{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#485a70;flex:1;min-width:160px}
        .mad4b-serp-layout .ms-field input{width:100%;max-width:none;height:38px;border:1px solid #b9c6d3;border-radius:7px;direction:ltr;text-align:left}
        .mad4b-serp-layout .ms-pills{display:flex;gap:9px;flex-wrap:wrap;align-items:center;padding:8px 0}
        .mad4b-serp-layout .ms-card{padding:16px 0;border-top:1px solid #e5eaf0;min-width:0}
        .mad4b-serp-layout .ms-card:first-child{border-top:0}
        .mad4b-serp-layout .ms-card h3{font-size:15px;margin:7px 0 5px;font-weight:700;overflow-wrap:anywhere}
        .mad4b-serp-layout .ms-card .ms-domain{color:#257148;direction:ltr;text-align:left;font-size:12px;overflow-wrap:anywhere}
        .mad4b-serp-layout .ms-card a{color:#0a5ca8;text-decoration:none}
        .mad4b-serp-layout .ms-card p{font-size:13px;color:#465468;margin:6px 0}
        .mad4b-serp-layout .ms-metric{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
        .mad4b-serp-layout .ms-metric>div{background:#f7f9fc;border-radius:10px;padding:13px;min-width:0}
        .mad4b-serp-layout .ms-metric strong{display:block;font-size:19px}
        .mad4b-serp-layout .ms-metric small{display:block;font-size:11px;color:#627081}
        .mad4b-serp-layout code{font-size:11px;overflow-wrap:anywhere;white-space:normal}
        .mad4b-serp-layout .ms-search-preview{border:1px solid #dfe3eb;border-radius:11px;background:#fff;padding:17px;direction:ltr;text-align:left;max-width:650px}
        .mad4b-serp-layout .ms-preview-title{font-size:18px;line-height:1.4;color:#1a0dab;margin:3px 0}
        .mad4b-serp-layout .ms-preview-url{color:#217341;font-size:12px;overflow-wrap:anywhere}
        .mad4b-serp-layout .ms-preview-desc{color:#49576b;font-size:13px}
        .mad4b-serp-layout .ms-minor{color:#718096;font-size:12px}
        .mad4b-serp-layout .ms-hide{display:none!important}
        @media(max-width:1020px){.mad4b-serp-layout .ms-flex{grid-template-columns:minmax(0,1fr)}.mad4b-serp-layout .ms-side{position:static;grid-row:1}.mad4b-serp-layout .ms-metric{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:600px){.mad4b-serp-layout{margin:12px 10px 40px 0}.mad4b-serp-layout .ms-panel{padding:14px}.mad4b-serp-layout .ms-metric{grid-template-columns:1fr}}
        </style>
        <?php if(isset($_GET['result'])): ?><div class="notice <?php echo sanitize_key($_GET['result'])==='saved'?'notice-success':'notice-warning'; ?> inline"><p><?php echo self::h(sanitize_text_field(wp_unslash($_GET['result']))); ?></p></div><?php endif; ?>
        <header class="mad4b-serp-top"><h1>سياسة عرض نتائج البحث <span class="ms-en">/ SERP Evidence & Appearance</span></h1>
        <p>عرض الأدلة المرصودة، معاينة نتائج صفحاتنا، وسجل المراجعات — بدون ادعاء ترتيب غير موثق أو منح صلاحية النشر.</p></header>
        <div class="ms-flex">
          <main class="ms-main">
            <section class="ms-panel"><h2>فحص الأدلة <span class="ms-en">/ Inspect research evidence</span></h2>
             <form method="get" action="<?php echo esc_url($url); ?>" class="ms-inline">
              <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
              <label class="ms-field">ContentJob ID <input name="job_id" placeholder="UUID" value="<?php echo esc_attr($job); ?>" required></label>
              <button type="submit" class="button button-primary">عرض النتائج / Inspect</button>
             </form>
            </section>
            <?php if(is_wp_error($observations)): ?><section class="ms-panel"><div class="notice notice-error inline"><p><?php echo self::h($observations->get_error_code()); ?></p></div></section>
            <?php elseif(is_array($observations)):
              $active=array_values(array_filter($observations['snapshots'],static fn($s)=>$s['status']==='active'));
              $history=array_values(array_filter($observations['snapshots'],static fn($s)=>$s['status']!=='active'));
            ?>
            <section class="ms-panel"><h2>النسخة النشطة <span class="ms-en">/ Active observation</span></h2>
              <div class="ms-inline"><span class="ms-tag"><?php echo self::h(count($active)); ?> active</span><span class="ms-tag"><?php echo self::h(count($history)); ?> historical</span><span class="ms-tag warn">No verified rank by default</span></div>
              <?php foreach($active as $snapshot): self::render_snapshot($snapshot,!empty($e['presentation']['group_by_query'])); endforeach; ?>
              <?php if(!$active): ?><p>لا توجد نسخة نشطة حاليًا / No active SERP observation.</p><?php endif; ?>
            </section>
            <?php if(!empty($e['presentation']['show_superseded_history'])): ?>
            <section class="ms-panel"><h2>سجل النسخ السابقة <span class="ms-en">/ Historical snapshots</span></h2>
              <details><summary><?php echo self::h(count($history)); ?> نسخة محفوظة — عرض للقراءة فقط</summary>
              <?php foreach($history as $snapshot): self::render_snapshot($snapshot,!empty($e['presentation']['group_by_query'])); endforeach; ?>
              </details>
            </section>
            <?php endif; ?>
            <?php endif; ?>
            <section class="ms-panel"><h2>معاينة ظهور صفحة <span class="ms-en">/ SEO Appearance Preview</span></h2>
             <form method="get" action="<?php echo esc_url($url); ?>" class="ms-inline">
              <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
              <?php if($job): ?><input type="hidden" name="job_id" value="<?php echo esc_attr($job); ?>"><?php endif; ?>
              <label class="ms-field">WordPress Post ID <input type="number" min="1" name="post_id" value="<?php echo esc_attr($post?:''); ?>" required></label>
              <button class="button button-secondary">معاينة / Preview</button>
             </form>
             <?php if(is_wp_error($appearance)): ?><p><?php echo self::h($appearance->get_error_code()); ?></p>
             <?php elseif(is_array($appearance)): ?>
               <p class="ms-minor"><?php echo self::h($appearance['title_source']); ?> · <?php echo self::h($appearance['description_source']); ?> · Desktop / Mobile indicative</p>
               <div class="ms-inline" style="margin:10px 0">
                 <button type="button" class="button ms-preview-mode" data-ms-width="650">Desktop</button>
                 <button type="button" class="button ms-preview-mode" data-ms-width="375">Mobile</button>
               </div>
               <div class="ms-search-preview">
                <div class="ms-preview-url"><?php echo self::h($appearance['permalink']); ?></div>
                <div class="ms-preview-title"><?php echo self::h($appearance['title']); ?></div>
                <div class="ms-preview-desc"><?php echo self::h($appearance['description']); ?></div>
               </div>
               <p><span class="ms-tag <?php echo $appearance['title_guidance']['within_range']?'ok':'warn'; ?>">Title <?php echo (int)$appearance['title_chars']; ?> chars</span>
               <span class="ms-tag <?php echo $appearance['description_guidance']['within_range']?'ok':'warn'; ?>">Description <?php echo (int)$appearance['description_chars']; ?> chars</span>
               <span class="ms-tag">Schema: <?php echo self::h($appearance['schema_type']?:'not declared'); ?></span></p>
               <p class="ms-minor">Google may rewrite title/snippet. Structured data does not guarantee rich results.</p>
             <?php endif; ?>
            </section>
          </main>
          <aside class="ms-side">
            <section class="ms-panel"><h2>قواعد الأدلة <span class="ms-en">/ Evidence rules</span></h2>
             <p><span class="ms-tag ok">HTTPS</span> روابط المصادر</p>
             <p><span class="ms-tag warn">Rank proof required</span> لا ترتيب بدون دليل</p>
             <p><span class="ms-tag warn">Volume proof required</span> لا حجم بحث تخميني</p>
             <p><span class="ms-tag">Max 100</span> نتيجة لكل Snapshot</p>
            </section>
            <section class="ms-panel"><h2>إرشادات SEO <span class="ms-en">/ Appearance guide</span></h2>
             <p>Title: <strong><?php echo (int)$e['appearance']['title_min']; ?>–<?php echo (int)$e['appearance']['title_max']; ?></strong> chars</p>
             <p>Description: <strong><?php echo (int)$e['appearance']['description_min']; ?>–<?php echo (int)$e['appearance']['description_max']; ?></strong> chars</p>
             <p class="ms-minor">قيم استرشادية وليست ضمانات للعرض.</p>
             <p class="ms-minor">Policy revision <?php echo (int)$e['revision']; ?></p>
             <?php if(MAD4B_SERP_Presentation_Policy::can_write()): ?>
             <details><summary>تخصيص السياسة / Scoped settings</summary>
             <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="mad4b_serp_policy_override">
              <input type="hidden" name="expected_revision" value="<?php echo (int)$e['revision']; ?>">
              <input type="hidden" name="job_id" value="<?php echo esc_attr($job); ?>">
              <?php wp_nonce_field('mad4b_serp_policy_override'); ?>
              <label class="ms-field">Language (empty = all)<input name="language" maxlength="8" placeholder="en"></label>
              <label class="ms-field">Content type (empty = all)<input name="content_type" maxlength="40" placeholder="article"></label>
              <?php foreach(['title_min','title_max','description_min','description_max'] as $key): ?>
               <label class="ms-field"><?php echo self::h(str_replace('_',' ',$key)); ?>
                <input type="number" min="15" max="240" name="<?php echo esc_attr($key); ?>" value="<?php echo (int)$e['appearance'][$key]; ?>"></label>
              <?php endforeach; ?>
              <p><label><input type="checkbox" name="group_by_query" value="1" <?php checked(!empty($e['presentation']['group_by_query'])); ?>> Group by query</label></p>
              <p><label><input type="checkbox" name="show_superseded_history" value="1" <?php checked(!empty($e['presentation']['show_superseded_history'])); ?>> Show historical snapshots</label></p>
              <button type="submit" class="button button-primary">حفظ التخصيص / Save</button>
              <p class="ms-minor">لا يمكن تعطيل شروط توثيق الترتيب أو حجم البحث.</p>
             </form></details>
             <?php endif; ?>
            </section>
          </aside>
        </div></div>
        <script>
        (()=>{document.querySelectorAll('.ms-preview-mode').forEach(btn=>btn.addEventListener('click',()=>{
          const panel=btn.closest('.ms-panel'), preview=panel.querySelector('.ms-search-preview');
          if(preview){preview.style.maxWidth=btn.dataset.msWidth+'px';}
        }));
        document.querySelectorAll('.ms-serp-filter').forEach(el=>{
        el.addEventListener('input',()=>{const root=el.closest('.ms-snapshot');
        const q=el.value.trim().toLowerCase();
        root.querySelectorAll('.ms-card').forEach(card=>card.classList.toggle('ms-hide',!card.textContent.toLowerCase().includes(q)));
        });});})();
        </script>
        <?php
    }
    private static function render_snapshot(array $s, bool $group_by_query=true) {
        ?><div class="ms-snapshot">
          <div class="ms-pills">
            <span class="ms-tag <?php echo $s['evidence_class']==='verified_serp'?'ok':'warn'; ?>"><?php echo self::h($s['evidence_class']); ?></span>
            <span class="ms-tag"><?php echo self::h($s['provider']); ?></span>
            <span class="ms-tag"><?php echo self::h($s['market'].' · '.$s['language']); ?></span>
            <span class="ms-tag"><?php echo self::h($s['record_count']); ?> results</span>
            <span class="ms-tag <?php echo $s['freshness']==='review_due'?'warn':'ok'; ?>"><?php echo self::h($s['freshness']); ?></span>
            <span class="ms-tag"><?php echo self::h($s['rankings_verified']); ?> verified ranks</span>
          </div>
          <p class="ms-minor">Collected <?php echo self::h($s['collected_at']); ?> · <?php echo self::h($s['status']); ?> · <code><?php echo self::h($s['artifact_id']); ?></code></p>
          <div class="ms-inline"><label class="ms-field">بحث داخل النتائج / Search results<input class="ms-serp-filter" placeholder="Query, domain, or title"></label></div>
          <?php
          $groups=[];
          foreach($s['results'] as $item){
              $key=$group_by_query?($item['query']?:$s['query']):'all';
              $groups[$key][]=$item;
          }
          foreach($groups as $group_name=>$group_items):
          if($group_by_query): ?><h3 style="margin:18px 0 8px;font-size:14px;border-right:3px solid #3d75b6;padding-right:10px"><?php echo self::h($group_name); ?> <span class="ms-tag"><?php echo count($group_items); ?></span></h3><?php endif; ?>
          <?php foreach($group_items as $item): ?><article class="ms-card">
            <div class="ms-inline"><span class="ms-tag"><?php echo self::h($item['query']); ?></span>
              <?php if($item['rank_verified']): ?><span class="ms-tag ok">Position <?php echo self::h($item['rank']); ?></span>
              <?php else: ?><span class="ms-tag warn">Position unverified</span><?php endif; ?>
            </div>
            <h3><a href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo self::h($item['title']); ?></a></h3>
            <div class="ms-domain"><?php echo self::h($item['domain']); ?></div>
            <?php if($item['snippet']): ?><p><?php echo self::h($item['snippet']); ?></p><?php endif; ?>
            <?php if($item['focus']): ?><p><strong>Observation:</strong> <?php echo self::h($item['focus']); ?></p><?php endif; ?>
          </article><?php endforeach; endforeach; ?>
        </div><?php
    }
}
