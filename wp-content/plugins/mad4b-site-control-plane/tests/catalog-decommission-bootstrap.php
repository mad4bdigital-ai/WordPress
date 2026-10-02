<?php
// The CLI can run from a checkout while WordPress loaded a different plugin copy.
define( 'WP_CLI', true ); define( 'ABSPATH', __DIR__ );
define( 'MAD4B_CATALOG_DECOMMISSION_LIBRARY', true );
class MAD4B_SCP_Catalog_Object_Store { const DIRECTORY = 'fixture'; const GC_CURSOR = 'cursor'; const READER_GRACE_SECONDS = 3600; }
function current_user_can() { return false; }
require __DIR__ . '/../tools/catalog-decommission.php';
if ( ! class_exists( 'MAD4B_Catalog_Decommission', false ) ) throw new RuntimeException( 'CLI class did not bootstrap' );
try { MAD4B_Catalog_Decommission::plan(); throw new LogicException( 'Unauthorized plan accepted' ); }
catch ( RuntimeException $error ) { if ( 'Administrator authority required' !== $error->getMessage() ) throw $error; }
echo "PASS CLI bootstrap reuses loaded catalog classes across checkout/install paths and denies unauthorized planning\n";
