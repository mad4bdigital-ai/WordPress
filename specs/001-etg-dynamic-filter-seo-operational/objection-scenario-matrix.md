# Objection & Realistic Scenario Matrix

This matrix records objections that would make a system appear generic while still allowing hidden cross-surface authority, stale SEO state, or accidental index growth.

| # | Objection / realistic change | Failure mode if ignored | Governed response |
|---|---|---|---|
| 1 | Tours route receives Property/Catalog query ID | route cross-product indexes wrong inventory | known archive remains governed; `query_not_profiled` hard deny |
| 2 | Property archive receives Catalog `brand` taxonomy | shared widget leaks taxonomy across surfaces | `taxonomy_not_profiled` hard deny |
| 3 | two profiles claim same canonical archive+route | nondeterministic profile choice | configuration/readiness ambiguity + hard deny |
| 4 | WordPress registers a new public taxonomy | discovery accidentally becomes authorization | inventory only; profile remains unchanged |
| 5 | operator adds taxonomy but not structural set | partial configuration creates crawlable shape | `taxonomy_set_not_allowlisted` hard deny |
| 6 | structural set exists but exact combination missing | combinatorial SEO explosion | `combination_not_approved` hard deny |
| 7 | exact combination remains while translation disappears | fallback text becomes indexable | `translation_fallback` hard deny |
| 8 | JetEngine filtered count API/lifecycle drifts | unfiltered or stale count used as permission | authority unavailable; hard deny |
| 9 | inventory falls to zero | thin/empty landing page remains indexed | `zero_results` hard deny |
| 10 | Elementor main query is `page` but listing is `property` | main-query Post Type observation is misleading | Query Builder Posts Query is preferred authority |
| 11 | Query Builder reports `post_type=any` | unbounded query masquerades as allowed CPT | unobserved/unbounded; hard deny |
| 12 | Query Builder reports `property,product` | intersection-only check would leak foreign Post Type | all observed Post Types must be allowed; hard deny |
| 13 | Query Builder is terms/users/custom query | query ID exists but cannot prove CPT | non-post query cannot grant Post Type authority |
| 14 | required Post Type cannot be observed due hook timing/drift | unknown identity gets treated as okay | `post_type_unobserved` hard deny |
| 15 | taxonomy business meta changes `active→paused` | high count overrides business state | taxonomy meta constraint is hard gate |
| 16 | tracking params arrive from campaigns | canonical duplication | classified tracking; ignored/stripped |
| 17 | new functional sort/filter param appears | same URL grammar represents different content | unsupported state hard deny until governed |
| 18 | pagination >1 is crawled | duplicate/weak faceted pages | hard deny |
| 19 | nested/WPML archive path changes | last-slug matching authorizes unintended path | exact Unicode archive authorities + known-language canonicalization |
| 20 | arbitrary `/foo/properties/` shares suffix | suffix matching impersonates authority | reject; no arbitrary suffix matching |
| 21 | provider count expands from one to many | independent provider/query arrays create Cartesian routes | exact `{provider,query_id}` pairs only |
| 22 | new profile omits `enabled` | configuration typo activates SEO surface | new profile defaults disabled |
| 23 | duplicate profile IDs | later entry silently replaces authority | duplicate IDs are configuration error |
| 24 | invalid JSON is saved | all authorities disappear/change unexpectedly | preserve last valid snapshot and surface error |
| 25 | oversized JSON is partially truncated | partial authority set becomes active | preserve last valid snapshot; reject oversized authority |
| 26 | extension filter returns unnormalized config/profile | hooks bypass sanitization | output is normalized again before use |
| 27 | content readiness hook promotes failed content | extension bypasses hard content gate | hook is veto-only; promotion blocked and traced |
| 28 | intro repeats same term copy as description | readiness character count is artificially doubled | deduplicated content corpus |
| 29 | taxonomy uses nonstandard ACF/native SEO/image fields | PHP edit required per taxonomy | profile `field_map` selects safe bounded keys |
| 30 | gallery is split across multiple term fields | first-field-only loses assets | aggregate configured gallery fields + dedupe IDs |
| 31 | generic breadcrumb still uses travel roles | new taxonomy silently omitted | profile-priority breadcrumb order |
| 32 | metadata/shortcode callbacks run before/after identity drift | wrong surface receives dynamic SEO/content | shared presentation identity guard |
| 33 | Synthetic Scenario Lab is green | simulation mistaken for runtime certification | `synthetic=true`; staging evidence still mandatory |
| 34 | Blueprint generation sees a new CPT | generated config accidentally indexes it | blueprint is disabled, authorizing=false, routes/sets/combinations empty |
| 35 | taxonomy is public but not attached to selected CPT | taxonomy discovery implies relation | relation validated through WordPress object-type binding |
| 36 | profile disabled during incident | global shutdown required for one surface | per-profile neutral rollback |
| 37 | global kill switch disabled | hidden profile still mutates metadata | all bridge profiles inactive |
| 38 | future cache stores old profile decision | config/term/WPML changes leave stale SEO | persistent decision cache remains out of Alpha |
| 39 | exact combination registry grows beyond bounded JSON | options storage becomes operational bottleneck | Alpha rejects/limits; external/importable registry requires separate design |
| 40 | a new CPT/Taxonomy is added weekly | code releases required for every taxonomy | explicit Profile/TaxonomyRule config supports growth without PHP change |
| 41 | several profiles reuse same taxonomy with different business rules | global taxonomy rule leaks policy | rules are profile-scoped |
| 42 | one archive is translated to Unicode slug | ASCII sanitizer destroys authority | Unicode-safe path normalization |
| 43 | active WPML language prefix changes | stale language wrapper matches incorrectly | only active language prefixes canonicalize base paths |
| 44 | route references missing/deleted Query Builder object | config looks valid but authority cannot be proven | readiness degrades; Post Type/result authorities fail closed |
| 45 | taxonomy is deleted after profile activation | stale profile keeps indexing URL | runtime taxonomy readiness fails; no index |
| 46 | operator removes allowed set but leaves exact combinations | exact list appears to authorize shape | structural set gate precedes combination gate |
| 47 | count is very high while content/business/translation gate fails | numerical evidence dominates governance | hard gates always precede soft threshold |
| 48 | profile grows to multiple Post Types | one allowed CPT hides foreign data | exact Post Type observation set must be subset of profile allowlist |
| 49 | public taxonomy slug drifts singular/plural | crawler evidence silently rewrites configured authority | report drift only; explicit staging profile change required |
| 50 | provider/query ID differs by archive surface | one known route is generalized across listings | exact route identity remains profile-scoped |
| 51 | vendor supports query-string and JSF-path filter states | parser treats both as equivalent without contract | query-string state remains outside bridge authority in this tranche |
| 52 | public language menu changes | hard-coded languages become stale authority | inventory WPML active languages; authorization remains explicit |
| 53 | inventory exporter leaks raw Query Builder args | discovery exposes dynamic values or implementation details | export structural identity only |
| 54 | inventory export is mistaken for profile config | operator pastes discovery as authority | contract is `authorizing=false`; disabled blueprint remains separate step |

