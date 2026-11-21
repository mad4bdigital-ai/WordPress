---
name: mad4b-jetengine-recipe
description: Source-of-truth workflow for designing, modifying, reviewing or migrating MAD4B JetEngine UI across Options Pages, CPT editors and native JetEngine forms. Use before any change to field styling, repeaters, accordion behavior, form layouts, navigation and native Save buttons.
version: 1.0.0
---

# MAD4B JetEngine Recipe — Interface Development Skill

## When to invoke
Use for every MAD4B change touching JetEngine Options Pages, JetEngine CPT meta editors, native JetEngine frontend forms, field groups, repeaters, accordion/collapse behavior, visual input components, responsive field layout, or Save button presentation. The Recipe Registry is the required starting point — do NOT build another isolated CSS field skin for each page or product.

## Source of truth
Read the live Tours.dev Staging source before GitHub snapshots; reconcile later.
Source directory: wp-content/plugins/mad4b-jetengine/modules/functional-modes/

Required files:
- class-recipe-registry.php: MAD4B_JetEngine_Recipe_Registry_V1, one site-scoped Registry option and native admin UI
- assets/jetengine-field-recipe.css: canonical Design Tokens and visual styles
- assets/recipe-registry.css and assets/recipe-registry.js: Cards / Compact / Accordion presentation and lookup
- class-field-modes.php: native Options Page adapter
- class-modern-meta-editor.php: CPT native metabox adapter
- class-frontend-form-recipe.php: legacy JetEngine frontend form adapter
- assets/functional-modes.js: existing Options Workspace navigation, not a replacement native form

## Mandatory inheritance contract
Global → Interface → CPT / Options Page / Form → Field Group → Individual Field.
Only cards, compact, accordion and inherit are permitted. Inherit removes an override and restores the parent. Resolve using native field/group IDs; do not duplicate field definitions, labels or values. Store presentation policy only under mad4b_jetengine_recipe_registry_v1, not native field values.

Use WordPress admin JetEngine → Recipe Registry. Scope targets: options plus page slug, cpt plus post type slug, form plus original JetEngine form ID. Group uses native group/panel ID; field uses its native name. Preserve hierarchy, no hardcoded company-specific CSS selectors.

## UI design contract
Recipe owns appearance (colors, spacing, radius, border, focus, typography, labels, helper text and responsive surfaces) and safe optional structure. Adapters own DOM mapping only; JetEngine owns all field metadata, validation, submission, save/update and original AJAX/pricing.
- Cards: consistent 9px white rounded native field cards without replacing field nodes.
- Compact: tighter spacing without harming labels, focus or touch sizes.
- Accordion: one accessible toggle for supported simple fields, aria-expanded and aria-controls. Inputs stay connected in the original form even when visually hidden.
- Repeaters, uploaders, WYSIWYG and native complex controls: preserve vendor Add/Copy/Delete/Drag/Collapse, avoid competing event handlers. A generic display:grid must never override cx-ui-repeater-min state.
- Only legacy native JetEngine Forms are supported by the frontend adapter. JetFormBuilder, Fluent Forms and WooCommerce checkout are NOT included until separate adapters are authored and tested.
- No horizontal overflow at 320, 768, 1366 widths. Verify Arabic RTL and WPML languages.

## Forbidden shortcuts
Do not patch vendor JetEngine core for presentation.
Do not move original inputs out of native forms or clone writable controls.
Do not add new Save, Publish, payment, Checkout or AJAX authority.
Do not inject a big Live Overview as a third flex sibling in a JetEngine cx-control. Use native info column.
Do not force provider repeater content visible when native cx-ui-repeater-min is active.
Do not treat synthetic fixtures as authenticated real-browser Save evidence.
Do not deploy to Wayfatours Production until source Staging acceptance.

