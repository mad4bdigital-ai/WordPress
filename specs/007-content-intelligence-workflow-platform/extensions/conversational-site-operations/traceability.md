# CSO01 — Requirement → ability family → tasks → gate → negative acceptance

| Requirement | Domain | Ability family | Tasks | Gate | Required rejection |
|---|---|---|---|---|---|
| CSO-R001 | Site Explorer | `site_discover` | CSO-T001, CSO-T002, CSO-T003 | CSO-G1 | unknown plugin or stale inventory denies write |
| CSO-R002 | Universal Form Bridge | `form_schema` | CSO-T004, CSO-T005, CSO-T006 | CSO-G2 | unknown dynamic field defaults to read-only |
| CSO-R003 | Smart Autocomplete | `form_suggest` | CSO-T007, CSO-T008, CSO-T009 | CSO-G2 | cross-tenant suggestion forbidden |
| CSO-R004 | Dependency-aware Forms | `form_validate` | CSO-T010, CSO-T011, CSO-T012 | CSO-G2 | hidden-field injection rejected |
| CSO-R005 | Visual Diff and Preview | `change_plan` | CSO-T013, CSO-T014, CSO-T015 | CSO-G3 | preview must not mutate or claim publication |
| CSO-R006 | Secure Credentials Center | `secret_session` | CSO-T016, CSO-T017, CSO-T018 | CSO-G2 | secret must never appear in chat logs or receipt |
| CSO-R007 | Bulk Operations | `bulk_plan` | CSO-T019, CSO-T020, CSO-T021 | CSO-G4 | partial success never reported as all success |
| CSO-R008 | Conversation Workflow Builder | `workflow_compile` | CSO-T022, CSO-T023, CSO-T024 | CSO-G4 | prompt injection cannot create capabilities |
| CSO-R009 | History, Reconcile and Rollback | `change_history` | CSO-T025, CSO-T026, CSO-T027 | CSO-G4 | unknown commit state prohibits blind retry |
| CSO-R010 | Content and Media Studio | `content_plan` | CSO-T028, CSO-T029, CSO-T030 | CSO-G5 | media rights and publish permission checked |
| CSO-R011 | Multi-site Command Center | `multisite_plan` | CSO-T031, CSO-T032, CSO-T033 | CSO-G6 | one-site grant does not grant another site |
| CSO-R012 | Approval and Delegation Center | `approval_plan` | CSO-T034, CSO-T035, CSO-T036 | CSO-G3 | self-approval forbidden for segregated roles |
| CSO-R013 | Monitoring and Smart Alerts | `monitor_plan` | CSO-T037, CSO-T038, CSO-T039 | CSO-G7 | stale monitoring evidence cannot claim online |
| CSO-R014 | Staging to Production Promotion | `promotion_plan` | CSO-T040, CSO-T041, CSO-T042 | CSO-G8 | staging approval never authorizes Production |
| CSO-R015 | Reusable Templates | `template_plan` | CSO-T043, CSO-T044, CSO-T045 | CSO-G2 | template cannot carry authority or secrets |
| CSO-R016 | Contextual Help and Troubleshooting | `form_explain` | CSO-T046, CSO-T047, CSO-T048 | CSO-G9 | unknown behavior clearly labelled unknown |
| CSO-R017 | Self Diagnostics and Guided Recovery | `doctor_plan` | CSO-T049, CSO-T050, CSO-T051 | CSO-G7 | disabled Host/Developer lane cannot silently execute |
| CSO-R018 | Conversation Intent and Session Continuity | `form_draft` | CSO-T052, CSO-T053, CSO-T054 | CSO-G2 | old-session replay rejected |
| CSO-R019 | Dynamic Storage Adapter Registry | `adapter_certify_plan` | CSO-T055, CSO-T056, CSO-T057 | CSO-G1 | unrecognized table fails closed |
| CSO-R020 | Localization, RTL and Accessibility | `form_accessibility_report` | CSO-T058, CSO-T059, CSO-T060 | CSO-G9 | missing labels and focus traps block |
| CSO-R021 | Workflow Quality and Usage Telemetry | `ux_metrics_plan` | CSO-T061, CSO-T062, CSO-T063 | CSO-G9 | no secret/value leakage in analytics |
| CSO-R022 | Policy and Data Governance | `policy_explain` | CSO-T064, CSO-T065, CSO-T066 | CSO-G1 | cross-origin or stale source denies |
| CSO-R023 | Durable Cross-plugin Orchestration | `workflow_plan` | CSO-T067, CSO-T068, CSO-T069 | CSO-G4 | no impossible global database atomicity promise |
| CSO-R024 | Drift and Lifecycle Management | `drift_plan` | CSO-T070, CSO-T071, CSO-T072 | CSO-G7 | changed plugin schema invalidates stale form |
| CSO-R025 | Credential Lifecycle and Rotation | `secret_rotate_plan` | CSO-T073, CSO-T074, CSO-T075 | CSO-G2 | rotation never echoes old or new value |
| CSO-R026 | Event Trigger and Webhook Automation | `trigger_plan` | CSO-T076, CSO-T077, CSO-T078 | CSO-G4 | spoofed webhook never mutates |
| CSO-R027 | Commerce and Custom Object Integration | `object_contract` | CSO-T079, CSO-T080, CSO-T081 | CSO-G5 | payment/order side effects need distinct policy |

Cross-cutting denials: secrets in chat or MCP request, guessed table/option permission, forged Staging/Production promotion, stale schema/revision/grant, ambiguous partial commit, cross-tenant disclosure, replayed approval/webhook, provider-disabled and untrusted client. Every section has `OPEN` tasks and gates, no claims of ready runtime.

## Reuse & no conflicting authority

- Feature 007: `governed-tool-execution`, `schema-contract-evolution`, `dynamic-provider-certification`, `multi-authority-registry`, `evidence-attestation-trust`, `execution-commit-guard`, `data-flow-policy`.
- CE01: competitive experience and conversational growth families, but no migration of CE01 task completion counts.
- ACI01: content and media relations, QA and context truth; reuses the source, never issues publishing authority.
- Existing native WordPress providers: Site Profile, Unified Capability Gateway, Semantic Content Field Contracts, governed option/content Abilities, Mutation Manager and Provider Resolver.
