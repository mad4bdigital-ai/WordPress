<?php
declare(strict_types=1);
namespace Commerce\Composition;

/** Compact WordPress Tools UI backed by the same REST inspection service. */
final class CommerceEngineDashboard
{
    public static function bootstrap():void {
        add_action('admin_menu',[self::class,'menu']);
    }
    public static function menu():void {
        add_management_page('Commerce Engine Diagnostics','Commerce Engines',
            'manage_options','commerce-engine-diagnostics',[self::class,'render']);
    }
    public static function render():void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $overview=CommerceEngineInspector::overview();
        echo '<div class="wrap"><h1>Commerce Engine Diagnostics</h1>';
        echo '<p>Read-only provider, product and display-context inspection. No quote approval, payment or supplier booking.</p>';
        if(is_wp_error($overview)){
            echo '<div class="notice notice-error"><p>'.esc_html($overview->get_error_code()).'</p></div></div>';
            return;
        }
        echo '<p><strong>Environment:</strong> '.esc_html($overview['environment']).
            ' &nbsp; <strong>Company definition revision:</strong> '.
            esc_html((string)$overview['company_definition_revision']).'</p>';
        if(!$overview['write_ready']){
            echo '<div class="notice notice-warning"><p><strong>Commercial execution remains blocked.</strong> '.
                esc_html(implode(', ',array_slice($overview['write_blockers'],0,5))).
                '</p></div>';
        }
        echo '<h2>Engine ownership and certification</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Engine</th><th>Owner</th><th>Company</th><th>Observed state</th></tr></thead><tbody>';
        foreach($overview['engines'] as $key=>$item)
            echo '<tr><td>'.esc_html($key).'</td><td>'.esc_html($item['provider']).
                '</td><td>'.esc_html($item['company_enabled']?'Enabled':'Disabled').
                '</td><td>'.esc_html($item['state']).'</td></tr>';
        echo '</tbody></table>';
        $policy=CompanyCommercePolicy::registry();
        echo '<h2>Company Commerce Policy</h2>';
        if(is_wp_error($policy)){
            echo '<p><strong>Policy unavailable:</strong> '.esc_html($policy->get_error_code()).'</p>';
        }else{
            echo '<p><strong>Status:</strong> '.esc_html($policy['status']).
                 ' &nbsp; <strong>Revision:</strong> '.esc_html((string)$policy['revision']).
                 ' &nbsp; <strong>Rules:</strong> '.esc_html((string)count($policy['rules'])).'</p>';
            echo '<p>Company policies determine the intended Rate sale mode. A Rate can use its own Woo Product ID and inherit its parent service context without making checkout automatically available.</p>';
        }
        $readiness=CommercialQuoteReadiness::overview();
        echo '<h2>Final Quote — Provider Readiness</h2>';
        if(is_wp_error($readiness)){
            echo '<div class="notice notice-warning"><p>'.
                esc_html($readiness->get_error_code()).'</p></div>';
        }else{
            echo '<p><strong>Company policy:</strong> '.esc_html($readiness['policy_status']).
                ' &nbsp; <strong>Woo Tax:</strong> '.
                esc_html($readiness['woocommerce_tax_calculation']).
                ' &nbsp; <strong>Final Quote Provider:</strong> Not yet certified</p>';
            echo '<table class="widefat striped"><thead><tr>'.
                '<th>Financial source</th><th>Owner</th><th>Native status</th><th>Certified</th>'.
                '</tr></thead><tbody>';
            foreach($readiness['native_rule_coverage'] as $id=>$fact)
                echo '<tr><td>'.esc_html($id).'</td><td>'.
                    esc_html($fact['owner']).'</td><td>'.
                    esc_html($fact['provider_state']).'</td><td>No</td></tr>';
            echo '</tbody></table>';
            echo '<p><small>For an exact service, Rate and travel date, use the admin-only '.
                '<code>POST /commerce-composition/v1/management/commercial-quote/readiness</code>'.
                ' with the native booking configuration and chosen sale role.</small></p>';
        }
        echo '<h2>Incomplete pricing contracts</h2>';
        $rules=$overview['pricing_rules'];
        echo '<p>'.esc_html((string)$rules['registered']).' rules registered. '.
            esc_html((string)count($rules['incomplete'])).' incomplete core rules.</p>';
        if($rules['incomplete']){
            echo '<ul>';
            foreach($rules['incomplete'] as $rule)
                echo '<li>'.esc_html($rule['rule']).' — '.esc_html($rule['state']).'</li>';
            echo '</ul>';
        }
        echo '<h2>Inspect a published product / service</h2>';
        echo '<form method="get"><input type="hidden" name="page" value="commerce-engine-diagnostics" />';
        $source=absint($_GET['source_id']??0);
        $rate=absint($_GET['rate_id']??0);
        $date=isset($_GET['travel_date'])&&is_string($_GET['travel_date'])?
            sanitize_text_field(wp_unslash($_GET['travel_date'])):'';
        echo '<label for="engine-source">Service ID</label> '.
            '<input type="number" min="1" id="engine-source" name="source_id" value="'.esc_attr($source?:'').'" required> ';
        echo '<label for="engine-rate">Rate ID (optional)</label> '.
            '<input type="number" min="1" id="engine-rate" name="rate_id" value="'.esc_attr($rate?:'').'"> ';
        echo '<label for="engine-date">Travel date (optional)</label> '.
            '<input type="date" id="engine-date" name="travel_date" value="'.esc_attr($date).'"> ';
        submit_button('Preview source context','secondary','',false);
        echo '</form>';
        $types=$overview['source_types']['services']??[];
        echo '<p><small>Company source types: '.esc_html(implode(', ',$types)).
             '. Use the company Catalog REST to discover paginated service IDs.</small></p>';
        if($source>0){
            $resolved=CompanyCommercePolicy::resolve($source,$rate?:null);
            echo '<h3>Company Policy — intended sale item</h3>';
            if(is_wp_error($resolved)){
                echo '<p>'.esc_html($resolved->get_error_code()).'</p>';
            }else{
                echo '<p><strong>Rate sale mode:</strong> '.esc_html($resolved['effective']['rate_sale_mode']).
                    ' &nbsp; <strong>Policy revision:</strong> '.esc_html((string)$resolved['policy_revision']).'</p>';
                foreach($resolved['source']['sale_item_choices'] as $key=>$choice)
                    echo '<p>'.esc_html($key).' — '.esc_html($choice['policy_selected']?'Selected':'Not selected').
                        ' — Woo ID '.esc_html((string)($choice['product_id']??'none')).'</p>';
                echo '<p><strong>Checkout blocked.</strong> '.esc_html(implode(', ',
                    $resolved['execution_blockers'])).'</p>';
            }
            $detail=CommerceEngineInspector::product($source,$rate?:null,$date?:null);
            if(is_wp_error($detail))
                echo '<div class="notice notice-error"><p>'.esc_html($detail->get_error_code()).'</p></div>';
            else {
                echo '<h3>Product context — #'.esc_html((string)$source).'</h3>';
                echo '<table class="widefat striped"><tbody>';
                foreach(['source_type','wc_identity','pricing_quote_status'] as $key)
                    echo '<tr><th>'.esc_html($key).'</th><td>'.
                        esc_html((string)$detail[$key]).'</td></tr>';
                echo '<tr><th>Rate</th><td>'.esc_html($detail['rate']['state']).'</td></tr>';
                echo '<tr><th>Form</th><td>'.
                    esc_html($detail['booking_interface']['status']).' — '.
                    esc_html($detail['booking_interface']['mode']??$detail['booking_interface']['reason']??'').
                    '</td></tr>';
                foreach($detail['presentation'] as $key=>$value)
                    echo '<tr><th>'.esc_html($key).'</th><td>'.
                        esc_html(is_bool($value)?($value?'Yes':'No'):(string)$value).'</td></tr>';
                echo '</tbody></table>';
            }
        }
        echo '</div>';
    }
}
