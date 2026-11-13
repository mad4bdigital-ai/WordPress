#!/usr/bin/env python3
"""Read-only, fail-closed release audit for dynamically catalogued source writes.

This does not activate the publisher, alter a policy, grant permissions,
or invoke an enrolled worker. Its two probes use temporary git repositories
and source-file fixtures; they intentionally report existing gaps.
"""
from __future__ import annotations
from copy import deepcopy
from datetime import datetime, timezone
import json
import ast
import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
TESTS = ROOT / "tests"
INSTALLED_REGISTRY = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "dynamic-operation-registry-v1/enrolled/index.json"
)
POLICY_PATH = Path("/etc/mad4b/native-source-publisher-policy.v2.json")
LIVE_WORDPRESS_ROOT = Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy")


def candidate_probes() -> dict:
    sys.path.insert(0, str(TESTS))
    from test_native_source_publisher_v1 import (
        SourcePublisher, JS2, lib,
    )

    fixture = SourcePublisher("test_two_operators_two_sites_one_engine")
    fixture.setUp()
    try:
        different = deepcopy(fixture.request1)
        different["files"][0]["source"] = JS2
        try:
            lib.make_plan(different, fixture.policy, fixture.registry)
            cross_source = "ACCEPTED"
        except lib.Denied:
            cross_source = "DENIED"
        derived = deepcopy(fixture.plan1)
        derived["changed_count"] = 999
        try:
            lib.check_plan(derived, fixture.policy, registry=fixture.registry)
            incorrect_count = "ACCEPTED"
        except lib.Denied:
            incorrect_count = "DENIED"
        return {
            "test_scope": "EPHEMERAL_GIT_FIXTURES_ONLY",
            "operation_discovery": "TWO_DISTINCT_PROVIDER_MANIFESTS",
            "different_git_source_for_same_target": cross_source,
            "changed_count_mismatch": incorrect_count,
            "site_files_touched": False,
        }
    finally:
        fixture.doCleanups()


def original_worker_contract_probes() -> dict:
    """Use the installed first-party *read-only* validators on temp plans.

    This does not call execute_claimed, create signed authority or claim a Job.
    It proves whether an arbitrary dynamically bound provider can pass the
    306 plan store and whether a core plan maps to a unique operation.
    """
    sys.path.insert(0, str(TESTS))
    sys.path.insert(0, str(INSTALLED_REGISTRY.parent))
    from test_native_source_publisher_v1 import SourcePublisher, lib
    import admission
    import registry
    import worker_adapter

    fixture = SourcePublisher("test_two_operators_two_sites_one_engine")
    fixture.setUp()
    try:
        plan = fixture.plan1
        with tempfile.TemporaryDirectory(prefix="mad4b-source-original313-contract-") as temp:
            plan_root = Path(temp)
            plan_root.chmod(0o700)
            path = plan_root / (plan["plan_sha256"] + ".json")
            path.write_text(json.dumps(plan, sort_keys=True))
            path.chmod(0o600)
            try:
                admission.checked_plan(plan_root, plan["plan_sha256"], fixture.manifest1)
                envelope = "ACCEPTED"
            except registry.RegistryDenied as exc:
                envelope = "DENIED:" + str(exc)

            core_doc = lib.core_plan(plan)
            path.write_text(json.dumps(core_doc, sort_keys=True))
            try:
                admission.checked_plan(plan_root, plan["plan_sha256"], fixture.manifest1)
                original = "ACCEPTED"
            except registry.RegistryDenied as exc:
                original = "DENIED:" + str(exc)

            class TempIndex:
                manifests = fixture.registry.manifests

                @staticmethod
                def command_sha256(op: str, plan_sha256: str) -> str:
                    # Test-only unique hashes; no original Broker claims.
                    return registry.sha(
                        json.dumps([op, plan_sha256], separators=(",", ":")).encode()
                    )

            mapped = worker_adapter.indexed_plans(TempIndex(), plan_root)
            operations = sorted({record["operation"] for record in mapped.values()})
            return {
                "test_scope": "ORIGINAL_VALIDATORS_ON_EPHEMERAL_PLAN_STORE",
                "original313_accepts_dynamic_envelope": envelope,
                "original313_accepts_plain_core_plan": original,
                "one_core_plan_indexed_under_operations": operations,
                "operation_exclusive": operations == [fixture.manifest1["operation_id"]],
                "job_issued": False,
                "grant_issued": False,
                "installed_worker_modified": False,
            }
    finally:
        fixture.doCleanups()


