<?php
namespace DynamicFilterSEOBridge\SEO;

use DynamicFilterSEOBridge\Presentation\PortableMediaGallery;

/** Read-only tenant SEO sources. Never owns publication, robots or sitemap. */
final class SiteSeoSources {
    public static function identity(bool $allowExternalSources=true): array {
        // WordPress current site settings are the default company authority.
        // A governed company resolver may supply a name or media attachment ID.
        $name=self::plain((string)get_option('blogname',''),100);
        // The current WordPress site icon is company-owned and takes
        // precedence over pre-migration Rank Math logo references.
        $logoId=absint(get_option('site_icon',0));
        $rank=(array)get_option('rank-math-options-titles',array());
        $registeredLogo=absint($rank['knowledgegraph_logo_id']??0);
        if(($logoId<=0 || !is_file((string)get_attached_file($logoId)))
            && $registeredLogo>0 && is_file((string)get_attached_file($registeredLogo))){
            $logoId=$registeredLogo;
        }
        // Safe Boot admin preview must not invoke arbitrary company-source
        // integrations before their provider contract is certified.
        $identity=array('name'=>$name,'url'=>home_url('/'),'logo_attachment_id'=>$logoId);
        if($allowExternalSources)$identity=apply_filters('dfsb/seo/site_identity',$identity);
        if(!is_array($identity))$identity=array();
        $name=self::plain((string)($identity['name']??$name),100);
        if($name==='')$name=self::plain(get_bloginfo('name'),100);
        $logoId=absint($identity['logo_attachment_id']??$logoId);
        $logo='';
        if($logoId>0 && wp_attachment_is_image($logoId) && is_file((string)get_attached_file($logoId))){
            $logo=(string)PortableMediaGallery::attachmentUrl($logoId);
            if(str_starts_with($logo,'/')){
                $origin=wp_parse_url(home_url('/'));
                if(is_array($origin)&&!empty($origin['host'])&&!empty($origin['scheme'])){
                    $host=$origin['scheme'].'://'.$origin['host'];
                    if(isset($origin['port']))$host.=':'.(int)$origin['port'];
                    $logo=$host.$logo;
                }
            }
            if(!preg_match('~^https?://~i',$logo))$logo='';
        }
        return array('name'=>$name,'url'=>home_url('/'),'logo_url'=>$logo,'logo_id'=>$logoId);
    }

    public static function plain(string $value,int $max=160): string {
        $value=html_entity_decode(wp_strip_all_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $value=preg_replace('/\s+/u',' ',trim($value));
        $value=is_string($value)?trim($value):'';
        return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }

    /** Locale-aware fallback copy. Editorial/Rank Math descriptions outrank it. */
    public static function archiveFallbackDescription(string $label,string $company,string $description='',?string $locale=null): string {
        $locale=$locale??(string)get_locale();
        $language=strtolower(substr(str_replace('-','_',$locale),0,2));
        $templates=array(
            'en'=>'Explore %1$s with %2$s',
            'ar'=>'استكشف %1$s مع %2$s',
            'de'=>'Entdecken Sie %1$s mit %2$s',
            'es'=>'Descubre %1$s con %2$s',
            'it'=>'Scopri %1$s con %2$s',
            'fr'=>'Découvrez %1$s avec %2$s',
        );
        $template=$templates[$language]??$templates['en'];
        $base=sprintf($template,$label,$company);
        if($description!=='')$base.='. '.$description;
        return self::plain($base,165);
    }

    public static function archiveInfo(string $postType,bool $allowExternalSources=true): array {
        $obj=get_post_type_object($postType);
        if(!$obj)return array();
        $title=self::plain((string)($obj->labels->name??$obj->label??''),80);
        $description=self::plain((string)($obj->description??''),240);
        $tagline=self::plain((string)get_option('blogdescription',''),150);
        $identity=self::identity($allowExternalSources);
        // Tenant/CPT-specific JetEngine or Control Plane source can override
        // the description. Rank Math's existing populated description wins.
        $source=$allowExternalSources?apply_filters('dfsb/seo/archive_description_source',$description,$postType,$identity):$description;
        if(is_string($source) && trim($source)!=='')$description=self::plain($source,240);
        if($description==='')$description=$tagline;
        $meta=self::archiveFallbackDescription($title,$identity['name'],$description);
        $metaSource=$allowExternalSources?apply_filters('dfsb/seo/archive_meta_description',$meta,$postType,$identity):$meta;
        if(is_string($metaSource) && trim($metaSource)!=='')$meta=self::plain($metaSource,165);
        $pageTitle=self::plain($title.' | '.$identity['name'],150);
        $titleSource=$allowExternalSources?apply_filters('dfsb/seo/archive_title_source',$pageTitle,$postType,$identity):$pageTitle;
        if(is_string($titleSource) && trim($titleSource)!=='')$pageTitle=self::plain($titleSource,150);
        $image=$identity['logo_url'];
        $imageId=$allowExternalSources?absint(apply_filters('dfsb/seo/archive_image_attachment_id',0,$postType,$identity)):0;
        if($imageId>0 && wp_attachment_is_image($imageId) && is_file((string)get_attached_file($imageId))){
            $candidate=(string)PortableMediaGallery::attachmentUrl($imageId);
            if(str_starts_with($candidate,'/')){
                $parsed=wp_parse_url(home_url('/'));
                if(is_array($parsed)&&!empty($parsed['host'])&&!empty($parsed['scheme'])){
                    $root=$parsed['scheme'].'://'.$parsed['host'];
                    if(isset($parsed['port']))$root.=':'.(int)$parsed['port'];
                    $candidate=$root.$candidate;
                }
            }
            if(preg_match('~^https?://~i',$candidate))$image=$candidate;
        }
        return array('label'=>$title,'title'=>$pageTitle,'description'=>$meta,
            'image_url'=>$image,'image_attachment_id'=>$imageId?:$identity['logo_id'],
            'identity'=>$identity);
    }
}
