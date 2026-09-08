<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\Presentation\MediaDiscoveryRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\MediaInspector;

final class MediaLabPage {
    const SLUG='etg-dfsb-media-lab';

    private $registry;private $inspector;private $gallery;private $previewContextProvider;
    public function __construct(MediaDiscoveryRegistry$registry,MediaInspector$inspector,GalleryComposer$gallery,callable$previewContextProvider=null){$this->registry=$registry;$this->inspector=$inspector;$this->gallery=$gallery;$this->previewContextProvider=$previewContextProvider;}

    public function register():void{
        add_action('admin_menu',array($this,'menu'));
        add_action('admin_post_etg_dfsb_save_media_discovery',array($this,'save'));
        add_action('admin_post_etg_dfsb_add_media_key',array($this,'addKey'));
    }
    public function menu():void{add_options_page('ETG Media Lab','ETG Media Lab','manage_options',self::SLUG,array($this,'render'));}

    public function save():void{
        if(!current_user_can('manage_options')){wp_die('Forbidden',403);}check_admin_referer('etg_dfsb_save_media_discovery');
        $settings=$this->registry->all();$taxonomy=sanitize_key((string)($_POST['taxonomy']??''));
        $settings['image']=$this->postList('global_image_keys');$settings['gallery']=$this->postList('global_gallery_keys');
        if($taxonomy){$settings['taxonomies'][$taxonomy]=array('image'=>$this->postList('taxonomy_image_keys'),'gallery'=>$this->postList('taxonomy_gallery_keys'));}
        $result=$this->registry->save($settings);
        wp_safe_redirect(AdminUi::pageUrl(self::SLUG,array('tab'=>'discovery','taxonomy'=>$taxonomy,'saved'=>!empty($result['saved'])?'1':'0')));exit;
    }

    public function addKey():void{
        if(!current_user_can('manage_options')){wp_die('Forbidden',403);}check_admin_referer('etg_dfsb_add_media_key');
        $taxonomy=sanitize_key((string)($_POST['taxonomy']??''));$kind=sanitize_key((string)($_POST['kind']??''));$key=isset($_POST['media_key'])?wp_unslash((string)$_POST['media_key']):'';
        if($taxonomy&&in_array($kind,array('image','gallery'),true)&&''!==trim($key)){$settings=$this->registry->all();$current=(array)($settings['taxonomies'][$taxonomy][$kind]??array());$current[]=$key;if(!isset($settings['taxonomies'][$taxonomy])){$settings['taxonomies'][$taxonomy]=array('image'=>array(),'gallery'=>array());}$settings['taxonomies'][$taxonomy][$kind]=$current;$this->registry->save($settings);}
        wp_safe_redirect(AdminUi::pageUrl(self::SLUG,array('tab'=>'discovery','taxonomy'=>$taxonomy,'scan'=>'1','added'=>$kind)));exit;
    }

    public function render():void{
        if(!current_user_can('manage_options')){return;}
        $tabs=array('discovery'=>'Discovery & Mapping','collections'=>'Collection Modes','preview'=>'Preview & Trace','recipes'=>'Elementor Recipes');$tab=AdminUi::activeTab($tabs,'discovery');
        $taxonomies=$this->inspector->taxonomyOptions();$taxonomy=isset($_GET['taxonomy'])?sanitize_key((string)wp_unslash($_GET['taxonomy'])):'';if(!$taxonomy&&$taxonomies){$taxonomy=(string)array_key_first($taxonomies);}
        $settings=$this->registry->all();
        echo '<div class="wrap etg-dfsb-admin etg-media-lab">';
        AdminUi::renderHeader(self::SLUG,'ETG Media Lab','Discover taxonomy media fields, define case-sensitive image/gallery keys, combine active Terms and preview slideshow-ready collections.',array(array('label'=>'Dynamic Content','url'=>AdminUi::pageUrl(DynamicContentPage::SLUG)),array('label'=>'JetEngine Inspector','url'=>AdminUi::pageUrl(JetEngineInspectorPage::SLUG))));
        if(isset($_GET['saved'])){echo '<div class="notice '.('1'===(string)$_GET['saved']?'notice-success':'notice-error').' is-dismissible"><p>'.('1'===(string)$_GET['saved']?'Media discovery mapping saved.':'Media mapping was not saved.').'</p></div>';}
        if(isset($_GET['added'])){echo '<div class="notice notice-success is-dismissible"><p>Detected Meta key added to '.esc_html((string)$_GET['added']).' discovery.</p></div>';}
        $this->status($settings,$taxonomy);
        AdminUi::renderTabs(self::SLUG,$tabs,$tab,array('taxonomy'=>$taxonomy));
        if('collections'===$tab){$this->renderCollections($taxonomy);}elseif('preview'===$tab){$this->renderPreview($taxonomy);}elseif('recipes'===$tab){$this->renderRecipes();}else{$this->renderDiscovery($taxonomy,$taxonomies,$settings);}
        echo '</div>';
    }

