# MAD4B AI Provider Routing — WordPress 7.1+

## Single connector source
Use WordPress Core Connectors API (wp_connectors_init / wp_get_connector) and Settings > Connectors for credentials. Core checks environment, PHP constant, then database. MAD4B stores route preferences only, never a second copy of a provider key.

WordPress Core mode requires a real active AI provider plugin and a configured connector. The existing Google Dedicated App OAuth for Drive does NOT establish access to Gemini generation. The separate MAD4B Gemini API key settings are required for Dedicated Gemini mode.

Registered providers:
- WordPress AI Client, using a supported Core connector/provider implementation.
- Dedicated Gemini API key configured through MAD4B constants.
- OpenRouter via Core connector mad4b_openrouter; default model openrouter/free (subject to free rate limits).
- Custom via Core connector mad4b_custom; explicitly configured public HTTPS chat-completions endpoint.

WordPress Settings > MAD4B AI Routing: provider choice, model, guarded custom HTTPS URL, bounded jobs per run, enable inference, source repair opt-in.

## One interface for all modules and Bit Flows
- Model execution: MAD4B_SCP_AI_Model_Router::generate_text.
- AI review cycle: MAD4B_SCP_AI_Review_Worker::run_once and ::preflight.
- Action trigger: mad4b/ai/review_cycle_requested; completion metadata hook: mad4b/ai/review_cycle_completed.
- Protected REST: GET|POST /wp-json/mad4b/ai/review-cycle (enrolled admin).
- Genuine delegated Agent decisions: original authenticated Local Lab context-review REST endpoint.
- Model verdict is not a permission grant. Never bypass SHA/registry CAS, source evidence checks or protected publishing and deployment approvals.

## Local scheduler
Host cron every 10 minutes uses flock and a Local-Lab-only CLI entrypoint. DISABLE_WP_CRON is true. The host operator path may reconcile an expired delegated service identity under the original site owner's authorization. Runs are idempotent and closed when provider credentials are absent. No secrets, prompts or original documents are logged.

Bit Flows is an optional orchestration caller of the same REST/action interface. Keep only one scheduler for a review queue; do not install a parallel approval system in Bit Flows.

## Source repair and safety
Text/Markdown source repair is separately enabled and restricted to bounded, registered local WordPress Media attachments. It requires an exact needs_changes review receipt, checked original bytes, original document identity and legitimate pre-existing URLs. Original complete source scan is authoritative. Other formats and protected operations require specialized independently authorized execution.

## Current deployment scope
ETG Local Lab only. No staging/production changes. Actual inference cannot be exercised until a real provider credential is configured and AI routing explicitly enabled.

## Test gates
ai-model-routing-worker-runtime.php: 20 mocked/deterministic cases, including unsafe URLs and fabricated model outputs. Existing Context Authority, AI-only scope, Excel, PDF/text normalization, and site-neutrality must pass. CI should independently verify after PR creation.
