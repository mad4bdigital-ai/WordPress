#!/usr/bin/env python3
"""Execute real workbook parser; reject stubs, partial runs and PHP errors."""
import shutil, subprocess, re, sys
from pathlib import Path
root=Path(__file__).resolve().parents[1]
media=(root/'includes/class-mad4b-scp-wordpress-media-context.php').read_text()
pipeline=(root/'includes/class-mad4b-scp-context-source-pipeline.php').read_text()
admin=(root/'includes/class-mad4b-scp-context-admin-ui.php').read_text()
authority=(root/'includes/class-mad4b-scp-context-authority.php').read_text()
mimes=['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
       'application/vnd.ms-excel.sheet.macroEnabled.12','text/csv']
assert all(t in x for t in mimes for x in [media,pipeline,admin,authority]),"MIME scope incomplete"
assert 'normalize_local_spreadsheet_bytes' in media
php=shutil.which('php')
if not php: raise SystemExit('PHP runtime missing')
test=Path(__file__).with_name('context-media-excel-runtime.php')
run=subprocess.run([php,str(test)],capture_output=True,text=True,timeout=60,check=False)
print(run.stdout,end='')
if run.stderr:print(run.stderr,file=sys.stderr)
assert run.returncode==0, 'Excel parser PHP regression failed'
assert len(re.findall(r'(?m)^[a-z][a-z0-9_]+: PASS$',run.stdout))==12, 'Excel parser tests incomplete'
assert 'SUMMARY pass=12 fail=0' in run.stdout,'Excel parser test summary absent'
print('MEDIA_EXCEL_EXECUTED_REGRESSION: PASS')
