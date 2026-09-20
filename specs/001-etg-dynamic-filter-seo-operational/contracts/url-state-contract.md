# URL State Contract v1

Supported primary grammar: `/.../jsf/<provider>:<query-id>/tax/<taxonomy>:<single-slug>[;<taxonomy>:<single-slug>...]`. Duplicate taxonomies and multi-value separators are unsupported and fail closed. `utm_*`, `gclid`, `fbclid`, `msclkid` are tracking. Unknown functional query params and pagination > 1 fail closed. Only explicitly allowlisted scalar query params may enter canonical query state.
