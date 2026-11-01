<?php
namespace DynamicFilterSEOBridge\Admin;
use DynamicFilterSEOBridge\Context\PageContextResolver as Pages;
use DynamicFilterSEOBridge\Context\ProviderContextResolver as Providers;
use DynamicFilterSEOBridge\SEO\ContextSeoSources as Seo;

/** Read-only tenant inventory and provider diagnostic, available under Safe Boot. */
final class SafeContextAuditPage {
    public function register(): void {
        add_action('admin_menu',array($this,'menu'),13);
    }
    public function menu(): void {
        if(current_user_can('manage_options')){
            add_submenu_page('dfsb-workflow','Context & Provider Audit','Context & Providers',
                'manage_options','dfsb-context-audit',array($this,'render'));
        }
    }
    public function render(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $catalog=Pages::catalog(100);
        echo '<div class="wrap"><h1>Context & Provider Discovery</h1>';
        echo '<p>Read-only registered CPTs and taxonomies. No filter publication, quote, booking, data migration or Safe Boot bypass.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Registered source</th><th>Context</th><th>Taxonomy / Owner</th><th>SEO description source</th><th>Filter provider</th><th>Pricing provider</th></tr></thead><tbody>';
        foreach($catalog['post_types'] as $type=>$definition){
            $context=$type==='post'?Pages::forPostsHome():
                ($type==='product' && \DynamicFilterSEOBridge\Context\WooCommerceContextResolver::available()
                    ?\DynamicFilterSEOBridge\Context\WooCommerceContextResolver::shop()
                    :Pages::forArchive($type));
            $seo=Seo::forContext($context,false);
            $providers=Providers::forContext($context);
            $this->row($definition['label'].' ('.$type.')',$context['page_kind'],
                implode(', ',array_slice($definition['taxonomies'],0,12)),
                $seo['description_source'],$providers['filter']['provider_id'],
                $providers['pricing']['provider_id']);
        }
        foreach($catalog['taxonomies'] as $tax=>$definition){
            $this->row($definition['label'].' ('.$tax.')','taxonomy',
                implode(', ',array_slice($definition['post_types'],0,8)),
                'native_term_description','requires_verified_binding','unresolved');
        }
        echo '</tbody></table>';
        echo '<p>Public CPTs: '.esc_html((string)count($catalog['post_types'])).'; public taxonomies: '.esc_html((string)count($catalog['taxonomies'])).'. '.($catalog['truncated']?'Inventory limited to 100 records per category.':'').'</p>';
        if(\DynamicFilterSEOBridge\Context\WooCommerceContextResolver::available()){
            $wc=\DynamicFilterSEOBridge\Context\WooCommerceContextResolver::catalog();
            echo '<h2>WooCommerce source authority (read-only)</h2>';
            echo '<p>Shop: '.esc_html((string)$wc['shop_id']).'; published products: '.esc_html((string)$wc['published_products']).'; drafts: '.esc_html((string)$wc['draft_products']).'.</p>';
            echo '<p>Registered WooCommerce product types: '.esc_html(implode(', ',$wc['registered_product_types'])).'.</p>';
            echo '<p>Public product taxonomies: '.esc_html(implode(', ',$wc['public_product_taxonomies'])).'.</p>';
            echo '<p>Product price/stock ownership stays with native WooCommerce. CPT-backed Rate pricing stays with its existing registry. Cart, Checkout and My Account are excluded from the public SEO source bridge. No Offer is generated.</p>';
        }
        echo '<h2>Verified presentation and pricing sample</h2>';
        $posts=get_posts(array('post_type'=>'tours-and-activities','post_status'=>'publish',
            'posts_per_page'=>1,'fields'=>'ids','suppress_filters'=>true));
        if($posts){
            $page=Pages::forPost((int)$posts[0]);
            $allProviders=Providers::forContext($page);
            $provider=$allProviders['pricing'];
            echo '<p>'.esc_html($page['title']).': '.esc_html($provider['provider_id']).
                ' — '.esc_html($provider['status']).'; can quote: no; can book: no.</p>';
            foreach($allProviders['related_components'] as $part){
                echo '<p>Optional component: '.esc_html($part['slot']).' via '.esc_html($part['provider_id']).' ('.esc_html($part['status']).'); booking: no.</p>';
            }
        }
        echo '<h2>Coverage matrix — source readiness vs release gates</h2>';
        echo '<p>The matrix flags unresolved commercial providers, language parity, native filter ownership and Elementor placement; it is not a certification.</p>';
        $matrix=\DynamicFilterSEOBridge\Diagnostics\ContextCoverageMatrix::sampleCatalog(30);
        echo '<table class="widefat striped"><thead><tr><th>Content Type</th><th>Context</th><th>Unique Copy</th><th>Provider</th><th>Schema</th><th>WPML/WCML</th><th>Presentation</th><th>Release</th></tr></thead><tbody>';
        foreach($matrix['items'] as $item){
            $check=$item['checks'];
            echo '<tr><td>'.esc_html($item['type']).'</td><td>'.esc_html($item['kind']).'</td>';
            foreach(array('unique_content','pricing_provider','schema','wpml_wcml','elementor_slots','safe_boot') as $column){
                echo '<td>'.esc_html((string)($check[$column]??'unresolved')).'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p>All provider IDs are observations, not authorizations. Prices, inventory, orders and service activation retain their original owners.</p>';
        \DynamicFilterSEOBridge\Admin\ContextPreviewPanel::render();
        echo '</div>';
    }
    private function row(string $name,string $kind,string $related,string $source,string $filter,string $pricing): void {
        echo '<tr><th>'.esc_html($name).'</th><td>'.esc_html($kind).'</td><td>'.esc_html($related).
            '</td><td>'.esc_html($source).'</td><td>'.esc_html($filter).
            '</td><td>'.esc_html($pricing).'</td></tr>';
    }
}
