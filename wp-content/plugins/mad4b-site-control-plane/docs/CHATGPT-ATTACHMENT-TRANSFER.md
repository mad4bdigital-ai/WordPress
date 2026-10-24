# ChatGPT attachment transfer — Staging bridge v1

This is a **source-only, opt-in client bridge**; it is not an OpenAI attachment API and does not grant ChatGPT access to chat attachments. The ChatGPT client must independently obtain the file bytes through an authorized attachment-capable connector/host and forward them. No URL fetch or production upload is supported.

## REST contract

Prefix: `/wp-json/mad4b-chatgpt-transfer/v1`. Every endpoint uses authenticated `POST`, requires `upload_files`, enrolled exact-origin Site Profile in **Staging**, connected WordPress user, and existing read policy. The endpoint never accepts client-specified paths.

1. `begin`: `{"filename":"example.pdf","size":1234,"sha256":"<64-lowercase-hex>"}`. Returns random `transfer_id` and 0 `next_offset`.
2. `chunk`: `{"transfer_id":"<32-hex>","offset":0,"data_base64":"..."}`. Decode **at the client**, split into chunks no larger than 131072 bytes, base64-encode each chunk; send sequentially with exact offset. The server returns `next_offset`.
3. `status`: `{"transfer_id":"..."}` returns the last accepted offset to resume after a lost acknowledgement.
4. `finish`: `{"transfer_id":"..."}` only after the entire 1–16 MiB file is received and SHA-256 matches. Runs MIME checks, imports to WordPress Media Library, and returns `attachment_id`, `attachment_url`, `sha256`, and `verified`. No publication.
5. `abort`: `{"transfer_id":"..."}` clears an incomplete transfer.

Transfers are bound to a user and enrolled origin; state expires after 1 hour. Files are temporarily stored with restrictive permissions under WordPress's PHP system temp directory, never in a caller-selected upload destination. For this initial slice, temp-file garbage collection, antivirus/content scanning, host-level upload execution guard certification, cross-request high-concurrency hardening, authenticated client attachment byte acquisition, and live browser/MCP acceptance remain to be implemented or independently verified. Treat unsupported file types as blocked, not as a reason to widen allowed MIME types. Never forward credentials or signed ChatGPT links as part of the transfer body.

## Acceptance steps

Run the workflow `MAD4B ChatGPT Attachment Transfer`; verify client authentication and exact Staging enrollment; test a disposable image and PDF with exact size/hash, negative tests for incorrect hash, MIME mismatch, wrong user, offset replay, oversized chunk, production origin, and interrupted transfer; verify WordPress Media Library attachment bytes and metadata by independent readback; confirm no PHP execution in uploads using certified **origin-level** host attestation. Delete test media via the regular governed workflow after explicit approval.

A REST-compatible authenticated client can use the bridge. Projection into MAD4B MCP discovery/dispatch and direct ChatGPT attachment-binary access is a separate integration step and is **not** claimed by this source slice.