## Implementation acceptance checklist
1. Identify native field ownership and actual DOM from live source.
2. Resolve correct Registry tier and existing Recipe token. Prefer a scoped adapter to a one-off page stylesheet.
3. Capture source snapshots, rollback SHA and read-only baseline.
4. Run PHP lint, JS syntax, Registry precedence and access tests, and Chromium with WordPress-rendered native HTML.
5. Test 320/768/1366, open/closed, keyboard focus, aria attributes, RTL, field values and exactly one native Save. Verify native submission or post Save in an authenticated session when available.
6. Report exact verified and unverified results separately. Never claim a completed real-browser Save test when none was run.
7. Record a SHA-guarded rollback that refuses to overwrite newer changes.
8. Reconcile Tours.dev to company Staging before considering any company Production, preserving its customizations, options and pricing.

## User decision
MAD4B Recipe owns field appearance AND field UX across JetEngine CPTs and native input forms, with dynamic hierarchy and individual overrides. Never split the Recipe back into navigation-only ownership while another independent stylesheet owns the fields.

## Recipe Workspace v2 and all native field types (2026-11-09)
The end-user control plane is JetEngine → Recipe Workspace, not a raw slug/field-ID form. It must present named Settings Pages, CPTs, legacy JetEngine Forms and published JetFormBuilder forms, show a Cards/Compact/Accordion/Inherit visual preview, and allow Global/Interface/Target/Group/Field inheritance without making users memorize slugs.

JetFormBuilder is supported as the distinct Registry interface jetform. Original JetFormBuilder post IDs are Target identifiers, with Gutenberg block name/label attributes for field discovery. Never restyle unrelated Fluent Forms or WooCommerce checkout.
The frontend Recipe detects form.jet-form and form.jet-form-builder; preserves original submit/actions/nonces and triggers Registry application when forms mount.

Installed JetEngine Options field types confirmed in this source:
- text, textarea, number, select
- checkbox and switcher
- colorpicker
- media and gallery
- repeater
- HTML/static content
Different field types use different original DOM. Recipe controls consistent spacing, titles, cards, helper text, colors and focus while NEVER cloning, moving or replacing native inputs. Media, gallery, WYSIWYG, range, checkbox/radio and repeater controls retain vendor interactions; no forced child display overriding native collapsed states.

For CPT editors, preserve WordPress Update/Publish. For Options Pages, preserve original JetEngine Save changes. For both frontend form engines, preserve actions, uploads, validation and hidden security fields. Never submit a published booking/registration/order form merely to validate cosmetics; use an isolated safe QA form with no business actions for end-to-end submission.

Before acceptance, test actual WP-rendered HTML and original vendor styling at 320/768/1366, Arabic RTL, values, accordion interactions and one native Save. Test Registry POST save/readback/delete and restore previous Option state. Isolated admin handler round-trip does NOT prove browser-authenticated Save. Require guarded rollback and no company Production changes before canary.

## Mandatory Field Type Compatibility Matrix (2026-11-10)
Before claiming all JetEngine types covered, use the live read-only
MAD4B_JetEngine_Recipe_Field_Matrix_V1::inventory() API and the
Recipe Workspace > Field Type Compatibility panel.

Inventory sources:
- Actual JetEngine Options Page definitions (not guesses from CSS).
- Native JetEngine Meta Box settings stored in jet_engine_meta_boxes,
  including nested fields (CPT, user, taxonomy edit contexts).
- Registered JetFormBuilder Gutenberg blocks and actual published/draft
  form contents, including nested blocks.
Do not equate a registered block name with a currently used widget.
CPT fields declared dynamically outside these sources may require another
adapter inventory.

Matrix states must distinguish:
- adapter_present: identified CSS/Recipe presentation support ONLY.
- vendor_structural: non-input blocks such as Submit/Form Break/Repeater Row;
  preserve original vendor semantics.
- review_required: a specialist widget adapter is not verified.
- isolated_partial: selected mock/SSR interactions, NOT native Save acceptance.
- not_verified: no authenticated save or field-specific RTL evidence.
- ready: only after actual individual field submission/readback and RTL QA.

Current specialist review targets: advanced-date-field, phone-field,
map-field, captcha-container. Never mock Captcha security, bypass form
validation or submit real bookings/orders to validate appearance.
A newly registered type should appear automatically in the read-only matrix,
and unknown types should be flagged; never mark them green by default.

Acceptance requires no duplicate Save/submit authority, 320/768/1366,
native repeaters/media/conditional behavior, keyboard and RTL, and real
native readback in a non-business QA fixture. Always record unresolved
field types and distinguish tested vs untested, maintain guarded rollback.

