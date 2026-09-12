<?php
declare(strict_types=1);

function etg_tag_preview_expect($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}

$root=dirname(__DIR__);
require_once $root.'/includes/Elementor/DynamicTags/DynamicTagRuntime.php';
require_once $root.'/includes/Elementor/DynamicTags/PreviewContextTrait.php';

use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\DynamicTagRuntime;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\PreviewContextTrait;

final class EtgPreviewProbe {
    use PreviewContextTrait;
    public function editorPreview():bool{return $this->etgIsEditorPreview();}
    protected function add_control($id,$args):void{}
    protected function get_settings($key){return '';}
}

$probe=new EtgPreviewProbe();
while(DynamicTagRuntime::isEditorRenderPass()){DynamicTagRuntime::endEditorRenderPass();}
etg_tag_preview_expect(false===$probe->editorPreview(),'without Elementor mode or render_tags bracket synthetic preview stays disabled');
DynamicTagRuntime::beginEditorRenderPass();
etg_tag_preview_expect(true===DynamicTagRuntime::isEditorRenderPass(),'render_tags before_render opens editor render pass');
etg_tag_preview_expect(true===$probe->editorPreview(),'render_tags pass authorizes editor-only synthetic preview evaluation');
DynamicTagRuntime::beginEditorRenderPass();
DynamicTagRuntime::endEditorRenderPass();
etg_tag_preview_expect(true===DynamicTagRuntime::isEditorRenderPass(),'nested render pass remains active until balanced');
DynamicTagRuntime::endEditorRenderPass();
etg_tag_preview_expect(false===DynamicTagRuntime::isEditorRenderPass(),'render_tags after_render closes editor render pass');
etg_tag_preview_expect(false===$probe->editorPreview(),'render pass cannot leak into normal live rendering');
DynamicTagRuntime::endEditorRenderPass();
etg_tag_preview_expect(false===DynamicTagRuntime::isEditorRenderPass(),'underflow is clamped fail-closed');

echo "Alpha13 Elementor dynamic-tag editor preview pass tests passed.\n";
