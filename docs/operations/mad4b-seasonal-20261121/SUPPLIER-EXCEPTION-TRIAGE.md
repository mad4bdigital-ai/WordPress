# Native supplier quotation exception triage (21 Nov 2026)

This extends the existing admin-only MAD4B_Supplier_Sheet_Reference_V1
ledger and Package & Rates UI. No duplicate season or parallel Rate Provider.

Original supplier note cells E/F/J/K/O remain grouped by Property and season
with exact source row and column. The read-only view now marks possible
date/season mentions, mandatory and optional items, meal supplements,
children policy and availability restrictions. When a note lacks a four-digit
year, other source cells with explicit years from the SAME property and
period appear as context, without inferring which date applies.

Every note has commercial_rule_status=original_source_requires_review
and cost_authority=false. No checkout, published Rate, vendor NET cost,
inventory, or supplier contract is authorized or written by this view.

Examples checked live from the connected sheet:
- Pyramids Park Resort Cairo: O8 contains Peak periods, O9 is Christmas
  dinner optional, O10 is NYE dinner mandatory.
- Barcelo Pyramids: O56 and O57 contain two different Peak windows,
  O58 records the mandatory gala; the original cells remain together.
- Coastal providers can have multiple rows of children notes and summer/
  winter date exceptions. No duration/price is guessed.

Tours.dev: 188 original note cells, 31 textually flagged for specialized
review. Wayfatours: 213 note cells, 38 textually flagged. Unflagged
notes are NOT implicitly approved; counts are heuristic indicators only.

WPML now resolves native Season Term Meta from a verified default-language
policy term. Peak remains vendor-specific and no global Peak term is invented.
Existing approval gates remain in effect.

No raw supplier quotes, prices or user-supplied note bodies belong in Git.

