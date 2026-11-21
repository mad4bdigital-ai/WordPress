# Host v140 — Server-render exact Owner review confirmation

## Verified root cause

Live Staging v139 PHP is installed with the exact Git SHA, but a read of
the connected administrator tab reveals no Copy exact confirmation button.
The open tab still contains the older rendered UI. WP Admin sends
private no-store responses and PHP-FPM OPcache validates timestamps
every 2 seconds. The current browser DOM is stale.

The earlier v139 solution depended on JavaScript inserting a copy
panel only after finding the original placeholder input. A browser
which does not load/hydrate that code leaves only the non-selectable
placeholder. User already has two preserved review records, so do
not prompt another request.

## v140 server-rendered UI and independent enhancement

The same 430 WordPress presentation MU outputs a protected, server-rendered
read-only input with the exact phrase from v134 public CONFIRM constant.
The input has a visible label, build marker v140 and selectable value,
so manual selection/Ctrl+C requires no JavaScript or clipboard permission.

A separate lightweight script repositions the server field after
the original v134 input, adds one Copy exact confirmation button with
select/focus fallback, and disables legacy re-submission if a
user-specific review exists. The earlier v139 JavaScript insertion
does not create a duplicate when the v140 server panel exists.

Labels and feedback follow each WordPress user profile locale:
English, Arabic, French, German, Spanish, Italian. The exact
confirmation bytes are never translated.

This is a presentation change only: no new routes, Root signers,
Original305/312/313 signatures, issued approval, Persistent Write,
Production mutation, or v134/v135 source change.

## Tests and rollout

Real PHP tests verify server-rendered HTML for six locales, authorized
and unauthorized sessions, exact v134 phrase, and absence of a duplicate
submit. Node tests execute the standalone enhancement without running
the main v136 JavaScript and verify copy, clipboard-denied fallback
and DOM relocation. Full Host/Release/Content Hub/Host Runner checks
must remain passing.

Staging-only replace the protected 430 file using exact Git hash, Root
private preimage and rollback, WordPress PHP lint and site binding, old
000/410/420/425 hashes, HTTP 200 and guest REST 403, read-only
signed Current and unchanged user review records.

A browser tab with old HTML must navigate/reload to receive the new
server-rendered UI; Root cannot modify its old DOM retroactively.
