# Original 313 owner launcher — preserved source reference

The WordPress **live Central** has the original provider loaded from
`mad4b-platform/modules/authority/332-mad4b-original-313-owner-launcher.php`
through the platform `config/ordered-mu-map.php`, entry 332. The FAORE Git
checkout does not track the `mad4b-platform` tree. This byte-identical
snapshot and its SHA-256 manifest prevent the working source inventory
from silently overlooking the existing authenticated owner launcher.

**Do not install this snapshot as a second plugin, and do not auto-create
305 requests or grants from a VPS shell.** The canonical live module is
owned by the Central platform; a future platform packaging/reconciliation
must explicitly preserve it and avoid duplicate class loading.

The original launcher serves:
- WordPress Tools > MAD4B Native 313
- POST `/wp-json/mad4b/v1/native-313/readonly-canary`
- GET `/wp-json/mad4b/v1/native-313/readonly-canary/{job_id}`

It uses the existing Wayfatours Production full WPCLI standing grant only
for an exact read-only `wp option get home` canary and requires an
**authenticated, authorized WordPress REST administrator session**.
It dispatches the original 305/306 and delegates signed claim/execution and
independent verification to the existing 312/313 worker; the owner action
does not itself prove completion.

Verified live, read-only: route class registered, route POST+GET present,
Wayfatours target/slot UUID matching, original grant and transport
registered and active, original signed host proof verified. The operator
session in ChatGPT does not possess a browser owner's REST nonce; the
launcher has NOT been invoked through WordPress.

Snapshot-only test checks the local file against the manifest. It is NOT
an assertion that all surrounding `mad4b-platform` code is in Git.
