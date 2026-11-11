<?php
/**
 * Plugin Name: MAD4B Dynamic Tour Contents — Elementor
 * Description: Native server-rendered, WPML-aware nested tour navigation using the JetEngine Tour Layout controller.
 * Version: 1.0.1
 */
defined('ABSPATH') || exit;

final class MAD4B_Tour_Contents_Widget_Loader {
    public const NAME = 'mad4b-tour-contents';
    public const OLD_WIDGET = 'b29a19b';

    public static function labels(): array {
        return [
            'en'=>['Tour contents','Overview','Experience & itinerary','Booking essentials','Explore more'],
            'ar'=>['محتويات الرحلة','نظرة عامة','التجربة وبرنامج الرحلة','معلومات الحجز','اكتشف المزيد'],
            'fr'=>['Sommaire du voyage','Aperçu','Expérience et itinéraire','Réservation','À découvrir'],
            'de'=>['Reiseübersicht','Überblick','Erlebnis und Reiseverlauf','Buchungsinfos','Mehr entdecken'],
            'it'=>['Contenuti del tour','Panoramica','Esperienze e itinerario','Prenotazione','Scopri di più'],
            'es'=>['Contenido del tour','Resumen','Experiencia e itinerario','Reservas','Descubre más'],
        ];
    }

    public static function titles(): array {
        return [
            'en'=>['af8f8d1'=>'About this tour','3c33d4f'=>'Amenities','b68567e'=>'Itinerary','409e1bc'=>'Included / Excluded','d90c3d1'=>'Highlights','1985c71'=>'Tour rates','59e3135'=>'Important links','711a0ab'=>'Travel assistance','6190bab'=>'Related destinations','53a3dd8'=>'FAQ','55314bb'=>'Tour details'],
            'ar'=>['af8f8d1'=>'عن الرحلة','3c33d4f'=>'مميزات الرحلة','b68567e'=>'برنامج الرحلة','409e1bc'=>'المشمول والمستثنى','d90c3d1'=>'أبرز المعالم','1985c71'=>'أسعار الرحلة','59e3135'=>'روابط مهمة','711a0ab'=>'مساعدة السفر','6190bab'=>'وجهات ذات صلة','53a3dd8'=>'الأسئلة الشائعة','55314bb'=>'تفاصيل الرحلة'],
            'fr'=>['af8f8d1'=>'À propos du voyage','3c33d4f'=>'Services','b68567e'=>'Itinéraire','409e1bc'=>'Inclus / Non inclus','d90c3d1'=>'Points forts','1985c71'=>'Tarifs','59e3135'=>'Liens importants','711a0ab'=>'Conseils de voyage','6190bab'=>'Destinations associées','53a3dd8'=>'FAQ','55314bb'=>'Détails du voyage'],
            'de'=>['af8f8d1'=>'Über diese Reise','3c33d4f'=>'Ausstattung','b68567e'=>'Reiseverlauf','409e1bc'=>'Inklusive / Exklusive','d90c3d1'=>'Höhepunkte','1985c71'=>'Preise','59e3135'=>'Wichtige Links','711a0ab'=>'Reiseberatung','6190bab'=>'Weitere Reiseziele','53a3dd8'=>'FAQ','55314bb'=>'Reisedetails'],
            'it'=>['af8f8d1'=>'Informazioni sul tour','3c33d4f'=>'Servizi','b68567e'=>'Itinerario','409e1bc'=>'Incluso / Escluso','d90c3d1'=>'Punti salienti','1985c71'=>'Prezzi','59e3135'=>'Link importanti','711a0ab'=>'Assistenza','6190bab'=>'Destinazioni correlate','53a3dd8'=>'FAQ','55314bb'=>'Dettagli del tour'],
            'es'=>['af8f8d1'=>'Acerca del tour','3c33d4f'=>'Servicios','b68567e'=>'Itinerario','409e1bc'=>'Incluye / No incluye','d90c3d1'=>'Lo más destacado','1985c71'=>'Tarifas','59e3135'=>'Enlaces importantes','711a0ab'=>'Asistencia','6190bab'=>'Destinos relacionados','53a3dd8'=>'Preguntas frecuentes','55314bb'=>'Detalles del tour'],
        ];
    }

