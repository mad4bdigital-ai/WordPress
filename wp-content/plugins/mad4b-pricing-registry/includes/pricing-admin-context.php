<?php
defined('ABSPATH') || exit;
/** Presentation context only. Never a permission or native-save decision. */
final class MAD4B_Pricing_Admin_Context_V1 {
    public static function matches(string $page): bool {
        if(class_exists('MAD4B_Pricing_Page_Navigation_V1',false)
            && class_exists('MAD4B_JetEngine_Pricing_Controls_Access_V1',false))
            return MAD4B_Pricing_Page_Navigation_V1::matches($page);
        // Compatibility for independent registry installations.
        $pages=apply_filters('mad4b/pricing_registry/admin_pages',['dynamic-pricing-controls']);
        return is_array($pages)&&in_array($page,$pages,true);
    }
}
