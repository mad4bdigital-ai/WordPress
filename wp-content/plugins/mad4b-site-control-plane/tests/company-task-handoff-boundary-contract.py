#!/usr/bin/env python3
"""Source-only handoff boundary: login is not task authorization."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/'includes/class-mad4b-scp-company-task-handoff.php').read_text()
assert s.count("register_rest_route('mad4b/v1',")==3
assert "foreach(['offer','accept','reject','cancel'] as$action)" in s
for name in ('/company-tasks/handoffs',"/company-tasks/(?P<id>\\\\d+)/handoff/'.$action","/company-tasks/(?P<id>\\\\d+)/handoff'"):
 assert name in s,name
assert s.count("'permission_callback'=>static fn()=>is_user_logged_in()")==3
assert "return self::master() || (self::allowed($actor)&&in_array($actor,self::members($task),true));" in s
assert "if(!$task || !$board || $board!==absint($task->board_id))" in s
assert "if(!self::authorized($task)" in s or "!self::authorized($task)" in s
assert "'expected_revision'=>['type'=>'integer','required'=>true" in s
assert "'request_id'=>['type'=>'string','required'=>true]" in s
assert 'SELECT GET_LOCK(%s, 3)' in s
assert "'source_issue_untouched'=>true" in s
assert "'Cache-Control','private, no-store'" in s
print('COMPANY_TASK_HANDOFF_BOUNDARY: PASS login + company board, assignee, revision, lock checks')