def verified_registry_candidates(candidates: dict[str, dict]) -> set[str]:
    """Never trust index name presence as installed executable verification.

    The installed first-party Registry rechecks root ownership, pinned
    manifest, provider handler, verifier, source dependencies and evidence
    schema SHAs. Finally require the installed contents equal candidate
    bytes exactly. This is still not a signed original 312/313 authority.
    """
    if not INSTALLED_REGISTRY.is_file() or INSTALLED_REGISTRY.is_symlink():
        return set()
    path=INSTALLED_REGISTRY.parent/"registry.py"
    if not path.is_file() or path.is_symlink():
        return set()
    spec=importlib.util.spec_from_file_location(
        "_mad4b_dynamic_readonly_firstparty_registry",path
    )
    if spec is None or spec.loader is None:
        return set()
    module=importlib.util.module_from_spec(spec)
    try:
        spec.loader.exec_module(module)
        installed=module.Registry(INSTALLED_REGISTRY.parent)
        return {
            op for op, candidate in candidates.items()
            if op in installed.manifests and
            installed.resolve(op)==candidate
        }
    except (OSError,ValueError,KeyError,RuntimeError,TypeError):
        return set()


def digest_file(path: Path) -> str:
    import hashlib
    return hashlib.sha256(path.read_bytes()).hexdigest()


def signed_association_admission_probe() -> dict:
    """Check installed Original306 has the new verified association contract.

    Source implementation may be test-correct while first-party worker lacks
    the hook. Do not call first-party Job execution or create a real signer.
    """
    import ast
    installed_worker = INSTALLED_REGISTRY.parent / "worker_adapter.py"
    tracked_worker = ROOT / "ops/dynamic-operation-registry-v1/worker_adapter.py"
    installed_worker_sha256 = (
        digest_file(installed_worker) if installed_worker.is_file() else None
    )
    tracked_worker_sha256 = (
        digest_file(tracked_worker) if tracked_worker.is_file() else None
    )
    source_worker_matches_installed = (
        installed_worker_sha256 is not None and
        installed_worker_sha256 == tracked_worker_sha256
    )
    candidate_module = HERE / "operation_plan_association.py"
    candidate_index = HERE / "associated_worker_index.py"
    candidate_exists = candidate_module.is_file()
    candidate_index_exists = candidate_index.is_file()
    candidate_index_has_signed_resolver = False
    if candidate_index_exists:
        source_tree = ast.parse(candidate_index.read_text())
        candidate_index_has_signed_resolver = any(
            isinstance(node, ast.Attribute) and
            node.attr == "exclusive_operation"
            for node in ast.walk(source_tree)
        )
    # The signed association callback is loaded dynamically by the
    # SHA-pinned first-party Registry. A static import/entrypoint scan will
    # always report a false negative even after that approved deployment.
    # Treat exact reviewed Worker+Registry bytes AND a separately enrolled
    # root-private issuer anchor as the minimum observable admission proof.
    source_registry = ROOT / "ops/dynamic-operation-registry-v1/registry.py"
    enrolled_registry = INSTALLED_REGISTRY.parent / "registry.py"
    registry_bytes_match = (
        source_registry.is_file() and enrolled_registry.is_file() and
        digest_file(source_registry) == digest_file(enrolled_registry)
    )
    anchor_enrolled = False
    if registry_bytes_match and INSTALLED_REGISTRY.is_file() and (
        not INSTALLED_REGISTRY.is_symlink()
    ):
        try:
            import base64
            import stat
            index = json.loads(INSTALLED_REGISTRY.read_bytes())
            anchor = index.get("operation_association_authority")
            if isinstance(anchor, dict) and set(anchor) == {
                "contract", "store", "issuer_public_key_b64"
            } and anchor["contract"] == (
                "mad4b.original306.plan-association-trust.v1"
            ):
                store = Path(anchor["store"])
                issuer = base64.b64decode(anchor["issuer_public_key_b64"], validate=True)
                verifier = base64.b64decode(
                    index["independent_verifier_public_key_b64"], validate=True
                )
                if (len(issuer) == 32 and len(verifier) == 32 and
                    issuer != verifier and store.is_absolute() and
                    store.as_posix() == anchor["store"] and
                    ".." not in store.parts and not store.is_symlink() and
                    store.is_dir()):
                    st = store.stat()
                    anchor_enrolled = (
                        st.st_uid == 0 and stat.S_ISDIR(st.st_mode) and
                        not stat.S_IMODE(st.st_mode) & 0o077
                    )
        except (ValueError, TypeError, KeyError, OSError):
            anchor_enrolled = False
    worker_hook_present = (
        source_worker_matches_installed and registry_bytes_match and
        anchor_enrolled and candidate_exists
    )
    return {
        "source_only_signed_association_contract_present": candidate_exists,
        "source_only_associated_worker_index_present": candidate_index_exists,
        "source_only_associated_worker_index_uses_signed_resolver":
            candidate_index_has_signed_resolver,
        "installed_original306_enforces_exclusive_association": worker_hook_present,
        "tracked_original306_worker_sha256": tracked_worker_sha256,
        "installed_original306_worker_sha256": installed_worker_sha256,
        "tracked_and_installed_original306_worker_match":
            source_worker_matches_installed,
        "installed_worker_updated": False,
        "original306_job_issued": False,
        "independent_signing_authority_enrolled": anchor_enrolled,
        "enrolled_registry_matches_reviewed_source": registry_bytes_match,
    }


