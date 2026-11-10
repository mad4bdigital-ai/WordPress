<?php
/** Read-only-to-WordPress filesystem fixture for unbounded managed Skills indexing. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress required' );
$root = sys_get_temp_dir() . '/mad4b-skills-page-' . getmypid();
$base = $root . '/workflow/bulk-index';
if ( ! wp_mkdir_p( $base ) ) throw new RuntimeException( 'Fixture directory unavailable' );
$cleanup = static function () use ( $root ) {
    if ( ! is_dir( $root ) ) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $files as $file ) {
        if ( $file->isLink() ) { @unlink( $file->getPathname() ); continue; }
        if ( $file->isDir() ) @rmdir( $file->getPathname() );
        else @unlink( $file->getPathname() );
    }
    @rmdir( $root );
};
register_shutdown_function( $cleanup );
$override = static function ( $value ) use ( $root ) { return $root; };
add_filter( 'mad4b_scp_skill_storage_root', $override );
for ( $i = 0; $i < 520; $i++ ) {
    $name = sprintf( 'bulk-%04d', $i );
    $dir = $base . '/' . $name;
    if ( ! wp_mkdir_p( $dir ) ) throw new RuntimeException( 'Unable to create fixture skill directory' );
    $body = "---\nname: " . $name . "\ndescription: Example test skill\n---\n\nFixture only.\n";
    if ( false === file_put_contents( $dir . '/SKILL.md', $body ) ) throw new RuntimeException( 'Fixture creation failed' );
}
$all = MAD4B_SCP_Skill_Registry::list_skills();
$first = MAD4B_SCP_Skill_Abilities::skills_list( array( 'limit' => 25, 'offset' => 0 ) );
$last = MAD4B_SCP_Skill_Abilities::skills_list( array( 'limit' => 25, 'offset' => 500 ) );
$query = MAD4B_SCP_Skill_Abilities::skills_list( array( 'limit' => 10, 'query' => 'bulk-051' ) );
$pass = count( $all ) === 520
    && $first['count'] === 25 && $first['total_count'] === 520 && $first['has_more']
    && $last['count'] === 20 && ! $last['has_more']
    && $first['skills'][0]['name'] === 'bulk-0000'
    && $last['skills'][19]['name'] === 'bulk-0519'
    && $query['total_count'] === 10;
remove_filter( 'mad4b_scp_skill_storage_root', $override );
if ( ! $pass ) throw new RuntimeException( 'Managed Skills pagination regression failed' );
echo wp_json_encode( array(
    'state' => 'PASS', 'skills_discovered' => count( $all ),
    'first_page' => $first['count'], 'last_page' => $last['count'],
    'query_total' => $query['total_count'], 'wordpress_data_mutated' => false
), JSON_PRETTY_PRINT ), PHP_EOL;
