#!/usr/bin/env python3
"""Non-destructive, all-candidate site media reference inventory.

This tool NEVER moves, deletes, marks public or edits WordPress records.
It reads from the authorized local staging MariaDB container, and writes a
private JSONL review manifest to an explicitly selected directory.
"""
import argparse
from collections import Counter, defaultdict, deque
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

class Matcher:
    def __init__(self, tokens):
        self.next = [{}]
        self.fail = [0]
        self.out = [[]]
        for token in tokens:
            state = 0
            for ch in token:
                if ch not in self.next[state]:
                    self.next[state][ch] = len(self.next)
                    self.next.append({})
                    self.fail.append(0)
                    self.out.append([])
                state = self.next[state][ch]
            self.out[state].append(token)
        pending = deque(self.next[0].values())
        while pending:
            r = pending.popleft()
            for ch, s in self.next[r].items():
                pending.append(s)
                state = self.fail[r]
                while state and ch not in self.next[state]:
                    state = self.fail[state]
                self.fail[s] = self.next[state].get(ch, 0)
                self.out[s].extend(self.out[self.fail[s]])

    def find(self, text):
        state = 0
        seen = set()
        for ch in text:
            while state and ch not in self.next[state]:
                state = self.fail[state]
            state = self.next[state].get(ch, 0)
            for match in self.out[state]:
                seen.add(match)
        return seen

def require(ok, message):
    if not ok: raise ValueError(message)

def db_lines(container, query):
    cmd = ["docker", "exec", container, "sh", "-c",
           'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" -D "$MARIADB_DATABASE" --batch --skip-column-names --quick --default-character-set=utf8mb4 -e "$1"', "read-only-audit", query]
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, bufsize=1024*1024)
    try:
        for line in proc.stdout:
            yield line.decode("ascii").rstrip("\n").split("\t")
    finally:
        proc.stdout.close()
        err = proc.stderr.read()
        code = proc.wait()
        require(code == 0, "MariaDB read failed (%s), stderr: %s" % (code, err[:250].decode(errors="replace")))

def decode(value):
    if value == "NULL": return ""
    return bytes.fromhex(value).decode("utf-8",errors="replace")

def safe_filename(raw):
    if not raw or len(raw) > 900 or "\x00" in raw or "\\" in raw or "%" in raw: return ""
    parts=raw.split("/")
    if any(p in ("",".","..") or p.startswith(".") for p in parts): return ""
    return raw

def classify(candidates, occurrence, counts):
    for item in candidates:
        refs=occurrence.get(item["id"],{})
        item["reference_signals"]=dict(sorted(refs.items()))
        item["classification"]="referenced_locally" if refs else "unresolved_manual_review"
        item["external_usage_verified"]=False
        item["file_movement_authorized"]=False
        counts[item["classification"]]+=1

