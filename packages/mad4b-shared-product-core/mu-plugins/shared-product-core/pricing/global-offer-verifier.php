<?php
/** Core-owned Global Offer verification boundary; fail closed until checkout certification exists. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_verify_global_offer_quote($envelope,$campaign,$rule,$eligible_ids){
 if (!$campaign instanceof WP_Post || $campaign->post_type!=='offer-campaign' || $campaign->post_status!=='publish') return false;
 if (!is_array($rule) || !is_array($eligible_ids) || !$eligible_ids || !is_array($envelope)) return false;
 if (($envelope['verified']??null)!==true || ($envelope['authority']??'')!=='shared_product_core') return false;
 if (!function_exists('global_offer_certified_discount_valid') || !global_offer_certified_discount_valid($envelope,$eligible_ids)) return false;
 // Price-preview and caller-declared authority are insufficient. An actual core checkout
 // issuer must be installed separately and must re-derive prices server-side.
 if (!function_exists('shared_product_global_offer_checkout_verify')) return false;
 return shared_product_global_offer_checkout_verify($envelope,$campaign,$rule,$eligible_ids) === true;
}
