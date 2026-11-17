<?php
/** Backward-compatible module entry point; generalized engine is canonical. */
defined('ABSPATH') || exit;
require_once __DIR__ . '/345-mad4b-company-native-bootstrap-313.php';
if (!class_exists('MAD4B_Wayfa_Original_Native_313_V1',false)) {
 class_alias('MAD4B_Company_Native_Bootstrap_313_V2','MAD4B_Wayfa_Original_Native_313_V1');
}
