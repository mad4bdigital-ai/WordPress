<?php
/**
 * Plugin Name: MAD4B Native Elementor CSS Reconcile
 * Description: Rebuild site-local compiled Elementor CSS from its actual Kit/template source after a company transfer.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI) return;

final class MAD4B_Elementor_CSS_Native_Reconcile_CLI {
    /**
     * Local, original-owned post-publication CSS reconciliation.
     * Elementor can invalidate CSS on draft -> publish. Regenerate only this
     * exact published template after an authorized native WPML publish.
     * No Kit rebuild, no CSS copying from source/other languages.
     */
    public static function after_wpml_publication(int $post_id):array|WP_Error {
        if(!defined('WP_CLI')||!WP_CLI||
           wp_get_environment_type()!=='staging'||
           rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com'||
           !class_exists('MAD4B_SCP_Policy')||
           !MAD4B_SCP_Policy::can_mutate()||
           !current_user_can('manage_options'))
           return new WP_Error('mad4b_wpml_css_publish_operator_denied');
        $post=get_post($post_id);
        if(!$post||$post->post_status!=='publish'||
           !in_array($post->post_type,['jet-engine','elementor_library'],true)||
           !class_exists('Elementor\\Core\\Files\\CSS\\Post'))
           return new WP_Error('mad4b_wpml_css_publish_template_invalid');
        $raw=(string)get_post_meta($post_id,'_elementor_data',true);
        if(strlen($raw)<2||!is_array(json_decode($raw,true)))
           return new WP_Error('mad4b_wpml_css_publish_source_missing');
        $css=new \Elementor\Core\Files\CSS\Post($post_id);
        $expected=$css->get_content();
        if(!is_string($expected)||strlen($expected)<100)
           return new WP_Error('mad4b_wpml_css_publish_compiler_empty');
        $path=$css->get_path();
        $mode=get_option('elementor_css_print_method')==='external'?'file':'inline';
        $prior=get_post_meta($post_id,'_elementor_css',true);
        $old=is_file($path)?file_get_contents($path):null;
        if($old===false||($old!==null&&strlen($old)>3000000))
           return new WP_Error('mad4b_wpml_css_publish_backup_unavailable');
        $current=$mode==='file'
           ?($old===null?'':hash('sha256',$old))
           :hash('sha256',is_array($prior)?(string)($prior['css']??''):'');
        $digest=hash('sha256',$expected);
        $needs=!hash_equals($digest,(string)$current)||
           !is_array($prior)||($prior['status']??'')!==$mode;
        if($needs){
           try{
               $css->update();
               $meta=get_post_meta($post_id,'_elementor_css',true);
               $now=$mode==='file'
                 ?(is_file($path)?hash_file('sha256',$path):'')
                 :hash('sha256',is_array($meta)?(string)($meta['css']??''):'');
               if(!is_array($meta)||($meta['status']??'')!==$mode||
                  !hash_equals($digest,(string)$now))
                  throw new RuntimeException('POST_PUBLISH_CSS_HASH_READBACK_FAILED');
           }catch(Throwable $e){
               if(is_array($prior))update_post_meta($post_id,'_elementor_css',$prior);
               else delete_post_meta($post_id,'_elementor_css');
               if($mode==='file'){
                   if($old===null){if(is_file($path))@unlink($path);}
                   else{
                       $tmp=$path.'.mad4b-rollback-'.getmypid();
                       if(file_put_contents($tmp,$old,LOCK_EX)!==false)@rename($tmp,$path);
                       else @unlink($tmp);
                   }
               }
               return new WP_Error('mad4b_wpml_css_publish_readback_rollback',
                   $e->getMessage());
           }
           if(class_exists('MAD4B_SCP_Adaptive_Asset_Intelligence'))
               MAD4B_SCP_Adaptive_Asset_Intelligence::mark_dirty(
                   'wpml_locale_css_published','/');
        }
        return ['contract'=>'mad4b.elementor-css.post-publish.v1',
            'post_id'=>$post_id,'mode'=>$mode,'regenerated'=>$needs,
            'expected_sha256'=>$digest,'independent_native_readback'=>true,
            'kit_css_mutated'=>false,'production_mutation_performed'=>false];
    }

    private static function digest($value): string {
        return hash('sha256',is_string($value)?$value:wp_json_encode($value,JSON_UNESCAPED_SLASHES));
    }
    private static function fail(string $message): void {
        WP_CLI::error($message);
    }
    /**
     * Read or regenerate locally compiled Elementor CSS, after Elementor Kit
     * and template metadata have been imported and the target Kit mapped.
     *
     * ## OPTIONS
     * --origin=<url>
     * : Target's actual canonical WordPress origin; required to avoid wrong-site writes.
     * [--ids=<ids>]
     * : Comma-separated original/target-mapped Elementor template IDs, maximum 50.
     * [--expected-kit-css-sha256=<sha>]
     * : Hash of the current target's Elementor Kit Custom CSS; required with --apply.
     * [--only-ids]
     * : With --ids, reconcile only requested templates; never touch shared Kit CSS.
     * [--apply]
     * : Persist generated CSS. Without this argument, the command is read-only.
     */
    public function reconcile(array $args, array $assoc): void {
        $expected=rtrim((string)($assoc['origin']??''),'/');
        $current=rtrim((string)home_url('/'),'/');
        if(!str_starts_with($expected,'https://') || !hash_equals($current,$expected))
            self::fail('TARGET_ORIGIN_NOT_EXACTLY_MATCHED');
        if(!class_exists('Elementor\\Core\\Files\\CSS\\Post') || !class_exists('Elementor\\Plugin'))
            self::fail('NATIVE_ELEMENTOR_NOT_READY');
        $kit=(int)get_option('elementor_active_kit');
        if($kit<1 || !get_post($kit))self::fail('ELEMENTOR_KIT_IDENTITY_MISSING');
        $setting=get_post_meta($kit,'_elementor_page_settings',true);
        if(!is_array($setting))self::fail('ELEMENTOR_KIT_SETTINGS_MISSING');
        $custom=(string)($setting['custom_css']??'');
        $source_sha=self::digest($custom);
        $apply=isset($assoc['apply']) && $assoc['apply']!==false;
        if($apply) {
            $expected_hash=(string)($assoc['expected-kit-css-sha256']??'');
            if(!preg_match('/^[a-f0-9]{64}$/D',$expected_hash) ||
               !hash_equals($source_sha,$expected_hash))self::fail('TARGET_KIT_SOURCE_HASH_MISMATCH');
        }
        // A mapped-locale repair should not rewrite the shared Kit's CSS.
        // The Kit source digest is still checked for every --apply.
        $only_ids=isset($assoc['only-ids']);
        if($only_ids && empty($assoc['ids'])) self::fail('SELECTIVE_IDS_REQUIRED');
        $ids=$only_ids?array():array($kit);
        if(isset($assoc['ids'])) {
            $raw=(string)$assoc['ids'];
            if(strlen($raw)>512 || !preg_match('/^[0-9,]+$/D',$raw))self::fail('INVALID_TEMPLATE_ID_LIST');
            $parsed=array_map('intval',explode(',',$raw));
            if(count($parsed)>50 || in_array(0,$parsed,true))self::fail('TEMPLATE_LIMIT_OR_ID_INVALID');
            $ids=array_values(array_unique(array_merge($ids,$parsed)));
        }
        foreach($ids as $id) {
            $post=get_post($id);
            if(!$post || !in_array($post->post_type,array('elementor_library','jet-engine','page','post','tours-and-activities'),true))
                self::fail('UNRECOGNIZED_ELEMENTOR_DOCUMENT_'.$id);
            if($id!==$kit) {
                $raw=get_post_meta($id,'_elementor_data',true);
                if(!is_string($raw) || strlen($raw)<2 || !is_array(json_decode($raw,true)))
                    self::fail('ELEMENTOR_TEMPLATE_SOURCE_MISSING_'.$id);
            }
        }
        // Always verify the effective source. Elementor external mode stores CSS
        // in a file, not in _elementor_css['css']; comparing inline-only rejected
        // valid CSS and could never complete readback on this site's configuration.
        $mode=get_option('elementor_css_print_method')==='external'?'file':'inline';
        $backup=array();$file_backups=array();$any_drift=false;
        $report=array('contract'=>'mad4b.elementor-css-native-reconcile.v1',
            'origin'=>$current,'kit_id'=>$kit,'kit_source_sha256'=>$source_sha,
            'css_storage_mode'=>$mode,'applied'=>false,'documents'=>array());
        foreach($ids as $id) {
            // Separate preview and writer avoids Elementor's double-append bug.
            $preview=new Elementor\Core\Files\CSS\Post($id);
            $source=$id===$kit ? $setting : (string)get_post_meta($id,'_elementor_data',true);
            $compiled=$preview->get_content();
            if(strlen($compiled)<100)self::fail('ELEMENTOR_COMPILED_CSS_EMPTY_'.$id);
            $stored=get_post_meta($id,'_elementor_css',true);
            $backup[$id]=$stored;
            $path=$preview->get_path();
            $file_exists=is_file($path);
            $current_hash=$mode==='file'
                ?($file_exists?hash_file('sha256',$path):'')
                :self::digest(is_array($stored)?(string)($stored['css']??''):'');
            $compiled_hash=self::digest($compiled);
            $matches=hash_equals($compiled_hash,(string)$current_hash)
                && is_array($stored) && ($stored['status']??'')===$mode;
            if(!$matches)$any_drift=true;
            $report['documents'][]=array('id'=>$id,
                'source_sha256'=>self::digest($source),
                'expected_compiled_sha256'=>$compiled_hash,
                'previous_stored_sha256'=>$current_hash,
                'previous_status'=>is_array($stored)?($stored['status']??'missing'):'missing',
                'source_bytes'=>is_string($source)?strlen($source):strlen(wp_json_encode($source)),
                'compiled_bytes'=>strlen($compiled),
                'drift_detected'=>!$matches);
        }
        $report['drift_detected']=$any_drift;
        if($apply && $any_drift) {
            // Preserve native postmeta rollback and take an independent file
            // snapshot for external CSS, before calling Elementor's update().
            $key='mad4b_css_reconcile_backup_'.substr(hash('sha256',$current.'|'.implode(',',$ids).'|'.$source_sha),0,24);
            $record=array('at'=>gmdate('c'),'origin'=>$current,'kit'=>$kit,
                'kit_source_sha256'=>$source_sha,'meta'=>$backup,'mode'=>$mode);
            if(get_option($key,null)===null && !add_option($key,$record,'','no'))
                self::fail('ELEMENTOR_CSS_BACKUP_CREATE_FAILED');
            $read=get_option($key,null);
            if(!is_array($read)||($read['origin']??'')!==$current||
                !hash_equals((string)($read['kit_source_sha256']??''),$source_sha))
                self::fail('ELEMENTOR_CSS_BACKUP_READBACK_FAILED');
            $report['backup_option']=$key;
            $written=array();
            try {
                foreach($ids as $id) {
                    $i=array_search($id,$ids,true);
                    if(empty($report['documents'][$i]['drift_detected']))continue;
                    $writer=new Elementor\Core\Files\CSS\Post($id);
                    $path=$writer->get_path();
                    if($mode==='file'){
                        $old=is_file($path)?file_get_contents($path):null;
                        if($old===false||($old!==null && strlen($old)>3000000))
                            throw new RuntimeException('ELEMENTOR_CSS_FILE_BACKUP_UNAVAILABLE_'.$id);
                        $file_backups[$id]=array('path'=>$path,'previous'=>$old);
                    }
                    $written[]=$id;
                    $writer->update();
                    $meta=get_post_meta($id,'_elementor_css',true);
                    $expected=$report['documents'][$i]['expected_compiled_sha256'];
                    $actual=$mode==='file'
                        ?(is_file($path)?hash_file('sha256',$path):'')
                        :self::digest(is_array($meta)?(string)($meta['css']??''):'');
                    if(!is_array($meta)||($meta['status']??'')!==$mode||
                       !hash_equals($expected,(string)$actual))
                        throw new RuntimeException('ELEMENTOR_NATIVE_COMPILED_READBACK_FAILED_'.$id);
                }
            } catch(Throwable $e) {
                foreach(array_reverse($written) as $id){
                    if(is_array($backup[$id]))update_post_meta($id,'_elementor_css',$backup[$id]);
                    else delete_post_meta($id,'_elementor_css');
                    if(isset($file_backups[$id])){
                        $entry=$file_backups[$id];$path=$entry['path'];
                        if($entry['previous']===null){
                            if(is_file($path))@unlink($path);
                        }else{
                            $temporary=$path.'.rollback-'.getmypid();
                            if(file_put_contents($temporary,$entry['previous'],LOCK_EX)!==false)
                                @rename($temporary,$path);
                            else @unlink($temporary);
                        }
                    }
                }
                self::fail('REGENERATE_ABORTED_ROLLBACK_ATTEMPTED:'.$e->getMessage());
            }
            $report['applied']=count($written)>0;
            $report['applied_ids']=$written;
            if($written && class_exists('MAD4B_SCP_Adaptive_Asset_Intelligence'))
                MAD4B_SCP_Adaptive_Asset_Intelligence::mark_dirty('elementor_css_reconciled','/');
        }else{
            $report['applied_ids']=array();
            $report['already_converged']=!$any_drift;
        }
        // Readback means exact compiled CSS bytes in the active storage mode.
        $report['readback_verified']=$apply ? true : !$any_drift;
        WP_CLI::line(wp_json_encode($report,JSON_UNESCAPED_SLASHES));
    }
}
WP_CLI::add_command('mad4b-css', 'MAD4B_Elementor_CSS_Native_Reconcile_CLI');
