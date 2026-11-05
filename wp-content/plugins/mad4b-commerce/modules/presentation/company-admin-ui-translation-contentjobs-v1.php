<?php
/** MAD4B UI Localization: read-only source-backed ContentJob candidate discovery.
 * Discovers native MAD4B plugin PHP/JS Arabic literals in bounded scans,
 * grouped by module + target site language. Never publishes, changes roles,
 * auto-translates source strings, or creates ContentJobs without admission.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Admin_UI_Translation_ContentJobs_V1 {
    public const CONTRACT='mad4b.company-admin-ui-translation-candidates.v1';
    private const CAP=240;
    private static function site():array {
        $profile=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::status():[];
        return ['uuid'=>(string)($profile['site_uuid']??''),
            'origin'=>untrailingslashit(home_url('/')),
            'ready'=>!empty($profile['configured'])&&!empty($profile['origin_match'])&&
              !empty($profile['deployment_binding_match'])&&!empty($profile['environment_match'])];
    }
    private static function languages():array {
        $settings=get_option('icl_sitepress_settings',[]);$out=[];
        foreach((array)(is_array($settings)?($settings['active_languages']??[]):[]) as $lang){
            if(is_string($lang)&&preg_match('/^[a-z]{2,3}(?:-[a-z]{2,4})?$/iD',$lang)&&
                strtolower($lang)!=='ar')$out[strtolower($lang)]=true;
        }
        if(!$out){$locale=strtolower(substr(get_locale(),0,2));if($locale&&$locale!=='ar')$out[$locale]=true;}
        return array_keys($out);
    }
    private static function add(array &$results,string $family,string $relative,string $text,int $line,string $type):void {
        $str=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags($text)));
        if(mb_strlen($str)<2||mb_strlen($str)>6000||!preg_match('/[\x{0600}-\x{06FF}]/u',$str))return;
        // Skip strings that are merely Arabic alphabet or a locale code.
        if(preg_match('/^(?:[\x{0600}-\x{06FF}]\s*){1,2}$/u',$str))return;
        $area=preg_match('~(?:admin|owner|manager|company|operation|wpml|content.?job|menu)~i',$relative)
           ?'admin_and_company':'runtime_ui';
        $key=hash('sha256',$family.'|'.$relative.'|'.hash('sha256',$str));
        if(isset($results[$key])){$results[$key]['occurrences']++;return;}
        $results[$key]=['fingerprint'=>$key,'family'=>$family,'source_file'=>$relative,
            'source_line'=>$line,'source_type'=>$type,'area'=>$area,
            'sample'=>mb_substr($str,0,250),'source_text_sha256'=>hash('sha256',$str),
            'occurrences'=>1];
    }
    public static function scan(array $input=[]):array {
        $site=self::site();
        if(!$site['ready'])return ['contract'=>self::CONTRACT,'state'=>'HOLD_SITE_IDENTITY_UNVERIFIED',
            'mutation_performed'=>false];
        $maxFiles=4500;$maxItems=4500;$files=0;$scanned=0;$records=[];$complete=true;$errors=[];
        $plugins=glob(WP_PLUGIN_DIR.'/mad4b*',GLOB_ONLYDIR);
        if(!is_array($plugins))$plugins=[];
        sort($plugins,SORT_STRING);
        foreach($plugins as $root){
            if(is_link($root))continue;
            $family=basename($root);
            $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
            foreach($iterator as $node){
                if($files>=$maxFiles||count($records)>=$maxItems){$complete=false;break 2;}
                if(!$node->isFile()||$node->isLink())continue;
                $path=$node->getPathname();
                if(preg_match('~/(?:vendor|node_modules|third-party|tests?|cache|backups?|languages|dist|build)/~i',$path))continue;
                $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
                if(!in_array($ext,['php','js'],true))continue;
                if($node->getSize()>262144||$node->getSize()<8)continue;
                $files++;
                $body=file_get_contents($path);
                if(!is_string($body)||!preg_match('/[\x{0600}-\x{06FF}]/u',$body))continue;
                $relative=substr($path,strlen(WP_PLUGIN_DIR)+1);
                if($ext==='php'){
                    $tokens=token_get_all($body);
                    foreach($tokens as $token){
                        if(!is_array($token) || !in_array($token[0],[T_CONSTANT_ENCAPSED_STRING,T_ENCAPSED_AND_WHITESPACE],true))continue;
                        $value=$token[1];
                        if($token[0]===T_CONSTANT_ENCAPSED_STRING)$value=substr($value,1,-1);
                        self::add($records,$family,$relative,$value,(int)$token[2],'php_literal');
                    }
                }else{
                    $lines=preg_split('/\R/u',$body);
                    foreach((array)$lines as $idx=>$line){
                        if(!preg_match('/[\x{0600}-\x{06FF}]/u',$line))continue;
                        $value=trim($line);
                        if(str_starts_with($value,'//')||str_starts_with($value,'*')||str_starts_with($value,'/*'))continue;
                        self::add($records,$family,$relative,$value,$idx+1,'js_review_only');
                    }
                }
                $scanned++;
            }
        }
        ksort($records,SORT_STRING);
        $locales=self::languages();sort($locales,SORT_STRING);
        $byFile=[];
        foreach($records as $item){$path=$item['source_file'];
            if(!isset($byFile[$path]))$byFile[$path]=['family'=>$item['family'],
                'area'=>$item['area'],'source_file'=>$path,'strings'=>[]];
            $byFile[$path]['strings'][]=$item;
        }
        $requests=[];
        foreach($byFile as $module){
            $hashes=array_column($module['strings'],'source_text_sha256');sort($hashes,SORT_STRING);
            $sourceHash=hash('sha256',wp_json_encode($hashes,JSON_UNESCAPED_SLASHES));
            foreach($locales as $lang){
                $token=hash('sha256',$site['uuid'].'|'.$module['source_file'].'|'.$lang.'|'.$sourceHash);
                $requests[]=['contract'=>'mad4b.contentjob.ui-translation-request.v1',
                  'status'=>'AWAITING_AUTHORIZED_NATIVE_CONTENT_JOB',
                  'task_family'=>'admin_ui_localization','source_language'=>'ar',
                  'target_language'=>$lang,'source_file'=>$module['source_file'],
                  'source_area'=>$module['area'],'family'=>$module['family'],
                  'literal_count'=>count($module['strings']),
                  'source_sha256'=>$sourceHash,'deduplication_key'=>$token,
                  'requires_governed_content_job_admission'=>true,
                  'requires_human_translation_review'=>true,
                  'auto_publish'=>false,'code_rewrite_authorized'=>false];
            }
        }
        $limit=max(1,min(150,(int)($input['limit']??80)));
        $language=(string)($input['language']??'');
        if($language!=='')$requests=array_values(array_filter($requests,
            static fn($r)=>$r['target_language']===$language));
        $total=count($requests);
        return ['contract'=>self::CONTRACT,
          'state'=>$complete?'REVIEW_ONLY_CONTENT_JOB_REQUESTS_READY':'HOLD_INCOMPLETE_SOURCE_SCAN',
          'site_uuid'=>$site['uuid'],'site_origin'=>$site['origin'],
          'language_scope'=>$locales,'plugin_files_scanned'=>$files,
          'files_with_candidates'=>count($byFile),
          'arabic_static_literals_detected'=>count($records),
          'total_content_job_requests'=>$total,'requests'=>array_slice($requests,0,$limit),
          'source_records_preview'=>array_slice(array_values($records),0,min($limit,30)),
          'scan_complete'=>$complete,
          'native_content_jobs_created'=>0,'translation_files_modified'=>false,
          'auto_publish'=>false,'mutation_performed'=>false];
    }
    public static function ability($input):array {return self::scan(is_array($input)?$input:[]);}
    public static function register_ability():void {
        if(!function_exists('wp_register_ability'))return;
        $name='mad4b/content-job-admin-ui-translation-candidates';
        if(function_exists('wp_has_ability')&&wp_has_ability($name))return;
        wp_register_ability($name,[
            'label'=>'Discover Source-owned Admin UI Translation Candidates',
            'description'=>'Read-only, revision-fingerprinted native ContentJob intake candidates from current company plugin PHP and JavaScript UI.',
            'category'=>'mad4b-read',
            'execute_callback'=>[__CLASS__,'ability'],
            'permission_callback'=>class_exists('MAD4B_SCP_Policy',false)
               ?['MAD4B_SCP_Policy','can_read']:static fn()=>current_user_can('manage_options'),
            'input_schema'=>['type'=>'object','properties'=>[
                'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>150,'default'=>80],
                'language'=>['type'=>'string','maxLength'=>8,'default'=>'']],
                'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['public'=>false,'show_in_rest'=>false,
                'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
        ]);
    }
    public static function cli($args):void {
        $mode=$args[0]??'status';
        if(!in_array($mode,['status','preview'],true))WP_CLI::error('Use status or preview');
        $data=self::scan(['limit'=>$mode==='preview'?100:10]);
        if($mode==='status')unset($data['requests'],$data['source_records_preview']);
        WP_CLI::line(wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}
add_action('wp_abilities_api_init',[MAD4B_Company_Admin_UI_Translation_ContentJobs_V1::class,'register_ability'],43);
add_filter('mad4b_company_reconcile_component_readiness',static function($parts){
    if(!is_array($parts))$parts=[];
    $parts['content_jobs_admin_ui_translation']=MAD4B_Company_Admin_UI_Translation_ContentJobs_V1::scan(['limit'=>1]);
    return $parts;
},42);
if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b-ui-translation-gaps',[MAD4B_Company_Admin_UI_Translation_ContentJobs_V1::class,'cli']);
