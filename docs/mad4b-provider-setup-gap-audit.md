# MAD4B Provider Setup Coverage — Local Lab audit, October 25, 2026

The existing Provider Connections page displayed only Google Drive's OAuth connection. A new read-only coverage projection exposes setup requirements from their original owning adapter, never a second credential registry.

## 17 current provider setup entries

- Browser runners: Cloudflare (CLOUDFLARE_ACCOUNT_ID, CLOUDFLARE_BROWSER_RUN_API_TOKEN), Browserbase (BROWSERBASE_API_KEY), Browserless (BROWSERLESS_TOKEN), Steel (STEEL_API_KEY). Secrets belong to the independent external browser runner; no WordPress form can prove their presence or signed browser execution. Native Hybrid and two installed acceptance adapters do not require these keys but still need separate certification.
- AI: native Core Google, OpenAI, Anthropic; MAD4B Gemini, OpenRouter and Custom, all using WordPress Core Connector credential storage. The built-in Core provider plugins are not active in this Local Lab. Model inference is not live-tested.
- Search: DataForSEO (login, password) and SerpAPI (api_key), already with encrypted, revision-bound Save/Test forms in Search Intelligence. Both are unconfigured locally. A successful account test is not a paid capture grant.
- Google Drive: original Google OAuth owner consent/refresh; must not be conflated with Gemini API inference.
- Generic Context extractor: MAD4B_CONTEXT_EXTRACTOR_URL and MAD4B_CONTEXT_EXTRACTOR_TOKEN in approved host PHP configuration; currently incomplete.
- G6 Vault: MAD4B_G6_VAULT_KEY_BASE64 and MAD4B_G6_VAULT_KEY_ID in host environment; currently incomplete.
- G5 Growth: no registered adapter locally; newly registered certified adapters will appear dynamically, with their original verified setup field descriptors.

## Corrections
1. Provider Connections now shows field names, real secret custodian, setup links, credential state, connection state and distinct execution/certification state, including Browser and AI.
2. Browser Acceptance lists all four external secret schemas immediately instead of only after choosing a runner. The UI warns that external secrets cannot be saved or verified by WordPress and that selecting a runner is not signed certification.
3. Provider inventory returns no API keys, credential values or handles, never calls paid provider APIs, does not grant authority and denies non-admin users.
4. G5 field expansion is data-driven from its original certified setup preview, not manually duplicated.
5. Live Local Lab readiness is not equated with API key presence or an OAuth status.

## Validation
Provider setup runtime 23/23 PASS, existing Browser Admin/Core/native adapter, Provider Connection Center, AI Routing, Authority Setup and Excel suites PASS. Plugin neutrality PASS (674 runtime files). Local WordPress and REST HTTP 200, 17 setup entries and 15 links visible, Brand Authority ready, Registry 77.

## Deferred operational dependencies
External runner provisioning and signed Browser Acceptance require an independently authorized runner. No external credentials, payment quotas, Breakglass, Developer grants, Staging, Production or remote account were modified.
