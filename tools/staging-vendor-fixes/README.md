# Staging vendor runtime boundary repairs

The live ETG search archive loses its Cairo search when pagination executes,
and permalink pagination appends `pagenum/2/` inside the final query value.
ARE renders PHP warnings from a null JetEngine block identity and an Elementor
array used as a text replacement. These candidates repair the actual bundled
vendor sources; they do not change site content or authority.

The recipes pin the full input archive and each original modified file. The
builder rejects drift, duplicate ZIP paths, traversal, and symlinks. All input
packages must match the reviewed repository bundles. It produces normalized,
installable plugin ZIPs and a SHA-256 manifest without executing WordPress.

Changes:

- JetEngine rejects invalid block identities before array access.
- Elementor text replacements reject arrays and non-stringable objects; the
  structured `returnType=object` branch and stringable/scalar values remain intact.
  This bounds invalid output; the offending site tag configuration still needs review.
- JetSmartFilters builds pagination paths before the query and fragment in PHP
  and JavaScript. Plain URLs retain the query; PHP sort type is initialized per argument.
- JetSearch carries the configured search parameter and three public form fields
  into JetSmartFilters AJAX `extra_props`, preserving explicit values. Arbitrary
  query parameters, array inputs, and values above 8 KiB are excluded. No site ID,
  post ID, domain, taxonomy ID, or query ID is hardcoded.

Run from the repository root with Python 3, Node.js, and PHP:

```bash
python3 tools/staging-vendor-fixes/verify.py
python3 tools/staging-vendor-fixes/build.py --source wp-content/plugins --output /tmp/mad4b-vendor-candidates
```

`verify.py` exercises actual vendor classes and the bundled pagination method,
reproduces failures against original source, checks every payload entry, rejects
archive drift, and repeats the build to verify deterministic hashes.

Local validation used PHP 8.3 and Node.js. The PHP 8.5 live scenario still needs
verification after deployment. These are **uncertified Staging candidates**, not
a successful deployment or a Production release. Existing plugin versions are
retained; the candidate manifest identifies the patched bytes.

Before applying: read installed plugin versions and source digests, back up the
installed packages and relevant settings, and obtain the governed package plan
and exact operation ticket. Preserve all plugin settings. After replacement,
invalidate the affected frontend asset cache and repeat Cairo search → page 2 →
reload, ARE home rendering, image dynamic tags, sorting, and WPML navigation.
Restore exact previous packages if those checks fail. User-owned content fixes,
the broken ETG link, verification text, and form encoding are separate pending
site mutations; these packages do not claim to repair them.
