# Elementor original-template parity

Canonical contract: `mad4b.elementor-native-template-parity.v1`

## Ownership

The **existing original Elementor document** owns its presentation. Reuse its native
widgets and containers instead of introducing competing HTML, custom renderers,
shadow Listing Grids, or CSS overlays for the same section. JetEngine conditions
belong to the original visibility container, not to the newly added child card.
WPML owns per-language translated document identity and text.

## Governed code path

`MAD4B_SCP_Elementor_Native_Template_Parity::verify(before, after)` is called by
the Elementor adapter's single `persist_elements` path, including forward
widget/structural/dynamic-tag operations and their restoration. It inspects
conditions/visibility by setting keys and element identity, not by hardcoded
query IDs, element IDs, locale counts, or company names. Existing conditional
owners and their native JetEngine consumers may not be removed, copied,
relocated or silently reconfigured by a fallback write. Ordinary native widget
text and style settings remain editable through the authorized adapter.

The guard never creates a post or translation, never touches WPML linkage,
never authorizes a write, and cannot control direct native Elementor Editor
saves or unrelated third-party database modifications. A deliberate redesign
in the Elementor Editor must establish a new reviewed source/locale baseline.

## Skills and rollout

The seeded Elementor, change-safety, and browser-acceptance Skills all reference
this same contract. The manifest and seeder carry the same incremented version,
so managed seeds can reconcile after the **authorized** Control Plane release.
User-owned Skill overrides are not overwritten. The provider Skill stays
subject to provider discovery and certification: changing a Skill never grants
an executor or Production authority.

Acceptance must distinguish the source code and Skill seed tests from actual
runtime deployment, WPML/source parity, CSS regeneration, cache readback,
desktop/mobile visual checks, and Production approval. A failed headless browser
run is **UNVERIFIED**, never a passing visual test.
