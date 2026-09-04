# Configuration Contract v3

One namespaced option `etg_dfsb_settings` remains the persistence surface. First-install global `enabled=false`. `profiles_json` is the bounded profile authority and is normalized after storage and again after extension filters.

Safety rules:
- invalid/oversized profile JSON preserves the previous valid snapshot;
- maximum 50 profiles in Alpha;
- new profiles default `enabled=false` when omitted;
- duplicate profile IDs are configuration errors;
- new non-legacy profiles require exact archive paths and exact route pairs;
- configuration revision is SHA-256-derived from normalized settings and is included in evidence.
