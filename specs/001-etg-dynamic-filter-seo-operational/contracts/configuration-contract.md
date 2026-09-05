# Configuration Contract v4

One namespaced option `etg_dfsb_settings` remains the persistence surface. First-install global `enabled=false`. `profiles_json` is the bounded profile authority and is normalized after storage and again after extension filters.

Safety rules:
- invalid/oversized profile JSON preserves the previous valid snapshot;
- maximum 50 profiles in Alpha, including extension-filter output;
- new profiles default `enabled=false` when omitted;
- duplicate profile IDs are configuration errors;
- every enabled profile requires exact archive paths and exact provider/query route pairs;
- global inheritance cannot synthesize authority identities or route combinations;
- route/taxonomy/combination/profile limit violations are visible validation errors on the first validation call and make scope resolution fail closed;
- configuration revision is SHA-256-derived from normalized settings and is included in evidence.
