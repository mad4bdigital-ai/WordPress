# MAD4B Unified Approval Core 0.2.1 — AI-first correction

## Required stage order

- `ai_only`: `PENDING_AI` → verified AI owner receipt → `REVIEW_APPROVED` (if approved).
- `human_and_ai`: `PENDING_AI` → verified AI owner receipt → `WAITING_HUMAN` → human decision → `REVIEW_APPROVED` (if approved).
- `human_only`: `WAITING_HUMAN` → human decision → `REVIEW_APPROVED` (if approved).
- `needs_changes` and `reject` are not approval.

Human decisions are blocked until the verified AI receipt exists in every hybrid flow, including configured `all_required` and `risk_adaptive`. They cannot jump directly from `PENDING_AI` to `REVIEW_APPROVED`.

## Legacy compatibility

Existing stored `human_then_ai` and `either_authorized` policy definitions are *read-only normalized* to `ai_then_human`; new writes using those retired rules are rejected. WordPress options are not mutated by a read. Existing open reviews with an incompatible policy fingerprint are rejected as stale and must be opened again against the current original source.

**Safety boundary:** Policy finality is for review only. No mutation, publication, grant, pricing, inventory or deployment authority is issued.

## Verification

- PHP lint: 7/7 Core classes passed.
- Isolated policy contract: 37/37 passed.
- Mock HTTP lifecycle: 32/32 passed.
- Core smoke/security checks: 12/12 passed.
- Isolated installer test: dry-run/apply/duplicate protection passed; Staging rejected.

No real ETG Local WordPress deployment or GitHub push was performed for this version.
