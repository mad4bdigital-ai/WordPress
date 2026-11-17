<?php
// Original ordered Shared Product Core source lines 28–40; eager until separately certified.
if (!defined('ABSPATH')) exit;
require_once $root.'pricing/financial-inheritance.php';
require_once $root.'pricing/financial-ledger.php';
require_once $root.'pricing/financial-order-snapshot.php';
require_once $root.'pricing/global-offer-verifier.php';
require_once $root.'pricing/global-offer-price-evidence.php';
require_once $root.'pricing/global-offer-checkout.php';
require_once $root.'pricing/global-offer-coupon.php';
require_once $root.'pricing/financial-order-adapter.php';
require_once $root.'pricing/financial-refund-lock.php';
require_once $root.'pricing/refund-provider-adapter.php';
require_once $root.'pricing/refund-provider-recheck.php';
if ($mad4b_shared_core_admin_registrars) require_once $root.'admin/woocommerce-refund-recheck.php';
if ($mad4b_shared_core_admin_registrars) require_once $root.'admin/woocommerce-financial-panel.php';
