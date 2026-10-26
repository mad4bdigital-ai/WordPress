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
            array( 'name' => 'code-quality-review', 'description' => 'Review code quality and defects.', 'logical_id' => 'workflow:code', 'sha256' => str_repeat('d', 64), 'level' => 'workflow', 'target' => 'default' ),
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
ok($match['source_page']['visited']===6 && $match['source_page']['next_offset']===6 && $match['source_page']['complete']===false && $match['source_page']['page_complete']===true,'pagination must not silently claim full coverage');
ok($match['candidates'][0]['name']==='api-design','topic-based match must prioritize exact skill');
ok($match['candidates'][0]['origin']==='external_untrusted','external candidate must be marked untrusted');
ok($match['candidates'][0]['executable']===false,'external candidate must not be executable');
$second=SCP_Skill_Task_Composition::match_task(array('task'=>'Investigate API design and debugging','topics'=>array('api','security'),'source_offset'=>6));
$skipped=SCP_Skill_Task_Composition::match_task(array('task'=>'Investigate API design and debugging','source_offset'=>40));
ok(!is_wp_error($skipped) && $skipped['source_page']['visited']===0 && $skipped['source_page']['page_complete']===true && $skipped['source_page']['complete']===false,'Out-of-range page must never imply globally complete coverage');

ok($second['source_page']['visited']===2 && $second['source_page']['next_offset']===null && $second['source_page']['complete']===false && $second['source_page']['page_complete']===true,'second page exhausted and complete');
$plan=SCP_Skill_Task_Composition::composition_plan(array('task'=>'Debug API security testing', 'topics'=>array('debug','api','security')));
ok(!is_wp_error($plan) && count($plan['steps'])>=1, 'plan must be generated');
ok($plan['execution_ready']===false && $plan['mode']==='read_only_plan','plan must not authorize execution');
ok(in_array('The registered external sources were only partially inspected or some source reads failed; selected matches are not exhaustive.',$plan['warnings'],true),'partial plan must disclose incomplete source coverage');
foreach($plan['steps'] as $step) ok($step['execution']==='not_authorized','every step must be non-authorizing');
$stageOrder=array('diagnose'=>10,'design'=>20,'implement'=>30,'verify'=>40,'release'=>50);
$previousStage=0; foreach($plan['steps'] as $step) { ok($stageOrder[$step['stage']]>=$previousStage,'dependent skill stages must be ordered'); $previousStage=$stageOrder[$step['stage']]; }
$again=SCP_Skill_Task_Composition::composition_plan(array('task'=>'Debug API security testing', 'topics'=>array('debug','api','security')));
ok($again['plan_sha256']===$plan['plan_sha256'],'stable inputs must yield stable plan digest');
$empty=SCP_Skill_Task_Composition::match_task(array('task'=>'zyxqv unknown irrelevant'));
ok($empty['matched_count']===0 && count($empty['candidates'])===0,'do not invent matches');
SCP_External_Skill_Sources::$fail=true;
$failed=SCP_Skill_Task_Composition::match_task(array('task'=>'security review'));
ok(count($failed['source_page']['errors'])===6 && $failed['source_page']['complete']===false && $failed['source_page']['page_complete']===false,'rate-limit failures must be explicit and deny full coverage');
ok(count($failed['candidates'])>=1,'keep valid managed candidates on source errors');

$followUp=SCP_Skill_Task_Composition::match_task(array('task'=>'تابع','task_context'=>'Review code security and debugging'));
ok(!is_wp_error($followUp) && $followUp['matched_count']>=2,'follow-up should match explicit prior task context');
$followNames=array_column($followUp['candidates'],'name');
ok(in_array('security-review',$followNames,true),'follow-up context should match security');
$followPlan=SCP_Skill_Task_Composition::composition_plan(array('task'=>'تابع','task_context'=>'Review code security and debugging'));
ok(!is_wp_error($followPlan) && $followPlan['mode']==='read_only_plan' && $followPlan['execution_ready']===false,'context does not grant execution');
$withoutContext=SCP_Skill_Task_Composition::match_task(array('task'=>'تابع'));
ok(!is_wp_error($withoutContext) && $withoutContext['matched_count']===0,'do not invent prior task context');
ok($followUp['task_sha256']!==$withoutContext['task_sha256'],'task context must be bound to request digest');
$badContext=SCP_Skill_Task_Composition::match_task(array('task'=>'تابع','task_context'=>str_repeat('x',4001)));
ok(is_wp_error($badContext),'oversized task context rejected');
$badContext=SCP_Skill_Task_Composition::match_task(array('task'=>'تابع','task_context'=>array('unexpected')));
ok(is_wp_error($badContext),'non-string task context rejected');
// Installed managed Skills take priority over externally named lookalikes.
$collision=SCP_Skill_Task_Composition::match_task(array('task'=>'Debug API','max_results'=>12));
ok(!is_wp_error($collision) && $collision['external_name_collisions_suppressed']>=1,'External duplicate name collision must be reported');
$managedDebug=false; $externalDebug=false; $externalApi=false;
foreach($collision['candidates'] as $candidate) {
    if($candidate['name']==='debugging') {
        if($candidate['origin']==='managed') $managedDebug=true;
        if($candidate['origin']==='external_untrusted') $externalDebug=true;
    }
    if($candidate['name']==='api-design' && $candidate['origin']==='external_untrusted') $externalApi=true;
}
ok($managedDebug && !$externalDebug,'Prefer local managed Skill on identical names');
ok($externalApi,'Unmatched specialist external Skills remain discoverable');
$collisionPlan=SCP_Skill_Task_Composition::composition_plan(array('task'=>'Debug API','max_results'=>12));
$managedStep=false; foreach($collisionPlan['steps'] as $step) {
    if($step['skill']['name']==='debugging' && $step['skill']['origin']==='managed') $managedStep=true;
}
ok($managedStep,'Composition must retain the managed Skill when names collide');
$reviewOnly=SCP_Skill_Task_Composition::match_task(array('task'=>'راجع'));
ok($reviewOnly['matched_count']>=1,'Arabic review imperative must not be dropped as stopword');
// Direct Arabic tasks must match useful English Skill names without caller-supplied topics.
$arabic=SCP_Skill_Task_Composition::match_task(array('task'=>'عالج الأخطاء واختبر الأمان'));
ok(!is_wp_error($arabic) && $arabic['matched_count']>=2,'Arabic routing must identify debugging and testing or security');
$arabicNames=array_column($arabic['candidates'],'name');
ok(in_array('debugging',$arabicNames,true),'Arabic fix request should match debugging');
ok(in_array('security-review',$arabicNames,true),'Arabic security request should match security');
// Distinct verification concerns must not be reduced to a single stage-wide choice.
$multi=SCP_Skill_Task_Composition::composition_plan(array('task'=>'Review code quality and security'));
ok(!is_wp_error($multi),'combined verification plan should resolve');
$multiNames=array_column(array_column($multi['steps'],'skill'),'name');
ok(in_array('security-review',$multiNames,true) && in_array('code-quality-review',$multiNames,true),'complementary verification skills must co-exist');
// TDD supports implementation and must precede security review rather than acting as post-hoc testing.
$tdd=SCP_Skill_Task_Composition::composition_plan(array('task'=>'Implement with test-driven-development and security review'));
$stages=array(); foreach($tdd['steps'] as $step) $stages[$step['skill']['name']]=$step['stage'];
ok(isset($stages['test-driven-development']) && $stages['test-driven-development']==='implement','TDD should be in implementation stage');
echo "Skill Task Composition fixtures: PASS\n";
