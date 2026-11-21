"""Root-private, monotonic Desired registry for approved Live-First source.

This is NOT a Host Runner operation, WordPress endpoint or signing authority.
It admits externally approved/signed records with independent signed
high-water, and stores them in a private transactional SQLite ledger.

The caller must already hold authenticated site-specific first-party
approved-source and independent Root registry public keys and a fresh
verified signed live Current observation.
"""
from __future__ import annotations

import base64
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import stat
import time

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.exceptions import InvalidSignature

from fleet_policy import SiteBinding
from persistent_enrollment import ConnectionDenied, need, keys_independent
from live_current_source import Current, validate_files, read_current
from live_first_reconciliation import (
    Desired, DESIRED_FIELDS, canonical_desired, verify_desired,
)

DB_NAME = "mad4b-desired-registry-v129.sqlite3"
GENESIS_SHA256 = "0" * 64
CONTRACT = "mad4b.host-v129.desired-registry-admission.v1"


def _checked_private_root(root: Path, wordpress_root: Path) -> Path:
    """Both paths are selected by a trusted Root caller, never an input job."""
    need(root.is_absolute() and wordpress_root.is_absolute(),
         "DESIRED_LEDGER_ABSOLUTE_PATHS_REQUIRED")
    root = root.absolute()
    current = root
    while True:
        need(not current.is_symlink(), "DESIRED_LEDGER_LINK_FORBIDDEN")
        if current.parent == current:
            break
        current = current.parent
    need(root.is_dir(), "DESIRED_LEDGER_PRIVATE_ROOT_REQUIRED")
    wp = wordpress_root.resolve(strict=True)
    resolved = root.resolve(strict=True)
    need(root == resolved and resolved != wp
         and not resolved.is_relative_to(wp),
         "DESIRED_LEDGER_CANNOT_LIVE_INSIDE_WORDPRESS")
    info = root.stat()
    need(stat.S_ISDIR(info.st_mode)
         and info.st_uid == os.geteuid()
         and not (stat.S_IMODE(info.st_mode) & 0o077),
         "DESIRED_LEDGER_ROOT_OWNER_OR_MODE_INVALID")
    return root


def _validate_database_file(path: Path) -> None:
    need(not path.is_symlink(), "DESIRED_LEDGER_DATABASE_SYMLINK_DENIED")
    if not path.exists():
        return
    info = path.stat()
    need(stat.S_ISREG(info.st_mode)
         and info.st_uid == os.geteuid()
         and not (stat.S_IMODE(info.st_mode) & 0o077),
         "DESIRED_LEDGER_DATABASE_OWNER_OR_MODE_INVALID")


def _record_sha(row: dict) -> str:
    return hashlib.sha256(canonical_desired(row)).hexdigest()


def _verify_persisted_row(row: dict, public: bytes,
                          binding: SiteBinding, expected_sha: str) -> None:
    """Stored historic revisions need signature checks but no stale high-water
    assertion: only the proposed NEW revision must have fresh independent
    high-water from the current authority.
    """
    need(type(row) is dict and set(row) == set(DESIRED_FIELDS)|{"signature"},
         "DESIRED_LEDGER_HISTORICAL_ROW_INVALID")
    need(type(public) is bytes and len(public) == 32
         and _record_sha(row) == expected_sha
         and row["company"] == binding.company
         and row["environment"] == binding.environment
         and row["site_uuid"] == binding.site_uuid
         and row["target_host_id"] == binding.host_id
         and row["host_key_sha256"] == binding.host_key_sha256
         and row["policy_sha256"] == binding.policy_sha256,
         "DESIRED_LEDGER_HISTORICAL_IDENTITY_DRIFT")
    try:
        signature = base64.b64decode(row["signature"], validate=True)
        need(len(signature) == 64, "DESIRED_LEDGER_HISTORICAL_SIGNATURE_INVALID")
        Ed25519PublicKey.from_public_bytes(public).verify(
            signature, canonical_desired(row))
    except (InvalidSignature, KeyError, TypeError, ValueError) as exc:
        raise ConnectionDenied("DESIRED_LEDGER_HISTORICAL_SIGNATURE_INVALID") from exc


