<?php
/** Plugin Name: Responsive Currency Header Switcher (Lab) */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) {
    return;
}
function pricing_render_currency_header($force_ready = null) {
    $settings = get_option('_wcml_settings', []);
    $enabled = ($settings['enable_multi_currency'] ?? 0) == 2;
    $codes = array_values(array_intersect(['USD', 'EUR', 'EGP', 'GBP'], array_keys($settings['currency_options'] ?? [])));
    if (!$enabled || count($codes) < 2) {
        return '';
    }
    $ready = is_bool($force_ready) ? $force_ready
        : (function_exists('pricing_public_fx_snapshot') && (bool) pricing_public_fx_snapshot());
    $selected = function_exists('pricing_display_currency') ? pricing_display_currency() : 'USD';
    if (!in_array($selected, $codes, true)) {
        $selected = 'USD';
    }
    $id = wp_unique_id('pricing-currency-select-');
    $html = '<div class="pricing-currency-switcher clean-wpml' . (!$ready ? ' is-degraded' : '') . '">';
    $html .= '<label class="screen-reader-text" for="' . esc_attr($id) . '">' . esc_html__('Currency', 'woocommerce') . '</label>';
    $html .= '<select id="' . esc_attr($id) . '" class="pricing-currency-select" aria-label="Currency"' . (!$ready ? ' data-fx-degraded="1"' : '') . '>';
    foreach ($codes as $code) {
        $disabled = !$ready && $code !== 'USD';
        $html .= '<option value="' . esc_attr($code) . '"' . selected($ready ? $selected : 'USD', $code, false)
            . ($disabled ? ' disabled="disabled"' : '') . '>' . esc_html($code) . '</option>';
    }
    $html .= '</select>';
    if (!$ready) {
        $html .= '<span class="pricing-currency-status" role="status" title="Currency rates are updating. USD only.">'
            . esc_html__('Rates updating — USD only', 'woocommerce') . '</span>';
        if ($selected !== 'USD') {
            $link = add_query_arg('wcmlc', 'USD', remove_query_arg('wcmlc'));
            $html .= '<a class="pricing-currency-recover" href="' . esc_url($link) . '">' . esc_html__('Use USD', 'woocommerce') . '</a>';
        }
    }
    return $html . '</div>';
}
add_shortcode('pricing_header_switcher', 'pricing_render_currency_header');
add_action('wp_enqueue_scripts', static function () {
    wp_register_script('pricing-header-currency-js', false, [], null, true);
    wp_enqueue_script('pricing-header-currency-js');
    $js = <<<'JS'
document.addEventListener('change', function (event) {
    const select = event.target.closest('.pricing-currency-select');
    if (!select || select.dataset.fxDegraded === '1') return;
    const currency = select.value;
    if (!/^(USD|EUR|EGP|GBP)$/.test(currency)) return;
    const url = new URL(window.location.href);
    url.searchParams.set('wcmlc', currency);
    window.location.assign(url.toString());
});
JS;
    wp_add_inline_script('pricing-header-currency-js', $js);
    wp_register_style('pricing-header-currency', false, [], null);
    wp_enqueue_style('pricing-header-currency');
    wp_add_inline_style('pricing-header-currency',
        '.pricing-currency-switcher{display:inline-flex;align-items:center;gap:5px;max-width:100%;min-width:72px;direction:ltr;flex-wrap:wrap}'
        . '.pricing-currency-select{appearance:auto;-webkit-appearance:menulist;min-height:36px;width:100%;max-width:105px;font:inherit;font-size:13px;line-height:1.4;padding:5px 7px;border:1px solid currentColor;border-radius:7px;background:transparent;color:inherit;cursor:pointer}'
        . '.pricing-currency-select:focus-visible{outline:2px solid currentColor;outline-offset:2px}'
        . '.pricing-currency-select option{color:#222;background:#fff}'
        . '.pricing-currency-switcher.is-degraded .pricing-currency-status{font-size:10px;line-height:1.2;max-width:130px}'
        . '.pricing-currency-recover{font-size:11px;text-decoration:underline}'
        . '@media(max-width:1024px){.pricing-currency-select{min-height:34px;max-width:90px;font-size:12px;padding:4px 5px}}'
        . '@media(max-width:767px){.pricing-currency-select{min-height:32px;max-width:80px;font-size:12px;padding:3px 4px}.pricing-currency-switcher.is-degraded .pricing-currency-status{font-size:9px;max-width:85px}}'
    );
});
