# WordPress-native MCP incident evidence

The existing managed MU bootstrap loads `bootstrap/mad4b-native-incident-mu.php`
from the installed Control Plane, only after exact enrolled Staging checks.
This extension reuses that loader and the existing option journal. It does not
install another MU file or change the bootstrap, Recovery Mode, WP_DEBUG,
error display, grants or execution policy.

In **MAD4B → Runtime Components → Diagnostics**, inspect the recorder state and
recent incident table. The same bounded journal is returned by the existing
`mad4b/native-runtime-diagnostics` governed read ability. Use the existing
preparation/read dispatcher when this ability is not directly projected.
If MCP cannot return a diagnostic, open the native administrator page instead.

New capture paths:

- Read permission/execution callback exceptions share the correlation shown in
  the failed MCP tool response with their native journal entry.
- Optional trace and metric storage exceptions record their original Throwable.
- The MCP Adapter error-handler interface records the errors that the Adapter
  catches outside the ability callback, including response assembly. It never
  changes the Adapter response or grants execution. This interface receives text
  rather than the Throwable, so these entries do **not** invent file/line hints.

The administrator table shows kind, stage, ability/tool name, correlation and
source hints. Relative file and line are shown only for publicly versioned MAD4B
plugin files. Other components expose family/slug and keyed fingerprints only.
Raw exception messages, stack traces, absolute paths, request bodies, tokens,
headers and user identities are never stored or rendered.

Captured exceptions are limited to three attempts per request, protected against
recursive storage failure, deduplicated for 60 seconds, and retained in the same
12-event, seven-day ring as fatal incidents. Persistence is best-effort; no event
means **unknown**, not a healthy runtime. A database outage, pre-bootstrap fatal,
process kill or memory exhaustion may prevent capture. Concurrent requests can
lose diagnostic history; this is not an audit or execution receipt.

After installing this source, reproduce **one read** that failed and inspect its
entry. Do not replay a mutation with an uncertain outcome. Recorder-not-loaded
requires checking the existing managed MU bootstrap and exact Staging binding;
it must not trigger an unreviewed MU replacement or Production fallback.

Source fixtures validate the recorder, adapter interface, callback correlation,
DB failure containment, capture budget, stored-data sanitization and policy
WP_Error denial. They do not certify deployment or establish the live -32603
root cause on any site.
