<?php
/**
 * Dynamic Native Ability Discovery and Owner-Pinned Read Trust.
 *
 * A native WordPress Ability can advertise its presence without being added to
 * an adapter class allowlist. Source approval is separate: an independently
 * deployed manifest outside WP_CONTENT_DIR must be SHA256 pinned in wp-config.
 * Unknown or modified abilities remain discovery-only; nothing auto-executes.
 */
if (!defined('ABSPATH')) { exit; }

final class MAD4B_SCP_Native_Ability_Trust {
    const CONTRACT = 'mad4b.native-ability-trust.v1';
    const MAX_PAGE_SIZE = 96;
    const MAX_ENTRIES = 64;

    private static function safe_path($value) {
        return is_string($value) && strlen($value)<=220 &&
            preg_match('~^(?:mu-plugins|plugins)/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*[.]php$~D',$value) &&
            false===strpos($value,'..') && false===strpos($value,'/./');
    }

    private static function source_file($callback) {
        try {
            if ($callback instanceof Closure) $r=new ReflectionFunction($callback);
            elseif (is_array($callback) && count($callback)===2 &&
                    (is_object($callback[0])||is_string($callback[0])) && is_string($callback[1]))
                $r=new ReflectionMethod($callback[0],$callback[1]);
            elseif (is_string($callback) && strpos($callback,'::')!==false) {
                $parts=explode('::',$callback,2); $r=new ReflectionMethod($parts[0],$parts[1]);
            } elseif (is_string($callback) && function_exists($callback)) $r=new ReflectionFunction($callback);
            else return false;
            $file=$r->getFileName();
            if (!is_string($file) || $file==='') return false;
            return realpath($file);
        } catch (Throwable $e) { return false; }
    }

    private static function ability_callback_file($ability) {
        // Private WP_Ability callbacks are read for PROVENANCE only; never
        // called or used to bypass the original permission_callback.
        if (!is_object($ability) || !($ability instanceof WP_Ability)) return false;
        try {
            $p=new ReflectionProperty($ability,'execute_callback');
            $callback=$p->getValue($ability);
            // These are the three built-in, source-controlled Core Control Plane
            // wrappers. Only unwrap exact callbacks from their exact files.
            $wrappers=[
                'class-mad4b-scp-execution-fence.php'=>['original','original_execute'],
                'class-mad4b-scp-error-contract-registry.php'=>['original'],
                'class-mad4b-scp-connector-resilience.php'=>['callback'],
            ];
            $trusted_dir=realpath(__DIR__);
            for($depth=0;$depth<5;$depth++) {
                $source=self::source_file($callback);
                if (!$source) return false;
                $basename=basename($source);
                if (!isset($wrappers[$basename])) return $source;
                if ($trusted_dir===false || dirname($source)!==$trusted_dir || !($callback instanceof Closure)) return false;
                $info=(new ReflectionFunction($callback))->getStaticVariables();
                $next=null;
                foreach($wrappers[$basename] as $property) {
                    if (isset($info[$property]) && is_callable($info[$property])) {$next=$info[$property];break;}
                }
                if ($next===null) return false;
                $callback=$next;
            }
            return false;
        } catch (Throwable $e) { return false; }
    }

    private static function manifest() {
        $empty=['state'=>'OWNER_PIN_NOT_CONFIGURED','rows'=>[],'verified'=>false];
        if (!defined('MAD4B_SCP_NATIVE_ABILITY_MANIFEST_PATH')
            || !defined('MAD4B_SCP_NATIVE_ABILITY_MANIFEST_SHA256')) return $empty;
        $path=constant('MAD4B_SCP_NATIVE_ABILITY_MANIFEST_PATH');
        $expected=strtolower((string)constant('MAD4B_SCP_NATIVE_ABILITY_MANIFEST_SHA256'));
        if (!is_string($path) || !preg_match('/^[a-f0-9]{64}$/D',$expected))
            return ['state'=>'OWNER_PIN_INVALID','rows'=>[],'verified'=>false];
        $real=realpath($path);
        $content=realpath(WP_CONTENT_DIR);
        if ($real===false || !is_file($real) || !is_readable($real)
            || is_link($path) || (bool)preg_match('~^'.$content.'(?:/|$)~D',$real)
            || (defined('ABSPATH') && 0===strpos($real,realpath(ABSPATH).DIRECTORY_SEPARATOR))
            || filesize($real)>65536)
            return ['state'=>'OWNER_PIN_INVALID','rows'=>[],'verified'=>false];
        $actual=hash_file('sha256',$real);
        if (!is_string($actual)||!hash_equals($expected,$actual))
            return ['state'=>'OWNER_PIN_MISMATCH','rows'=>[],'verified'=>false];
        $data=json_decode((string)file_get_contents($real),true);
        if (!is_array($data) || ($data['contract']??null)!==self::CONTRACT ||
            ($data['site_host']??null)!==(string)wp_parse_url(home_url('/'),PHP_URL_HOST) ||
            !is_array($data['abilities']??null) || count($data['abilities'])>self::MAX_ENTRIES)
            return ['state'=>'OWNER_PIN_INVALID','rows'=>[],'verified'=>false];
        $rows=[];
        foreach($data['abilities'] as $row) {
            if (!is_array($row) || !is_string($row['name']??null) ||
                !preg_match('~^[a-z0-9_-]{1,64}/[a-z0-9._/-]{1,191}$~D',$row['name']) ||
                isset($rows[$row['name']]) ||
                ($row['scope']??null)!=='read' ||
                !self::safe_path($row['source_relpath']??null) ||
                !is_string($row['source_sha256']??null) ||
                !preg_match('/^[a-f0-9]{64}$/D',$row['source_sha256']) ||
                !is_string($row['input_schema_sha256']??null) ||
                !preg_match('/^[a-f0-9]{64}$/D',$row['input_schema_sha256']))
                return ['state'=>'OWNER_PIN_INVALID','rows'=>[],'verified'=>false];
            $rows[$row['name']]=$row;
        }
        return ['state'=>'OWNER_PIN_VERIFIED','rows'=>$rows,'verified'=>true];
    }

