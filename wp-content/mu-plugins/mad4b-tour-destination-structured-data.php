<?php
/**
 * Plugin Name: MAD4B Tourism Structured Data
 * Description: Native Tour and Destination schema, using live JetEngine/WPML post and term fields.
 * Version: 1.0.0
 *
 * Never invent Offer/availability/review data from a starting price. A booking
 * provider may supply a VERIFIED price through mad4b_schema_verified_tour_offer.
 */
if (!defined('ABSPATH')) { exit; }

final class MAD4B_Tour_Destination_Structured_Data {
    private const MAX_DESCRIPTION = 550;
    private const MAX_LOCATIONS = 12;

    public static function init(): void {
        add_filter('rank_math/json_ld', [__CLASS__, 'extend_rank_math'], 90, 2);
        add_action('wp_head', [__CLASS__, 'fallback_without_rank_math'], 30);
    }

    public static function text($value, int $max = self::MAX_DESCRIPTION): string {
        if (!is_scalar($value)) return '';
        $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($value))) ?: '');
        return $max > 0 ? mb_substr($value, 0, $max) : $value;
    }

    private static function http_url($value): string {
        if (is_numeric($value) && (int)$value > 0) {
            $value = wp_get_attachment_image_url((int)$value, 'large');
        }
        if (!is_string($value) || !preg_match('~^https?://~i', trim($value))) return '';
        return esc_url_raw(trim($value), ['http', 'https']);
    }

    private static function noindex($robots): bool {
        if (is_string($robots)) $robots = preg_split('/[\s,]+/', strtolower($robots));
        return is_array($robots) && in_array('noindex', array_map('strtolower', array_filter($robots, 'is_string')), true);
    }

    private static function public_post(WP_Post $post): bool {
        if ($post->post_status !== 'publish' || $post->post_type !== 'tours-and-activities') return false;
        if (in_array((string)get_post_meta($post->ID, 'tour_public_status', true), ['0', 'false', 'draft', 'stop_sale'], true)) return false;
        return !self::noindex(get_post_meta($post->ID, 'rank_math_robots', true));
    }

    private static function public_term(WP_Term $term): bool {
        if ($term->taxonomy !== 'destinations' || $term->term_id <= 0) return false;
        foreach (['destination_public_status', 'is_public'] as $field) {
            if (in_array((string)get_term_meta($term->term_id, $field, true), ['0', 'false'], true)) return false;
        }
        return !self::noindex(get_term_meta($term->term_id, 'rank_math_robots', true));
    }

    private static function place_type(WP_Term $term): string {
        $kind = strtolower(self::text(get_term_meta($term->term_id, 'destination_type', true), 80));
        return (str_contains($kind, 'attraction') || str_contains($kind, 'landmark') || str_contains($kind, 'museum'))
            ? 'TouristAttraction' : 'TouristDestination';
    }

    private static function language(): string {
        $code = function_exists('apply_filters') ? apply_filters('wpml_current_language', null) : null;
        return is_string($code) && preg_match('/^[a-z]{2}(?:-[A-Za-z]{2})?$/', $code) ? $code : '';
    }

    /** Existing JetEngine term coordinates only; never geocode or guess. */
    private static function coordinates(WP_Term $term): ?array {
        $location = get_term_meta($term->term_id, 'google_location', true);
        if (is_string($location)) {
            $decoded = json_decode($location, true);
            if (is_array($decoded)) $location = $decoded;
        }
        $lat = is_array($location) ? ($location['lat'] ?? $location['latitude'] ?? null) : null;
        $lng = is_array($location) ? ($location['lng'] ?? $location['longitude'] ?? null) : null;
        if ($lat === null || $lng === null) {
            $meta = get_term_meta($term->term_id);
            foreach (array_keys((array)$meta) as $key) {
                if (str_ends_with($key, '_lat')) {
                    $pair = substr($key, 0, -4).'_lng';
                    if (!array_key_exists($pair, (array)$meta)) continue;
                    $lat = get_term_meta($term->term_id, $key, true);
                    $lng = get_term_meta($term->term_id, $pair, true);
                    break;
                }
            }
        }
        if (!is_numeric($lat) || !is_numeric($lng)) return null;
        $lat = (float)$lat; $lng = (float)$lng;
        if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180 || ($lat == 0 && $lng == 0)) return null;
        return ['@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng];
    }

    public static function destination_node(WP_Term $term): ?array {
        if (!self::public_term($term)) return null;
        $url = get_term_link($term);
        if (is_wp_error($url) || !is_string($url) || !$url) return null;
        $node = [
            '@type' => self::place_type($term),
            '@id' => trailingslashit($url).'#destination',
            'name' => self::text($term->name, 180),
            'url' => $url,
            'mainEntityOfPage' => ['@id' => $url.'#webpage'],
        ];
        $description = self::text($term->description);
        if ($description === '') $description = self::text(get_term_meta($term->term_id, 'exerpt', true));
        if ($description !== '') $node['description'] = $description;
        $image = self::http_url(get_term_meta($term->term_id, 'featured_image', true));
        if ($image === '') $image = self::http_url(get_term_meta($term->term_id, 'thumbnail_id', true));
        if ($image !== '') $node['image'] = $image;
        $geo = self::coordinates($term);
        if ($geo) $node['geo'] = $geo;
        $address = self::text(get_term_meta($term->term_id, 'google_address', true), 220);
        if ($address !== '') $node['address'] = $address;
        if ($term->parent > 0) {
            $parent = get_term($term->parent, 'destinations');
            if ($parent instanceof WP_Term && self::public_term($parent)) {
                $parent_url = get_term_link($parent);
                if (!is_wp_error($parent_url)) {
                    $node['containedInPlace'] = [
                        '@type' => self::place_type($parent),
                        '@id' => trailingslashit($parent_url).'#destination',
                        'name' => self::text($parent->name, 180),
                    ];
                }
            }
        }
        return $node;
    }

    /** Public taxonomy URLs identify real classes of trips; never fabricate URLs. */
    private static function tour_classification(int $id): array {
        $types = [];
        foreach (['tour-types', 'tour-styles'] as $taxonomy) {
            $terms = wp_get_post_terms($id, $taxonomy);
            if (!is_array($terms) || is_wp_error($terms)) continue;
            foreach ($terms as $term) {
                if (!$term instanceof WP_Term) continue;
                $link = get_term_link($term);
                if (is_wp_error($link) || !is_string($link) || $link === '') continue;
                $types[] = esc_url_raw($link);
                if (count($types) >= 12) break 2;
            }
        }
        // tour_mode is an internal discriminator, NOT a Schema.org @type.
        // A default "tour" is already expressed by TouristTrip.
        $mode = self::text(get_post_meta($id, 'tour_mode', true), 60);
        $labels = ['cruise_tour'=>'Cruise Tour', 'activity'=>'Tourist Activity', 'extra_trip'=>'Extra Trip'];
        if (isset($labels[$mode])) $types[] = $labels[$mode];
        return array_values(array_unique($types));
    }

    private static function tour_audience(int $id): array {
        $terms = wp_get_post_terms($id, 'good-for');
        if (!is_array($terms) || is_wp_error($terms)) return [];
        $names = [];
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) continue;
            $name = self::text($term->name, 80);
            if ($name !== '') $names[] = $name;
            if (count($names) >= 10) break;
        }
        return array_values(array_unique($names));
    }

    /**
     * An Offer requires independently verified checkout-ready price and currency.
     * A `start_price` or a WooCommerce CPT association is NOT such evidence.
     * Provider return example: ['checkout_ready'=>true, 'price'=>199.50,
     * 'currency'=>'USD', 'url'=>'https://.../book/...', 'availability'=>'InStock'].
     */
    private static function verified_offer(int $id): ?array {
        $verified = apply_filters('mad4b_schema_verified_tour_offer', null, $id);
        if (!is_array($verified) || ($verified['checkout_ready'] ?? null) !== true) return null;
        $amount = $verified['price'] ?? null;
        $currency = strtoupper((string)($verified['currency'] ?? ''));
        $link = self::http_url($verified['url'] ?? '');
        if (!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount <= 0
            || !preg_match('/^[A-Z]{3}$/', $currency) || $link === '') return null;
        $url_host = wp_parse_url($link, PHP_URL_HOST);
        $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (!$url_host || !$site_host || strtolower((string)$url_host) !== strtolower((string)$site_host)) return null;
        $offer = ['@type'=>'Offer', 'price'=>number_format((float)$amount, 2, '.', ''),
            'priceCurrency'=>$currency, 'url'=>$link];
        $availability = (string)($verified['availability'] ?? '');
        if (in_array($availability, ['InStock','LimitedAvailability','PreOrder','OutOfStock'], true))
            $offer['availability'] = 'https://schema.org/'.$availability;
        return $offer;
    }

    public static function tour_node(WP_Post $post): ?array {
        if (!self::public_post($post)) return null;
        $url = get_permalink($post);
        if (!is_string($url) || !$url) return null;
        $node = [
            '@type' => 'TouristTrip',
            '@id' => trailingslashit($url).'#tour',
            'name' => self::text($post->post_title, 220),
            'url' => $url,
            'mainEntityOfPage' => ['@id'=>$url.'#webpage'],
        ];
        $description = self::text(get_post_meta($post->ID, '_description', true));
        if ($description === '') $description = self::text($post->post_excerpt);
        if ($description === '') $description = self::text($post->post_content);
        if ($description !== '') $node['description'] = $description;
        $image = self::http_url(get_the_post_thumbnail_url($post->ID, 'large'));
        if ($image === '') $image = self::http_url(get_post_meta($post->ID, 'image_vertical', true));
        if ($image !== '') $node['image'] = $image;
        $classification = self::tour_classification($post->ID);
        if ($classification) $node['additionalType'] = $classification;
        $audience = self::tour_audience($post->ID);
        if ($audience) $node['touristType'] = $audience;
        $terms = wp_get_post_terms($post->ID, 'destinations');
        if (is_array($terms) && !is_wp_error($terms)) {
            $locations = [];
            foreach ($terms as $term) {
                if (!$term instanceof WP_Term || !self::public_term($term)) continue;
                $link = get_term_link($term);
                if (is_wp_error($link) || !is_string($link)) continue;
                $locations[] = ['@type'=>self::place_type($term), '@id'=>trailingslashit($link).'#destination',
                    'name'=>self::text($term->name, 180)];
                if (count($locations) >= self::MAX_LOCATIONS) break;
            }
            // Only place membership is known. No sequence is claimed here.
            if ($locations) $node['itinerary'] = $locations;
        }
        $verified = self::verified_offer($post->ID);
        if ($verified) {
            // Only a verified bookable offer qualifies this trip as a purchasable Product.
            $node['@type'] = ['TouristTrip', 'Product'];
            $node['offers'] = $verified;
        }
        return $node;
    }

    private static function already_present(array $graph, string $kind): bool {
        foreach ($graph as $entry) {
            if (!is_array($entry)) continue;
            $types = (array)($entry['@type'] ?? []);
            if (in_array($kind, $types, true)) return true;
        }
        return false;
    }

    public static function selected_node(): ?array {
        if (is_admin() || is_feed() || is_preview() || wp_doing_ajax()) return null;
        if (is_singular('tours-and-activities')) {
            $post = get_queried_object();
            return $post instanceof WP_Post ? self::tour_node($post) : null;
        }
        if (is_tax('destinations')) {
            $term = get_queried_object();
            return $term instanceof WP_Term ? self::destination_node($term) : null;
        }
        return null;
    }

    public static function extend_rank_math($graph, $jsonld = null) {
        if (!is_array($graph)) return $graph;
        $node = self::selected_node();
        if (!$node) return $graph;
        $types = (array)$node['@type'];
        foreach ($types as $type) {
            if (self::already_present($graph, $type)) return $graph;
        }
        $key = in_array('TouristTrip', $types, true) ? 'mad4b-tourist-trip' : 'mad4b-tourist-destination';
        $graph[$key] = $node;
        return $graph;
    }

    public static function fallback_without_rank_math(): void {
        if (defined('RANK_MATH_VERSION')) return;
        $node = self::selected_node();
        if (!$node) return;
        echo '<script type="application/ld+json">'.wp_json_encode(
            ['@context'=>'https://schema.org', '@graph'=>[$node]],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        )."</script>\n";
    }
}
MAD4B_Tour_Destination_Structured_Data::init();