def run(args):
    require(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{1,95}", args.db_container) is not None, "invalid DB container name")
    root = Path(args.media_root)
    require(root.is_absolute() and root.is_dir() and not root.is_symlink(), "invalid media origin path")
    out = Path(args.out_dir)
    require(out.is_absolute() and not out.is_symlink(), "invalid output directory")
    out.mkdir(parents=True,exist_ok=True, mode=0o700)
    os.chmod(out,0o700)
    require(not (out/"MEDIA-AUDIT-REVIEW.jsonl").exists(), "existing report must not be overwritten")
    sql = """
    SELECT a.ID,a.post_parent,HEX(IFNULL(f.meta_value,'')),HEX(a.guid)
    FROM wp_posts a
    LEFT JOIN wp_posts pp ON pp.ID=a.post_parent
    LEFT JOIN wp_postmeta f ON f.post_id=a.ID AND f.meta_key='_wp_attached_file'
    WHERE a.post_type='attachment' AND a.post_mime_type LIKE 'image/%'
      AND (a.post_parent=0 OR pp.ID IS NULL)
    GROUP BY a.ID
    ORDER BY a.ID
    """
    candidates=[]; tokens=defaultdict(set); ids=set()
    for fields in db_lines(args.db_container,sql):
        require(len(fields)==4, "bad candidate output")
        id, parent = int(fields[0]), int(fields[1])
        path=safe_filename(decode(fields[2]))
        candidate={"id":id,"parent_id":parent,"parent_state":"no_parent" if parent==0 else "dangling_parent",
                   "attached_path":path,"path_exists":False,"source_size":None}
        if path:
            file=root/path
            if file.is_file() and not file.is_symlink() and file.resolve().is_relative_to(root.resolve()):
                candidate["path_exists"]=True
                candidate["source_size"]=file.stat().st_size
            base=Path(path).name.casefold()
            if len(base)>=7: tokens[base].add(id)
            # Full relative path complements filename matches (especially collision cases).
            if len(path)>=10: tokens[path.casefold()].add(id)
        candidates.append(candidate);ids.add(id)
    require(len(ids)==len(candidates), "duplicate candidates")
    require(len(candidates)>0, "no candidates returned; refuse incomplete report")
    matcher=Matcher(tokens)
    refs=defaultdict(lambda:defaultdict(int))
    scanned=Counter()
    numeric_keys=frozenset(["_thumbnail_id","_product_image_gallery","gallery","gallery_ids","featured_image",
                           "image_gellery","_gallery_ids","_thumbnail","image","logo","image_id",
                           "cover_image","attachment_id","featured_media"])
    image_id_re=re.compile(r"(?:wp-image-|attachment[_-]?id[\"'\s:=]+|image[_-]?id[\"'\s:=]+)(\d{1,10})",re.I)
    def inspect(source, owner, value, key=""):
        scanned[source]+=1
        val=value.casefold()
        # Matches file-name and relative-path references; self metadata is not counted as a use.
        for token in matcher.find(val):
            for id in tokens[token]:
                if source=="postmeta" and owner==id and key in ("_wp_attached_file","_wp_attachment_metadata","_wp_attachment_backup_sizes"):
                    continue
                refs[id][source] += 1
        if source=="postmeta" and key in numeric_keys:
            stripped=value.strip()
            if stripped.isdecimal() and int(stripped) in ids and owner != int(stripped):
                refs[int(stripped)]["metadata_id"] += 1
            for part in re.findall(r"\d{3,10}", value):
                num=int(part)
                if num in ids and num!=owner: refs[num]["metadata_id_possible"]+=1
        for match in image_id_re.finditer(value):
            num=int(match.group(1))
            if num in ids and not (source=="postmeta" and owner==num):
                refs[num][source+"_id"]+=1

    for source, sql in [
        ("postmeta","SELECT post_id,HEX(meta_key),HEX(meta_value) FROM wp_postmeta WHERE meta_value<>''"),
        ("termmeta","SELECT term_id,HEX(meta_key),HEX(meta_value) FROM wp_termmeta WHERE meta_value<>''"),
        ("options","SELECT option_id,HEX(option_name),HEX(option_value) FROM wp_options WHERE option_value<>''"),
        ("usermeta","SELECT umeta_id,HEX(meta_key),HEX(meta_value) FROM wp_usermeta WHERE meta_value<>''"),
        ("content","SELECT ID,HEX(post_type),HEX(CONCAT_WS(' ',post_content,post_excerpt)) FROM wp_posts WHERE post_content<>'' OR post_excerpt<>''")
    ]:
        for fields in db_lines(args.db_container, sql):
            require(len(fields)==3, "bad %s rows"%source)
            owner=int(fields[0]);key=decode(fields[1])
            inspect(source, owner, decode(fields[2]), key)
    counts=Counter()
    classify(candidates,refs,counts)
    stamp=datetime.datetime.now(datetime.timezone.utc).isoformat()
    report=out/"MEDIA-AUDIT-REVIEW.jsonl"
    with report.open("x",encoding="utf-8") as f:
        os.chmod(report,0o600)
        for item in candidates:
            item["tenant_scope"]="etg-commerce-staging"
            item["review_status"]="candidate_not_approved"
            item["no_delete_no_move"]=True
            f.write(json.dumps(item,ensure_ascii=False,separators=(",",":"))+"\n")
    sha=hashlib.file_digest(report.open("rb"),"sha256").hexdigest()
    summary={
        "contract":"mad4b.media-full-local-reference-inventory.v1",
        "generated_at":stamp,"environment":"ETG Commerce Staging",
        "result":"INVENTORY_REVIEW_ONLY",
        "scope":"Local WordPress core reference columns: wp_postmeta, wp_posts, wp_termmeta, wp_options, wp_usermeta; no external website/JS/CSS or other-company coverage",
        "candidate_total":len(candidates),"classifications":dict(counts),
        "parent_states":dict(Counter(i["parent_state"] for i in candidates)),
        "missing_file_count":sum(not i["path_exists"] for i in candidates),
        "sampled_db_rows":dict(scanned),
        "report_sha256":sha,
        "quarantine_files_moved":0,
        "deletions":0,
        "public_media_catalog_permissions_granted":0,
        "external_references_cleared":False,
        "safe_to_physical_quarantine":False,
        "required_next_steps":["review unresolved images with full Elementor/WPML custom-table & external-URL references", "re-check variants and source hashes", "obtain approval for file-level guarded reversible quarantine", "revalidate links and rollback before any physical move"],
    }
    with (out/"SUMMARY.json").open("x") as f:
        os.chmod(out/"SUMMARY.json",0o600)
        json.dump(summary,f,indent=2)
        f.write("\n")
    print(json.dumps(summary,indent=2))
def main():
    p=argparse.ArgumentParser()
    p.add_argument("--db-container",required=True)
    p.add_argument("--media-root",required=True)
    p.add_argument("--out-dir",required=True)
    run(p.parse_args())
if __name__=="__main__":main()
