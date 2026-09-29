# Staging Canary Matrix

C01 create draft — exact readback + completed journal.
C02 update draft — exact pre-state CAS + readback.
C03 accept/publish — accepted state unchanged + single receipt consume.
C04 stale receipt — reject, revalidate, new receipt, publish.
C05 rollback/undo — exact restored state.
C06 human edit during compensation — refuse overwrite.
C07 missing term — explicit dependency, no hidden creation.
C08 provider validation failure — no acceptance.
C09 worker termination after owned write — deterministic recovery classification.
C10 lock loss during repair — stop after safe boundary + recovery_required.
C11 external provider effect failure — no false full rollback.
C12 pipeline config drift — pinned config stays authoritative.
C13 concurrent receipt consume — at most one success.
C14 100-operation soak — no leaked lock/zombie/cache defect.
C15 10 independent concurrent ops — invariants preserved.
C16 same-target contention — deterministic owner/rejection.

Every canary binds code SHA, build/package digest, site identity, environment, policy digest, pipeline digest and evidence timestamp.
