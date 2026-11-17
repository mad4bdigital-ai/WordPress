<?php
defined('ABSPATH') || exit;

/** Central planning drafts, isolated from runtime price and content options. */
final class MAD4B_Pricing_Strategy_Drafts {
    public static function catalog(): array {
        return [
            'private-direct@1'=>['Private tour · direct price', [
                'supplier'=>'company', 'reference'=>'tour_initial_retail', 'sale_unit'=>'per_person',
                'dimensions'=>['tour_mode','private_group','group_size']]],
            'supplier-rate@1'=>['Contracted tour · supplier rate', [
                'supplier'=>'external', 'reference'=>'supplier_contract', 'sale_unit'=>'contract_unit',
                'dimensions'=>['tour_mode','season','group_tier','market','currency']]],
            'package@1'=>['Package · tour, hotel and transfer', [
                'supplier'=>'per_component', 'reference'=>'per_component',
                'units'=>['tour'=>'per_person','hotel'=>'room_night','transfer'=>'vehicle_journey'],
                'dimensions'=>['tour_mode','package_level','season','occupancy','child_age','vehicle_capacity']]]
        ];
    }

    public static function draft(string $preset, string $uuid): array|WP_Error {
        $catalog=self::catalog();
        if (!wp_is_uuid($uuid) || !isset($catalog[$preset])) return new WP_Error('invalid_strategy_selection');
        return ['site_uuid'=>$uuid,'preset'=>$preset,'state'=>'draft','preferences'=>$catalog[$preset][1]];
    }

    private const MARKER = '/^MAD4B pricing preset: ([a-z0-9-]+@[0-9]+)$/m';

    public static function selection(array $record): string {
        $text=$record['sections']['products_commerce']??'';
        return preg_match(self::MARKER,$text,$matches)?$matches[1]:'';
    }

    public static function candidate(array $record, string $preset): array|WP_Error {
        if (!isset(self::catalog()[$preset])) return new WP_Error('invalid_strategy_selection');
        if (($record['policies']['products_commerce']??'inherit')==='locked') return new WP_Error('products_commerce_locked');
        $text=$record['sections']['products_commerce']??'';
        if (preg_match_all(self::MARKER,$text)>1) return new WP_Error('ambiguous_preset');
        $marker='MAD4B pricing preset: '.$preset;
        $record['sections']['products_commerce']=preg_match(self::MARKER,$text)
            ?preg_replace(self::MARKER,$marker,$text):$text.($text===''?'':"\n").$marker;
        $record['policies']=$record['policies']??array_fill_keys(MAD4B_Company_Strategy_V1::FIELDS,'inherit');
        return $record;
    }

    /** Called inside the hub's verified company permission boundary. */
    public static function panel(string $company, string $uuid): void {
        $key='mad4b_company_strategy_draft_'.$uuid;
        $catalog=self::catalog();
        $binding=MAD4B_Company_Strategy_V1::binding($company);
        if(is_wp_error($binding)||$binding['company_site_uuid']!==$uuid)return;
        $row=MAD4B_Company_Strategy_V1::current($binding);
        if(is_wp_error($row)){echo '<p>Strategy record needs reconciliation.</p>';return;}
        $record=$row['candidate']??$row['active']??[];
        $selected=self::selection($record);
        $legacy=get_option($key,[]);
        if($selected==='' && ($legacy['site_uuid']??'')===$uuid)$selected=$legacy['preset']??'';
        if (($_SERVER['REQUEST_METHOD']??'')==='POST' && isset($_POST['mad4b_strategy_nonce'])) {
            check_admin_referer($key,'mad4b_strategy_nonce');
            $preset=wp_unslash($_POST['mad4b_strategy_preset']??'');
            $draft=is_string($preset)?self::draft($preset,$uuid):new WP_Error('invalid_strategy_selection');
            if(is_wp_error($draft)) wp_die('Invalid strategy selection.', '', ['response'=>400]);
            if(isset($row['candidate']) && $row['candidate']['state']!=='draft')wp_die('Candidate is under review.', '', ['response'=>409]);
            $revision=filter_var($_POST['strategy_revision']??null,FILTER_VALIDATE_INT);
            if($revision!==$row['revision'])wp_die('Reload the current strategy revision.', '', ['response'=>409]);
            $candidate=self::candidate($record,$preset);
            if(is_wp_error($candidate))wp_die(esc_html($candidate->get_error_code()), '', ['response'=>409]);
            $result=MAD4B_Company_Strategy_V1::transact($company,$revision,'save',$candidate);
            if(is_wp_error($result))wp_die(esc_html($result->get_error_code()), '', ['response'=>409]);
            $row=MAD4B_Company_Strategy_V1::current($binding);
            if(is_wp_error($row)||self::selection($row['candidate']??[])!==$preset)wp_die('Draft readback failed.', '', ['response'=>503]);
            $selected=$preset;
            echo '<div class="notice notice-success"><p>Strategy draft saved.</p></div>';
        }
        $saved=$catalog[$selected][1]??[];
        echo '<h2>Pricing strategy draft</h2><p>'.esc_html($company).' · Internal planning only.</p><form method="post">';
        echo '<input type="hidden" name="strategy_revision" value="'.esc_attr((string)$row['revision']).'">';
        wp_nonce_field($key,'mad4b_strategy_nonce');
        echo '<label>Strategy and version <select required name="mad4b_strategy_preset"><option value="">Choose a strategy</option>';
        foreach ($catalog as $id=>[$label,$rules]) {
            echo '<option value="'.esc_attr($id).'" '.selected($selected,$id,false).'>'.esc_html($label.' · '.$id).'</option>';
        }
        echo '</select></label> <button class="button">Save draft</button></form>';
        if (class_exists('MAD4B_Operational_Pricing_Presets_V1') && current_user_can('manage_woocommerce')) {
            $scenarios=MAD4B_Operational_Pricing_Presets_V1::catalog();
            $links=['private-direct@1'=>['tour-single-20261120','tour-double-20261120'],
                'supplier-rate@1'=>['transport-expired-future','rail-no-native-rate'],
                'package@1'=>['journey-2adults-qa','property-eileen-qa','tax-policy-missing']];
            foreach($links[$selected]??[] as $id)if(isset($scenarios[$id])){
                $href=add_query_arg(['page'=>MAD4B_Operational_Pricing_Presets_V1::PAGE,'sim_preset'=>$id],admin_url('admin.php'));
                echo '<p><a href="'.esc_url($href).'">'.esc_html($scenarios[$id]['title']).'</a></p>';
            }
            $url=add_query_arg('page',MAD4B_Operational_Pricing_Presets_V1::PAGE,admin_url('admin.php'));
            echo '<p><a href="'.esc_url($url).'">'.esc_html(sprintf('Review existing pricing simulations (%d)',count($scenarios))).'</a></p>';
        }
        if ($saved) echo '<pre>'.esc_html(wp_json_encode($saved,JSON_PRETTY_PRINT)).'</pre>';
    }
}
