# Wayfatours configuration notice revalidation

Company-owned, explicit manual release. It is not an instruction to install into Tours.dev. Only an independently verified provider status can resolve the corresponding historical WordPress admin notice. Current adapter covers the precise Stripe Test Mode notice and leaves all other notices unchanged. The same provider proof automatically reopens the notice if its mode regresses. No Stripe settings, payments or mail credentials are written.

Original305 HOLD and Original313 signed approval remain separate and unchanged. The file in this folder is a source mirror; changing the mirror does not deploy it.

## Fluent SMTP native provider recovery

The existing MAD4B Configuration Jobs adapter is patched in place at `wp-content/mu-plugins/mad4b-configuration-jobs/providers.php` using exact live preimage CAS. It still records original failed Fluent SMTP log rows, but considers a historical failure recovered only when both native SMTP connection health records postdate the failures and at least two newer provider-accepted mail entries exist. This is not proof of inbox delivery. The existing notice revalidator closes the historical wp_mail_failed hook record with that provider proof, and later fresh incidents remain visible. The normal Configuration Jobs scheduled scan performs rechecks; no new transport, payment or cron lane is added.
