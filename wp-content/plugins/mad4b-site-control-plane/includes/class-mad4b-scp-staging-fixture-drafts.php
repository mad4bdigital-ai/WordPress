<?php
/** Dedicated private WP draft records: no commerce, sales, public URLs or real Context registration. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Staging_Fixture_Drafts {
    const SOURCE='mad4b_trial_src';
    const ASSET='mad4b_trial_asset';
    const META_CLASS='_mad4b_data_classification';
    const META_TEST='_mad4b_is_test_data';
    const META_SITE='_mad4b_fixture_site_uuid';
    const META_BRAND='_mad4b_fixture_brand_id';
    const META_KEY='_mad4b_fixture_key';
    const META_SOURCE='_mad4b_fixture_source_id';
    const META_LOCK='_mad4b_fixture_lock';
    private static $provisioning=false;
    private static function restrictedTypes():array {return [self::SOURCE,self::ASSET];}
    private static function protectedMetaKeys():array {
        return [self::META_CLASS,self::META_TEST,self::META_SITE,self::META_BRAND,self::META_KEY,self::META_SOURCE,self::META_LOCK];
    }
    public static function boot():void {
        if(wp_get_environment_type()!=='staging')return;
        add_action('init',[__CLASS__,'registerPostTypes'],20);
        add_filter('wp_insert_post_data',[__CLASS__,'guardPostLifecycle'],999,2);
        add_filter('add_post_metadata',[__CLASS__,'guardMetadataAdd'],20,5);
        add_filter('update_post_metadata',[__CLASS__,'guardMetadataUpdate'],20,5);
        add_filter('delete_post_metadata',[__CLASS__,'guardMetadataDelete'],20,5);
    }
    public static function registerPostTypes():void {
        foreach([self::SOURCE=>'TEST Context Sources',self::ASSET=>'TEST Context Assets'] as$type=>$label) {
            register_post_type($type,[
                'label'=>$label,'description'=>'Private, synthetic Staging-only Drafts; never real Context assets',
                'public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,
                'show_ui'=>false,'show_in_menu'=>false,'show_in_rest'=>false,
                'has_archive'=>false,'rewrite'=>false,'query_var'=>false,
                'capability_type'=>'post','map_meta_cap'=>true,
                'supports'=>['title','editor'],
            ]);
        }
    }
    public static function guardPostLifecycle(array $data,array $postarr):array {
        $original=null;
        if(!empty($postarr['ID']))$original=get_post((int)$postarr['ID']);
        $restricted=is_array($data)&&in_array($data['post_type']??'',self::restrictedTypes(),true);
        if($original && in_array($original->post_type,self::restrictedTypes(),true)){
            $restricted=true;
            // Cannot turn a test draft into a normal WP post by changing post_type.
            $data['post_type']=$original->post_type;
        }
        if($restricted){
            $data['post_status']=($data['post_status']??'')==='trash'?'trash':'draft';
            $data['comment_status']='closed';$data['ping_status']='closed';
            $data['post_password']='';
        }
        return $data;
    }
    private static function locked($postId,$key):bool {
        if(!in_array($key,self::protectedMetaKeys(),true))return false;
        $post=get_post((int)$postId);
        return $post && in_array($post->post_type,self::restrictedTypes(),true) && !self::$provisioning;
    }
    public static function guardMetadataAdd($check,$id,$key,$value,$unique){
        return self::locked($id,(string)$key)?false:$check;
    }
    public static function guardMetadataUpdate($check,$id,$key,$value,$previous){
        return self::locked($id,(string)$key)?false:$check;
    }
    public static function guardMetadataDelete($check,$id,$key,$value,$deleteAll){
        return self::locked($id,(string)$key)?false:$check;
    }
    private static function draftRows(string $postType,string $key):array {
        return get_posts(['post_type'=>$postType,'post_status'=>['draft','publish','private','pending','future','trash'],
            'posts_per_page'=>-1,'fields'=>'ids','suppress_filters'=>true,
            'meta_query'=>[['key'=>self::META_KEY,'value'=>$key,'compare'=>'=']]]);
    }
    public static function report() {
        $catalog=MAD4B_SCP_Staging_Context_Fixtures::catalog();
        if(is_wp_error($catalog))return $catalog;
        $rows=[];$errors=[];
        foreach(['sources'=>self::SOURCE,'assets'=>self::ASSET] as$collection=>$type){
            foreach($catalog[$collection] as$fixture){
                $key=$collection==='sources'?$fixture['source_key']:$fixture['asset_key'];
                $posts=self::draftRows($type,$key);
                $item=['key'=>$key,'post_type'=>$type,'post_id'=>count($posts)===1?(int)$posts[0]:null,
                    'found_count'=>count($posts),'post_status'=>count($posts)===1?get_post_status($posts[0]):null];
                if(count($posts)===1){
                    $id=(int)$posts[0];
                    $item['is_test_data']=(string)get_post_meta($id,self::META_TEST,true)==='1';
                    $item['data_classification']=(string)get_post_meta($id,self::META_CLASS,true);
                    $item['site_match']=(string)get_post_meta($id,self::META_SITE,true)===$catalog['site_uuid'];
                    $item['brand_match']=(string)get_post_meta($id,self::META_BRAND,true)===$catalog['brand_id'];
                    $item['meta_locked']=(string)get_post_meta($id,self::META_LOCK,true)==='1';
                    if($item['post_status']!=='draft'||!$item['is_test_data']||$item['data_classification']!=='test_only'||
                        !$item['site_match']||!$item['brand_match']||!$item['meta_locked'])$errors[]='invalid_test_draft_'.$key;
                }elseif(count($posts)>1)$errors[]='duplicate_test_draft_'.$key;
                $rows[]=$item;
            }
        }
        return ['contract'=>'mad4b.test-draft-readback.v1','site_uuid'=>$catalog['site_uuid'],
            'brand_id'=>$catalog['brand_id'],'test_only'=>true,'expected_drafts'=>6,
            'existing_drafts'=>count(array_filter($rows,static fn($r)=>$r['found_count']===1)),
            'rows'=>$rows,'errors'=>$errors,'production_enabled'=>false,'external_send_allowed'=>false];
    }
    /** Internal, explicit QA provisioning, never a public REST write or normal WP lifecycle action. */
    public static function provisionForStagingQa() {
        if(PHP_SAPI!=='cli'||!defined('MAD4B_STAGING_QA_DRAFT_PROVISION')||
            MAD4B_STAGING_QA_DRAFT_PROVISION!==true)
            return new WP_Error('mad4b_test_draft_provision_not_authorized','Explicit staging QA CLI approval required');
        $catalog=MAD4B_SCP_Staging_Context_Fixtures::catalog();
        if(is_wp_error($catalog))return $catalog;
        $before=self::report();if(is_wp_error($before))return $before;
        if($before['errors'])return new WP_Error('mad4b_test_draft_conflict','Existing trial records fail isolation checks');
        $created=[];$sourceIds=[];
        try{
            self::$provisioning=true;
            foreach(['sources'=>self::SOURCE,'assets'=>self::ASSET] as$group=>$type){
                foreach($catalog[$group] as$fixture){
                    $key=$group==='sources'?$fixture['source_key']:$fixture['asset_key'];
                    $existing=self::draftRows($type,$key);
                    if(count($existing)>1)throw new RuntimeException('ambiguous fixture identity');
                    if($existing){$id=(int)$existing[0];}
                    else{
                        $title=$group==='sources'?$fixture['label']:$fixture['title'];
                        $body=$group==='sources'?'Synthetic source authority for test preview only.':$fixture['content'];
                        $id=wp_insert_post(['post_type'=>$type,'post_status'=>'draft',
                            'post_title'=>$title,'post_content'=>$body,
                            'post_excerpt'=>'TEST DATA — NON-PUBLISHABLE',
                            'comment_status'=>'closed','ping_status'=>'closed'],true);
                        if(is_wp_error($id)||!$id)throw new RuntimeException('cannot create isolated draft');
                        $id=(int)$id;$created[]=$id;
                        $meta=[self::META_TEST=>'1',self::META_CLASS=>'test_only',
                            self::META_BRAND=>$catalog['brand_id'],self::META_SITE=>$catalog['site_uuid'],
                            self::META_KEY=>$key,self::META_LOCK=>'1'];
                        if($group==='assets')$meta[self::META_SOURCE]=(string)($sourceIds[$fixture['source_id']]??'');
                        foreach($meta as$mkey=>$val){
                            if($mkey===self::META_SOURCE && $val==='')throw new RuntimeException('missing test-only parent source');
                            if(!add_post_meta($id,$mkey,$val,true))throw new RuntimeException('test meta persist failed');
                        }
                    }
                    if($group==='sources')$sourceIds[$fixture['source_id']]=$id;
                    if(get_post_status($id)!=='draft')throw new RuntimeException('draft readback mismatch');
                }
            }
        }catch(Throwable $e){
            foreach(array_reverse($created) as$id)wp_delete_post($id,true);
            return new WP_Error('mad4b_test_draft_provision_rolled_back','Trial draft provisioning failed and new posts were removed');
        }finally{self::$provisioning=false;}
        $after=self::report();
        if(is_wp_error($after)||$after['errors']||$after['existing_drafts']!==6)
            return new WP_Error('mad4b_test_draft_readback_unverified','Draft provisioning requires operator review');
        return ['status'=>'PASS','created_draft_posts'=>count($created),'total_test_drafts'=>6,
            'data_classification'=>'test_only','post_status'=>'draft',
            'site_uuid'=>$catalog['site_uuid'],'brand_id'=>$catalog['brand_id'],
            'external_registry_mutation'=>false,'email_sent'=>false];
    }
}
MAD4B_SCP_Staging_Fixture_Drafts::boot();
