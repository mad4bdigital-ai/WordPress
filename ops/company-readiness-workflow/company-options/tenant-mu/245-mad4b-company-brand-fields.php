<?php
/**
 * Plugin Name: Company Brand — Native JetEngine Options
 * Description: Editable identity, typography and component tokens on Look & Feel.
 */
defined('ABSPATH') || exit;


/** Native JetEngine field definitions; no settings are written on load. */
function company_brand_option_fields_v1(): array {
    $rows=[];
    $tab=static function(string $name,string $title)use(&$rows):void {
        $rows[]=['name'=>'company_brand_tab_'.$name,'title'=>$title,'object_type'=>'tab','tab_layout'=>'horizontal'];
    };
    $field=static function(string $key,string $title,string $type,array $extra=[])use(&$rows):void {
        $rows[]=array_merge(['name'=>'company_brand_'.$key,'title'=>$title,'type'=>$type,'object_type'=>'field','width'=>'100%'],$extra);
    };
    $select=static function(string $key,string $title,array $choices,string $default,string $description='')use($field):void {
        $options=[];
        foreach($choices as $value=>$label)$options[]=['key'=>$value,'value'=>$label];
        $field($key,$title,'select',['options_source'=>'manual','options'=>$options,'default_val'=>$default,'description'=>$description]);
    };
    // Close any preceding legacy layout without moving or renaming its fields.
    $rows[]=['name'=>'company_brand_layout_start','title'=>'Brand settings','object_type'=>'endpoint'];
    $tab('profile','Brand profile');
    $field('intro','One identity, throughout the experience','html',['html'=>'<p>Use the company profile to supply consistent colours, typography and component styling. Native Elementor controls remain available for intentional local overrides.</p>']);
    $select('profile','Identity profile',['inherit'=>'Keep current identity','custom'=>'Custom company identity'],'inherit','Keep current identity leaves the existing site presentation unchanged. Custom uses only company-entered settings; empty fields inherit local site settings.');
    $field('name','Brand display name','text',['description'=>'Optional display name. Leave blank to inherit the profile name, the current company identity.']);
    $field('revision','Identity revision','text',['description'=>'Optional reference for example company revision 1. Use a new revision when approving an updated identity.']);
    $field('preview_guide','Preview before applying','html',['html'=>'<p>Review desktop, mobile and Arabic layouts before changing the company default. Blank overrides inherit the selected profile.</p>'.(function_exists('company_brand_preview_parameters')?'<p><a class="button button-secondary" target="_blank" rel="noopener" href="'.esc_url(add_query_arg(company_brand_preview_parameters(),home_url('/'))).'">Open Company preview</a></p>':'')]);

    $tab('palette','Colour roles');
    $select('palette','Palette family',['midnight'=>'Midnight · Navy / Gold / Ivory','mineral'=>'Mineral · Teal / Sand','terracotta'=>'Terracotta · Clay / Warm cream','azure'=>'Azure · Blue / Light gold','ivory'=>'Ivory · Cream / Navy','ink'=>'Ink · Charcoal / Silver'],'midnight','Choose one coherent palette. Optional colour overrides below replace individual roles, rather than introducing another independent palette.');
    foreach(['primary'=>'Primary · navigation and strong emphasis','secondary'=>'Secondary · supporting surfaces','accent'=>'Accent · highlights and fine details','text'=>'Text · reading and labels','muted'=>'Muted text · secondary information','surface'=>'Surface · quiet backgrounds','border'=>'Border · subtle separation','focus'=>'Focus · keyboard navigation'] as $role=>$label) {
        $field('color_'.$role,$label,'colorpicker',['alpha_mode'=>false,'description'=>'Leave blank to inherit this role from the identity profile.','width'=>'50%']);
    }

    $tab('logos','Logo variants');
    $media=['value_format'=>'id','restrict_mime_types'=>true,'allowed_mime_types'=>['image/jpeg','image/png','image/webp'],'description'=>'Choose an image from this company’s Media Library. Leave blank to inherit the profile asset. Preserve the original logo artwork and lettering.'];
    foreach(['default'=>'Default logo · light backgrounds','inverse'=>'Inverse logo · dark backgrounds','compact'=>'Compact logo · limited space','mobile'=>'Mobile logo · small header'] as $variant=>$label)$field('logo_'.$variant,$label,'media',$media);
    $field('favicon','Site icon','media',array_merge($media,['description'=>'Choose a square icon, ideally 512 × 512. Leave blank to inherit the profile icon.']));

    $tab('typography','Typography');
    $field('font_heading','Heading font family','text',['description'=>'Optional font-family override. Leave blank for the profile heading face. Keep headings restrained and readable; this does not alter lettering inside the logo.']);
    $field('font_body','Body and interface font family','text',['description'=>'Optional family for copy, prices, labels and controls. Use a face available to the site; a name alone does not upload a font.']);
    $field('font_arabic','Arabic font family','text',['description'=>'Optional Arabic / RTL family. Leave blank to inherit the profile Arabic face.']);
    $field('heading_scale','Heading scale','number',['min_value'=>0.85,'max_value'=>1.2,'step_value'=>0.05,'description'=>'Optional multiplier from 0.85 to 1.20. Leave blank for the profile scale.']);
    $field('body_size','Body size','number',['min_value'=>14,'max_value'=>20,'step_value'=>1,'description'=>'Optional base size in pixels, from 14 to 20. Responsive Elementor overrides remain available.']);

    $tab('components','Components');
    foreach(['button'=>'Buttons · corner radius','card'=>'Cards · corner radius','input'=>'Inputs · corner radius'] as $part=>$label)$field('radius_'.$part,$label,'number',['min_value'=>0,'max_value'=>32,'step_value'=>1,'description'=>'Optional radius in pixels, from 0 to 32. Leave blank for the profile default.','width'=>'33.33%']);
    $select('density','Interface spacing',['balanced'=>'Balanced','comfortable'=>'Comfortable · more breathing room','compact'=>'Compact · information dense'],'comfortable','Spacing preference for components that inherit the company profile.');

    $tab('artwork','Quiet artwork');
    $select('decoration','Decorative treatment',['none'=>'None · plain surfaces','subtle'=>'Subtle · selected editorial areas only'],'subtle','Decorative artwork belongs in introductions, transitions and quiet calls to action. Keep booking forms, pricing comparisons and filters clear.');
    $select('motion','Hero motion',['calm'=>'Calm · use native poster image','native'=>'Keep authored video behaviour'],'calm','Calm uses the original Elementor fallback artwork, reducing distraction and avoiding autoplay in branded hero areas.');
    $field('pattern_media','Custom decorative artwork','media',array_merge($media,['description'=>'Optional prepared PNG, JPEG or WebP artwork. SVG library sources should first be prepared as a safe web image. Leave blank for the selected profile treatment.']));
    $rows[]=['name'=>'company_brand_layout_end','title'=>'End brand settings','object_type'=>'endpoint'];
    return $rows;
}

add_filter('jet-engine/options-pages/raw-fields',static function($fields,$page){
    if (($page->slug??'')!=='look-and-feel' || !is_array($fields)) return $fields;
    $known=[];
    foreach($fields as $row)if(is_array($row)&&isset($row['name']))$known[(string)$row['name']]=true;
    foreach(company_brand_option_fields_v1() as $row) {
        if(isset($known[$row['name']]))continue;
        $fields[]=$row;
        $known[$row['name']]=true;
    }
    return $fields;
},70,2);
