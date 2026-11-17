<?php
/** Original Shared Product Core financial field enum. Data-only, shared by presentation and commerce runtimes. */
defined('ABSPATH') || exit;
if (!function_exists('shared_product_financial_field_choices')) {
function shared_product_financial_field_choices(){return [
 'tax_price_basis'=>['exclusive','inclusive'],
 'discount_tax_order'=>['before_tax','after_tax'],
 'payment_fee_taxable'=>['yes','no'],
 'payment_fee_bearer'=>['customer','merchant','split'],
 'discount_scope'=>['all','base_only','selected_components','exclude_selected_components','none'],
 'refund_allocation'=>['proportional','line_based','nonrefundable_components'],
 'refund_payment_fee'=>['refundable','nonrefundable','proportional'],
 ];}
}
