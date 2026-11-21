# Host v139 — Copy exact browser-review confirmation

## Report and root cause

In the legacy v134 Owner step-up form, the exact confirmation is only a
placeholder in an empty input, so it cannot be selected or copied like text.
The page displayed "Exact confirmation does not match" after clicking the
legacy request button. The underlying confirmation is an exact protocol
string for creating an UNSIGNED browser review; it is NOT an Owner
step-up signature, Original305 approval or standing Write permission.

The user already submitted two reviews on Tours.dev Staging, now
expired but preserved, pending independent Original313 predecessor.
The owner should not be required to submit another one now.

## Fix: only the existing v138 presentation layer

The original v134 420 file and exact REST protocol remain untouched.
v139 adds an accessible technical copy panel in the optional
administrator-details disclosure of v138's 430 presentation. It reads
the exact value from the v134 confirmation input placeholder (single
source), renders it in a selectable LTR code element, and provides
"Copy exact confirmation". The clipboard API copies it on direct user
interaction; if unavailable, the exact input is populated and selected
so native Ctrl+C works. Nothing is automatically submitted or signed.

If an existing Owner-specific review record is present, hide and
disable the legacy v134 "Request independent Owner review" submit
button; keep the copy panel visible as reference but add a notice
not to resubmit. The primary owner page keeps the existing review
milestone and the no-duplicate behavior.

All copy labels and feedback are localized to the WordPress user's
language (EN, AR, FR, DE, ES, IT). All API operations, hashes, signer
keys, approval gates, and Production remain unchanged.

## Acceptance

Focused real-PHP and Node fake-DOM tests verify the original v134
contract, selectable text, copy success/fallback, absence of duplicate
submit on a recorded review and preservation of the missing-review
action. Full Host and release regression suites must pass before
selective rollout.

Deploy only the 430 presentation file on Tours.dev Staging with exact
Git SHA, Root-owned private preimage, immutable protection, PHP lint,
site binding, admin hook, existing HTTP/REST checks, original 420/425
hashes, read-only signed Host Runner Current and rollback. Do not
replay Original305 ambiguous-write attempts or issue synthetic signatures.
