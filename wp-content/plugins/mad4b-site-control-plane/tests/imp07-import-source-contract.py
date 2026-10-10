#!/usr/bin/env python3
"""IMP07 source invariants only; not a native PHP or WordPress test."""
from pathlib import Path
P=Path(__file__).resolve().parents[1]
G=P.parent.parent.parent
get=lambda f:(P/"includes"/f).read_text(encoding="utf8")
authority=get("class-mad4b-scp-activity-import-authority.php")
review=get("class-mad4b-scp-activity-import-review.php")
snapshot=get("class-mad4b-scp-activity-import-snapshot.php")
xlsx=get("class-mad4b-scp-activity-import-xlsx.php")
modes=get("class-mad4b-scp-activity-import-modes.php")
ux=get("class-mad4b-scp-activity-import-experience.php")
profiles=get("class-mad4b-scp-content-experience-profiles.php")
test=(P/"tests"/"imp07-import-source-runtime.php").read_text(encoding="utf8")
preflight=(P/"tests"/"feature007-manual-preflight.py").read_text(encoding="utf8")
def ensure(test,why):
    if not test: raise AssertionError(why)
for token in ("'auto_execute'", "false !== $raw['auto_execute']",
              "'mad4b_import_implicit_execution_denied'",
              "'period_start_field'", "'period_end_field'",
              "'period_format'", "iso_date", "unix_seconds"):
    ensure(token in authority,"Missing dynamic policy and safe roundtrip: "+token)
for token in ("parse_period(", "DateTimeImmutable::createFromFormat",
              "date_period_invalid", "date_interval_reversed",
              "historical_rate_period_requires_review"):
    ensure(token in review, "Missing strict ISO/UTC interval review: "+token)
for token in ("php://temp/maxmemory:2097152", "rewind( $out )",
              "fpassthru( $out )", "mad4b_import_csv_formula_denied",
              "mad4b_import_export_size", "Content-Length"):
    ensure(token in snapshot, "Missing complete preflight-before-download: "+token)
ensure(snapshot.index("fputcsv( $out, $headers )") < snapshot.index("nocache_headers();"),
       "CSV response header leaked before the whole file was validated")
for token in ("MAD4B_IMPORT_XLSX_PARSER_APPROVED", "is_uploaded_file", "ZipArchive::RDONLY", "MAX_UNCOMPRESSED",
              "MAX_CELLS", "TargetMode", "External", "TYPE_FORMULA",
              "setReadDataOnly( true )", "listWorksheetNames",
              "count( $names ) !== 1", "PhpSpreadsheet",
              "source_format' => 'xlsx'", "post_writes' => 0",
              "catch ( \\Throwable"):
    ensure(token in xlsx,"Unbounded or unsafe XLSX conversion: "+token)
ensure("'admin_xlsx_convert'" in modes and "MAD4B_SCP_Activity_Import_Xlsx::available()" in modes,
       "XLSX Mode may misrepresent parser readiness")
ensure("'admin_xlsx_convert' === $mode" in ux and
       "'admin_post_mad4b_activity_import_xlsx'" in review and
       "admin_upload_xlsx()" in review,
       "XLSX UI upload not connected to an approved Staging handler")
ensure("MAD4B_SCP_Activity_Import_Authority::normalize_contract(" in test and
       "date_period_invalid" in test and "mad4b_xlsx_parser_unavailable" in test,
       "Negative native fixture omits policy roundtrip or XLSX denial")
for token in ("class-mad4b-scp-activity-import-xlsx.php",
              "imp07-import-source-runtime.php",
              "imp07-import-source-contract.py"):
    ensure(token in preflight,"Offline manual preflight did not register "+token)
ensure("class-mad4b-scp-activity-import-xlsx.php" in profiles,
       "XLSX source capability not loaded")
print("PASS IMP07 source-owned interval schema, bounded XLSX upload, preflighted CSV and regression enrollment (STATIC ONLY)")
