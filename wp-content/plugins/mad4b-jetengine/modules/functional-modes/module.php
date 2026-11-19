<?php
defined('ABSPATH') || exit;
return [
    'id' => 'functional-modes',
    'label' => 'Functional Field Modes',
    'version' => '1.6.0',
    'default_enabled' => true,
    'dependencies' => [],
    'boot' => static function (): void {
        require_once __DIR__ . '/class-guidance.php';
        require_once __DIR__ . '/class-contextual-help-registry.php';
        require_once __DIR__ . '/class-provider-price-sources.php';
        require_once __DIR__ . '/class-provider-jetengine-bridge.php';
        require_once __DIR__ . '/class-repeater-overview.php';
        require_once __DIR__ . '/class-page-recipes.php';
        require_once __DIR__ . '/class-recipe-field-matrix.php';
        require_once __DIR__ . '/class-recipe-workspace.php';
        require_once __DIR__ . '/class-recipe-registry.php';
        require_once __DIR__ . '/class-native-wp-screen-coverage.php';
        require_once __DIR__ . '/class-native-wordpress-ui-inventory.php';
        require_once __DIR__ . '/class-native-wp-settings-adapter.php';
        require_once __DIR__ . '/class-field-modes.php';
        require_once __DIR__ . '/class-modern-meta-editor.php';
        require_once __DIR__ . '/class-frontend-form-recipe.php';
        require_once __DIR__ . '/class-tour-preview-filter-registry.php';
        require_once __DIR__ . '/class-currency-glossary.php';
        require_once __DIR__ . '/class-currency-configuration.php';
        require_once __DIR__ . '/class-strategy-schema-bridge.php';
        require_once __DIR__ . '/class-published-tours-preview.php';
        require_once __DIR__ . '/class-price-group-controller.php';
        require_once __DIR__ . '/class-affected-tour-strategy.php';
        require_once __DIR__ . '/class-pricing-controls-access.php';
        require_once __DIR__ . '/class-pricing-dimensions.php';
        require_once __DIR__ . '/class-hybrid-pricing-policies.php';
        require_once __DIR__ . '/class-hybrid-pricing-admin.php';
        require_once __DIR__ . '/class-pricing-workspace.php';
        require_once __DIR__ . '/class-hybrid-rate-generator-bridge.php';
        require_once __DIR__ . '/class-pricing-lab.php';
        require_once __DIR__ . '/class-rate-season-term-matcher.php';
        require_once __DIR__ . '/class-rate-title-override.php';
        MAD4B_JetEngine_Affected_Tour_Strategy_V1::boot();
        MAD4B_Pricing_Workspace_V1::boot();
        MAD4B_JetEngine_Pricing_Controls_Access_V1::boot();
        MAD4B_JetEngine_Pricing_Dimensions_V1::boot();
        MAD4B_Pricing_Contextual_Help_V1::boot();
        MAD4B_Provider_Price_Sources_V1::boot();
        MAD4B_Provider_JetEngine_Bridge_V1::boot();
        MAD4B_JetEngine_Currency_Glossary_V1::boot();
        MAD4B_Pricing_Currency_Configuration_V1::boot();
        MAD4B_JetEngine_Pricing_Lab_V1::boot();
        MAD4B_Hybrid_Pricing_Policies_V1::boot();
        MAD4B_Hybrid_Pricing_Admin_V1::boot();
        MAD4B_Hybrid_Rate_Generator_Bridge_V1::boot();
        MAD4B_JetEngine_Rate_Season_Term_Matcher_V1::boot();
        MAD4B_JetEngine_Rate_Title_Override_V1::boot();
        MAD4B_JetEngine_Price_Group_Controller_V1::boot();
        MAD4B_JetEngine_Published_Tours_Preview_V1::boot();
        MAD4B_JetEngine_Repeater_Overview_V1::boot();
        MAD4B_JetEngine_Field_Modes_V1::boot(__DIR__);
    },
];
