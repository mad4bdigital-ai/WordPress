<?php
/**
 * Plugin Name: MAD4B Gallery Resolution Engine
 * Description: Verified local attachment resolution with multiple strategies, deterministic duplicate handling and source preservation.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Gallery_Resolution_Engine_V1 {
    const CONTRACT='mad4b.gallery-resolution.v1';
    const CACHE_GROUP='mad4b_gallery_resolution';
    const EPOCH_OPTION='mad4b_gallery_resolution_epoch_v1';
    private static $epoch_bumped=false;
    private static $observed=[];

    public static function epoch():string {
        $revision=(int)get_option(self::EPOCH_OPTION,1);
        return (string)max(1,$revision);
    }
    public static function invalidate($object_id=0,$meta_key='') {
        if(self::$epoch_bumped)return;
        if($meta_key!=='' && !in_array((string)$meta_key,['_wp_attached_file','_wp_attachment_metadata'],true))return;
        if($object_id && get_post_type((int)$object_id)!=='attachment')return;
        self::$epoch_bumped=true;
        update_option(self::EPOCH_OPTION,max(1,(int)get_option(self::EPOCH_OPTION,1))+1,false);
    }

    public static function parse($value):array {
        if(is_numeric($value)&&ctype_digit((string)$value))
            return ['kind'=>'id','id'=>(int)$value];
        if(!is_string($value)||strlen($value)>1200)return ['kind'=>'invalid'];
        $url=trim($value);
        $parts=wp_parse_url($url);
        if(!is_array($parts)||empty($parts['host'])
           ||!in_array(strtolower((string)($parts['scheme']??'')),['https','http'],true)
           ||!empty($parts['user'])||!empty($parts['pass']))
           return ['kind'=>'invalid'];
        $host=strtolower(rtrim((string)$parts['host'],'.'));
        $path=rawurldecode((string)($parts['path']??''));
        $prefix='/wp-content/uploads/';
        $pos=strpos($path,$prefix);
        if($pos===false)return ['kind'=>'external_non_upload','host'=>$host];
        $rel=substr($path,$pos+strlen($prefix));
        if($rel==='' || strlen($rel)>700 || str_starts_with($rel,'/')
           || str_contains($rel,'..') || str_contains($rel,'\\')
           || preg_match('/[\x00-\x1f]/',$rel))
            return ['kind'=>'invalid'];
        return ['kind'=>'path','relative_path'=>$rel,'host'=>$host];
    }

    public static function inspect($value,$post_id):array {
        $id=(int)$post_id;
        $out=[
          'contract'=>self::CONTRACT,'post_id'=>$id,'valid_source'=>false,
          'total_entries'=>0,'verified_local'=>0,'unresolved'=>0,
          'equivalent_attachment_records'=>0,'foreign_origin_count'=>0,
          'strategies'=>[],'render_value'=>$value,'content_changed'=>false,
          'media_meta_updated'=>false,'editorial_relevance_verified'=>false,
          'provenance_verified'=>false,'requires_media_review'=>false
        ];
        if(!is_string($value)||strlen($value)>180000
           ||$id<=0||get_post_type($id)!=='tours-and-activities')return $out;
        $entries=array_values(array_filter(array_map('trim',explode(',',$value)),'strlen'));
        $count=count($entries);
        if(!$count||$count>500)return $out;
        $out['valid_source']=true;$out['total_entries']=$count;

        $key='r-'.hash('sha256',
            (string)get_current_blog_id().'|'.$id.'|'.self::epoch().'|'.$value);
        $cached=wp_cache_get($key,self::CACHE_GROUP);
        if(is_array($cached)&&($cached['contract']??'')===self::CONTRACT)
            return $cached;
        $parsed=array_map([self::class,'parse'],$entries);
        $paths=[];
        $site_host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        $upload_host=strtolower((string)wp_parse_url(wp_upload_dir(null,false)['baseurl'],PHP_URL_HOST));
        foreach($parsed as $piece){
            if(($piece['kind']??'')!=='path')continue;
            $paths[$piece['relative_path']]=true;
            if(!in_array($piece['host'],[$site_host,$upload_host],true))
                $out['foreign_origin_count']++;
        }
        global $wpdb;
        $source_candidates=[];
        $variants=[];
        foreach(array_keys($paths) as $rel){
            $base=preg_replace('/-\d{2,5}x\d{2,5}(?=\.(?:jpe?g|png|webp|avif)$)/i','',$rel);
            if($base!==$rel){
                $variants[$rel]=[$base];
                // WP core stores its full-size original as *-scaled.ext,
                // but generated subsizes use the UNscaled basename. Query
                // both attachment paths, then verify the size filename
                // against that attachment's exact WP metadata and file.
                $scaled=preg_replace('/(?=\.(?:jpe?g|png|webp|avif)$)/i','-scaled',$base);
                if($scaled!==$base){
                    $variants[$rel][]=$scaled;
                    $source_candidates[$scaled]=true;
                }
            }
            $source_candidates[$rel]=true;
            $source_candidates[$base]=true;
        }
        // Second-level shared catalog cache, keyed by verified file paths
        // rather than post ID. This avoids repeating identical DB queries
        // for every tour sharing a migrated Gallery. Canonical attachment
        // selection remains post-scoped below.
        $candidate_names=array_keys($source_candidates);
        sort($candidate_names,SORT_STRING);
        $catalog_key='c-'.hash('sha256',(string)get_current_blog_id()
            .'|'.self::epoch().'|'.implode("\n",$candidate_names));
        $attachments=wp_cache_get($catalog_key,self::CACHE_GROUP);
        $out['attachment_catalog_cache_hit']=is_array($attachments);
        if(!is_array($attachments)){
            $attachments=[];
        foreach(array_chunk($candidate_names,160) as $chunk){
            $slots=implode(',',array_fill(0,count($chunk),'%s'));
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT pm.meta_value,p.ID,p.post_parent,p.post_status
                 FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON pm.post_id=p.ID
                 WHERE pm.meta_key='_wp_attached_file' AND pm.meta_value IN ($slots)
                    AND p.post_type='attachment' AND p.post_mime_type LIKE 'image/%%'
                 ORDER BY p.ID ASC",...$chunk),ARRAY_A);
            if(!is_array($rows)||$wpdb->last_error){
                $out['resolver_error']='attachment_catalog_unavailable';
                return $out;
            }
            foreach($rows as $row)
                $attachments[(string)$row['meta_value']][]=[
                    'id'=>(int)$row['ID'],
                    'parent'=>(int)$row['post_parent'],
                    'status'=>(string)$row['post_status']
                ];
        }
            wp_cache_set($catalog_key,$attachments,self::CACHE_GROUP,12*MINUTE_IN_SECONDS);
        }
        $base_path=realpath((string)wp_upload_dir(null,false)['basedir']);
        if(!$base_path){
            $out['resolver_error']='local_media_directory_unavailable';
            return $out;
        }
        $chosen=[];$used=[];$reasons=[];
        foreach($parsed as $piece){
            $kind=$piece['kind']??'invalid';
            if($kind==='id'){
                $aid=(int)$piece['id'];
                $f=$aid>0?get_attached_file($aid):'';
                $real=$f?realpath($f):false;
                if($real&&str_starts_with($real,$base_path.DIRECTORY_SEPARATOR)
                   &&is_file($real)&&str_starts_with((string)get_post_mime_type($aid),'image/')){
                    $reasons['existing_attachment_id']=($reasons['existing_attachment_id']??0)+1;
                    if(!isset($used[$aid])){$chosen[]=$aid;$used[$aid]=true;}
                    $out['verified_local']++;
                    continue;
                }
                $out['unresolved']++;continue;
            }
            if($kind!=='path'){$out['unresolved']++;continue;}
            $rel=$piece['relative_path'];
            $full=realpath($base_path.'/'.$rel);
            if(!$full || !str_starts_with($full,$base_path.DIRECTORY_SEPARATOR)
               || !is_file($full)){$out['unresolved']++;continue;}
            $candidates=$attachments[$rel]??[];
            $strategy='exact_local_attachment';
            if(!$candidates && isset($variants[$rel])){
                foreach($variants[$rel] as $parentPath){
                    foreach((array)($attachments[$parentPath]??[]) as $parent)
                        $candidates[]=$parent;
                }
                $strategy='verified_wordpress_image_size';
            }
            $valid=[];
            foreach($candidates as $row){
                $aid=$row['id'];$original=get_attached_file($aid);
                if(!$original||!str_starts_with((string)get_post_mime_type($aid),'image/'))continue;
                if($strategy==='exact_local_attachment'){
                    if(realpath($original)!==$full)continue;
                } else {
                    $meta=wp_get_attachment_metadata($aid);
                    $variants_meta=is_array($meta)?($meta['sizes']??[]):[];
                    $matches=false;
                    foreach((array)$variants_meta as $entry){
                        if(is_array($entry)&&($entry['file']??'')===basename($rel)){
                            $matches=true;break;
                        }
                    }
                    if(!$matches)continue;
                    $dirname=dirname((string)realpath($original));
                    if($dirname!==dirname($full))continue;
                }
                $valid[]=$row;
            }
            if(!$valid){$out['unresolved']++;continue;}
            usort($valid,static fn($a,$b) =>
                ((int)($b['parent']===$id)<=>(int)($a['parent']===$id))
                ?: ((int)($b['status']==='inherit')<=>(int)($a['status']==='inherit'))
                ?:($a['id']<=>$b['id']));
            $winner=$valid[0]['id'];
            if(count($valid)>1){
                $out['equivalent_attachment_records']+=count($valid)-1;
                $strategy='canonical_equivalent_attachment';
            }
            $reasons[$strategy]=($reasons[$strategy]??0)+1;
            if(!isset($used[$winner])){$chosen[]=$winner;$used[$winner]=true;}
            $out['verified_local']++;
        }
        $out['strategies']=$reasons;
        $out['requires_media_review']=$out['foreign_origin_count']>0
            ||$out['unresolved']>0 ||$out['equivalent_attachment_records']>0;
        // Rendering is strictly a projection. Source post meta is untouched.
        // Use validated local IDs when available; preserve legacy value if
        // no local file can be proven, instead of silently fabricating images.
        if($chosen){
            $out['render_value']=implode(',',$chosen);
            $out['presentation_mode']=$out['unresolved']>0
                ?'verified_subset_with_review':'verified_local_attachment_ids';
        } else $out['presentation_mode']='original_preserved_no_safe_local_match';
        wp_cache_set($key,$out,self::CACHE_GROUP,12*MINUTE_IN_SECONDS);
        self::$observed[$id]=$out;
        return $out;
    }
    public static function render($value,$post_id){
        $r=self::inspect($value,$post_id);
        return !empty($r['resolver_error'])?$value:($r['render_value']??$value);
    }
    public static function observed($post_id):array{
        return self::$observed[(int)$post_id]??[];
    }
}
add_action('add_attachment',[MAD4B_Gallery_Resolution_Engine_V1::class,'invalidate'],15,1);
add_action('edit_attachment',[MAD4B_Gallery_Resolution_Engine_V1::class,'invalidate'],15,1);
add_action('delete_attachment',[MAD4B_Gallery_Resolution_Engine_V1::class,'invalidate'],15,1);
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)
    add_action($hook,static function($meta_id,$object_id,$meta_key){
        MAD4B_Gallery_Resolution_Engine_V1::invalidate($object_id,$meta_key);
    },15,3);
