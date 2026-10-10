#!/usr/bin/env python3
"""Adversarial evidence-only tests for ACI01 dynamic plan compiler."""
from copy import deepcopy
from pathlib import Path
import importlib.util
import json

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("aci_dynamic_core", ROOT / "dynamic_core.py")
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

def read(name):
    return json.loads((ROOT / name).read_text(encoding='utf-8'))

model = {
    'tasks': read('task-registry.json'), 'gates': read('acceptance-gates.json'),
    'requirements': read('requirements.json'), 'system_map': read('system-map.json')
}
policy = read('dynamic-policy.json')
manifest = read('manifest.json')
recipes = read('content-recipes.json')
facts = read('domain-fact-authority.json')
use_cases = read('use-cases.json')
scope = {
    'tenant_id': 'tenant1', 'site_uuid': '11111111-2222-3333-4444-555555555555',
    'brand_id': 'demo', 'market': 'eg', 'locale': 'ar',
    'environment': 'disposable', 'runtime_generation': 'f'*64,
    'restore_epoch': 1, 'canonical_origin': 'https://example.invalid'
}
checks = 0

def check(assertion, label):
    global checks
    assert assertion, label
    checks += 1

def fail(fn, prefix):
    global checks
    try:
        fn()
    except m.SpecViolation as error:
        assert str(error).startswith(prefix), (str(error), prefix)
        checks += 1
        return
    raise AssertionError('expected explicit denial: '+prefix)

v = m.validate_model(**{
    'task_registry': model['tasks'], 'gate_registry': model['gates'],
    'requirement_registry': model['requirements'], 'system_map': model['system_map']
})
check(v['tasks'] == 71 and v['requirements'] == 43 and v['gates'] == 11, 'complete model')
check(len(m.topological(v['task_dag'])) == 71, 'acyclic execution graph')
check(len(m.topological(v['gate_dag'])) == 11, 'acyclic conditional certification graph')
check(policy['authorizing'] is False and policy['principle'] == 'DYNAMIC_BY_DEFAULT', 'dynamic authority')
check(recipes['default_content_recipe'] is None and len(recipes['recipes']) >= 6, 'portable recipes')
check(all(x.get('site_uuid') is None for x in facts['profiles']), 'fact profile cannot bind a live tenant')
check(len(use_cases['use_cases']) >= 25, 'use cases coverage')
check(len({x['id'] for x in use_cases['use_cases']}) == len(use_cases['use_cases']), 'unique scenarios')
check(all(x['authorizing'] is False for x in use_cases['use_cases']), 'scenarios never authorize')
check(manifest['status'] == 'SPEC_BACKLOG_ONLY', 'no release status promotion')
t = model['tasks']['tasks']
r = model['requirements']['requirements']
check(len({x['id'] for x in t}) == len(t), 'task IDs unique')
check(all(x['execution_effect'] == 'SPEC_ONLY' for x in t), 'no hidden executor')
check(all(len(x['requirement_ids']) <= 4 for x in t), 'semantic not blanket mapping')
check(all(set(x['task_ids']) for x in r), 'every requirement has actual tasks')

# Pure positive: one reviewed repository-spec task with current pretend capability certificate.
result = m.compile_candidate(
    {'task_ids':['ACI-T0001'], 'content_profile':{'native_relation_in_scope':False}},
    scope, {'capabilities':{'spec_integrity':{'certified':True,'runtime_generation':'f'*64}},
            'completed_tasks':[], 'certified_gates':['ACI-G0']}, model, policy)
check(result['status'] == 'READY_FOR_NON_AUTHORITATIVE_PLAN', 'pure candidate ready')
check(result['authorizing'] is False and result['eligible_for_mutation'] is False
      and result['mutation_performed'] is False and result['credentials_issued'] is False, 'no write promotion')
check('ACI-G4' not in result['applicable_certification_gates'], 'irrelevant native gate not required')

# Scope and provider denials.
for key,value,reason in [
    ('canonical_origin','http://example.invalid','origin_invalid'),
    ('runtime_generation','fake','runtime_generation_invalid'),
    ('restore_epoch',True,'restore_epoch_invalid'),
    ('environment','not-an-environment','environment_unknown'),
    ('site_uuid','wrong','site_uuid_invalid')
]:
    s=deepcopy(scope);s[key]=value
    fail(lambda s=s: m.compile_candidate({'task_ids':['ACI-T0001']},s,{},model,policy),reason)

