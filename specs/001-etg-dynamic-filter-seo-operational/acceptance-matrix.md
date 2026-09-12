# Acceptance Matrix — Generic Surface Profile Engine

Every row is fail-closed unless explicitly classified `neutral/vendor-owned`. Synthetic rows prove policy mechanics only; live staging rows remain mandatory before RC.

| ID | Scenario | Expected governed behavior |
|---|---|---|
| A01 | fresh install | global bridge disabled; vendor behavior |
| A02 | migrated Tours profile | existing ETG route/taxonomies preserved without auto-enabling a new surface |
| A03 | approved Tours EN location+type pair | index only after structural, content, count and threshold gates |
| A04 | unregistered Tours pair | hard noindex `combination_not_approved` |
| A05 | unknown taxonomy on known profile | hard noindex / scope violation |
| A06 | duplicate or unsupported multi-value taxonomy | hard noindex malformed state |
| A07 | unknown functional query parameter | hard noindex `unsupported_query_state` |
| A08 | UTM/gclid only | ignored for eligibility; stripped from canonical state |
| A09 | `paged=2` | hard noindex |
| A10 | missing WPML translation | hard noindex `translation_fallback` |
| A11 | archive canonical under `/it/...` | language path preserved |
| A12 | observed provider/query differs from URL | hard noindex `provider_query_mismatch` |
| A13 | result count missing/untrusted | hard noindex `result_count_unavailable` |
| A14 | result count = 0 | hard noindex `zero_results` |
| A15 | thin content | hard noindex `content_not_ready` |
| A16 | hard deny + ordinary soft override=true | remains hard noindex |
| A17 | soft threshold + intentional override | base/final/override evidence recorded |
| A18 | Property archive + Catalog query | `scope_violation:query_not_profiled` hard noindex |
| A19 | Property profile + Catalog taxonomy `brand` | `scope_violation:taxonomy_not_profiled` hard noindex |
| A20 | two enabled profiles claim same canonical archive+route authority | readiness error + runtime `ambiguous_profile` hard deny |
| A21 | required Post Type proven by exact JetEngine Posts Query | continue through policy |
| A22 | Query Builder Post Type mismatched | hard noindex `post_type_mismatch` |
| A23 | required Post Type not provable | hard noindex `post_type_unobserved` |
| A24 | taxonomy configured but not attached to allowed Post Type | readiness degraded |
| A25 | taxonomy known but exact taxonomy set not allowlisted | hard noindex `taxonomy_set_not_allowlisted` |
| A26 | required taxonomy meta missing | hard noindex `taxonomy_meta_constraint_missing` |
| A27 | taxonomy meta value disallowed | hard noindex `taxonomy_meta_constraint_failed` |
| A28 | generic Real Estate approved city+property-type pair | index only after exact combo + content + count + threshold |
| A29 | Catalog brand-only state not structurally allowed | hard noindex even with high result count |
| A30 | disabled Knowledge profile | neutral/vendor-owned |
| A31 | add Knowledge `format` taxonomy by profile config only | parser/policy supports it without PHP change; indexing still requires explicit authorities |
| A32 | public taxonomy discovered but not configured | discovery only; no runtime authority |
| A33 | exact translated/nested archive path configured | only the configured/canonicalized authority matches |
| A34 | provider/query arrays could form Cartesian pair | exact `routes[]` blocks unconfigured pair |
| A35 | profile-specific canonical mode | applies only to selected profile |
| A36 | profile-specific content minimum | applies only to selected profile |
| A37 | generic composition | arbitrary taxonomy roles ordered by configured priority |
| A38 | travel composition | ETG travel-specific Arabic/English/neutral behavior preserved |
| A39 | Synthetic Scenario Lab passes | `synthetic=true`; never staging evidence |
| A40 | one profile disabled during incident | that surface returns to vendor behavior; other profiles unaffected |
| A41 | global kill switch disabled | all bridge profiles inactive |
| A42 | PHP warnings/notices | none |
| A43 | exact-head artifact provenance | source SHA + vendor blob SHAs + deterministic ZIP digest |
| A44 | new profile omits `enabled` | profile normalizes to disabled |
| A45 | duplicate profile IDs | configuration error; no silent last-write-wins |
| A46 | new profile has only legacy `providers[]/query_ids[]` | validation error; no Cartesian authority unless explicitly inherited legacy profile |
| A47 | new profile has archive slug but no exact archive path | validation error; no route authorization |
| A48 | request `/foo/properties/` while authority is `/properties/` | does not match by arbitrary suffix |
| A49 | request `/it/properties/` and `it` is active WPML language | may match canonical base archive authority according to multilingual contract |
| A50 | explicit Unicode authority `/ar/كتب/` | normalizes and matches without dropping Unicode characters |
| A51 | unknown first path segment wrapping an archive | does not masquerade as a WPML prefix |
| A52 | Query Builder `post_type=any` | unbounded/unobserved; hard deny when binding required |
| A53 | Query Builder is non-`posts` query type | cannot grant Post Type authority |
| A54 | Query Builder returns `property` + foreign `product` | hard deny; all observed Post Types must be inside profile allowlist |
| A55 | `post_type_authority=both`, sources disagree | hard deny |
| A56 | configured custom term `field_map` | configured native/ACF fields precede safe default keys |
| A57 | two configured gallery fields contain overlapping attachment IDs | aggregate and deduplicate IDs deterministically |
| A58 | invalid `profiles_json` submitted | previous valid profile authority snapshot preserved; settings error emitted |
| A59 | profile input exceeds Alpha profile bound | previous valid snapshot preserved; no partial/truncated authority replacement |
| A60 | extension filter returns malformed/new profile without `enabled` | output re-normalized; new profile remains disabled or invalid |
| A61 | same term text appears in intro and description | content readiness counts unique corpus once, not inflated duplicate text |
| A62 | content filter tries to promote failed readiness | promotion blocked; evidence records attempted promotion |
| A63 | content filter vetoes otherwise ready content | veto accepted; readiness becomes false |
| A64 | metadata adapter sees wrong Post Type/provider/translation state | no Rank Math mutation |
| A65 | shortcodes see wrong Post Type/provider/translation state | emit no governed dynamic content |
| A66 | generic breadcrumb with arbitrary taxonomy roles | order follows profile taxonomy priority, not travel hard-coding |
| A67 | Profile Blueprint created from discovered CPT/taxonomies | `enabled=false`, no archive routes/sets/combinations; authorizing=false |
| A68 | Blueprint includes taxonomy not attached to chosen Post Type | taxonomy excluded/warned; no implicit relation authority |
| A69 | exact archive+route belongs to disabled profile and enabled profile separately | only enabled valid authority may govern; ambiguity rules remain fail-closed |
| A70 | profile route/query points to missing Query Builder object | readiness/post-type authority degraded; no index |
| A71 | profile taxonomy deleted/unregistered after configuration | readiness degraded and active matching state hard denies |
| A72 | profile exact combination remains but taxonomy set is removed | structural set denial wins before exact combination approval |
| A73 | exact combination exists but required business meta becomes paused | meta hard gate wins; count cannot override |
| A74 | count remains high after content translation becomes incomplete | translation/content hard gate wins |
| A75 | future public CPT/taxonomy appears after plugin update | inventory can expose it; no profile generated/enabled automatically |
| A76 | profile growth from one to many Post Types | each profile remains isolated by exact archive/route/Post Type/taxonomy authorities |
| A77 | profile configuration changes during cache-free Alpha | next request uses new configuration revision; no persistent decision cache |
| A78 | real staging filtered count vs rendered listing | must match for representative URLs before RC |
| A79 | real rendered Rank Math title/meta/canonical/robots/OG | must match IndexDecision and selected profile before RC |
| A80 | kill switch/per-profile disable + cache purge rehearsal | rollback proven before RC |
| A81 | public crawl shows `tour-styles_jet` while configured profile has `tour-style_jet` | no auto-remap/authority widening; staging inventory required |
| A82 | public crawl shows `archive_category` while profile route is `tours_query_archive` | route remains distinct/unprofiled until exact Query Builder identity is proven |
| A83 | taxonomy filter exists only as query-string state | bridge remains inactive/vendor-owned under current URL grammar; no implicit JSF-path authority |
| A84 | public language list differs from assumed test languages | runtime WPML inventory is evidence; crawl does not authorize languages |
| A85 | runtime inventory is downloaded | contract says `authorizing=false`, `read_only=true`, `profile_mutation=false`; no settings change |
| A86 | Query Builder inventory contains `post_type=any` | reported `post_type_bounded=false`; never profile authority |
| A87 | Query Builder inventory exposes dynamic/filter args | raw args are omitted; only structural IDs/Post Types/taxonomy names leave the collector |