## Specialist provider guards and QA boundaries (2026-11-10)
The 4 live registered specialist widgets advanced-date-field, phone-field,
map-field and captcha-container have safe presentation guards and MUST NOT
receive a generic Recipe accordion toggle. Do not hide captcha tokens, move
map coordinate hidden inputs or rebuild phone international paired inputs.
A specialist_guarded matrix status is not native functionality acceptance:
test original calendar provider, phone-country selection, map provider and
captcha security in an isolated QA form before claiming end-to-end support.

## Original native Checkbox markup and 10/10 Options input RTL QA (2026-11-10)
JetEngine CX checkbox fields in the live Options Page do not use
input[type=checkbox]. They can use hidden .cx-checkbox-input values plus
.cx-checkbox-item-wrap/.cx-checkbox-item and vendor actions.
Apply Recipe styling to outer choice surfaces, not checked state or inputs.
Preserve the Select All/Deselect All original handlers and native values.

Real WordPress-rendered Options controls with scrubbed values were tested in
Arabic RTL at 320/768/1366 for Checkbox, Color Picker, Media and Gallery,
60/60 DOM/layout checks PASS. Combined with prior 84/84 native RTL checks,
10/10 actual writable Options field families have isolated RTL layout
evidence. JetEngine HTML is a static/structural field type, NOT a writable
input with a Save roundtrip. It must be classified accordingly in Matrix.

Do not turn 'RTL layout partial' into native edit/save acceptance; calendar,
media upload, gallery actions and CX Checkbox selection must be tested in
an authorized real browser session. Do not issue admin auth cookies from
shell to bypass the safety gate.

## Native Options storage evidence, not browser acceptance (2026-11-10)
Actual Jet_Engine_Options_Page_Factory::update_options() and get()
tested 10 writable field types using a unique isolated temporary option,
with built-in sanitization. The QA asserts saved native values for text,
number, select, checkbox, textarea, switcher, colorpicker, nested repeater,
empty media and empty gallery, 45/45 successful assertions, option deleted.
This qualifies only as isolated_native_storage in the Compatibility Matrix,
not browser Save/Update/Publish or real file/gallery upload, JFB submission
or validation acceptance. Never silently increment save_verified or
fully_accepted. Use no existing company options or customer forms.
If a temporary option exists, abort rather than overwriting.
Never create administrator sessions from shell to evade tool safety.

## Isolated JetFormBuilder draft definition QA (2026-11-10)
A private non-published test form with native JetFormBuilder Gutenberg
text-field, number-field, textarea-field and submit-field blocks was
created by the original WP post API on Tours.dev Staging. Verified exact
saved serialization, original JFB parser, names/labels and no business
actions or active payment gateways, then permanently removed the draft.
15/15 assertions passed. JFB can initialize a harmless protect_price_field
gateway metadata default; do not mistake that for an active payment gateway.
This evidence is ONLY isolated_draft_readback of field definitions,
NOT native form submission or saved field values. All production/public
JetFormBuilder submission and security checks remain unverified.
No privileged browser session may be fabricated from SSH for acceptance.

## JFB actual validation boundaries (2026-11-10)
Isolated draft test with JFB native Validation_Handler and Block_Helper
verified published-form security guards, reject unknown IDs, draft IDs,
invalid signature and unsigned request, 13/13 PASS. Temporary draft was
deleted in finally. This is request rejection QA, NOT a successful form
Submit, validation of user input, CAPTCHA challenge, web/browser Save or
record persistence. The matrix may mark jfb_validation_boundary_qa=true,
but save_verified and fully_accepted MUST stay zero. Never publish or send
actual business forms to test JFB, and never bypass signature or admin
authentication restrictions.

## JFB no-action native submit evidence (2026-11-10)
An ephemeral published QA-only JetFormBuilder form with original Text,
Number and Submit blocks was submitted using the native in-process
jet_fb_handler()->send_form() in WordPress Staging. Original hidden
security fields came from the real shortcode render. No security filters
were removed or bypassed. Zero configured Actions or active payments,
exact Text and Number parser results, no persistent JFB submission records
and full deletion in finally. 11/11 PASS.
Only isolated_native_noaction for two JFB types is justified.
Not browser HTTP submit, not admin Save, not media upload, not stored
Form Record or provider CAPTCHA/Map acceptance. Full Save status remains 0.

