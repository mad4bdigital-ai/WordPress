<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function etg_archive_expect($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}

$root=dirname(__DIR__);
require_once $root.'/includes/Elementor/ArchiveHeroPreset.php';

use ETG\DynamicFilterSEOBridge\Elementor\ArchiveHeroPreset;

final class EtgArchiveHeroElementFixture {
    public $raw=array();
    public $display=array();
    public $set=array();
    public $attrs=array();
    public $displayReads=0;
    public $parsed_active_settings=array('etg_dfsb_background_mode'=>'off','etg_dfsb_background_suitability'=>'any');

    public function __construct(array $raw,array $display=array()){
        $this->raw=$raw;
        $this->display=$display+$raw;
    }
    public function get_data($key=null){return 'settings'===$key?$this->raw:array('settings'=>$this->raw);}
    public function get_raw_data($withHtml=false){return array('settings'=>$this->raw);}
    public function get_settings(){return $this->display;}
    public function get_settings_for_display(){$this->displayReads++;return $this->display;}
    public function set_settings($key,$value){$this->set[$key]=$value;}
    public function add_render_attribute($key,$value){$this->attrs[$key]=$value;}
}

$preset=new ArchiveHeroPreset();
$marked=new EtgArchiveHeroElementFixture(
    array('_css_classes'=>'hero etg-dfsb-archive-hero archive'),
    array('etg_dfsb_background_mode'=>'off','etg_dfsb_background_suitability'=>'any')
);
$preset->beforeRender($marked);
etg_archive_expect(($marked->set['etg_dfsb_background_mode']??'')==='slideshow','marker activates slideshow when raw mode is absent even if display controls would expose the default off value');
etg_archive_expect($marked->displayReads===0,'preset must not prewarm Elementor display settings before in-memory normalization');
etg_archive_expect(($marked->set['etg_dfsb_background_collection_mode']??'')==='balanced','marker uses balanced collection');
etg_archive_expect(($marked->set['etg_dfsb_background_suitability']??'')==='wide','marker slideshow defaults to Wide Hero suitability');
etg_archive_expect(($marked->set['etg_dfsb_background_fit']??'')==='cover','unsaved fit is normalized before ContainerDynamicBackground');
etg_archive_expect(($marked->set['etg_dfsb_background_position']??'')==='center center','unsaved position is normalized before ContainerDynamicBackground');
etg_archive_expect(($marked->attrs['_wrapper']['data-etg-dfsb-background-origin']??'')==='archive_hero_marker','marker origin is observable without authorizing persistence');
etg_archive_expect(($marked->attrs['_wrapper']['data-etg-dfsb-background-scope']??'')==='container','preset scope remains container-only');
$preset->invalidateDisplayCache($marked);
etg_archive_expect($marked->parsed_active_settings===null,'marker invalidates Elementor parsed display cache before ContainerDynamicBackground');

// Simulate Elementor's parsed_active_settings cache already being warm. The pure
// render normalizer must still override stale display defaults from raw authority.
$cached=ArchiveHeroPreset::normalizeForRender($marked,array(
    'etg_dfsb_background_mode'=>'off',
    'etg_dfsb_background_suitability'=>'any',
    'etg_dfsb_background_max_slides'=>30,
));
etg_archive_expect(($cached['etg_dfsb_background_mode']??'')==='slideshow','render normalization survives a pre-warmed display cache');
etg_archive_expect(($cached['etg_dfsb_background_suitability']??'')==='wide','render normalization forces Wide Hero when suitability is not persisted');
etg_archive_expect(($cached['etg_dfsb_background_max_slides']??0)===8,'marker defaults override cached control defaults when raw settings are absent');
etg_archive_expect(($cached['__etg_dfsb_background_origin']??'')==='archive_hero_marker','render normalization exports marker origin');

$explicitOff=new EtgArchiveHeroElementFixture(array('_css_classes'=>'etg-dfsb-archive-hero','etg_dfsb_background_mode'=>'off'));
$preset->beforeRender($explicitOff);
etg_archive_expect(!isset($explicitOff->set['etg_dfsb_background_mode']),'explicitly saved Elementor Default remains a hard off override');
etg_archive_expect(empty($explicitOff->attrs),'explicit off does not emit ETG background ownership');
$explicitOffRender=ArchiveHeroPreset::normalizeForRender($explicitOff,array('etg_dfsb_background_mode'=>'slideshow'));
etg_archive_expect(($explicitOffRender['etg_dfsb_background_mode']??'')==='off','raw explicit off overrides stale rendered slideshow state');

$killSwitch=new EtgArchiveHeroElementFixture(array('_css_classes'=>'archive etg-dfsb-background-off','etg_dfsb_background_mode'=>'slideshow'));
$preset->beforeRender($killSwitch);
etg_archive_expect(($killSwitch->set['etg_dfsb_background_mode']??'')==='off','kill-switch class overrides a stale saved slideshow mode in memory');
etg_archive_expect(($killSwitch->attrs['_wrapper']['data-etg-dfsb-background-origin']??'')==='archive_background_off','kill-switch origin is observable');
$preset->invalidateDisplayCache($killSwitch);
etg_archive_expect($killSwitch->parsed_active_settings===null,'kill-switch invalidates Elementor parsed display cache before downstream render');
$killRender=ArchiveHeroPreset::normalizeForRender($killSwitch,array('etg_dfsb_background_mode'=>'slideshow'));
etg_archive_expect(($killRender['etg_dfsb_background_mode']??'')==='off','kill-switch also overrides pre-warmed rendered slideshow state');

$explicitImage=new EtgArchiveHeroElementFixture(array('etg_dfsb_background_mode'=>'image'));
$preset->beforeRender($explicitImage);
etg_archive_expect(($explicitImage->set['etg_dfsb_background_suitability']??'')==='any','explicit dynamic image defaults to any healthy image rather than slideshow Wide Hero policy');
etg_archive_expect(($explicitImage->set['etg_dfsb_background_fit']??'')==='cover','explicit dynamic image also receives safe unsaved fit normalization');

$explicitSlideshow=new EtgArchiveHeroElementFixture(array('etg_dfsb_background_mode'=>'slideshow','etg_dfsb_background_suitability'=>'custom','etg_dfsb_background_fit'=>'contain'));
$preset->beforeRender($explicitSlideshow);
etg_archive_expect(!isset($explicitSlideshow->set['etg_dfsb_background_suitability']),'explicit suitability remains authoritative');
etg_archive_expect(!isset($explicitSlideshow->set['etg_dfsb_background_fit']),'explicit fit remains authoritative');
$explicitRender=ArchiveHeroPreset::normalizeForRender($explicitSlideshow,array('etg_dfsb_background_suitability'=>'any','etg_dfsb_background_fit'=>'cover'));
etg_archive_expect(($explicitRender['etg_dfsb_background_suitability']??'')==='custom','raw explicit suitability overrides a stale parsed display value');
etg_archive_expect(($explicitRender['etg_dfsb_background_fit']??'')==='contain','raw explicit fit overrides a stale parsed display value');

echo "Alpha13 archive hero raw authority, display-cache invalidation, render normalization, kill-switch and safe preset smoke tests passed.\n";
