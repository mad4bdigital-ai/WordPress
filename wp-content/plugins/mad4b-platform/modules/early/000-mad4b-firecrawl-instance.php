<?php
/** Site-only Firecrawl research identity; no passwords in this configuration. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'MAD4B_FIRECRAWL_CONSUMER_ID' ) ) {
    define( 'MAD4B_FIRECRAWL_CONSUMER_ID', 'mad4b-etg-commerce-control-plane' );
}
if ( ! defined( 'MAD4B_FIRECRAWL_ALLOWED_HOSTS' ) ) {
    define( 'MAD4B_FIRECRAWL_ALLOWED_HOSTS', array( 'example.com' ) );
}
