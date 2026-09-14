<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

require_once dirname( __DIR__, 2 ) . '/Presentation/MediaAssetValidator.php';

use ETG\DynamicFilterSEOBridge\Presentation\MediaAssetValidator;

final class FilterImageTag extends \Elementor\Core\DynamicTags\Data_Tag {
    use PreviewContextTrait;

    public function get_name(){ return 'etg-filter-image'; }
    public function get_title(){ return 'ETG Filter Image'; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ return array(\Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY); }

    protected function register_controls(){
        $roles = DynamicTagRuntime::roleOptions();
        $modes = array(
            'priority'=>'Profile Gallery Priority',
            'combined'=>'First Image From Combined Media',
            'all_terms'=>'First Image Across All Active Terms',
            'galleries_only'=>'First Image From Gallery Fields Only',
            'primary_images'=>'First Primary Term Image',
            'balanced'=>'First Balanced Media Item',
            'role_priority'=>'First Media By Role Priority',
        );
        foreach ($roles as $role => $label) { $modes[$role] = $label . ' only'; }
        $this->add_control('mode', array(
            'label'=>'Image Collection Mode',
            'type'=>\Elementor\Controls_Manager::SELECT,
            'options'=>$modes,
            'default'=>'priority',
            'description'=>'Returns the first healthy image from this collection.',
        ));
        $mediaControl = defined('Elementor\\Controls_Manager::MEDIA') ? \Elementor\Controls_Manager::MEDIA : 'media';
        $this->add_control('fallback_image', array(
            'label'=>'Fallback Image',
            'type'=>$mediaControl,
            'default'=>array('id'=>0,'url'=>''),
            'dynamic'=>array('active'=>true),
            'description'=>'Used only when ETG returns no image.',
        ));
        $this->etgRegisterPreviewControl();
    }

    public function get_value(array $options = array()) {
        $resolver = DynamicTagRuntime::resolver();
        $primary = $resolver ? $this->normalizeImage($resolver->image((string) $this->get_settings('mode'), $this->etgPreviewContext())) : array('id'=>0,'url'=>'');
        if ($this->hasImage($primary)) { return $primary; }
        return $this->fallbackImage();
    }

    private function fallbackImage(): array {
        if (!DynamicTagRuntime::enterImageFallback()) { return array('id'=>0,'url'=>''); }
        try {
            $value = method_exists($this, 'get_settings_for_display') ? $this->get_settings_for_display('fallback_image') : $this->get_settings('fallback_image');
            return $this->normalizeImage($value);
        } catch (\Throwable $error) {
            return array('id'=>0,'url'=>'');
        } finally {
            DynamicTagRuntime::leaveImageFallback();
        }
    }

    private function normalizeImage($value): array {
        $id = 0;
        $url = '';
        if (is_array($value)) {
            $id = isset($value['id']) && is_numeric($value['id']) ? max(0, (int) $value['id']) : 0;
            $url = isset($value['url']) && is_scalar($value['url']) ? trim((string) $value['url']) : '';
        } elseif (is_numeric($value)) {
            $id = max(0, (int) $value);
        } elseif (is_string($value)) {
            $url = trim($value);
        }
        if ($id > 0) {
            $health = MediaAssetValidator::inspect($id);
            if (empty($health['valid'])) { return array('id'=>0,'url'=>''); }
            $url = isset($health['url']) && is_scalar($health['url']) ? trim((string) $health['url']) : '';
            if ('' === $url && function_exists('wp_get_attachment_image_url')) {
                $resolved = wp_get_attachment_image_url($id, 'full');
                $url = is_string($resolved) ? $resolved : '';
            }
        }
        if ('' !== $url && function_exists('esc_url_raw')) { $url = esc_url_raw($url); }
        return array('id'=>$id,'url'=>$url);
    }

    private function hasImage(array $image): bool {
        return !empty($image['id']) || !empty($image['url']);
    }
}
