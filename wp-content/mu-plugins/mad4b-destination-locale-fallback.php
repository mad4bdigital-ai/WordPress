<?php
/**
 * Plugin Name: MAD4B Destination WPML Locale Fallback
 * Description: Localizes unlinked Elementor template strings without overriding linked WPML translations or site-specific editor customizations.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Destination_Locale_Fallback {
    private const GROUP = 'MAD4B Destination Content';

    public static function lang(): string {
        // Safe locale read: never call WPML filters from a translation hook.
        $lang = defined('ICL_LANGUAGE_CODE') ? (string) ICL_LANGUAGE_CODE : '';
        if (!$lang) $lang = strtolower(substr((string) get_locale(), 0, 2));
        return in_array($lang, ['en', 'ar', 'fr', 'de', 'it', 'es'], true) ? $lang : 'en';
    }

    public static function originals(): array {
        return [
            'custom_trips'  => 'Customized Trips',
            'happy_customers' => '5K+ Happy Customer',
            'belief_heading' => 'We Believe That Travel is a Personal Journey',
            'belief_body' => 'Our team of seasoned travel experts brings years of experience and an in-depth understanding of the world’s most captivating destinations. We believe that travel is a personal journey, and we strive to provide personalized service that reflects your unique tastes and interests.',
            'read_more' => 'Read More',
            'click_here' => 'Click Here',
        ];
    }

    private static function copy(): array {
        return [
            'ar' => [
                'custom_trips'=>'رحلات مصممة خصيصًا لك',
                'happy_customers'=>'أكثر من ٥٬٠٠٠ عميل سعيد',
                'belief_heading'=>'نؤمن بأن السفر رحلة شخصية',
                'belief_body'=>'يجمع فريق خبرائنا في السفر بين سنوات من الخبرة والمعرفة العميقة بأكثر الوجهات إثارة في العالم. نؤمن بأن السفر تجربة شخصية، لذلك نقدم خدمة مصممة وفق اهتماماتك وتفضيلاتك.',
                'read_more'=>'اقرأ المزيد',
                'click_here'=>'اضغط هنا',
            ],
            'es' => [
                'custom_trips'=>'Viajes a medida',
                'happy_customers'=>'Más de 5.000 clientes satisfechos',
                'belief_heading'=>'Creemos que cada viaje es una experiencia personal',
                'belief_body'=>'Nuestro equipo de especialistas en viajes aporta años de experiencia y un conocimiento profundo de los destinos más fascinantes del mundo. Creemos que cada viaje es una experiencia personal y ofrecemos un servicio adaptado a tus gustos e intereses.',
                'read_more'=>'Leer más',
                'click_here'=>'Haz clic aquí',
            ],
            'it' => [
                'custom_trips'=>'Viaggi su misura',
                'happy_customers'=>'Oltre 5.000 clienti soddisfatti',
                'belief_heading'=>'Crediamo che ogni viaggio sia un’esperienza personale',
                'belief_body'=>'Il nostro team di esperti di viaggio mette a disposizione anni di esperienza e una conoscenza approfondita delle destinazioni più affascinanti del mondo. Crediamo che ogni viaggio sia un’esperienza personale e offriamo un servizio su misura per i tuoi gusti e interessi.',
                'read_more'=>'Scopri di più',
                'click_here'=>'Clicca qui',
            ],
            'fr' => [
                'custom_trips'=>'Voyages sur mesure',
                'happy_customers'=>'Plus de 5 000 clients satisfaits',
                'belief_heading'=>'Nous pensons que chaque voyage est une expérience personnelle',
                'belief_body'=>'Notre équipe d’experts du voyage possède des années d’expérience et une connaissance approfondie des destinations les plus fascinantes au monde. Nous pensons que chaque voyage est une expérience personnelle et proposons un accompagnement adapté à vos goûts et à vos envies.',
                'read_more'=>'En savoir plus',
                'click_here'=>'Cliquez ici',
            ],
            'de' => [
                'custom_trips'=>'Individuelle Reisen',
                'happy_customers'=>'Über 5.000 zufriedene Kunden',
                'belief_heading'=>'Wir glauben, dass jede Reise etwas Persönliches ist',
                'belief_body'=>'Unser Team erfahrener Reiseexperten verfügt über langjährige Erfahrung und umfassende Kenntnisse der faszinierendsten Reiseziele weltweit. Wir sind überzeugt, dass jede Reise etwas Persönliches ist, und bieten einen Service, der auf Ihre Wünsche und Interessen zugeschnitten ist.',
                'read_more'=>'Mehr erfahren',
                'click_here'=>'Hier klicken',
            ],
        ];
    }

    private static function translated(string $key, string $lang): string {
        $original = self::originals()[$key] ?? '';
        $fallback = self::copy()[$lang][$key] ?? $original;
        // Authoritative localized fallback without recursive WPML/front-end
        // translation filters. Native WPML-translated templates are untouched.
        return $fallback;
    }

    public static function register_strings(): void {
        if (!has_action('wpml_register_single_string')) return;
        foreach (self::originals() as $key => $original) {
            do_action('wpml_register_single_string', self::GROUP, $key, $original);
        }
    }

    public static function dynamic_heading_translate($translated, $text, $domain) {
        if ($domain !== 'default' || !is_tax('destinations') || $translated !== $text) return $translated;
        $lang = self::lang();
        $map = [
            'Explore %s & Nearby Destinations' => [
                'ar'=>'استكشف %s والوجهات القريبة', 'fr'=>'Explorez %s et les destinations proches',
                'de'=>'Entdecken Sie %s und nahegelegene Reiseziele',
                'it'=>'Esplora %s e le destinazioni vicine',
                'es'=>'Explora %s y destinos cercanos',
            ],
            'Explore Attractions Around %s' => [
                'ar'=>'استكشف المعالم القريبة من %s', 'fr'=>'Découvrez les attractions autour de %s',
                'de'=>'Entdecken Sie Sehenswürdigkeiten rund um %s',
                'it'=>'Scopri le attrazioni nei dintorni di %s',
                'es'=>'Explora las atracciones cerca de %s',
            ],
        ];
        return $map[$text][$lang] ?? $translated;
    }

    private static function localized_archive_tag(string $raw, string $lang): string {
        if (strpos($raw, 'name="archive-title"') === false) return $raw;
        if (!preg_match('/settings="([^"]+)"/', $raw, $m, PREG_OFFSET_CAPTURE)) return $raw;
        $params = json_decode(rawurldecode($m[1][0]), true);
        if (!is_array($params)) return $raw;
        $before = (string)($params['before'] ?? '');
        $after = (string)($params['after'] ?? '');
        $patterns = [
            'ar' => [
                'about'=>['نبذة عن ',''], 'attractions'=>['استكشف معالم ',' السياحية'],
                'zones'=>['مناطق وأحياء ',''], 'cities'=>['أهم المدن التي تستحق الزيارة في ',''],
                'states'=>['محافظات وولايات ',''], 'related'=>['',' - مقالات ذات صلة'],
            ],
            'es' => [
                'about'=>['Acerca de ',''], 'attractions'=>['Explora las atracciones de ',''],
                'zones'=>['',' zonas y áreas'], 'cities'=>['',' ciudades imprescindibles'],
                'states'=>['',' provincias y estados'], 'related'=>['',' artículos relacionados'],
            ],
            'it' => [
                'about'=>['Informazioni su ',''], 'attractions'=>['Esplora le attrazioni di ',''],
                'zones'=>['',' zone e aree'], 'cities'=>['',' città da visitare'],
                'states'=>['',' province e stati'], 'related'=>['',' articoli correlati'],
            ],
            'fr' => [
                'about'=>['À propos de ',''], 'attractions'=>['Découvrez les attractions de ',''],
                'zones'=>['', ' : quartiers et zones'], 'cities'=>['', ' : villes incontournables'],
                'states'=>['', ' : gouvernorats et régions'], 'related'=>['',' : articles associés'],
            ],
            'de' => [
                'about'=>['Über ',''], 'attractions'=>['Entdecken Sie die Sehenswürdigkeiten von ',''],
                'zones'=>['', ' : Stadtteile und Gebiete'], 'cities'=>['', ' : sehenswerte Städte'],
                'states'=>['', ' : Gouvernements und Bundesländer'], 'related'=>['',' : verwandte Artikel'],
            ],
        ];
        if (!isset($patterns[$lang])) return $raw;
        $kind = null;
        if ($before === 'About ') $kind = 'about';
        elseif (strpos($after, 'Attractions') !== false) $kind = 'attractions';
        elseif (strpos($after, 'Zones/Areas') !== false) $kind = 'zones';
        elseif (strpos($after, 'Must visit Cities') !== false) $kind = 'cities';
        elseif (strpos($after, 'Government / States') !== false) $kind = 'states';
        elseif (strpos($after, 'Related Articles') !== false) $kind = 'related';
        if (!$kind) return $raw;
        [$params['before'], $params['after']] = $patterns[$lang][$kind];
        $replacement = rawurlencode(wp_json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return substr_replace($raw, $replacement, $m[1][1], strlen($m[1][0]));
    }

    public static function editor_data($nodes, $post_id) {
        if (!is_array($nodes) || !in_array((int)$post_id, [45891, 45774, 45886, 48542, 48544, 56096, 56106, 56108, 56110], true)) return $nodes;
        $lang = self::lang();
        if ($lang === 'en') return $nodes;
        static $inProgress = false;
        if ($inProgress) return $nodes;
        $inProgress = true;
        $walk = static function (array $items) use (&$walk, $post_id, $lang): array {
            foreach ($items as &$item) {
                if (!is_array($item)) continue;
                if (($item['elType'] ?? '') === 'widget' && isset($item['settings']) && is_array($item['settings'])) {
                    $settings = &$item['settings'];
                    $id = (string)($item['id'] ?? '');
                    if ((int)$post_id === 45891) {
                        $fields = [
                            '489d7e55'=>['title'=>'custom_trips'],
                            '63dc9a7e'=>['title'=>'happy_customers'],
                            '5cb8cc5c'=>[
                                'infobox_title'=>'belief_heading','infobox_description'=>'belief_body',
                                'infobox_link_text'=>'read_more','infobox_button_text'=>'click_here',
                            ],
                        ];
                        foreach ($fields[$id] ?? [] as $field => $key) {
                            if (($settings[$field] ?? null) === (self::originals()[$key] ?? null)) {
                                $settings[$field] = self::translated($key, $lang);
                            }
                        }
                    }
                    if (in_array((int)$post_id, [45774,45886,48542,48544,56096,56106,56108,56110], true)
                        && in_array($lang, ['ar','fr','de','it','es'], true)) {
                        if ((int)$post_id === 45774 && $id === 'f10db4f' &&
                            ($settings['title'] ?? '') === 'Destination Contents') {
                            $labels = [
                                'ar'=>'محتويات الوجهة', 'fr'=>'Sommaire de la destination',
                                'de'=>'Reiseziel-Inhalte', 'it'=>'Contenuti della destinazione',
                                'es'=>'Contenido del destino',
                            ];
                            $settings['title'] = $labels[$lang] ?? $settings['title'];
                        }
                        if ($id === 'f10db4f' && empty($settings['exclude_headings_by_selector'])) {
                            $settings['exclude_headings_by_selector'] = '.elementor-sticky__spacer';
                        }
                        if ($id === 'c52b494' && ($settings['title'] ?? '') === 'Explore Regional Attractions') {
                            $headings = [
                                'ar'=>'استكشف معالم المنطقة السياحية',
                                'es'=>'Explora las atracciones regionales',
                                'it'=>'Esplora le attrazioni della regione',
                                'fr'=>'Découvrez les attractions régionales',
                                'de'=>'Entdecken Sie regionale Sehenswürdigkeiten',
                            ];
                            $settings['title'] = $headings[$lang] ?? $settings['title'];
                        }
                        if (isset($settings['__dynamic__']['title']) && is_string($settings['__dynamic__']['title'])) {
                            $settings['__dynamic__']['title'] =
                                self::localized_archive_tag($settings['__dynamic__']['title'], $lang);
                        }
                    }
                    unset($settings);
                }
                if (!empty($item['elements']) && is_array($item['elements'])) {
                    $item['elements'] = $walk($item['elements']);
                }
            }
            unset($item);
            return $items;
        };
        try {
            return $walk($nodes);
        } finally {
            $inProgress = false;
        }
    }
}

add_filter('elementor/frontend/builder_content_data', ['MAD4B_Destination_Locale_Fallback','editor_data'], 185, 2);
add_action('admin_init', ['MAD4B_Destination_Locale_Fallback','register_strings'], 55);
