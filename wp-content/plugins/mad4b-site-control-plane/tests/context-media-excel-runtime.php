<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function add_action($name, $callback) { return true; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/','', (string)$value)); }
function wp_check_invalid_utf8($value, $strip = false) {
    if ( preg_match('//u',(string)$value) ) return (string)$value;
    return $strip ? iconv('UTF-8','UTF-8//IGNORE',(string)$value) : '';
}
require_once __DIR__ . '/../includes/class-mad4b-scp-google-drive-context.php';
require_once __DIR__ . '/../includes/class-mad4b-scp-wordpress-media-context.php';
$pass = 0;
$check = static function($label, $ok) use (&$pass) {
    echo $label . ': ' . ($ok ? 'PASS' : 'FAIL') . "\n";
    if (!$ok) exit(1);
    $pass++;
};
$fixture = file_get_contents(__DIR__.'/fixtures/context-media-spreadsheet-sample.xlsx');
$mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
$xlsx = MAD4B_SCP_WordPress_Media_Context::normalize_bytes($mime,$fixture);
$check('xlsx_structured_complete', is_array($xlsx) && $xlsx['content_complete'] === true);
$text = $xlsx['normalized_text'] ?? '';
$check('xlsx_names_preserved', strpos($text,'[Sheet: Tour Revenue; rows=6]') !== false && strpos($text,'[Sheet: Market Notes; rows=5]') !== false);
$check('xlsx_numeric_values_preserved', strpos($text,"4\tT-103\tAswan\tDE\t4\t2500\t10000") !== false);
$check('xlsx_header_columns_preserved', strpos($text,"1\tTour ID\tDestination\tMarket\tGuests\tUnit Price EGP\tRevenue EGP") !== false);
$xlsm = MAD4B_SCP_WordPress_Media_Context::normalize_bytes('application/vnd.ms-excel.sheet.macroEnabled.12',$fixture);
$check('xlsm_data_only', is_array($xlsm) && $xlsm['normalized_text'] === $text);
$csv = MAD4B_SCP_WordPress_Media_Context::normalize_bytes('text/csv',"Market,Visitors,Revenue\nEG,3,4500\nUK,2,6400\n");
$check('csv_table_readable', is_array($csv) && strpos($csv['normalized_text'],"2\tEG\t3\t4500") !== false);
$check('xls_legacy_rejected',is_wp_error(MAD4B_SCP_WordPress_Media_Context::normalize_bytes('application/vnd.ms-excel','OLE legacy xls')));
$check('spoofed_xlsx_rejected',is_wp_error(MAD4B_SCP_WordPress_Media_Context::normalize_bytes($mime,'not an Excel ZIP')));
$check('truncated_csv_rejected',is_wp_error(MAD4B_SCP_WordPress_Media_Context::normalize_bytes('text/csv', str_repeat('a',262145))));
$temp=tempnam(sys_get_temp_dir(),'mad4b-excel-fixture-');
file_put_contents($temp,$fixture);
$zip=new ZipArchive();
$check('zip_temp_open', $zip->open($temp)===true);
$xml=$zip->getFromName('xl/worksheets/sheet2.xml');
$modified=preg_replace('#<c r="B2"[^>]*>.*?</c>#s','',$xml,1,$n);
$zip->addFromString('xl/worksheets/sheet2.xml',$modified);$zip->close();
$sparse=MAD4B_SCP_WordPress_Media_Context::normalize_bytes($mime,file_get_contents($temp));
$check('blank_column_position_preserved', $n===1 && is_array($sparse) && strpos($sparse['normalized_text'],"2\tEG\t\tDay tours\t120")!==false);
$zip=new ZipArchive();$zip->open($temp);
$xml=$zip->getFromName('xl/worksheets/sheet1.xml');
$modified=preg_replace('#<c r="E2"[^>]*>.*?</c>#s','<c r="E2"><f>1+1</f></c>',$xml,1,$n);
$zip->addFromString('xl/worksheets/sheet1.xml',$modified);$zip->close();
$bad=MAD4B_SCP_WordPress_Media_Context::normalize_bytes($mime,file_get_contents($temp));
@unlink($temp);
$check('formula_without_cached_value_rejected', $n===1 && is_wp_error($bad) && $bad->get_error_code()==='mad4b_context_spreadsheet_formula_uncached');
echo "SUMMARY pass=$pass fail=0\n";
