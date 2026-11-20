<?php
/**
 * MAD4B Pricing Workspace / Currency Configuration v1.
 *
 * Catalog: native JetEngine Glossary.
 * Monetary recognition: WooCommerce.
 * Display/FX readiness: existing WCML settings.
 * Authoring scope: optional, versioned MAD4B option for NEW drafts only.
 * Does not change store currency, WCML FX or published Rate pricing.
 */
defined('ABSPATH') || exit;
final class MAD4B_Pricing_Currency_Configuration_V1 {
    public const SLUG='mad4b-pricing-currencies';
    public const OPTION='mad4b_pricing_currency_configuration_v1';
    public const SAVE='mad4b_pricing_currency_configuration_save_v1';

    public static function permitted():bool {
        return current_user_can('commerce_manage_pricing')
            &&current_user_can('manage_woocommerce');
    }
    public static function boot():void {
        if(!is_admin())return;
        add_action('admin_menu',[__CLASS__,'register'],75);
        add_action('admin_post_'.self::SAVE,[__CLASS__,'save']);
    }
    public static function register():void {
        if(!self::permitted())return;
        add_submenu_page('mad4b-pricing-workspace','Currencies & Markets',
            'Currencies & Markets','commerce_manage_pricing',self::SLUG,
            [__CLASS__,'page']);
    }
    public static function wcml():array {
        $wcml=get_option('_wcml_settings',[]);
        $wcml=is_array($wcml)?$wcml:[];
        $valid=MAD4B_JetEngine_Currency_Glossary_V1::choices();
        $base=get_option('woocommerce_currency','USD');
        $codes=[];
        foreach(array_keys((array)($wcml['currency_options']??[])) as $code) {
            if(is_string($code)&&isset($valid[$code]))$codes[$code]=$code;
        }
        if(is_string($base)&&isset($valid[$base]))$codes[$base]=$base;
        return ['enabled'=>((int)($wcml['enable_multi_currency']??0))>0,
            'currencies'=>array_values($codes),'base_currency'=>(string)$base];
    }
    public static function current():array {
        $raw=get_option(self::OPTION,[]);
        $raw=is_array($raw)?$raw:[];
        $mode=($raw['mode']??'')==='restrict'?'restrict':'inherit_woocommerce';
        $picker=($raw['picker']??'')==='wcml_only'?'wcml_only':'glossary';
        $valid=MAD4B_JetEngine_Currency_Glossary_V1::choices();
        $allowed=[];
        foreach((array)($raw['allowed']??[]) as $code)
            if(is_string($code) && isset($valid[$code]))$allowed[$code]=$code;
        $wcml=self::wcml();
        return [
            'mode'=>$mode,'picker'=>$picker,'allowed'=>array_values($allowed),
            'revision'=>max(0,(int)($raw['revision']??0)),
            'updated_at'=>(string)($raw['updated_at']??''),
            'effective_currencies'=>($mode==='restrict')
                ?array_values($allowed):array_keys($valid),
            'wcml'=>$wcml,
            'saved'=>isset($raw['mode'])
        ];
    }
    public static function authoring_choices():array {
        $settings=self::current();
        $supported=MAD4B_JetEngine_Currency_Glossary_V1::choices();
        $choices=$settings['mode']==='restrict'
            ?array_intersect_key($supported,
                array_fill_keys($settings['allowed'],true)):$supported;
        if($settings['picker']==='wcml_only')
            $choices=array_intersect_key($choices,
                array_fill_keys($settings['wcml']['currencies'],true));
        return $choices;
    }
    public static function authoring_allowed(string $code):bool {
        $source=MAD4B_JetEngine_Currency_Glossary_V1::choices();
        $code=strtoupper(trim($code));
        $conf=self::current();
        return isset($source[$code]) && (
            $conf['mode']!=='restrict'
            ||in_array($code,$conf['allowed'],true));
    }
    public static function save():void {
        if(!self::permitted())wp_die('Pricing management permission required',403);
        check_admin_referer(self::SAVE);
        $old=self::current();
        $posted=isset($_POST['revision'])?absint($_POST['revision']):-1;
        if($posted!==$old['revision'])self::redirect('conflict');
        $mode=isset($_POST['mode'])&&is_string($_POST['mode'])
            ?sanitize_key(wp_unslash($_POST['mode'])):'';
        $picker=isset($_POST['picker'])&&is_string($_POST['picker'])
            ?sanitize_key(wp_unslash($_POST['picker'])):'';
        if(!in_array($mode,['inherit_woocommerce','restrict'],true)
            ||!in_array($picker,['glossary','wcml_only'],true))
            self::redirect('invalid');
        $selected=isset($_POST['allowed'])?wp_unslash($_POST['allowed']):[];
        if(!is_array($selected)||count($selected)>200)
            self::redirect('invalid');
        $valid=MAD4B_JetEngine_Currency_Glossary_V1::choices();
        $allowed=[];
        foreach($selected as $code){
            if(!is_string($code)||!isset($valid[$code]))
                self::redirect('unsupported');
            $allowed[$code]=$code;
        }
        $base=(string)get_option('woocommerce_currency','USD');
        if($mode==='restrict'
           &&(!$allowed||!isset($allowed[$base])))
            self::redirect('base_required');
        // Existing WCML FX, WooCommerce base currency, and Tour Rates are
        // deliberately never written by this authoring configuration.
        update_option(self::OPTION,[
            'schema'=>'mad4b-currency-configuration.v1',
            'mode'=>$mode,'picker'=>$picker,
            'allowed'=>array_values($allowed),
            'revision'=>$old['revision']+1,
            'updated_at'=>gmdate('c'),
            'updated_by'=>get_current_user_id()
        ],false);
        self::redirect('saved');
    }
    private static function redirect(string $status):void {
        wp_safe_redirect(add_query_arg([
          'page'=>self::SLUG,'currency_status'=>$status
        ],admin_url('admin.php')));
        exit;
    }
    public static function page():void {
        if(!self::permitted())wp_die('Pricing management permission required',403);
        $data=self::current();
        $diag=MAD4B_JetEngine_Currency_Glossary_V1::diagnostics();
        $catalog=MAD4B_JetEngine_Currency_Glossary_V1::catalog();
        $supported=MAD4B_JetEngine_Currency_Glossary_V1::choices();
        $wcml=$data['wcml'];
        $status=sanitize_key((string)($_GET['currency_status']??''));
        $base=$wcml['base_currency'];
        $defaultAllowed=$data['saved']?$data['allowed']:$wcml['currencies'];
        echo '<div class="wrap mad4b-currency-config"><h1>Currencies & Markets</h1>';
        echo '<p class="description">Configure currency choices for new Pricing Lab and Rate Generator drafts. Existing saved Rates, WooCommerce base currency, WCML exchange rates, checkout and global market permissions are unchanged.</p>';
        if($status!==''){
            $alerts=[
                'saved'=>'Currency authoring configuration saved.',
                'conflict'=>'Settings changed in another session. Reload before saving.',
                'invalid'=>'Invalid currency configuration.',
                'unsupported'=>'Selected currency is not supported by WooCommerce and the JetEngine catalog.',
                'base_required'=>'Keep the WooCommerce base currency in the authoring allowlist.'
            ];
            echo '<div class="notice '.($status==='saved'?'notice-success':'notice-error').
                '"><p>'.esc_html($alerts[$status]??'Configuration error').'</p></div>';
        }
        echo '<div class="mad4b-currency-summary">';
        foreach([
           ['JetEngine catalog',count($catalog).' unique currencies'],
           ['WooCommerce recognized',count($supported).' available for pricing'],
           ['WCML display',implode(', ',$wcml['currencies'])],
           ['Store base',$base],
        ] as [$label,$value])
           echo '<div><small>'.esc_html($label).'</small><strong>'.
               esc_html($value).'</strong></div>';
        echo '</div>';
        echo '<section class="mad4b-currency-panel"><h2>Sources & responsibilities</h2>';
        echo '<p><strong>JetEngine Glossary #'.(int)$diag['id'].
            ':</strong> primary catalog of currency values. '.
            (int)($diag['raw_entries']??0).' original rows; '.
            (int)($diag['duplicates_collapsed']??0).' repeated codes grouped in this view. '.
            (int)($diag['non_supported_entries']??0).
            ' catalog codes are not recognized for pricing by WooCommerce.</p>';
        echo '<p><strong>WooCommerce:</strong> recognizes ISO codes and owns the base store currency. <strong>WCML:</strong> controls actual display/FX activation ('.esc_html(implode(', ',$wcml['currencies'])).'). This MAD4B page does not replace either system.</p>';
        $outside=array_diff_key($catalog,$supported);
        if($outside)
          echo '<p><strong>Catalog only, not supported for pricing:</strong> '.
            esc_html(implode(', ',array_keys($outside))).'.</p>';
        echo '</section>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="'.esc_attr(self::SAVE).'">';
        echo '<input type="hidden" name="revision" value="'.(int)$data['revision'].'">';
        wp_nonce_field(self::SAVE);
        echo '<section class="mad4b-currency-panel"><h2>Currency choice behavior</h2>';
        echo '<p>Choose which currencies appear in NEW Rate Generator and Pricing Lab menus. The native JetEngine field uses its own Glossary selector.</p>';
        echo '<label><input type="radio" name="picker" value="glossary" '.checked($data['picker'],'glossary',false).'> All WooCommerce-supported currencies from JetEngine Glossary ('.count($supported).')</label>';
        echo '<label><input type="radio" name="picker" value="wcml_only" '.checked($data['picker'],'wcml_only',false).'> Only currently enabled WCML display currencies ('.count($wcml['currencies']).')</label>';
        echo '</section>';
        echo '<section class="mad4b-currency-panel"><h2>New Rate / Pricing Lab currency eligibility</h2>';
        echo '<p><strong>Inherit WooCommerce</strong> is the default. Use a narrower allowlist only when needed. Restricting this authoring list does not invalidate existing saved Rates or change the checkout currency.</p>';
        echo '<label><input type="radio" name="mode" value="inherit_woocommerce" '.checked($data['mode'],'inherit_woocommerce',false).'> Inherit recognized currencies from WooCommerce</label>';
        echo '<label><input type="radio" name="mode" value="restrict" '.checked($data['mode'],'restrict',false).'> Restrict currencies for NEW pricing drafts</label>';
        echo '<div class="mad4b-currency-filter"><label for="mad4b-currency-search">Search currencies</label> <input id="mad4b-currency-search" type="search" placeholder="ISO code or currency name" autocomplete="off"></div>';
        echo '<div class="mad4b-currency-list" role="group" aria-label="Allowed new rate currencies">';
        foreach($supported as $code=>$label){
          $on=in_array($code,$defaultAllowed,true);
          echo '<label class="mad4b-currency-option"><input type="checkbox" name="allowed[]" value="'.esc_attr($code).'"'.checked($on,true,false).' data-currency-code="'.esc_attr($code).'"><span>'.
              esc_html($label).(in_array($code,$wcml['currencies'],true)?' · WCML':'').'</span></label>';
        }
        echo '</div><p class="description">Select at least the store currency '.esc_html($base).' when enabling restrictions. Only codes recognized by both sources can be allowed.</p>';
        echo '</section><p class="submit"><button class="button button-primary" type="submit">Save currency configuration</button></p>';
        echo '</form></div>';
    }
}
