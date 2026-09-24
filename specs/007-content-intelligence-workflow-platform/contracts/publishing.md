# Contract — Publishing

Contract: mad4b.content-publishing.v1

## Principle
Publishing is orchestration over existing governed site abilities, not a parallel WordPress mutation stack.

## PublishManifest
Binds:
- site_uuid
- operation mode
- target post type/id
- ArticleDraft artifact + SHA
- MediaManifest artifact + SHA
- SEO intent/artifact + SHA
- expected target-state fingerprint
- reviewed operation plan SHA
- manifest SHA

Modes:
- create_draft
- update_draft
- schedule
- publish

## Execution
1. evaluate relevant quality gate;
2. read target state;
3. confirm expected fingerprint;
4. authorize exact site abilities;
5. execute content/media/SEO operations;
6. read after write;
7. verify result;
8. record mutation/audit/evidence;
9. update ContentJob target reference/stage.

## Rules
- draft is not publish;
- scheduling is not publish;
- unsupported SEO provider stays fail-closed;
- no direct Rank Math/other provider DB writes unless separately certified;
- stale manifest/target state fails closed;
- Production publication remains separately authorized.
