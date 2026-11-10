"""FAORE federated operation kernel for the existing MAD4B Broker.

The library never authenticates, grants privileges, invokes WP-CLI or deploys.
Adapters must provide verified company registry, transport authentication,
local admission and native executor/readback. No background worker starts here.
"""
from __future__ import annotations
import hashlib
import json
import os
from urllib.parse import urlsplit
from pathlib import Path
import re
import sqlite3
import time
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Callable, Any
from uuid import UUID

CONTRACTS = frozenset((
    "company.capabilities.v1", "company.task.dispatch.v1",
    "company.task.admission.v1", "company.task.progress.v1",
    "company.task.receipt.v1", "company.runtime.snapshot.v1"))
EVIDENCE = ("Observed", "Verified", "Unverified", "Stale")
MODES = ("local", "central_managed", "hybrid")
ID_RE = re.compile(r"^[a-zA-Z0-9][a-zA-Z0-9._:-]{2,127}$")


class FCPError(Exception):
    def __init__(self, code: str):
        super().__init__(code)
        self.code = code


def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True,
                      separators=(",", ":"), allow_nan=False)


def sha(value):
    return hashlib.sha256(canonical(value).encode("utf-8")).hexdigest()


def check_id(value):
    if not isinstance(value, str) or ID_RE.fullmatch(value) is None:
        raise FCPError("FCP_ID_INVALID")
    return value


def check_uuid(value):
    try:
        if not isinstance(value, str) or str(UUID(value)) != value.lower():
            raise ValueError()
    except (ValueError, AttributeError, TypeError):
        raise FCPError("FCP_UUID_INVALID")
    return value


def check_message(message, expected="company.task.dispatch.v1"):
    if not isinstance(message, dict) or message.get("contract") not in CONTRACTS:
        raise FCPError("FCP_CONTRACT_UNSUPPORTED")
    if expected and message["contract"] != expected:
        raise FCPError("FCP_CONTRACT_MISMATCH")
    for key in ("company_id", "message_id", "delivery_id"):
        check_id(message.get(key))
    check_uuid(message.get("site_uuid"))
    if message.get("environment") not in ("staging", "production", "development"):
        raise FCPError("FCP_ENVIRONMENT_INVALID")
    if type(message.get("sequence_number")) is not int or message["sequence_number"] < 0:
        raise FCPError("FCP_SEQUENCE_INVALID")
    if not isinstance(message.get("payload"), dict):
        raise FCPError("FCP_PAYLOAD_INVALID")
    try:
        if len(canonical(message).encode("utf-8")) > 65536:
            raise FCPError("FCP_BODY_TOO_LARGE")
    except (TypeError, ValueError, OverflowError):
        raise FCPError("FCP_PAYLOAD_INVALID")
    try:
        stamp = datetime.fromisoformat(message["sent_at"].replace("Z", "+00:00"))
        if stamp.utcoffset() is None:
            raise ValueError()
    except (ValueError, TypeError, KeyError, AttributeError):
        raise FCPError("FCP_TIMESTAMP_INVALID")
    if message["contract"].startswith("company.task."):
        for key in ("parent_operation_id", "child_operation_id",
                    "requested_capability", "idempotency_key"):
            check_id(message.get(key))
    if expected == "company.task.dispatch.v1":
        p = message["payload"]
        if (p.get("local_admission_required") is not True
            or p.get("local_skill_resolution") is not True
            or p.get("result_delivery") != "durable_outbox"
            or p.get("execution_mode") not in MODES
            or p.get("status") not in ("PLANNED", "QUEUED")
            or not isinstance(p.get("requested_scope_digest"), str)
            or re.fullmatch(r"sha256:[0-9a-f]{64}",
                            p["requested_scope_digest"]) is None):
            raise FCPError("FCP_DISPATCH_SCOPE_INVALID")
    return True


@dataclass(frozen=True)
class Company:
    company_id: str
    environment: str
    site_uuid: str
    origin: str
    mode: str
    capabilities: tuple[str, ...]


