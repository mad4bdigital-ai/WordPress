<?php
/**
 * Test-only Redis object-cache drop-in for Feature 007 catalog certification.
 *
 * It persists the WordPress "options" group across PHP processes and delegates
 * every other group to core WP_Object_Cache. It is copied only into disposable
 * CI WordPress; it is never distributed as the plugin's production drop-in.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'Redis' ) ) { throw new RuntimeException( 'MAD4B CI Redis extension unavailable.' ); }
require_once ABSPATH . WPINC . '/class-wp-object-cache.php';
define( 'MAD4B_CI_REDIS_OBJECT_CACHE', 'mad4b.ci-redis-object-cache.v1' );

class MAD4B_CI_Redis_Object_Cache extends WP_Object_Cache {
	private $redis;
	private $blog_id = 1;
	private $prefix = 'mad4b-ci-oc:';

	public function __construct() {
		parent::__construct();
		$this->blog_id = function_exists( 'get_current_blog_id' ) ? (int)get_current_blog_id() : 1;
		$this->redis = new Redis();
		$host = getenv( 'MAD4B_CI_REDIS_HOST' ) ?: '127.0.0.1';
		$port = (int)( getenv( 'MAD4B_CI_REDIS_PORT' ) ?: 6379 );
		if ( ! $this->redis->connect( $host, $port, 2.0 ) ) throw new RuntimeException( 'MAD4B CI Redis connection failed.' );
		$pong = $this->redis->ping();
		if ( false === $pong ) throw new RuntimeException( 'MAD4B CI Redis ping failed.' );
	}

	private function persistent_group( $group ) { return 'options' === (string)$group; }
	private function redis_key( $key, $group ) {
		return $this->prefix . $this->blog_id . ':' . (string)$group . ':' . rtrim( strtr( base64_encode( (string)$key ), '+/', '-_' ), '=' );
	}
	private function redis_pattern( $group = '*' ) { return $this->prefix . $this->blog_id . ':' . $group . ':*'; }

	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->persistent_group( $group ) ) return parent::set( $key, $data, $group, $expire );
		$payload = serialize( $data ); $rk = $this->redis_key( $key, $group );
		return $expire > 0 ? (bool)$this->redis->setex( $rk, (int)$expire, $payload ) : (bool)$this->redis->set( $rk, $payload );
	}
	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->persistent_group( $group ) ) return parent::add( $key, $data, $group, $expire );
		$rk = $this->redis_key( $key, $group );
		if ( ! $this->redis->setnx( $rk, serialize( $data ) ) ) return false;
		if ( $expire > 0 ) $this->redis->expire( $rk, (int)$expire );
		return true;
	}
	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->persistent_group( $group ) ) return parent::replace( $key, $data, $group, $expire );
		$rk = $this->redis_key( $key, $group );
		if ( ! $this->redis->exists( $rk ) ) return false;
		return $this->set( $key, $data, $group, $expire );
	}
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		if ( ! $this->persistent_group( $group ) ) return parent::get( $key, $group, $force, $found );
		$raw = $this->redis->get( $this->redis_key( $key, $group ) );
		if ( false === $raw ) { $found = false; ++$this->cache_misses; return false; }
		$found = true; ++$this->cache_hits;
		return unserialize( $raw );
	}
	public function delete( $key, $group = 'default', $deprecated = false ) {
		if ( ! $this->persistent_group( $group ) ) return parent::delete( $key, $group, $deprecated );
		return (bool)$this->redis->del( $this->redis_key( $key, $group ) );
	}
	public function add_multiple( array $data, $group = '', $expire = 0 ) { $out=array(); foreach($data as $k=>$v)$out[$k]=$this->add($k,$v,$group,$expire); return $out; }
	public function set_multiple( array $data, $group = '', $expire = 0 ) { $out=array(); foreach($data as $k=>$v)$out[$k]=$this->set($k,$v,$group,$expire); return $out; }
	public function get_multiple( $keys, $group = '', $force = false ) { $out=array(); foreach($keys as $k){$found=null;$out[$k]=$this->get($k,$group,$force,$found);} return $out; }
	public function delete_multiple( array $keys, $group = '' ) { $out=array(); foreach($keys as $k)$out[$k]=$this->delete($k,$group); return $out; }
	public function incr( $key, $offset = 1, $group = 'default' ) {
		if ( ! $this->persistent_group( $group ) ) return parent::incr( $key, $offset, $group );
		$found=null; $value=$this->get($key,$group,false,$found); if(!$found||!is_numeric($value))return false;
		$value=max(0,(int)$value+(int)$offset); return $this->set($key,$value,$group)?$value:false;
	}
	public function decr( $key, $offset = 1, $group = 'default' ) { return $this->incr( $key, -(int)$offset, $group ); }
	public function flush() {
		$it=null; do { $keys=$this->redis->scan($it,$this->redis_pattern('*'),200); if(is_array($keys)&&$keys)$this->redis->del($keys); } while($it!==0);
		parent::flush(); return true;
	}
	public function flush_group( $group ) {
		if ( ! $this->persistent_group( $group ) ) return method_exists( get_parent_class($this), 'flush_group' ) ? parent::flush_group($group) : false;
		$it=null; do { $keys=$this->redis->scan($it,$this->redis_pattern((string)$group),200); if(is_array($keys)&&$keys)$this->redis->del($keys); } while($it!==0);
		return true;
	}
	public function flush_runtime() { return method_exists( get_parent_class($this), 'flush_runtime' ) ? parent::flush_runtime() : true; }
	public function switch_to_blog( $blog_id ) { $this->blog_id=(int)$blog_id; if(method_exists(get_parent_class($this),'switch_to_blog')) parent::switch_to_blog($blog_id); }
	public function add_non_persistent_groups( $groups ) { unset( $groups ); return true; }
	public function close() { try { $this->redis->close(); } catch ( Throwable $e ) {} return true; }
	public function supports( $feature ) { return in_array((string)$feature,array('add_multiple','set_multiple','get_multiple','delete_multiple','flush_runtime','flush_group'),true); }
}

function wp_cache_init(){ $GLOBALS['wp_object_cache']=new MAD4B_CI_Redis_Object_Cache(); }
function wp_cache_add($k,$d,$g='',$e=0){global $wp_object_cache;return $wp_object_cache->add($k,$d,$g,(int)$e);}
function wp_cache_add_multiple(array $d,$g='',$e=0){global $wp_object_cache;return $wp_object_cache->add_multiple($d,$g,(int)$e);}
function wp_cache_replace($k,$d,$g='',$e=0){global $wp_object_cache;return $wp_object_cache->replace($k,$d,$g,(int)$e);}
function wp_cache_set($k,$d,$g='',$e=0){global $wp_object_cache;return $wp_object_cache->set($k,$d,$g,(int)$e);}
function wp_cache_set_multiple(array $d,$g='',$e=0){global $wp_object_cache;return $wp_object_cache->set_multiple($d,$g,(int)$e);}
function wp_cache_get($k,$g='',$f=false,&$found=null){global $wp_object_cache;return $wp_object_cache->get($k,$g,$f,$found);}
function wp_cache_get_multiple($ks,$g='',$f=false){global $wp_object_cache;return $wp_object_cache->get_multiple($ks,$g,$f);}
function wp_cache_delete($k,$g=''){global $wp_object_cache;return $wp_object_cache->delete($k,$g);}
function wp_cache_delete_multiple(array $ks,$g=''){global $wp_object_cache;return $wp_object_cache->delete_multiple($ks,$g);}
function wp_cache_incr($k,$o=1,$g=''){global $wp_object_cache;return $wp_object_cache->incr($k,$o,$g);}
function wp_cache_decr($k,$o=1,$g=''){global $wp_object_cache;return $wp_object_cache->decr($k,$o,$g);}
function wp_cache_flush(){global $wp_object_cache;return $wp_object_cache->flush();}
function wp_cache_flush_runtime(){global $wp_object_cache;return $wp_object_cache->flush_runtime();}
function wp_cache_flush_group($g){global $wp_object_cache;return $wp_object_cache->flush_group($g);}
function wp_cache_supports($f){global $wp_object_cache;return $wp_object_cache->supports($f);}
function wp_cache_close(){global $wp_object_cache;return $wp_object_cache->close();}
function wp_cache_add_global_groups($groups){global $wp_object_cache;return $wp_object_cache->add_global_groups($groups);}
function wp_cache_add_non_persistent_groups($groups){global $wp_object_cache;return $wp_object_cache->add_non_persistent_groups($groups);}
function wp_cache_switch_to_blog($blog_id){global $wp_object_cache;return $wp_object_cache->switch_to_blog($blog_id);}
function wp_cache_reset(){global $wp_object_cache;return $wp_object_cache->switch_to_blog(function_exists('get_current_blog_id')?get_current_blog_id():1);}
