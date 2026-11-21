# Host v141 — Do not ask twice for a completed Owner review request

## User observation

Tours.dev Host Authority showed three review surfaces:
the v136/v140 owner progress panel showing Review recorded, the legacy
v134 Owner step-up form asking for exact confirmation, and the v135
Remote Owner Step-up form asking to Create remote review request.
The user already registered reviews, now stored and awaiting independent
Original313 predecessor verification. These are duplicate review
interfaces, not independent user obligations.

## UI-only reconciliation

v141 changes only the existing 430 presentation MU plugin:
- The v140 standalone confirmation panel has a hidden attribute when
  a properly scoped saved review exists. Server-generated author CSS
  hides the obsolete v134 review form and the v140 copy field without
  relying on DOM hydration.
- The pre-existing v135 Remote Owner Step-up card is discovered by its
  original heading in the technical panel. Its duplicate Create review
  and Copy handoff controls are hidden and disabled; the third Verify
  signed proof control remains intact.
- The remote signed-proof intake is only displayed when the existing
  authenticated remote-status endpoint reports an independently
  signed Original313 offer is present; otherwise this technical card
  stays hidden.
- With no user-specific saved review, the single v136 primary request
  action remains available. No change to Root approval requirements,
  automatic signing, capability grants, or existing REST behavior.
- No changes to the original 420/425 source, WordPress review rows,
  original Root verifiers, or Production Host Write.

## Tests and Staging

Six-locale PHP fixture checks server-hidden completed review and no-review
copy availability. Node executes the actual v141 browser UI, ensuring
both redundant controls stay disabled and verification of signed proof
is preserved on positive signed-offer readiness. No duplicate POST.

Full Host/Release/Content Hub/Staging-fence regression tests must pass.
Selective Staging replacement of exact 430 Git SHA uses Root-private
rollback preimage, PHP lint, original 000/410/420/425 hash pins,
WordPress Admin hook, HTTP 200, external REST guest 403, signed
read-only Host Runner Current, and byte-identical review records
before/after. Persistent Write remains blocked until actual first-party
Original305/312/313 signing completes.
