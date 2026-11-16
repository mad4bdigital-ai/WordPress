<?php
/**
 * Plugin Name: MAD4B Tour Gallery Source Repair
 * Description: Independent source gallery catalog, exact local attachment matching, reviewed append, WPML parity and rollback. Never downloads external images or silently trims a gallery.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Tour_Gallery_Source_Repair_V1 {
    const CONTRACT='mad4b.tour-gallery-source.v1';
    const META='_mad4b_gallery_source_catalog_v1';
    const BACKUP='mad4b_gallery_source_rollback_v1_';
    private static function fail($code):void {if(defined('WP_CLI')&&WP_CLI)WP_CLI::error('mad4b_gallery_source_'.$code);throw new RuntimeException('mad4b_gallery_source_'.$code);}
    private static function tour(int $id):void {
        if($id<=0||get_post_type($id)!=='tours-and-activities'||get_post_status($id)!=='publish')self::fail('tour_missing');
    }
    private static function gallery(int $id):string {return (string)get_post_meta($id,'gallery',true);}
    private static function sha(string $data):string{return hash('sha256',$data);}
    private static function rows(string $g):array{return array_values(array_filter(array_map('trim',explode(',',$g)),'strlen'));}
    private static function emit(array $data):void {echo wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}
    private static function origin(int $id):array {
        if(!class_exists('MAD4B_Media_Visual_Lineage_V1',false))self::fail('lineage_guard_missing');
        $o=MAD4B_Media_Visual_Lineage_V1::origin($id);
        if(empty($o['verified'])||empty($o['origin_sha256']))self::fail('candidate_origin_unverified_'.$id);
        return $o;
    }
    private static function family(int $id):array {
        $result=['default'=>$id];
        $trid=apply_filters('wpml_element_trid',null,$id,'post_tours-and-activities');
        if($trid){
            $translations=apply_filters('wpml_get_element_translations',null,$trid,'post_tours-and-activities');
            if(is_array($translations)){
                $found=[];
                foreach($translations as $lang=>$obj){
                    $pid=(int)($obj->element_id??0);
                    if($pid>0&&get_post_type($pid)==='tours-and-activities'&&get_post_status($pid)==='publish')$found[(string)$lang]=$pid;
                }
                if($found && in_array($id,$found,true))$result=$found;
            }
        }
        return $result;
    }
    private static function verified_local_id(string $url,int $post_id):int {
        $r=MAD4B_Gallery_Resolution_Engine_V1::inspect($url,$post_id);
        if(empty($r['valid_source'])||(int)$r['verified_local']!==1||(int)$r['unresolved']!==0)self::fail('local_media_missing');
        $id=(int)$r['render_value'];
        if(get_post_type($id)!=='attachment')self::fail('local_attachment_missing');
        return $id;
    }
    private static function source_export(int $id):void {
        self::tour($id);
        $raw=self::gallery($id);$urls=self::rows($raw);
        if(!$urls||count($urls)>500)self::fail('source_gallery_empty_or_oversize');
        self::emit(['contract'=>self::CONTRACT,'exported_at_utc'=>gmdate('c'),
            'source_home'=>home_url(),'source_id'=>$id,'source_slug'=>get_post_field('post_name',$id),
            'source_title'=>get_the_title($id),'gallery_sha256'=>self::sha($raw),
            'gallery_urls'=>$urls,'editorial_relevance_verified'=>false,
            'media_rights_verified'=>false,'source_mutated'=>false]);
    }
    private static function stage(int $id,string $path):void {
        self::tour($id);
        if(!is_file($path)||filesize($path)>240000)self::fail('manifest_missing_or_oversize');
        $m=json_decode((string)file_get_contents($path),true);
        if(!is_array($m)||($m['contract']??'')!==self::CONTRACT ||
           !hash_equals((string)($m['source_slug']??''),(string)get_post_field('post_name',$id)) ||
           !preg_match('/^[a-f0-9]{64}$/D',(string)($m['gallery_sha256']??'')))self::fail('manifest_identity_or_schema');
        $urls=$m['gallery_urls']??null;
        if(!is_array($urls)||!array_is_list($urls)||count($urls)<1||count($urls)>500||count(array_unique($urls))!==count($urls))self::fail('manifest_gallery_invalid');
        foreach($urls as $url){if(!is_string($url)||($p=MAD4B_Gallery_Resolution_Engine_V1::parse($url))['kind']!=='path')self::fail('manifest_url_invalid');}
        if(!hash_equals($m['gallery_sha256'],self::sha(implode(',',$urls))))self::fail('manifest_gallery_digest_mismatch');
        // Resolve the full source set in ONE bounded DB query; single-image
        // resolution for every item creates quadratic load on long galleries.
        $resolution=MAD4B_Gallery_Resolution_Engine_V1::inspect(implode(',',$urls),$id);
        if(empty($resolution['valid_source'])||(int)$resolution['verified_local']!==count($urls)
           ||(int)$resolution['unresolved']!==0)self::fail('manifest_local_resolution_incomplete');
        $local_ids=explode(',',(string)$resolution['render_value']);
        if(count($local_ids)!==count($urls))self::fail('manifest_local_id_count_mismatch');
        $candidate=[];$seen=[];$physical_unique=0;
        foreach($urls as $index=>$url){
            $aid=(int)$local_ids[$index];
            if(get_post_type($aid)!=='attachment')self::fail('local_attachment_missing');
            $o=self::origin($aid);
            if(!isset($seen[$o['origin_sha256']])){$physical_unique++;$seen[$o['origin_sha256']]=true;}
            $candidate[]=['index'=>$index,'attachment_id'=>$aid,'origin_sha256'=>$o['origin_sha256'],
                'url'=>wp_get_attachment_url($aid),'source_url'=>$url];
        }
        $catalog=['contract'=>self::CONTRACT,'source'=>[ 'home'=>$m['source_home'],
          'post_id'=>(int)$m['source_id'],'slug'=>$m['source_slug'],'gallery_sha256'=>$m['gallery_sha256']],
          'staged_utc'=>gmdate('c'),'items'=>$candidate,'editorial_review_required'=>true,
          'image_rights_not_reverified'=>true,'no_foreign_files_downloaded'=>true];
        update_post_meta($id,self::META,$catalog);
        $check=get_post_meta($id,self::META,true);
        if(!is_array($check)||count($check['items']??[])!==count($candidate))self::fail('catalog_readback_failed');
        self::emit(['status'=>'staged_review_required','post_id'=>$id,'source_items'=>count($candidate),
          'verified_local'=>count($candidate),'unique_physical_origins'=>$physical_unique,
          'source_gallery_sha256'=>$m['gallery_sha256'],'current_gallery_items'=>count(self::rows(self::gallery($id))),
          'gallery_updated'=>false,'no_media_import'=>true]);
    }
    private static function catalog(int $id):array {
        self::tour($id);$c=get_post_meta($id,self::META,true);
        if(!is_array($c)||($c['contract']??'')!==self::CONTRACT||empty($c['items']))self::fail('catalog_not_staged');
        return $c;
    }
    private static function plan(int $id,int $limit=24):void {
        $c=self::catalog($id);$candidate=[];
        foreach(array_slice($c['items'],0,max(1,min(100,$limit))) as $i){
            $aid=(int)$i['attachment_id'];$candidate[]=['index'=>(int)$i['index'],'attachment_id'=>$aid,
              'title'=>get_the_title($aid),'url'=>$i['url']];
        }
        self::emit(['status'=>'review_required','post_id'=>$id,'source'=>$c['source'],
          'candidate_count'=>count($c['items']),'current_gallery_count'=>count(self::rows(self::gallery($id))),
          'current_gallery_sha256'=>self::sha(self::gallery($id)),'family'=>self::family($id),
          'displayed_candidates'=>$candidate,'no_automatic_apply'=>true]);
    }
    private static function apply(int $id,string $indexes,string $expected):void {
        $c=self::catalog($id);$family=self::family($id);
        if(!preg_match('/^\d+(?:,\d+)*$/D',$indexes))self::fail('indexes_invalid');
        $selected=array_map('intval',explode(',',$indexes));
        if(!$selected||count($selected)>80||count($selected)!==count(array_unique($selected)))self::fail('selection_invalid');
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||!hash_equals($expected,self::sha(self::gallery($id))))self::fail('expected_gallery_digest_drift');
        $picked=[];
        foreach($selected as $n){
            if(!isset($c['items'][$n])||(int)$c['items'][$n]['index']!==$n)self::fail('unknown_source_index');
            $item=$c['items'][$n];$aid=(int)$item['attachment_id'];$o=self::origin($aid);
            if(!hash_equals($item['origin_sha256'],$o['origin_sha256']))self::fail('source_photo_lineage_changed');
            // Stage/review may include uncertain origin, but a confirmed
            // generative image can never be selected for tour publication.
            if(!class_exists('MAD4B_Tour_Photo_Quality_V1',false)
              ||MAD4B_Tour_Photo_Quality_V1::provenance($aid)['status']==='synthetic_confirmed')
                self::fail('synthetic_media_not_publishable');
            $url=wp_get_attachment_url($aid);
            if(!is_string($url)||$url!==$item['url'])self::fail('source_url_changed');
            $picked[]=['sha'=>$o['origin_sha256'],'url'=>$url,'aid'=>$aid];
        }
        $changes=[];$counts=[];
        foreach($family as $lang=>$pid){
            $old=self::gallery($pid);$existing=self::rows($old);
            if(count($existing)>500)self::fail('current_gallery_oversize');
            $resolved=$existing?MAD4B_Gallery_Resolution_Engine_V1::inspect($old,$pid):['verified_local'=>0,'render_value'=>''];
            if($existing && ((int)($resolved['verified_local']??0)!==count($existing)))self::fail('existing_gallery_not_locally_resolved');
            $seen=[];$joined=[];
            $local_ids=$existing?explode(',',(string)$resolved['render_value']):[];
            foreach($existing as $j=>$url){
                $aid=(int)($local_ids[$j]??0);$o=self::origin($aid);
                // Preserve every previously visible item, even if a legacy
                // gallery itself already contains duplicated photographs.
                $joined[]=$url;$seen[$o['origin_sha256']]=true;
            }
            foreach($picked as $item){if(isset($seen[$item['sha']]))continue;$joined[]=$item['url'];$seen[$item['sha']]=true;}
            if(count($joined)>500)self::fail('merged_gallery_oversize');
            $new=implode(',',$joined);
            $changes[$pid]=['lang'=>$lang,'old'=>$old,'new'=>$new,'before'=>count($existing),'after'=>count($joined)];
            $counts[$lang]=count($joined);
        }
        if(count(array_unique($counts))!==1)self::fail('wpml_gallery_parity_unequal');
        $token=wp_generate_uuid4();$key=self::BACKUP.str_replace('-','',$token);
        $backup=['contract'=>self::CONTRACT,'token'=>$token,'source_post_id'=>$id,'selected'=>$selected,
                 'changes'=>$changes,'status'=>'applied','at_utc'=>gmdate('c')];
        global $wpdb;$wpdb->query('START TRANSACTION');
        try{
            if(!add_option($key,$backup,'','no'))throw new RuntimeException('backup_write_failed');
            foreach($changes as $pid=>$change){
                if($change['new']===$change['old'])continue;
                if(!update_post_meta($pid,'gallery',$change['new'],$change['old']))throw new RuntimeException('gallery_cas_write_failed_'.$pid);
            }
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit_failed');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');self::fail($e->getMessage());}
        foreach($changes as $pid=>$change){clean_post_cache($pid);do_action('litespeed_purge_post',(int)$pid);}
        foreach($changes as $pid=>$change){if(self::gallery($pid)!==$change['new'])self::fail('postwrite_readback_failed_'.$pid);}
        self::emit(['status'=>'applied','post_id'=>$id,'selected_source_indexes'=>$selected,
          'rollback_token'=>$token,'family_gallery_counts'=>$counts,
          'existing_images_preserved'=>true,'foreign_files_downloaded'=>0]);
    }
    private static function rollback(string $token):void {
        if(!preg_match('/^[a-f0-9-]{36}$/D',$token))self::fail('rollback_token_invalid');
        $key=self::BACKUP.str_replace('-','',$token);$b=get_option($key,false);
        if(!is_array($b)||($b['contract']??'')!==self::CONTRACT||($b['status']??'')!=='applied')self::fail('rollback_missing_or_used');
        foreach($b['changes'] as $pid=>$change)if(self::gallery((int)$pid)!==$change['new'])self::fail('rollback_current_gallery_changed');
        global $wpdb;$wpdb->query('START TRANSACTION');
        try{
            foreach($b['changes'] as $pid=>$change){if($change['old']===$change['new'])continue;
                if(!update_post_meta((int)$pid,'gallery',$change['old'],$change['new']))throw new RuntimeException('rollback_cas_failed');}
            $b['status']='rolled_back';if(!update_option($key,$b,false))throw new RuntimeException('rollback_status_failed');
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('rollback_commit_failed');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');self::fail($e->getMessage());}
        foreach($b['changes'] as $pid=>$change){clean_post_cache((int)$pid);do_action('litespeed_purge_post',(int)$pid);}
        self::emit(['status'=>'rolled_back','rollback_token'=>$token,'restored_posts'=>array_keys($b['changes'])]);
    }
    // Native WordPress editor UI: review local photos before adding them.
    // No external fetches, no automatic replace, no extra login/owner grant.
    public static function admin_box($post):void {
        $family=self::family((int)$post->ID);
        $canonical=(int)($family['en']??reset($family));
        if(!$canonical||!current_user_can('edit_post',$canonical))return;
        $catalog=get_post_meta($canonical,self::META,true);
        if(!is_array($catalog)||($catalog['contract']??'')!==self::CONTRACT){
            echo '<p>Source gallery catalog is not staged for this tour. Import a verified source manifest first; existing gallery photos remain untouched.</p>';
            return;
        }
        $items=$catalog['items'];$per=24;$pages=(int)ceil(count($items)/$per);
        $page=max(0,min($pages-1,(int)($_GET['mad4b_photo_page']??0)));
        $start=$page*$per;
        echo '<p><strong>'.esc_html(count($items)).' source candidates</strong> · '.esc_html(count(self::rows(self::gallery($canonical)))).' current gallery photos. Review place relevance and image rights before publishing. Nothing is removed automatically.</p>';
        if(($_GET['mad4b_gallery_status']??'')==='applied')echo '<p><strong>Selected source images added. All prior gallery images were preserved.</strong></p>';
        if(($_GET['mad4b_gallery_status']??'')==='error')echo '<p style="color:#a00"><strong>Gallery not changed:</strong> review your selection, permissions, or reload this page if the gallery changed since you opened it.</p>';
        // WordPress post.php already owns a form. Never nest a second form:
        // browser submission would accidentally save the outer post instead.
        echo '<div data-mad4b-gallery-repair="1" data-endpoint="'.esc_url(admin_url('admin-post.php')).'"'
          .' data-post="'.esc_attr($canonical).'" data-return="'.esc_attr((int)$post->ID).'"'
          .' data-expected="'.esc_attr(self::sha(self::gallery($canonical))).'"'
          .' data-nonce="'.esc_attr(wp_create_nonce('mad4b_gallery_apply_'.$canonical)).'">';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:12px">';
        foreach(array_slice($items,$start,$per) as $item){
            $aid=(int)$item['attachment_id'];$index=(int)$item['index'];
            $thumbnail=wp_get_attachment_image_url($aid,'thumbnail') ?: (string)$item['url'];
            $label=wp_strip_all_tags(get_the_title($aid));
            echo '<label style="display:block;border:1px solid #d0d5dc;padding:7px;border-radius:8px;cursor:pointer">';
            echo '<img loading="lazy" src="'.esc_url($thumbnail).'" alt="'.esc_attr($label).'" style="width:100%;height:90px;object-fit:cover;border-radius:4px">';
            echo '<span style="display:flex;gap:6px;align-items:center"><input type="checkbox" data-mad4b-index="'.esc_attr($index).'"> Photo '.esc_html($index).'</span>';
            echo '</label>';
        }
        echo '</div><p><button type="button" class="button button-primary mad4b-gallery-append">Add selected photos to full gallery</button></p></div>';
        echo <<<'JS'
<script>
(function () {
 document.addEventListener('click', function (event) {
  const button=event.target.closest('.mad4b-gallery-append');
  if (!button) return;
  const root=button.closest('[data-mad4b-gallery-repair]');
  if (!root) return;
  const selected=[...root.querySelectorAll('input[data-mad4b-index]:checked')].map(el=>el.getAttribute('data-mad4b-index'));
  if (!selected.length) {window.alert('Select at least one source photo.');return;}
  const form=document.createElement('form');form.method='post';form.action=root.dataset.endpoint;
  const fields={action:'mad4b_gallery_source_apply',post_id:root.dataset.post,return_id:root.dataset.return,
    expected_sha256:root.dataset.expected,mad4b_gallery_nonce:root.dataset.nonce};
  selected.forEach(v=>{const el=document.createElement('input');el.type='hidden';el.name='indexes[]';el.value=v;form.appendChild(el)});
  Object.entries(fields).forEach(([name,value])=>{const el=document.createElement('input');el.type='hidden';el.name=name;el.value=value;form.appendChild(el)});
  document.body.appendChild(form);form.submit();
 });
})();
</script>
JS;

        if($pages>1){echo '<p>Source pages: ';
          for($i=0;$i<$pages;$i++){
            $link=add_query_arg(['post'=>(int)$post->ID,'action'=>'edit','mad4b_photo_page'=>$i],admin_url('post.php'));
            echo '<a style="margin-right:8px" href="'.esc_url($link).'">'.esc_html((string)($i+1)).'</a>';
          }echo '</p>';
        }
    }
    public static function admin_apply():void {
        $id=absint($_POST['post_id']??0);$return=absint($_POST['return_id']??0);
        if(!$id||!$return||!current_user_can('edit_post',$id)||!current_user_can('edit_post',$return))wp_die('Insufficient permissions');
        check_admin_referer('mad4b_gallery_apply_'.$id,'mad4b_gallery_nonce');
        $values=isset($_POST['indexes'])?(array)wp_unslash($_POST['indexes']):[];
        $indexes=implode(',',array_map('intval',$values));
        $expected=sanitize_text_field(wp_unslash($_POST['expected_sha256']??''));
        $status='applied';
        try{
            ob_start();self::apply($id,$indexes,$expected);ob_end_clean();
        }catch(Throwable $e){if(ob_get_level())ob_end_clean();$status='error';}
        wp_safe_redirect(add_query_arg(['post'=>$return,'action'=>'edit','mad4b_gallery_status'=>$status],admin_url('post.php')));
        exit;
    }
    public static function cli(array $args,array $assoc):void {
        $action=(string)($args[0]??'');$id=absint($args[1]??0);
        switch($action){
            case 'export':self::source_export($id);break;
            case 'stage':self::stage($id,(string)($assoc['manifest']??''));break;
            case 'plan':self::plan($id,(int)($assoc['limit']??24));break;
            case 'apply':self::apply($id,(string)($assoc['indexes']??''),(string)($assoc['expected-sha256']??''));break;
            case 'rollback':self::rollback((string)($args[1]??''));break;
            default:self::fail('usage_export_stage_plan_apply_rollback');
        }
    }
}
if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b gallery-source',[MAD4B_Tour_Gallery_Source_Repair_V1::class,'cli']);
add_action('add_meta_boxes_tours-and-activities', static function ():void {
    add_meta_box('mad4b-tour-source-gallery', 'MAD4B · Repair gallery from original source',
      [MAD4B_Tour_Gallery_Source_Repair_V1::class,'admin_box'],'tours-and-activities','normal','low');
});
add_action('admin_post_mad4b_gallery_source_apply',
    [MAD4B_Tour_Gallery_Source_Repair_V1::class,'admin_apply']);
