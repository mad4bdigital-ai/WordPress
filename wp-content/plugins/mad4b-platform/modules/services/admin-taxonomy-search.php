<?php
/**
 * Plugin Name: TripKlik Admin Taxonomy Search
 * Description: Adds a lightweight search field to Classic Editor taxonomy meta boxes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'TK_ADMIN_TAXONOMY_SEARCH_ENABLED' ) && ! TK_ADMIN_TAXONOMY_SEARCH_ENABLED ) {
    return;
}

add_action( 'admin_enqueue_scripts', function ( $hook ): void {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
        return;
    }

    wp_enqueue_script( 'jquery' );

    $script = <<<'JS'
(function($){
    function addTaxSearch(context) {
        $('.categorydiv, .tagsdiv', context || document).each(function(){
            var box = $(this);
            if (box.find('> .tk-tax-search-input-wrap').length) return;

            var searchWrap = $('<div class="tk-tax-search-input-wrap" style="padding:6px 0;"></div>');
            var search = $('<input type="search" class="tk-tax-search-input" placeholder="Search terms..." style="width:100%;box-sizing:border-box;">');
            searchWrap.append(search);

            var checklist = box.find('.categorychecklist').first();
            var tagCloud = box.find('.tagchecklist').first();

            if (checklist.length) {
                checklist.before(searchWrap);
                search.on('input', function(){
                    var term = $(this).val().toLowerCase();
                    checklist.find('li').each(function(){
                        var item = $(this);
                        var directText = item.clone().children('ul').remove().end().text().toLowerCase();
                        var childText = item.find('ul').text().toLowerCase();
                        var match = directText.indexOf(term) !== -1 || childText.indexOf(term) !== -1;
                        item.toggle(!term || match);
                    });
                });
                return;
            }

            if (tagCloud.length) {
                tagCloud.before(searchWrap);
                search.on('input', function(){
                    var term = $(this).val().toLowerCase();
                    tagCloud.find('span').each(function(){
                        var item = $(this);
                        item.toggle(!term || item.text().toLowerCase().indexOf(term) !== -1);
                    });
                });
            }
        });
    }

    $(function(){
        addTaxSearch(document);

        if (window.MutationObserver) {
            var observer = new MutationObserver(function(mutations){
                mutations.forEach(function(mutation){
                    if (mutation.addedNodes && mutation.addedNodes.length) {
                        addTaxSearch(mutation.target);
                    }
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });
})(jQuery);
JS;

    wp_add_inline_script( 'jquery', $script );
} );