    private function status(array$settings,string$taxonomy):void{
        $keys=$this->registry->keysForTaxonomy($taxonomy);
        echo '<div class="etg-status-grid"><div class="etg-status-card"><span class="etg-status-label">Authority</span><span class="etg-status-value"><span class="etg-badge etg-badge--readonly">PRESENTATION ONLY</span></span><span class="etg-status-help">Media mapping cannot enable SEO or profiles.</span></div>';
        echo '<div class="etg-status-card"><span class="etg-status-label">Image Keys</span><span class="etg-status-value">'.esc_html((string)count($keys['image'])).'</span><span class="etg-status-help">Global + taxonomy-specific scan order.</span></div>';
        echo '<div class="etg-status-card"><span class="etg-status-label">Gallery Keys</span><span class="etg-status-value">'.esc_html((string)count($keys['gallery'])).'</span><span class="etg-status-help">All matched galleries are deduplicated.</span></div>';
        echo '<div class="etg-status-card"><span class="etg-status-label">Collection Modes</span><span class="etg-status-value">7</span><span class="etg-status-help">Single Image, Gallery and Slideshow consumers.</span></div></div>';
    }

    private function renderDiscovery(string$taxonomy,array$taxonomies,array$settings):void{
        $specific=(array)($settings['taxonomies'][$taxonomy]??array('image'=>array(),'gallery'=>array()));$scan=!empty($_GET['scan'])?$this->inspector->scanTaxonomy($taxonomy,15):array();
        echo '<section class="etg-panel"><div class="etg-panel__head"><div><h2>Media Discovery Mapping</h2><p class="description">Keys are exact/case-sensitive. Taxonomy-specific keys run before global defaults; profile field_map remains an additional compatibility layer.</p></div></div><div class="etg-panel__body">';
        echo '<form method="get" class="etg-actions"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'"><input type="hidden" name="tab" value="discovery"><select name="taxonomy">';foreach($taxonomies as$slug=>$label){echo '<option value="'.esc_attr($slug).'" '.selected($taxonomy,$slug,false).'>'.esc_html($label).'</option>';}echo '</select><input type="hidden" name="scan" value="1"><button class="button">Scan Taxonomy Media</button></form><hr/>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="etg_dfsb_save_media_discovery"><input type="hidden" name="taxonomy" value="'.esc_attr($taxonomy).'">';wp_nonce_field('etg_dfsb_save_media_discovery');
        echo '<div class="etg-overview-grid"><article class="etg-overview-card"><h3>Global Image Keys</h3><textarea class="large-text code" rows="7" name="global_image_keys">'.esc_textarea(implode("\n",(array)$settings['image'])).'</textarea><p>First matching field with media wins for primary image discovery.</p></article>';
        echo '<article class="etg-overview-card"><h3>Global Gallery Keys</h3><textarea class="large-text code" rows="7" name="global_gallery_keys">'.esc_textarea(implode("\n",(array)$settings['gallery'])).'</textarea><p>All matching fields are combined, deduplicated and bounded.</p></article>';
        echo '<article class="etg-overview-card"><h3>'.esc_html($taxonomy).' Image Keys</h3><textarea id="etg-taxonomy-image-keys" class="large-text code" rows="7" name="taxonomy_image_keys">'.esc_textarea(implode("\n",(array)($specific['image']??array()))).'</textarea><p>Runs before global Image Keys for this taxonomy only.</p></article>';
        echo '<article class="etg-overview-card"><h3>'.esc_html($taxonomy).' Gallery Keys</h3><textarea id="etg-taxonomy-gallery-keys" class="large-text code" rows="7" name="taxonomy_gallery_keys">'.esc_textarea(implode("\n",(array)($specific['gallery']??array()))).'</textarea><p>Additional gallery/repeater/media fields for this taxonomy.</p></article></div>';
        submit_button('Save Media Discovery Mapping');echo '</form></div></section>';
        if($scan){$this->renderScan($scan,$taxonomy);}
    }

