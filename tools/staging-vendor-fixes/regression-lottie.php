<?php
// Run the actual source switch without booting Elementor/WordPress.
set_error_handler(function ($level, $message) { throw new ErrorException($message, 0, $level); });
$source = file_get_contents($argv[1] . '/jet-elements/includes/addons/jet-elements-lottie.php');
if (!preg_match('/switch\s*\(\s*\$source\s*\)\s*\{(.*?)\n\t\t\};/s', $source, $match)) {
    throw new RuntimeException('Lottie render switch not found');
}
$snippet = 'switch ($source) {' . $match[1] . "\n};";
// A semicolon case separator compiles on PHP 7.4/8.3 but is deprecated on
// PHP 8.5. Check tokens as well so every lane catches the source regression.
$case = false;
foreach (token_get_all('<?php ' . $snippet) as $token) {
    if (is_array($token) && T_CASE === $token[0]) $case = true;
    elseif ($case && is_string($token) && in_array($token, array(':', ';'), true)) {
        if (';' === $token) throw new RuntimeException('Deprecated case separator');
        $case = false;
    }
}
function esc_url($url) { return $url; }
$render = static function ($source, $settings) use ($snippet) {
    return eval($snippet . ' return $path;');
};
foreach (array(
    array('json_file', array('json_file' => array('url' => 'https://example.test/local.json')), 'https://example.test/local.json'),
    array('external_url', array('external_url' => 'https://example.test/external.json'), 'https://example.test/external.json'),
    array('unknown', array(), ''),
) as $case) {
    if ($render($case[0], $case[1]) !== $case[2]) throw new RuntimeException('Lottie source dispatch changed');
}
echo "PASS Lottie: PHP 8.5 case syntax and original local/external/default dispatch\n";
