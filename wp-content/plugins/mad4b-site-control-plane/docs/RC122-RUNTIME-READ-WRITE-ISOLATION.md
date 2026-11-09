# rc.122 Runtime lane isolation

This candidate reconciles concurrent Tours.dev staging readiness changes.

Runtime = registered site identity and valid build bootstrap.
Read = Runtime plus live verified connection/reconnect identities.
Write = per-target grant, candidate, current snapshot and DB writer topology.
The report only indicates precheck readiness and does not grant execution.
Exact MCP protocol compatibility is an operation-scoped diagnostic, never
a global read or WordPress-native write gate.
Deferred Skills certification is an observation rather than proof of failure.

Session evidence merge only means coherent bounded read evidence from
one runtime generation, not release acceptance or Write authority.

No production operation is activated and no new grants are issued.
Regressions run in CI across supported PHP versions.
