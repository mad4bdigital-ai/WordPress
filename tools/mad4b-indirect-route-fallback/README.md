# MAD4B Indirect Route Relations Fallback v1.0.2

Read-only route suggestions for original Tour/Program itinerary via native Transportations, destinations and JetEngine relations.

- Original saved From/To overrides all suggestions.
- A unique explicitly linked Transportation route is displayed as a candidate only. Wider taxonomy/JetEngine links are review evidence only; no inferred route order.
- No Google API calls, no distance, cost, Rates, Woo orders, or automatic supplier approval.
- Source SHA-256: `9725f882d816643a78973ae9dee1f8ea42de73fbca25375c7721e627d3a47c06`.
- Verified on Tours.dev and Wayfa, PHP lint clean, `inspect(60629)` 12 days both; 36 vs 14 broader linked candidates; Tour Rates unchanged.
- An admin-only, read-only meta box is enabled in native Tour Rate editor.

Rollback: remove only `wp-content/mu-plugins/mad4b-indirect-route-relations-fallback-v1.php` from each tenant after hash check. Companion backup and QA in `/opt/mad4b/operations/indirect-route-relations-fallback-20261124`.