    public static function group_for(string $id): ?int {
        static $map = [
            'af8f8d1'=>0,'3c33d4f'=>0,
            'b68567e'=>1,'409e1bc'=>1,'d90c3d1'=>1,
            '711a0ab'=>2,'1985c71'=>2,'59e3135'=>2,
            '6190bab'=>3,'53a3dd8'=>3,'55314bb'=>3,
        ];
        return $map[$id] ?? null;
    }

    public static function language(): string {
        $lang = (string) apply_filters('wpml_current_language','');
        if ($lang === '') $lang = substr((string)get_locale(),0,2);
        return isset(self::labels()[$lang]) ? $lang : 'en';
    }

    public static function order(int $tour): array {
        if ($tour < 1) return [];
        if (class_exists('MAD4B_Tour_Layout_Control_V1')) {
            $order = MAD4B_Tour_Layout_Control_V1::order_for($tour);
            if ($order) return $order;
        }
        return ['f846cdc','af8f8d1','3c33d4f','b68567e','409e1bc','d90c3d1','711a0ab','1985c71','59e3135','6190bab','53a3dd8','55314bb'];
    }

    public static function groups(int $tour, string $lang): array {
        $out = [];
        $map = self::titles()[$lang] ?? self::titles()['en'];
        foreach (self::order($tour) as $id) {
            $id = (string)$id;
            $group = self::group_for($id);
            if ($group === null || !isset($map[$id])) continue;
            // JetEngine explicitly hides the itinerary on tours with no itinerary.
            if ($id === 'b68567e') {
                $itinerary = get_post_meta($tour,'itinerary_item',true);
                $other = get_post_meta($tour,'itinerary_item_description',true);
                if (empty($itinerary) && empty($other)) continue;
            }
            if ($id === '1985c71' && (string)get_post_meta($tour,'tour_rates_status',true) === '0') continue;
            $out[$group][] = ['id'=>$id,'title'=>$map[$id]];
        }
        return $out;
    }

    public static function register_assets(): void {
        $base = content_url('mu-plugins/assets/');
        $dir = WPMU_PLUGIN_DIR . '/assets/';
        foreach (['mad4b-tour-toc-native.css'=>'style','mad4b-tour-toc-native.js'=>'script'] as $file=>$kind) {
            $path = $dir.$file;
            if (!is_readable($path)) continue;
            $ver = (string)filemtime($path);
            if ($kind==='style') wp_register_style('mad4b-tour-toc-native',$base.$file,[],$ver);
            else wp_register_script('mad4b-tour-toc-native',$base.$file,[],$ver,true);
        }
    }

    public static function anchor($element): void {
        if (!is_singular('tours-and-activities') || !method_exists($element,'get_id')) return;
        $id = (string)$element->get_id();
        if (self::group_for($id) === null) return;
        $element->add_render_attribute('_wrapper','id','mad4b-tour-section-'.$id);
        $element->add_render_attribute('_wrapper','class','mad4b-tour-section-anchor');
    }

    public static function register_widget($widgets): void {
        if (!class_exists('\Elementor\Widget_Base')) return;
        if (!class_exists('MAD4B_Tour_Contents_Widget_V1',false)) {
            require_once WPMU_PLUGIN_DIR . '/assets/class-mad4b-tour-contents-widget.php';
        }
        $widgets->register(new \MAD4B_Tour_Contents_Widget_V1());
    }

    public static function suppress_legacy(): void {
        if (!is_singular('tours-and-activities'))return;
        // Original client-side TOC builder is superseded by server-side markup.
        wp_dequeue_script('mad4b-mobile-tour-toc');
    }
}

add_action('elementor/widgets/register',['MAD4B_Tour_Contents_Widget_Loader','register_widget'],25);
add_action('elementor/frontend/container/before_render',['MAD4B_Tour_Contents_Widget_Loader','anchor'],32);
// A tour section can be a standalone Elementor widget (e.g. Price rates), not only a container.
add_action('elementor/frontend/widget/before_render',['MAD4B_Tour_Contents_Widget_Loader','anchor'],32);
add_action('wp_enqueue_scripts',['MAD4B_Tour_Contents_Widget_Loader','register_assets'],8);
add_action('wp_enqueue_scripts',['MAD4B_Tour_Contents_Widget_Loader','suppress_legacy'],150);
