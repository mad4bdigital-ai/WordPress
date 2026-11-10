<?php
/**
 * Plugin Name: MAD4B Visual Media Lineage Guard
 * Description: Read-only cross-tour featured-photo lineage and perceptual similarity audit; fail-closed preflight for new shared featured originals.
 * Version: 1.0.1
 */
defined('ABSPATH') || exit;

final class MAD4B_Media_Visual_Lineage_V1 {
    const CONTRACT = 'mad4b.media-visual-lineage.v1';
    const SOURCE = 'mad4b-media-visual-lineage';
    const MAX_TOURS = 1000;
    const MAX_ANCESTORS = 12;
    private static $origin_cache = [];
    private static $perceptual_cache = [];

    /** Resolve a verified local file's ORIGINAL photographic bytes, never its cropped derivative bytes. */
    public static function origin(int $id): array {
        $initial = $id;
        if (isset(self::$origin_cache[$initial])) return self::$origin_cache[$initial];
        $visited = [];
        $chain = [];
        for ($depth=0; $depth<self::MAX_ANCESTORS; $depth++) {
            if ($id<1 || isset($visited[$id]) || get_post_type($id)!=='attachment')
                return self::$origin_cache[$initial]=['verified'=>false,'reason'=>'lineage_cycle_or_attachment_missing','chain'=>$chain];
            $visited[$id]=true;
            $chain[]=$id;
            $next=(int)get_post_meta($id,'_mad4b_portrait_source_attachment_id',true);
            if ($next===0) {
                $path=get_attached_file($id);
                $upload=wp_get_upload_dir();
                $base=realpath((string)($upload['basedir']??''));
                $real=$path?realpath($path):false;
                if (!$base || !$real || !str_starts_with($real,$base.DIRECTORY_SEPARATOR)
                    || !is_file($real) || !str_starts_with((string)get_post_mime_type($id),'image/'))
                    return self::$origin_cache[$initial]=['verified'=>false,'reason'=>'origin_local_file_unverified','chain'=>$chain];
                $sha=hash_file('sha256',$real);
                if (!is_string($sha) || strlen($sha)!==64)
                    return self::$origin_cache[$initial]=['verified'=>false,'reason'=>'origin_digest_unavailable','chain'=>$chain];
                return self::$origin_cache[$initial]=[
                    'verified'=>true,'origin_attachment_id'=>$id,'origin_sha256'=>$sha,
                    'lineage_depth'=>count($chain)-1,'chain'=>$chain
                ];
            }
            $id=$next;
        }
        return self::$origin_cache[$initial]=['verified'=>false,'reason'=>'lineage_too_deep','chain'=>$chain];
    }

