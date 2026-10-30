# ChatGPT Client Cairo Research — 2026-10-30

Status: evidence researched in the current ChatGPT conversation, **not** persisted to Staging's ContentJob. Direct ChatGPT OAuth MCP connection for tours.dev.mad4b.com is not present in the session and the VPS write attempt was blocked.

Target ContentJob: `14d65a58-73f2-4f1c-8512-c9fb652317a0`; stage `INFORMATION_GAIN`; last observed revision `5`. Executor `chatgpt_client`. Submission key: `cairo-day-tours-keyword-20261030-01`.

## Client-observed sources

- https://egyptdaytours.net/cairo — Cairo private/half-day/full-day itineraries and themes.
- https://egyptplanners.com/cairo-day-tours/ — Pyramids + museum tour product examples.
- https://gem.eg/en/visit/plan-your-visit/tours — official guided museum tour formats.
- https://www.egyptgate.net/en/cairo/excursions/best-tours-and-excursions — commercial Cairo excursion categories.

## Evidence-backed keyword candidates

| Keyword | Intent | Source |
|---|---|---|
| cairo private day tours | commercial_research | egyptdaytours.net/cairo |
| giza pyramids and grand egyptian museum day tour | commercial_research | egyptplanners.com/cairo-day-tours |
| cairo half-day pyramids and sphinx tour | commercial_research | egyptdaytours.net/cairo |
| grand egyptian museum guided tours | commercial_research | gem.eg/en/visit/plan-your-visit/tours |

No Google SERP position, search volume, CPC, audience size or market ranking was measured. These are observed page topics, not verified ranking statistics.

After Staging source parity and direct ChatGPT MCP connection, call `mad4b/hub-executor-submit-keywords` with the exact job ID, current revision, client step key, source URLs and keyword records. The new ability records an immutable `keyword_research` Artifact, checks source evidence and prevents duplicate or stale submissions. It does not publish content, execute a server AI model or claim OAuth attribution unless verified from the actual request.
