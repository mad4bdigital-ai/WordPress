"""Reconcile Original312 v3 public verifier with FIRST-PARTY resource signer.

Generates only a candidate one-file PHP verifier and Root read-only public
role mapping. Does not create a private signer, sign an Owner decision, install
WordPress files or enable Host Write. A Root publisher must separately back up
and hash/CAS the real installed file before selecting the candidate.
"""
from __future__ import annotations
import base64
import hashlib
import json
import re
from dataclasses import dataclass

CONTRACT="mad4b.original-v133.firstparty-public-role-migration.v1"
ROOT_ROLE_CONTRACT="mad4b.host-v133.original-v3-verified-public-roles.v1"
SHA64=re.compile(r"[a-f0-9]{64}\Z")
B64=re.compile(r"[A-Za-z0-9+/]{42,44}={0,2}\Z")
OLD_BINDING = (
  'define("MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64","%s");'
)
ORIGINAL313 = 'define("MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64","%s");'

class PinMigrationDenied(ValueError):pass

def need(ok,reason):
    if not ok:raise PinMigrationDenied(reason)

def _public(value):
    need(type(value) is str and len(value)<90,
         "V133_PUBLIC_KEY_ENCODING_INVALID")
    try:
        blob=base64.b64decode(value,validate=True)
    except (ValueError,TypeError) as error:
        raise PinMigrationDenied("V133_PUBLIC_KEY_ENCODING_INVALID") from error
    need(len(blob)==32 and base64.b64encode(blob).decode()==value,
         "V133_PUBLIC_KEY_NOT_ED25519")
    return blob

def sha(raw:bytes):
    return hashlib.sha256(raw).hexdigest()

@dataclass(frozen=True)
class Review:
    php: bytes
    public_role_registry: dict
    before_sha256: str
    after_sha256: str

def prepare_public_roles(current_php:bytes, index_raw:bytes,
                         policy_raw:bytes, profile_raw:bytes, *,
                         expected_current_sha256:str,
                         expected_site_uuid:str,
                         expected_origin:str,
                         expected_broker_sha256:str,
                         expected_public313_sha256:str,
                         expected_site_deployment_digest:str)->Review:
    """Explicit roots must supply proven original Root files, not API data."""
    need(all(type(item) is bytes and len(item)<100000 for item in
             (current_php,index_raw,policy_raw,profile_raw)),
         "V133_SOURCE_INPUT_INVALID")
    need(all(type(v) is str and SHA64.fullmatch(v) for v in
             (expected_current_sha256,expected_broker_sha256,
              expected_public313_sha256,expected_site_deployment_digest))
         and len(expected_site_uuid)==36
         and expected_origin=="https://tours.dev.mad4b.com",
         "V133_PINNED_SITE_PARAMETERS_INVALID")
    need(sha(current_php)==expected_current_sha256,
         "V133_LIVE_CURRENT_PREIMAGE_CHANGED")
    try:
        index=json.loads(index_raw)
        policy=json.loads(policy_raw)
        profile=json.loads(profile_raw)
        original=current_php.decode("utf-8")
    except (UnicodeError,ValueError,TypeError) as error:
        raise PinMigrationDenied("V133_ROOT_DOCUMENT_INVALID") from error
    need(type(index) is dict
         and index.get("installed_by_first_party") is True
         and index.get("contract")=="mad4b.dynamic-operation-index.v1",
         "V133_ORIGINAL_REGISTRY_NOT_FIRST_PARTY")
    need(type(policy) is dict
         and policy.get("contract")=="mad4b.native-source-publisher-policy.v2"
         and policy.get("enabled") is True
         and policy.get("central_approval_scope")=="tours-central:staging",
         "V133_ORIGINAL_MASTER_POLICY_NOT_ENROLLED")
    target=policy.get("targets",{}).get("tours-central:staging")
    need(type(target) is dict
         and target.get("site_uuid")==expected_site_uuid
         and target.get("origin")==expected_origin,
         "V133_ORIGINAL_PUBLISHER_SITE_CHANGED")
    need(type(profile) is dict
         and profile.get("contract")=="mad4b.site-profile.v2"
         and profile.get("version")==2
         and profile.get("environment")=="staging"
         and profile.get("site_uuid")==expected_site_uuid
         and profile.get("canonical_origin")==expected_origin
         and profile.get("deployment_binding_digest")==
              expected_site_deployment_digest,
         "V133_CENTRAL_SITE_PROFILE_DRIFT")
    need("MAD4B_HOST_V3_ROOT_TRUST_COLLISION" in original
         and f'"site_uuid" => "{expected_site_uuid}"' in original
         and f'"origin" => "{expected_origin}"' in original
         and f'define("MAD4B_HOST_V3_CENTRAL_DEPLOYMENT_BINDING_SHA256",'
             f'"{expected_site_deployment_digest}")' in original,
         "V133_EXISTING_ROOT_VERIFIER_IDENTITY_INVALID")
    master=policy.get("master_approval_public_key_b64")
    resource=index.get("host_resource_312_public_key_b64")
    broker=index.get("host_anchor_312_public_key_b64")
    independent=index.get("independent_verifier_public_key_b64")
    keys=[_public(k) for k in (master,resource,broker,independent)]
    need(len(set(keys))==4
         and sha(keys[2])==expected_broker_sha256
         and sha(keys[3])==expected_public313_sha256
         and master!=broker and resource!=broker,
         "V133_ORIGINAL_INDEPENDENT_SIGNER_PINS_INVALID")
    need(OLD_BINDING%broker in original
         and original.count(OLD_BINDING%broker)==1
         and ORIGINAL313%independent in original
         and original.count(ORIGINAL313%independent)==1,
         "V133_INSTALLED_LEGACY_BROKER_PUBLIC_BINDING_CHANGED")
    new_php=original.replace(OLD_BINDING%broker,OLD_BINDING%resource,1).encode()
    need(new_php!=current_php and
         OLD_BINDING%broker not in new_php.decode()
         and OLD_BINDING%resource in new_php.decode(),
         "V133_PUBLIC_ROOT_VERIFIER_UNCHANGED")
    summary={
      "contract":ROOT_ROLE_CONTRACT,
      "origin":expected_origin,
      "company":"mad4b-central",
      "environment":"staging",
      "site_uuid":expected_site_uuid,
      "site_profile_deployment_digest":expected_site_deployment_digest,
      "existing_firstparty_registry_sha256":sha(index_raw),
      "existing_firstparty_publisher_policy_sha256":sha(policy_raw),
      "existing_firstparty_original305_master_public_b64":master,
      "existing_firstparty_original312_resource_public_b64":resource,
      "existing_firstparty_original313_independent_public_b64":independent,
      "legacy_original_broker_public_b64":broker,
      "four_pinned_public_signers_distinct":True,
      "original305_master_registered_in_legacy_policy":True,
      "original312_resource_registered_in_legacy_registry":True,
      "original313_independent_registered_in_legacy_registry":True,
      "wordpress_public_verifier_current_sha256":sha(current_php),
      "wordpress_public_verifier_desired_sha256":sha(new_php),
      "root_public_role_mapping":"SOURCE_VERIFIED_STAGING_ONLY",
      "new_v3_owner_role_operationally_authorized":False,
      "human_owner_v3_stepup_signed":False,
      "original312_v3_issuer_enabled":False,
      "original313_v3_predecessor_signed":False,
      "original318_v3_signed_enrollment_installed":False,
      "standing_host_write_authorized":False,
      "production_write_authorized":False,
    }
    return Review(new_php,summary,sha(current_php),sha(new_php))
