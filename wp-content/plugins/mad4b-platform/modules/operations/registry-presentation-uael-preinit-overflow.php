<?php
/** Local Lab: prevent UAEL image gallery's uninitialized track from stretching the page. */
if (!defined('ABSPATH') || (!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)) return;
add_action('wp_head', static function (): void {
    if (is_admin()) return;
    echo '<style id="mad4b-uael-preinit-clip">'
       .'.elementor-element-d3b8065 .uael-img-gallery-wrap.uael-img-carousel-wrap:not(.slick-initialized){max-width:100%;overflow-x:clip;overflow-y:hidden;}'
       .'</style>';
}, 1);
