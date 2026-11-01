import copy
import unittest
from full_host_authority import (CATALOG_CONTRACT,EVIDENCE_CONTRACT,HOST_CONTRACT,
                                 CAPABILITIES,InvalidAuthoritySource,plan)

class AuthorityTests(unittest.TestCase):
    def setUp(self):
        self.site={"company":"company-a","environment":"staging",
            "site_uuid":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa",
            "canonical_origin":"https://staging.example.org","host_id":"host-a"}
        self.other={"company":"company-b","environment":"production",
            "site_uuid":"bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb",
            "canonical_origin":"https://example.net","host_id":"host-a"}
        self.catalog={"contract":CATALOG_CONTRACT,"target_count":2,"targets":[self.site,self.other]}
        self.policies={"host-a":{"contract":HOST_CONTRACT,"host_id":"host-a",
            "principal":"root","revoked":False,"allow_general_shell":True,
            "allow_raw_sql":True,"scopes":{}}}
        self.evidence={}
    def proof(self,site,production=False):
        d={"contract":EVIDENCE_CONTRACT,**site,"assignment_revision":4,
           "wordpress_actor_user_id":17,
           "owner_approval_verified":True,"manager_delegation_verified":True,
           "host_proof_verified":True,"original_core_site_binding_verified":True,
           "independent_signer_verified":True,
           "original_305_matched_active_pair":True,
           "native_executor_independent_readback":True}
        if production:d.update(production_owner_approval_verified=True,rollback_ready=True)
        return d
    def test_all_registered_sites_get_full_request(self):
        x=plan(self.catalog,self.policies,{})
        self.assertEqual(x['target_count'],2)
        self.assertEqual(x['company_count'],2)
        self.assertEqual(x['targets'][0]['requested_capabilities'],list(CAPABILITIES))
        self.assertFalse(x['grants_created'])
        self.assertFalse(x['global_wildcard_authority_created'])
    def test_staging_and_production_candidate_separate(self):
        x=plan(self.catalog,self.policies,{})['targets']
        self.assertEqual(x[0]['proposed_exact_host_scope']['allow_production_mutation'],False)
        self.assertEqual(x[1]['proposed_exact_host_scope']['allow_production_mutation'],True)
        self.assertIn('INDEPENDENT_PRODUCTION_AUTHORITY_REQUIRED',x[1]['blockers'])
    def test_unverified_evidence_never_grants(self):
        self.evidence['company-a:staging']=self.proof(self.site)
        x=plan(self.catalog,self.policies,self.evidence)['targets'][0]
        self.assertFalse(x['existing_full_scope_verified'])
        self.assertEqual(x['state'],'PENDING_SITE_SPECIFIC_AUTHORITY')
    def test_full_scope_and_verified_proof_allows_readonly_classification(self):
        self.evidence['company-a:staging']=self.proof(self.site)
        self.policies['host-a']['scopes']['company-a:staging']={
            **self.site,"allow_operator":True,"allow_mutation":True,
            "allow_sql":True,"allow_production_mutation":False}
        x=plan(self.catalog,self.policies,self.evidence)['targets'][0]
        self.assertEqual(x['state'],'ADMISSION_EVIDENCE_COMPLETE_READ_ONLY')
        self.assertFalse(x['authorization_issued_by_this_plan'])
    def test_production_requires_separate_approval(self):
        self.evidence['company-b:production']=self.proof(self.other)
        self.policies['host-a']['scopes']['company-b:production']={
            **self.other,"allow_operator":True,"allow_mutation":True,
            "allow_sql":True,"allow_production_mutation":True}
        x=plan(self.catalog,self.policies,self.evidence)['targets'][1]
        self.assertFalse(x['exact_owner_manager_host_proofs_verified'])
        self.assertIn('INDEPENDENT_PRODUCTION_AUTHORITY_REQUIRED',x['blockers'])
    def test_production_with_proof_still_read_only(self):
        self.evidence['company-b:production']=self.proof(self.other,production=True)
        self.policies['host-a']['scopes']['company-b:production']={
            **self.other,"allow_operator":True,"allow_mutation":True,
            "allow_sql":True,"allow_production_mutation":True}
        x=plan(self.catalog,self.policies,self.evidence)['targets'][1]
        self.assertEqual(x['state'],'ADMISSION_EVIDENCE_COMPLETE_READ_ONLY')
        self.assertFalse(x['production_mutated'])
    def test_wrong_scope_identity_rejected(self):
        self.policies['host-a']['scopes']['company-a:staging']={"site_uuid":self.other['site_uuid'],
            "canonical_origin":self.site['canonical_origin']}
        with self.assertRaisesRegex(InvalidAuthoritySource,'IDENTITY_CONFLICT'):
            plan(self.catalog,self.policies,{})
    def test_wrong_manager_proof_does_not_pass(self):
        bad=self.proof(self.site);bad['canonical_origin']='https://other.example'
        x=plan(self.catalog,self.policies,{'company-a:staging':bad})['targets'][0]
        self.assertFalse(x['exact_owner_manager_host_proofs_verified'])
    def test_duplicate_site_rejected(self):
        self.catalog['targets'].append(copy.deepcopy(self.site));self.catalog['target_count']=3
        with self.assertRaisesRegex(InvalidAuthoritySource,'DUPLICATE'):
            plan(self.catalog,self.policies,{})
    def test_unregistered_evidence_rejected(self):
        with self.assertRaisesRegex(InvalidAuthoritySource,'UNKNOWN_TARGET'):
            plan(self.catalog,self.policies,{'company-x:staging':self.proof(self.site)})
    def test_missing_host_policy_rejected(self):
        self.policies={}
        with self.assertRaisesRegex(InvalidAuthoritySource,'POLICY_INVALID'):
            plan(self.catalog,self.policies,{})
    def test_revoked_host_policy_rejected(self):
        self.policies['host-a']['revoked']=True
        with self.assertRaisesRegex(InvalidAuthoritySource,'POLICY_INVALID'):
            plan(self.catalog,self.policies,{})
    def test_global_shell_disabled_blocks_ready(self):
        self.evidence['company-a:staging']=self.proof(self.site)
        self.policies['host-a']['allow_general_shell']=False
        self.policies['host-a']['scopes']['company-a:staging']={**self.site,
            "allow_operator":True,"allow_mutation":True,"allow_sql":True,
            "allow_production_mutation":False}
        self.assertFalse(plan(self.catalog,self.policies,self.evidence)['targets'][0]['existing_full_scope_verified'])
    def test_original_305_is_distinct(self):
        proof=self.proof(self.site);proof['original_305_matched_active_pair']=False
        self.evidence['company-a:staging']=proof
        self.policies['host-a']['scopes']['company-a:staging']={**self.site,
            "allow_operator":True,"allow_mutation":True,"allow_sql":True,
            "allow_production_mutation":False}
        x=plan(self.catalog,self.policies,self.evidence)['targets'][0]
        self.assertFalse(x['original_305_and_executor_readback_verified'])
    def test_invalid_origin_rejected(self):
        self.site['canonical_origin']='https://user@example.org'
        with self.assertRaisesRegex(InvalidAuthoritySource,'ORIGIN'):
            plan(self.catalog,self.policies,{})
    def test_boolean_actor_not_accepted(self):
        proof=self.proof(self.site);proof['wordpress_actor_user_id']=True
        self.evidence['company-a:staging']=proof
        self.assertFalse(plan(self.catalog,self.policies,self.evidence)['targets'][0]['exact_owner_manager_host_proofs_verified'])
    def test_no_mutation_to_input_sources(self):
        before=copy.deepcopy((self.catalog,self.policies,self.evidence))
        plan(self.catalog,self.policies,self.evidence)
        self.assertEqual(before,(self.catalog,self.policies,self.evidence))
    def test_invalid_catalog_fails_closed(self):
        self.catalog['contract']='invalid'
        with self.assertRaisesRegex(InvalidAuthoritySource,'ORIGINAL_CATALOG'):
            plan(self.catalog,self.policies,{})
    def test_other_site_with_same_hostname_no_global_grant(self):
        x=plan(self.catalog,self.policies,{})
        self.assertEqual(len(x['targets']),2)
        self.assertNotEqual(x['targets'][0]['site_uuid'],x['targets'][1]['site_uuid'])
        self.assertFalse(x['grants_created'])

if __name__=='__main__':unittest.main()