def verifier_environment_contract_probe() -> dict:
    """Observe a verifier/schema mismatch without executing signed 313.

    Installed evidence.verify_signed requires the production_mutation flag
    to match the job environment. A source verifier that always emits false
    cannot satisfy a Production proof. This is a static contract audit only.
    """
    verifier=HERE/"original_313_independent_readback.py"
    tree=ast.parse(verifier.read_text())
    value=None
    for func in tree.body:
        if isinstance(func,ast.FunctionDef) and func.name=="evidence_for_original_job":
            for node in ast.walk(func):
                if isinstance(node,ast.Dict):
                    for key,item in zip(node.keys,node.values):
                        if (isinstance(key,ast.Constant) and
                            key.value=="production_mutation"):
                            value=(item.value if isinstance(item,ast.Constant) else "DYNAMIC")
    installed_evidence=INSTALLED_REGISTRY.parent/"evidence.py"
    authority_requires_environment_match=(
        installed_evidence.is_file() and
        'evidence.get("production_mutation") is (proof["environment"]=="production")'
        in installed_evidence.read_text()
    )
    # Do not accept just a nonconstant expression (which could merely trust
    # a caller flag). Execute the candidate's pure receipt-scope check against
    # both environments plus forged-scope and forged-success fixtures.
    receipt_bound_contract_verified=False
    try:
        module_spec=importlib.util.spec_from_file_location(
            "_mad4b_original313_env_receipt_contract",verifier
        )
        if module_spec is None or module_spec.loader is None:
            raise ValueError("ORIGINAL313_RECEIPT_VERIFIER_UNAVAILABLE")
        original_path=list(sys.path)
        try:
            sys.path.insert(0,str(HERE))
            candidate=importlib.util.module_from_spec(module_spec)
            module_spec.loader.exec_module(candidate)
        finally:
            sys.path[:]=original_path
        plan={
            "environment":"staging", "company":"source-fixture",
            "site_uuid":"00000000-0000-4000-8000-000000000001",
            "git_commit":"a"*40, "plan_sha256":"b"*64,
            "files":[{"target":"wp-content/plugins/mad4b-fixture/one.php"}],
        }
        grant={"nonce":"c"*32}
        receipt={
            "contract":candidate.publisher.core.RECEIPT_CONTRACT,
            "state":"APPLIED_VERIFIED", "company":plan["company"],
            "environment":plan["environment"], "site_uuid":plan["site_uuid"],
            "git_commit":plan["git_commit"], "plan_sha256":plan["plan_sha256"],
            "nonce":grant["nonce"], "files":[plan["files"][0]["target"]],
            "failed_reason":None, "broker_grant_signature_verified":True,
            "broker_receipt_verified":False, "original_313_receipt":False,
        }
        expected_false=candidate.receipt_bound_production_mutation(plan,receipt,grant) is False
        prod_plan=dict(plan,environment="production")
        prod_receipt=dict(receipt,environment="production")
        expected_true=candidate.receipt_bound_production_mutation(prod_plan,prod_receipt,grant) is True
        rejects_unsafe=True
        for bad in (dict(prod_receipt,company="wrong"),
                    dict(prod_receipt,state="ROLLED_BACK"),
                    dict(prod_receipt,files=[]),
                    dict(prod_receipt,nonce="f"*32),
                    dict(prod_receipt,environment="staging")):
            try:
                candidate.receipt_bound_production_mutation(prod_plan,bad,grant)
                rejects_unsafe=False
            except candidate.publisher.Denied:
                pass
        receipt_bound_contract_verified=expected_false and expected_true and rejects_unsafe
    except (ImportError,OSError,ValueError,KeyError,AttributeError,RuntimeError,TypeError):
        receipt_bound_contract_verified=False
    return {
        "candidate_production_mutation_expression":value,
        "original313_requires_env_bound_mutation_flag":authority_requires_environment_match,
        "receipt_scope_and_environment_contract_fixture_verified":receipt_bound_contract_verified,
        "production_proof_compatible":(
            value=="DYNAMIC" and authority_requires_environment_match
            and receipt_bound_contract_verified
        ),
        "original_independent_signer_modified":False,
        "signed_proof_created":False,
    }