    /** Perceptual similarity is advisory, NOT a proof that two photos have the same source. */
    private static function dhash(int $id): ?string {
        if (array_key_exists($id,self::$perceptual_cache))return self::$perceptual_cache[$id];
        if (!function_exists('imagecreatefromstring'))return self::$perceptual_cache[$id]=null;
        $file=get_attached_file($id);
        if (!$file || !is_file($file) || filesize($file)>12000000)return self::$perceptual_cache[$id]=null;
        // GD decodes only local images, bounded by metadata pixel count.
        $info=@getimagesize($file);
        if (!$info || $info[0]*$info[1]>16000000 || $info[0]<9 || $info[1]<8)
            return self::$perceptual_cache[$id]=null;
        $bytes=@file_get_contents($file);
        $im=$bytes!==false?@imagecreatefromstring($bytes):false;
        if (!$im)return self::$perceptual_cache[$id]=null;
        $small=imagecreatetruecolor(9,8);
        imagecopyresampled($small,$im,0,0,0,0,9,8,imagesx($im),imagesy($im));
        $bits='';
        for($y=0;$y<8;$y++)for($x=0;$x<8;$x++) {
            $a=imagecolorat($small,$x,$y);
            $b=imagecolorat($small,$x+1,$y);
            $l=(($a>>16)&255)*299+(($a>>8)&255)*587+($a&255)*114;
            $r=(($b>>16)&255)*299+(($b>>8)&255)*587+($b&255)*114;
            $bits.=($l>$r)?'1':'0';
        }
        imagedestroy($small);imagedestroy($im);
        return self::$perceptual_cache[$id]=$bits;
    }
    private static function distance(string $a,string $b): int {
        $count=0;for($i=0;$i<64;$i++)if($a[$i]!==$b[$i])$count++;
        return $count;
    }
    /** WordPress/WPML distinct tour families, not translated posts counted separately. */
    private static function inventory(): array {
        global $wpdb;
        $ids=$wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s ORDER BY ID LIMIT %d",
            'tours-and-activities','publish',self::MAX_TOURS+1));
        if(!is_array($ids)||$wpdb->last_error||count($ids)>self::MAX_TOURS)
            return ['complete'=>false,'reason'=>'published_tour_inventory_unbounded'];
        $trids=[];
        if($ids && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.'icl_translations'))){
            foreach(array_chunk($ids,250) as $chunk){
                $slot=implode(',',array_fill(0,count($chunk),'%d'));
                $rows=$wpdb->get_results($wpdb->prepare(
                    "SELECT element_id,trid FROM {$wpdb->prefix}icl_translations WHERE element_type='post_tours-and-activities' AND element_id IN ($slot)",...$chunk),ARRAY_A);
                if(!is_array($rows)||$wpdb->last_error)return ['complete'=>false,'reason'=>'wpml_family_readback_failed'];
                foreach($rows as $row)$trids[(int)$row['element_id']]=(int)$row['trid'];
            }
        }
        $out=[];
        foreach($ids as $post_id){
            $post_id=(int)$post_id;
            $attachment=(int)get_post_thumbnail_id($post_id);
            if(!$attachment)continue;
            $out[]=['post_id'=>$post_id,'family_key'=>isset($trids[$post_id])?'wpml:'.$trids[$post_id]:'post:'.$post_id,
                'attachment_id'=>$attachment,'title'=>get_the_title($post_id)];
        }
        return ['complete'=>true,'rows'=>$out,'scanned_published_posts'=>count($ids)];
    }
    public static function audit(bool $perceptual=true):array {
        $data=self::inventory();
        $result=['contract'=>self::CONTRACT,'site_uuid'=>class_exists('MAD4B_SCP_Site_Profile',false)
            ?(string)MAD4B_SCP_Site_Profile::site_uuid():'',
            'complete'=>$data['complete'],'scanned_published_posts'=>$data['scanned_published_posts']??0,
            'mode'=>'read_only','checks'=>['exact_original_sha256','portrait_source_lineage','wpml_family','advisory_dhash'],
            'confirmed_reused_featured_origins'=>[],'possible_visual_duplicates'=>[],'unresolved_origins'=>[],
            'confirmed_group_count'=>0,'advisory_pair_count'=>0,'mutations_performed'=>false];
        if(!$data['complete']){$result['reason']=$data['reason'];return $result;}
        $by_group=[];$by_sha=[];$seen=[];
        foreach($data['rows'] as $row){
            $o=self::origin((int)$row['attachment_id']);
            if(!$o['verified']){$result['unresolved_origins'][]=$row+['reason'=>$o['reason']];continue;}
            $row['origin_sha256']=$o['origin_sha256'];$row['origin_attachment_id']=$o['origin_attachment_id'];
            $row['lineage_depth']=$o['lineage_depth'];
            $key=$row['family_key'].'|'.$o['origin_sha256'];
            if(isset($seen[$key]))continue;$seen[$key]=true;
            $by_sha[$o['origin_sha256']][]=$row;
            $by_group[]=$row;
        }
        foreach($by_sha as $sha=>$rows){
            $family=array_unique(array_column($rows,'family_key'));
            if(count($family)>1)$result['confirmed_reused_featured_origins'][]=[
                'origin_sha256'=>$sha,'families'=>count($family),'tours'=>$rows,
                'reason'=>'same_original_photo_across_distinct_tours_even_when_cropped'
            ];
        }
        $result['confirmed_group_count']=count($result['confirmed_reused_featured_origins']);
        // Compare original photographic pixels, never differently cropped derivatives.
        // A very small Hamming distance suggests possible near-duplicates,
        // but similar blue skies/temples can be false positives: review only.
        if($perceptual){
            $n=count($by_group);
            for($i=0;$i<$n;$i++){
                $a=$by_group[$i];$ha=self::dhash($a['origin_attachment_id']);if($ha===null)continue;
                for($j=$i+1;$j<$n;$j++){
                    $b=$by_group[$j];
                    if($a['family_key']===$b['family_key'] || $a['origin_sha256']===$b['origin_sha256'])continue;
                    $hb=self::dhash($b['origin_attachment_id']);if($hb===null)continue;
                    $distance=self::distance($ha,$hb);
                    if($distance<=4 && count($result['possible_visual_duplicates'])<150)
                        $result['possible_visual_duplicates'][]=['post_ids'=>[$a['post_id'],$b['post_id']],
                            'attachment_ids'=>[$a['attachment_id'],$b['attachment_id']],
                            'distance'=>$distance,'classification'=>'requires_visual_review_not_automatic_block'];
                }
            }
        }
        $result['advisory_pair_count']=count($result['possible_visual_duplicates']);
        return $result;
    }
    public static function guard_featured(int $post_id,int $candidate_id):array {
        $post=get_post($post_id);
        if(!$post||$post->post_type!=='tours-and-activities')return ['allowed'=>false,'reason'=>'tour_missing'];
        $origin=self::origin($candidate_id);
        if(!$origin['verified'])return ['allowed'=>false,'reason'=>'featured_origin_unverified'];
        $inventory=self::inventory();
        if(!$inventory['complete'])return ['allowed'=>false,'reason'=>$inventory['reason']];
        // The candidate tour may be published in EN+ES: skip translations
        // by the authoritative WPML family rather than comparing post IDs.
        $target_family=null;
        foreach($inventory['rows'] as $row)if($row['post_id']===$post_id){$target_family=$row['family_key'];break;}
        if($target_family===null){
            $trid=apply_filters('wpml_element_trid',null,$post_id,'post_tours-and-activities');
            $target_family=$trid?'wpml:'.(int)$trid:'post:'.$post_id;
        }
        $conflicts=[];
        foreach($inventory['rows'] as $row){
            if($row['family_key']===$target_family)continue;
            $other=self::origin((int)$row['attachment_id']);
            if(!$other['verified'])return ['allowed'=>false,'reason'=>'portfolio_featured_origin_incomplete'];
            if(hash_equals($origin['origin_sha256'],$other['origin_sha256']))
                $conflicts[$row['family_key']]=['post_id'=>$row['post_id'],'featured_id'=>$row['attachment_id']];
        }
        return ['allowed'=>count($conflicts)===0,'reason'=>$conflicts?'featured_original_reused_across_tours':'unique_featured_original_verified',
            'origin_sha256'=>$origin['origin_sha256'],'conflicts'=>array_values($conflicts)];
    }
    public static function issues($issues,$policy){
        if(!is_array($issues))return $issues;
        $audit=self::audit(false);
        if(!$audit['complete']){
            $issues[]=['source'=>self::SOURCE,'code'=>'media_visual_lineage_scan_incomplete'];return $issues;
        }
        foreach($audit['confirmed_reused_featured_origins'] as $group){
            foreach($group['tours'] as $tour){
                $issues[]=['source'=>self::SOURCE,'code'=>'media_featured_visual_origin_reused',
                    'post_type'=>'tours-and-activities','object_id'=>$tour['post_id'],'severity'=>'high',
                    'label'=>'Featured photo visually duplicated across '.$group['families'].' tour families',
                    'source_evidence'=>['origin_sha256'=>$group['origin_sha256'],'origin_attachment_id'=>$tour['origin_attachment_id'],
                    'other_tour_ids'=>array_values(array_diff(array_column($group['tours'],'post_id'),[$tour['post_id']])),
                    'verified_by'=>'portrait_source_lineage_and_original_file_hash',
                    'remediation'=>'use a genuinely distinct original photo; cropping is not sufficient']];
            }
        }
        return $issues;
    }
    public static function cli($args,$assoc){
        if($args||array_diff(array_keys($assoc),['no-perceptual','perceptual']))WP_CLI::error('Usage: wp mad4b media-visual-lineage [--no-perceptual]');
        $perceptual=!isset($assoc['no-perceptual']) && (!array_key_exists('perceptual',$assoc) || filter_var($assoc['perceptual'],FILTER_VALIDATE_BOOLEAN));
        $report=self::audit($perceptual);
        WP_CLI::line(wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        if(!$report['complete'])WP_CLI::halt(3);
    }
}
if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b media-visual-lineage',[MAD4B_Media_Visual_Lineage_V1::class,'cli']);
