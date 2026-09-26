# Contract — Cron and Growth

Contract: mad4b.cron-growth.v1

## Cron semantic family

Generic:
- cron.list
- cron.read
- cron.health
- cron.run
- cron.schedule
- cron.unschedule

Providers:
- WordPressCronProvider
- HostCronProvider
- future external scheduler

Rules:
- read discovery is separate from mutation;
- cron.run requires exact event identity and budget;
- schedule/unschedule require exact desired/expected state;
- provider-specific identifiers remain adapter fields;
- job/workflow orchestration does not gain cron mutation by implication.

## Growth loop

SearchPerformanceProvider is provider-neutral.

Normalized dimensions may include:
- query
- page/content identity
- country
- device
- date range

Artifacts:
- IndexStatus
- SearchPerformanceSnapshot
- ContentDecaySignal
- CannibalizationSignal
- RefreshRecommendation

Rules:
- Growth is post-publish;
- recommendation does not mutate content;
- approved refresh creates new/revision ContentJob;
- historical published artifact identity is preserved;
- GSC or any one vendor is an adapter, not the domain model.
