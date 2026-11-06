"""Non-deploying, ownership-aware Source Release planner for FAORE."""
from dataclasses import dataclass
import re
from typing import Callable
from kernel import FCPError, Kernel

HASH = re.compile(r"^[0-9a-f]{64}$")


@dataclass(frozen=True)
class Generation:
    name: str
    manifest_digest: str
    source_commit: str
    compatible_min: int
    compatible_max: int
    owner: str = "shared"

    def __post_init__(self):
        if (not self.name or not HASH.fullmatch(self.manifest_digest)
                or not re.fullmatch(r"[0-9a-f]{40}",self.source_commit)
                or self.compatible_min<1 or self.compatible_max<self.compatible_min
                or self.owner!="shared"):
            raise FCPError("RELEASE_MANIFEST_INVALID")


def preview(kernel: Kernel, changed_component: str, generation: Generation,
            company_runtime: Callable[[str], dict]) -> dict:
    """Identify eligible companies; never enable a provider or apply a release.

    company_runtime supplies locally observed generation, protocol number,
    existing company-owned override digest, deployment provider and admission.
    """
    if not callable(company_runtime):
        raise FCPError("RUNTIME_OBSERVER_REQUIRED")
    targets=[]
    for company in kernel.impacted_companies(changed_component):
        observed=company_runtime(company)
        if not isinstance(observed,dict):
            observed={}
        protocol=observed.get("protocol_version")
        provider=observed.get("provider")
        ready=(isinstance(protocol,int) and not isinstance(protocol,bool)
               and generation.compatible_min<=protocol<=generation.compatible_max)
        local_ok=observed.get("local_release_admission") is True
        target={
            "company_id":company,
            "current_generation":str(observed.get("generation","")),
            "desired_generation":generation.name,
            "company_overrides_preserved":True,
            "overrides_digest":str(observed.get("overrides_digest","")),
            "provider_observed":str(provider or ""),
            "action":"PREVIEW_ONLY",
            "eligible":bool(ready and provider=="coolify" and local_ok),
            "reason":"ELIGIBLE" if ready and provider=="coolify" and local_ok else (
                "PROTOCOL_INCOMPATIBLE" if not ready else
                "COOLIFY_PROVIDER_UNAVAILABLE" if provider!="coolify" else
                "LOCAL_RELEASE_ADMISSION_REQUIRED")
        }
        targets.append(target)
    return {
        "contract":"mad4b.faore.release-plan.v1",
        "changed_component":changed_component,
        "source_commit":generation.source_commit,
        "manifest_digest":generation.manifest_digest,
        "desired_generation":generation.name,
        "targets":targets,
        "deployed":False,
        "company_data_copied":False,
    }


def verify_apply(plan: dict, company: str, observed: dict,
                 receipt_verify: Callable[[dict,dict],bool]) -> dict:
    """Post-apply evidence check only; never invokes Coolify or WP-CLI."""
    targets=plan.get("targets",[]) if isinstance(plan,dict) else []
    target=next((t for t in targets if t.get("company_id")==company),None)
    if target is None or target.get("eligible") is not True:
        raise FCPError("RELEASE_TARGET_NOT_ADMITTED")
    if (not isinstance(observed,dict) or
        observed.get("current_generation")!=plan.get("desired_generation") or
        observed.get("source_digest")!=plan.get("manifest_digest")):
        return {"company_id":company,"state":"UNVERIFIED","reason":"RUNTIME_GENERATION_MISMATCH"}
    if not callable(receipt_verify) or receipt_verify(plan,observed) is not True:
        return {"company_id":company,"state":"UNVERIFIED","reason":"RECEIPT_OR_READBACK_MISSING"}
    return {"company_id":company,"state":"VERIFIED","generation":plan["desired_generation"]}
