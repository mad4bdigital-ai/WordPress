<?php
/**
 * Plugin Name: MU Plugins Control
 * Description: Central switches for diagnostic/workaround MU plugins. Edit this file instead of renaming MU plugin files.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * Production/default compatibility settings.
 * Change true/false here when you want to disable a workaround temporarily.
 */

// Elementor dynamic-tag array logging. Keep false unless debugging.
if ( ! defined( 'TK_ELEMENTOR_DTAG_PROBE' ) ) {
    define( 'TK_ELEMENTOR_DTAG_PROBE', false );
}
if ( ! defined( 'TK_ELEMENTOR_DTAG_PROBE_LOG_MAX_CHARS' ) ) {
    define( 'TK_ELEMENTOR_DTAG_PROBE_LOG_MAX_CHARS', 3000 );
}

// Admin missing dependency recovery for jquery-ui-core / pointer scripts.
if ( ! defined( 'TK_FIX_JQUERY_UI_CORE_ADMIN_ENABLED' ) ) {
    define( 'TK_FIX_JQUERY_UI_CORE_ADMIN_ENABLED', true );
}
if ( ! defined( 'TK_FIX_JQUERY_UI_CORE_ADMIN_POINTER_ENABLED' ) ) {
    define( 'TK_FIX_JQUERY_UI_CORE_ADMIN_POINTER_ENABLED', true );
}

// JetEngine missing jet-engine-blocks-views handle shim.
if ( ! defined( 'TK_JE_FIX_MISSING_DEPS_ENABLED' ) ) {
    define( 'TK_JE_FIX_MISSING_DEPS_ENABLED', true );
}
if ( ! defined( 'TK_JE_FIX_MISSING_DEPS_EMPTY_FALLBACK' ) ) {
    define( 'TK_JE_FIX_MISSING_DEPS_EMPTY_FALLBACK', true );
}

// WPML object-id emergency sanitizer. Disable after root cause is fixed.
if ( ! defined( 'TK_WPML_OBJECT_ID_SANITIZE_ENABLED' ) ) {
    define( 'TK_WPML_OBJECT_ID_SANITIZE_ENABLED', true );
}
if ( ! defined( 'TK_WPML_OBJECT_ID_SANITIZE_LOG' ) ) {
    define( 'TK_WPML_OBJECT_ID_SANITIZE_LOG', false );
}

// WPML String Translation debounce. Monitor after translation/cache changes.
if ( ! defined( 'TK_WPML_ST_DEBOUNCE_ENABLED' ) ) {
    define( 'TK_WPML_ST_DEBOUNCE_ENABLED', true );
}
if ( ! defined( 'TK_WPML_ST_DEBOUNCE_LOG' ) ) {
    define( 'TK_WPML_ST_DEBOUNCE_LOG', false );
}

// TripKlik Meta Admin Toolkit. Powerful admin tool; keep disabled until needed.
if ( ! defined( 'TK_META_ADMIN_TOOLKIT_ENABLED' ) ) {
    define( 'TK_META_ADMIN_TOOLKIT_ENABLED', false );
}
if ( ! defined( 'TK_META_ADMIN_ALLOW_PRIVATE_KEYS' ) ) {
    define( 'TK_META_ADMIN_ALLOW_PRIVATE_KEYS', false );
}

// Taxonomy Count Tools. Powerful repair tool; keep disabled until needed.
if ( ! defined( 'NK_TAX_COUNT_TOOLS_ENABLED' ) ) {
    define( 'NK_TAX_COUNT_TOOLS_ENABLED', false );
}
if ( ! defined( 'NK_TAX_COUNT_ALLOW_FULL_CACHE_PURGE' ) ) {
    define( 'NK_TAX_COUNT_ALLOW_FULL_CACHE_PURGE', false );
}
if ( ! defined( 'NK_TAX_COUNT_DEBUG_LIMIT' ) ) {
    define( 'NK_TAX_COUNT_DEBUG_LIMIT', 500 );
}

// Admin media helper tools converted from Code Snippets exports.
if ( ! defined( 'TK_ADMIN_MEDIA_TOOLS_ENABLED' ) ) {
    define( 'TK_ADMIN_MEDIA_TOOLS_ENABLED', true );
}
if ( ! defined( 'TK_MEDIA_FOLDER_MENU_ENABLED' ) ) {
    define( 'TK_MEDIA_FOLDER_MENU_ENABLED', true );
}
if ( ! defined( 'TK_RESTORE_UPLOADED_FILTER_ENABLED' ) ) {
    define( 'TK_RESTORE_UPLOADED_FILTER_ENABLED', true );
}
if ( ! defined( 'TK_SELECTED_MEDIA_TO_TOP_ENABLED' ) ) {
    define( 'TK_SELECTED_MEDIA_TO_TOP_ENABLED', false );
}
if ( ! defined( 'TK_ADMIN_MEDIA_TOOLS_POST_TYPES' ) ) {
    define( 'TK_ADMIN_MEDIA_TOOLS_POST_TYPES', '' ); // Empty = all post edit screens. Example: tours-and-activities,properties
}

