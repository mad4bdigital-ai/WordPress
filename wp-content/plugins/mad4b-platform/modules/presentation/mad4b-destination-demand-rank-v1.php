<?php
/**
 * MAD4B Platform early-module bridge for cross-company destination ranking.
 * The full portable engine is owned by one MU file, so companies without
 * a platform plugin (e.g. Wayfatours) run the identical code, not a fork.
 */
defined('ABSPATH') || exit;
$source = WP_CONTENT_DIR . '/mu-plugins/mad4b-destination-demand-rank-v1.php';
if (is_file($source)) require_once $source;
