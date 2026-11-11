# Company Manager — source-only early mail hook ownership

## Corrected route

On Production, the Core early zero-touch bootstrap registers company email hooks only when Company Manager is absent. The Company Manager previously registered its corresponding early email hooks on Staging alone. This left an unowned route when the Manager was active on Production.

The additive source update registers the Manager's existing three mail classes on both staging and production, with class_exists checks to prevent redefinition when the Core fallback has already loaded any of the same class identifiers. No other Company Manager enrollment, delegated rights, Master Admin, content/commerce policy, company-specific SMTP setting or WordPress option has changed.

## Retained restrictions

- Company mail still depends on the existing Company Communication / Sender Policy checks and target-owned Brand Context, recipient scope and mail delivery readiness.
- This is not enabling a default SMTP provider, importing credentials, changing WooCommerce internals or sending test messages.
- The Core Production fallback branch remains unchanged. No central OAuth grant or Original 305/312/313 authority is issued by loading mail hooks.
- WordPress network-activation plugin ordering, Core fallback precedence and real Production mail hook readback require an independent controlled acceptance before deployment.
- Source tests verify exact preimage/postimage hashes, early class duplication guards and intended environment selector. They do not certify actual mail delivery.

**State: SOURCE_ONLY / PRODUCTION_MAIL_ACCEPTANCE_HOLD.**