| 55 | inventory JSON is edited manually | stale/tampered evidence accepted | recompute fingerprint and reject mismatch |
| 56 | imported snapshot contains huge collections | reconciliation becomes DoS surface | enforce collector bounds before analysis |
| 57 | native CPT archive differs from listing Page | false blocking drift | native archive path is warning evidence only |
| 58 | Query Builder route appears to match a new CPT | discovery becomes route authorization | keep route under evidence; candidate `routes=[]` |
| 59 | new CPT appears | auto-created profile begins indexing | candidate disabled with all authority-bearing sets empty |
| 60 | taxonomy gets attached to a profiled CPT | profile silently expands | emit warning only; no rule/set mutation |
| 61 | previous snapshot is malformed | system reports “no drift” | surface `previous_inventory_invalid` |
| 62 | query becomes `any` | prior route still looks valid by ID | boundedness downgrade is blocking |
| 63 | blocking drift is discovered | engine auto-disables production surface | no automatic mutation; operator controls rollback |
| 64 | WP-CLI reads arbitrary giant snapshot | filesystem input becomes unbounded parser | readable-file + 2 MiB limit + contract validation |

| 65 | inventory limit silently drops a configured taxonomy | false missing/block | explicit completeness metadata; truncated section cannot prove absence |
| 66 | manager query order changes between requests | fingerprint churn / different sliced queries | deterministic identity sort before slicing |
| 67 | duplicate `custom_query_id` points to different Post Types | first-record-wins authority leak | collision detection + collided IDs excluded from query index |
| 68 | collision exists outside serialized conflict detail bound | operator sees incomplete collision list | `identity_conflict_count` + `identity_conflicts_truncated`; any nonzero count blocks |
| 69 | Query Builder manager unavailable | empty list mistaken for no queries | dedicated unavailable finding; candidates suppressed |
| 70 | current snapshot truncated compared to previous complete snapshot | false removal drift | section comparison skipped as incomplete |
| 71 | attacker recomputes fingerprint after tampering completeness counts | self-consistent forged metadata | structural completeness validation against serialized section counts and fixed limits |
| 72 | archive translation budget exhausted | translated path appears missing | completeness marks translation truncation; mismatch becomes unresolved evidence |