// Classic Editor taxonomy meta-box search helper.
if ( ! defined( 'TK_ADMIN_TAXONOMY_SEARCH_ENABLED' ) ) {
    define( 'TK_ADMIN_TAXONOMY_SEARCH_ENABLED', true );
}

// WPML cruise_runs repeater sync. Disabled by default because it writes translated post meta.
if ( ! defined( 'TK_WPML_CRUISE_RUNS_SYNC_ENABLED' ) ) {
    define( 'TK_WPML_CRUISE_RUNS_SYNC_ENABLED', false );
}
if ( ! defined( 'TK_WPML_CRUISE_RUNS_SYNC_POST_TYPES' ) ) {
    define( 'TK_WPML_CRUISE_RUNS_SYNC_POST_TYPES', 'tours-and-activities' );
}
if ( ! defined( 'TK_WPML_CRUISE_RUNS_SYNC_META_KEY' ) ) {
    define( 'TK_WPML_CRUISE_RUNS_SYNC_META_KEY', 'cruise_runs' );
}
if ( ! defined( 'TK_WPML_CRUISE_RUNS_SYNC_OVERWRITE' ) ) {
    define( 'TK_WPML_CRUISE_RUNS_SYNC_OVERWRITE', true );
}


// Tour origin meta sync migrated from Astra child theme functions.php.
if ( ! defined( 'TK_TOUR_ORIGIN_SYNC_ENABLED' ) ) {
    define( 'TK_TOUR_ORIGIN_SYNC_ENABLED', true );
}
if ( ! defined( 'TK_TOUR_ORIGIN_SYNC_POST_TYPE' ) ) {
    define( 'TK_TOUR_ORIGIN_SYNC_POST_TYPE', 'tours-and-activities' );
}
if ( ! defined( 'TK_TOUR_ORIGIN_SYNC_TAXONOMY' ) ) {
    define( 'TK_TOUR_ORIGIN_SYNC_TAXONOMY', 'destinations' );
}
if ( ! defined( 'TK_TOUR_ORIGIN_SYNC_REFERENCE_LANG' ) ) {
    define( 'TK_TOUR_ORIGIN_SYNC_REFERENCE_LANG', 'en' );
}
if ( ! defined( 'TK_TOUR_ORIGIN_SYNC_DEBUG' ) ) {
    define( 'TK_TOUR_ORIGIN_SYNC_DEBUG', false );
}

// Tour date filter / Flatpickr locales / cruise itinerary frontend data.
if ( ! defined( 'SHARED_PRODUCT_PROGRAM_ASSETS_ENABLED' ) ) {
    define( 'SHARED_PRODUCT_PROGRAM_ASSETS_ENABLED', true );
}
if ( ! defined( 'SHARED_PRODUCT_PROGRAM_POST_TYPE' ) ) {
    define( 'SHARED_PRODUCT_PROGRAM_POST_TYPE', 'tours-and-activities' );
}
if ( ! defined( 'SHARED_PRODUCT_PROGRAM_WEEKDAY_TAXONOMY' ) ) {
    define( 'SHARED_PRODUCT_PROGRAM_WEEKDAY_TAXONOMY', 'schudle-days-of-weak' );
}
if ( ! defined( 'SHARED_PRODUCT_PROGRAM_REFERENCE_LANGUAGE' ) ) {
    define( 'SHARED_PRODUCT_PROGRAM_REFERENCE_LANGUAGE', 'en' );
}
if ( ! defined( 'SHARED_PRODUCT_PROGRAM_LEGACY_CALENDAR_SCRIPT' ) ) {
    define( 'SHARED_PRODUCT_PROGRAM_LEGACY_CALENDAR_SCRIPT', 'trip-date-filter.js' );
}
if ( ! defined( 'SHARED_PRODUCT_PROGRAM_LOAD_CALENDAR_LOCALES' ) ) {
    define( 'SHARED_PRODUCT_PROGRAM_LOAD_CALENDAR_LOCALES', true );
}
