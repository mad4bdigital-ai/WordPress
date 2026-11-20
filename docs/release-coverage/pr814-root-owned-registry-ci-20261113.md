# Unified Source CI — root-owned Original306 Registry test only

The source preview Registry fixture creates a temporary first-party enrollment
under `TemporaryDirectory`. The actual Original306 `Registry` requires the
runtime root directory to be root-owned and not group/world-writable, plus
exact pinned Provider dependencies. CI executes the fixture as the default
unprivileged `runner` user and fails with `REGISTRY_ROOT_NOT_TRUSTED`.

**Test-runner correction, not a runtime gate removal:** run just the isolated
source candidate test with `sudo -n -E "$(command -v python3)"` on the
`ubuntu-latest` hosted CI runner. Its ephemeral temporary Registry is then
root-owned and still subject to exactly the same real `Registry` validation.
All other test steps remain unprivileged. The source preview remains
`NOT-ENROLLED`; no signed Grant or Production registration is performed.

Local positive source test: 1/1 passed as root. GitHub's independent runner
result is still required. **No change** to `Registry` trust checks, key
validation, Producer/Provider signatures or WordPress runtime privileges.
Original313 historical postimage mismatches remain Release HOLD.
