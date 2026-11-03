"""Site-to-host binding evidence. Never an execution or authorization grant.

A host can serve many sites/environments. One site has one active host binding
at a time; a relocation is reconciled through independently verified
readbacks, with review of changed host/root, not an implicit grant transfer.
"""
import hashlib
import json
import re
from urllib.parse import urlsplit

CONTRACT = "mad4b.dynamic-site-binding.v1"
HEX_UUID = re.compile(r"^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$")
NAME = re.compile(r"^[a-z0-9][a-z0-9_.:-]{1,95}$")
ROOT_PREFIX = ("/home/", "/var/www/", "/srv/www/", "/opt/")

def _valid_origin(value):
    if not isinstance(value,str) or len(value)>255:
        return False
    p=urlsplit(value)
    return (p.scheme=="https" and bool(p.hostname)
            and p.path in ("","/") and not p.username and not p.password
            and not p.query and not p.fragment and p.port in (None,443))

def _valid_root(value):
    return (isinstance(value,str) and len(value)<400
            and value.startswith(ROOT_PREFIX)
            and all(part not in ("", ".", "..") for part in value.split("/")[1:])
            and not any(c in value for c in ("\x00","\n","\r","\\","$",";")))

def _record_state(state,reason,*,host=None,provider=None,details=None):
    return {"contract":CONTRACT,"state":state,"reason":reason,"host_id":host,
            "provider":provider,"evidence":details or {},"operation_authorized":False,
            "write_performed":False,"production_mutation":False}

def validate_registry(registry):
    """Configuration is not a source of authority. Invalid input fails closed."""
    if not isinstance(registry,dict) or registry.get("contract")!=CONTRACT:
        raise ValueError("site_binding_registry_contract_invalid")
    hosts,sites=registry.get("hosts"),registry.get("sites")
    if not isinstance(hosts,dict) or not isinstance(sites,dict):
        raise ValueError("site_binding_registry_shape_invalid")
    if len(hosts)>1000 or len(sites)>10000:
        raise ValueError("site_binding_registry_limits_exceeded")
    for host_id,h in hosts.items():
        if not isinstance(host_id,str) or not NAME.fullmatch(host_id) or not isinstance(h,dict):
            raise ValueError("invalid_host_entry")
        if h.get("provider") not in ("registered_ssh","local_wpcli","mcp_api"):
            raise ValueError("host_provider_not_supported")
        if not isinstance(h.get("enrollment_ref"),str) or not NAME.fullmatch(h["enrollment_ref"]):
            raise ValueError("host_enrollment_reference_invalid")
        if h.get("write_allowed") is True:
            raise ValueError("site_binding_registry_must_be_read_only")
    uuids=set()
    for target,site in sites.items():
        if not isinstance(target,str) or not NAME.fullmatch(target) or not isinstance(site,dict):
            raise ValueError("invalid_site_entry")
        if site.get("host_id") not in hosts:
            raise ValueError("site_host_not_registered")
        if site.get("environment") not in ("staging","production","development"):
            raise ValueError("site_environment_invalid")
        company=site.get("company_id")
        if not isinstance(company,str) or company+":"+site["environment"]!=target:
            raise ValueError("site_target_scope_conflict")
        if not isinstance(site.get("site_uuid"),str) or not HEX_UUID.fullmatch(site["site_uuid"]):
            raise ValueError("site_uuid_invalid")
        if site["site_uuid"] in uuids:
            raise ValueError("site_uuid_duplicate")
        uuids.add(site["site_uuid"])
        if not _valid_origin(site.get("canonical_origin")):
            raise ValueError("site_origin_invalid")
        if hosts[site["host_id"]]["provider"] in ("registered_ssh","local_wpcli"):
            if not _valid_root(site.get("wp_root")):
                raise ValueError("site_root_invalid")
        elif site.get("wp_root") not in (None,""):
            raise ValueError("api_provider_cannot_claim_filesystem_root")
        if site.get("write_allowed") is True:
            raise ValueError("site_binding_registry_must_be_read_only")
    return True

