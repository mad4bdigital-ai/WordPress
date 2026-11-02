# Brand-neutral WPML translation execution — Staging validation, 2026-11-02

## Policy and authority

Translation of ordinary UI strings does not require a company-specific Brand Core, Tone of Voice, or Brand Context document. Use the source text, target locale, official WPML object relationships, and natural, clear translations. This does **not** remove tenant/site identity, operator authorization, WordPress capabilities, or native ContentJob brand/scope authorization. Never invent an owner signature, source reference, or external-provider receipt.

The first batch ran on the authorized `tours.dev.mad4b.com` Staging site using the enrolled operator, without reading or requiring a brand identity file. The executable source and prewrite backups remain in the host's isolated lab; the WordPress audit options retain exact translation receipts and Elementor source/target digests. No Production deployment.

## Verified changes

| Source template | Locale | Target | Change | Native ContentJob |
|---|---|---|---|---|
| 25917 Home Tours Section | fr | 35108 | Apply filter → Appliquer le filtre | f4332a45-f23e-48c6-b244-7f9d88048f65 |
| 25917 Home Tours Section | de | 35112 | Apply filter → Filter anwenden | 7b274d19-7b78-4671-b4d3-f482c14865eb |
| 25917 Home Tours Section | es | 35110 | Apply filter → Aplicar filtro | eb6c8481-5cee-4d0c-a8a9-3c535580d637 |
| 25917 Home Tours Section | it | 39504 | Apply filter → Applica filtro | c06680b5-3870-43de-9cd9-6a7100114b73 |
| 25917 Home Tours Section | ar | 40545 | Apply filter → تطبيق الفلتر | 24442178-0670-460c-bbab-fe0bf05446a8 |
| 43704 Home Blog Section | fr | 43777 | Read More → Lire la suite; All → Tout in both JetBlog widgets | cd9135f0-4a4c-4f19-bbba-875744dd7696 |
| 43704 Home Blog Section | ar | 44251 | Read More → اقرأ المزيد; All → الكل in both JetBlog widgets | 58c1c71c-66b0-4da1-be49-2f4921162ee2 |
| 41738 Home Transportations Search Box | ar | 44268 | Apply filter → تطبيق الفلتر | f4d8561a-d7f7-47da-b862-1674da98201c |
| 41731 Home Properties Search Box | ar | 44266 | Apply filter → تطبيق الفلتر | 46bfbae1-6dc5-41ff-a3dd-4ee0f198d391 |

Each change was constrained to exact Elementor widget ID and field; the old complete target `_elementor_data` is stored in a per-target WordPress option keyed by its previous SHA-256. The existing outbox was claimed by the authorized actor before mutation. New content and post status were **not** published or created.

WPML locale, translated field and resulting Elementor data hashes were read back. A durable scoped audit record was written with `mad4b.wpml.neutral.*` receipt IDs; native `MAD4B_Elementor_Localization_V1::ack` returned `verified` after evaluating actual remaining gaps. The corresponding native ContentJobs transitioned to `WAITING_REVIEW`, **not** `COMPLETED`, because editorial approval remains a separate gate.

The first Blog French Ack failed because a local script reused the outbox-key variable name as a foreach field key. The write and backup were intact; the original claimed outbox task and durable receipt were re-read and resumed without creating a duplicate job. Exact field/hash readback and the native Ack succeeded. The bug was corrected in the remaining execution scripts.

## Latest independent acceptance

- Forced fresh `MAD4B_Elementor_Localization_V1::queue_scan()`: success.
- Nine mapped native ContentJobs: `WAITING_REVIEW`, nine corresponding outbox entries: `verified`, `readback_passed=true`, receipt present.
- Full native map at readback: 121 `QUEUED`, 9 `WAITING_REVIEW`, 2 `CANCELLED`; counts may change as the timer ingests further requests.
- `mad4b-wpml-content-intake-staging.timer` active; five localized homepage HTTP checks returned 200.
- Remaining top requests are mainly WPML missing target or Elementor layout mismatch. Do not equate them to lexical translation and do not auto-create published pages or bypass review.
- No brand-context read was performed for these neutral UI translations. Tenant and scope checks remain mandatory.

## Next governed stage

A general-purpose executor should discover source/locale dynamically, consult the known UI-label lexicon and existing WPML translations first, create a source- and target-hash-bound backup/receipt, and only submit a verified Ack if **all** qualifying lexical gaps are actually cleared. Editorial copy beyond the lexicon needs a real translation provider or authoring work, still without requiring a specific company's Brand Context. Layout-only and text-free widgets must follow the technical synchronization path instead of receiving fabricated translations.
