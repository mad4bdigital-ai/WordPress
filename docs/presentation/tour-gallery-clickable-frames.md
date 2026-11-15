# Tour Gallery — Clickable native Elementor background frames

Verified on Wayfatours Production and Tours.dev on 2026-11-15.

The original Elementor single-Tour gallery uses one standalone tall portrait
background container and one nested four-frame CSS Grid. The All Photos
Elementor button opens the company's configured Popup, ID 15284
on the reference tour. This contract does not own Popup creation.

## Source and live authority

Existing MU module:
wp-content/mu-plugins/mad4b-tour-gallery-popup-layout.php

Its small subordinate asset:
wp-content/mu-plugins/mad4b-tour-gallery-popup-layout/frames.js

The module enqueues the subordinate script only for tours-and-activities
single pages and retains the existing responsive Popup layout CSS.

JavaScript discovers the existing native All Photos action anchor and its
enclosing gallery CSS Grid. It binds the standalone background portrait
container beside the Grid wrapper AND the Grid's background-image children.
This is structure-derived, not a static list of widget IDs or a fixed
popup ID. It never binds unrelated page images.

The whole image frame activates the existing Elementor All Photos anchor,
so native Elementor continues owning the Popup and its gallery content.
The last frame preserves the native button/link and avoids nested ARIA
button semantics. Other frames get role=button, tabindex=0,
Enter/Space and keyboard-visible focus styles. Mobile hidden classes
remain authoritative. No Elementor template, attachment, Tour, WPML,
gallery metadata, or media ownership was changed.

## Regression

Read-only browser regression:
python3 scripts/qa/tour-gallery-five-frames-contract.py --host wayfatours.com
python3 scripts/qa/tour-gallery-five-frames-contract.py --host tours.dev.mad4b.com

Check portrait-first + five-frame activation, original Popup appearance,
desktop click, keyboard and mobile tap with no JavaScript exceptions.

Live PHP/JS SHA-256 parity was checked on both sites. The earlier
four-frame implementation excluded the standalone portrait (ef55af8)
because it only inspected children of the inner Grid (74f3fbc).

Wayfatours FastCGI generation keys were rotated only for 84 canonical
published Tour URLs (42 EN, 42 ES) via existing Cache Control. No
global or Redis cache flush. Production tests succeeded on FastCGI HIT.
PHP/JS backups are retained in a restricted VPS backup directory:
 /opt/mad4b-company-transport/backups/gallery-frame-opener-20261115

A source merge is not a signed Original313 release receipt.
