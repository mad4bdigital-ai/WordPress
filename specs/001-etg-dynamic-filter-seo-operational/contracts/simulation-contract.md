# Simulation Contract v2

`etg.dfsb.simulation.v1`

Scenario Lab accepts bounded synthetic inputs and returns the same IndexingPolicy decision shape used by runtime. It can model profile/Post Type/taxonomy/result/content/translation/query-state changes but MUST NOT mutate settings, terms, queries, caches, Rank Math metadata, index state or WordPress content.

Every result includes `synthetic=true`. Simulation is objection testing, never staging acceptance evidence.
