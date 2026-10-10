# Actual PHPWASM CSO simulation

This runner uses the official WordPress/wordpress-playground PHP 7.4.33 and 8.3.33 builds pinned to commit `4d3932270ebfdc7654d9b1cb3954841229d1fd2d`. It invokes the actual CLI engine, not a PHP parser or translated test. No npm dependencies are required; use Node 20+ and Python 3 for the coordinators.

```sh
bash setup-runtime.sh /absolute/scratch/php-runtime
python calibrate.py --runtime /absolute/scratch/php-runtime --fixtures fixtures --out /absolute/scratch/calibration-report
node run-php.mjs --runtime /absolute/scratch/php-runtime --php 7.4 --repo /absolute/plugin-snapshot --file tests/cso-gateway-runtime.php --report /absolute/scratch/gateway.json -- normal
node run-php.mjs --runtime /absolute/scratch/php-runtime --php 8.3 --repo /absolute/plugin-snapshot --file includes/class-mad4b-scp-cso-form-ui.php --lint --report /absolute/scratch/ui-lint.json
python replay-published.py --runtime /absolute/scratch/php-runtime --repo /absolute/plugin-snapshot --commit EXACT_ARCHIVED_COMMIT --out /absolute/scratch/published-matrix
```

Use an immutable plugin-only `git archive` snapshot from an exact commit; the full WordPress tree can exceed the 256 MiB source limit. The commit argument labels the coordinator report; independently record the archive/commit/tree provenance. Each runner report records the complete copied source hash, individual fixture hash, all official asset hashes, runner hash, stdout/stderr hashes, genuine PHP exit status and a before/after host-source fence. Writing reports into the input snapshot is denied.

The official PHPWASM `/request` devices pass borrowed WASM HEAP views. This runner synchronously copies those views before posting Worker output. Calibration compares Unicode, emoji, NUL, repeated allocator-overwrite output and unfinished tails against independently constructed Python byte strings, three times per engine. It also checks RSA 2048, AES GCM, exact exit 42, actual syntax failure 255, wall timeout 124 and unavailable subprocess execution.

Only explicitly selected regular source files are copied into private MEMFS. Symlinks, Git, common credential/cache directories, `.env` files and `node_modules` are excluded. No host filesystem mount, host environment inheritance, process provider, or network proxy is configured. PHP URL access and external network entry functions are disabled. Limits are 50,000 files, 256 MiB source, 8 MiB output, 256 MiB PHP memory and 30 seconds wall time by default.

These results are **WASM simulations, not native CI or live acceptance**. The pinned engines use OpenSSL 1.1.1t and have no Sodium/pcntl. Private MEMFS locks do not prove native multiprocess locking. Native WordPress/MySQL, durable storage, concurrency, Redis, browser and live endpoint acceptance require separate verification. The runner is a test harness, not a security boundary for hostile PHP. Fixture doubles must be named honestly; a production CSO class double cannot establish its real integration.

Large generated WASM/loader binaries and local report logs are deliberately not committed. The reproducible setup fetches official pinned assets, and the runner verifies their exact Git blob identities on every invocation.
