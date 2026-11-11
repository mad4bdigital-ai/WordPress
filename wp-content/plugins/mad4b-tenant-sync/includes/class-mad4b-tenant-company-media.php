<?php
/** Tenant-origin image delivery and independently reviewed per-company media SEO.
 * Shared-hub binary ingest is intentionally NOT implemented or enabled here. */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Company_Media {
    const CONTRACT='mad4b.tenant-company-domain-media.v1';
    const SCHEMA='mad4b_tenant_media_seo_schema_v1';
    const ROUTE='mad4b-company-media';
    const FIELDS=['alt','title','caption','description','meta_description'];
    const SIZES=['thumbnail','medium','medium_large','large','full'];
    private static function site(){
        if(!class_exists('MAD4B_Tenant_Sync'))return '';
        $p=MAD4B_Tenant_Sync::profile();
        return !empty($p['ready'])?(string)$p['site_uuid']:'';
    }
    private static function language($lang=''){
        $lang=strtolower((string)$lang);
        if($lang==='' && defined('ICL_SITEPRESS_VERSION')) {
            $active=apply_filters('wpml_current_language',null);
            if(is_string($active))$lang=strtolower($active);
        }
        if($lang==='')$lang=strtolower(substr((string)get_locale(),0,2));
        return preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/D',$lang)?$lang:'';
    }
    private static function media($id){
        $id=absint($id);
        $p=$id?get_post($id):null;
        if(!$p||$p->post_type!=='attachment'||!wp_attachment_is_image($id))return null;
        $mime=(string)get_post_mime_type($id);
        if(!in_array($mime,['image/jpeg','image/png','image/gif','image/webp','image/avif'],true))
            return null;
        if($p->post_parent){
            $parent=get_post($p->post_parent);
            if(!$parent||!is_post_publicly_viewable($parent))return null;
        }
        if(!in_array($p->post_status,['inherit','publish'],true))return null;
        return $p;
    }
    public static function table(){
        global $wpdb;return $wpdb->prefix.'mad4b_tenant_media_seo';
    }
    public static function install(){
        if(!self::site()||!MAD4B_Tenant_Operation_Authority::schema(
            'tenant.media.schema',[self::table()]))return false;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        global $wpdb;
        $t=self::table();$collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          site_uuid varchar(64) NOT NULL,
          attachment_id bigint(20) unsigned NOT NULL,
          language varchar(16) NOT NULL,
          source_sha char(64) NOT NULL,
          meta_json longtext NOT NULL,
          status varchar(20) NOT NULL,
          prior_id bigint(20) unsigned NOT NULL DEFAULT 0,
          proposed_by bigint(20) unsigned NOT NULL,
          reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
          created_utc datetime NOT NULL,
          updated_utc datetime NOT NULL,
          PRIMARY KEY  (id),
          KEY company_attachment_lang (site_uuid,attachment_id,language,status)
        ) $collate;");
        $ok=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))===$t;
        if($ok)update_option(self::SCHEMA,'1',false);
        return $ok;
    }
    private static function ready(){
        global $wpdb; $t=self::table();
        return get_option(self::SCHEMA)==='1'
         && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))===$t;
    }
    private static function native($id){
        $p=get_post($id);
        return [
          'alt'=>(string)get_post_meta($id,'_wp_attachment_image_alt',true),
          'title'=>(string)$p->post_title,
          'caption'=>(string)$p->post_excerpt,
          'description'=>(string)$p->post_content,
          'meta_description'=>''
        ];
    }
    private static function source_sha($id){
        $p=get_post($id);
        $file=get_attached_file($id);
        return hash('sha256',wp_json_encode([
            'attachment_id'=>(int)$id,'file_relative' => (string)get_post_meta($id,'_wp_attached_file',true),
            'mime'=>(string)$p->post_mime_type,
            'post_modified_gmt'=>(string)$p->post_modified_gmt,
            'native'=>self::native($id)
        ]));
    }
    private static function active($id,$lang){
        global $wpdb;
        if(!self::ready())return null;
        $site=self::site();
        if(!$site)return null;
        $t=self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE site_uuid=%s
            AND attachment_id=%d AND language=%s AND status='applied' ORDER BY id DESC LIMIT 1",
            $site,$id,$lang),ARRAY_A) ?: null;
    }
    public static function brand(){
        // A staging test brand is not the company's approved identity.
        // Never stamp shared images with a demo brand merely because an
        // unrelated communication provider reports a valid draft context.
        if(!class_exists('MAD4B_SCP_Context_Authority') ||
           !class_exists('MAD4B_SCP_Company_Communication'))return '';
        $profile=MAD4B_SCP_Context_Authority::profile();
        if(!is_array($profile)||($profile['status']??'')==='test_only'
            || ($profile['site_uuid']??'')!==self::site())return '';
        $identity=MAD4B_SCP_Company_Communication::brandIdentity();
        return !empty($identity['valid'])?(string)$identity['brand_name']:'';
    }
    private static function tokens($value){
        if(!str_contains((string)$value,'{brand}'))return (string)$value;
        $brand=self::brand();
        return $brand!==''?str_replace('{brand}',$brand,(string)$value):'';
    }
    public static function preview($id,$lang=''){
        $p=self::media($id);
        $site=self::site();$lang=self::language($lang);
        if(!$p||!$site||!$lang)return new WP_Error('mad4b_media_not_public_or_unbound');
        $raw=self::native($id);$active=self::active((int)$id,$lang);
        $source=self::source_sha($id);
        $stored=$active?json_decode($active['meta_json'],true):null;
        if(is_array($stored) && hash_equals((string)$active['source_sha'],$source))
          foreach(self::FIELDS as $key)
            if(array_key_exists($key,$stored))$raw[$key]=(string)$stored[$key];
        $rendered=[];
        foreach($raw as $key=>$val)$rendered[$key]=self::tokens($val);
        return [
            'contract'=>self::CONTRACT,'site_uuid'=>$site,'language'=>$lang,
            'attachment_id'=>(int)$id,'source_sha'=>$source,
            'unverified_source_media_ref'=>(string)get_post_meta($id,'_mad4b_verified_shared_media_id',true),
            'signed_hub_mapping_certified'=>false,
            'local_media_only'=>true,'shared_binary_ingest_enabled'=>false,
            'company_domain_url'=>self::url($id,'full'),
            'file_metadata_review_required'=>(static function()use($id){
                $file=self::resolve_file($id,'full');
                return !is_wp_error($file)&&!empty($file['extension_mime_mismatch']);
            })(),
            'brand_context_valid'=>self::brand()!=='',
            'inherited'=>self::native($id),
            'effective'=>$rendered,
            'overrides_effective'=>(bool)$stored && is_array($stored) &&
                hash_equals((string)$active['source_sha'],$source),
            'current_revision'=>(int)($active['id']??0),
            'needs_source_review'=>$active&&!hash_equals((string)$active['source_sha'],$source)
        ];
    }
    public static function url($id,$size='full'){
        if(!self::site()||!self::media($id)||!in_array($size,self::SIZES,true))return '';
        return home_url('/'.self::ROUTE.'/'.absint($id).'/'.rawurlencode($size).'/');
    }
    public static function resolve_file($id,$size='full'){
        if(!self::site()||!self::media($id)||!in_array($size,self::SIZES,true))
            return new WP_Error('mad4b_media_unavailable');
        $upload=wp_upload_dir();
        $root=realpath($upload['basedir']);
        $file=get_attached_file($id);
        if($size!=='full'){
            $meta=wp_get_attachment_metadata($id);
            $partial=$meta['sizes'][$size]['file']??null;
            if(!is_string($partial)||$partial==='')return new WP_Error('mad4b_media_size_missing');
            $file=dirname($file).'/'.$partial;
        }
        $resolved=realpath($file);
        if(!$root||!$resolved||!str_starts_with($resolved,$root.DIRECTORY_SEPARATOR)
            ||!is_file($resolved))return new WP_Error('mad4b_media_file_not_local');
        $type=wp_check_filetype($resolved);
        $allow=['image/jpeg','image/png','image/webp','image/gif','image/avif'];
        // Some migrated files contain valid AVIF bytes under historical .jpg
        // filenames. Never trust the extension over the actual file contents.
        $actual=function_exists('mime_content_type')?mime_content_type($resolved):'';
        if(!in_array($actual,$allow,true))
            return new WP_Error('mad4b_media_type_untrusted');
        $meta=wp_get_attachment_metadata($id);
        $registered=$size==='full'?(string)get_post_mime_type($id):
            (string)($meta['sizes'][$size]['mime-type']??'');
        $declaredMismatch=$registered!==$actual;
        // Older image processors may keep the original AVIF MIME in every
        // thumbnail record, while the actual thumbnail file is a valid JPEG.
        // Allow this ONLY for a registered WordPress-generated intermediate,
        // when the extension agrees with inspected bytes; flag it for repair.
        if($declaredMismatch && ($size==='full'||($type['type']??'')!==$actual))
            return new WP_Error('mad4b_media_mime_mismatch');
        return ['path'=>$resolved,'mime'=>$actual,'bytes'=>filesize($resolved),
          'extension_mime_mismatch'=>($type['type']??'')!==$actual,
          'declared_mime_mismatch'=>$declaredMismatch,
          'site_uuid'=>self::site(),'url'=>self::url($id,$size)];
    }
    public static function rewrite(){
        add_rewrite_tag('%mad4b_company_media%','([0-9]+)');
        add_rewrite_tag('%mad4b_company_size%','([a-z_]+)');
        add_rewrite_rule('^'.self::ROUTE.'/([0-9]+)/([a-z_]+)/?$',
            'index.php?mad4b_company_media=$matches[1]&mad4b_company_size=$matches[2]','top');
    }
    public static function stream(){
        $id=absint(get_query_var('mad4b_company_media'));
        if(!$id)return;
        $size=(string)get_query_var('mad4b_company_size','full');
        $f=self::resolve_file($id,$size);
        if(is_wp_error($f)){status_header(404);nocache_headers();exit;}
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){
            status_header(405);exit;
        }
        header('Content-Type: '.$f['mime']);
        header('Content-Length: '.$f['bytes']);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        header('Cross-Origin-Resource-Policy: same-site');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($f['path']);
        exit;
    }
    public static function image($atts){
        $atts=shortcode_atts(['id'=>0,'size'=>'large','language'=>'','schema'=>'0'],
            $atts,'mad4b_company_image');
        $id=absint($atts['id']);$size=sanitize_key($atts['size']);
        $p=self::preview($id,sanitize_key($atts['language']));
        if(is_wp_error($p)||!in_array($size,self::SIZES,true))return '';
        $src=self::resolve_file($id,$size);
        if(is_wp_error($src))return '';
        $meta=wp_get_attachment_metadata($id);
        $width=(int)($size==='full'?($meta['width']??0):($meta['sizes'][$size]['width']??0));
        $height=(int)($size==='full'?($meta['height']??0):($meta['sizes'][$size]['height']??0));
        $srcset=[];
        foreach(self::SIZES as $variant){
            $w=$variant==='full'?(int)($meta['width']??0):
                (int)($meta['sizes'][$variant]['width']??0);
            if($w>0 && !is_wp_error(self::resolve_file($id,$variant)))
                $srcset[$w]=self::url($id,$variant).' '.$w.'w';
        }
        ksort($srcset,SORT_NUMERIC);
        $m=$p['effective'];
        $html='<figure class="mad4b-tenant-branded-media">'
             .'<img src="'.esc_url($src['url']).'"'
             .($srcset?' srcset="'.esc_attr(implode(', ',$srcset)).'" sizes="(max-width: 768px) 100vw, 768px"':'')
             .($width?' width="'.$width.'"':'').($height?' height="'.$height.'"':'')
             .' alt="'.esc_attr($m['alt']).'" title="'.esc_attr($m['title']).'"'
             .' loading="lazy" decoding="async">';
        if($m['caption']!=='')$html.='<figcaption>'.esc_html($m['caption']).'</figcaption>';
        $html.='</figure>';
        if($atts['schema']==='1') {
            $schema=['@context'=>'https://schema.org','@type'=>'ImageObject',
                'contentUrl'=>$src['url'],'name'=>$m['title'],
                'description'=>$m['meta_description']!==''?$m['meta_description']:$m['description']];
            if($p['brand_context_valid'])$schema['creditText']=self::brand();
            $html.='<script type="application/ld+json">'.wp_json_encode($schema,
                JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE).
                '</script>';
        }
        return $html;
    }
    public static function permission($id){
        return get_current_user_id()>0 && current_user_can('upload_files') &&
          current_user_can('edit_post',(int)$id) &&
          (current_user_can('mad4b_propose_company_content')||current_user_can('manage_options'));
    }
    private static function error($code,$status=409){
        return new WP_Error($code,'Company media review requirement not met',['status'=>$status]);
    }
    public static function propose($id,$lang,$fields,$source,$expected){
        $site=self::site();$lang=self::language($lang);
        if(!$site||!$lang||!self::ready()||!self::media($id) || !self::permission($id))
            return self::error('mad4b_media_proposer_denied',403);
        if(!is_array($fields)||!$fields||count($fields)>5)
            return self::error('mad4b_media_fields_required',400);
        $clean=[];
        foreach($fields as $key=>$value){
            if(!in_array($key,self::FIELDS,true)||!is_string($value)
                ||strlen($value)>600 || str_contains($value,'<'))
                return self::error('mad4b_media_metadata_invalid',400);
            $clean[$key]=trim(sanitize_text_field($value));
        }
        $sourceNow=self::source_sha($id);
        if(!is_string($source)||!hash_equals($sourceNow,$source))
            return self::error('mad4b_media_source_drift');
        if(in_array('{brand}',array_map('strval',$clean),true) && !self::brand())
            return self::error('mad4b_media_brand_context_missing');
        foreach($clean as $v)
            if(str_contains($v,'{brand}') && !self::brand())
                return self::error('mad4b_media_brand_context_missing');
        global $wpdb;$t=self::table();
        $active=self::active($id,$lang);
        if((int)($active['id']??0)!==(int)$expected)
            return self::error('mad4b_media_revision_drift');
        $pending=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t
            WHERE site_uuid=%s AND attachment_id=%d AND language=%s AND status='pending'",
            $site,$id,$lang));
        if($pending)return self::error('mad4b_media_pending_exists');
        $now=current_time('mysql',true);
        $ok=$wpdb->insert($t,[
            'site_uuid'=>$site,'attachment_id'=>$id,'language'=>$lang,
            'source_sha'=>$sourceNow,'meta_json'=>wp_json_encode($clean),
            'status'=>'pending','prior_id'=>(int)$expected,
            'proposed_by'=>get_current_user_id(),'created_utc'=>$now,'updated_utc'=>$now]);
        if(!$ok)return self::error('mad4b_media_insert_failed',500);
        $idRow=(int)$wpdb->insert_id;
        $audit=MAD4B_SCP_Audit::record('mad4b/company-media-proposed',[
            'site_uuid'=>$site,'attachment_id'=>$id,
            'proposal_id'=>$idRow,'language'=>$lang,'field_keys'=>array_keys($clean),
            'actor_id'=>get_current_user_id()],'intent');
        if(is_wp_error($audit)){
            $wpdb->delete($t,['id'=>$idRow,'site_uuid'=>$site]);
            return $audit;
        }
        return ['status'=>'pending','proposal_id'=>$idRow,
            'company_domain_url'=>self::url($id),'image_file_modified'=>false];
    }
    public static function review($proposal_id,$approve){
        if(!class_exists('MAD4B_SCP_Company_Owner_Activation') ||
           !MAD4B_SCP_Company_Owner_Activation::browserOwnerAuthorized())
            return self::error('mad4b_media_owner_browser_required',403);
        $site=self::site();
        if(!$site||!self::ready())return self::error('mad4b_media_site_unready');
        global $wpdb;$t=self::table();
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE site_uuid=%s
            AND id=%d AND status='pending'",$site,(int)$proposal_id),ARRAY_A);
        if(!$r)return self::error('mad4b_media_proposal_missing',404);
        if((int)$r['proposed_by']===get_current_user_id())
            return self::error('mad4b_media_four_eyes',403);
        if(!in_array($approve,['approve','reject'],true))
            return self::error('mad4b_media_review_invalid',400);
        $active=self::active((int)$r['attachment_id'],$r['language']);
        if($approve==='approve' && (
            (int)($active['id']??0)!==(int)$r['prior_id'] ||
            !hash_equals($r['source_sha'],self::source_sha((int)$r['attachment_id']))
        ))return self::error('mad4b_media_revision_or_source_changed');
        $wpdb->query('START TRANSACTION');
        try {
            if($approve==='approve'&&$active){
                if($wpdb->update($t,['status'=>'superseded'],[
                    'id'=>(int)$active['id'],'site_uuid'=>$site,'status'=>'applied'])!==1)
                    throw new RuntimeException('Previous active profile changed');
            }
            $state=$approve==='approve'?'applied':'rejected';
            $ok=$wpdb->update($t,[
                'status'=>$state,'reviewed_by'=>get_current_user_id(),
                'updated_utc'=>current_time('mysql',true)],
                ['id'=>(int)$r['id'],'site_uuid'=>$site,'status'=>'pending']);
            if($ok!==1)throw new RuntimeException('Proposal has changed');
            $audit=MAD4B_SCP_Audit::record('mad4b/company-media-reviewed',[
                'site_uuid'=>$site,'proposal_id'=>(int)$r['id'],
                'attachment_id'=>(int)$r['attachment_id'],
                'actor_id'=>get_current_user_id(),'decision'=>$state],'ok');
            if(is_wp_error($audit))throw new RuntimeException('Audit failed');
            $wpdb->query('COMMIT');
            return ['status'=>$state,'proposal_id'=>(int)$r['id'],
                'native_attachment_modified'=>false,'shared_hub_modified'=>false];
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');
            return self::error('mad4b_media_review_rolled_back',500);
        }
    }
    public static function admin(){
        if(!current_user_can('upload_files') &&
            !current_user_can('manage_options'))wp_die('Not permitted',403);
        $id=absint($_GET['media_id']??0);
        echo '<div class="wrap"><h1>Company Media SEO & Domain URLs</h1>';
        echo '<p>Brand metadata lives on this company, never on the shared image source. '
           .'Only reviewed image overrides are shown publicly. Files are served from the company origin.</p>';
        echo '<form method="get" action="'.esc_url(admin_url('upload.php')).'">'
            .'<input type="hidden" name="page" value="mad4b-company-media">'
            .'<label>Local media attachment ID <input type="number" min="1" name="media_id" value="'.(int)$id.'"></label> '
            .'<button type="submit" class="button">Preview image</button></form>';
        if($id){
            $p=self::preview($id);
            if(is_wp_error($p)){echo '<p>Image is not eligible for public company delivery.</p></div>';return;}
            $companyName=self::brand();
            echo '<p><strong>Company image URL:</strong> <code>'.esc_html($p['company_domain_url']).'</code></p>'
                .'<p><strong>Verified Brand Context:</strong> '.($companyName!==''?esc_html($companyName):'Unavailable — {brand} requires verification').'</p>';
            $idLang=esc_attr($p['language']);
            $readonly=!self::permission($id);
            if($readonly)echo '<p>This account cannot propose media metadata edits.</p>';
            else {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('mad4b_company_media_'.$id.'_'.$p['language']);
                echo '<input type="hidden" name="action" value="mad4b_company_media_propose">'
                    .'<input type="hidden" name="id" value="'.$id.'">'
                    .'<input type="hidden" name="lang" value="'.$idLang.'">'
                    .'<input type="hidden" name="source" value="'.esc_attr($p['source_sha']).'">'
                    .'<input type="hidden" name="expected" value="'.(int)$p['current_revision'].'">';
                foreach(self::FIELDS as $field){
                    echo '<p><label>'.esc_html(ucwords(str_replace('_',' ',$field))).' '
                        .'<input maxlength="600" style="width:560px;max-width:96%"'
                        .' name="metadata['.esc_attr($field).']" value="'
                        .esc_attr($p['effective'][$field]).'"></label></p>';
                }
                echo '<p>Use <code>{brand}</code> to insert the verified company brand. '
                    .'Blank values can describe decorative images, and must be reviewed.</p>';
                echo '<button class="button button-primary" type="submit">Submit company image SEO for review</button></form>';
            }
        }
        if(current_user_can('manage_options')&&current_user_can('activate_plugins')){
            global $wpdb;$t=self::table();$site=self::site();
            if(self::ready()&&$site){
                $rows=$wpdb->get_results($wpdb->prepare("SELECT id,attachment_id,language,status,proposed_by
                    FROM $t WHERE site_uuid=%s AND status='pending' ORDER BY id DESC LIMIT 30",$site));
                echo '<h2>Pending independent owner reviews</h2>';
                foreach($rows as $r){
                    echo '<p>Image #'.(int)$r->attachment_id.' / '.esc_html($r->language)
                        .' — Request #'.(int)$r->id.' (author '.(int)$r->proposed_by.') ';
                    foreach(['approve','reject'] as $choice){
                        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">';
                        wp_nonce_field('mad4b_company_media_review_'.$r->id);
                        echo '<input type="hidden" name="action" value="mad4b_company_media_review">'
                            .'<input type="hidden" name="proposal_id" value="'.(int)$r->id.'">'
                            .'<input type="hidden" name="decision" value="'.$choice.'">'
                            .'<button class="button" type="submit">'.ucfirst($choice).'</button></form> ';
                    }
                    echo '</p>';
                }
            }
        }
        echo '</div>';
    }
    public static function admin_post(){
        if(!is_user_logged_in())wp_die('Login required',403);
        $id=absint($_POST['id']??0);$lang=self::language(sanitize_key($_POST['lang']??''));
        check_admin_referer('mad4b_company_media_'.$id.'_'.$lang);
        $posted=wp_unslash($_POST['metadata']??[]);
        $fields=[];
        foreach(self::FIELDS as $field)
            if(isset($posted[$field])&&is_string($posted[$field]))$fields[$field]=$posted[$field];
        $r=self::propose($id,$lang,$fields,(string)($_POST['source']??''),(int)($_POST['expected']??0));
        if(is_wp_error($r))wp_die(esc_html($r->get_error_code()),409);
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-company-media','media_id'=>$id,
            'proposal'=>(int)$r['proposal_id']],admin_url('upload.php')));exit;
    }
    public static function admin_review(){
        $id=absint($_POST['proposal_id']??0);
        check_admin_referer('mad4b_company_media_review_'.$id);
        $r=self::review($id,sanitize_key($_POST['decision']??''));
        if(is_wp_error($r))wp_die(esc_html($r->get_error_code()),409);
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-company-media','reviewed'=>$id],admin_url('upload.php')));exit;
    }
    public static function boot(){
        add_action('init',[__CLASS__,'rewrite']);
        add_action('template_redirect',[__CLASS__,'stream'],0);
        add_shortcode('mad4b_company_image',[__CLASS__,'image']);
        add_action('admin_menu',static function(){
            add_media_page('Company Media SEO','Company Media SEO','upload_files',
                'mad4b-company-media',[__CLASS__,'admin']);
        });
        add_action('admin_post_mad4b_company_media_propose',[__CLASS__,'admin_post']);
        add_action('admin_post_mad4b_company_media_review',[__CLASS__,'admin_review']);
    }
}
MAD4B_Tenant_Company_Media::boot();