    private function renderScan(array$scan,string$taxonomy):void{
        $fields=(array)($scan['fields']??array());AdminUi::renderTableSearch('etg-media-field-search','etg-media-field-table','Search detected media Meta key…',count($fields).' media fields');
        echo '<section class="etg-panel"><div class="etg-panel__head"><div><h2>Detected Media Fields</h2><p class="description">Bounded scan of up to 15 Terms. Only Meta values that resolve to WordPress attachment IDs are shown.</p></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped" id="etg-media-field-table"><thead><tr><th>Meta Key</th><th>Detected</th><th>Term Hits</th><th>Sample IDs</th><th>Configured</th><th>Actions</th></tr></thead><tbody>';
        if(!$fields){echo '<tr><td colspan="6">No media-bearing Term Meta detected in this bounded sample.</td></tr>';}else{foreach($fields as$key=>$row){$search=strtolower($key.' '.($row['kind']??'').' '.implode(' ',(array)($row['sample_terms']??array())));echo '<tr data-etg-search="'.esc_attr($search).'"><td><code>'.esc_html($key).'</code><br><small>'.esc_html(implode(', ',(array)($row['sample_terms']??array()))).'</small></td><td>'.esc_html((string)($row['kind']??'')).'</td><td>'.esc_html((string)($row['term_hits']??0)).'</td><td><code>'.esc_html(implode(',',(array)($row['media_ids']??array()))).'</code></td><td>'.esc_html(implode(', ',(array)($row['configured_as']??array()))?:'—').'</td><td>'.$this->addKeyForm($taxonomy,$key,'image','Use as Image').' '.$this->addKeyForm($taxonomy,$key,'gallery','Use as Gallery').'</td></tr>';}}
        echo '</tbody></table></div></div></section>';
    }

    private function renderCollections(string$taxonomy):void{
        $modes=$this->modes();echo '<section class="etg-panel"><div class="etg-panel__head"><div><h2>Collection Strategies</h2><p class="description">A collection strategy decides which existing attachments are returned. Elementor decides how they are displayed.</p></div></div><div class="etg-panel__body"><table class="widefat striped"><thead><tr><th>Mode</th><th>Behavior</th><th>Best For</th></tr></thead><tbody>';
        foreach($modes as$key=>$row){echo '<tr><td><code>'.esc_html($key).'</code></td><td>'.esc_html($row[0]).'</td><td>'.esc_html($row[1]).'</td></tr>';}
        echo '</tbody></table><p><strong>Role-only modes:</strong> Elementor also exposes each active profile role (for example <code>location</code>, <code>tour_type</code>, <code>style</code>) as a collection mode.</p></div></section>';
        echo '<div class="etg-safety-strip"><span class="dashicons dashicons-images-alt2"></span><p><strong>No persistent gallery is created.</strong> ETG resolves attachment IDs at request time, removes duplicates and returns Elementor-native image/gallery arrays.</p></div>';
    }

    private function renderPreview(string$taxonomy):void{
        $url=isset($_GET['preview_url'])?trim((string)wp_unslash($_GET['preview_url'])):'';$mode=isset($_GET['mode'])?sanitize_key((string)wp_unslash($_GET['mode'])):'balanced';if(!isset($this->modes()[$mode])){$mode='balanced';}
        echo '<section class="etg-panel"><div class="etg-panel__head"><div><h2>Preview Filter URL</h2><p class="description">Same-origin editor evidence only. This does not enable SEO or mutate a profile.</p></div></div><div class="etg-panel__body"><form method="get"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'"><input type="hidden" name="tab" value="preview"><input type="hidden" name="taxonomy" value="'.esc_attr($taxonomy).'"><input class="large-text code" name="preview_url" value="'.esc_attr($url).'" placeholder="https://example.com/tours-and-activities/jsf/..."><p><select name="mode">';foreach($this->modes()as$key=>$row){echo '<option value="'.esc_attr($key).'" '.selected($mode,$key,false).'>'.esc_html($key.' — '.$row[0]).'</option>';}echo '</select> <button class="button button-primary">Resolve Media</button></p></form></div></section>';
        if(''===$url||!$this->previewContextProvider){return;}$context=call_user_func($this->previewContextProvider,$url);if(!is_array($context)||empty($context['terms'])){echo '<div class="notice notice-warning inline"><p>No renderable ETG filter context resolved from this Preview URL.</p></div>';return;}
        echo '<div class="etg-status-grid">';foreach($this->modes()as$key=>$row){$ids=$this->gallery->ids($context,$key);echo '<div class="etg-status-card"><span class="etg-status-label">'.esc_html($key).'</span><span class="etg-status-value">'.esc_html((string)count($ids)).'</span><span class="etg-status-help">attachments</span></div>';}echo '</div>';
        $ids=array_slice($this->gallery->ids($context,$mode),0,30);$trace=$this->gallery->trace($context,$mode,30);echo '<section class="etg-panel"><div class="etg-panel__head"><div><h2>'.esc_html($mode).' Preview</h2><p class="description">'.esc_html((string)count($ids)).' unique attachments after normalization.</p></div></div><div class="etg-panel__body"><div class="etg-media-preview-grid">';foreach($ids as$id){$src=function_exists('wp_get_attachment_image_url')?wp_get_attachment_image_url($id,'thumbnail'):'';echo '<figure class="etg-media-preview">'.($src?'<img src="'.esc_url($src).'" alt="">':'').'<figcaption>#'.esc_html((string)$id).'</figcaption></figure>';}if(!$ids){echo '<p>No media resolved for this mode.</p>';}echo '</div><h3>Resolution Trace</h3><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>ID</th><th>Term / Source</th></tr></thead><tbody>';foreach((array)($trace['items']??array())as$item){$sources=array();foreach((array)($item['sources']??array())as$source){$sources[]=($source['role']??'').' → '.($source['kind']??'').' → '.($source['key']??'');}echo '<tr><td><code>#'.esc_html((string)($item['id']??0)).'</code></td><td>'.esc_html($sources?implode(' | ',$sources):'primary/fallback media').'</td></tr>';}echo '</tbody></table></div></div></section>';
    }

