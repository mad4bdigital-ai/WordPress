# Certified Universal Form Bridge

Inputs: exact SiteScope, actor/permission, provider fingerprint, target revision, locale, field query and bounded rendering options. Output: immutable FormDescriptor with schema fingerprint, capability, field types, required/conditional expressions, sensitivity, expiry and policy flags. A form is NOT approval.

Field types: Unicode/RTL text; bounded numbers/currency; boolean; time/date; URL with host allowlist; enum/select/multiselect; scoped CPT/term/user relation; native media reference with rights; repeaters/groups with bounded depth/cardinality; safe rich text. API key is a secure handoff, never a normal form text field.

Conditional logic: declarative and side-effect-free; server re-evaluates hidden, required and mutually exclusive fields. No arbitrary JS or arbitrary PHP. Smart autocomplete must be tenant/site/role/CPT/taxonomy/locale scoped, paginated, and reveal neither value nor existence of unauthorized records.

For every editable field identify native owning plugin+version, typed read/write Ability, sanitizer, hooks, associated side effects, current value read permission, expected revision and approved rollback. Serialized option arrays and custom tables are blocked without certified whole-object adapter and hooks. Re-validate descriptor, grant and target revision immediately before commit.

Preview shows redacted before/after and dependency effects; status remains PROPOSED until governed submit and independently verified receipt.