def central_source_bridge_static_parity(source_root: Path = ROOT,
                                        live_root: Path = LIVE_WORDPRESS_ROOT) -> dict:
    """Compare source and actual installed WordPress bytes, not a Git marker.

    Static parity cannot prove the Abilities API booted, a client is
    authorized, or the first-party Original306 provider is enrolled.
    """
    part = Path("wp-content/plugins/mad4b-site-control-plane")
    main_rel = part / "mad4b-site-control-plane.php"
    module_rel = part / "includes/class-mad4b-scp-native-source-release-review.php"
    require_line = ("require_once MAD4B_SCP_DIR . "
                    "'includes/class-mad4b-scp-native-source-release-review.php';")
    boot_line = "MAD4B_SCP_Native_Source_Release_Review::boot();"
    def valid(root: Path) -> bool:
        main, module = root / main_rel, root / module_rel
        if (not main.is_file() or main.is_symlink() or
            not module.is_file() or module.is_symlink()):
            return False
        try:
            lines = [line.strip() for line in main.read_text().splitlines()]
        except (OSError,UnicodeError):
            return False
        return require_line in lines and boot_line in lines
    source_valid = valid(source_root)
    live_valid = valid(live_root)
    matching = False
    if source_valid and live_valid:
        try:
            candidate = (source_root / module_rel).read_bytes()
            installed = (live_root / module_rel).read_bytes()
            matching = bool(candidate and candidate == installed)
        except OSError:
            matching = False
    return {"source_candidate_present": source_valid,
            "installed_source_byte_parity": matching,
            "wordpress_ability_runtime_verified": False,
            "signed_authority_verified": False}