## Mandatory live Options Page visual parity (2026-11-10)
Never equate successful Recipe data attribute or CSS registration with a
modern interface. Service Activation taught us that native JetEngine
cx-control-inline (two-column title/input) can remain visibly legacy even
when its parent receives the Recipe card border.
In Modern Visual Mode, ordinary native inline controls must have clearly
stacked info/title above the original controls, with responsive full-width
surfaces, while keeping ALL original inputs, labels, field names, actions,
checkbox hidden-value model and vendor Save. Do not replace input nodes.
Custom CX checkbox group uses hidden .cx-checkbox-input and
.cx-checkbox-item-wrap (not standard checkbox controls); provide responsive
option tiles without touching original checked values.
Maintain Native Mode fallback: scope enhancements to mad4b-ws-visual only,
and avoid changing native JetEngine defaults outside that mode.

Before accepting an interface, run the real native page's SSR controls
through Chromium, compare computed flex-direction/box geometry (not just
DOM), validate all dynamic fields and one native Save, test 320/768/1366
in LTR and RTL, no overflow and no runtime errors.
Service Activation native SSR had 31/31 fields matching its dynamic schema;
modern visual CSS contract passed 78/78 isolated Chromium checks.
This is not authenticated web browser Save acceptance.

## Mandatory live WP Script dependency graph acceptance (2026-11-10)
Service Activation real-world symptom persisted after CSS parity tests
because the shared functional-modes.js had a MANDATORY dependency on
mad4b-provider-price-sources, registered exclusively by Pricing pages.
When a WordPress enqueued script references an unregistered dependency,
WP_Scripts omits the depending script from printed output. The React/CX
vendor form keeps rendering, creating a plausible-looking but completely
legacy admin UI. Therefore HTML/Playwright tests which insert JS directly
do not certify end-user deployment.

For shared Options Pages, enqueue optional enhancement dependencies only
when actually registered at that stage of WordPress admin enqueue.
Preserve explicit dependency order wherever the optional scripts exist.
Never require a Pricing-only script on Service Activation or other pages.
For each page, QA must check WP_Scripts::all_deps and do_items printed
script and inline config (not just wp_script_is('enqueued')).
The permanent code correction checks wp_script_is(handle,'registered')
before including contextual-help and provider-price-source handles.
Service Activation 7/7 dependency + 6/6 printed-script checks;
Pricing page 7/7 dependency checks. The real native HTML and inserted JS
with full CSS yielded 78/78 modern stacked card visual geometry QA.
No authenticated in-browser Save was performed; do not imply otherwise.

## Universal JetEngine Options Page coverage scan (2026-11-10)
Service Activation's absent-script-dependency bug was corrected earlier.
Afterward all 11 registered native JetEngine Options Pages must pass the
WordPress actual script dependency-graph/printed-JS test, not merely
wp_enqueue_script() registration. Current source reaches 11/11.

JetEngine Options Pages may receive new raw fields after stored schema
discovery, including Look & Feel. Never drop them or hide them all in
'Other settings'. The Functional Workspace must recognize unknown native
.cx-control[data-control-name] from original DOM, infer *presentation only*
type and label, classify into existing content/design/integration/
operations/general groups, and apply native Recipe cards. Read NO values,
tokens, passwords, emails or content from the actual input. Never clone,
move, rename, serialize or save native input nodes through MAD4B.
Intentional sensitive-field exclusions in server-provided config stay
intact, while their original visible vendor controls still get a safe
presentation treatment.

Tablet/mobile CSS must have one real grid column for <=900px AFTER
three-column Tour Inspector desktop rules, with sidebar, content and
native form in column one and successive rows. Otherwise phantom grid
tracks cause content overlays and block nav pointer events. Always test
actual CLICK on each navigation group and confirm at least one native
group control is visible, not merely tagged in DOM. Retain one original
vendor Save and instant Native Mode fallback.

