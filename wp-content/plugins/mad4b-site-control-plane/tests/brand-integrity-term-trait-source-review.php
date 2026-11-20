<?php
/**
 * Exact source-only certification of a PHP Trait extraction; no WP loading.
 * Historical source identity is verified byte-for-byte after reconstructing
 * the original file, so no arbitrary successor can satisfy a SHA pin.
 */
// Standalone hermetic CLI test may define ABSPATH; imported code still fails closed.
if (!defined('ABSPATH')) {
    if (basename($_SERVER['SCRIPT_FILENAME']??'') !== basename(__FILE__)) exit;
    define('ABSPATH', dirname(__DIR__).'/');
}
function mad4b_brand_integrity_trait_exact_source_review($plugin_root) {
    $root = rtrim((string)$plugin_root,'/');
    $main = $root.'/includes/class-mad4b-scp-brand-integrity.php';
    $trait = $root.'/includes/trait-mad4b-scp-brand-integrity-term-meta.php';
    if (!is_file($main) || is_link($main) || !is_file($trait) || is_link($trait)) return false;
    $a = file_get_contents($main);
    $b = file_get_contents($trait);
    if (!is_string($a) || !is_string($b) ||
        !hash_equals('684a560a851f090ef9f4b7dce32dc134b4507143b6024985594acc29df0b58da',hash('sha256',$a)) ||
        !hash_equals('4cbf2b88908cfca9c865cbb2fc68cbf82bbc6e543bb0449c704e26ead680151c',hash('sha256',$b))) return false;
    $header = <<<'HDR'
<?php
/**
 * Read-only Term Meta and destination taxonomy integrity.
 * Exact extracted methods from MAD4B_SCP_Brand_Integrity; no behavioral changes.
 * This is a Trait owned by the original final class, not a new global writer.
 */
defined('ABSPATH') || exit;

trait MAD4B_SCP_Brand_Integrity_Term_Meta_Trait {
HDR;
    // PHP nowdoc excludes the terminal newline, so restore it for exact proof.
    $header .= "\n";
    if (strpos($b,$header)!==0 || substr($b,-2)!=="}\n") return false;
    $methods = substr($b,strlen($header),-2);
    $loader = "require_once __DIR__.'/trait-mad4b-scp-brand-integrity-term-meta.php';\n";
    $use = "    use MAD4B_SCP_Brand_Integrity_Term_Meta_Trait;\n";
    $anchor = '    public static function scan_batch(array $types';
    if (substr_count($a,$loader)!==1 || substr_count($a,$use)!==1 ||
        substr_count($a,$anchor)!==1 ||
        substr_count($methods,'    public static function scan_term_report(')!==1) return false;
    $prior = str_replace($loader,'',$a);
    $prior = str_replace($use,'',$prior);
    $prior = str_replace($anchor,$methods.$anchor,$prior);
    return hash_equals('7a6f444a034a70418f2adf95d8f9d25e83f4f6cdba33d0c984687125826da58e',hash('sha256',$prior));
}

if (basename($_SERVER['SCRIPT_FILENAME']??'') === basename(__FILE__)) {
    $root=dirname(__DIR__);
    if (!mad4b_brand_integrity_trait_exact_source_review($root)) {
        fwrite(STDERR,"BRAND_TRAIT_EXACT_SOURCE_REJECTED\n");exit(1);
    }
    echo "PASS: BRAND_TRAIT_EXACT_SOURCE_RECONSTRUCTION (CI-only, no writes)\n";
}
