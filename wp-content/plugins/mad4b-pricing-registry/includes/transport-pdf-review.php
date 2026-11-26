<?php
/**
 * Administration readback of supplier PDF transfer operations, per WPML language.
 *
 * This displays source amounts for authorized pricing staff only.
 * It is NOT an availability endpoint, currency converter, supplier quote,
 * pricing provider, or payment/checkout authority.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/pricing-admin-context.php';
final class MAD4B_Transport_PDF_Review_V1 {
    public const CONTRACT='mad4b.transport-pdf-review.v1';
    private const VEHICLE_NAMES=array(
        'en'=>array('limousine'=>'Limousine','luxury_7_seats'=>'Luxury 7 seats','h1'=>'H1 (unpriced)','hiace'=>'Hiace','coaster'=>'Coaster','bus'=>'Bus'),
        'ar'=>array('limousine'=>'ليموزين','luxury_7_seats'=>'سيارة فاخرة 7 مقاعد','h1'=>'H1 (بدون سعر)','hiace'=>'هاي أس','coaster'=>'كوستر','bus'=>'باص'),
        'es'=>array('limousine'=>'Limusina','luxury_7_seats'=>'Lujo 7 plazas','h1'=>'H1 (sin tarifa)','hiace'=>'Hiace','coaster'=>'Coaster','bus'=>'Autobús'),
        'fr'=>array('limousine'=>'Limousine','luxury_7_seats'=>'Luxe 7 places','h1'=>'H1 (sans tarif)','hiace'=>'Hiace','coaster'=>'Coaster','bus'=>'Autocar'),
        'de'=>array('limousine'=>'Limousine','luxury_7_seats'=>'Luxus 7 Sitze','h1'=>'H1 (ohne Preis)','hiace'=>'Hiace','coaster'=>'Coaster','bus'=>'Reisebus'),
        'it'=>array('limousine'=>'Limousine','luxury_7_seats'=>'Lusso 7 posti','h1'=>'H1 (senza prezzo)','hiace'=>'Hiace','coaster'=>'Coaster','bus'=>'Pullman'));
    public static function permitted(): bool {
        return current_user_can('manage_woocommerce') || current_user_can('commerce_manage_pricing');
    }
    public static function active_languages(): array {
        $active=apply_filters('wpml_active_languages',null,array('skip_missing'=>0));
        $allowed=array('en','ar','fr','de','es','it');
        return array_values(array_intersect(array_keys(is_array($active)?$active:array()),$allowed));
    }
    public static function rows(string $city='',string $lang='en'): array {
        $cities=array('','Cairo','Aswan','Luxor');
        if (!in_array($city,$cities,true)) $city='';
        if (!in_array($lang,self::active_languages(),true)) $lang='en';
        $d=require WP_PLUGIN_DIR.'/mad4b-pricing-registry/data/transport-pdf-2026-localized.php';
        if (($d['contract']??'')!=='mad4b.transfer-pdf-2026-localized.v1' ||
            count($d['rows']??[])!==51) return array();
        $sourceIds=array();
        $ids=get_posts(array('post_type'=>'transportations','post_status'=>array('draft','publish'),
            'posts_per_page'=>650,'fields'=>'ids','suppress_filters'=>true,
            'meta_key'=>'_mad4b_transfer_pdf_2026_sha256',
            'meta_value'=>$d['source_sha256']));
        foreach($ids as $id) {
            $key=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_source_key',true);
            $postLang=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_editorial_language',true);
            if (!$key || !$postLang || $postLang!==$lang) continue;
            if(!isset($sourceIds[$key])) $sourceIds[$key]=array();
            $sourceIds[$key][]=(int)$id;
        }
        $media=get_option('mad4b_transfer_pdf_2026_vehicle_media_map_v1',array());
        $rows=array();
        foreach ($d['rows'] as $row) {
            if ($city!==''&&$row['city']!==$city)continue;
            $found=$sourceIds[$row['source_key']]??array();
            $views=array();
            foreach ($row['vehicle_categories'] as $key) {
                $id=(int)($media[$key]??0);
                $price=$row['vehicle_rates_unverified'][$key]??null;
                $views[$key]=array('category'=>self::VEHICLE_NAMES[$lang][$key]??$key,
                   'source_amount'=>$price,'price_status'=>$price===null?'unpriced':'unverified_no_currency',
                   'image_id'=>$id,'image_url'=>$id?wp_get_attachment_image_url($id,'medium'):null,
                   'alt'=>$id?get_post_meta($id,'_mad4b_transport_media_alt_'.$lang,true):'');
            }
            $rows[]=array('source_key'=>$row['source_key'],
                'source_row'=>$row['source_row'],'origin'=>$row['city'],
                'title'=>(string)$row['titles'][$lang],
                'original_ar'=>(string)$row['source_ar'],
                'service_kind'=>$row['service_kind'],
                'service_note'=>$row['service_note'],
                'supplier_prices'=>$views,
                'draft_ids'=>$found,'draft_count'=>count($found),
                'source_post_ids'=>$found,'source_post_count'=>count($found),
                'editorial_status'=>count($found)===1?get_post_status($found[0]):'missing_or_duplicate',
                'review_status'=>count($found)===1
                    ?(get_post_status($found[0])==='publish' &&
                      get_post_meta($found[0],'_mad4b_pdf_editorial_publication_v1',true)==='mad4b.transport-pdf-editorial.v1'
                        ?'localized_editorial_published'
                        :(get_post_status($found[0])==='draft'
                           ?'localized_draft_exists'
                           :'published_without_publication_provenance'))
                    :'missing_or_duplicate_source_posts',
                'contractual_status'=>'source_currency_and_sale_role_unconfirmed',
                'quality_flags'=>$row['quality_flags'],
                'source_date'=>$row['effective_from'],
                'discount_note'=>$row['supplier_discount_note'],
                'publish_authorized'=>false,'bookable'=>false);
        }
        return $rows;
    }
    public static function snapshot(string $city='',string $lang='en'): array {
        $langs=self::active_languages();
        if (!in_array($lang,$langs,true))$lang='en';
        $rows=self::rows($city,$lang);
        $missing=0;
        foreach ($rows as $row) if ($row['source_post_count']!==1 ||
            !in_array($row['review_status'],array('localized_draft_exists',
                'localized_editorial_published'),true))++$missing;
        return array('contract'=>self::CONTRACT,'site'=>home_url('/'),
            'active_languages'=>$langs,'selected_language'=>$lang,
            'city'=>$city,'count'=>count($rows),'review_issues'=>$missing,
            'rows'=>$rows,
            'editorial_published_count'=>count(array_filter($rows,
                static fn($r)=>($r['review_status']??'')==='localized_editorial_published')),
            'source_pricing_is_verified'=>false,
            'price_role_confirmed'=>false,'published'=>false,
            'mutation_performed'=>false,'checkout_authorized'=>false);
    }
    public static function rest(): void {
        register_rest_route('mad4b-pricing/v1','/transport-pdf-2026-review',array(
            'methods'=>'GET',
            'callback'=>static function($request) {
                return rest_ensure_response(self::snapshot(
                    sanitize_text_field((string)$request->get_param('city')),
                    sanitize_key((string)($request->get_param('lang')?:'en'))));
            },
            'permission_callback'=>array(__CLASS__,'permitted')));
    }
    public static function menu(): void {
        add_management_page('2026 Transfer Source Review',
            '2026 Transfer Source Review','manage_woocommerce',
            'mad4b-transfer-pdf-2026-review',array(__CLASS__,'render'));
    }
    public static function pricing_link(): void {
        if (!self::permitted() || !isset($_GET['page']))return;
        $page=sanitize_key((string)wp_unslash($_GET['page']));
        if (!MAD4B_Pricing_Admin_Context_V1::matches($page))return;
        echo '<div class="notice notice-info"><p><strong>Transfer 2026:</strong> <a href="'.
         esc_url(admin_url('tools.php?page=mad4b-transfer-pdf-2026-review')).
         '">View the localized 51-source-operation review with vehicle photos and unverified rate cells</a>.</p></div>';
    }
    public static function render(): void {
        if (!self::permitted())wp_die(esc_html__('Not allowed.','mad4b'));
        $city=isset($_GET['transport_city'])?sanitize_text_field(wp_unslash((string)$_GET['transport_city'])):'';
        if (!in_array($city,array('','Cairo','Aswan','Luxor'),true))$city='';
        $lang=isset($_GET['transport_lang'])?sanitize_key(wp_unslash((string)$_GET['transport_lang'])):'en';
        if (!in_array($lang,self::active_languages(),true))$lang='en';
        $snap=self::snapshot($city,$lang);
        echo '<div class="wrap"><h1>2026 Transfer Source Review</h1>';
        echo '<p><strong>51 supplier operations</strong> from the PDF. Source records are language-linked, whether Draft or editorial-published; the source currency, cost/sale role, vehicle fleet, contract end date and booking availability are not verified. Prices below are RAW SOURCE VALUES ONLY, NOT SELLING PRICES.</p>';
        echo '<form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-bottom:18px">';
        echo '<input type="hidden" name="page" value="mad4b-transfer-pdf-2026-review">';
        echo '<label>Origin city <select name="transport_city">';
        foreach(array(''=>'All origins','Cairo'=>'Cairo','Aswan'=>'Aswan','Luxor'=>'Luxor') as $v=>$name)
            echo '<option value="'.esc_attr($v).'" '.selected($v,$city,false).'>'.esc_html($name).'</option>';
        echo '</select></label>';
        echo '<label>Language <select name="transport_lang">';
        foreach(self::active_languages() as $l)
            echo '<option value="'.esc_attr($l).'" '.selected($l,$lang,false).'>'.esc_html(strtoupper($l)).'</option>';
        echo '</select></label><button class="button button-primary" type="submit">Apply</button></form>';
        $media=get_option('mad4b_transfer_pdf_2026_vehicle_media_map_v1',array());
        echo '<h2>Vehicle Category Illustrations — Not Supplier Fleet Photos</h2>';
        echo '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:25px">';
        foreach (array('limousine','luxury_7_seats','hiace','coaster','bus') as $vehicle) {
            $id=(int)($media[$vehicle]??0);
            if (!$id) continue;
            $url=wp_get_attachment_image_url($id,'medium');
            $alt=(string)get_post_meta($id,'_mad4b_transport_media_alt_'.$lang,true);
            echo '<div style="width:170px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:8px">';
            echo '<img loading="lazy" src="'.esc_url($url).'" alt="'.esc_attr($alt).'" style="width:100%;height:106px;object-fit:cover;border-radius:4px">';
            echo '<strong>'.esc_html(self::VEHICLE_NAMES[$lang][$vehicle]??$vehicle).'</strong>';
            echo '<p style="font-size:11px;margin:4px 0">'.esc_html(
                get_post_meta($id,'_mad4b_transport_media_creator',true)).' · '.
                esc_html(get_post_meta($id,'_mad4b_transport_media_license',true)).'</p>';
            echo '<a href="'.esc_url(get_post_meta($id,'_mad4b_transport_media_source',true)).
                '" rel="noopener noreferrer" target="_blank">Wikimedia source</a></div>';
        }
        echo '</div><h2>Operations ('.esc_html((string)$snap['count']).')</h2>';
        if ($snap['review_issues'])echo '<p><strong>Attention:</strong> '.
            esc_html((string)$snap['review_issues']).' source rows have missing/duplicate locale posts or unreviewed publication.</p>';
        echo '<div style="max-width:100%;overflow-x:auto"><table class="widefat striped"><thead><tr>';
        foreach (array('PDF row','Localized title','Original Arabic','Operation classification',
                'Raw vehicle amounts — currency unknown','WPML Service','Review') as $th)
            echo '<th scope="col">'.esc_html($th).'</th>';
        echo '</tr></thead><tbody>';
        foreach ($snap['rows'] as $row) {
            echo '<tr><td>'.esc_html($row['source_key']).'</td>';
            echo '<td>'.esc_html($row['title']).'</td>';
            echo '<td dir="rtl">'.esc_html($row['original_ar']).'</td>';
            echo '<td>'.esc_html($row['service_kind']).'</td>';
            echo '<td>';
            foreach ($row['supplier_prices'] as $k=>$v) {
                echo '<span style="display:block">'.esc_html($v['category']).': '.
                    ($v['source_amount']===null?'—':esc_html((string)$v['source_amount'])).'</span>';
            }
            echo '</td>';
            echo '<td>';
            if ($row['source_post_count']===1)
                echo '<a href="'.esc_url(get_edit_post_link($row['source_post_ids'][0])).'">Edit '.esc_html(ucfirst($row['editorial_status'])).'</a>';
            else echo esc_html($row['review_status']);
            echo '</td><td>'.esc_html(implode('; ',array_unique($row['quality_flags']))).'</td></tr>';
        }
        echo '</tbody></table></div><p>The original PDF is authoritative. No amount in this view has a confirmed currency, cost/sale role, or booking eligibility.</p></div>';
    }
    public static function boot(): void {
        add_action('rest_api_init',array(__CLASS__,'rest'));
        add_action('admin_menu',array(__CLASS__,'menu'),100);
        add_action('admin_notices',array(__CLASS__,'pricing_link'));
    }
}
MAD4B_Transport_PDF_Review_V1::boot();
