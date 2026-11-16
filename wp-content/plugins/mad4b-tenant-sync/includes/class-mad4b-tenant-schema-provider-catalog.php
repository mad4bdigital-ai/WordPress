<?php
/**
 * Source-owned, dynamic tenant schema catalog.
 *
 * No operation IDs or table names are registered centrally. Providers declare
 * their own table suffixes from installed trusted PHP source. A discovery result
 * is never permission to run DDL: Original 312 exact signed verification stays
 * inside MAD4B_Tenant_Operation_Authority::schema().
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Tenant_Schema_Provider_Catalog_V1', false)) {
final class MAD4B_Tenant_Schema_Provider_Catalog_V1 {
    public const CONTRACT = 'mad4b.tenant.schema-provider-catalog.v1';
    public const DECLARATION = 'mad4b.tenant.schema-source.v1';
    public const MAX_PROVIDERS = 48;
    public const MAX_TABLES = 8;

    private static function trusted_source(ReflectionClass $class, ReflectionMethod $method):bool {
        $path=$class->getFileName();
        $methodPath=$method->getFileName();
        if (!is_string($path)||!is_string($methodPath)
            ||$path!==$methodPath||!$class->isFinal()
            ||!$method->isStatic()||!$method->isPublic()
            ||$method->getNumberOfRequiredParameters()!==0
            ||$method->getDeclaringClass()->getName()!==$class->getName())
            return false;
        $real=realpath($path);
        $root=realpath(__DIR__);
        if(!is_string($real)||!is_string($root)
            ||dirname($real)!==$root
            ||$real!==realpath($methodPath)
            ||is_link($path)||!is_file($real)
            ||fileowner($real)!==0
            ||(fileperms($real)&0022)!==0)return false;
        // The trusted release file must not be replaceable through its
        // plugin package or includes directory by the WP web-server user.
        foreach([$root,dirname($root)] as $directory){
            if(is_link($directory)||!is_dir($directory)
                ||fileowner($directory)!==0
                ||(fileperms($directory)&0022)!==0)return false;
        }
        return true;
    }

    public static function declaration_valid($source):bool {
        if (!is_array($source)
            ||array_keys($source)!==['contract','operation','tables']
            ||($source['contract']??null)!==self::DECLARATION
            ||!is_string($source['operation']??null)
            ||preg_match('/^[a-z][a-z0-9.]{5,95}$/D',$source['operation'])!==1
            ||!is_array($source['tables'])
            ||count($source['tables'])<1
            ||count($source['tables'])>self::MAX_TABLES)
            return false;
        $seen=[];
        foreach($source['tables'] as $label=>$suffix){
            if(!is_string($label)||!is_string($suffix)
                ||preg_match('/^[a-z][a-z0-9_]{0,47}$/D',$label)!==1
                ||preg_match('/^mad4b_tenant_[a-z0-9_]{2,48}$/D',$suffix)!==1
                ||isset($seen[$suffix]))
                return false;
            $seen[$suffix]=true;
        }
        return true;
    }

    /** The caller cannot select a provider through an option or REST field. */
    public static function discover():array {
        $rows=[];$duplicates=[];$rejected=0;$count=0;
        foreach(get_declared_classes() as $className){
            if(!str_starts_with($className,'MAD4B_Tenant_')
                ||!method_exists($className,'schema_source'))continue;
            if(++$count>self::MAX_PROVIDERS)
                return ['contract'=>self::CONTRACT,'state'=>'SOURCE_PROVIDER_LIMIT_HOLD',
                    'operations'=>[],'rejected'=>$rejected];
            try{
                $cls=new ReflectionClass($className);
                $method=$cls->getMethod('schema_source');
                if(!self::trusted_source($cls,$method)){
                    $rejected++;continue;
                }
                $decl=$method->invoke(null);
                if(!self::declaration_valid($decl)){
                    $rejected++;continue;
                }
                $op=$decl['operation'];
                if(isset($duplicates[$op])){
                    $rejected++;continue;
                }
                if(isset($rows[$op])){
                    unset($rows[$op]);
                    $duplicates[$op]=true;
                    $rejected+=2;
                    continue;
                }
                $body=wp_json_encode($decl,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                if(!is_string($body)){
                    $rejected++;continue;
                }
                $rows[$op]=[
                    'operation'=>$op,
                    'tables'=>$decl['tables'],
                    'source_class'=>$className,
                    'source_relative_file'=>basename((string)$cls->getFileName()),
                    'source_sha256'=>hash_file('sha256',(string)$cls->getFileName()),
                    'declaration_sha256'=>hash('sha256',$body),
                    'read_only_discovery'=>true,
                    'schema_mutation_authorized'=>false,
                ];
            }catch(Throwable $error){
                $rejected++;
            }
        }
        ksort($rows,SORT_STRING);
        return [
            'contract'=>self::CONTRACT,
            'state'=>$duplicates?'AMBIGUOUS_OPERATION_HOLD':'SOURCE_OWNED_DISCOVERY_ONLY',
            'operations'=>$rows,'rejected'=>$rejected,
            'schema_mutation_authorized'=>false,
            'signed_grant_present'=>false,
        ];
    }

    /**
     * Compose the existing read-only schema manifest from source declarations.
     * The selecting caller supplies explicit operation IDs and exact-site
     * identity; neither is proof of permission. No implicit ALL-operations
     * migration and no table defaults are chosen by this helper.
     */
    public static function manifest_for(array $operations,array $site):?array {
        if(!isset($site['company'],$site['environment'],
                  $site['site_uuid'],$site['origin'])
            ||!is_string($site['company'])
            ||preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$site['company'])!==1
            ||!is_string($site['environment'])
            ||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$site['environment'])!==1
            ||!is_string($site['site_uuid'])
            ||preg_match('/^[a-f0-9-]{36}$/D',$site['site_uuid'])!==1
            ||!is_string($site['origin'])
            ||preg_match('~^https://[a-z0-9][a-z0-9.-]*\\.[a-z]{2,}$~iD',
                $site['origin'])!==1
            ||count($operations)<1||count($operations)>self::MAX_PROVIDERS
            ||count(array_unique($operations))!==count($operations))
            return null;
        $catalog=self::discover();
        if(($catalog['state']??null)!=='SOURCE_OWNED_DISCOVERY_ONLY')
            return null;
        $tables=[];$sources=[];
        foreach($operations as $operation){
            if(!is_string($operation)
                ||!isset($catalog['operations'][$operation]))
                return null;
            $source=$catalog['operations'][$operation];
            foreach($source['tables'] as $label=>$suffix){
                if(isset($tables[$label])||in_array($suffix,$tables,true))
                    return null;
                $tables[$label]=$suffix;
            }
            $sources[$operation]=[
                'declaration_sha256'=>$source['declaration_sha256'],
                'source_sha256'=>$source['source_sha256'],
            ];
        }
        if(count($tables)<1||count($tables)>32)return null;
        ksort($tables,SORT_STRING);
        ksort($sources,SORT_STRING);
        $manifest=[
            'company'=>$site['company'],
            'contract'=>'mad4b.company-schema-manifest.v1',
            'environment'=>$site['environment'],
            'origin'=>$site['origin'],
            'site_uuid'=>$site['site_uuid'],
            'tables'=>$tables,
        ];
        $json=wp_json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $sourcesJson=wp_json_encode($sources,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($json)||!is_string($sourcesJson))return null;
        return [
            'contract'=>self::CONTRACT.'.read-only-manifest.v1',
            'state'=>'SOURCE_OWNED_SCHEMA_MANIFEST_PREVIEW_UNSIGNED',
            'manifest'=>$manifest,
            'manifest_sha256'=>hash('sha256',$json),
            'source_capability_sha256'=>hash('sha256',$sourcesJson),
            'source_declarations'=>$sources,
            'read_only'=>true,'source_site_authority_confirmed'=>false,
            'migration_authorized'=>false,'original_313_signed'=>false,
            'site_mutated'=>false,
        ];
    }
    /** Match exact live-prefix table names in provider-owned order. */
    public static function resolve(string $operation,array $tables):?array {
        if(!preg_match('/^[a-z][a-z0-9.]{5,95}$/D',$operation)
            ||count($tables)<1||count($tables)>self::MAX_TABLES
            ||array_values($tables)!==$tables)return null;
        global $wpdb;
        $prefix=is_object($wpdb)?($wpdb->prefix??null):null;
        if(!is_string($prefix)||!preg_match('/^[A-Za-z0-9_]{1,40}$/D',$prefix))
            return null;
        $catalog=self::discover();
        if(($catalog['state']??null)!=='SOURCE_OWNED_DISCOVERY_ONLY')
            return null;
        $selected=$catalog['operations'][$operation]??null;
        if(!is_array($selected))return null;
        $expected=[];
        foreach($selected['tables'] as $suffix){
            $full=$prefix.$suffix;
            if(strlen($full)>64)return null;
            $expected[]=$full;
        }
        if($tables!==$expected)return null;
        return [
            'contract'=>self::CONTRACT.'.exact-source-match',
            'operation'=>$operation,'tables'=>$expected,
            'source_class'=>$selected['source_class'],
            'source_sha256'=>$selected['source_sha256'],
            'declaration_sha256'=>$selected['declaration_sha256'],
            'source_catalog_verified'=>true,
            'original_signed_312_verified'=>false,
            'schema_mutation_authorized'=>false,
        ];
    }
}
}
