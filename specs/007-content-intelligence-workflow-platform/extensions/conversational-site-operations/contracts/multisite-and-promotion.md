# Multi-site Command and Signed Environment Promotion

A multi-site request produces N independent plans with N site scopes, plugin/schema compatibility checks, object revisions, permissions and owner approvals. Coordinator aggregates results but may never reuse tokens, grants, secrets or approvals from Site A on Site B. Tenant data residency and locale must be honored. Templates carry inert schema/field migration data only, no credentials or hidden elevated grants.

Staging → Production: independently signed exact source/artifact and plan diff → clean Staging/Browser/Host/provider evidence → release readiness checks → explicit separate Production authority and approval → controlled application → independent Production postcondition and rollback. A Staging ready status, CI green or merged PR cannot authorize Production. Clones/restore invalidates identity; secrets are never copied across environments by default.
