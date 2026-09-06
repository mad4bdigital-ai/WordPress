<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\InventoryContentCatalog;

final class DynamicContentPage {
    const SLUG='etg-dfsb-dynamic-content';
    private $inventory;private $catalog;private $slots;private $profiles;

    public function __construct(RuntimeInventory $inventory,InventoryContentCatalog $catalog,ContentSlotRegistry $slots,ProfileRegistry $profiles){$this->inventory=$inventory;$this->catalog=$catalog;$this->slots=$slots;$this->profiles=$profiles;}
    public function register():void{add_action('admin_menu',array($this,'menu'));add_action('admin_post_etg_dfsb_save_dynamic_slot',array($this,'save'));add_action('admin_post_etg_dfsb_delete_dynamic_slot',array($this,'delete'));}
    public function menu():void{add_options_page('ETG Dynamic Content','ETG Dynamic Content','manage_options',self::SLUG,array($this,'render'));}

    public function save():void{
        if(!current_user_can('manage_options')){wp_die('Forbidden',403);}check_admin_referer('etg_dfsb_save_dynamic_slot');
        $snapshot=$this->inventory->collect();
        $slot=array(
            'id'=>isset($_POST['slot_id'])?wp_unslash((string)$_POST['slot_id']):'',
            'label'=>isset($_POST['label'])?wp_unslash((string)$_POST['label']):'',
            'enabled'=>isset($_POST['enabled'])?'1':'0',
            'type'=>isset($_POST['type'])?wp_unslash((string)$_POST['type']):'text',
            'template'=>isset($_POST['template'])?wp_unslash((string)$_POST['template']):'',
            'fallback'=>isset($_POST['fallback'])?wp_unslash((string)$_POST['fallback']):'',
            'prefix'=>isset($_POST['prefix'])?wp_unslash((string)$_POST['prefix']):'',
            'suffix'=>isset($_POST['suffix'])?wp_unslash((string)$_POST['suffix']):'',
            'max_length'=>isset($_POST['max_length'])?wp_unslash((string)$_POST['max_length']):'0',
            'source_inventory_fingerprint'=>(string)($snapshot['snapshot_fingerprint']??''),
        );
        $result=$this->slots->save($slot);
        $url=add_query_arg(array('page'=>self::SLUG,'saved'=>!empty($result['saved'])?'1':'0','reason'=>(string)($result['reason']??''),'slot'=>(string)($result['slot']['id']??'')),admin_url('options-general.php'));
        wp_safe_redirect($url);exit;
    }

    public function delete():void{
        if(!current_user_can('manage_options')){wp_die('Forbidden',403);}check_admin_referer('etg_dfsb_delete_dynamic_slot');
        $id=isset($_POST['slot_id'])?sanitize_key(wp_unslash((string)$_POST['slot_id'])):'';
        $wasDefault=$this->slots->isDefault($id);
        $deleted=$this->slots->delete($id);
        wp_safe_redirect(add_query_arg(array('page'=>self::SLUG,'deleted'=>$deleted?'1':'0','restored'=>$deleted&&$wasDefault?'1':'0'),admin_url('options-general.php')));exit;
    }

