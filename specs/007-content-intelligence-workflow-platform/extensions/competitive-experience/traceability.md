# Traceability — CE01

40 competitor requirements plus 13 Adaptive Capability Fabric v2 requirements; each task has exactly one owner.

| Family | Workstream | Task IDs | Acceptance |
| --- | --- | --- | --- |
| CPBEN | Evidence and reproducible comparison | T3901–T3905 | Exact behavior, UI, denial and evidence contracts |
| CPUX | Complete setup and admin journeys | T3906–T3910 | Exact behavior, UI, denial and evidence contracts |
| CPUNDO | User-facing undo and verified readback | T3911–T3915 | Exact behavior, UI, denial and evidence contracts |
| CPNHI | NHI and per-agent permissions | T3916–T3920 | Exact behavior, UI, denial and evidence contracts |
| CPOAUTH | Granular OAuth and client compatibility | T3921–T3925 | Exact behavior, UI, denial and evidence contracts |
| CPGROW | Analytics and competitive search intelligence | T3926–T3930 | Exact behavior, UI, denial and evidence contracts |
| CPFORMS | Forms and private submission operations | T3931–T3935 | Exact behavior, UI, denial and evidence contracts |
| CPWC | Commerce capability breadth | T3936–T3940 | Exact behavior, UI, denial and evidence contracts |
| CPBUILD | Builders and site-editor parity | T3941–T3945 | Exact behavior, UI, denial and evidence contracts |
| CPSEO | SEO provider family and rendered provenance | T3946–T3950 | Exact behavior, UI, denial and evidence contracts |
| CPHIST | Visual change history and evidence export | T3951–T3955 | Exact behavior, UI, denial and evidence contracts |
| CPAIBASE | Optional AI workspace and governed model use | T3956–T3960 | Exact behavior, UI, denial and evidence contracts |
| CPRAG | Grounded knowledge and AI forms | T3961–T3965 | Exact behavior, UI, denial and evidence contracts |
| CPOPS | Backup, cache, migration, security and redirects | T3966–T3970 | Exact behavior, UI, denial and evidence contracts |
| CPCORE | Core, multilingual, custom fields and community | T3971–T3975 | Exact behavior, UI, denial and evidence contracts |
| CPCONV | Capability-local convergence and external acceptance | T3976–T3980 | Exact behavior, UI, denial and evidence contracts |
| ACFGRAPH | Runtime Candidate Graph v2 | T4001–T4005 | Exact behavior, UI, denial and evidence contracts |
| ACFCLASS | Semantic Classification Engine | T4006–T4010 | Exact behavior, UI, denial and evidence contracts |
| ACFMAN | Declarative Adapter Manifest | T4011–T4015 | Exact behavior, UI, denial and evidence contracts |
| ACFSYNTH | Dynamic Operation Synthesis and Workflow Compiler | T4016–T4020 | Exact behavior, UI, denial and evidence contracts |
| ACFSHADOW | Shadow Certification for Reads | T4021–T4025 | Exact behavior, UI, denial and evidence contracts |
| ACFCANARY | Reversible Canary Engine | T4026–T4030 | Exact behavior, UI, denial and evidence contracts |
| ACFPACK | Versioned Runtime Registry and Certification Packs | T4031–T4035 | Exact behavior, UI, denial and evidence contracts |
| ACFOWN | Ownership-aware Three-Way Reconciliation | T4036–T4040 | Exact behavior, UI, denial and evidence contracts |
| ACFJOURNAL | Universal Mutation Journal | T4041–T4045 | Exact behavior, UI, denial and evidence contracts |
| ACFUPDATE | Update Acceptance and Safe Remediation Pipeline | T4046–T4050 | Exact behavior, UI, denial and evidence contracts |
| ACFHOST | Host Capability Registry | T4051–T4055 | Exact behavior, UI, denial and evidence contracts |
| ACFEXT | External Provider Framework | T4056–T4060 | Exact behavior, UI, denial and evidence contracts |
| ACFACT | Operator Action Center and Autonomy Evaluation | T4061–T4065 | Exact behavior, UI, denial and evidence contracts |