class DesiredRegistry:
    def __init__(self, private_root: Path, wordpress_root: Path):
        self.root = _checked_private_root(private_root, wordpress_root)
        self.db = self.root / DB_NAME
        _validate_database_file(self.db)

    def _connect(self) -> sqlite3.Connection:
        _validate_database_file(self.db)
        if not self.db.exists():
            try:
                fd = os.open(str(self.db), os.O_CREAT | os.O_EXCL | os.O_WRONLY |
                             getattr(os, "O_NOFOLLOW", 0), 0o600)
                os.close(fd)
            except FileExistsError:
                pass
        _validate_database_file(self.db)
        conn = sqlite3.connect(str(self.db), timeout=3.0, isolation_level=None)
        conn.execute("PRAGMA journal_mode=DELETE")
        conn.execute("PRAGMA synchronous=FULL")
        conn.execute("PRAGMA busy_timeout=3000")
        conn.execute(
            "CREATE TABLE IF NOT EXISTS records ("
            "site_uuid TEXT NOT NULL, revision INTEGER NOT NULL, "
            "row_sha256 TEXT NOT NULL, signed_record_json TEXT NOT NULL, "
            "signed_checkpoint_json TEXT NOT NULL, "
            "committed_epoch INTEGER NOT NULL, "
            "PRIMARY KEY (site_uuid, revision))"
        )
        conn.execute(
            "CREATE TABLE IF NOT EXISTS heads ("
            "site_uuid TEXT PRIMARY KEY, revision INTEGER NOT NULL, "
            "row_sha256 TEXT NOT NULL)"
        )
        return conn

    @staticmethod
    def _head(conn: sqlite3.Connection, site_uuid: str) -> tuple[int, str]:
        row = conn.execute(
            "SELECT revision, row_sha256 FROM heads WHERE site_uuid = ?",
            (site_uuid,),
        ).fetchone()
        return (int(row[0]), str(row[1])) if row else (0, GENESIS_SHA256)

    def read_historical_head(self, binding: SiteBinding) -> dict | None:
        """A stored historical record is NOT fresh Desired authority.

        To obtain active Desired, caller must separately fetch and verify a
        fresh independent signed high-water checkpoint.
        """
        if not self.db.exists():
            return None
        with self._connect() as conn:
            revision, digest = self._head(conn, binding.site_uuid)
            if revision == 0:
                return None
            found = conn.execute(
                "SELECT signed_record_json FROM records "
                "WHERE site_uuid=? AND revision=? AND row_sha256=?",
                (binding.site_uuid, revision, digest),
            ).fetchone()
            need(found is not None, "DESIRED_LEDGER_HEAD_RECORD_MISSING")
            return {
                "revision":revision, "approved_record_sha256":digest,
                "historic_signed_record":json.loads(found[0]),
                "current_authority_verified":False,
            }

    def read_current_approved(self, binding: SiteBinding,
                              independent_highwater_reader,
                              approved_source_public: bytes,
                              independent_registry_public: bytes,
                              *, now: int | None = None) -> dict:
        """Read the CURRENT approved Desired from the Root-private ledger.

        Historical storage is insufficient. Query a fresh first-party
        independent checkpoint callback for the exact site and confirm its
        current signed revision/digest matches the transactionally stored
        head. No data is written or automatically deployed.
        """
        observed = int(time.time()) if now is None else now
        need(type(observed) is int and observed > 0,
             "DESIRED_REGISTRY_CLOCK_INVALID")
        need(callable(independent_highwater_reader),
             "DESIRED_REGISTRY_INDEPENDENT_READER_REQUIRED")
        need(self.db.exists(), "DESIRED_REGISTRY_CURRENT_MISSING")
        conn=self._connect()
        try:
            conn.execute("BEGIN")
            revision, digest = self._head(conn,binding.site_uuid)
            need(revision>0,"DESIRED_REGISTRY_CURRENT_MISSING")
            record=conn.execute(
                "SELECT signed_record_json FROM records "
                "WHERE site_uuid=? AND revision=? AND row_sha256=?",
                (binding.site_uuid,revision,digest),
            ).fetchone()
            need(record is not None,"DESIRED_REGISTRY_HEAD_RECORD_MISSING")
            signed_row=json.loads(record[0])
            _verify_persisted_row(signed_row,approved_source_public,
                                  binding,digest)
            signed_checkpoint=independent_highwater_reader(binding)
            desired=verify_desired(
                signed_row,binding,approved_source_public,
                signed_checkpoint,independent_registry_public,
                now=observed,
            )
            need(desired.approved_revision==revision
                 and desired.signed_record_sha256==digest,
                 "DESIRED_REGISTRY_HEAD_HIGHWATER_DRIFT")
            conn.execute("COMMIT")
            return {
                "contract":"mad4b.host-v129.current-approved-desired-read.v1",
                "site_uuid":binding.site_uuid,
                "approved_revision":revision,
                "approved_source_head_sha":desired.source_head_sha,
                "approved_record_sha256":digest,
                "current_authority_verified":True,
                "independent_current_highwater_verified":True,
                "desired":desired,
                "mutations_performed":False,
                "host_write_authorized":False,
            }
        except Exception:
            if conn.in_transaction:
                conn.execute("ROLLBACK")
            raise
        finally:
            conn.close()

    def admit(self, binding: SiteBinding, signed_row: dict,
              signed_highwater: dict, site_reader, pinned_host_runner_public: bytes,
              expected_origin: str,
              approved_source_public: bytes, independent_registry_public: bytes,
              *, expected_previous_sha256: str,
              now: int | None = None) -> dict:
        """Transactional optimistic-CAS admission of one signed revision.

        Requires a fresh actual-site Current (already verified by its
        independently pinned Host Runner signer). The caller cannot
        fabricate an approval by merely supplying a Git commit.
        """
        need(binding.environment == "staging",
             "DESIRED_LEDGER_STAGING_ONLY")
        keys_independent(pinned_host_runner_public,approved_source_public,
                         independent_registry_public)
        observed = int(time.time()) if now is None else now
        need(type(observed) is int and observed > 0,
             "DESIRED_LEDGER_CLOCK_INVALID")
        # Never admit based on a manually constructed Current dataclass.
        # Read and independently verify the actual site's enrolled signer.
        current = read_current(
            binding,site_reader,pinned_host_runner_public,expected_origin,
            now=observed)
        need(type(expected_previous_sha256) is str
             and len(expected_previous_sha256) == 64
             and all(c in "0123456789abcdef" for c in expected_previous_sha256),
             "DESIRED_LEDGER_PREVIOUS_DIGEST_INVALID")
        desired: Desired = verify_desired(
            signed_row, binding, approved_source_public,
            signed_highwater, independent_registry_public, now=observed,
        )
        need({p for p,*_ in current.files}
             == {p for p,*_ in desired.files},
             "DESIRED_LEDGER_CURRENT_FILE_SET_DRIFT")
        conn = self._connect()
        try:
            conn.execute("BEGIN IMMEDIATE")
            revision, prior_sha = self._head(conn, binding.site_uuid)
            need(prior_sha == expected_previous_sha256,
                 "DESIRED_LEDGER_CAS_PREIMAGE_MISMATCH")
            need(desired.approved_revision == revision + 1,
                 "DESIRED_LEDGER_NON_MONOTONIC_REVISION")
            if revision:
                previous = conn.execute(
                    "SELECT signed_record_json FROM records "
                    "WHERE site_uuid=? AND revision=? AND row_sha256=?",
                    (binding.site_uuid, revision, prior_sha),
                ).fetchone()
                need(previous is not None,"DESIRED_LEDGER_PRIOR_RECORD_MISSING")
                prior_row = json.loads(previous[0])
                _verify_persisted_row(
                    prior_row, approved_source_public,binding,prior_sha)
                need(validate_files(signed_row["base_files"])
                     == validate_files(prior_row["files"]),
                     "DESIRED_LEDGER_PREDECESSOR_BASE_DRIFT")
            else:
                need(validate_files(signed_row["base_files"])
                     == current.files,
                     "DESIRED_LEDGER_GENESIS_NOT_LIVE_BASED")
            raw = json.dumps(
                signed_row, sort_keys=True, separators=(",",":"), ensure_ascii=False)
            checkpoint = json.dumps(
                signed_highwater, sort_keys=True,
                separators=(",",":"), ensure_ascii=False)
            conn.execute(
                "INSERT INTO records "
                "(site_uuid, revision, row_sha256, signed_record_json, "
                "signed_checkpoint_json, committed_epoch) VALUES (?,?,?,?,?,?)",
                (binding.site_uuid,desired.approved_revision,
                 desired.signed_record_sha256,raw,checkpoint,observed),
            )
            if revision == 0:
                conn.execute(
                    "INSERT INTO heads (site_uuid,revision,row_sha256)"
                    " VALUES (?,?,?)",
                    (binding.site_uuid,desired.approved_revision,
                     desired.signed_record_sha256),
                )
            else:
                c = conn.execute(
                    "UPDATE heads SET revision=?,row_sha256=? "
                    "WHERE site_uuid=? AND revision=? AND row_sha256=?",
                    (desired.approved_revision,desired.signed_record_sha256,
                     binding.site_uuid,revision,prior_sha),
                )
                need(c.rowcount==1,"DESIRED_LEDGER_ATOMIC_CAS_FAILED")
            conn.execute("COMMIT")
            return {
                "contract":CONTRACT,
                "site_uuid":binding.site_uuid,
                "previous_revision":revision,
                "previous_sha256":prior_sha,
                "new_revision":desired.approved_revision,
                "approved_record_sha256":desired.signed_record_sha256,
                "live_current_sha256":current.snapshot_sha256,
                "independent_highwater_checked":True,
                "registry_record_committed":True,
                "wordpress_mutation_performed":False,
                "host_write_authorized":False,
                "candidate_deployed":False,
            }
        except Exception:
            if conn.in_transaction:
                conn.execute("ROLLBACK")
            raise
        finally:
            conn.close()
