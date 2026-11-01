# MAD4B optional AI Agent Only grant mode

## Purpose
The existing Context Authority ai_only review mode binds an authenticated reviewer to content. It does not itself create permissions. The optional AI Grant Mode adds one owner-controlled exact ability: mad4b/context-ai-review on server mad4b-write.

## Selection and safety
The default is off. An enrolled WordPress administrator must opt in using the native WP nonce on Settings > MAD4B AI Routing. The original Site Profile origin, Agent identity and the nonproduction environment must match; append-only audit must be ready. Production is excluded.

If the exact review grant already exists, it is reused without claiming ownership. Any grant created by this optional controller is environment-scoped and tracked by its exact ID, and only that grant can be revoked when the owner disables the setting. Explicit denials win. A Staging Agent in a Local Lab is supported only if original AI Context Authority is already ready and an original all-environment grant exists; the mode never manufactures a cross-environment grant.

## Privileged grants
Developer Grants, Developer Breakglass and Full Staging Authority continue using their separate original owner OAuth and step-up paths, exact plan fingerprints, Agent attribution and audit. No AI response or toggle may mint, inherit or widen those protected grants. An independently preauthorized Agent can still act only under its existing permissions. This mode does not enable raw-SQL breakglass, publish content, grant payment authority or deploy Production code.

## Local acceptance
On ETG WooCommerce Local Lab, WordPress 7.1.3 and REST HTTP 200 remained healthy. The feature is installed read-only with its option off. No live grants were issued. Three mandatory Brand Context assets remain approved, Registry 77. Regression tests cover explicit owner nonce, default-off, production rejection, deny-first, exact ability, idempotency, revocation ownership, cross-environment reuse, and absence of privileged grants. All tests must pass under PHP 7.4 or later. Source-reviewed CI coverage is not itself Production authorization.