def installed_status() -> dict:
    """Read all candidate manifest definitions; never assume an operation ID."""
    generated = HERE / "catalog/generated"
    candidates = {}
    for candidate_file in sorted(generated.glob("*.manifest.json")):
        if candidate_file.is_symlink() or not candidate_file.is_file():
            raise ValueError("UNSAFE_SOURCE_MANIFEST_CANDIDATE")
        candidate = json.loads(candidate_file.read_text())
        op = candidate.get("operation_id")
        if (not isinstance(op, str) or
                candidate_file.name != op + ".manifest.json" or
                op in candidates):
            raise ValueError("INVALID_DYNAMIC_MANIFEST_OR_DUPLICATE")
        candidates[op] = candidate
    if not candidates:
        raise ValueError("SOURCE_CATALOG_CANDIDATES_EMPTY")

    index_entries = {}
    if INSTALLED_REGISTRY.is_file() and not INSTALLED_REGISTRY.is_symlink():
        index = json.loads(INSTALLED_REGISTRY.read_text())
        for row in index.get("providers", []):
            if not isinstance(row, dict):
                continue
            path = row.get("manifest", "")
            if isinstance(path, str) and path.endswith("/manifest.json"):
                operation = Path(path).parent.name
                index_entries[operation] = row
    unregistered = sorted(set(candidates) - set(index_entries))
    verified=verified_registry_candidates(candidates)
    not_verified=sorted(set(candidates)-verified)
    # Presence in an index is not signature verification. This audit never
    # claims that an installed provider is authorized without the first-party
    # 306/313 readback and independently signed receipts.
    bridge = central_source_bridge_static_parity()
    # Candidate source is NOT evidence of installation on WordPress. A
    # successful installed-source check is still not a live ability or signed
    # write authorization, and requires a separate WordPress runtime readback.
    bootstrapped = bridge["installed_source_byte_parity"]
    return {
        "candidate_operations": sorted(candidates),
        "unregistered_candidate_operations": unregistered,
        "all_candidate_operations_present_in_index": not unregistered,
        "registry_hash_verified_candidate_operations": sorted(verified),
        "registry_unverified_candidate_operations": not_verified,
        "all_candidate_manifests_sha_verified": not not_verified,
        "native_policy_file_present": POLICY_PATH.exists(),
        "central_source_review_module_bootstrapped": bootstrapped,
        "central_source_review_source_candidate_present": bridge["source_candidate_present"],
        "central_source_review_live_static_byte_parity": bridge["installed_source_byte_parity"],
        "central_source_review_wordpress_ability_runtime_verified": False,
        "first_party_signed_enrollment_verified": False,
    }


def original_historical_grants_probe() -> dict:
    """Independent read-only signed-grant inventory by DISTINCT legacy plan.

    This never interprets historical grant validity as a fresh original312
    authority and never refreshes expired Grants or accesses signing keys.
    """
    try:
        from datetime import datetime,timezone
        root=INSTALLED_REGISTRY.parent
        grants_path=Path("/var/lib/mad4b/company-code-release/grants")
        policy_path=Path("/etc/mad4b/company-code-release-policy.json")
        grant_script=HERE/"original_historical_grants_audit.py"
        def load(alias,path):
            spec=importlib.util.spec_from_file_location(alias,path)
            if spec is None or spec.loader is None:
                raise ValueError("FIRST_PARTY_SCRIPT_NOT_AVAILABLE")
            module=importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            return module

        history=load("_mad4b_historical_grants_readonly",grant_script)
        legacy=load(
            "_mad4b_first_party_code_release_grants",
            root/"company-code-release-v1/company_code_release.py"
        )
        absent=object()
        modules=("registry","admission","worker_adapter")
        prior={name:sys.modules.get(name,absent) for name in modules}
        try:
            registry_mod=load("_mad4b_private_grant_registry",root/"registry.py")
            sys.modules["registry"]=registry_mod
            admission_mod=load("_mad4b_private_grant_admission",root/"admission.py")
            sys.modules["admission"]=admission_mod
            worker_mod=load("_mad4b_private_grant_worker",root/"worker_adapter.py")
            index=registry_mod.Registry(root)
            original_commands=worker_mod.indexed_plans(
                index,Path("/var/lib/mad4b/dynamic-operation-registry/plans")
            )
        finally:
            for name,value in prior.items():
                if value is absent:
                    sys.modules.pop(name,None)
                else:
                    sys.modules[name]=value

        plans={
            row["plan"]["plan_sha256"]:row["plan"]
            for row in original_commands.values()
            if row["plan"]["contract"]==legacy.PLAN_CONTRACT
        }
        policy=legacy.trusted_config(policy_path)
        return history.summarize(
            plans,grants_path,
            broker_public_key_b64=policy["broker_public_key_b64"],
            signed_message=legacy.grant_message,
            expected_contract=legacy.AUTH_CONTRACT,
            now=datetime.now(timezone.utc),
        )
    except Exception as exc:
        return {
            "contract":"mad4b.original305.historical-signed-grant-audit.v1",
            "state":"HOLD__GRANT_HISTORY_UNVERIFIED",
            "reason":type(exc).__name__,
            "original_plans_in_scope":None,
            "distinct_plans_with_valid_grant_evidence":None,
            "distinct_plans_without_matching_grant_evidence":None,
            "current_original305_job_verified":False,
            "fresh_original312_verified":False,
            "grants_extended_or_issued":False,
            "plans_or_wordpress_mutated":False,
        }


