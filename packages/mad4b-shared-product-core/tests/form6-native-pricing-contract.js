"use strict";
const fs=require("node:fs");
const vm=require("node:vm");
const assert=require("node:assert/strict");
const root=__dirname+"/../mu-plugins/shared-product-core/";
const js=fs.readFileSync(root+"assets/presentation-fluent-quote-v1.js","utf8");
const php=fs.readFileSync(root+"adapters/presentation-form6-price-quote-v1.php","utf8");
new vm.Script(js,{filename:"presentation-fluent-quote-v1.js"});
// Exactly one visible price controller; Tour Rate comes from the selection broker.
assert(js.includes("window.SharedPresentationSelectionV1"));
assert(js.includes("broker.getSnapshot()"));
assert(js.includes("snap.selectedRateId"));
assert(js.includes("shared_tour_selection_v2"));
assert(!php.includes("add_action('fluentform/after_form_render'"));
assert(!php.includes("shared-form6-price-reference"));
// Public minor previews keep actual passenger counts and exclude minor charges.
assert(js.includes("{...intent,extras:[],stay_stages:[]}"));
assert(!js.includes("{...intent,children:0,infants:0,extras:[]"));
for(const fragment of [
 "quote.status==='partial_price_preview_only'",
 "quote.display_kind==='adults_only_reference'",
 "quote.total===null",
 "quote.checkout_ready===false",
 "quote.order_created===false",
 "quote.children_count===intent.children",
 "quote.infants_count===intent.infants",
 "quote.display_amount",
 "Adults only",
 "Final price to confirm"
])assert(js.includes(fragment),"missing canonical partial contract: "+fragment);
assert(js.includes("data.status!=='price_preview_only'"),"adult-only preview unchanged");
assert(js.includes("const partialAdults="),"Rate and Direct pricing must allow partial adults");
assert(js.includes("intent.source==='rate'&&asRateId(intent.rate_id)"),"selected Rate must participate in minor preview");
assert(js.includes("amount(quote.display_amount,quote.currency)"),"must render non-null amount");
assert(!js.includes("amount(quote.total,quote.currency)"),"must never format a null full total");
for(const lang of ['en','fr','de','es','it','ar'])
 assert(js.includes('"'+lang+'":{'),"missing translation: "+lang);
assert(js.includes('numberLocale=document.documentElement.lang'),"currency formatting must follow document language");

// The PHP endpoint must be runtime-gated, not restricted to the WordPress local environment.
assert(php.includes("MAD4B_COMMERCE_RUNTIME_ENABLED"));
assert(php.includes("shared_product_form6_price_runtime_enabled"));
assert(!php.includes("wp_get_environment_type()!=='local'"));
console.log("FORM6_NATIVE_PRICING_CONTRACT_PASS");
