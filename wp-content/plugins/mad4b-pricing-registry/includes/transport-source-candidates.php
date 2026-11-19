<?php
/** Private, read-only source-rate candidates; not a live pricing provider. */
defined('ABSPATH') || exit;

final class MAD4B_Transport_Source_Candidates_V1 {
    public const CONTRACT = 'mad4b.transport-source-candidates.v1';
    private const PRICE_KEYS = array('limousine', 'luxury_7_seats', 'hiace', 'coaster', 'bus');

    public static function snapshot(): array {
        $source = require dirname(__DIR__) . '/data/transportation-2026-source-candidates.php';
        if (!is_array($source) || !is_array($source['rows'] ?? null)) {
            return array('error' => 'source_data_invalid', 'mutation_performed' => false);
        }
        $counts = array();
        $priceCells = 0; $review = array(); $ids = array();
        foreach ($source['rows'] as $row) {
            $city = (string) ($row['origin_city'] ?? '');
            $counts[$city] = ($counts[$city] ?? 0) + 1;
            $sourceId = $city . ':' . (string) ($row['source_row'] ?? '');
            if (isset($ids[$sourceId])) $review[] = array('row' => $sourceId, 'issue' => 'duplicate_source_row');
            $ids[$sourceId] = true;
            $present = array();
            foreach (self::PRICE_KEYS as $key) {
                $price = $row[$key] ?? '';
                if ($price === '') continue;
                if (!is_numeric($price) || (float) $price <= 0) {
                    $review[] = array('row' => $sourceId, 'issue' => 'price_invalid_' . $key);
                } else {
                    ++$priceCells;
                    $present[$key] = (float) $price;
                }
            }
            if (!$present) $review[] = array('row' => $sourceId, 'issue' => 'all_prices_missing');
            // Only a review warning, not a correctness assertion about vendor pricing.
            if (isset($present['limousine'], $present['bus']) && $present['bus'] < $present['limousine']) {
                $review[] = array('row' => $sourceId, 'issue' => 'vehicle_price_order_anomaly');
            }
            if (empty($row['source_currency'])) $review[] = array('row' => $sourceId, 'issue' => 'currency_unconfirmed');
            if (($row['commercial_price_role'] ?? '') === 'unknown') {
                $review[] = array('row' => $sourceId, 'issue' => 'price_role_unconfirmed');
            }
        }
        return array('contract' => self::CONTRACT, 'source_name' => $source['source_name'],
            'source_sha256' => $source['source_sha256'], 'effective_from' => $source['source_as_of'],
            'rows_count' => count($source['rows']), 'price_cells' => $priceCells,
            'rows_by_city' => $counts, 'review' => $review,
            'source_currency_confirmed' => $source['currency_confirmed'],
            'commercial_role_confirmed' => $source['commercial_role_confirmed'],
            'ready_to_import' => false, 'ready_to_publish' => false,
            'mutation_performed' => false, 'checkout_authorized' => false);
    }

    public static function review_rows(string $city=''): array {
        $source = require dirname(__DIR__).'/data/transportation-2026-source-candidates.php';
        if (!is_array($source) || !is_array($source['rows']??null)) return array();
        $allowed = array('','Cairo','Aswan','Luxor');
        if (!in_array($city,$allowed,true)) $city='';
        return array_values(array_filter($source['rows'],static fn($r) =>
            $city==='' || ($r['origin_city']??'')===$city));
    }