def original_job_lifecycle_contract_probe() -> dict:
    """A pure fail-closed lifecycle classifier is staged, not installed.

    No WP broker snapshot is available through the admitted read surface;
    do not pretend an unverified preview is first-party evidence.
    """
    path=HERE/"original_job_lifecycle_preview.py"
    redacted=HERE/"compact_job_readback_classifier.py"
    readback=ROOT/"wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-original-job-readback.php"
    boot=ROOT/"wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php"
    source_only=(readback.is_file() and boot.is_file() and
       "MAD4B_SCP_Original_Job_Readback" not in boot.read_text() and
       "class-mad4b-scp-original-job-readback.php" not in boot.read_text())
    return {
        "candidate_lifecycle_classifier_available":path.is_file(),
        "source_only_redacted_lifecycle_classifier_present":redacted.is_file(),
        "source_only_wp_job_claim_readback_php_present":readback.is_file(),
        "source_only_readback_not_bootstrapped":source_only,
        "source_candidate_readback_bootstrapped":bool(readback.is_file() and boot.is_file() and
            "MAD4B_SCP_Original_Job_Readback::boot();" in boot.read_text() and
            "class-mad4b-scp-original-job-readback.php" in boot.read_text()),
        # A Git branch is not proof of a live first-party WordPress Ability.
        "first_party_current_job_claim_readback_available":False,
        "historical_grants_transformed_into_current_authority":False,
        "release_authorized":False,
    }


def original_finish_evidence_probe() -> dict:
    """Read installed plans and broker-signed historical FINISH evidence only.

    Import installed 306 modules under temporary private names and restore the
    process module cache, so this audit cannot contaminate other Registry
    tests. Historical signatures do not constitute fresh 305/312 or 313.
    """
    module=HERE/"original_finish_readonly_audit.py"
    root=INSTALLED_REGISTRY.parent
    plan_store=Path("/var/lib/mad4b/dynamic-operation-registry/plans")
    policy_path=Path("/etc/mad4b/company-code-release-policy.json")
    try:
        sp=importlib.util.spec_from_file_location(
            "_mad4b_original_finish_readonly",module
        )
        if sp is None or sp.loader is None:
            raise ValueError("HISTORICAL_FINISH_AUDIT_NOT_AVAILABLE")
        verified=importlib.util.module_from_spec(sp)
        sp.loader.exec_module(verified)

        absent=object()
        names=("registry","admission","worker_adapter")
        prior={name:sys.modules.get(name,absent) for name in names}
        try:
            def fresh(name: str):
                path=root/(name+".py")
                found=importlib.util.spec_from_file_location(
                    "_mad4b_private_finish_"+name,path
                )
                if found is None or found.loader is None:
                    raise ValueError("FIRST_PARTY_COMPONENT_NOT_AVAILABLE")
                mod=importlib.util.module_from_spec(found)
                found.loader.exec_module(mod)
                sys.modules[name]=mod
                return mod
            reg=fresh("registry")
            fresh("admission")
            worker=fresh("worker_adapter")
            index=reg.Registry(root)
            original_commands=worker.indexed_plans(index,plan_store)
        finally:
            for name,value in prior.items():
                if value is absent:
                    sys.modules.pop(name,None)
                else:
                    sys.modules[name]=value

        policy=json.loads(policy_path.read_text())
        return verified.summarize(
            original_commands,verified.DEFAULT_RECEIPTS,
            policy["broker_public_key_b64"]
        )
    except Exception as exc:
        return {
            "contract":"mad4b.original305312.historical-finish-correlation.v1",
            "state":"HOLD__ORIGINAL_RECEIPT_EVIDENCE_UNVERIFIED",
            "reason":type(exc).__name__,
            "installed_command_count":None,
            "commands_with_verified_historical_signed_finish":None,
            "original305_current_state_verified":False,
            "original312_current_eligibility_verified":False,
            "live_site_mutated":False,
        }