Reusable authoritative aggregate scan (read-only, no field values):
wp eval-file wp-content/plugins/mad4b-jetengine/modules/functional-modes/qa/options-page-coverage-scan.php --skip-themes --user=1
Execute after registering or editing any JetEngine Options Page.
It emits only aggregate field counts, dependency print results and
unmapped DOM totals. Unknown raw fields are allowed but must be
classified from original DOM by the UI and covered by responsive
Chromium tests. Do not extend shared Recipe styling to unrelated
third-party settings screens without their own opt-in adapter.

This release: 11 pages, 524 native rendered control wrappers; 319
source-config-unmapped native controls safely classified; 11/11 actual
WordPress script graph and print, 792/792 browser checks in 66
LTR/RTL responsive variants, 105/105 group navigation clicks in 33
viewport cases at 320/768/1366, no horizontal overflow and original
JetEngine Save retained. Browser Save/readback still unverified.

## Core WordPress native UI discovery and safe styling (2026-11-10)
The project is no longer permitted to equate JetEngine coverage with all
WordPress admin UI coverage. WordPress core 7.1.3 on Tours.dev has
separately loaded native CSS for admin foundations, controls/forms,
list tables, admin sidebar, Media Library, color picker, classic post
editor, nav menus, widget editor, Gutenberg Components, Block Editor
and Gutenberg Editor. The read-only Native WP UI Inventory submenu is
part of MAD4B. The source-backed WP-CLI scanner audits 12 core families,
selected stable selectors, registered WP_Styles handles and RTL assets:

wp eval-file wp-content/plugins/mad4b-jetengine/modules/functional-modes/qa/wp-native-ui-inventory.php --skip-themes --user=1

Rules for permanent UI engineering:
- Discover WordPress core registered style/script handles and actual
  CSS before adapting them; never rely solely on memorized selectors.
- Treat Core CSS presence, handle registration, screen enqueue and actual
  browser compatibility as DIFFERENT evidence levels.
- Core components have separate ownership. Apply styling only on
  MAD4B-owned/opted-in admin screen surfaces with a unique root class.
  Never blanket-skin #wpadminbar, #adminmenu, .components-*, .media-modal,
  .wp-list-table or editor canvas at global scope.
- Themed interfaces may inherit Core primitives, scoped design tokens,
  accessible spacing/radii and focus rings, but MUST preserve original
  forms, nonces, keyboard behavior, screen reader names, AJAX handlers,
  Gutenberg state and Media Library selection/upload.
- For each native family adopt explicit adapter contracts: source
  selectors, WP version/registered handle, original behavior, LTR+RTL,
  dark/light where appropriate, 320/768/1024/1440 viewports, keyboard
  Tab and focus, empty/error/loading/success states, and Native fallback.
- Prefer using WordPress core API components rather than recreating
  native controls. Do not equate a CSS selector match with acceptance.
- Four of 50 example selectors were not in their *expected file* in
  7.1.3. This signals different/changed ownership or versioned markup,
  not proof of a bug; discover actual source before writing a style rule.
- Treat WordPress Updates, Plugins, Settings, media upload and Gutenberg
  as high-risk interactive surfaces. No global deployment without
  isolated editor/Media tests and original native save/readback.
- A native widefat table can overflow a 320px viewport. The MAD4B
  Inventory page uses a keyboard-focusable horizontal scroll region
  scoped to its own screen. NEVER patch all .widefat tables globally.

The core discovery inventory is read-only and does not activate a global
WordPress theme. Its UI passed 11/11 generated-page assertions and
36/36 responsive browser checks (320,768,1366; LTR/RTL).

## Native WordPress core component Adapter pilot (2026-11-10)
The native WordPress Inventory admin page contains the first opt-in
MAD4B-owned pilot of original WordPress input.regular-text, select,
checkbox, .button, .button-primary and .notice components.
Do NOT globally override WordPress selectors. The pilot styles only
#mad4b-wp-native-inventory .mad4b-native-pilot with semantic design tokens
and leaves the provider DOM, behavior and normal admin forms untouched.
Read-only interactive demo has no form, no submit button, no server
writes, no AJAX. JS uses textContent for text and native button click
events; Reset restores state and focuses the original native input.
Its success notice is a local preview, not a WordPress persistence claim.

