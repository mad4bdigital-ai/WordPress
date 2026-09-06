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

    public function save():void{if(!current_user_can('manage_options')){wp_die('Forbidden',403);}check_admin_referer('etg_dfsb_save_dynamic_slot');$snapshot=$this->inventory->collect();$slot=array('id'=>isset($_POST['slot_id'])?wp_unslash((string)$_POST['slot_id']):'','label'=>isset($_POST['label'])?wp_unslash((string)$_POST['label']):'','enabled'=>isset($_POST['enabled'])?'1':'0','type'=>isset($_POST['type'])?wp_unslash((string)$_POST['type']):'text','template'=>isset($_POST['template'])?wp_unslash((string)$_POST['template']):'','fallback'=>isset($_POST['fallback'])?wp_unslash((string)$_POST['fallback']):'','prefix'=>isset($_POST['prefix'])?wp_unslash((string)$_POST['prefix']):'','suffix'=>isset($_POST['suffix'])?wp_unslash((string)$_POST['suffix']):'','max_length'=>isset($_POST['max_length'])?wp_unslash((string)$_POST['max_length']):'0','source_inventory_fingerprint'=>(string)($snapshot['snapshot_fingerprint']??''));$result=$this->slots->save($slot);$url=add_query_arg(array('page'=>self::SLUG,'saved'=>!empty($result['saved'])?'1':'0','reason'=>(string)($result['reason']??'')),admin_url('options-general.php'));wp_safe_redirect($url);exit;}
    public function delete():void{if(!current_user_can('manage_options')){wp_die('Forbidden',403);}check_admin_referer('etg_dfsb_delete_dynamic_slot');$id=isset($_POST['slot_id'])?sanitize_key(wp_unslash((string)$_POST['slot_id'])):'';$this->slots->delete($id);wp_safe_redirect(add_query_arg(array('page'=>self::SLUG,'deleted'=>'1'),admin_url('options-general.php')));exit;}

    public function render():void{if(!current_user_can('manage_options')){return;}$snapshot=$this->inventory->collect();$catalog=$this->catalog->build($snapshot,$this->profiles->all());$slots=$this->slots->all();$edit=isset($_GET['slot'])?$this->slots->get(sanitize_key((string)wp_unslash($_GET['slot']))):array();$seed=isset($_GET['token'])?strtolower(trim((string)wp_unslash($_GET['token']))):'';if(!$edit&&$seed&&isset($catalog['tokens'][$seed])){$edit=array('id'=>sanitize_key(str_replace(':','_',$seed)),'label'=>(string)$catalog['tokens'][$seed]['label'],'enabled'=>true,'type'=>(string)$catalog['tokens'][$seed]['type'],'template'=>'{{'.$seed.'}}','fallback'=>'','prefix'=>'','suffix'=>'','max_length'=>0);}
        $edit=array_merge(array('id'=>'','label'=>'','enabled'=>true,'type'=>'text','template'=>'','fallback'=>'','prefix'=>'','suffix'=>'','max_length'=>0),$edit);
        ?>
        <div class="wrap"><h1>ETG Dynamic Content</h1>
        <p><strong>Presentation-only control.</strong> Build reusable content slots from the live Runtime Inventory. Saving a slot never enables a profile, Global bridge, indexing, sitemap publication or canonical authority.</p>
        <p><strong>Inventory fingerprint:</strong> <code><?php echo esc_html((string)($snapshot['snapshot_fingerprint']??'')); ?></code> &nbsp; <strong>Tokens:</strong> <?php echo esc_html((string)count((array)($catalog['tokens']??array()))); ?></p>
        <?php if(isset($_GET['saved'])):?><div class="notice notice-<?php echo '1'===(string)$_GET['saved']?'success':'error'; ?> is-dismissible"><p><?php echo esc_html('1'===(string)$_GET['saved']?'Dynamic content slot saved.':'Slot was not saved: '.sanitize_text_field((string)($_GET['reason']??'unknown'))); ?></p></div><?php endif; ?>
        <h2>Content Slots</h2>
        <table class="widefat striped"><thead><tr><th>ID</th><th>Label</th><th>Type</th><th>Status</th><th>Inventory fingerprint</th><th>Actions</th></tr></thead><tbody>
        <?php if(!$slots):?><tr><td colspan="6">No slots yet. Use a token below to create one.</td></tr><?php endif; ?>
        <?php foreach($slots as $slot):?><tr><td><code><?php echo esc_html($slot['id']); ?></code></td><td><?php echo esc_html($slot['label']); ?></td><td><?php echo esc_html($slot['type']); ?></td><td><?php echo !empty($slot['enabled'])?'Enabled':'Disabled'; ?></td><td><code><?php echo esc_html(substr((string)$slot['source_inventory_fingerprint'],0,12)); ?></code></td><td><a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page'=>self::SLUG,'slot'=>$slot['id']),admin_url('options-general.php'))); ?>">Edit</a> <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"><input type="hidden" name="action" value="etg_dfsb_delete_dynamic_slot"><input type="hidden" name="slot_id" value="<?php echo esc_attr($slot['id']); ?>"><?php wp_nonce_field('etg_dfsb_delete_dynamic_slot'); ?><button type="submit" class="button button-small">Delete</button></form></td></tr><?php endforeach; ?>
        </tbody></table>

        <h2><?php echo $edit['id']?'Edit Slot':'Create Slot'; ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="etg_dfsb_save_dynamic_slot"><?php wp_nonce_field('etg_dfsb_save_dynamic_slot'); ?>
        <table class="form-table" role="presentation">
        <tr><th><label for="etg-slot-id">Slot ID</label></th><td><input id="etg-slot-id" name="slot_id" class="regular-text code" required value="<?php echo esc_attr((string)$edit['id']); ?>"><p class="description">Stable key used by Elementor Dynamic Tag, shortcode and PHP API.</p></td></tr>
        <tr><th><label for="etg-slot-label">Label</label></th><td><input id="etg-slot-label" name="label" class="regular-text" value="<?php echo esc_attr((string)$edit['label']); ?>"></td></tr>
        <tr><th>Enabled</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($edit['enabled'])); ?>> Render this presentation slot</label></td></tr>
        <tr><th><label for="etg-slot-type">Output Type</label></th><td><select id="etg-slot-type" name="type"><?php foreach(array('text'=>'Text','html'=>'HTML','url'=>'URL','image'=>'Image ID / URL') as $k=>$label):?><option value="<?php echo esc_attr($k); ?>" <?php selected((string)$edit['type'],$k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr>
        <tr><th><label for="etg-slot-template">Template</label></th><td><textarea id="etg-slot-template" name="template" class="large-text code" rows="5"><?php echo esc_textarea((string)$edit['template']); ?></textarea><p class="description">Compose any discovered tokens, for example <code>Explore {{term:location:name}} — {{result_count}} tours</code>.</p></td></tr>
        <tr><th><label for="etg-slot-fallback">Fallback</label></th><td><textarea id="etg-slot-fallback" name="fallback" class="large-text" rows="2"><?php echo esc_textarea((string)$edit['fallback']); ?></textarea></td></tr>
        <tr><th>Wrapper text</th><td><input name="prefix" placeholder="Prefix" value="<?php echo esc_attr((string)$edit['prefix']); ?>"> <input name="suffix" placeholder="Suffix" value="<?php echo esc_attr((string)$edit['suffix']); ?>"></td></tr>
        <tr><th><label for="etg-slot-max">Max Length</label></th><td><input id="etg-slot-max" type="number" min="0" max="20000" name="max_length" value="<?php echo esc_attr((string)$edit['max_length']); ?>"><p class="description">0 means no additional slot-level truncation.</p></td></tr>
        </table><?php submit_button('Save Dynamic Content Slot'); ?></form>

        <h2>Runtime Inventory Token Catalog</h2><p>Choose <strong>Use</strong> to prefill a slot from an observed/runtime-derived token. Token discovery is non-authorizing evidence.</p>
        <table class="widefat striped"><thead><tr><th>Token</th><th>Label</th><th>Type</th><th>Source</th><th></th></tr></thead><tbody>
        <?php foreach((array)($catalog['tokens']??array()) as $token=>$meta):?><tr><td><code><?php echo esc_html('{{'.$token.'}}'); ?></code></td><td><?php echo esc_html((string)$meta['label']); ?></td><td><?php echo esc_html((string)$meta['type']); ?></td><td><?php echo esc_html((string)$meta['source']); ?></td><td><a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page'=>self::SLUG,'token'=>$token),admin_url('options-general.php'))); ?>">Use</a></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php
    }
}
