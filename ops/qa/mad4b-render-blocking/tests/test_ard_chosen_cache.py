from pathlib import Path
import json,re
R=Path(__file__).resolve().parents[4]
manifest=json.loads((R/'.well-known/ard.json').read_text())
assert isinstance(manifest.get('entries'),list) and manifest['entries']
for e in manifest['entries']:
 assert re.fullmatch(r'urn:air:tours\.dev\.mad4b\.com:[a-z0-9:-]+',e['identifier'])
 assert e['displayName'] and e['type'] and (bool(e.get('url')) != bool(e.get('data')))
 assert len(e['representativeQueries']) in range(2,6)
 assert e.get('url','').startswith('https://tours.dev.mad4b.com/wp-json/wp/v2/')
js=(R/'wp-content/mu-plugins/assets/mad4b-chosen-crawlable.js').read_text()
assert '.chosen-container a.chosen-single' in js
assert "setAttribute('href',catalog)" in js
assert "event.preventDefault()" in js
print('PASS: ARD manifest public origin, schema core and Chosen crawlable fallback')