Before broadening the adapter to other MAD4B screens, run original
WordPress Admin render (native CSS/controls) and Chromium at 320/768/
1366 in both RTL/LTR, original control/read-only behavior, keyboard
Enter and focus, meaningful notice status, zero horizontal overflow,
no JS errors. This pilot passed 12/12 source tests and 78/78 Chromium
interaction checks. Core Gutenberg and Media Library are NOT covered
by this adapter or approved for global retheming. Deploy each
higher-risk family only after own native interaction/save acceptance.

## Complete admin screen discovery before extending Core adapters (2026-11-10)
A WordPress CSS family inventory is not the same thing as MAD4B
admin-screen coverage. Source Tours.dev has 167 MAD4B-related registered
admin routes in the observed WP-CLI menu registry, including this new
inventory route. These are menu routes, not 167 verified standalone
fully functional screen experiences. Prior JetEngine Options coverage
is a separate 11-page surface-specific acceptance.

Read-only registered-route scanner:
wp eval-file wp-content/plugins/mad4b-jetengine/modules/functional-modes/qa/wp-native-screen-coverage-scan.php --skip-themes --user=1

The WordPress Native UI Inventory now shows a searchable, filterable
Screen Coverage Registry from $submenu at admin runtime. Each route
gets a heuristic component family and proposed adapter. The current
groups are Commerce/Pricing, Company/Other, Content/Editorial,
JetEngine/Recipe, Media/Upload, Operations/Control Plane and
Settings/Brand. These are NOT proof of actual component CSS use.
Do not assume a route is accepted without opening its real admin UI,
checking original save/readback, AJAX, state and language behavior.

A global Core skin is still FORBIDDEN. Modernize in owner-scoped
modules with explicit opt-in root selectors. Commerce and pricing
(require price/currency/booking integrity); media (selection/upload
integrity); and operations (admin controls/security) have high-risk
classification and need dedicated native functional tests before
any visual modifications.

The Registry is read-only and does not call screen rendering callbacks,
change permissions, attach CSS to foreign screens, or execute writes.
It passed 11/11 WP source assertions and 60/60 Chromium assertions at
320/768/1366 in LTR and RTL (including filtered counts, search/family
filters, native pilot keyboard interaction and no overflow).
Do not promote full screen acceptance from these registry tests.

## First native WP Settings API real-screen adapters (2026-11-10)
Source Tours.dev Staging: opted in precisely three existing MAD4B
Settings API screens after inspecting their actual registered callbacks,
rendered native WordPress forms and field controls:
mad4b-visitor-experience (2 checkboxes), mad4b-presentation-experience
(18) and mad4b-site-shell (1). Original forms POST to options.php,
with native WordPress hidden fields/nonce and one native Submit each.
The original controls, checked/hidden input ownership, form action,
method, Names and keyboard behavior are never mutated, cloned or moved.
The opt-in class is only added via admin_body_class on the allowlisted
admin pages with manage_options; WordPress common/forms CSS are declared
dependencies, and a unique MAD4B-specific stylesheet is enqueued only
there. CSS supports both <form><p><label> and <form><label> vendor shapes.

Real WordPress SSR fields + stylesheet injection in isolated Chromium:
216/216 PASS across all three pages, 320/768/1366 and RTL/LTR,
covering byte-identical native form markup before/after styling,
21 original checkbox inputs, native Save, checked state, keyboard Space
toggle/restoration, modern card geometry, no overflow and JS errors.
WordPress core enqueue/allowlist and vendor DOM invariants 30/30 PASS,
permanent read-only contract in qa/wp-native-settings-adapter-contract.php.

AI Routing, Brand Asset Store, Recipe Registry and all other admin
pages are explicitly OUT OF SCOPE; do not silently cascade. WooCommerce
global admin_init fired manually from WP-CLI raises a wc_get_page_screen_id
error in that artificial context; it is not evidence of a browser or
production outage. Do not disable WooCommerce hooks or mock globals to
force a false pass. Authenticated admin browser Save/readback for the
three pages remains unverified. Do not call a CSS-only pass a Save pass.