def project(target, registry, observed=None, *, original_scope=None, previous=None):
    """Bind only when enrolled host identity and exact site WP evidence agree.

    Existing original operator/WordPress policies stay authoritative. Site
    migrations return REVIEW_REQUIRED until new root/host are re-enrolled.
    """
    try:validate_registry(registry)
    except (TypeError,ValueError,KeyError,AttributeError):
        return _record_state("review_required","invalid_registry")
    site=registry["sites"].get(target)
    if site is None:
        return _record_state("awaiting_provider","site_not_enrolled")
    host_id=site["host_id"]
    host=registry["hosts"][host_id]
    provider=host["provider"]
    if not isinstance(original_scope,dict) or (original_scope.get("site_uuid")!=site["site_uuid"]
        or original_scope.get("environment")!=site["environment"]
        or original_scope.get("canonical_origin")!=site["canonical_origin"]):
        return _record_state("review_required","original_site_scope_mismatch",host=host_id,provider=provider)
    if original_scope.get("allow_production_mutation") is True and site["environment"]!="production":
        return _record_state("review_required","cross_environment_authority_conflict",host=host_id,provider=provider)
    if isinstance(previous,dict) and previous.get("state")=="verified":
        old_evidence=previous.get("evidence",{})
        if not isinstance(old_evidence,dict):old_evidence={}
        if (previous.get("host_id")!=host_id or
            old_evidence.get("wp_root")!=site.get("wp_root") or
            old_evidence.get("site_uuid")!=site["site_uuid"]):
            # Independent host trust + exact original operator re-enrollment
            # must both exist before a migration can be considered verified.
            if not (isinstance(observed,dict) and observed.get("reenrollment_verified") is True
                    and isinstance(site.get("binding_revision"),int)
                    and site["binding_revision"]>old_evidence.get("binding_revision",0)
                    and original_scope.get("binding_revision")==site["binding_revision"]):
                return _record_state("review_required","site_relocation_requires_independent_reenrollment",
                                     host=host_id,provider=provider,
                                     details={"previous_host_id":previous.get("host_id"),"previous_verified":True})
    if not isinstance(observed,dict):
        return _record_state("awaiting_provider","trusted_site_readback_missing",host=host_id,provider=provider)
    # The provider proof comes from original runtime adapters; never from the
    # incoming event or the desired-state registry.
    exact=(observed.get("trusted") is True
           and observed.get("host_enrollment_ref")==host["enrollment_ref"]
           and observed.get("site_uuid")==site["site_uuid"]
           and observed.get("canonical_origin")==site["canonical_origin"]
           and observed.get("environment")==site["environment"]
           and observed.get("host_id")==host_id)
    if provider in ("registered_ssh","local_wpcli"):
        exact=exact and observed.get("wp_root")==site.get("wp_root")
    if not exact:
        return _record_state("review_required","site_host_readback_mismatch",
                             host=host_id,provider=provider)
    return _record_state("verified","exact_site_host_binding_readback_verified",
        host=host_id,provider=provider,
        details={"site_uuid":site["site_uuid"],"canonical_origin":site["canonical_origin"],
                 "environment":site["environment"],"wp_root":site.get("wp_root"),
                 "host_enrollment_ref":host["enrollment_ref"],
                 "binding_revision":site.get("binding_revision",0),
                 "readback_source":observed.get("readback_source")})

def original_runtime_readback(target,registry,scope,domains):
    """Resolve proof from *existing* registered WordPress/SSH adapters only.

    Not a generic host discovery probe: newly entered hosts or arbitrary
    incoming event fields never assert trust.
    """
    if not isinstance(domains,dict) or not isinstance(scope,dict):
        return None
    site=registry.get("sites",{}).get(target,{})
    host=registry.get("hosts",{}).get(site.get("host_id"),{})
    transport=domains.get("transport",{})
    identity=domains.get("identity",{})
    if not isinstance(transport,dict) or not isinstance(identity,dict):
        return None
    if transport.get("state")!="verified" or identity.get("state")!="verified":
        return None
    if (target=="egypt-tour-gates:staging"
        and site.get("host_id")=="hosting-3"
        and transport.get("provider")=="etg_staging_release_observer"
        and transport.get("reason")=="registered_pinned_ssh_wpcli_read_only"
        and identity.get("provider")=="etg_staging_release_observer"
        and identity.get("reason")=="exact_etg_staging_profile_verified"
        and identity.get("evidence",{}).get("site_uuid")==site.get("site_uuid")):
        # This adapter's fixed pinned host/user/key/root must also match the
        # enrolled site binding. Both WordPress and the SSH readback ran.
        import etg_staging_release_observer as provider
        if (provider.TARGET_ROOT==site.get("wp_root")
            and provider.UUID==site.get("site_uuid")
            and provider.SITE==site.get("canonical_origin")
            and provider.CREDENTIAL_DIR.endswith("/"+host.get("enrollment_ref",""))
            and provider.HOST=="u783534439@82.198.227.227"):
            return {"trusted":True,"host_id":site["host_id"],
                "host_enrollment_ref":host["enrollment_ref"],
                "site_uuid":site["site_uuid"],
                "canonical_origin":site["canonical_origin"],
                "environment":site["environment"],"wp_root":site["wp_root"],
                "readback_source":"registered_pinned_ssh_etg_wpcli_site_profile"}
    if (transport.get("provider")=="registry_ssh"
        and transport.get("reason")=="verified_ssh_binding"
        and identity.get("provider")=="signed_profile_v4"
        and identity.get("reason")=="mutual_p256_signature_verified"
        and scope.get("transport_binding")==site.get("host_id")+":"+site.get("environment")
        and transport.get("evidence",{}).get("remote_root")==site.get("wp_root")
        and identity.get("evidence",{}).get("site_uuid")==site.get("site_uuid")
        and host.get("provider")=="registered_ssh"
        and host.get("enrollment_ref")==site.get("host_id")):
        return {"trusted":True,"host_id":site["host_id"],
                "host_enrollment_ref":host["enrollment_ref"],
                "site_uuid":site["site_uuid"],
                "canonical_origin":site["canonical_origin"],
                "environment":site["environment"],"wp_root":site["wp_root"],
                "readback_source":"registry_ssh_plus_signed_profile_v4"}
    return None

def status_digest(bindings):
    return hashlib.sha256(json.dumps(bindings,sort_keys=True,separators=(",",":")).encode()).hexdigest()
