<?php
/** Localized photo attribution and ALT text for licensed category illustrations. */
defined('ABSPATH') || exit;
final class MAD4B_Transport_Media_Credits_V1 {
    private const OPTION='mad4b_transport_public_photo_credits_v1';
    private const LABELS=array(
        'en'=>array('aria'=>'Transport image credits','title'=>'Transport photo sources and licenses',
            'notice'=>'Photos illustrate transport categories, not a guaranteed vehicle or train.',
            'change'=>'Resized for the web'),
        'ar'=>array('aria'=>'حقوق صور وسائل النقل','title'=>'مصادر وترخيص صور النقل',
            'notice'=>'الصور توضيحية لفئات النقل وليست ضمانًا لمركبة أو قطار بعينه.',
            'change'=>'تم تصغير الصورة للويب'),
        'es'=>array('aria'=>'Créditos de imágenes de transporte','title'=>'Fuentes y licencias de las fotos de transporte',
            'notice'=>'Las fotos ilustran categorías de transporte; no garantizan un vehículo o tren específico.',
            'change'=>'Imagen redimensionada para la web'),
        'fr'=>array('aria'=>'Crédits des images de transport','title'=>'Sources et licences des photos de transport',
            'notice'=>'Ces photos illustrent des catégories de transport, sans garantir un véhicule ou train précis.',
            'change'=>'Image redimensionnée pour le Web'),
        'de'=>array('aria'=>'Bildnachweise zum Transport','title'=>'Quellen und Lizenzen der Transportfotos',
            'notice'=>'Die Fotos illustrieren Transportkategorien und garantieren kein bestimmtes Fahrzeug oder Zug.',
            'change'=>'Bild für das Web verkleinert'),
        'it'=>array('aria'=>'Crediti delle immagini di trasporto','title'=>'Fonti e licenze delle foto dei trasporti',
            'notice'=>'Le foto illustrano categorie di trasporto, non garantiscono un veicolo o treno specifico.',
            'change'=>'Immagine ridimensionata per il Web'));
    private static function lang(): string {
        $l=(string)apply_filters('wpml_current_language',null);
        return isset(self::LABELS[$l])?$l:'en';
    }
    public static function alt(array $attrs, $attachment): array {
        if (!is_object($attachment) || empty($attachment->ID)) return $attrs;
        $lang=self::lang();
        $localized=(string)get_post_meta((int)$attachment->ID,
            '_mad4b_transport_media_alt_'.$lang,true);
        if ($localized!=='') $attrs['alt']=$localized;
        return $attrs;
    }
    public static function credits(): void {
        if (is_admin()) return;
        $items=get_option(self::OPTION,array());
        if (!is_array($items) || !$items) return;
        $locale=self::LABELS[self::lang()];
        echo '<aside class="mad4b-transport-photo-credits" style="font-size:12px;line-height:1.5;padding:8px 12px;opacity:.9;" aria-label="'.
          esc_attr($locale['aria']).'"><details><summary>'.esc_html($locale['title']).'</summary>';
        echo '<p>'.esc_html($locale['notice']).'</p><ul>';
        foreach ($items as $item) {
            if (!is_array($item)||($item['license']??'')!=='CC BY-SA 4.0'
                || empty($item['source']) || empty($item['author'])) continue;
            echo '<li>'.esc_html((string)$item['label']).' — '.
                esc_html((string)$item['author']).' · <a rel="license noopener noreferrer" href="'.
                esc_url((string)$item['source']).'">Wikimedia Commons</a> · '.
                '<a rel="license noopener noreferrer" href="https://creativecommons.org/licenses/by-sa/4.0/">'.
                'CC BY-SA 4.0</a> · '.esc_html($locale['change']).'</li>';
        }
        echo '</ul></details></aside>';
    }
}
add_filter('wp_get_attachment_image_attributes',
    array('MAD4B_Transport_Media_Credits_V1','alt'),10,2);
add_action('wp_footer',array('MAD4B_Transport_Media_Credits_V1','credits'),99);