| A88 | runtime inventory fingerprint is modified after collection | reconciliation returns `invalid_inventory`; no drift analysis/authority |
| A89 | imported inventory exceeds collector bounds | rejected before reconciliation |
| A90 | enabled Profile Post Type disappears | `blocking: profile_post_type_missing` |
| A91 | enabled Profile taxonomy disappears/detaches | blocking drift |
| A92 | exact JetEngine query disappears | blocking drift |
| A93 | bounded posts query changes to `post_type=any` | current + snapshot boundedness blockers |
| A94 | query changes from one allowed Post Type to allowed+foreign | blocking Post Type mismatch |
| A95 | native CPT archive URL differs from configured Elementor listing Page | warning evidence only; T122 proves real surface |
| A96 | active WPML language path changes between snapshots | review-required language drift |
| A97 | newly discovered CPT has archive/query evidence | disabled candidate may be emitted |
| A98 | disabled candidate is generated | `enabled=false`, `archive_paths/routes/allowed_taxonomy_sets/indexable_combinations` all empty |
| A99 | exact single-CPT Query Builder route is discovered | may appear under evidence only, never authority fields |
| A100 | attached taxonomy appears on an already-profiled CPT but is not in Profile rules | warning, no structural-set expansion |
| A101 | invalid previous snapshot supplied | visible `previous_inventory_invalid`; not silently treated as unchanged |
| A102 | non-JetEngine provider route exists | inventory marks provider outside its authority; does not infer query evidence |
| A103 | WP-CLI previous file exceeds 2 MiB | command fails before parsing/reconciliation |
| A104 | reconciliation returns `blocked_drift` | CLI warns; no automatic disable/edit/rollback |
| A105 | identical valid snapshots compared | no structural drift findings |

| A106 | Query inventory observes 105 queries with limit 100 | completeness says observed=105, included=100, truncated=true; reconciliation blocks |
| A107 | same Query Builder objects returned in different order | identical normalized order and snapshot fingerprint |
| A108 | two Query Builder records share one effective custom query ID | identity collision is blocking; collided ID cannot satisfy route |
| A109 | enabled profile references taxonomy absent from a truncated taxonomy inventory | unresolved-incomplete finding, never proven `profile_taxonomy_missing` |
| A110 | previous complete taxonomy snapshot compared with current truncated snapshot | section comparison skipped; no false `snapshot_taxonomies_removed` |
| A111 | Query Builder inventory unavailable while profile has JetEngine route | `profile_query_inventory_unavailable`; no false missing |
| A112 | inventory truncated or identity-ambiguous with unprofiled CPTs | `disabled_candidates=[]` |
| A113 | completeness metadata included_count does not match serialized section | snapshot rejected as invalid inventory |
| A114 | archive-path translation budget is truncated | blocking inventory-quality evidence; archive absence is unresolved evidence |
