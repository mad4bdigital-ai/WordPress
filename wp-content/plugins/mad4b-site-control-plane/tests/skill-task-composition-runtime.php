<?php
// Standalone, deterministic tests. No WordPress bootstrap, network or writes.
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class MAD4B_SCP_Skill_Registry {
    public static function list_skills( $filters = array() ) {
        return array(
            array( 'name' => 'test-driven-development', 'description' => 'Implement features with tests.', 'logical_id' => 'workflow:test', 'sha256' => str_repeat('a', 64), 'level' => 'workflow', 'target' => 'default' ),
            array( 'name' => 'security-review', 'description' => 'Inspect security risks and code.', 'logical_id' => 'workflow:security', 'sha256' => str_repeat('b', 64), 'level' => 'workflow', 'target' => 'default' ),
            array( 'name' => 'debugging', 'description' => 'Debug failure symptoms and root causes.', 'logical_id' => 'workflow:debug', 'sha256' => str_repeat('c', 64), 'level' => 'workflow', 'target' => 'default' ),
        );
    }
}
class SCP_External_Skill_Sources {
    public static $fail = false;
    public static function sources() {
        $out = array();
        for ( $i=1; $i<=8; $i++ ) $out[] = array('id'=>str_pad((string)$i,24,'0',STR_PAD_LEFT),'repository'=>'owner/repo-'.$i,'ref'=>'main');
        return $out;
    }
    public static function list_skills( $input ) {
        if ( self::$fail ) return new WP_Error( 'rate_limited' );
        return array( 'tree_sha'=>str_repeat('d', 40), 'skills'=>array(
            array('name'=>'api-design','path'=>'skills/api-design/SKILL.md','blob_sha'=>str_repeat('e',40)),
            array('name'=>'debugging','path'=>'skills/debugging/SKILL.md','blob_sha'=>str_repeat('f',40)),
        ) );
    }
}
require_once dirname(__DIR__) . '/includes/class-skill-task-composition.php';
function ok($cond, $msg) { if(!$cond){fwrite(STDERR,"FAIL: $msg\n");exit(1);} }
$invalid=SCP_Skill_Task_Composition::match_task(array('task'=>'a'));
ok(is_wp_error($invalid),'short task must fail');
$invalid=SCP_Skill_Task_Composition::match_task(array('task'=>str_repeat('x',4001)));
ok(is_wp_error($invalid),'oversized task must fail');
$invalid=SCP_Skill_Task_Composition::match_task(array('task'=>'debug api','source_ids'=>array(str_repeat('9',24))));
ok(is_wp_error($invalid),'unregistered source must fail');
$invalid=SCP_Skill_Task_Composition::match_task(array('task'=>'debug api','source_offset'=>-1));
ok(is_wp_error($invalid),'bad source offset must fail');
$match=SCP_Skill_Task_Composition::match_task(array('task'=>'Investigate API design and debugging','topics'=>array('api','security')));
ok(!is_wp_error($match),'valid task must return result');
ok($match['source_page']['visited']===6 && $match['source_page']['next_offset']===6,'pagination must not silently drop extra sources');
ok($match['candidates'][0]['name']==='api-design','topic-based match must prioritize exact skill');
ok($match['candidates'][0]['origin']==='external_untrusted','external candidate must be marked untrusted');
ok($match['candidates'][0]['executable']===false,'external candidate must not be executable');
$second=SCP_Skill_Task_Composition::match_task(array('task'=>'Investigate API design and debugging','topics'=>array('api','security'),'source_offset'=>6));
ok($second['source_page']['visited']===2 && $second['source_page']['next_offset']===null,'second page exhausted');
$plan=SCP_Skill_Task_Composition::composition_plan(array('task'=>'Debug API security testing', 'topics'=>array('debug','api','security')));
ok(!is_wp_error($plan) && count($plan['steps'])>=1, 'plan must be generated');
ok($plan['execution_ready']===false && $plan['mode']==='read_only_plan','plan must not authorize execution');
foreach($plan['steps'] as $step) ok($step['execution']==='not_authorized','every step must be non-authorizing');
$stageOrder=array('diagnose'=>10,'design'=>20,'implement'=>30,'verify'=>40,'release'=>50);
$previousStage=0; foreach($plan['steps'] as $step) { ok($stageOrder[$step['stage']]>$previousStage,'dependent skill stages must be ordered'); $previousStage=$stageOrder[$step['stage']]; }
$again=SCP_Skill_Task_Composition::composition_plan(array('task'=>'Debug API security testing', 'topics'=>array('debug','api','security')));
ok($again['plan_sha256']===$plan['plan_sha256'],'stable inputs must yield stable plan digest');
$empty=SCP_Skill_Task_Composition::match_task(array('task'=>'zyxqv unknown irrelevant'));
ok($empty['matched_count']===0 && count($empty['candidates'])===0,'do not invent matches');
SCP_External_Skill_Sources::$fail=true;
$failed=SCP_Skill_Task_Composition::match_task(array('task'=>'security review'));
ok(count($failed['source_page']['errors'])===6,'rate-limit failures must be explicit');
ok(count($failed['candidates'])>=1,'keep valid managed candidates on source errors');
echo "Skill Task Composition fixtures: PASS\n";
