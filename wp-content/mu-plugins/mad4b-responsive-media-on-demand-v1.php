<?php
/**
 * Plugin Name: MAD4B Responsive Media On Demand
 * Description: Creates only requested, validated responsive WebP derivatives via site-local WordPress Cron.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) return;

final class MAD4B_Responsive_Media_On_Demand_V1 {
    const HOOK = 'mad4b_responsive_media_create_v1';
    const CONTRACT = 'mad4b.responsive-media.demand.v1';
    const MAX_SOURCE_BYTES = 10000000;
    const MAX_OUTPUT_BYTES = 1200000;
    const MAX_SOURCE_VARIANTS = 4;
    const MIN_WIDTH = 192;
    const MAX_WIDTH = 1536;
    const WIDTH_STEP = 64;

    public static function width_bucket($physical): int {
        if (!is_numeric($physical) || !is_finite((float)$physical)) return 0;
        $w=(int)ceil((float)$physical);
        if ($w<self::MIN_WIDTH || $w>self::MAX_WIDTH) return 0;
        return (int)(ceil($w / self::WIDTH_STEP) * self::WIDTH_STEP);
    }
    private static function policy(): array {
        $p=get_option('mad4b_responsive_media_policy_v1', []);
        if (!is_array($p)) $p=[];
        return [
            'enabled' => !empty($p['enabled']),
            'taxonomy' => preg_match('/^[a-z][a-z0-9_-]{1,47}$/D',(string)($p['taxonomy']??'')) ? $p['taxonomy'] : '',
            'ids_option' => preg_match('/^[a-z][a-z0-9_]{3,80}$/D',(string)($p['ids_option']??'')) ? $p['ids_option'] : '',
            'source_meta_key' => preg_match('/^[a-z][a-z0-9_]{3,80}$/D',(string)($p['source_meta_key']??'')) ? $p['source_meta_key'] : '',
            'selector' => preg_match('/^\.elementor-element-[a-f0-9]{7}$/D',(string)($p['selector']??'')) ? $p['selector'] : '',
        ];
    }
    private static function eligible_term(int $term_id): ?array {
        $p=self::policy();
        if (!$p['enabled'] || !$p['taxonomy'] || !$p['ids_option'] || !$p['source_meta_key']
            || $term_id<1) return null;
        $ids=get_option($p['ids_option'],[]);
        if (!is_array($ids) || count($ids)>50 || count($ids)<1) return null;
        $allowed=array_unique(array_filter(array_map('absint',$ids)));
        $term=get_term($term_id,$p['taxonomy']);
        if (!$term || is_wp_error($term)) return null;
        // Cross-language validation: allow only configured IDs or their
        // WPML translations in the *active* languages, never arbitrary IDs.
        if (!in_array($term_id,$allowed,true)) {
            $langs=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
            if (!is_array($langs) || count($langs)>24) return null;
            $match=false;
            foreach ($allowed as $id) {
                foreach (array_keys($langs) as $lang) {
                    $translation=(int)apply_filters('wpml_object_id',$id,$p['taxonomy'],false,$lang);
                    if ($translation===$term_id) {$match=true;break 2;}
                }
            }
            if (!$match) return null;
        }
        $url=(string)get_term_meta($term_id,$p['source_meta_key'],true);
        $parts=wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme']??'')!=='https'
            || strtolower((string)($parts['host']??''))!==strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))) return null;
        $path=(string)($parts['path']??'');
        $prefix='/wp-content/uploads/';
        if (!str_starts_with($path,$prefix)) return null;
        $base=realpath(WP_CONTENT_DIR.'/uploads');
        $relative=rawurldecode(substr($path,strlen($prefix)));
        if (!$base || str_contains($relative,'..') || str_contains($relative,'\\') || str_contains($relative,"\0")) return null;
        $original=realpath($base.'/'.$relative);
        if (!$original || !str_starts_with($original,$base.'/') || is_link($original)
            || !is_file($original) || !is_readable($original)
            || filesize($original)>self::MAX_SOURCE_BYTES || filesize($original)<1000
            || !preg_match('/\.(?:jpe?g|png|webp)$/i',$original)) return null;
        $stamp=substr(hash('sha256',$original.':'.(string)filemtime($original).':'.(string)filesize($original)),0,12);
        return ['original'=>$original,'relative'=>substr($original,strlen($base)+1),'base'=>$base,'stamp'=>$stamp];
    }
    private static function derivative_path(array $file,int $bucket): string {
        return $file['original'].'.mad4b-demand-'.$bucket.'-'.$file['stamp'].'.webp';
    }
    private static function derivative_url(array $file,int $bucket): string {
        return set_url_scheme(content_url('/uploads/'.$file['relative'].'.mad4b-demand-'.$bucket.'-'.$file['stamp'].'.webp'),'https');
    }
    public static function query(int $term_id,$physical_width,bool $schedule=true): array {
        $width=self::width_bucket($physical_width);
        if (!$width) return ['state'=>'invalid_width'];
        $file=self::eligible_term($term_id);
        if (!$file) return ['state'=>'invalid_source'];
        $existing=self::derivative_path($file,$width);
        $prefix=$file['original'].'.mad4b-demand-';
        $available=[];
        foreach ((glob($prefix.'*.webp') ?: []) as $variant) {
            if (is_link($variant)||!is_file($variant)) continue;
            $suffix=substr($variant,strlen($prefix));
            if (!preg_match('/^([0-9]{3,4})-([a-f0-9]{12})\.webp$/D',$suffix,$m) || $m[2]!==$file['stamp'])continue;
            if (filesize($variant)<1500 || filesize($variant)>self::MAX_OUTPUT_BYTES)continue;
            $available[]=(int)$m[1];
        }
        if (in_array($width,$available,true)) return [
            'state'=>'ready','width'=>$width,'url'=>self::derivative_url($file,$width)];
        // Use a close existing derivative when within 12.5% of requested
        // physical width; it avoids excess image copies on minor layouts.
        sort($available,SORT_NUMERIC);
        foreach ($available as $variant) {
            if ($variant >= $width && $variant <= $width*1.125)
                return ['state'=>'ready','width'=>$variant,'url'=>self::derivative_url($file,$variant)];
        }
        if (count($available)>=self::MAX_SOURCE_VARIANTS) return ['state'=>'fallback_variant_limit'];
        if ($schedule && !wp_next_scheduled(self::HOOK,[$term_id,$width])) {
            $key='mad4b_media_demand_'.substr(hash('sha256',(string)$term_id),0,16);
            $requests=get_transient($key);
            if (!is_array($requests)) $requests=[];
            if (!in_array($width,$requests,true) && count($requests)>=self::MAX_SOURCE_VARIANTS)
                return ['state'=>'fallback_request_budget'];
            if (!in_array($width,$requests,true)) {
                $requests[]=$width;set_transient($key,$requests,HOUR_IN_SECONDS);
            }
            // Only one pending generation per exact source/width; no resize
            // within an HTTP request. PHP image work is done locally by Cron.
            if (!wp_schedule_single_event(time()+60,self::HOOK,[$term_id,$width]))
                return ['state'=>'host_schedule_unavailable','width'=>$width];
        }
        return ['state'=>'pending','width'=>$width];
    }
    public static function rest($req) {
        $batch=$req->get_param('items');
        if ($batch!==null) {
            // One site-local WordPress bootstrap for at most eight *visible*
            // image requests. Invalid/oversized lists fail closed.
            if (!is_string($batch) || strlen($batch)>170 ||
                !preg_match('/^[0-9]{1,9}:[0-9]{3,4}(?:,[0-9]{1,9}:[0-9]{3,4}){0,7}$/D',$batch))
                return new WP_Error('mad4b_invalid_media_batch','Invalid media batch',['status'=>400]);
            $result=[];
            foreach (explode(',',$batch) as $pair) {
                [$term,$width]=array_map('intval',explode(':',$pair));
                $key=$term.':'.$width;
                if (array_key_exists($key,$result)) continue;
                $result[$key]=self::query($term,$width,true);
            }
            $payload=['contract'=>self::CONTRACT,'items'=>$result];
        } else {
            $term_id=(int)$req->get_param('term_id');
            $width=$req->get_param('width');
            if ($term_id<1 || $width===null)
                return new WP_Error('mad4b_invalid_media_request','Invalid media request',['status'=>400]);
            $payload=self::query($term_id,$width,true);
        }
        // No guest PII, no request-selected source file, no arbitrary URL.
        $response=rest_ensure_response($payload);
        $response->header('Cache-Control','private, no-store, max-age=0');
        return $response;
    }
    public static function process($term_id,$width): void {
        $term_id=(int)$term_id;
        $width=self::width_bucket($width);
        if (!$width) return;
        $file=self::eligible_term($term_id);
        if (!$file || !class_exists('Imagick')) return;
        $dest=self::derivative_path($file,$width);
        if (is_file($dest))return;
        $prefix=$file['original'].'.mad4b-demand-';
        if (count(glob($prefix.'*-'.$file['stamp'].'.webp') ?: [])>=self::MAX_SOURCE_VARIANTS)return;
        // Serialize image work per site, across all cards and languages.
        // Defer follow-up jobs rather than starting multiple Imagick workers
        // inside the same visitor-triggered WordPress Cron process.
        $globalLock=@fopen($file['base'].'/.mad4b-responsive-media-worker.lock','c');
        if (!$globalLock || !flock($globalLock,LOCK_EX|LOCK_NB)) {
            if (!wp_next_scheduled(self::HOOK,[$term_id,$width]))
                wp_schedule_single_event(time()+90,self::HOOK,[$term_id,$width]);
            return;
        }
        $lockHandle=null;
        $limits=[];
        try {
            if (get_transient('mad4b_media_demand_worker_cooldown_v1')) {
                if (!wp_next_scheduled(self::HOOK,[$term_id,$width]))
                    wp_schedule_single_event(time()+90,self::HOOK,[$term_id,$width]);
                return;
            }
            $lock=$file['original'].'.mad4b-demand.lock';
            $lockHandle=@fopen($lock,'c');
            if (!$lockHandle || !flock($lockHandle,LOCK_EX|LOCK_NB))return;
            if (is_file($dest)) return;
            if (count(glob($prefix.'*-'.$file['stamp'].'.webp') ?: [])>=self::MAX_SOURCE_VARIANTS)return;
            $dimensions=@getimagesize($file['original']);
            if (!is_array($dimensions) || $dimensions[0]<100 || $dimensions[1]<100
                || $dimensions[0]>8000 || $dimensions[1]>8000
                || ((int)$dimensions[0] * (int)$dimensions[1]) > 22000000) return;
            foreach ([Imagick::RESOURCETYPE_MEMORY=>128*1024*1024,
                      Imagick::RESOURCETYPE_MAP=>256*1024*1024,
                      Imagick::RESOURCETYPE_TIME=>10] as $type=>$bound) {
                $previous=Imagick::getResourceLimit($type);
                $limits[$type]=$previous;
                Imagick::setResourceLimit($type,min($previous>0?$previous:$bound,$bound));
            }
            $image=new Imagick($file['original']);
            if ($image->getNumberImages()!==1) return;
            $scale=min(1,$width/$image->getImageWidth());
            $w=(int)max(1,round($image->getImageWidth()*$scale));
            $h=(int)max(1,round($image->getImageHeight()*$scale));
            $image->resizeImage($w,$h,Imagick::FILTER_LANCZOS,1);
            $image->setImageFormat('WEBP');
            $image->setImageCompressionQuality(80);
            $image->setOption('webp:method','5');
            $tmp=tempnam(dirname($dest),'.mad4b-demand-');
            if (!$tmp) return;
            try {
                if (!$image->writeImage($tmp))return;
                if (filesize($tmp)<1500 || filesize($tmp)>self::MAX_OUTPUT_BYTES)return;
                $currentStamp=substr(hash('sha256',$file['original'].':'.(string)filemtime($file['original']).':'.(string)filesize($file['original'])),0,12);
                if ($currentStamp!==$file['stamp'] || is_link($dest) || file_exists($dest))return;
                if (!rename($tmp,$dest))return;
                chmod($dest,0644);
                set_transient('mad4b_media_demand_worker_cooldown_v1',1,25);
            } finally { if (file_exists($tmp))@unlink($tmp); }
            $image->clear();$image->destroy();
        } catch (Exception $e) {
            error_log('MAD4B_ON_DEMAND_MEDIA_GENERATION_FAILED '.get_class($e));
        } finally {
            foreach ($limits as $type=>$originalLimit) {
                try { Imagick::setResourceLimit($type,$originalLimit); }
                catch (Exception $e) { error_log('MAD4B_ON_DEMAND_RESOURCE_RESTORE_FAILED'); }
            }
            if (is_resource($lockHandle)) {flock($lockHandle,LOCK_UN);fclose($lockHandle);}
            flock($globalLock,LOCK_UN);fclose($globalLock);
        }
    }
    public static function enqueue(): void {
        $p=self::policy();
        if (!$p['enabled'] || !$p['selector'] || !is_front_page() || is_admin() || is_user_logged_in())return;
        $asset=__DIR__.'/assets/mad4b-responsive-media-demand-v1.js';
        if (!is_file($asset))return;
        wp_enqueue_script('mad4b-responsive-media-demand',content_url('/mu-plugins/assets/mad4b-responsive-media-demand-v1.js'),[],(string)filemtime($asset),true);
        wp_add_inline_script('mad4b-responsive-media-demand','window.MAD4BResponsiveMedia='.wp_json_encode([
            'selector'=>$p['selector'],
            'endpoint'=>rest_url('mad4b/v1/responsive-media'),
            'minWidth'=>self::MIN_WIDTH,'maxWidth'=>self::MAX_WIDTH,
            'step'=>self::WIDTH_STEP,
        ],JSON_UNESCAPED_SLASHES).';','before');
    }
    public static function boot(): void {
        add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],110);
        add_action(self::HOOK,[__CLASS__,'process'],10,2);
        add_action('rest_api_init',static function() {
            register_rest_route('mad4b/v1','/responsive-media',[
                'methods'=>'GET','callback'=>[__CLASS__,'rest'],
                'permission_callback'=>'__return_true',
                'args'=>['term_id'=>['required'=>false,'type'=>'integer','minimum'=>1],
                         'width'=>['required'=>false,'type'=>'integer','minimum'=>self::MIN_WIDTH,'maximum'=>self::MAX_WIDTH],
                         'items'=>['required'=>false,'type'=>'string','maxLength'=>170]]]);
        });
    }
}
MAD4B_Responsive_Media_On_Demand_V1::boot();