    public static function catalog($input = []) {
        $prefix=(string)($input['prefix']??'');
        $offset=(int)($input['offset']??0);
        $page_size=(int)($input['limit']??48);
        if (!is_array($input) || array_diff(array_keys($input),['prefix','offset','limit']) || strlen($prefix)>64 || $offset<0 || $offset>10000 || $page_size<1 || $page_size>self::MAX_PAGE_SIZE) return new WP_Error('native_ability_invalid_page','Invalid catalog pagination.');
        $manifest=self::manifest();
        $items=[];
        $total=0; $matched=0;
        if (!function_exists('wp_get_abilities')) return [
            'contract'=>self::CONTRACT,'state'=>'WORDPRESS_ABILITIES_UNAVAILABLE',
            'discovery_only'=>true,'items'=>[],'approved_read_count'=>0,
            'execution_authorized_by_catalog'=>false,'mutation_performed'=>false
        ];
        $all=wp_get_abilities();
        if (!is_array($all)) $all=[];
        ksort($all,SORT_STRING);
        $approved=0; $seen=[];
        foreach($all as $name=>$ability) {
            if (!is_string($name) || !is_object($ability)) continue;
            $total++;
            if ($prefix!=='' && strpos($name,$prefix)!==0) continue;
            $matched++;
            // A manifest never grants execute by itself. It only proves that
            // this exact read Ability has owner-reviewed source and schema.
            $row=$manifest['rows'][$name]??null;
            $status='DISCOVERED_REVIEW_REQUIRED';
            $reason='no_owner_reviewed_manifest_binding';
            $meta=method_exists($ability,'get_meta')?$ability->get_meta():[];
            $is_read=is_array($meta)
                && !empty($meta['annotations']['readonly'])
                && empty($meta['annotations']['destructive'])
                && empty($meta['mcp']['public'])
                && empty($meta['public'])
                && empty($meta['show_in_rest'])
                && (($meta['mcp']['surface']??null)==='read');
            if ($row) {
                $source=self::ability_callback_file($ability);
                $root=realpath(WP_CONTENT_DIR);
                $actual_source=$root?realpath($root.'/'.$row['source_relpath']):false;
                $schema=method_exists($ability,'get_input_schema')?$ability->get_input_schema():null;
                $schema_hash=hash('sha256',wp_json_encode($schema,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                if (!$is_read) $reason='not_a_private_readonly_ability';
                elseif (!$source || !$actual_source || $source!==$actual_source ||
                         !$root || strpos($source,$root.DIRECTORY_SEPARATOR)!==0)
                    $reason='callback_source_mismatch';
                elseif (hash_file('sha256',$source)!==$row['source_sha256'])
                    $reason='source_integrity_drift';
                elseif (!hash_equals($row['input_schema_sha256'],$schema_hash))
                    $reason='input_schema_drift';
                else {
                    $status='OWNER_PINNED_READ_SOURCE_VERIFIED';
                    $reason='exact_source_and_schema_verified';
                    $approved++;
                }
            }
            $seen[$name]=true;
            if ($matched<=$offset || count($items)>=$page_size) continue;
            $items[]=[
                'ability'=>$name,'state'=>$status,'reason'=>$reason,
                'read_source_eligible'=>$status==='OWNER_PINNED_READ_SOURCE_VERIFIED',
                'execution_authorized_by_catalog'=>false,
                'write_authorized'=>false,
            ];
        }
        $missing=[];
        foreach($manifest['rows'] as $name=>$row) {
            if (!isset($all[$name])) $missing[]=$name;
        }
        sort($missing,SORT_STRING);
        return [
            'contract'=>self::CONTRACT,'manifest_state'=>$manifest['state'],
            'discovered_count'=>$matched,'page_count'=>count($items), 'offset'=>$offset, 'limit'=>$page_size, 'prefix'=>$prefix, 'next_offset'=>($offset+count($items)<$matched)?$offset+count($items):null, 'truncated'=>false,
            'approved_read_count'=>$approved,'missing_pinned_abilities'=>$missing,
            'items'=>$items,'discovery_only'=>!$manifest['verified'],
            'execution_authorized_by_catalog'=>false,
            'write_authority_created'=>false,'mutation_performed'=>false,
            'owner_approval_required'=>!$manifest['verified'],
        ];
    }
}