def legacy_plan_compatibility_probe() -> dict:
    """Read first-party installed plan-store metadata for enrollment impact.

    Do not infer active Job state from stored plan files. This is a risk
    count and must be verified against signed Original305/312 before migration.
    """
    module_path=HERE/"plan_store_compatibility_audit.py"
    provider_root=INSTALLED_REGISTRY.parent
    try:
        loader=importlib.util.spec_from_file_location(
            "_mad4b_private_plan_store_compatibility",module_path
        )
        if loader is None or loader.loader is None:
            raise ValueError("PLAN_COMPATIBILITY_MODULE_MISSING")
        module=importlib.util.module_from_spec(loader)
        loader.loader.exec_module(module)
        registry_spec=importlib.util.spec_from_file_location(
            "_mad4b_private_plan_compat_registry",provider_root/"registry.py"
        )
        if registry_spec is None or registry_spec.loader is None:
            raise ValueError("FIRST_PARTY_REGISTRY_NOT_AVAILABLE")
        first_party=importlib.util.module_from_spec(registry_spec)
        registry_spec.loader.exec_module(first_party)
        verified=first_party.Registry(provider_root)
        candidates={}
        for path in sorted((HERE/"catalog/generated").glob("*.manifest.json")):
            if path.is_symlink():
                raise ValueError("UNTRUSTED_CANDIDATE_MANIFEST_LINK")
            manifest=json.loads(path.read_text())
            op=manifest.get("operation_id")
            if (not isinstance(op,str) or path.name!=op+".manifest.json" or
                op in candidates):
                raise ValueError("DUPLICATE_OR_INVALID_CANDIDATE")
            candidates[op]=manifest
        return module.summarize(verified,module.PLAN_ROOT,candidates)
    except Exception as exc:
        return {
            "contract":"mad4b.native-source-plan-store-compatibility-audit.v1",
            "state":"HOLD__LEGACY_PLAN_AUDIT_UNVERIFIED",
            "plan_count":None,
            "ambiguous_existing_plan_count":None,
            "reason":type(exc).__name__,
            "plan_store_modified":False,
            "provider_enrolled":False,
        }


