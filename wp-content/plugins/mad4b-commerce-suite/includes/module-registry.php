<?php
/**
 * Canonical, bounded inventory. This registry does not load other plugins.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class MAD4B_Commerce_Suite_Registry {
    public const CONTRACT = 'mad4b.commerce.modules.v1';

    public static function definitions(): array {
        return [
            'shared_product_core' => [
                'label' => 'Shared Product Core',
                'owner' => 'shared-product-core MU package',
                'detect' => ['class', 'Shared_Product_Date_Service'],
                'requires' => ['woocommerce', 'jetengine'],
                'purpose' => 'Rates, scheduling, booking configuration and financial policies',
            ],
            'cpt_product_adapter' => [
                'label' => 'CPT / WooCommerce Integration',
                'owner' => 'shared-product-core MU package',
                'detect' => ['function', 'shared_product_cpt_indicative_price'],
                'requires' => ['shared_product_core', 'woocommerce'],
                'purpose' => 'Indicative CPT prices; CPTWoo still owns product identity',
            ],
            'rate_pricing' => [
                'label' => 'Rates & Pricing',
                'owner' => 'shared-product-core MU package',
                'detect' => ['function', 'shared_product_resolve_price_unit'],
                'requires' => ['shared_product_core'],
                'purpose' => 'Rate selection, price units, audiences and market rules',
            ],
            'scheduling' => [
                'label' => 'Scheduling & Eligibility',
                'owner' => 'shared-product-core MU package',
                'detect' => ['class', 'Shared_Product_Scheduling_Eligibility'],
                'requires' => ['shared_product_core'],
                'purpose' => 'Date, itinerary and booking eligibility decisions',
            ],
            'booking_cart' => [
                'label' => 'Booking Draft & Cart Preview',
                'owner' => 'shared-product-core MU package',
                'detect' => ['function', 'shared_product_booking_v2_cart_draft'],
                'requires' => ['shared_product_core', 'woocommerce'],
                'purpose' => 'Validated booking draft, not checkout authorization',
            ],
            'financial_ledger' => [
                'label' => 'Financial Ledger & Refund Policy',
                'owner' => 'shared-product-core MU package',
                'detect' => ['function', 'shared_product_ledger_quote'],
                'requires' => ['shared_product_core'],
                'purpose' => 'Money rounding, quote evidence and financial policy',
            ],
            'dynamic_presentation' => [
                'label' => 'Dynamic Presentation',
                'owner' => 'shared-product-core MU package',
                'detect' => ['class', 'Shared_Product_Presentation_Context_v1'],
                'requires' => ['shared_product_core', 'elementor', 'jetengine'],
                'purpose' => 'Dynamic tags, listings, filters and signed query contexts',
            ],
            'form_pricing_bridge' => [
                'label' => 'Form Pricing Bridge',
                'owner' => 'shared-product-core MU package',
                'detect' => ['function', 'shared_product_form_price_breakdown'],
                'requires' => ['shared_product_core'],
                'purpose' => 'Server-side quote preview for form consumers',
            ],
            'pricing_source_registry' => [
                'label' => 'Pricing Source Registry',
                'owner' => 'mad4b-pricing-registry plugin',
                'detect' => ['class', 'MAD4B_Pricing_Source_Registry'],
                'requires' => ['woocommerce'],
                'purpose' => 'Read-only price provider discovery',
            ],
            'age_occupancy_registry' => [
                'label' => 'Age & Occupancy Registry',
                'owner' => 'mad4b-age-occupancy-registry plugin',
                'detect' => ['class', 'MAD4B_Age_Occupancy_Registry'],
                'requires' => ['woocommerce'],
                'purpose' => 'Age and room occupancy policy authority',
            ],
            'woocommerce' => [
                'label' => 'WooCommerce',
                'owner' => 'third-party',
                'detect' => ['class', 'WooCommerce'],
                'requires' => [],
                'enrollable' => false,
                'purpose' => 'Commerce and order authority; never bundled into Suite',
            ],
            'jetengine' => [
                'label' => 'JetEngine',
                'owner' => 'third-party',
                'detect' => ['class', 'Jet_Engine'],
                'requires' => [],
                'enrollable' => false,
                'purpose' => 'Existing CPT, taxonomy, meta and repeater owner',
            ],
            'elementor' => [
                'label' => 'Elementor',
                'owner' => 'third-party',
                'detect' => ['class', '\Elementor\Plugin'],
                'requires' => [],
                'enrollable' => false,
                'purpose' => 'Dynamic tags and presentation editor',
            ],
            'fluentforms' => [
                'label' => 'Fluent Forms',
                'owner' => 'third-party',
                'detect' => ['function', 'wpFluent'],
                'requires' => [],
                'enrollable' => false,
                'purpose' => 'Form input and inquiry pipeline',
            ],
        ];
    }

    public static function normalize_desired($raw): array {
        if (!is_array($raw)) {
            return [];
        }
        $allowed = array_keys(array_filter(self::definitions(), static fn($module) => ($module['enrollable'] ?? true)));
        $normalized = [];
        foreach ($raw as $value) {
            if (is_string($value) && in_array($value, $allowed, true) && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    public static function detect(array $definition): bool {
        if (count($definition['detect'] ?? []) !== 2) {
            return false;
        }
        [$type, $symbol] = $definition['detect'];
        if ($type === 'class') {
            return class_exists($symbol, false);
        }
        if ($type === 'function') {
            return function_exists($symbol);
        }
        return false;
    }

    public static function snapshot(array $desired): array {
        $definitions = self::definitions();
        $desired = self::normalize_desired($desired);
        $observed = [];
        foreach ($definitions as $id => $definition) {
            $observed[$id] = self::detect($definition);
        }
        $modules = [];
        foreach ($definitions as $id => $definition) {
            $missing = [];
            foreach ($definition['requires'] as $dependency) {
                if (empty($observed[$dependency])) {
                    $missing[] = $dependency;
                }
            }
            $modules[$id] = [
                'label' => $definition['label'],
                'owner' => $definition['owner'],
                'purpose' => $definition['purpose'],
                'desired' => in_array($id, $desired, true),
                'enrollable' => (bool)($definition['enrollable'] ?? true),
                'runtime_detected' => $observed[$id],
                'missing_dependencies' => $missing,
                'activation_authorized' => false,
                'activation_performed' => false,
            ];
        }
        return [
            'contract' => self::CONTRACT,
            'modules' => $modules,
            'runtime_mutation_performed' => false,
            'commercial_authority_changed' => false,
            'release_certified' => false,
        ];
    }
}
