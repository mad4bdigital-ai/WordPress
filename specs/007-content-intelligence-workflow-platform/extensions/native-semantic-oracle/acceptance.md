# Adversarial native Browser Acceptance checklist
| Case | Expected outcome |
|---|---|
| Unrecognized provider / brand changes | No site-name branching; native provider stays capability-specific |
| Public published WordPress page, same-origin canonical | Server signs plan; genuine signed matching browser evidence may PASS |
| Page password protected or draft | Never put in native plan |
| Native build manifest mismatch/stale | Block plan before browser |
| Browser worker has no private RSA signer | Fail before remote sessions |
| WordPress has no pinned public key | Block plan |
| Wrong/weak RSA key, tampered evidence or malformed signature | BLOCKED |
| Validly signed evidence but observed canonical differs | FAIL |
| Client forges matches_expected without browser signature | BLOCKED |
| WordPress source fingerprint, plugin inventory, theme or admin revision changes mid-execution | BLOCKED |
| Expired issued_at / challenge invalid | BLOCKED |
| Non-HTTPS/off-root/subdirectory site in unsupported scope | BLOCKED without over-generalizing |
| Generic provider and specialist provider both recognized | Explicit profile or other governed intent required; no default by hostname |
| High-risk booking/payment mutation or arbitrary selector/JS | Unsupported; never given browser authorization |
| CI queued or local source simulation only | No release PASS |