    private function renderRecipes():void{
        echo '<div class="etg-guide-recipe-grid"><article class="etg-guide-recipe"><h3>Single Hero Image</h3><ol><li>Elementor Image/Background → Dynamic Tags.</li><li>Select <code>ETG Filter Image</code>.</li><li>Choose Priority, Galleries Only, Balanced or a role.</li><li>Optional Elementor fallback image remains last-resort.</li></ol></article>';
        echo '<article class="etg-guide-recipe"><h3>Gallery / Carousel</h3><ol><li>Use an Elementor control that accepts Gallery dynamic tags.</li><li>Select <code>ETG Filter Gallery</code>.</li><li>Use <code>all_terms</code>, <code>galleries_only</code> or <code>balanced</code>.</li><li>Set the Elementor carousel layout separately.</li></ol></article>';
        echo '<article class="etg-guide-recipe"><h3>Background Slideshow</h3><ol><li>Open the Slideshow Images/Gallery control.</li><li>Select <code>ETG Filter Slideshow</code>.</li><li><code>balanced</code> is the recommended default.</li><li>Autoplay, transition, Ken Burns and timing remain Elementor settings.</li></ol></article>';
        echo '<article class="etg-guide-recipe"><h3>Live JetSmartFilters Slideshow Payload</h3><ol><li>Create a Gallery Content Slot.</li><li>Set its <code>Media Collection Mode</code> to Balanced/All Terms/etc.</li><li>Use ETG Content Slot Gallery or <code>[etg_dynamic_gallery]</code>.</li><li>Slot media uses the existing bounded AJAX presentation transport.</li></ol></article></div>';
    }

    private function addKeyForm(string$taxonomy,string$key,string$kind,string$label):string{
        $action=esc_url(admin_url('admin-post.php'));$nonce=wp_create_nonce('etg_dfsb_add_media_key');return '<form method="post" action="'.$action.'" style="display:inline"><input type="hidden" name="action" value="etg_dfsb_add_media_key"><input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'"><input type="hidden" name="taxonomy" value="'.esc_attr($taxonomy).'"><input type="hidden" name="media_key" value="'.esc_attr($key).'"><input type="hidden" name="kind" value="'.esc_attr($kind).'"><button class="button button-small" type="submit">'.esc_html($label).'</button></form>';
    }

    private function modes():array{return array(
        'combined'=>array('Combine media from every active Term using gallery priority order.','General Gallery'),
        'all_terms'=>array('Explicit alias for all active Terms; deduplicated.','Slideshow / Gallery'),
        'priority'=>array('Use the first gallery-priority Term that contains media.','Hero / focused gallery'),
        'galleries_only'=>array('Combine only configured gallery fields; primary image fallbacks are excluded.','Pure galleries / slides'),
        'primary_images'=>array('Take one primary image from each active Term.','Compact slideshow / category montage'),
        'balanced'=>array('Round-robin one item per Term bucket so one large gallery cannot dominate.','Recommended Slideshow'),
        'role_priority'=>array('Combine all media using normal profile role priority rather than gallery priority.','Editorial priority'),
    );}

    private function postList(string$key):array{$value=isset($_POST[$key])?wp_unslash((string)$_POST[$key]):'';return preg_split('/[\r\n,]+/',$value)?:array();}
}
