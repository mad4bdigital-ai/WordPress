<?php
/**
 * Regression contract for the 19 October 2026 ETG Staging PHP deprecation log.
 *
 * Must run on PHP 7.4 and the latest PHP without booting WordPress. Only
 * signatures are inspected; no site state, SEO state or production is mutated.
 */
$root = dirname( __DIR__ );
$cases = array(
    'includes/Presentation/functions.php' => array( 'context' => 5 ),
    'includes/Bootstrap.php' => array( 'context' => 2 ),
    'includes/JetEngine/QueryIdentityResolver.php' => array( 'queryProvider' => 1 ),
    'includes/JetSmartFilters/FilterDefinitionInspector.php' => array( 'templateProvider' => 1, 'filterProvider' => 1 ),
    'includes/Runtime/RuntimeTopologyDiscoverer.php' => array( 'templateProvider' => 1, 'queryProvider' => 1, 'templateReferenceProvider' => 1 ),
    'includes/Runtime/RuntimeQueryBindingResolver.php' => array( 'topology' => 1, 'identity' => 1 ),
    'includes/Context/FilterContextBuilder.php' => array( 'ajaxParser' => 1 ),
    'includes/Terms/TermMetaReader.php' => array( 'mediaRegistry' => 1 ),
    'includes/SEO/PublicationRegistry.php' => array( 'cache' => 1 ),
    'includes/SEO/PublicationResultCountProbe.php' => array( 'languages' => 1 ),
    'includes/Acceptance/LiveAcceptanceProvider.php' => array( 'caseSelector' => 1, 'normalizer' => 1, 'reducer' => 1 ),
    'includes/Acceptance/SemanticQueryEvaluator.php' => array( 'bindingResolver' => 1, 'bindingProvider' => 1 ),
    'includes/Acceptance/CaseSelector.php' => array( 'termsProvider' => 1 ),
    'includes/Presentation/ContentSourceResolver.php' => array( 'fields' => 1 ),
    'includes/Presentation/PresentationResolver.php' => array( 'evidenceProvider' => 1, 'sources' => 1, 'context' => 9 ),
    'includes/Admin/DynamicContentPage.php' => array( 'sources' => 1 ),
    'includes/Admin/MediaLabPage.php' => array( 'previewContextProvider' => 1 ),
    'includes/Admin/JetEngineInspectorPage.php' => array( 'previewContextProvider' => 1 ),
);
$manifest_path = $root . '/installed-content-manifest.json';
$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
if ( ! is_array( $manifest ) || 'etg.dfsb.installed-content-manifest.v1' !== ( $manifest['contract'] ?? '' )
    || 'sha256' !== ( $manifest['algorithm'] ?? '' ) || ! isset( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
    throw new RuntimeException( 'ETG source manifest missing or invalid' );
}
$checked = 0;
foreach ( $cases as $relative => $parameters ) {
    $file = $root . '/' . $relative;
    $source = file_get_contents( $file );
    if ( ! is_string( $source ) ) throw new RuntimeException( 'Missing ETG file: ' . $relative );
    if ( empty( $manifest['files'][ $relative ] ) || ! hash_equals( (string) $manifest['files'][ $relative ], hash( 'sha256', $source ) ) ) {
        throw new RuntimeException( 'ETG installed-file integrity drift: ' . $relative );
    }
    foreach ( $parameters as $param => $expected ) {
        $name = preg_quote( (string) $param, '/' );
        $explicit = '/\?[A-Za-z_][A-Za-z0-9_]*\s*\$' . $name . '\s*=\s*null\b/';
        $implicit = '/(?<![A-Za-z0-9_?])[A-Za-z_][A-Za-z0-9_]*\s*\$' . $name . '\s*=\s*null\b/';
        $observed = preg_match_all( $explicit, $source );
        if ( $observed !== $expected || preg_match( $implicit, $source ) ) {
            throw new RuntimeException( 'Implicit nullable compatibility drift: ' . $relative . ':$' . $param );
        }
        $checked += $observed;
    }
}
if ( 40 !== $checked || 18 !== count( $cases ) ) throw new RuntimeException( 'Unexpected ETG deprecation evidence coverage' );
echo "PASS ETG explicit nullable PHP signatures: 40 parameters / 18 files; installed-content SHA-256 verified; no site mutation\n";
