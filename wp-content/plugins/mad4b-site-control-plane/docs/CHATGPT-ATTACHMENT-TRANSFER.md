# ChatGPT attachment transfer — Staging bridge v1

This is a **source-only, opt-in client bridge**; it is not an OpenAI attachment API and does not grant ChatGPT access to chat attachments. The ChatGPT client must independently obtain the file bytes through an authorized attachment-capable connector/host and forward them. No URL fetch or production upload is supported.

## REST contract

Prefix: `/wp-json/mad4b-chatgpt-transfer/v1`. Every endpoint uses authenticated `POST`, requires `upload_files`, enrolled exact-origin Site Profile in **Staging**, connected WordPress user, and existing read policy. The endpoint never accepts client-specified paths.

1. `begin`: `{"filename":"example.pdf","size":1234,"sha256":"<64-lowercase-hex>"}`. Returns random `transfer_id` and 0 `next_offset`.
2. `chunk`: `{"transfer_id":"<32-hex>","offset":0,"data_base64":"..."}`. Decode **at the client**, split into chunks no larger than 131072 bytes, base64-encode each chunk; send sequentially with exact offset. The server returns `next_offset`.
3. `status`: `{"transfer_id":"..."}` returns the last accepted offset to resume after a lost acknowledgement.
4. `finish`: `{"transfer_id":"..."}` only after the entire 1–16 MiB file is received and SHA-256 matches. Runs MIME checks, imports to WordPress Media Library, and returns `attachment_id`, `attachment_url`, `sha256`, and `verified`. No publication.
5. `abort`: `{"transfer_id":"..."}` clears an incomplete transfer.

Transfers are bound to a user and enrolled origin; state expires after 1 hour. Files are temporarily stored with restrictive permissions under WordPress's PHP system temp directory, never in a caller-selected upload destination. For this initial slice, live Staging acceptance of the bounded orphan cleanup, antivirus/content scanning, host-level upload execution guard certification, cross-request high-concurrency hardening, authenticated client attachment byte acquisition, and live browser/MCP acceptance remain to be implemented or independently verified. Treat unsupported file types as blocked, not as a reason to widen allowed MIME types. Never forward credentials or signed ChatGPT links as part of the transfer body.

## Acceptance steps

Run the workflow `MAD4B ChatGPT Attachment Transfer`; verify client authentication and exact Staging enrollment; test a disposable image and PDF with exact size/hash, negative tests for incorrect hash, MIME mismatch, wrong user, offset replay, oversized chunk, production origin, and interrupted transfer; verify WordPress Media Library attachment bytes and metadata by independent readback; confirm no PHP execution in uploads using certified **origin-level** host attestation. Delete test media via the regular governed workflow after explicit approval.

A REST-compatible authenticated client can use the bridge. A guarded source-level mapping to the existing governed MAD4B Write discovery/dispatch has been added, but live projection, grant validity and native ChatGPT attachment-byte acquisition remain **unverified** until independent Staging acceptance.

## Explicit client-side sender (no ChatGPT conversation access)

The source tree now includes `tools/mad4b_chatgpt_attachment_sender.py`. The caller must first obtain a user-authorized local copy of an attachment; this script cannot read or discover ChatGPT conversations or retrieve private ChatGPT attachment URLs. It accepts an explicitly provided source file, checks size and SHA-256, verifies TLS to the exact HTTPS WordPress origin, blocks redirects, and sends base64 chunks through the Staging REST transport. OAuth credentials are read only from an explicitly named environment variable, never passed on the command line.

```bash
export MAD4B_WORDPRESS_OAUTH_TOKEN='<authorized Staging bearer token>'
python3 tools/mad4b_chatgpt_attachment_sender.py \\
  --file /path/to/authorized-attachment.pdf \\
  --site https://staging.example.test
```

To inspect the local source without network or credentials, add `--dry-run`. If interrupted, the client writes a `TRANSFER_RESUME_ID` to stderr; rerun using `--resume-id <32-hex-id>` within the server's 1-hour expiry. Never share or log the bearer token. Upload size remains capped at 16 MiB. Readback receipts are not independent proof that the public origin safely serves uploads.

## Scoped grant and deployment

`mad4b/attachment-transfer` is a non-public WordPress Ability and requires `current_user_can('mad4b_attachment_transfer')` **plus** the standard `upload_files` WordPress capability, enrolled matching Staging origin, authorized connected identity, and the effective global mutation switch. The specialized capability is **not automatically granted** or exposed to arbitrary users. An authorized Staging administrator must grant it to the exact user after separate review, and later revoke it when not needed. No Production grants or publishing are included.

After certified package deployment on Staging, use MAD4B's authenticated ability discovery and governed write dispatcher to verify that the Ability is actually projected and executable. Native WordPress Ability source registration alone is not an external MCP transport certification. End-to-end validation must include a real attached file, user/role deny tests, host upload execution checks, and a media readback. None of these live facts can be inferred from source CI.
