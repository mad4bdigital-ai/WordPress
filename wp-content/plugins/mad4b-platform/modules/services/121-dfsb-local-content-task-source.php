<?php
/**
 * Local Lab: action-oriented SEO editorial gaps for native MAD4B readiness.
 * Does not create Fluent Boards tasks or emit content to public pages.
 * Never run as a remote publication or release authority.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;

final class MAD4B_Local_DFSB_Content_Tasks_v1 {
    private const SOURCE='dynamic-filter-seo-bridge';
    private const MAX_BYTES=250000;
    private static function issue(string $code,string $severity,string $label): array {
        return array(
            'id'=>hash('sha256',get_current_blog_id().'|site|0|'.$code),
            'post_type'=>'site','object_id'=>0,'source'=>self::SOURCE,
            'code'=>$code,'severity'=>$severity,'label'=>mb_substr($label,0,235)
        );
    }
    private static function fail(string $reason): WP_Error {
        return new WP_Error('dfsb_editorial_scan_incomplete','Source audit incomplete: '.$reason);
    }
    private static function sameSiteURL(string $url): bool {
        $base=wp_parse_url(home_url('/'));$target=wp_parse_url($url);
        return is_array($base)&&is_array($target)&&
            ($target['scheme']??'')==='https' &&
            ($target['host']??'')===($base['host']??'') &&
            (int)($target['port']??443)===(int)($base['port']??443) &&
            !isset($target['user'])&&!isset($target['pass'])&&!isset($target['fragment']);
    }
    private static function isMetaPresent(string $html,string $name,string $property='name'): bool {
        if(!preg_match_all('/<meta\b[^>]*>/i',$html,$matches))return false;
        foreach($matches[0] as $tag){
            if(!preg_match('/\b'.preg_quote($property,'/').'\s*=\s*(["\'])(.*?)\1/i',$tag,$m)||strcasecmp($m[2],$name)!==0)continue;
            if(preg_match('/\bcontent\s*=\s*(["\'])(.*?)\1/is',$tag,$v) && trim(html_entity_decode($v[2],ENT_QUOTES|ENT_HTML5,'UTF-8'))!=='')return true;
        }
        return false;
    }
    private static function langInHTML(string $html): string {
        if(!preg_match('/<html\b[^>]*\blang\s*=\s*(["\'])(.*?)\1/i',$html,$m))return '';
        return strtolower(explode('-',trim((string)$m[2]))[0]);
    }
    private static function targets(): array {
        $langs=array('en');
        if(has_filter('wpml_active_languages')!==false){
            $wpml=apply_filters('wpml_active_languages',null,array('skip_missing'=>0));
            if(is_array($wpml))$langs=array_slice(array_keys($wpml),0,8);
        }
        $list=[];
        $tour=post_type_exists('tours-and-activities')?get_post_type_archive_link('tours-and-activities'):false;
        $blogId=(int)get_option('page_for_posts');
        $blog=$blogId?get_permalink($blogId):false;
        foreach($langs as$lang){
            $lang=sanitize_key($lang);
            if(!preg_match('/^[a-z]{2}$/',$lang))continue;
            foreach(array('tours'=>$tour,'blog'=>$blog) as$surface=>$url){
                if(!$url)continue;
                $translated=has_filter('wpml_permalink')!==false?
                    apply_filters('wpml_permalink',(string)$url,$lang):(string)$url;
                $list[]=array('lang'=>$lang,'surface'=>$surface,'url'=>(string)$translated);
            }
        }
        // One confirmed native category example; never crawl all terms or filtered routes.
        $term=get_term_by('slug','adventure','category');
        if($term instanceof WP_Term){$url=get_term_link($term);
            if(!is_wp_error($url))$list[]=array('lang'=>'en','surface'=>'category_adventure','url'=>(string)$url);
        }
        return array_slice($list,0,17);
    }
    public static function append($external,$policy=array()) {
        if(!is_array($external))return $external;
        // Only the SCP's explicit discovery may call this. No writes on boot.
        if(!class_exists('MAD4B_SCP_Content_Readiness_Alerts'))return $external;
        foreach(self::targets() as$t){
            $code=$t['surface'].'_'.$t['lang'];$url=$t['url'];
            if(!self::sameSiteURL($url))return self::fail('invalid target for '.$code);
            $response=wp_safe_remote_get($url,array('timeout'=>8,'redirection'=>2,
                'limit_response_size'=>self::MAX_BYTES,
                'headers'=>array('User-Agent'=>'MAD4B-Local-Content-Readiness/1.0')));
            if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)
                return self::fail('inaccessible source '.$code);
            $html=(string)wp_remote_retrieve_body($response);
            if(strlen($html)<700||!str_contains($html,'<html'))return self::fail('unexpected page '.$code);
            $actualLang=self::langInHTML($html);
            if(!$actualLang)return self::fail('missing language for '.$code);
            if($actualLang!==$t['lang']){
                // Routing/template mismatch is a technical task, never a missing translation claim.
                $external[]=self::issue('dfsb_tech_language_route_fallback_'.$code,'high',
                    'Developer: fix ['.$t['lang'].'] '.$t['surface'].' routing (shows '.$actualLang.'): '.$url);
                continue;
            }
            if(!self::isMetaPresent($html,'description')){
                $external[]=self::issue('dfsb_content_description_'.$code,'high',
                    'Content: add Rank Math Meta Description ['.$t['lang'].'] '.$t['surface'].': '.$url);
            }
            if(!self::isMetaPresent($html,'og:image','property')){
                $external[]=self::issue('dfsb_content_social_image_'.$code,'high',
                    'Content: set source-approved social image ['.$t['lang'].'] '.$t['surface'].': '.$url);
            }
        }
        return $external;
    }
}
add_filter('mad4b/content_readiness/external_issues',array('MAD4B_Local_DFSB_Content_Tasks_v1','append'),25,2);
