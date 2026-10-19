<?php
declare(strict_types=1);
namespace ETG\DynamicFilterSEOBridge\Config {
    class Configuration {
        const OPTION_NAME='etg_dfsb_settings';
        public function all(){return \get_option(self::OPTION_NAME,array());}
        public function enabled(){return !empty($this->all()['enabled']);}
        public function validationErrors(){return !empty($GLOBALS['etg_test_invalid_config'])?array('simulated_bad_state'):array();}
        public function revision(){return substr(hash('sha256',\wp_json_encode($this->all())),0,16);}
        public function sanitizeForStorage($v){return $v;}
    }
    class ProfileRegistry {
        public function __construct($config){}
        public function all(){return array('tours'=>array('enabled'=>false));}
    }
}
namespace ETG\DynamicFilterSEOBridge\Diagnostics {
    class RuntimeInventory {
        public function collect(){return array('snapshot_fingerprint'=>'runtime-fixture-001');}
    }
    class InventoryProfilePlanner {
        public function plan($s,$p){return array('proposals'=>array('tours'=>array('safe_to_apply'=>true,
            'proposed_profile'=>array('post_types'=>array('tours-and-activities'),'routes'=>array(array('query_id'=>'tours_query_archive'))))));}
    }
}
namespace ETG\DynamicFilterSEOBridge\Presentation {
    class ContentSlotRegistry {
        public function revision(){return hash('sha256',\wp_json_encode($GLOBALS['etg_test_slots']));}
        public function normalize(array $slot){return array('id'=>\sanitize_key($slot['id']??''),'type'=>'text',
            'template'=>(string)($slot['template']??''),'enabled'=>!empty($slot['enabled']));}
        public function get(string $id){if(!isset($GLOBALS['etg_test_slots'][$id]))return array();
            return $GLOBALS['etg_test_slots'][$id]+array('origin'=>'custom');}
        public function save(array $slot,?string $expectedRevision=null):array {
            if(null!==$expectedRevision && !hash_equals($this->revision(),$expectedRevision))
                return array('saved'=>false,'reason'=>'stale_revision');
            $n=$this->normalize($slot);$GLOBALS['etg_test_slots'][$n['id']]=$n;
            return array('saved'=>true,'reason'=>'saved');
        }
        public function delete(string $id,?string $expectedRevision=null):bool{
            if($expectedRevision!==null&&!hash_equals($this->revision(),$expectedRevision))return false;
            if(!isset($GLOBALS['etg_test_slots'][$id]))return false;
            unset($GLOBALS['etg_test_slots'][$id]);return true;
        }
    }
}
namespace {
    define('ABSPATH',__DIR__);
    define('ETG_DFSB_VERSION','0.4.0-alpha.13');
    $GLOBALS['etg_test_env']='staging';
    $GLOBALS['etg_test_admin']=true;
    $GLOBALS['etg_test_context']=true;
    $GLOBALS['etg_test_invalid_config']=false;
    $GLOBALS['etg_test_slots']=array();
    $GLOBALS['etg_test_options']=array('etg_dfsb_settings'=>array('enabled'=>false,
        'profiles_json'=>\json_encode(array(array('id'=>'tours','enabled'=>false,'post_types'=>array('posts'),
            'publication'=>array('elementor_content_verified'=>true,'elementor_verification_evidence_id'=>'old-evidence'))))));
    function add_action($hook,$callback){}
    function wp_get_environment_type(){return $GLOBALS['etg_test_env'];}
    function current_user_can($cap){return 'manage_options'===$cap && $GLOBALS['etg_test_admin'];}
    function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
    function wp_generate_uuid4(){return 'fixture-uuid-'.bin2hex(random_bytes(4));}
    function wp_json_encode($value,$opts=0){return json_encode($value,$opts);}
    function is_wp_error($value){return $value instanceof WP_Error;}
    function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['etg_test_options'])?$GLOBALS['etg_test_options'][$key]:$default;}
    function update_option($key,$value,$autoload=false){$GLOBALS['etg_test_options'][$key]=$value;return true;}
    function add_option($key,$value,$deprecated='',$autoload=false){
        if(array_key_exists($key,$GLOBALS['etg_test_options']))return false;
        $GLOBALS['etg_test_options'][$key]=$value;return true;
    }
    function delete_option($key){unset($GLOBALS['etg_test_options'][$key]);return true;}
    class WP_Error {
        private $code;
        public function __construct($code,$message=''){$this->code=$code;}
        public function get_error_code(){return $this->code;}
    }
    class MAD4B_SCP_Policy {public static function can_mutate(){return true;}}
    class MAD4B_SCP_Reversible_Adapter_Mutations {
        public static function current_mutation_context(){
            return $GLOBALS['etg_test_context']?array('mutation_id'=>'fixture','adapter_id'=>'etg-dfsb-write'):array();
        }
    }
    abstract class MAD4B_SCP_Adapter_Base {
        abstract public function id();
        abstract public function label();
        abstract public function is_available();
        abstract public function ability_names();
        abstract public function register_abilities();
        protected function schema(array $p,array $r=array()){return array('type'=>'object','properties'=>$p,'required'=>$r);}
    }
    require_once dirname(__DIR__).'/includes/adapters/class-mad4b-scp-etg-dfsb-write-adapter.php';
    function assert_etg($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
    function error_etg($value,$code){return $value instanceof WP_Error && $value->get_error_code()===$code;}
    $writer=new MAD4B_SCP_ETG_DFSB_Write_Adapter();
    assert_etg($writer->is_available(),'writer available in Staging only');
    $slot=array('id'=>'intro','template'=>'Hello','enabled'=>true);
    $slots=new \ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry();
    $normalized=$slots->normalize($slot);
    $slotInput=array('confirmed'=>true,'slot'=>$slot,'expected_revision'=>$slots->revision(),
        'expected_proposed_sha256'=>hash('sha256',wp_json_encode($normalized)));
    assert_etg(error_etg($writer->capture_reversible_state($writer::SLOT_APPLY,
        array_merge($slotInput,array('expected_revision'=>str_repeat('a',64)))),'mad4b_etg_slot_stale'),'stale slot plan denied');
    $before=$writer->capture_reversible_state($writer::SLOT_APPLY,$slotInput);
    assert_etg(is_array($before)&&$before['target_id']==='intro'&&$before['state']['slot']===array(),'slot before-state captured');
    $GLOBALS['etg_test_context']=false;
    assert_etg(error_etg($writer->slot_apply($slotInput),'mad4b_etg_unjournaled_mutation_denied'),'direct unjournaled slot write blocked');
    $GLOBALS['etg_test_context']=true;
    $applied=$writer->slot_apply($slotInput);
    assert_etg(is_array($applied)&&!empty($applied['verified']),'journaled slot write verified');
    $target=$before['target'];$after=$writer->read_reversible_state($writer::SLOT_APPLY,$target);
    $afterHash=hash('sha256',wp_json_encode($after,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $restored=$writer->restore_reversible_state($writer::SLOT_APPLY,$target,$before['state'],array('after_sha256'=>$afterHash));
    assert_etg(is_array($restored)&&!empty($restored['verified'])&&!$slots->get('intro'),'slot restored through native delete');
    assert_etg(error_etg($writer->restore_reversible_state($writer::SLOT_APPLY,$target,$before['state'],
        array('after_sha256'=>str_repeat('b',64))),'mad4b_etg_undo_state_drift'),'stale undo denied');

    $config=new \ETG\DynamicFilterSEOBridge\Config\Configuration();
    $profileInput=array('confirmed'=>true,'profile_id'=>'tours',
        'expected_revision'=>$config->revision(),'snapshot_fingerprint'=>'runtime-fixture-001');
    assert_etg(error_etg($writer->capture_reversible_state($writer::PROFILE_APPLY,array_merge($profileInput,
        array('snapshot_fingerprint'=>'wrong'))),'mad4b_etg_snapshot_drift'),'inventory fingerprint guard');
    $profileBefore=$writer->capture_reversible_state($writer::PROFILE_APPLY,$profileInput);
    assert_etg(is_array($profileBefore)&&isset($profileBefore['state']['option']),'profile before state captured');
    $appliedProfile=$writer->profile_apply($profileInput);
    $newConfig=$config->all();$profiles=json_decode($newConfig['profiles_json'],true);
    assert_etg(is_array($appliedProfile)&&$appliedProfile['verified']&&$profiles[0]['enabled']===false,
        'disabled profile write verified');
    assert_etg($profiles[0]['publication']['elementor_content_verified']===false,
        'old publication evidence invalidated');
    $profileAfter=$writer->read_reversible_state($writer::PROFILE_APPLY,$profileBefore['target']);
    $profileAfterHash=hash('sha256',wp_json_encode($profileAfter,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    assert_etg(error_etg($writer->restore_reversible_state($writer::PROFILE_APPLY,
        $profileBefore['target'],$profileBefore['state'],array('after_sha256'=>str_repeat('f',64))),
        'mad4b_etg_undo_state_drift'),'profile undo rejects drift');
    // A failed write can leave invalid configuration: approved undo must remain available.
    $GLOBALS['etg_test_invalid_config']=true;
    $result=$writer->restore_reversible_state($writer::PROFILE_APPLY,
        $profileBefore['target'],$profileBefore['state'],array('after_sha256'=>$profileAfterHash));
    assert_etg(is_array($result)&&!empty($result['verified'])&&$config->all()===$profileBefore['state']['option'],
        'profile rollback repairs invalid after-state with exact previous option');
    $GLOBALS['etg_test_invalid_config']=false;
    $GLOBALS['etg_test_env']='production';
    assert_etg(!$writer->is_available(),'production unavailable');
    assert_etg(error_etg($writer->slot_apply($slotInput),'mad4b_etg_staging_only'),'production write prohibited');
    echo "PASS ETG governed writer: revision, fingerprint, journaling, readback, rollback, drift, production fence\n";
}
