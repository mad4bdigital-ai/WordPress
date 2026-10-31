<?php
// Staging-only WP-CLI design migration candidate. PHP is not loaded by web requests.
if(wp_get_environment_type()!=='staging'||wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')throw new RuntimeException('wrong_environment');
$targets=[25102=>[35070=>'fr',35076=>'de'],33747=>[33749=>'fr',33751=>'de']];
function md_get($id){$raw=get_post_meta($id,'_elementor_data',true);$d=json_decode((string)$raw,true);if(!is_array($d))throw new RuntimeException('invalid Elementor data:'.$id);return $d;}
function md_walk($items,&$out){foreach($items as $item){$out[$item['id']]=$item;md_walk($item['elements']??[],$out);}}
function md_rewrite($items,$source,$target,$locale,$old){
 $mapped=[];
 $pairs=$source===25102?['e446b68'=>'e446b68','edc0a21'=>'5ee5d7b','65a676b'=>'2c82390','go25102'=>'go'.$target]:
 ['1b772ff'=>'1b772ff','bb85bd8'=>'bb85bd8','d130545'=>'d130545','56c59488'=>null];
 foreach($items as $item){
   $sid=$item['id'];$oldid=$pairs[$sid]??$sid;
   if($oldid&&isset($old[$oldid])){
     $os=$old[$oldid]['settings']??[];$ns=$item['settings']??[];
     // Only translatable content, not CSS, layout, media, bindings, or metadata.
     foreach(['link_label','delete_link_dialog','dynamic_field_format'] as $k){
       if(array_key_exists($k,$ns)&&array_key_exists($k,$os))$ns[$k]=$os[$k];
     }
     // The rate Dynamic Tag stays bound, with localized dynamic configuration if equal tag class.
     if(in_array($sid,['65a676b','d130545'],true) &&
          str_contains((string)($os['__dynamic__']['title']??''),'tour-component-price') &&
          str_contains((string)($ns['__dynamic__']['title']??''),'tour-component-price')){
       $ns['__dynamic__']['title']=$os['__dynamic__']['title'];
     }
     $item['settings']=$ns;
   }
   if($sid==='go25102')$item['id']='go'.$target;
   $item['elements']=md_rewrite($item['elements']??[],$source,$target,$locale,$old);
   $mapped[]=$item;
 }
 return $mapped;
}
$out=[];
foreach($targets as $source=>$bylang){$src=md_get($source);$srcitems=[];md_walk($src,$srcitems);
foreach($bylang as $target=>$locale){
 if(getenv('MAD4B_SYNC_TARGET') && (int)getenv('MAD4B_SYNC_TARGET')!==(int)$target)continue;
 $mapped=(int)apply_filters('wpml_object_id',$source,get_post_type($source),false,$locale);
 if($mapped!==$target)throw new RuntimeException('wpml mapping changed:'.$source.'/'.$locale);
 $orig=md_get($target);$old=[];md_walk($orig,$old);
 if($source===25102 && count($old)!==19)throw new RuntimeException('unexpected old list structure');
 if($source===33747 && count($old)!==20)throw new RuntimeException('unexpected old grid structure');
 $candidate=md_rewrite($src,$source,$target,$locale,$old);$new=[];md_walk($candidate,$new);
 $require=$source===25102?['803041a','65a676b','30e3360','edc0a21']:['1f78f38','d130545','12ad8f7','bb85bd8'];
 foreach($require as $id)if(!isset($new[$id]))throw new RuntimeException('lost native UI widget:'.$id);
 $paired=$source===25102?['edc0a21'=>'5ee5d7b','e446b68'=>'e446b68','65a676b'=>'2c82390']:['bb85bd8'=>'bb85bd8','1b772ff'=>'1b772ff','d130545'=>'d130545'];
 $checks=[];
 foreach($paired as $newid=>$oldid){$ns=$new[$newid]['settings']??[];$os=$old[$oldid]['settings']??[];
  foreach(['link_label','dynamic_field_format'] as $key)if(isset($os[$key])&&isset($ns[$key])){
   if($ns[$key]!==$os[$key])throw new RuntimeException('translation lost:'.$key);
   $checks[$newid.'.'.$key]=$ns[$key];
  }
  if(in_array($newid,['65a676b','d130545'],true) && !str_contains((string)($ns['__dynamic__']['title']??''),'tour-component-price'))throw new RuntimeException('price tag lost');
 }
 if(getenv('MAD4B_APPLY')==='1'){
    $expected=['35070'=>'7fba0177d3e1e9ae96113eb1979975699c6cc7b1e293cf600ce268ea13418f9b',
               '35076'=>'f897fe92f5a6b1f34ca881ce1a3e9876a72434d6179b76fd3c0dd465d7327440',
               '33749'=>'1f95ccc8bc72f16e49039a2232b784373048245b7da23cfdfb2ba84c0a930b6d',
               '33751'=>'03cd2f4695fc4ffb071754e9dc7c318ff3948334a99bdaab8eaf1847d435b450'];
    if(hash('sha256',(string)get_post_meta($target,'_elementor_data',true))!==$expected[$target])
       throw new RuntimeException('concurrent_edit_detected_for_'.$target);
    $encoded=wp_json_encode($candidate,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($encoded) || strlen($encoded)<20000)throw new RuntimeException('invalid_design_candidate_'.$target);
    $backup=json_decode(file_get_contents('/tmp/mad4b-meta-owned.json'),true);
    $original=$backup['templates'][(string)$target]['meta']??null;
    if(!is_array($original) || hash('sha256',(string)$original['_elementor_data'])!==$expected[$target])
       throw new RuntimeException('protected_backup_unavailable_'.$target);
    try{
        update_post_meta($target,'_elementor_data',wp_slash($encoded));
        $actual=md_get($target);$actualEls=[];md_walk($actual,$actualEls);
        $semanticMatch=($actual==$candidate);
        if(count($actualEls)!==count($new) || !$semanticMatch){
          $diag=['target'=>$target,'semantic_match'=>$semanticMatch,'count'=>count($actualEls),
            'expected_sha'=>hash('sha256',$encoded),'actual_sha'=>hash('sha256',(string)get_post_meta($target,'_elementor_data',true))];
          fwrite(STDERR,'DESIGN_MISMATCH '.json_encode($diag)."\n");
          throw new RuntimeException('post_write_semantic_mismatch_'.$target);
        }
        $class=\Elementor\Core\Files\CSS\Post::class;
        (new $class($target))->update();
        $postcss=get_post_meta($target,'_elementor_css',true);
        if(!is_array($postcss) || strlen((string)($postcss['css']??''))<10000)
           throw new RuntimeException('generated_style_missing_'.$target);
    } catch(Throwable $e) {
        // Restore through the same validated WP metadata API, never raw SQL.
        update_post_meta($target,'_elementor_data',wp_slash($original['_elementor_data']));
        update_post_meta($target,'_elementor_css',$original['_elementor_css']);
        $restored=hash('sha256',(string)get_post_meta($target,'_elementor_data',true))===$expected[$target];
        fwrite(STDERR,'MIGRATION_ROLLBACK '.json_encode(['target'=>$target,'restored'=>$restored])."\n");
        throw $e;
    }
 }
 $out[]=['source'=>$source,'target'=>$target,'locale'=>$locale,'source_elements'=>count($srcitems),'old_elements'=>count($old),'candidate_elements'=>count($new),'old_sha256'=>hash('sha256',(string)get_post_meta($target,'_elementor_data',true)),'translation_checks'=>$checks,'new_widgets'=>array_values(array_diff(array_keys($new),array_keys($old)))];
}
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