    public function render():void{
        if(!current_user_can('manage_options')){return;}
        $snapshot=$this->inventory->collect();
        $catalog=$this->catalog->build($snapshot,$this->profiles->all());
        $slots=$this->slots->all();
        $edit=isset($_GET['slot'])?$this->slots->get(sanitize_key((string)wp_unslash($_GET['slot']))):array();
        $seed=isset($_GET['token'])?strtolower(trim((string)wp_unslash($_GET['token']))):'';
        if(!$edit&&$seed&&isset($catalog['tokens'][$seed])){$edit=array('id'=>sanitize_key(str_replace(':','_',$seed)),'label'=>(string)$catalog['tokens'][$seed]['label'],'enabled'=>true,'type'=>(string)$catalog['tokens'][$seed]['type'],'template'=>'{{'.$seed.'}}','fallback'=>'','prefix'=>'','suffix'=>'','max_length'=>0,'origin'=>'custom');}
        $edit=array_merge(array('id'=>'','label'=>'','enabled'=>true,'type'=>'text','template'=>'','fallback'=>'','prefix'=>'','suffix'=>'','max_length'=>0,'origin'=>'custom'),$edit);
        $builtIn=0;$custom=0;$overrides=0;$enabled=0;foreach($slots as $slot){$enabled+=!empty($slot['enabled'])?1:0;$origin=(string)($slot['origin']??'custom');if('built_in'===$origin){$builtIn++;}elseif('override'===$origin){$overrides++;}else{$custom++;}}
        ?>
        <div class="wrap etg-dfsb-admin etg-dfsb-dynamic-content">
            <div class="etg-page-head"><div><h1>ETG Dynamic Content</h1><p>Build presentation-only content for Elementor and JetSmartFilters from governed Runtime Inventory. Slots and Dynamic Tags never enable a profile, indexing, sitemap, canonical, or SEO publication authority.</p></div><div class="etg-actions"><a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>'etg-dfsb-inventory-control'),admin_url('options-general.php'))); ?>">Inventory Control</a><a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>'etg-filter-seo-publication','tab'=>'elementor'),admin_url('options-general.php'))); ?>">Elementor Publication</a></div></div>

            <?php if(isset($_GET['saved'])):?><div class="notice notice-<?php echo '1'===(string)$_GET['saved']?'success':'error'; ?> is-dismissible"><p><?php echo esc_html('1'===(string)$_GET['saved']?'Dynamic content slot saved.':'Slot was not saved: '.sanitize_text_field((string)($_GET['reason']??'unknown'))); ?></p></div><?php endif; ?>
            <?php if(isset($_GET['deleted'])):?><div class="notice notice-<?php echo '1'===(string)$_GET['deleted']?'success':'warning'; ?> is-dismissible"><p><?php echo esc_html('1'===(string)$_GET['deleted']?(!empty($_GET['restored'])?'Customization removed; the built-in slot is active again.':'Custom slot deleted.'):'No saved customization was found to delete.'); ?></p></div><?php endif; ?>

            <div class="etg-status-grid">
                <div class="etg-status-card"><span class="etg-status-label">Authority</span><span class="etg-status-value"><span class="etg-badge etg-badge--readonly">PRESENTATION ONLY</span></span><span class="etg-status-help">No indexing or profile mutation.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Available Slots</span><span class="etg-status-value"><?php echo esc_html((string)count($slots)); ?></span><span class="etg-status-help"><?php echo esc_html((string)$builtIn); ?> built-in · <?php echo esc_html((string)$overrides); ?> customized · <?php echo esc_html((string)$custom); ?> custom</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Enabled Slots</span><span class="etg-status-value"><?php echo esc_html((string)$enabled); ?></span><span class="etg-status-help">Only enabled slots can be requested by AJAX presentation.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Inventory Tokens</span><span class="etg-status-value"><?php echo esc_html((string)count((array)($catalog['tokens']??array()))); ?></span><span class="etg-status-help">Runtime-derived and allowlisted.</span></div>
                <div class="etg-status-card"><span class="etg-status-label">Inventory Fingerprint</span><span class="etg-status-value"><code><?php echo esc_html(substr((string)($snapshot['snapshot_fingerprint']??''),0,16)); ?></code></span><span class="etg-status-help">Saved customizations record the current snapshot.</span></div>
            </div>

            <section class="etg-panel"><div class="etg-panel__head"><div><h2>Content Slots</h2><p class="description">Six safe built-ins keep Elementor selectors usable even before you create custom content.</p></div><div class="etg-actions"><a class="button button-primary" href="<?php echo esc_url(add_query_arg(array('page'=>self::SLUG),admin_url('options-general.php'))); ?>#etg-slot-editor">Create Custom Slot</a></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Slot</th><th>Type</th><th>Origin</th><th>Status</th><th>Inventory</th><th>Actions</th></tr></thead><tbody>
            <?php foreach($slots as $slot):$origin=(string)($slot['origin']??'custom'); ?><tr><td><strong><?php echo esc_html($slot['label']); ?></strong><br><code><?php echo esc_html($slot['id']); ?></code></td><td><?php echo esc_html(strtoupper((string)$slot['type'])); ?></td><td><span class="etg-slot-origin etg-slot-origin--<?php echo esc_attr($origin); ?>"><?php echo esc_html(str_replace('_',' ',$origin)); ?></span></td><td><span class="etg-badge <?php echo !empty($slot['enabled'])?'etg-badge--safe':'etg-badge--warn'; ?>"><?php echo !empty($slot['enabled'])?'Enabled':'Disabled'; ?></span></td><td><?php echo !empty($slot['source_inventory_fingerprint'])?'<code>'.esc_html(substr((string)$slot['source_inventory_fingerprint'],0,12)).'</code>':'<span class="description">Built-in</span>'; ?></td><td><a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page'=>self::SLUG,'slot'=>$slot['id']),admin_url('options-general.php'))); ?>#etg-slot-editor"><?php echo 'built_in'===$origin?'Customize':'Edit'; ?></a><?php if('built_in'!==$origin): ?> <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"><input type="hidden" name="action" value="etg_dfsb_delete_dynamic_slot"><input type="hidden" name="slot_id" value="<?php echo esc_attr($slot['id']); ?>"><?php wp_nonce_field('etg_dfsb_delete_dynamic_slot'); ?><button type="submit" class="button button-small"><?php echo 'override'===$origin?'Reset':'Delete'; ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></section>

            <section id="etg-slot-editor" class="etg-panel"><div class="etg-panel__head"><div><h2><?php echo $edit['id']?('built_in'===(string)$edit['origin']?'Customize Built-in Slot':'Edit Slot'):'Create Slot'; ?></h2><p class="description">Templates use allowlisted <code>{{token}}</code> placeholders. Hard-coded language fallbacks are optional; leaving fallback empty is safer for WPML.</p></div><?php if($edit['id']):?><span class="etg-badge"><?php echo esc_html((string)$edit['id']); ?></span><?php endif; ?></div><div class="etg-panel__body etg-editor-grid"><div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="etg_dfsb_save_dynamic_slot"><?php wp_nonce_field('etg_dfsb_save_dynamic_slot'); ?><table class="form-table" role="presentation">
                <tr><th><label for="etg-slot-id">Slot ID</label></th><td><input id="etg-slot-id" name="slot_id" class="regular-text code" required value="<?php echo esc_attr((string)$edit['id']); ?>"><p class="description">Stable key used by Elementor Dynamic Tag, shortcode and PHP API.</p></td></tr>
                <tr><th><label for="etg-slot-label">Label</label></th><td><input id="etg-slot-label" name="label" class="regular-text" value="<?php echo esc_attr((string)$edit['label']); ?>"></td></tr>
                <tr><th>Enabled</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($edit['enabled'])); ?>> Render this presentation slot</label></td></tr>
                <tr><th><label for="etg-slot-type">Output Type</label></th><td><select id="etg-slot-type" name="type"><?php foreach(array('text'=>'Text','html'=>'HTML','url'=>'URL','image'=>'Image ID / URL') as $k=>$label):?><option value="<?php echo esc_attr($k); ?>" <?php selected((string)$edit['type'],$k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><p class="description">Use dedicated ETG Image/Gallery Dynamic Tags for native Elementor media controls.</p></td></tr>
                <tr><th><label for="etg-slot-template">Template</label></th><td><textarea id="etg-slot-template" name="template" class="large-text code" rows="7"><?php echo esc_textarea((string)$edit['template']); ?></textarea><p class="description">Example: <code>Explore {{term:location:name}} — {{result_count}} tours</code>.</p></td></tr>
                <tr><th><label for="etg-slot-fallback">Fallback</label></th><td><textarea id="etg-slot-fallback" name="fallback" class="large-text" rows="2"><?php echo esc_textarea((string)$edit['fallback']); ?></textarea><p class="description">Optional. Keep empty when a single language fallback would be unsafe.</p></td></tr>
                <tr><th>Wrapper Text</th><td><input name="prefix" placeholder="Prefix" value="<?php echo esc_attr((string)$edit['prefix']); ?>"> <input name="suffix" placeholder="Suffix" value="<?php echo esc_attr((string)$edit['suffix']); ?>"></td></tr>
                <tr><th><label for="etg-slot-max">Max Length</label></th><td><input id="etg-slot-max" type="number" min="0" max="20000" name="max_length" value="<?php echo esc_attr((string)$edit['max_length']); ?>"><p class="description">0 means no additional slot-level truncation.</p></td></tr>
            </table><?php submit_button($edit['id']?'Save Slot':'Create Dynamic Content Slot'); ?></form></div><aside class="etg-editor-help"><h3>Elementor Alpha13</h3><p>Use direct tags for the common cases:</p><ul><li><strong>Heading:</strong> ETG Filter Title</li><li><strong>Intro:</strong> ETG Filter Intro</li><li><strong>Term content:</strong> ETG Filter Term Field / Term Section</li><li><strong>Background/Image:</strong> ETG Filter Image</li><li><strong>Gallery:</strong> ETG Filter Gallery</li></ul><p><strong>Editor Preview URL</strong> is available inside ETG tags and is evidence-only. Live front-end rendering ignores that preview setting.</p><p class="description">Native Elementor image/gallery attributes render from ETG on server/editor preview. Transient AJAX media attribute replacement remains a separate runtime adapter boundary; text/HTML and governed custom URL targets remain the live bridge.</p></aside></div></section>

            <section class="etg-panel"><div class="etg-panel__head"><div><h2>Runtime Inventory Token Catalog</h2><p class="description">Choose Use to prefill a custom slot. Token discovery is read-only and non-authorizing.</p></div></div><div class="etg-panel__body"><div class="etg-token-toolbar"><span><strong><?php echo esc_html((string)count((array)($catalog['tokens']??array()))); ?></strong> allowlisted tokens</span><input id="etg-token-search" type="search" placeholder="Search token, label, type or source…" aria-label="Search Runtime Inventory tokens"></div><div class="etg-table-scroll"><table class="widefat striped" id="etg-token-table"><thead><tr><th>Token</th><th>Label</th><th>Type</th><th>Source</th><th></th></tr></thead><tbody>
            <?php foreach((array)($catalog['tokens']??array()) as $token=>$meta):$search=strtolower($token.' '.(string)$meta['label'].' '.(string)$meta['type'].' '.(string)$meta['source']); ?><tr class="etg-token-row" data-search="<?php echo esc_attr($search); ?>"><td><code><?php echo esc_html('{{'.$token.'}}'); ?></code></td><td><?php echo esc_html((string)$meta['label']); ?></td><td><?php echo esc_html((string)$meta['type']); ?></td><td><?php echo esc_html((string)$meta['source']); ?></td><td><a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page'=>self::SLUG,'token'=>$token),admin_url('options-general.php'))); ?>#etg-slot-editor">Use</a></td></tr><?php endforeach; ?>
            </tbody></table></div></div></section>
        </div>
        <script>(function(){var q=document.getElementById('etg-token-search');if(!q){return;}var rows=document.querySelectorAll('.etg-token-row');q.addEventListener('input',function(){var needle=(q.value||'').toLowerCase().trim();Array.prototype.forEach.call(rows,function(row){row.hidden=!!needle&&String(row.getAttribute('data-search')||'').indexOf(needle)===-1;});});}());</script>
        <?php
    }
}
