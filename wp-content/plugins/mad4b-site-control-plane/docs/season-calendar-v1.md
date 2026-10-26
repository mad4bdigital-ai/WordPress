# MAD4B Country Season Calendar v1

## Provider ownership

MAD4B owns the entire calendar discovery/read path through the already-installed `MAD4B_SCP_Google_Drive_Context` Google OAuth provider under Context Authority (`mad4b-control-plane-context`). No second Google credential, OAuth app, or third-party token store is created. `calendar_read_request` only allows the CalendarList and bounded Events endpoints on `www.googleapis.com`, checks the exact calendar.readonly/full OAuth scope, never returns tokens, and limits paginated API responses to five pages.

The ETG WooCommerce Lab Google connection currently reports `token_unreadable` (2026-10-24). Native site OAuth must be reconnected for live provider calls; a separate ChatGPT Google Calendar connection does not replace this.

## Country and market boundaries

- Only `MAD4B_SCP_Market_Profiles::by_country(country, language)` approved pairs can import events (`EG+ar`, `US+es` and `US+en` are independent).
- The current WPML language must not be hidden; hidden languages make markets unavailable without deleting stored records.
- `MAD4B_SCP_Season_Calendar::subscribed_country_calendars()` discovers only Google holiday feeds already subscribed by the authorized Google account, not a guessed global list of calendars. Common Google country-slug aliases such as `usa->US`, `uk->GB`, and `brazilian->BR` are mapped intentionally; unknown/non-holiday calendars are rejected.
- A public holiday feed provides dated events, **not commercial campaign, Brand Context, or pricing approval**. In Google Calendar, individual event dates may be revised, especially tentative religious holidays.

## Data flow and policy

1. Admin approves a Market Profile (country + visible WPML language) in MAD4B.
2. Existing Context Authority Google OAuth must be usable and include Calendar read permission. Holiday feed is subscribed in that Google account.
3. Admin chooses an approved market, one discovered holiday calendar of the same country, and a bounded date window in `MAD4B -> Country Seasons (Calendar)`.
4. Fetch enumerates recurring instances (`singleEvents=true`), excludes cancelled events, converts Google's all-day exclusive end date into an inclusive WP season window, and stores `mad4b_scp_season_calendar_proposals_v1` as `pending_review`. This does **not** create or activate a season.
5. Admin can explicitly confirm a non-tentative event. MAD4B creates a `season` taxonomy term with `season_mode=domestic`, `season_date_windows`, country/language/source metadata and `season_enabled=0`. It remains inactive; Elementor Top Bar and financial offers are unchanged.
6. An optional checked box on the first successful fetch authorizes once-daily refresh via WP-Cron, bound to the exact site UUID, market ID, country, language and calendar. Cron can only refresh proposals; it cannot approve dates or publish offers. If a previously reviewed event changes/disappears in a complete bounded provider window, its Season term is forced inactive pending fresh review.

## Operational safety and limits

- No automatic Market Profile approval or Season term publication. Brand Authority approval remains separate.
- Calendar read is blocked if existing Google token is unreadable, revoked, or missing the correct OAuth scope.
- Multi-page Calendar API reads fail closed beyond the configured bound; imports reject more than 500 events.
- Only Google Calendar all-day events are converted; timed events are ignored rather than guessed.
- CalendarLast sync failures are surfaced as provider error codes, without credentials or private events in logs.
- Site writes in this version are intentionally **local admin only**. Production/Staging mutation and approval require a separately certified governed policy, append-only audits, credential-scope certification, schema migration and CI.
- WordPress Cron executes on site traffic unless a reliable system runner is provided; ensure a real scheduler for production.

## Local acceptance evidence, October 24 2026

- Real authenticated ChatGPT Calendar listing discovered subscribed Egypt holiday calendars, but **not** through the site's unreadable MAD4B OAuth; that source is only evidence of API/Calendar capabilities.
- Pure-provider event fixture accepted confirmed Egypt event and refused tentative date approval, mismatched market, unregistered calendar, and stale review revision.
- A reviewed event created an inactive domestic `season` term. Same-event replay was idempotent. Source date change and source disappearance kept the term inactive and required fresh review. Fixture term and proposals were removed after testing.
- Scheduled binding opt-in registered a WP-Cron daily event in an isolated test, then cleared it and restored the original bindings. No live sync was started.
- `MAD4B_SCP_Google_Drive_Context::calendar_read_request('calendar_list')` correctly returned `mad4b_calendar_google_connection_unavailable` for current `token_unreadable` state.
- Top Bar seasonal selection and financial offers remain off.
