"""Prepare a root-private OCI Docker build context without deploying it.

Source inputs are immutable package artifacts from the existing first-party
Publisher toolchain. A separate Original-signed Native operation must perform
any traffic migration, schema migration or production container creation.
"""
from __future__ import annotations
import importlib.util
import io
import json
import os
from pathlib import Path, PurePosixPath
import stat
import tarfile

RUNTIME = Path(__file__).with_name("immutable_runtime.py")
spec = importlib.util.spec_from_file_location("_mad4b_runtime_spec", RUNTIME)
model = importlib.util.module_from_spec(spec)
spec.loader.exec_module(model)

CONTRACT = "mad4b.immutable-runtime.oci-build-context.v1"


def local_base_alias(image_id: str) -> str:
    model.require(isinstance(image_id, str) and
                  len(image_id) == 71 and image_id.startswith("sha256:"),
                  "OCI_LOCAL_BASE_IMAGE_ID_INVALID")
    return "mad4b-immutable-base:sha-" + image_id[7:31]


def assert_local_base_pin(image_id: str, alias: str, image_inspector) -> None:
    """The executor must verify this before AND after offline image build."""
    model.require(alias == local_base_alias(image_id) and
                  image_inspector(alias) == image_id,
                  "OCI_BASE_ALIAS_ID_CHANGED")


def private_file(path: Path, max_bytes: int) -> bytes:
    model.require(path.parent.is_dir() and not path.parent.is_symlink(),
                  "RUNTIME_SOURCE_ROOT_MISSING")
    parent = path.parent.stat()
    model.require(parent.st_uid == 0 and not stat.S_IMODE(parent.st_mode) & 0o077,
                  "RUNTIME_SOURCE_ROOT_NOT_PRIVATE")
    try:
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    except OSError as exc:
        raise model.RuntimeHold("RUNTIME_SOURCE_ARTIFACT_MISSING") from exc
    try:
        info = os.fstat(fd)
        model.require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and
                      info.st_nlink == 1 and
                      not stat.S_IMODE(info.st_mode) & 0o077 and
                      info.st_size <= max_bytes,
                      "RUNTIME_ARTIFACT_UNTRUSTED")
        with os.fdopen(fd, "rb") as stream:
            fd = -1
            data = stream.read(max_bytes + 1)
        model.require(len(data) <= max_bytes, "RUNTIME_ARTIFACT_EXCESSIVE")
        return data
    finally:
        if fd >= 0:
            os.close(fd)


def write_new(path: Path, raw: bytes, mode: int):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                 getattr(os, "O_NOFOLLOW", 0), mode)
    with os.fdopen(fd, "wb") as stream:
        stream.write(raw)
        stream.flush()
        os.fsync(stream.fileno())


def safe_tar_files(raw: bytes, *, plugin: str, document: dict) -> dict[str, bytes]:
    expected = {r["path"]: r for r in document["source_runtime_files"]}
    files = {}
    with tarfile.open(fileobj=io.BytesIO(raw), mode="r:") as archive:
        for member in archive:
            name = PurePosixPath(member.name)
            model.require(not name.is_absolute() and
                          name.parts[0] == plugin and ".." not in name.parts,
                          "RUNTIME_TAR_SCOPE_ESCAPE")
            model.require(member.uid == 0 and member.gid == 0 and
                          member.mtime == 0 and (member.isdir() or member.isfile()),
                          "RUNTIME_TAR_NONREGULAR_DENIED")
            if member.isdir():
                continue
            relative = "/".join(name.parts[1:])
            model.require(relative in expected and relative not in files and
                          not relative.startswith("tests/"),
                          "RUNTIME_TAR_UNDECLARED_OR_DUPLICATE")
            data = archive.extractfile(member).read()
            record = expected[relative]
            model.require(len(data) == record["size"] and
                          model.sha(data) == record["sha256"],
                          "RUNTIME_TAR_FILE_DIGEST_CHANGED")
            files[relative] = data
    model.require(set(files) == set(expected),
                  "RUNTIME_TAR_FILES_INCOMPLETE")
    return files