| Capability | Requirement | Family | Evidence | Tasks |
| --- | --- | --- | --- | --- |
| CE001 | Immutable competitor package archive | CPBEN | SRC-RM-IDENTITY | T3901–T3905 |
| CE002 | Evidence-based comparison and progress | CPBEN | SRC-EASY-COUNTS, SRC-MO-SECURITY-CLAIMS | T3901–T3905 |
| CE003 | Guided first useful operation | CPUX | SRC-EASY-ONBOARDING, SRC-MO-NHI | T3906–T3910 |
| CE004 | Provider credential and profile discoverability | CPUX | SRC-EASY-ONBOARDING, SRC-EASY-GA4, SRC-EASY-DFS | T3906–T3910 |
| CE005 | Every admin page, legacy route and external action | CPUX | SRC-EASY-ONBOARDING, SRC-RM-HEALTH | T3906–T3910 |
| CE006 | Receipt-backed Undo workspace | CPUNDO | SRC-RM-UNDO-TTL, SRC-RM-UNDO-DRIFT | T3911–T3915 |
| CE007 | Exact write readback and recovery uncertainty | CPUNDO | SRC-RM-READBACK, SRC-RM-UNDO-DRIFT | T3911–T3915 |
| CE008 | NHI/agent permission editor and My Access | CPNHI | SRC-MO-NHI, SRC-MO-WRITE-GUARD | T3916–T3920 |
| CE009 | Agent attribution, scope conflict and lifecycle | CPNHI | SRC-MO-NHI, SRC-EASY-SCOPED-CALL | T3916–T3920 |
| CE010 | Granular consent and safe presets | CPOAUTH | SRC-EASY-CONSENT, SRC-EASY-SCOPED-CALL | T3921–T3925 |
| CE011 | OAuth and MCP client/session compatibility | CPOAUTH | SRC-RM-PKCE, SRC-EASY-SCOPED-CALL | T3921–T3925 |
| CE012 | GA4 measurement and property selection | CPGROW | SRC-EASY-GA4 | T3926–T3930 |
| CE013 | Search Console queries and index observations | CPGROW | SRC-EASY-GSC | T3926–T3930 |
| CE014 | Keyword, backlink, competitor and AI visibility adapters | CPGROW | SRC-EASY-SEMRUSH, SRC-EASY-SERANKING, SRC-EASY-AHREFS, SRC-EASY-DFS | T3926–T3930 |
| CE015 | Form/schema/configuration provider family | CPFORMS | SRC-MO-FORMS, SRC-RM-WPFORMS, SRC-RM-CF7 | T3931–T3935 |
| CE016 | Private submissions, retention and export/delete | CPFORMS | SRC-RM-WPFORMS, SRC-RM-CF7 | T3931–T3935 |
| CE017 | Products, variations, attributes and bounded batch | CPWC | SRC-EASY-WC-PRODUCT | T3936–T3940 |
| CE018 | Orders, customers, coupons and reports | CPWC | SRC-EASY-WC-ORDER | T3936–T3940 |
| CE019 | Shipping, taxes, gateways, webhooks and refunds | CPWC | SRC-EASY-WC-WEBHOOK, SRC-EASY-WC-ORDER | T3936–T3940 |
| CE020 | Elementor structural operations and templates | CPBUILD | SRC-RM-ELEMENTOR, SRC-RM-EDITOR-LOCK | T3941–T3945 |
| CE021 | Divi 4/5 format-aware operations | CPBUILD | SRC-RM-DIVI, SRC-RM-EDITOR-LOCK | T3941–T3945 |
| CE022 | Kadence and Gutenberg/FSE templates/styles | CPBUILD | SRC-MO-KADENCE, SRC-EASY-FSE | T3941–T3945 |
| CE023 | Six-provider field-level SEO family | CPSEO | SRC-EASY-SEO, SRC-EASY-SEO-HEAD | T3946–T3950 |
| CE024 | Rendered head, language and archive verification | CPSEO | SRC-EASY-SEO-HEAD, SRC-RM-REDIRECT | T3946–T3950 |
| CE025 | Visual timeline and structured diffs | CPHIST | SRC-EASY-HISTORY | T3951–T3955 |
| CE026 | Redaction, retention and portable evidence | CPHIST | SRC-EASY-REDACTION, SRC-EASY-HISTORY | T3951–T3955 |
| CE027 | Optional AI Workspace and approval cards | CPAIBASE | SRC-AI-WORKSPACE | T3956–T3960 |
| CE028 | Governed model routing and usage controls | CPAIBASE | SRC-AI-MODELS | T3956–T3960 |
| CE029 | Copilot, translation, media and audio/video proposals | CPAIBASE | SRC-AI-MEDIA-CLAIM, SRC-AI-AUDIO-PRO | T3956–T3960 |
| CE030 | Knowledge/PDF ingestion, retrieval and vector stores | CPRAG | SRC-AI-RAG-PRO | T3961–T3965 |
| CE031 | AI forms and bounded function calling | CPRAG | SRC-AI-FORMS-PRO, SRC-AI-WORKSPACE | T3961–T3965 |
| CE032 | Backup and migration artifacts | CPOPS | SRC-RM-BACKUP, SRC-RM-MIGRATION | T3966–T3970 |
| CE033 | Cache inspection and bounded purge | CPOPS | SRC-RM-CACHE | T3966–T3970 |
| CE034 | Security status, lockouts and policy claims | CPOPS | SRC-RM-SECURITY, SRC-MO-SECURITY-CLAIMS | T3966–T3970 |
| CE035 | Redirect and link management | CPOPS | SRC-RM-REDIRECT | T3966–T3970 |
| CE036 | Multilingual taxonomy hierarchy reconciliation | CPCORE | SRC-EASY-TERM | T3971–T3975 |
| CE037 | Core content/media/menus/users and ACF completeness | CPCORE | SRC-EASY-ACF, SRC-RM-READBACK | T3971–T3975 |
| CE038 | Community and event operations | CPCORE | SRC-EASY-COMMUNITY, SRC-EASY-EVENTS | T3971–T3975 |
| CE039 | Capability-local provider recertification | CPCONV | SRC-RM-HEALTH, SRC-MO-WRITE-GUARD | T3976–T3980 |
| CE040 | External handshake, browser and passive performance acceptance | CPCONV | SRC-EASY-ONBOARDING, SRC-RM-HEALTH | T3976–T3980 |
| CE041 | Runtime Candidate Graph v2 | ACFGRAPH | SRC-AO-01 | T4001–T4005 |
| CE042 | Semantic Classification Engine | ACFCLASS | SRC-AO-02 | T4006–T4010 |
| CE043 | Declarative Adapter Manifest | ACFMAN | SRC-AO-03 | T4011–T4015 |
| CE044 | Dynamic Operation Synthesis and Workflow Compiler | ACFSYNTH | SRC-AO-04 | T4016–T4020 |
| CE045 | Shadow Certification for Reads | ACFSHADOW | SRC-AO-05 | T4021–T4025 |
| CE046 | Reversible Canary Engine | ACFCANARY | SRC-AO-06 | T4026–T4030 |
| CE047 | Versioned Runtime Registry and Certification Packs | ACFPACK | SRC-AO-07 | T4031–T4035 |
| CE048 | Ownership-aware Three-Way Reconciliation | ACFOWN | SRC-AO-08 | T4036–T4040 |
| CE049 | Universal Mutation Journal | ACFJOURNAL | SRC-AO-09 | T4041–T4045 |
| CE050 | Update Acceptance and Safe Remediation Pipeline | ACFUPDATE | SRC-AO-10 | T4046–T4050 |
| CE051 | Host Capability Registry | ACFHOST | SRC-AO-11 | T4051–T4055 |
| CE052 | External Provider Framework | ACFEXT | SRC-AO-12 | T4056–T4060 |
| CE053 | Operator Action Center and Autonomy Evaluation | ACFACT | SRC-AO-13 | T4061–T4065 |
