#!/usr/bin/env python3
"""Run the exact bundled vendor render code, without installing/mutating a site."""
import hashlib
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile

plugin = Path(__file__).resolve().parents[1]
archive = plugin.parent / 'jet-smart-filters.zip'
with zipfile.ZipFile(archive) as source:
    render = source.read('includes/render.php')
    aliases = source.read('includes/url-aliases.php')
with tempfile.TemporaryDirectory(prefix='indexflow-vendor-') as temporary:
    path = Path(temporary) / 'render.php'
    path.write_bytes(render)
    alias_path = Path(temporary) / 'url-aliases.php'
    alias_path.write_bytes(aliases)
    subprocess.run([shutil.which('php') or 'php', str(plugin / 'tests/indexflow-vendor-handoff-smoke.php'), str(path), str(alias_path)], check=True)
print('Vendor render source sha256=' + hashlib.sha256(render).hexdigest())
