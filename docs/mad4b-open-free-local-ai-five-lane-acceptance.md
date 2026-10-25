# MAD4B ETG Local Lab — free open-model review acceptance (25 October 2026)

## Verified real inference
Ollama runs as an isolated sidecar sharing only the ETG Local Lab PHP network namespace, bound to **127.0.0.1:11434**, with no published Docker port. The model **qwen2.5:1.5b** is openly available under Apache 2.0 and the locally downloaded manifest reports 986 MB. The Local Lab route is named local_ollama and is fixed to the one model and one API path; no password, cloud API key or billed provider is used.

The original ETG MU sandbox continues blocking all outbound WordPress requests. An explicit one-path, POST-only exception is installed locally for http://127.0.0.1:11434/api/chat, and WordPress WP_ACCESSIBLE_HOSTS includes only loopback under the already existing block policy. The route is rejected outside wp_get_environment_type() === local, and request data cannot select another host, model, route or privilege. This local MU guard and wp-config adjustment were backed up **only on Local Lab**, not propagated to Staging or Production.

The original WordPress router now offers local_ollama as a local-only choice and uses Ollama's official bounded JSON Schema output only when an authorized worker requests response_format=review_verdict_v1. The same exact schema is reused by Brand Context and the shared original-owner AI-domain review dispatcher. Existing WP Core, Gemini, OpenRouter, and Custom HTTPS routes remain independent. A generic source text request does not force JSON.

## Public test input
Fetched once from **https://countries.dev/alpha/EG** without signing up or using an API key. Source file SHA-256: **6fe50fb1e8822368f2cb52a8f84bf261e8b19021b6bea27ca27df5798e0744b9**. Evidence: Egypt / EG / EGY / capital Cairo / language ar (Arabic) / currency EGP. The fixture is stored privately outside WordPress's document root and carries the canonical source URL and SHA-256.

The five source-specific smoke lanes check that their corresponding existing MAD4B owner class and owner method actually exist before invoking real inference:
- content_job — MAD4B_SCP_Content_Jobs::get_job (public-data editorial example)
- product_catalog — MAD4B_SCP_Catalog_Object_Store::status (country/currency catalog example, no prices or products)
- seo_metadata — MAD4B_SCP_G5_SEO_Provider_Families::catalog (SEO draft only, no indexing claim)
- localization — MAD4B_SCP_Translation_Bridge_Adapter::register_with_registry (Arabic language sample, no WPML write)
- design_template — MAD4B_SCP_Browser_Acceptance_Core::plan (design evidence example, no Elementor render)

Real Ollama inference with the fixed JSON Schema produced **5/5 valid review verdict objects on the first attempt**. Four were needs_changes and the localization test sample returned approve. That approve is NOT an original WPML/native review approval. Five-dimensional report: /opt/hostinger-migrations/hosting-3/etg-woocommerce-lab/reviews/ai-five-free-model-report-20261025.json.

## What remains unproved
The test is **not a set of five certified original-owner executors**. It only proves their current owner-class inventory, free public evidence sourcing, model inferencing, schema validation and lab-only safety. All native_owner_review_submitted and original_owner_receipt_verified flags are FALSE, no content was mutated, no grants issued and no publication. Dedicated native owner read/review/receipt APIs are still necessary for automated approvals in Content Jobs, Product Catalog, SEO, Localization and Design Templates; protected governance lanes do not auto-approve. Never replace those gates with a model's proposed verdict.

This is a Local Lab operational acceptance only. The model was not configured, deployed or exposed on Staging or Production. Security constraints should be rereviewed before enabling any real owner writes, and a small model's approval must be corroborated by trusted source evidence.