def evaluate() -> dict:
    probes = candidate_probes()
    original_contract = original_worker_contract_probes()
    association = signed_association_admission_probe()
    verifier_environment = verifier_environment_contract_probe()
    installed = installed_status()
    legacy_plan_impact = legacy_plan_compatibility_probe()
    historical_finishes = original_finish_evidence_probe()
    historical_grants = original_historical_grants_probe()
    lifecycle = original_job_lifecycle_contract_probe()
    blockers = []
    if probes["different_git_source_for_same_target"] != "DENIED":
        blockers.append("SOURCE_TARGET_EQUIVALENCE_NOT_ENFORCED")
    if probes["changed_count_mismatch"] != "DENIED":
        blockers.append("DERIVED_CHANGED_COUNT_NOT_REVALIDATED")
    # The executable Original306/313 contract consumes the ORIGINAL plain
    # Core CAS Plan. A publisher-only envelope must be rejected, not demanded.
    if original_contract["original313_accepts_plain_core_plan"] != "ACCEPTED":
        blockers.append("ORIGINAL313_CORE_PLAN_NOT_COMPATIBLE")
    if not original_contract["operation_exclusive"]:
        blockers.append("ORIGINAL306_PLAN_OPERATION_NOT_UNIQUELY_BOUND")
    if (not historical_finishes["original305_current_state_verified"] or
        not historical_finishes["original312_current_eligibility_verified"]):
        blockers.append("ORIGINAL305312_CURRENT_JOB_CLAIM_NOT_VERIFIED")
    if (historical_grants["state"]=="HOLD__GRANT_HISTORY_UNVERIFIED" or
        historical_grants["distinct_plans_without_matching_grant_evidence"]):
        blockers.append("LEGACY_HISTORICAL_SIGNED_GRANT_COVERAGE_INCOMPLETE")
    if legacy_plan_impact["state"] == "HOLD__LEGACY_PLAN_AUDIT_UNVERIFIED":
        blockers.append("LEGACY_PLAN_STORE_IMPACT_UNVERIFIED")
    elif legacy_plan_impact["ambiguous_existing_plan_count"]:
        blockers.append("LEGACY_EXISTING_PLANS_SHARE_NEW_SOURCE_CONTRACT")
    if not association["installed_original306_enforces_exclusive_association"]:
        blockers.append("ORIGINAL306_SIGNED_ASSOCIATION_HOOK_NOT_INSTALLED")
    if not association["tracked_and_installed_original306_worker_match"]:
        blockers.append("ORIGINAL306_SOURCE_INSTALLED_WORKER_DRIFT")
    if not verifier_environment["production_proof_compatible"]:
        blockers.append("ORIGINAL313_PRODUCTION_EVIDENCE_ENVIRONMENT_MISMATCH")
    if not installed["all_candidate_operations_present_in_index"]:
        blockers.append("FIRST_PARTY_SOURCE_PROVIDER_NOT_ENROLLED")
    if (installed["all_candidate_operations_present_in_index"]
        and not installed["all_candidate_manifests_sha_verified"]):
        blockers.append("FIRST_PARTY_PROVIDER_CONTENT_NOT_VERIFIED")
    if not installed["native_policy_file_present"]:
        blockers.append("ROOT_OWNED_NATIVE_POLICY_NOT_ENROLLED")
    if not installed["central_source_review_module_bootstrapped"]:
        blockers.append("CENTRAL_SOURCE_READ_BRIDGE_NOT_INSTALLED")
    return {
        "contract": "mad4b.native-source-dynamic-release-readonly-audit.v1",
        "observed_utc": datetime.now(timezone.utc).isoformat(),
        "state": "HOLD" if blockers else "SOURCE_AUDIT_PASS__SIGNED_AUTHORITY_STILL_REQUIRED",
        "blockers": blockers,
        "ephemeral_security_probes": probes,
        "original_worker_contract_probes": original_contract,
        "legacy_plan_compatibility_probe": legacy_plan_impact,
        "historical_finish_evidence_probe": historical_finishes,
        "historical_grants_evidence_probe": historical_grants,
        "original_job_lifecycle_contract_probe": lifecycle,
        "signed_association_admission_probe": association,
        "verifier_environment_contract_probe": verifier_environment,
        "installed_status": installed,
        "first_party_signed_original313_receipt_verified": False,
        "authorization_issued": False,
        "live_deployment_performed": False,
        "production_changed": False,
    }


def main() -> int:
    audit = evaluate()
    print(json.dumps(audit, indent=2, sort_keys=True))
    return 2 if audit["state"] == "HOLD" else 0


if __name__ == "__main__":
    raise SystemExit(main())