    public static function review_table(): void {
        if (!MAD4B_Transport_Pricing_Readiness_V1::permitted()) return;
        $city = isset($_GET['transport_city']) ?
            sanitize_text_field(wp_unslash((string)$_GET['transport_city'])) : '';
        if (!in_array($city,array('','Cairo','Aswan','Luxor'),true)) $city='';
        $rows=self::review_rows($city);
        echo '<h3>2026 source-rate matrix — verification queue</h3>';
        echo '<form method="get" action="'.esc_url(admin_url('tools.php')).'">';
        echo '<input type="hidden" name="page" value="mad4b-transport-pricing-readiness">';
        echo '<label for="mad4b-transport-city">Origin city</label> ';
        echo '<select id="mad4b-transport-city" name="transport_city">';
        foreach (array(''=>'All origins','Cairo'=>'Cairo','Aswan'=>'Aswan','Luxor'=>'Luxor') as $key=>$label) {
            echo '<option value="'.esc_attr($key).'" '.selected($city,$key,false).'>'.
                esc_html($label).'</option>';
        }
        echo '</select> <button type="submit" class="button">Filter</button></form>';
        echo '<p>All amounts below are copied from the supplier PDF, with no confirmed currency or cost/selling status. ';
        echo 'The missing H1 prices and Luxor row 23 remain blank; these are not zero prices.</p>';
        echo '<div style="max-width:100%;overflow-x:auto"><table class="widefat striped"><thead><tr>';
        foreach (array('Origin','Source row','Operation','Limousine','Luxury 7 seats / H1',
            'Hiace','Coaster','Bus','Validation') as $head)
             echo '<th scope="col">'.esc_html($head).'</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>'.esc_html($r['origin_city']??'').'</td><td>'.
                 esc_html((string)($r['source_row']??'')).'</td><td>'.
                 esc_html($r['operating_statement_ar']??'').'</td>';
            foreach (array('limousine','luxury_7_seats','hiace','coaster','bus') as $k) {
                // H1 is unpriced in Aswan/Luxor; the CSV column is used only
                // as a positional placeholder and is not a sellable vehicle.
                $v=(string)($r[$k]??'');
                echo '<td>'.($v===''?'—':esc_html($v)).'</td>';
            }
            $note=(string)($r['review_note']??'');
            echo '<td>'.esc_html($note===''?'Currency + role unconfirmed':$note).
                '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p><em>These are vendor quotation candidates only. Commercial approval, current availability, ';
        echo 'currency and expiry require independent confirmation.</em></p>';
    }

    public static function rest(): void {
        register_rest_route('mad4b-pricing/v1','/transport-source-candidates',array(
            'methods'=>'GET', 'callback'=>static fn()=>rest_ensure_response(self::snapshot()),
            'permission_callback'=>array('MAD4B_Transport_Pricing_Readiness_V1','permitted'),
        ));
    }

    public static function admin(): void {
        $d = self::snapshot();
        echo '<h2>Transportation 2026 — Source Import Candidates</h2>';
        echo '<p>Staged source only. Currency, cost/selling role, and final mapping have not been approved. No rates inserted or published.</p>';
        echo '<p><strong>Source:</strong> '.esc_html($d['source_name']).' &nbsp; ';
        echo '<strong>Operations:</strong> '.esc_html((string) $d['rows_count']).' &nbsp; ';
        echo '<strong>Priced cells:</strong> '.esc_html((string) $d['price_cells']).' &nbsp; ';
        echo '<strong>Currency:</strong> Unconfirmed</p>';
        echo '<p><strong>By origin:</strong> ';
        foreach ($d['rows_by_city'] as $city=>$count) {
            echo esc_html($city.' '.$count).' &nbsp; ';
        }
        echo '</p>';
        $visible = array_filter($d['review'],static fn($a)=>!in_array($a['issue'],array('currency_unconfirmed','price_role_unconfirmed'),true));
        echo '<h3>Source anomalies</h3>';
        if (!$visible) echo '<p>No source anomalies noted.</p>';
        else {
            echo '<table class="widefat striped"><thead><tr><th>Source row</th><th>Review issue</th></tr></thead><tbody>';
            foreach ($visible as $item) echo '<tr><td>'.esc_html($item['row']).'</td><td>'.esc_html($item['issue']).'</td></tr>';
            echo '</tbody></table>';
        }
        self::review_table();
        echo '<p><em>Not for sale. Does not modify Transport Rates CCT or the Tour Rate Generator.</em></p>';
    }
}
add_action('rest_api_init',array('MAD4B_Transport_Source_Candidates_V1','rest'));
