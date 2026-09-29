# Security & Privacy

Telemetry/recovery evidence may expose content, protected meta, credentials, PII or provider payloads. Protected meta is never emitted by default. Unregistered meta is redacted by default and safe display requires explicit allowlist policy. Raw post content/excerpt is excluded from events by default. Large text uses digest/length and optional bounded preview. OAuth/approval secrets are never persisted in trace metadata. Provider evidence exposes safe summaries plus digests. Trace reads are policy/capability controlled. Retention is finite. Journal tamper evidence is mandatory.

Release fails on secret leakage, unredacted protected/unregistered meta, unchained mutable journal evidence, unauthorized trace access, or Production package containing fault-injection implementation.
