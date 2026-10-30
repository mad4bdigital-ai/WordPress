<?php
namespace MAD4B\DFSB\NativePresentation;

if (!defined('ABSPATH')) { exit; }

/**
 * Request-scoped, read-only composition from the real WordPress query and
 * JetSmartFilters' native SEO rules. Never synthesizes filters or writes SEO.
 */
final class SharedContext {
    private static $current = null;

    /** Pure data composition for deterministic regression tests. */
    public static function compose(array $page, array $filters = array()): array {
        $title = (string)($page['title'] ?? '');
        $description = (string)($page['description'] ?? '');
        $valid = !empty($page['resolved']);
        $matched = $valid && !empty($filters['rule_matched']) && !empty($filters['has_filters']);
        $provider = $matched ? sanitize_key((string)($filters['provider']??'')) : '';
        $query = $matched ? sanitize_key((string)($filters['query_id']??'')) : '';
        if ($matched && $provider !== '') {
            $filterTitle = trim(wp_strip_all_tags((string)($filters['title'] ?? '')));
            $filterDescription = trim(wp_strip_all_tags((string)($filters['description'] ?? '')));
            if ($filterTitle !== '') $title = $filterTitle;
            if ($filterDescription !== '') $description = $filterDescription;
        } else {
            $matched = false;
        }
        return array(
            'contract'=>'mad4b.shared-filter-context.v1',
            'read_only'=>true,
            'resolved'=>$valid,
            'page_kind'=>(string)($page['page_kind']??'unresolved'),
            'language'=>(string)($page['language']??''),
            'title'=>mb_substr($title,0,180),
            'description'=>mb_substr($description,0,800),
            'provider'=>$provider, 'query_id'=>$query,
            'filter_rule_matched'=>$matched,
            'filter_source'=>$matched?'jetsmartfilters_rule':'wordpress_native',
            'seo_publisher'=>$matched?'jetsmartfilters_native_rule':'rank_math_native',
            'seo_mutation'=>false,
        );
    }

    public static function current(): array {
        if (self::$current !== null) return self::$current;
        $native = array('resolved'=>false,'page_kind'=>'unresolved');
        if (ready()) {
            $context=context();
            $valid=in_array((string)($context['page_kind']??''),array(
                'singular','taxonomy','post_type_archive','posts_home','shop_archive'),true);
            $native=array(
                'resolved'=>$valid,
                'page_kind'=>$context['page_kind']??'unresolved',
                'language'=>$context['language']??'',
                'title'=>$valid?value('title',$context):'',
                'description'=>$valid?value('description',$context):'',
            );
        }
        $filters=array();
        // Use only the real JSF SEO frontend's exact rule/provider/query resolution.
        // AJAX requests do not publish server-side SEO and cannot become page context.
        if (!is_admin() && !wp_doing_ajax() && function_exists('jet_smart_filters')) {
            $plugin=jet_smart_filters();
            $frontend=$plugin->seo->frontend??null;
            if (!empty($plugin->settings->is_seo_enabled) && $frontend
                && method_exists($frontend,'get_current_rules')
                && method_exists($frontend,'get_current_provider_data')
                && method_exists($frontend,'get_current_query')) {
                $rules=(array)$frontend->get_current_rules();
                $provider=(array)$frontend->get_current_provider_data();
                $query=(array)$frontend->get_current_query();
                if ($rules && $query && count($provider)>0
                    && method_exists($frontend,'has_active_rule_filters')
                    && $frontend->has_active_rule_filters()) {
                    $filters=array(
                        'rule_matched'=>true,
                        'has_filters'=>true,
                        'provider'=>$provider[0]??'',
                        'query_id'=>$provider[1]??'',
                        'title'=>method_exists($frontend,'get_current_title') ?
                            $frontend->get_current_title():'',
                        'description'=>method_exists($frontend,'get_current_description') ?
                            $frontend->get_current_description():'',
                    );
                }
            }
        }
        self::$current=self::compose($native,$filters);
        return self::$current;
    }

    public static function field(string $key): string {
        $allowed=array('title','description','language','page_kind','provider','query_id','seo_publisher');
        return in_array($key,$allowed,true)?(string)(self::current()[$key]??''):'';
    }

    public static function register(): void {
        add_filter('mad4b/shared-context/read',array(__CLASS__,'project'),10,1);
        add_shortcode('mad4b_shared_title',array(__CLASS__,'titleShortcode'));
        add_shortcode('mad4b_shared_description',array(__CLASS__,'descriptionShortcode'));
    }

    /** Native JetEngine listing macro contract: no custom SQL or Query Builder. */
    public static function jetMacros($macros): array {
        if (!is_array($macros)) return array();
        $macros['mad4b_shared_title']=array(
            'label'=>'MAD4B Shared Title',
            'cb'=>array(__CLASS__,'macroTitle'),
        );
        $macros['mad4b_shared_description']=array(
            'label'=>'MAD4B Shared Description',
            'cb'=>array(__CLASS__,'macroDescription'),
        );
        $macros['mad4b_shared_language']=array(
            'label'=>'MAD4B Shared Language',
            'cb'=>array(__CLASS__,'macroLanguage'),
        );
        return $macros;
    }
    public static function macroTitle($args=array()): string {
        return esc_html(self::field('title'));
    }
    public static function macroDescription($args=array()): string {
        return esc_html(self::field('description'));
    }
    public static function macroLanguage($args=array()): string {
        return esc_html(self::field('language'));
    }

    public static function project($original) {
        return self::current();
    }
    public static function titleShortcode(): string {
        $data=self::current();
        $class=$data['filter_rule_matched']?' jet-smart-filters-seo-rules-title':'';
        return '<span class="mad4b-shared-context-title'.$class.'">'.
            esc_html($data['title']).'</span>';
    }
    public static function descriptionShortcode(): string {
        $data=self::current();
        $class=$data['filter_rule_matched']?' jet-smart-filters-seo-rules-description':'';
        return '<span class="mad4b-shared-context-description'.$class.'">'.
            esc_html($data['description']).'</span>';
    }
}
