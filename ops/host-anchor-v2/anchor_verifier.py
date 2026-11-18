"""Purpose-specific Original312 Bootstrap / Exact Job signature verifier."""
from __future__ import annotations
import importlib.util
from pathlib import Path


class Original312Denied(ValueError):
    pass


def source(name):
    p = Path(__file__).with_name(name)
    if not p.is_file() or p.is_symlink() or p.stat().st_uid != 0:
        raise Original312Denied("HOST_ANCHOR_V2_PINNED_ORIGINAL312_REQUIRED")
    spec = importlib.util.spec_from_file_location(
        "_mad4b_anchor_original312_" + name.removesuffix(".py"), p)
    if spec is None or spec.loader is None:
        raise Original312Denied("HOST_ANCHOR_VERIFIER_UNAVAILABLE")
    m = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    return m


def verify_signed_original312(index, plan, signed, job, independent_authority):
    """Does not create signatures, Jobs or execution authorization."""
    if not all(type(v) is dict for v in (
            plan, signed, job, independent_authority)):
        raise Original312Denied("HOST_ANCHOR_SIGNED_SOURCE_EVIDENCE_REQUIRED")
    operation = plan.get("operation_id")
    manifest = index.resolve(operation)
    if manifest.get("execution_enabled") is not False:
        raise Original312Denied("HOST_ANCHOR_PROVIDER_NOT_COMMISSIONED")
    public = index.host_anchor_312_public_key
    if not isinstance(public, bytes) or len(public) != 32:
        raise Original312Denied("HOST_ANCHOR_DEDICATED_SIGNER_NOT_ENROLLED")
    if plan.get("authority_mode") == "bootstrap":
        if operation != "company.host-authority.anchor.issue":
            raise Original312Denied("BOOTSTRAP_CANNOT_REVOKE")
        return source("bootstrap_signed_job.py").verify_bootstrap_job(
            signed, public, job, independent_authority)
    return source("original312_host_v2_exact_job_contract.py").verify_exact_job(
        signed, public, job, independent_authority)
