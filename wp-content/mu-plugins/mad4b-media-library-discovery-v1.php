<?php
/**
 * Plugin Name: MAD4B Media Library Discovery
 * Description: Bounded read-only site-local image discovery without attachment ownership mutation.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Media_Library_Discovery_V1 {
    const CONTRACT = 'mad4b.media-library-discovery.v1';
    private static function err($code) { return new WP_Error($code, 'Media discovery rejected input or incomplete evidence.'); }
    private static function family($id) {
        $trid=apply_filters('wpml_element_trid',null,$id,'post_tours-and-activities');
        $translations=$trid ? apply_filters('wpml_get_element_translations',[],$trid,'post_tours-and-activities'):[];
        $out=[];
        foreach((array)$translations as $lang=>$t) {
            $p=(int)($t->element_id??0);
            if(preg_match('/^[a-z]{2}$/D',(string)$lang) && $p && get_post_type($p)==='tours-and-activities')$out[$lang]=$p;
        }
        ksort($out);return $out;
    }
    private static function terms($input,$post) {
        $explicit=is_string($input) && trim($input)!=='';
        if($explicit) {
            $tokens=array_values(array_filter(array_map('trim',preg_split('/[;,]+/u',$input))));
        } else {
            $tokens=preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower(wp_strip_all_tags($post->post_title),'UTF-8'));
        }
        $skip=array_fill_keys(['egypt','tour','tours','day','days','from','with','best','private','luxury','the','and','trip','package','cruise','discovery','temple','guided','highlights','egyptian','full','one','cairo','aswan','luxor'],true);
        $out=[];
        foreach($tokens as $v){$s=mb_strtolower(trim($v),'UTF-8');if(mb_strlen($s)<4||mb_strlen($s)>42||(!$explicit && isset($skip[$s])))continue;$out[$s]=true;}
        return array_slice(array_keys($out),0,6);
    }
    public static function discover($post_id,$query_terms='', $limit=24, $after_id=0){
        global $wpdb;
        $id=absint($post_id);$limit=(int)$limit;$after_id=(int)$after_id;
        if($id<1||$limit<1||$limit>24||$after_id<0||!is_string($query_terms)||strlen($query_terms)>240)return self::err('mad4b_media_discovery_invalid_request');
        $site=class_exists('MAD4B_SCP_Site_Profile',false) ? MAD4B_SCP_Site_Profile::status():[];
        if(empty($site['site_uuid'])||empty($site['origin_match'])||empty($site['environment_match'])||empty($site['deployment_binding_match']))return self::err('mad4b_media_discovery_site_unbound');
        $post=get_post($id);
        if(!$post||$post->post_type!=='tours-and-activities'||!in_array($post->post_status,['publish','draft'],true))return self::err('mad4b_media_discovery_tour_unavailable');
        $family=self::family($id);$source=(string)apply_filters('wpml_default_language','en');
        if(($family[$source]??null)!==$id)return self::err('mad4b_media_discovery_canonical_required');
        if(!$family)return self::err('mad4b_media_discovery_wpml_family_missing');
        $tokens=self::terms($query_terms,$post);if(!$tokens)return self::err('mad4b_media_discovery_no_relevant_terms');
        $clauses=[];$args=['attachment','image/%',$source,$after_id];
        foreach($tokens as $term){$clauses[]='(p.post_title LIKE %s OR p.post_name LIKE %s)';$needle='%'.$wpdb->esc_like($term).'%';$args[]=$needle;$args[]=$needle;}
        $sql="SELECT p.ID,p.post_title,p.post_parent FROM {$wpdb->posts} p LEFT JOIN {$wpdb->prefix}icl_translations tr ON tr.element_id=p.ID AND tr.element_type='post_attachment' WHERE p.post_type=%s AND p.post_mime_type LIKE %s AND p.post_parent=0 AND (tr.element_id IS NULL OR tr.language_code=%s) AND p.ID>%d AND (".implode(' OR ',$clauses).") ORDER BY p.ID ASC LIMIT %d";
        $args[]=181;
        $rows=$wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A);
        if(!is_array($rows)||$wpdb->last_error)return self::err('mad4b_media_discovery_query_failed');
        $has_next=count($rows)>180;
        if($has_next)$rows=array_slice($rows,0,180);
        $next_after_id=$has_next?(int)end($rows)['ID']:null;
        $base=realpath((string)(wp_upload_dir(null,false)['basedir']??''));if(!$base)return self::err('mad4b_media_discovery_upload_root_missing');
        $out=[];$hashes=[];$rejected=['not_wpml_complete'=>0,'missing_local_file'=>0,'insufficient_dimensions'=>0,'duplicate_physical_file'=>0];
        foreach($rows as $row){
            $aid=(int)$row['ID'];$trid=apply_filters('wpml_element_trid',null,$aid,'post_attachment');
            $translations=$trid ? apply_filters('wpml_get_element_translations',[],$trid,'post_attachment'):[];
            $linked=[];$real=null;$valid=true;
            foreach($family as $lang=>$pid){
                $child=isset($translations[$lang])?(int)($translations[$lang]->element_id??0):0;
                if(!$child||get_post_type($child)!=='attachment'){$valid=false;break;}
                $p=get_post($child);if(!$p){$valid=false;break;}
                $f=get_attached_file($child);$freal=$f?realpath($f):false;
                if(!$freal||!str_starts_with($freal,$base.DIRECTORY_SEPARATOR)||!is_file($freal)){$rejected['missing_local_file']++;$valid=false;break;}
                if($real!==null && $freal!==$real){$valid=false;break;}
                $real=$freal;$linked[$lang]=['attachment_id'=>$child,'post_id'=>$pid,'existing_attachment_parent_id'=>(int)$p->post_parent,'association_review_required'=>((int)$p->post_parent!==0)];
            }
            if(!$valid||count($linked)!==count($family)){$rejected['not_wpml_complete']++;continue;}
            $meta=wp_get_attachment_metadata($aid);$w=(int)($meta['width']??0);$h=(int)($meta['height']??0);
            if($w<800||$h<500){$rejected['insufficient_dimensions']++;continue;}
            $sha=hash_file('sha256',$real);
            if(isset($hashes[$sha])){$rejected['duplicate_physical_file']++;continue;}
            $hashes[$sha]=true;
            $matches=[];foreach($tokens as $term)if(mb_stripos($row['post_title'],$term)!==false)$matches[]=$term;
            $out[]=['canonical_attachment_id'=>$aid,'title'=>$row['post_title'],'image_sha256'=>$sha,'dimensions'=>[$w,$h],'locale_attachments'=>$linked,'matched_terms'=>$matches,'source_evidence'=>'canonical_unattached_local_wpml_verified','visual_subject_verified'=>false,'photographic_origin_verified'=>false,'media_rights_verified'=>false,'auto_apply_authorized'=>false];
        }
        usort($out,static fn($a,$b)=>(count($b['matched_terms'])<=>count($a['matched_terms'])) ?:($a['canonical_attachment_id']<=>$b['canonical_attachment_id']));
        return ['contract'=>self::CONTRACT,'site_uuid'=>$site['site_uuid'],'tour_id'=>$id,'source_language'=>$source,'wpml_family'=>$family,'search_terms'=>$tokens,'scan_complete'=>!$has_next,'next_after_id'=>$next_after_id,'requested_after_id'=>$after_id,'database_candidate_rows'=>count($rows),'distinct_local_candidates'=>count($out),'rejected'=>$rejected,'candidates'=>array_slice($out,0,$limit),'result_truncated'=>count($out)>$limit,'read_only'=>true,'requires_explicit_visual_review_and_signed_association'=>true,'post_parent_changed'=>false,'rights_verified'=>false];
    }
    public static function cli($args,$assoc){
        if(count($args)!==1||!isset($args[0])||!ctype_digit((string)$args[0]))WP_CLI::error('One canonical tour ID required.');
        $terms=(string)($assoc['terms']??'');$limit=isset($assoc['limit'])?(int)$assoc['limit']:24;$after=isset($assoc['after-id'])?(int)$assoc['after-id']:0;
        $r=self::discover((int)$args[0],$terms,$limit,$after);
        if(is_wp_error($r))WP_CLI::error($r->get_error_code());
        WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}
if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b media-library-discover',['MAD4B_Media_Library_Discovery_V1','cli']);