missing=m.compile_candidate({'task_ids':['ACI-T0001']},scope,
    {'capabilities':{},'completed_tasks':[],'certified_gates':[]},model,policy)
check(missing['status'] == 'NEEDS_EVIDENCE', 'missing provider evidence denied')
prod=deepcopy(scope);prod['environment']='production'
v=m.compile_candidate({'task_ids':['ACI-T0001']},prod,
    {'capabilities':{'spec_integrity':{'certified':True,'runtime_generation':'f'*64}},
     'completed_tasks':[],'certified_gates':['ACI-G0']},model,policy)
check(v['status']=='DENIED' and 'production_authority_out_of_scope' in v['reason_codes'], 'production denied')
paid=m.compile_candidate({'task_ids':['ACI-T0001'],'effect_class':'PAID'},scope,
    {'capabilities':{'spec_integrity':{'certified':True,'runtime_generation':'f'*64}},
     'completed_tasks':[],'certified_gates':['ACI-G0']},model,policy)
check(paid['status'] == 'DENIED' and 'account_cost_reservation_unverified' in paid['reason_codes'], 'no paid fixture')
stale=m.compile_candidate({'task_ids':['ACI-T0001']},scope,
    {'capabilities':{'spec_integrity':{'certified':True,'runtime_generation':'a'*64}},
     'completed_tasks':[],'certified_gates':['ACI-G0']},model,policy)
check('stale_capability_generation' in stale['reason_codes'], 'stale generation')
wrong_policy=deepcopy(policy);wrong_policy['authorizing']=True
fail(lambda:m.compile_candidate({'task_ids':['ACI-T0001']},scope,{},model,wrong_policy),
     'policy_non_authorizing_required')

# Structural adversarial denials.
cycle=deepcopy(model)
cycle['tasks']['tasks'][0]['execution_dependencies']=['ACI-T0002']
fail(lambda:m.validate_model(cycle['tasks'],cycle['gates'],cycle['requirements'],cycle['system_map']), 'dag_cycle')
missing_ref=deepcopy(model)
missing_ref['requirements']['requirements'][0]['task_ids']=[]
fail(lambda:m.validate_model(missing_ref['tasks'],missing_ref['gates'],missing_ref['requirements'],
                             missing_ref['system_map']), 'requirement_reverse_mapping_invalid')
bogus_gate=deepcopy(model)
bogus_gate['gates']['gates'][0]['conditional_dependencies']=[{'gate':'ACI-G1','when':'unknown'}]
fail(lambda:m.validate_model(bogus_gate['tasks'],bogus_gate['gates'],bogus_gate['requirements'],
                             bogus_gate['system_map']), 'unreviewed_conditional_dependency')
shortcut=deepcopy(model)
shortcut['system_map']['edges'].append({'source':'growth_learning','target':'native_write_effect'})
fail(lambda:m.validate_model(shortcut['tasks'],shortcut['gates'],shortcut['requirements'],
                             shortcut['system_map']), 'write_bypass:')
shortcut2=deepcopy(model)
shortcut2['system_map']['edges'].append({'source':'publish_manifest','target':'governed_wordpress_dispatch'})
fail(lambda:m.validate_model(shortcut2['tasks'],shortcut2['gates'],shortcut2['requirements'],
                             shortcut2['system_map']), 'write_bypass:')
false_done=deepcopy(model)
false_done['gates']['gates'][0]['completion_claimed']=True
fail(lambda:m.validate_model(false_done['tasks'],false_done['gates'],false_done['requirements'],
                             false_done['system_map']), 'gate_authority_invalid')

# Profile-driven conditional certificates: an article cannot inherit a tour's
# native WPML requirement, and a caller's false does not waive a tour relation.
rich_model = deepcopy(model)
rich_model['content_recipes'] = read('content-recipes.json')
empty_evidence = {'capabilities':{}, 'completed_tasks':[], 'certified_gates':[]}
article = m.compile_candidate(
    {'task_ids':['ACI-T0033'], 'content_recipe_id':'article',
     'content_profile':{'native_relation_in_scope':False}},
    scope, empty_evidence, rich_model, policy)
check('ACI-G4' not in article['applicable_certification_gates'], 'article native test not mandatory')
tour = m.compile_candidate(
    {'task_ids':['ACI-T0033'], 'content_recipe_id':'tour',
     'content_profile':{'native_relation_in_scope':False}},
    scope, empty_evidence, rich_model, policy)