def prepare_context(runtime_plan: dict, artifacts: dict[str, tuple[Path, Path]],
                    destination: Path) -> dict:
    """Creates only a new local image-build context; no docker run or switch."""
    model.verify_plan(runtime_plan)
    model.require(not destination.exists() and not destination.is_symlink(),
                  "RUNTIME_CONTEXT_ALREADY_EXISTS")
    parent = destination.parent
    model.require(parent.is_dir() and not parent.is_symlink() and
                  parent.stat().st_uid == 0 and
                  not stat.S_IMODE(parent.stat().st_mode) & 0o077,
                  "RUNTIME_CONTEXT_ROOT_NOT_PRIVATE")
    model.require(set(artifacts) == {r["plugin"] for r in runtime_plan["layers"]},
                  "RUNTIME_CONTEXT_LAYER_BINDING_MISSING")
    # Validate EVERY artifact and tar before creating any context directories.
    prepared = {}
    for layer in runtime_plan["layers"]:
        plugin = layer["plugin"]
        manifest, package = artifacts[plugin]
        doc = json.loads(private_file(manifest, 1_000_000))
        verified = model.verify_artifact(doc)
        model.require(verified == layer, "RUNTIME_SOURCE_MANIFEST_NOT_PINNED")
        raw = private_file(package, 100_000_000)
        model.require(model.sha(raw) == layer["artifact_sha256"] and
                      len(raw) == doc["artifact_bytes"],
                      "RUNTIME_PACKAGE_DIGEST_CHANGED")
        prepared[plugin] = safe_tar_files(raw, plugin=plugin, document=doc)
    destination.mkdir(mode=0o700)
    output = destination / "plugins"
    output.mkdir(mode=0o755)
    for plugin, files in sorted(prepared.items()):
        base = output / plugin
        base.mkdir(mode=0o755)
        for name, content in sorted(files.items()):
            relative = PurePosixPath(name)
            model.require(not relative.is_absolute() and
                          all(part not in (".", "..") for part in relative.parts),
                          "RUNTIME_CONTEXT_TRAVERSAL_DENIED")
            target = base.joinpath(*relative.parts)
            target.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
            write_new(target, content, 0o644)
    # This Dockerfile contains zero secrets, callbacks, tenant config or sites.
    # The base is an exact installed image ID, NOT a mutable tag.
    dockerfile = (
        "FROM " + local_base_alias(runtime_plan["base_image_id"]) + "\n" +
        "LABEL org.mad4b.runtime.plan=\"" +
        runtime_plan["runtime_plan_sha256"] + "\"\n" +
        "LABEL org.mad4b.runtime.site=\"" +
        runtime_plan["site"]["site_uuid"] + "\"\n" +
        "COPY --chown=0:0 plugins/ /opt/mad4b/immutable-runtime/wp-content/plugins/\n"
    ).encode("ascii")
    write_new(destination/"Dockerfile", dockerfile, 0o600)
    outcome = {
        "contract": CONTRACT,
        "runtime_plan_sha256": runtime_plan["runtime_plan_sha256"],
        "base_image_id": runtime_plan["base_image_id"],
        "base_image_local_alias": local_base_alias(runtime_plan["base_image_id"]),
        "dockerfile_sha256": model.sha(dockerfile),
        "artifact_count": len(prepared),
        "source_file_count": sum(len(files) for files in prepared.values()),
        "context_root_owned": True,
        "production_mutated": False,
        "traffic_switched": False,
        "state": "OCI_CONTEXT_READY_NO_RUNTIME_AUTHORITY",
    }
    write_new(destination/"context-proof.json",model.canonical(outcome)+b"\n",
              0o600)
    return outcome
