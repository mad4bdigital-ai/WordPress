<?php
/**
 * Plugin Name: MAD4B Company Policy Library (JetEngine + Elementor)
 * Description: Company-owned, language-aware policy text sourced from a JetEngine Options Page.
 * Version: 1.0.0
 * Author: MAD4B
 */

defined('ABSPATH') || exit;

if (!function_exists('mad4b_company_policy_types')) {
    function mad4b_company_policy_types() {
        return [
            'cancellation' => 'Cancellation & Refunds',
            'children' => 'Children & Ages',
            'booking' => 'Booking & Deposits',
            'accommodation' => 'Accommodation',
            'pricing' => 'Prices & Exclusions',
            'tipping' => 'Tipping & Gratuities',
            'force_majeure' => 'Force Majeure',
            'general' => 'General Travel Terms',
        ];
    }
}
if (!function_exists('mad4b_company_policy_locales')) {
    function mad4b_company_policy_locales() {
        return ['en'=>'English','ar'=>'Arabic','fr'=>'French','de'=>'German','it'=>'Italian','es'=>'Spanish'];
    }
}

/**
 * Register a source-controlled JetEngine Options Page. No default policy values
 * are seeded and no other company or demo policy is ever imported.
 */
add_action('init', static function () {
    if (!function_exists('jet_engine')) {
        return;
    }
    $manager = jet_engine()->options_pages ?? null;
    if (!is_object($manager) || !method_exists($manager, 'register_new_options_page')) {
        return;
    }
    $slug = 'mad4b-company-policy-library';
    if (isset($manager->registered_pages[$slug])) {
        return;
    }

    $fields = [[
        'name'=>'policy_info',
        'title'=>'Company-owned policies — one WordPress site per company',
        'type'=>'html','object_type'=>'field','id'=>910000,'width'=>'100%',
        'html'=>'<p>Draft by default. Approve only verified, company-owned terms with a revision. No company inherits another company\'s fees, dates, legal promises or provider accounts. Text is independent for each WPML language.</p>',
    ]];
    $number = 910001;
    foreach (mad4b_company_policy_types() as $key => $name) {
        $fields[] = ['name'=>$key.'_approval','title'=>$name.' — publication status',
            'type'=>'select','object_type'=>'field','id'=>$number++,'width'=>'50%',
            'options_source'=>'manual','options'=>[
                ['key'=>'draft','value'=>'Draft — not a binding offer'],
                ['key'=>'approved','value'=>'Approved for this company and locale'],
            ],
            'description'=>'Approved requires a nonempty reviewed revision and localized policy text.'];
        $fields[] = ['name'=>$key.'_revision','title'=>$name.' — approved revision ID',
            'type'=>'text','object_type'=>'field','id'=>$number++,'width'=>'50%',
            'description'=>'Company-approved reference/revision, not another tenant\'s policy ID.'];
        foreach (mad4b_company_policy_locales() as $locale => $label) {
            $fields[] = ['name'=>$key.'_'.$locale,'title'=>$name.' — '.$label,
                'type'=>'wysiwyg','object_type'=>'field','id'=>$number++,'width'=>'100%',
                'description'=>'Company-specific approved text. {{company_name}} inserts the CURRENT public site name. Leave blank until reviewed.'];
        }
    }
    $manager->register_new_options_page([
        'id'=>910000,'slug'=>$slug,
        'labels'=>['name'=>'Company Customer Policies','menu_name'=>'Customer Policies'],
        'parent'=>'mad4b-company','icon'=>'dashicons-media-text',
        'capability'=>'mad4b_manage_company_operations','position'=>86,
        'storage_type'=>'default','option_prefix'=>true,'autoload_option'=>false,
        'hide_field_names'=>false,'fields'=>$fields,
    ]);
}, 90);

if (!function_exists('mad4b_company_policy_html')) {
    /**
     * Render approved, language-exact company content with an explicit safe
     * unconfigured state. No fallback to foreign tenant or another language's
     * financial/legal terms.
     */
    function mad4b_company_policy_html($type) {
        $types = mad4b_company_policy_types();
        if (!is_string($type) || !isset($types[$type])) {
            return '';
        }
        $locale = apply_filters('wpml_current_language', null);
        if (!is_string($locale) || !isset(mad4b_company_policy_locales()[$locale])) {
            $locale = substr((string)get_locale(), 0, 2);
        }
        if (!isset(mad4b_company_policy_locales()[$locale])) {
            $locale = 'en';
        }

        $options = get_option('mad4b-company-policy-library', []);
        $options = is_array($options) ? $options : [];
        $content = $options[$type.'_'.$locale] ?? '';
        $is_approved = ($options[$type.'_approval'] ?? '') === 'approved';
        $has_revision = is_string($options[$type.'_revision'] ?? null) &&
            trim($options[$type.'_revision']) !== '';
        if ($is_approved && $has_revision && is_string($content) && trim(wp_strip_all_tags($content)) !== '') {
            $public_name = function_exists('mad4b_company_template_public_name')
                ? mad4b_company_template_public_name()
                : trim(wp_strip_all_tags((string)get_option('blogname', '')));
            $content = str_replace('{{company_name}}', esc_html($public_name), $content);
            return wp_kses_post($content);
        }

        $neutral = [
            'en'=>'Company-specific policy details have not yet been confirmed. Please verify terms before booking.',
            'ar'=>'لم يتم تأكيد تفاصيل سياسة الشركة بعد. يُرجى التحقق من الشروط قبل الحجز.',
            'fr'=>'Les conditions propres à cette société ne sont pas encore confirmées. Veuillez les vérifier avant de réserver.',
            'de'=>'Die unternehmensspezifischen Bedingungen sind noch nicht bestätigt. Bitte vor der Buchung prüfen.',
            'it'=>'Le condizioni specifiche della società non sono ancora confermate. Verificale prima di prenotare.',
            'es'=>'Las condiciones específicas de la empresa aún no están confirmadas. Verifícalas antes de reservar.',
        ];
        return '<p>'.esc_html($neutral[$locale]).'</p>';
    }
}

add_shortcode('mad4b_company_policy', static function ($atts) {
    $atts = shortcode_atts(['type'=>'general'], $atts, 'mad4b_company_policy');
    return mad4b_company_policy_html($atts['type']);
});

/** Native Elementor selectable dynamic text tag, backed by JetEngine Options. */
add_action('elementor/dynamic_tags/register', static function ($tags_manager) {
    if (!class_exists('Elementor\\Core\\DynamicTags\\Tag')) {
        return;
    }
    if (!class_exists('MAD4B_Company_Policy_Dynamic_Tag', false)) {
        class MAD4B_Company_Policy_Dynamic_Tag extends \Elementor\Core\DynamicTags\Tag {
            public function get_name() { return 'mad4b-company-policy'; }
            public function get_title() { return 'MAD4B Company Policy (JetEngine Options)'; }
            public function get_group() { return 'site'; }
            public function get_categories() {
                return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];
            }
            protected function register_controls() {
                $this->add_control('policy_key', [
                    'label'=>'Policy category',
                    'type'=>\Elementor\Controls_Manager::SELECT,
                    'options'=>mad4b_company_policy_types(),
                    'default'=>'cancellation',
                ]);
            }
            public function render() {
                echo mad4b_company_policy_html($this->get_settings('policy_key'));
            }
        }
    }
    $tags_manager->register(new MAD4B_Company_Policy_Dynamic_Tag());
});