class Kernel:
    """Durable operation envelope store. One SQLite DB per local Broker."""

    def __init__(self, db_path: str | Path):
        path = Path(db_path)
        if str(path) != ":memory:":
            if path.is_symlink():
                raise FCPError("STATE_PATH_SYMLINK_DENIED")
            if not path.exists():
                fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_EXCL, 0o600)
                os.close(fd)
            os.chmod(path, 0o600)
        self.db = sqlite3.connect(str(db_path), isolation_level=None, timeout=15)
        self.db.row_factory = sqlite3.Row
        self.db.execute("PRAGMA journal_mode=WAL")
        self.db.execute("PRAGMA busy_timeout=15000")
        self.db.executescript("""
        CREATE TABLE IF NOT EXISTS company(
          company_id TEXT,environment TEXT,site_uuid TEXT,origin TEXT,
          mode TEXT,capabilities TEXT,PRIMARY KEY(company_id,environment));
        CREATE TABLE IF NOT EXISTS job(
          company_id TEXT,environment TEXT,child_id TEXT,parent_id TEXT,
          site_uuid TEXT,capability TEXT,scope_digest TEXT,idem TEXT,
          state TEXT,reason TEXT,evidence TEXT,receipt_ref TEXT,
          result_sha TEXT,readback_sha TEXT,created INTEGER,updated INTEGER,
          PRIMARY KEY(company_id,environment,child_id),
          UNIQUE(company_id,environment,idem));
        CREATE TABLE IF NOT EXISTS inbox(
          delivery_id TEXT PRIMARY KEY,message_id TEXT UNIQUE,
          company_id TEXT,environment TEXT,child_id TEXT,body_sha TEXT,
          seq INTEGER,state TEXT,received INTEGER);
        CREATE TABLE IF NOT EXISTS outbox(
          event_id TEXT PRIMARY KEY,company_id TEXT,environment TEXT,
          child_id TEXT,kind TEXT,payload TEXT,attempts INTEGER DEFAULT 0,
          next_due INTEGER DEFAULT 0,state TEXT DEFAULT 'PENDING');
        CREATE TABLE IF NOT EXISTS journal(
          row_id INTEGER PRIMARY KEY AUTOINCREMENT,company_id TEXT,
          environment TEXT,child_id TEXT,transition TEXT,evidence_ref TEXT,
          created INTEGER);
        CREATE TABLE IF NOT EXISTS dependency(
          src TEXT,dst TEXT,impact TEXT,PRIMARY KEY(src,dst,impact));
        """)
    @contextmanager
    def transaction(self):
        self.db.execute("BEGIN IMMEDIATE")
        try:
            yield
        except BaseException:
            self.db.execute("ROLLBACK")
            raise
        else:
            self.db.execute("COMMIT")

    def close(self):
        self.db.close()

    def enroll(self, company: Company, registry_verify: Callable[[Company], bool]):
        """Exact pre-existing Company Registry confirmation is mandatory."""
        if not callable(registry_verify) or registry_verify(company) is not True:
            raise FCPError("REGISTRY_BINDING_UNVERIFIED")
        check_id(company.company_id)
        check_uuid(company.site_uuid)
        if company.environment not in ("staging", "production", "development"):
            raise FCPError("FCP_ENVIRONMENT_INVALID")
        origin=urlsplit(company.origin)
        if (company.mode not in MODES or origin.scheme != "https"
            or not origin.hostname or origin.username or origin.password
            or origin.query or origin.fragment):
            raise FCPError("REGISTRY_BINDING_INVALID")
        with self.transaction():
            row=self.db.execute(
                "SELECT site_uuid,origin FROM company WHERE company_id=? AND environment=?",
                (company.company_id,company.environment)).fetchone()
            if row and (row["site_uuid"]!=company.site_uuid or row["origin"]!=company.origin):
                raise FCPError("REGISTRY_BINDING_CONFLICT")
            self.db.execute("""
              INSERT INTO company VALUES(?,?,?,?,?,?)
              ON CONFLICT(company_id,environment) DO UPDATE SET
              mode=excluded.mode,capabilities=excluded.capabilities
            """,(company.company_id,company.environment,company.site_uuid,
                 company.origin,company.mode,canonical(sorted(set(company.capabilities)))))

    def company(self, company_id, environment):
        row=self.db.execute(
          "SELECT * FROM company WHERE company_id=? AND environment=?",
          (company_id,environment)).fetchone()
        if row is None:
            raise FCPError("COMPANY_NOT_ENROLLED")
        result=dict(row)
        result["capabilities"]=json.loads(result["capabilities"])
        return result

    def job(self, company_id, environment, child_id):
        row=self.db.execute("""
          SELECT * FROM job WHERE company_id=? AND environment=? AND child_id=?
        """,(company_id,environment,child_id)).fetchone()
        if row is None:
            raise FCPError("JOB_NOT_FOUND")
        return dict(row)

    def _event(self, c,e,j,kind,payload):
        eid=sha([c,e,j,kind,payload])
        self.db.execute("""
          INSERT OR IGNORE INTO outbox(event_id,company_id,environment,child_id,kind,payload)
          VALUES(?,?,?,?,?,?)
        """,(eid,c,e,j,kind,canonical(payload)))
        return eid

    def _journal(self,c,e,j,state,ref=""):
        self.db.execute("""
          INSERT INTO journal(company_id,environment,child_id,transition,
                              evidence_ref,created) VALUES(?,?,?,?,?,?)
        """,(c,e,j,state,ref,int(time.time())))

    def deliver(self, msg: dict, authenticate: Callable[[dict], dict],
                admit: Callable[[dict, dict], dict]) -> dict:
        """Record envelope durably before asking local policy; no implicit apply."""
        check_message(msg)
        if not callable(authenticate) or not callable(admit):
            raise FCPError("ADAPTER_REQUIRED")
        principal=authenticate(msg)
        if not isinstance(principal, dict) or principal.get("verified") is not True:
            raise FCPError("TRANSPORT_UNAUTHENTICATED")
        for field in ("company_id","environment","site_uuid"):
            if principal.get(field)!=msg[field]:
                raise FCPError("TRANSPORT_SCOPE_MISMATCH")
        c,e,j=msg["company_id"],msg["environment"],msg["child_operation_id"]
        binding=self.company(c,e)
        if binding["site_uuid"]!=msg["site_uuid"]:
            raise FCPError("LOCAL_SITE_MISMATCH")
        if binding["mode"]=="local":
            raise FCPError("CENTRAL_DISPATCH_DISABLED")
        if msg["requested_capability"] not in binding["capabilities"]:
            raise FCPError("CAPABILITY_UNAVAILABLE")
        scope=msg["payload"]["requested_scope_digest"]
        fingerprint=sha(msg)
        with self.transaction():
            delivery=self.db.execute(
                "SELECT body_sha,state FROM inbox WHERE delivery_id=?",
                (msg["delivery_id"],)).fetchone()
            if delivery:
                if delivery["body_sha"]!=fingerprint:
                    raise FCPError("DELIVERY_REPLAY_CONFLICT")
                return {"state":"DUPLICATE_DELIVERY","original_state":delivery["state"]}
            existing=self.db.execute("""
              SELECT * FROM job WHERE company_id=? AND environment=?
              AND (child_id=? OR idem=?)
            """,(c,e,j,msg["idempotency_key"])).fetchone()
            if existing:
                if (existing["child_id"]!=j or existing["idem"]!=msg["idempotency_key"]
                    or existing["parent_id"]!=msg["parent_operation_id"]
                    or existing["site_uuid"]!=msg["site_uuid"]
                    or existing["scope_digest"]!=scope
                    or existing["capability"]!=msg["requested_capability"]):
                    raise FCPError("LOGICAL_OPERATION_CONFLICT")
            if self.db.execute("SELECT 1 FROM inbox WHERE message_id=?",
                               (msg["message_id"],)).fetchone():
                raise FCPError("MESSAGE_REPLAY_CONFLICT")
            latest=self.db.execute(
                "SELECT MAX(seq) FROM inbox WHERE company_id=? AND environment=?",
                (c,e)).fetchone()[0]
            if not existing and latest is not None and msg["sequence_number"]<=latest:
                raise FCPError("MESSAGE_SEQUENCE_REPLAY")
            self.db.execute("""
              INSERT INTO inbox(delivery_id,message_id,company_id,environment,child_id,
                                body_sha,seq,state,received) VALUES(?,?,?,?,?,?,?,?,?)
            """,(msg["delivery_id"],msg["message_id"],c,e,j,fingerprint,
                 msg["sequence_number"],"REDELIVERED" if existing else "RECEIVED",
                 int(time.time())))
            if existing:
                return {"state":"REDELIVERED","original_state":existing["state"]}
            self.db.execute("""
              INSERT INTO job(company_id,environment,child_id,parent_id,site_uuid,
                              capability,scope_digest,idem,state,reason,evidence,
                              receipt_ref,result_sha,readback_sha,created,updated)
              VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            """,(c,e,j,msg["parent_operation_id"],msg["site_uuid"],
                 msg["requested_capability"],scope,msg["idempotency_key"],
                 "QUEUED","","Unverified","","","",int(time.time()),int(time.time())))
            self._journal(c,e,j,"RECEIVED")
        # Policy callback may query WordPress local policy. Fail closed on error.
        # A process crash during callback leaves a recoverable, non-executable QUEUED job.
        try:
            decision=admit(msg,binding)
        except Exception:
            decision={"decision":"REJECTED","reason_code":"LOCAL_ADMISSION_UNAVAILABLE"}
        accepted=(isinstance(decision,dict)
                  and decision.get("decision")=="ACCEPTED"
                  and decision.get("scope_digest")==scope)
        reason="LOCAL_SCOPE_ALLOWED" if accepted else (
            decision.get("reason_code","LOCAL_ADMISSION_DENIED")
            if isinstance(decision,dict) else "LOCAL_ADMISSION_DENIED")
        if (not isinstance(reason,str)
            or re.fullmatch(r"[A-Z][A-Z0-9_]{2,63}",reason) is None):
            reason="LOCAL_ADMISSION_DENIED"
        state="ADMITTED" if accepted else "REJECTED"
        with self.transaction():
            cursor=self.db.execute("""
              UPDATE job SET state=?,reason=?,updated=?
              WHERE company_id=? AND environment=? AND child_id=? AND state='QUEUED'
            """,(state,reason,int(time.time()),c,e,j))
            if cursor.rowcount!=1:
                raise FCPError("ADMISSION_TRANSITION_CONFLICT")
            self.db.execute("UPDATE inbox SET state=? WHERE delivery_id=?",
                            (state,msg["delivery_id"]))
            self._journal(c,e,j,state)
            self._event(c,e,j,"company.task.admission.v1",{
                "decision":"ACCEPTED" if accepted else "REJECTED",
                "reason_code":reason,"child_operation_id":j,
                "parent_operation_id":msg["parent_operation_id"]})
        return {"state":state,"reason_code":reason,"child_operation_id":j}

    def execute(self, c: str, e: str, j: str, *,
                executor: Callable[[dict], Any],
                readback: Callable[[dict], Any],
                receipt_verify: Callable[[dict, Any, Any], str]) -> dict:
        """Execute once: uncertain effects are never blindly retried."""
        if not all(callable(fn) for fn in (executor,readback,receipt_verify)):
            raise FCPError("EXECUTOR_AND_READBACK_REQUIRED")
        with self.transaction():
            item=self.job(c,e,j)
            if item["state"]!="ADMITTED":
                raise FCPError("OPERATION_NOT_ADMITTED_OR_ALREADY_CLAIMED")
            self.db.execute("""
              UPDATE job SET state='RUNNING',updated=?
              WHERE company_id=? AND environment=? AND child_id=? AND state='ADMITTED'
            """,(int(time.time()),c,e,j))
            self._journal(c,e,j,"RUNNING")
        error="EXECUTION_UNCERTAIN"
        state="RECOVERY_REQUIRED"
        evidence="Unverified"
        receipt=""
        result_sha=""
        readback_sha=""
        try:
            result=executor(item)
            result_sha=sha(result)
            observed=readback(item)
            readback_sha=sha(observed)
            proof=receipt_verify(item,result,observed)
            # The trusted adapter must independently verify native stage 313,
            # operation binding and readback. A string or HTTP success is not proof.
            valid=(isinstance(proof,dict) and proof.get("verified") is True
                   and type(proof.get("broker_stage")) is int
                   and proof.get("broker_stage")==313
                   and proof.get("company_id")==c and proof.get("environment")==e
                   and proof.get("site_uuid")==item["site_uuid"]
                   and proof.get("child_operation_id")==j
                   and proof.get("result_digest")==result_sha
                   and proof.get("readback_digest")==readback_sha
                   and isinstance(proof.get("receipt_reference"),str)
                   and re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9._:-]{3,127}",
                                    proof["receipt_reference"]) is not None)
            if valid and result_sha==readback_sha:
                receipt=proof["receipt_reference"]
                state,evidence,error="COMPLETED","Verified",""
            else:
                error="READBACK_OR_RECEIPT_UNVERIFIED"
        except Exception:
            error="EXECUTION_OR_READBACK_UNCERTAIN"
        with self.transaction():
            self.db.execute("""
              UPDATE job SET state=?,reason=?,evidence=?,receipt_ref=?,
              result_sha=?,readback_sha=?,updated=?
              WHERE company_id=? AND environment=? AND child_id=? AND state='RUNNING'
            """,(state,error,evidence,receipt,result_sha,readback_sha,
                 int(time.time()),c,e,j))
            self._journal(c,e,j,state,receipt)
            self._event(c,e,j,"company.task.receipt.v1",{
                "status":"COMPLETED" if state=="COMPLETED" else "FAILED",
                "evidence_state":evidence,"receipt_reference":receipt,
                "result_digest":result_sha,"readback_digest":readback_sha,
                "error_code":error,"child_operation_id":j})
        return {"state":state,"evidence":evidence,
                "receipt_reference":receipt,"error_code":error}

    def due(self, now: int | None = None, limit: int = 50) -> list[dict]:
        now=int(time.time()) if now is None else int(now)
        return [dict(row) for row in self.db.execute("""
          SELECT * FROM outbox WHERE state IN ('PENDING','RETRY_WAIT')
          AND next_due<=? ORDER BY rowid LIMIT ?
        """,(now,max(1,min(500,int(limit))))).fetchall()]

    def acknowledge(self, event_id: str, ack_verify: Callable[[str], bool]) -> None:
        """Only a transport adapter that verifies the receiver ACK may confirm."""
        check_id(event_id)
        if not callable(ack_verify) or ack_verify(event_id) is not True:
            raise FCPError("ACK_NOT_VERIFIED")
        with self.transaction():
            changed=self.db.execute("""
              UPDATE outbox SET state='ACKED' WHERE event_id=?
              AND state IN ('PENDING','RETRY_WAIT')
            """,(event_id,)).rowcount
            if changed!=1:
                raise FCPError("ACK_STATE_CONFLICT")

    def retry(self, event_id: str, now: int | None = None,
              max_attempts: int = 6) -> str:
        check_id(event_id)
        now=int(time.time()) if now is None else int(now)
        with self.transaction():
            row=self.db.execute("SELECT * FROM outbox WHERE event_id=?",
                                (event_id,)).fetchone()
            if row is None or row["state"] not in ("PENDING","RETRY_WAIT"):
                raise FCPError("DELIVERY_RETRY_CONFLICT")
            attempts=row["attempts"]+1
            status="DEAD_LETTER" if attempts>=max_attempts else "RETRY_WAIT"
            delay=min(3600,5*(2**min(10,attempts-1)))
            self.db.execute("""
              UPDATE outbox SET state=?,attempts=?,next_due=?
              WHERE event_id=?
            """,(status,attempts,now+delay,event_id))
            return status

    def dead_letters(self) -> list[dict]:
        return [dict(row) for row in self.db.execute(
          "SELECT event_id,company_id,environment,child_id,kind,attempts"
          " FROM outbox WHERE state='DEAD_LETTER' ORDER BY rowid").fetchall()]

    def recover_uncertain(self, c: str, e: str, j: str,
                          readback_check: Callable[[dict], dict]) -> dict:
        """Read-only recovery; never re-runs executor and never upgrades evidence."""
        if not callable(readback_check):
            raise FCPError("READBACK_ADAPTER_REQUIRED")
        row=self.job(c,e,j)
        if row["state"] not in ("RUNNING","RECOVERY_REQUIRED","QUEUED"):
            return {"state":row["state"],"action":"NO_REPLAY"}
        inspection=readback_check(row)
        if not isinstance(inspection,dict) or not inspection.get("inspected"):
            raise FCPError("RECOVERY_READBACK_UNAVAILABLE")
        # Terminal success requires a separate signed 313 receipt verifier
        # and is intentionally not inferred by this inspection-only API.
        with self.transaction():
            self.db.execute("""
              UPDATE job SET state='RECOVERY_REQUIRED',reason=?,updated=?
              WHERE company_id=? AND environment=? AND child_id=?
              AND state IN ('RUNNING','RECOVERY_REQUIRED','QUEUED')
            """,("MANUAL_RECONCILIATION_REQUIRED",int(time.time()),c,e,j))
            self._journal(c,e,j,"RECOVERY_INSPECTED")
        return {"state":"RECOVERY_REQUIRED","action":"MANUAL_RECONCILIATION_REQUIRED"}

    def add_dependency(self, source: str, target: str, impact: str) -> None:
        check_id(source); check_id(target)
        if impact not in ("Runtime","Schema","Data","Presentation","Security"):
            raise FCPError("DEPENDENCY_IMPACT_INVALID")
        with self.transaction():
            self.db.execute("""
              INSERT OR IGNORE INTO dependency(src,dst,impact) VALUES(?,?,?)
            """,(source,target,impact))

    def impacted_companies(self, source: str) -> list[str]:
        check_id(source)
        seen={source}
        queue=[source]
        result=set()
        while queue:
            current=queue.pop(0)
            for row in self.db.execute(
                "SELECT dst FROM dependency WHERE src=?",(current,)).fetchall():
                dst=row["dst"]
                if dst.startswith("company:"):
                    result.add(dst[len("company:"):])
                if dst not in seen:
                    seen.add(dst)
                    queue.append(dst)
        return sorted(result)

    def fleet_report(self, parent_id: str, *, stale_after: int = 3600,
                     now: int | None = None) -> dict:
        check_id(parent_id)
        now=int(time.time()) if now is None else int(now)
        rows=self.db.execute("""
          SELECT company_id,environment,child_id,state,reason,evidence,
                 receipt_ref,updated
          FROM job WHERE parent_id=? ORDER BY company_id,environment,child_id
        """,(parent_id,)).fetchall()
        children=[]
        for row in rows:
            item=dict(row)
            if now-int(item["updated"])>stale_after:
                item["evidence"]="Stale"
            children.append(item)
        states={status:sum(1 for i in children if i["state"]==status)
                for status in sorted({i["state"] for i in children})}
        grade="Verified" if children and all(
            i["state"]=="COMPLETED" and i["evidence"]=="Verified"
            for i in children) else "Unverified"
        return {"contract":"mad4b.faore.fleet-report.v1",
                "parent_operation_id":parent_id,
                "children":children,"summary":states,"evidence":grade,
                "customer_data_included":False}
