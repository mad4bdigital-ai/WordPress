<?php
/** MAD4B Catalog Feed Studio: company-owned workbench, no partner upload. */
defined('ABSPATH') || exit;
final class MAD4B_Catalog_Studio_Admin_V1 {
    private const PAGE='mcfm_feeds_v2';
    private static function e(string $s): string {return esc_html($s);}
    private static function href(string $slug='',array $more=[]): string {
        return MAD4B_Catalog_Studio_V1::page_url($slug,$more);
    }
    public static function menus(): void {
        // Existing MCFM remains fully accessible as Advanced Mapping; identical
        // data store and resolver, no cross-plugin code copied.
        if(!function_exists('mcfm_admin_page'))return;
        remove_submenu_page('options-general.php',self::PAGE);
        // Reusing the slug without removing the original hook would render
        // BOTH UIs and potentially process the same POST twice.
        remove_action('settings_page_'.self::PAGE,'mcfm_admin_page');
        add_options_page('MAD4B Catalog Feed Studio','Catalog Feed Studio','manage_options',self::PAGE,[__CLASS__,'render']);
        add_options_page('Catalog Feed Studio — Advanced Mapping','Feed Mapping (Advanced)',
            'manage_options','mad4b-catalog-advanced','mcfm_admin_page');
    }
    private static function form_start(string $action,string $slug='',string $class=''): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="'.esc_attr($class).'">';
        wp_nonce_field('mad4b_catalog_studio_action');
        echo '<input type="hidden" name="action" value="mad4b_catalog_studio_action">';
        echo '<input type="hidden" name="studio_action" value="'.esc_attr($action).'">';
        echo '<input type="hidden" name="slug" value="'.esc_attr($slug).'">';
        if($slug!==''){
            $current=MAD4B_Catalog_Studio_V1::get($slug);
            if($current)echo '<input type="hidden" name="feed_revision" value="'.
                esc_attr(MAD4B_Catalog_Studio_V1::fingerprint($current['feed'])).'">';
        }
    }
    private static function action(string $action,string $slug,string $label,bool $disabled=false,string $class=''): void {
        self::form_start($action,$slug,'mad4b-studio__inline-form');
        echo '<button type="submit" class="button '.esc_attr($class).'" '.disabled($disabled,true,false).'>'.self::e($label).'</button></form>';
    }
    private static function status(array $feed): array {
        $item=MAD4B_Catalog_Studio_V1::get_state($feed);
        return match($item['status']) {
            'READY_INTERNAL'=>['Ready internally','ready'],
            'VALIDATED_INTERNAL'=>['Validated','validated'],
            'VALIDATION_BLOCKED'=>['Needs fixes','blocked'],
            default=>['Draft / unreviewed','draft'],
        };
    }
    public static function render(): void {
        if(!current_user_can('manage_options'))wp_die('Not authorized',403);
        $canUse=function_exists('mcfm_get_feeds') && class_exists('MAD4B_Catalog_Studio_V1');
        $all=$canUse?mcfm_get_feeds():[];
        $managed=[];$privateCount=0;$destinationCount=0;$validated=0;
        foreach($all as $i=>$raw){
            $feed=mcfm_normalize_feed($raw);
            if(!MAD4B_Catalog_Studio_V1::is_private($feed))continue;
            $managed[$feed['slug']]=['index'=>$i,'feed'=>$feed];$privateCount++;
            if(($feed['object_key']??'')==='destinations')$destinationCount++;
            if(in_array(MAD4B_Catalog_Studio_V1::get_state($feed)['status'],['READY_INTERNAL','VALIDATED_INTERNAL'],true))$validated++;
        }
        $selected=isset($_GET['feed'])?sanitize_title(wp_unslash((string)$_GET['feed'])):'';
        if(!isset($managed[$selected]))$selected=(string)(array_key_first($managed)??'');
        $chosen=$selected!==''?($managed[$selected]['feed']??null):null;
        $review=$chosen?MAD4B_Catalog_Studio_V1::get_state($chosen):[];
        $preview=$chosen?MAD4B_Catalog_Studio_V1::get_preview($selected):[];
        $import=$chosen?MAD4B_Catalog_Studio_V1::get_import_review($selected):[];
        $wpae=class_exists('PMXE_Plugin') && current_user_can(PMXE_Plugin::$capabilities);
        $wpai=class_exists('PMXI_Plugin') && current_user_can('manage_options');
        ?>
        <div class="wrap mad4b-studio" id="mad4b-studio-workspace">
          <div class="mad4b-studio__eyebrow">MAD4B / DISTRIBUTION SUITE</div>
          <header class="mad4b-studio__header"><div><h1>Catalog Feed Studio</h1><p>Build, validate and preview tour and destination feeds using the live MAD4B resolvers.</p></div><div class="mad4b-studio__top-actions">
            <a class="button" href="<?php echo esc_url(admin_url('tools.php?page=mad4b-distribution-channels')); ?>">Distribution Control Center</a>
            <a class="button" href="<?php echo esc_url(admin_url('options-general.php?page=mad4b-catalog-advanced')); ?>">Advanced Mapper</a>
          </div></header>
          <div class="mad4b-studio__metrics"><div><span>Saved feeds</span><strong><?php echo count($all); ?></strong><small>Source-of-truth MCFM profiles</small></div><div><span>Private profiles</span><strong><?php echo $privateCount; ?></strong><small>MAD4B internal export paths</small></div><div><span>Validated profiles</span><strong><?php echo $validated; ?></strong><small>Current mapping fingerprint</small></div><div><span>Destination feeds</span><strong><?php echo $destinationCount; ?></strong><small>Taxonomy-sourced profiles</small></div></div>
          <?php if(isset($_GET['studio_notice'])): ?><div class="mad4b-studio__notice" role="status"><?php echo self::e(ucwords(str_replace('_',' ',sanitize_key((string)$_GET['studio_notice'])))); ?> — review the feed status below.</div><?php endif; ?>
          <?php if(!$canUse): ?><div class="mad4b-studio__notice">The Meta Catalog Feed Mapper dependency is not active. Feed editing is unavailable until the local company plugin is installed and activated.</div><?php endif; ?>
          <div class="mad4b-studio__message"><span class="dashicons dashicons-shield"></span><div><strong>Private preview &amp; delivery separation</strong><p>Validate, Preview and Mark Ready work inside this company. None of these buttons uploads to Google, Meta, OTAs or partners. External delivery is controlled by verified channel connectors.</p></div></div>
          <div class="mad4b-studio__layout">
            <aside class="mad4b-studio__library" aria-label="Feed library"><div class="mad4b-studio__pane-head"><h2>Feed library</h2><a href="#mad4b-studio-create">+ New feed</a></div><p class="mad4b-studio__caption">Select a private feed to edit and preview.</p>
              <div class="mad4b-studio__feed-list">
              <?php foreach($managed as $slug=>$item): $f=$item['feed'];[$label,$kind]=self::status($f); ?><a href="<?php echo esc_url(self::href($slug).'#mad4b-studio-workbench'); ?>" class="mad4b-studio__feed <?php echo $slug===$selected?'is-selected':''; ?>"><span class="mad4b-studio__feed-icon dashicons <?php echo $f['object_type']==='taxonomy'?'dashicons-location':'dashicons-media-spreadsheet'; ?>"></span><span class="mad4b-studio__feed-label"><strong><?php echo self::e((string)$f['label']); ?></strong><small><?php echo self::e((string)$f['object_key']); ?></small><em class="is-<?php echo esc_attr($kind); ?>"><?php echo self::e($label); ?></em></span></a><?php endforeach; ?>
              <?php if(!$managed): ?><div class="mad4b-studio__empty">No private feeds yet. Create one below to begin.</div><?php endif; ?></div>
              <?php $others=[];foreach($all as $i=>$raw){$other=mcfm_normalize_feed($raw);if(!MAD4B_Catalog_Studio_V1::is_private($other))$others[$i]=$other;} ?>
              <?php if($others): ?><details class="mad4b-studio__legacy"><summary>Other configured feeds (<?php echo count($others); ?>)</summary><p>Existing external/legacy profiles remain editable through the original Mapper; they are not automatically marked private.</p>
              <?php foreach($others as $i=>$other): ?><a href="<?php echo esc_url(add_query_arg(['page'=>'mad4b-catalog-advanced','edit'=>$i],admin_url('options-general.php'))); ?>"><?php echo self::e((string)$other['label']); ?></a><?php endforeach; ?></details><?php endif; ?>
              <div class="mad4b-studio__integration"><span class="dashicons dashicons-database-export"></span><strong>WP All Export Pro</strong><p>Use its full export designer and native Preview for advanced WordPress exports. MAD4B feed previews use the same field resolver as their real export.</p>
                <?php if($wpae): ?><a href="<?php echo esc_url(admin_url('admin.php?page=pmxe-admin-export')); ?>" class="button">Open WP All Export</a><?php else: ?><span>WP All Export is unavailable to this account.</span><?php endif; ?>
                <?php if($wpai): ?><a href="<?php echo esc_url(admin_url('admin.php?page=pmxi-admin-import')); ?>" class="button">Open WP All Import</a><?php endif; ?></div>
            </aside>
            <main class="mad4b-studio__workbench" id="mad4b-studio-workbench">
              <?php if($chosen): $status=self::status($chosen); ?>
              <div class="mad4b-studio__workbench-head"><div><span>SELECTED FEED</span><h2><?php echo self::e((string)$chosen['label']); ?></h2><p><code><?php echo self::e($selected); ?></code> · <?php echo self::e((string)$chosen['object_type'].' / '.$chosen['object_key']); ?></p></div><span class="mad4b-studio__pill is-<?php echo esc_attr($status[1]); ?>"><?php echo self::e($status[0]); ?></span></div>
              <div class="mad4b-studio__toolbar">
                <?php self::action('validate',$selected,'Validate current mapping',false,'button-primary'); ?>
                <?php self::form_start('preview',$selected,'mad4b-studio__inline-form mad4b-studio__preview-language'); ?>
                  <label>Preview language <select name="preview_language" aria-label="Preview language">
                    <option value="">Feed default</option>
                    <?php foreach((array)mcfm_get_active_languages() as $code=>$language): ?>
                    <option value="<?php echo esc_attr((string)$code); ?>"><?php echo self::e((string)($language['native_name']??$code)); ?></option>
                    <?php endforeach; ?>
                  </select></label>
                  <button type="submit" class="button">Preview 5 rows</button>
                </form>
                <?php self::action('publish_internal',$selected,'Mark ready internally',($review['status']??'')!=='VALIDATED_INTERNAL'); ?>
                <?php self::action('duplicate',$selected,'Duplicate as draft'); ?>
                <?php if(isset($managed[$selected])): $idx=$managed[$selected]['index'];$download=wp_nonce_url(add_query_arg(['action'=>'mcfm_export_template','feed'=>$idx],admin_url('admin-post.php')),'mcfm_export_template'); ?><a class="button" href="<?php echo esc_url($download); ?>">Download mapping JSON</a><?php endif; ?>
              </div>
              <div class="mad4b-studio__workbench-sections"><section class="mad4b-studio__panel"><div class="mad4b-studio__panel-head"><h3>Mapping Studio</h3><span><?php echo count($chosen['mapping']); ?> mapped columns</span></div><p class="mad4b-studio__caption">Save a draft to update the current profile. Any saved change expires its previous validation receipt.</p>
                <?php self::form_start('save_mapping',$selected,'mad4b-studio__mapping-form'); ?>
                <div class="mad4b-studio__mapping-scroll"><table class="widefat striped mad4b-studio__mapping-table"><thead><tr><th>Output column</th><th>Source type</th><th>Source field / key</th><th>Options</th><th>Required</th><th></th></tr></thead><tbody id="mad4b-studio-mapping-body">
                  <?php $rows=$chosen['mapping'];if(count($rows)<3)$rows=array_merge($rows,array_fill(0,3-count($rows),['column'=>'','source_type'=>'meta','source'=>'','extra'=>'','required_non_empty'=>0]));
                  foreach($rows as $i=>$row): ?><tr><td><input name="column[<?php echo $i; ?>]" value="<?php echo esc_attr((string)($row['column']??'')); ?>" placeholder="output_field"></td><td><select name="source_type[<?php echo $i; ?>]">
                    <?php foreach(mcfm_get_mapping_types($chosen) as $type=>$name): ?><option value="<?php echo esc_attr($type); ?>" <?php selected($row['source_type']??'meta',$type); ?>><?php echo self::e($name); ?></option><?php endforeach; ?></select></td><td><input name="source[<?php echo $i; ?>]" value="<?php echo esc_attr((string)($row['source']??'')); ?>" placeholder="source or meta key"></td><td><input name="extra[<?php echo $i; ?>]" value="<?php echo esc_attr((string)($row['extra']??'')); ?>" placeholder="optional"></td><td><input type="checkbox" name="required[<?php echo $i; ?>]" value="1" aria-label="Required output row <?php echo $i+1; ?>" <?php checked(!empty($row['required_non_empty'])); ?>></td><td><button class="button mad4b-studio__remove-row" type="button" aria-label="Remove mapping row <?php echo $i+1; ?>">×</button></td></tr><?php endforeach; ?></tbody></table></div>
                <div class="mad4b-studio__form-actions"><button type="button" class="button" id="mad4b-studio-add-row">+ Add mapping row</button><button type="submit" class="button button-primary">Save mapping draft</button></div></form>
              </section>
              <section class="mad4b-studio__panel" id="mad4b-studio-preview"><div class="mad4b-studio__panel-head"><h3>Live field preview</h3><span>Up to 5 eligible source rows</span></div>
                <?php if($preview): $columns=array_keys($preview[0]);$columns=array_slice($columns,0,12); ?><div class="mad4b-studio__preview-scroll"><table class="widefat striped"><thead><tr><?php foreach($columns as $col): ?><th><?php echo self::e((string)$col); ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach($preview as $row): ?><tr><?php foreach($columns as $col): ?><td><?php $v=$row[$col]??'';echo self::e(mb_strimwidth(is_scalar($v)?(string)$v:wp_json_encode($v),0,220,'…')); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><details><summary>Preview raw JSON</summary><pre class="mad4b-studio__raw"><?php echo self::e((string)wp_json_encode($preview,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></pre></details><?php else: ?><div class="mad4b-studio__preview-placeholder"><span class="dashicons dashicons-visibility"></span><strong>No preview loaded</strong><p>Click Preview 5 rows to render actual mapped values. This does not publish or send data.</p></div><?php endif; ?>
              </section>
              </div>
              <?php else: ?><div class="mad4b-studio__empty-state"><h2>Select or create a feed</h2><p>Choose an existing profile from the library or create a private company feed below.</p></div><?php endif; ?>
            </main>
            <aside class="mad4b-studio__inspector"><div class="mad4b-studio__pane-head"><h2>Validation &amp; settings</h2></div>
              <?php if($chosen): ?><section class="mad4b-studio__side-panel"><h3>Readiness checklist</h3><div class="mad4b-studio__readiness"><strong class="is-<?php echo esc_attr($status[1]); ?>"><?php echo self::e($status[0]); ?></strong><p><?php echo !empty($review['checked_at'])?'Last checked: '.self::e((string)$review['checked_at']):'Not validated against current mapping'; ?></p></div>
                <?php foreach((array)($review['issues']??[]) as $issue): ?><div class="mad4b-studio__issue is-error"><span class="dashicons dashicons-warning"></span><?php echo self::e((string)$issue); ?></div><?php endforeach; ?><?php foreach((array)($review['warnings']??[]) as $issue): ?><div class="mad4b-studio__issue is-warning"><span class="dashicons dashicons-info"></span><?php echo self::e((string)$issue); ?></div><?php endforeach; ?>
                <div class="mad4b-studio__checks"><div>Mapping version <b><?php echo substr(MAD4B_Catalog_Studio_V1::fingerprint($chosen),0,10); ?></b></div><div>Previewed rows <b><?php echo (int)($review['sample_count']??0); ?></b></div><div>External delivery <b>OFF</b></div></div></section>
              <section class="mad4b-studio__side-panel"><h3>Feed settings</h3><?php self::form_start('save_profile',$selected,'mad4b-studio__settings-form'); ?><label>Export format <select name="format"><?php foreach(['csv','tsv','xml','rss','atom','xlsx'] as $format): ?><option value="<?php echo $format; ?>" <?php selected($chosen['default_format'],$format); ?>><?php echo strtoupper($format); ?></option><?php endforeach; ?></select></label>
                <label>Languages <select name="language_mode"><option value="all" <?php selected($chosen['language_mode'],'all'); ?>>All enabled</option><option value="default" <?php selected($chosen['language_mode'],'default'); ?>>Default only</option><option value="specific" <?php selected($chosen['language_mode'],'specific'); ?>>Specific language</option></select></label>
                <label>Language code <input name="language_code" placeholder="en" value="<?php echo esc_attr((string)($chosen['language_code']??'')); ?>"></label>
                <?php if($chosen['object_type']==='taxonomy'): ?><label class="mad4b-studio__checkbox"><input type="checkbox" name="hide_empty_terms" value="1" <?php checked(!empty($chosen['hide_empty_terms'])); ?>> Hide empty terms</label><?php endif; ?>
                <button class="button" type="submit">Save feed settings</button></form></section>
              <section class="mad4b-studio__side-panel"><h3>Import mapping — two-step review</h3><p>Upload a JSON mapping or CSV headers, inspect the proposed fields, then confirm. The original profile remains unchanged until confirmation.</p>
                <?php if($import): ?><div class="mad4b-studio__import-review"><strong>Pending import review</strong><p><?php echo count($import['mapping']); ?> fields staged. Current mapping has not changed.</p><ul><?php foreach(array_slice($import['mapping'],0,8) as $item): ?><li><?php echo self::e((string)($item['column']??'')); ?></li><?php endforeach; ?></ul><?php self::form_start('apply_import',$selected); ?><input type="hidden" name="review_token" value="<?php echo esc_attr((string)$import['token']); ?>"><button class="button button-primary" type="submit">Confirm import mapping</button></form></div><?php endif; ?>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mad4b-studio__settings-form"><?php wp_nonce_field('mad4b_catalog_studio_action'); ?><input type="hidden" name="action" value="mad4b_catalog_studio_action"><input type="hidden" name="studio_action" value="import_review"><input type="hidden" name="slug" value="<?php echo esc_attr($selected); ?>"><input type="hidden" name="feed_revision" value="<?php echo esc_attr(MAD4B_Catalog_Studio_V1::fingerprint($chosen)); ?>"><input type="file" name="template_file" accept=".json,.csv,.txt"><button class="button" type="submit">Review import</button></form></section>
              <section class="mad4b-studio__side-panel"><h3>Publishing targets</h3><p>Meta Catalog, Google Things to do, and external marketplaces remain inactive until their separate channel validation and provider approval.</p><a class="button" href="<?php echo esc_url(admin_url('tools.php?page=mad4b-distribution-channels')); ?>">Check channel readiness</a></section>
              <?php else: ?><div class="mad4b-studio__caption">Select a feed to inspect validation and profile settings.</div><?php endif; ?>
            </aside>
          </div>
          <section class="mad4b-studio__new" id="mad4b-studio-create"><div><h2>Create company feed</h2><p>Creates a private draft. No external publishing or scheduled exports.</p></div><?php self::form_start('create','','mad4b-studio__create-form'); ?><label>Name <input name="label" placeholder="e.g. Partner Tour Catalog" required minlength="3"></label><label>Slug <input name="slug" placeholder="partner-tours" pattern="[a-z0-9-]+" required></label><label>Source type <select name="object_type" id="mad4b-studio-object-type"><option value="post_type">Post type</option><option value="taxonomy">Taxonomy</option></select></label><label>Source <select name="object_key" id="mad4b-studio-object-key"><?php foreach(get_post_types(['public'=>true],'objects') as $name=>$obj): if($name==='attachment')continue; ?><option data-kind="post_type" value="<?php echo esc_attr($name); ?>"><?php echo self::e($obj->labels->name); ?></option><?php endforeach; ?><?php foreach(get_taxonomies(['public'=>true],'objects') as $name=>$obj): if($name==='post_format')continue; ?><option data-kind="taxonomy" value="<?php echo esc_attr($name); ?>"><?php echo self::e($obj->labels->name); ?></option><?php endforeach; ?></select></label><button class="button button-primary" type="submit">Create private draft</button></form></section>
          <footer class="mad4b-studio__footer">MAD4B Distribution Suite · Company-local feed profiles · Powered by existing Meta Catalog Feed Mapper resolvers · WP All Export available for advanced exports</footer>
        </div>
        <?php
    }
    public static function boot(): void {add_action('admin_menu',[__CLASS__,'menus'],99);}
}
MAD4B_Catalog_Studio_Admin_V1::boot();