check('ACI-G4' in tour['applicable_certification_gates'], 'tour recipe prevents native bypass')
existing_growth = m.compile_candidate({'task_ids':['ACI-T0059']},
    scope, empty_evidence, rich_model, policy)
check('ACI-G8' not in existing_growth['applicable_certification_gates'],
      'analyzing existing content does not require a new publish')
new_growth = m.compile_candidate(
    {'task_ids':['ACI-T0059'], 'depends_on_new_publication':True, 'content_recipe_id':'tour'},
    scope, empty_evidence, rich_model, policy)
check('ACI-G8' in new_growth['applicable_certification_gates']
      and 'ACI-G4' in new_growth['applicable_certification_gates'],
      'newly published tours demand publishing and typed native proof')
fail(lambda: m.compile_candidate(
    {'task_ids':['ACI-T0033'], 'content_recipe_id':'unknown'},
    scope, empty_evidence, rich_model, policy), 'content_recipe_unknown_or_invalid')
# Demonstrate the planner's registry can grow without a hardcoded 71-task
# runtime assumption. Frozen Spec Kit inventories remain versioned in manifest.
extended = deepcopy(model)
extra = deepcopy(extended['tasks']['tasks'][0])
extra['id'] = 'ACI-T8888'
extended['tasks']['tasks'].append(extra)
extended['gates']['gates'][0]['task_ids'].append('ACI-T8888')
for requirement in extended['requirements']['requirements']:
    if requirement['id'] in extra['requirement_ids']:
        requirement['task_ids'].append('ACI-T8888')
v2 = m.validate_model(extended['tasks'], extended['gates'],
                      extended['requirements'], extended['system_map'])
check(v2['tasks'] == 72, 'additive semantic task registry without runtime code edits')

# Incident findings are restrictive: they never become permission or
# erase missing certified provider/epoch evidence.
rules = read('disposition-rules.json')
case_model = deepcopy(model)
case_model['disposition_rules'] = rules
for state_expected, finding in [
    ('NEEDS_REVIEW', 'edit_conflict'),
    ('QUARANTINED', 'poisoned_scrape'),
    ('DENIED', 'unapproved_cross_site_identity'),
]:
    # Scenario labels are not finding identifiers. Use the versioned rule
    # codes also declared by use-cases.json; unknown labels must quarantine.
    declared_rule = next(row for row in rules['rules'] if row['code'] == finding)
    check(declared_rule['state'] == state_expected, 'declared_rule_' + finding)
    result = m.compile_candidate(
        {'task_ids':['ACI-T0001']}, scope,
        {'capabilities':{'spec_integrity':{'certified':True,'runtime_generation':'f'*64}},
         'completed_tasks':[], 'certified_gates':['ACI-G0'], 'observed_findings':[finding]},
        case_model, policy)
    check(result['status'] == state_expected, 'restrictive_disposition_' + state_expected)
    check(result['operational_disposition'] == state_expected, 'classified_' + finding)
    check(result['trusted_authority_verified'] is False and
          result['mutation_performed'] is False, 'never_trusted_' + state_expected)

unknown = m.compile_candidate(
    {'task_ids':['ACI-T0001']}, scope,
    {'capabilities':{'spec_integrity':{'certified':True,'runtime_generation':'f'*64}},
     'completed_tasks':[], 'certified_gates':['ACI-G0'],
     'observed_findings':['UNRECOGNIZED_EXTERNAL_EFFECT']}, case_model, policy)
check(unknown['status'] == 'QUARANTINED', 'unknown issue never silently accepted')

no_promotion = m.compile_candidate(
    {'task_ids':['ACI-T0001']}, scope,
    {'capabilities':{}, 'completed_tasks':[], 'certified_gates':[],
     'observed_findings':['NO_FINDING']}, case_model, policy)
check(no_promotion['status'] == 'NEEDS_EVIDENCE'
      and 'capability_not_certified' in no_promotion['reason_codes'],
      'clean incident disposition cannot invent missing capability evidence')
check(no_promotion['trusted_authority_verified'] is False
      and no_promotion['authorizing'] is False
      and no_promotion['mutation_performed'] is False,
      'clean disposition never grants acceptance or authority')

print('mad4b.aci-os.dynamic-core.tests.v1: PASS', checks)
