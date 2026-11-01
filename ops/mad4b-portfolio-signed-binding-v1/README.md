# MAD4B Company Portfolio — Signed Staging Binding Candidate

**Status:** source candidate for review; not installed on the live WordPress site.

This folder contains a byte-for-byte copy of the existing Central Staging
Company Portfolio Manager plugin source, except for a narrowly-scoped update
to `MAD4B_Company_Portfolio_Manager_V1::company_binding()`.

## Contract change

When the original primary Company Registry row cannot prove its site binding,
the manager may resolve the company's independent Staging binding, but only
when BOTH original providers agree:

- `MAD4B_Company_Environment_Registry_V2::target(company, 'staging')`
  reports a centrally verified signed-v4 identity.
- `MAD4B_Environment_Signed_Identity_V4::status(company, 'staging')`
  returns `centrally_verified === true` for a current proof, the same exact
  Site UUID and the same canonical origin, with `environment === staging`.

The staging SHA-256 binding commits to company + environment + Site UUID +
canonical origin. Changing or expiring proof makes the current assignment
ineligible. Existing valid Production bindings retain their legacy digest
and delegation checks unchanged.

This plugin source does **not** issue a 305 grant, automatically create or
approve any company-manager assignment, run WP-CLI on remote hosts, or
authorize a native write provider. The original owner/authenticated
administrator and company-scoped rights remain separate prerequisites.

## Isolated test

Run inside a PHP 8.x CLI with the staged files together:

```bash
php -l mad4b-company-portfolio-manager.php
php test-signed-staging-binding.php
```

The isolated test uses WordPress function mocks and makes no DB, REST, SSH,
or filesystem mutations. After the source is approved via a normal release,
the site should be tested using read-only WP-CLI and actual original
Signed Identity V4 readback **before** any company delegation is proposed.

## Original live source

Central Staging:
`tours.dev.mad4b.com/wp-content/plugins/mad4b-company-portfolio-manager/`

The exact live main-plugin SHA-256 observed when preparing this source was:

`4b46ab50e3cbd141ac2e832788acea11f28ddd7449523bb2184f42c98092d445`

Installation is deliberately **not** embedded in this candidate: the original
site's approved release process must check exact source and site identity and
provide its own rollback. No SSH or WP-CLI owner impersonation is authorized.
