# Media Rights publication policy — Site neutral

The original `MAD4B_SCP_Remote_Media_Rights::publish_guard` and `MAD4B_SCP_Content_Experience_Media_Rights::publish_guard` no longer depend on `wp_get_environment_type()` or the source demo hostname to choose rights-verification behavior. Both use the same site-specific WordPress option `mad4b_remote_media_rights_mode`, default `advisory`. The canonical filter `mad4b_remote_media_rights_mode` may supply the effective option for centrally governed installations. Supported values: `advisory` and `enforced`; invalid values fail with a policy error. No version pin or environment label is required.

- `advisory`: missing or unknown rights metadata yields a warning rather than blocking publication. This is an **editorial workflow setting**, not a license, copyright ownership claim, or authorization to use another person's protected media.
- `enforced`: missing evidence blocks publication through the original guard. This is explicitly configurable per site.
- Both modes retain **exact file/content identity, source/provenance state checks** and block **known expired or invalid license dates**. Other WordPress capability, target binding, and media import security checks remain unchanged.
- An attachment discovered with unknown rights can remain `draft` in the private JetEngine CCT. Such records must not be presented as licensed credits until actual source/rights facts are verified.

Validation on `tours.dev.mad4b.com` (Staging) and `wayfatours.com` (Production) showed identical default `advisory` behavior, preserving both existing content and CCT records. Test fixtures are available under `ops/media-rights/tests`; they use a source-host fixture ID and database rollback for simulated provenance. They are not an instruction to automatically ingest or publish unlicensed third-party images.

Other environment-specific gates within Company Management, structural QA, disaster recovery or provider canaries are separate workflows and are **not** disabled by this media-publishing policy. A broader change requires per-operation functional validation rather than a blanket replacement of environment guards.
