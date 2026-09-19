<?php
define( 'ABSPATH', '/tmp/mad4b-context-doc-normalization/' );

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code=(string)$code; $this->message=(string)$message; $this->data=$data; }
	public function get_error_code(){ return $this->code; }
	public function get_error_message(){ return $this->message; }
	public function get_error_data(){ return $this->data; }
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v)); }
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }
function esc_url_raw($v){ return (string)$v; }
function wp_check_invalid_utf8($v,$strip=false){ return (string)$v; }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }

require dirname(__DIR__) . '/includes/class-mad4b-scp-google-drive-context.php';

function nassert($ok,$message,$context=null){
	if($ok) return;
	fwrite(STDERR,"FAIL: {$message}\n");
	if(null!==$context) fwrite(STDERR,json_encode($context,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
	exit(1);
}

$ref = new ReflectionClass('MAD4B_SCP_Google_Drive_Context');
$normalize = $ref->getMethod('normalize_binary_content'); $normalize->setAccessible(true);
$pdf = $ref->getMethod('normalize_pdf'); $pdf->setAccessible(true);

$rtf = "{\\rtf1\\ansi Brand \\b strategy\\b0\\par Tone of Voice}";
$r = $normalize->invoke(null,'application/rtf',$rtf,'brand.rtf');
nassert(!is_wp_error($r) && !empty($r['complete']) && false!==strpos($r['content'],'Brand'), 'RTF normalization must produce governed text.', $r);

$html = "<html><style>.x{}</style><body><h1>Brand</h1><p>Tone</p><script>x()</script></body></html>";
$r = $normalize->invoke(null,'text/html',$html,'brand.html');
nassert(!is_wp_error($r) && false!==strpos($r['content'],'Brand') && false===strpos($r['content'],'x()'), 'HTML normalization must exclude script/style content.', $r);

$svg = "<svg xmlns=\"http://www.w3.org/2000/svg\"><text>Visual brand rule</text></svg>";
$r = $normalize->invoke(null,'image/svg+xml',$svg,'brand.svg');
nassert(!is_wp_error($r) && false!==strpos($r['content'],'Visual brand rule'), 'SVG visible text must normalize locally.', $r);

$pdfText = "BT /F1 12 Tf (Brand Strategy) Tj ET";
$pdfBytes = "%PDF-1.4\n1 0 obj << /Length ".strlen($pdfText)." >>\nstream\n".$pdfText."\nendstream\nendobj\n%%EOF";
$r = $pdf->invoke(null,$pdfBytes);
nassert(!is_wp_error($r) && !empty($r['complete']) && false!==strpos($r['content'],'Brand Strategy'), 'Text PDF normalization must produce bounded text.', $r);

$scannedPdf = "%PDF-1.4\n1 0 obj << /Filter /DCTDecode /Length 4 >>\nstream\nABCD\nendstream\nendobj\n%%EOF";
$r = $pdf->invoke(null,$scannedPdf);
nassert(!is_wp_error($r) && empty($r['complete']) && 'extractor_required'===$r['normalization_status'] && 'pdf_ocr_required'===$r['normalization_reason'], 'Image-only PDF must route to OCR extractor instead of pretending completeness.', $r);

if (class_exists('ZipArchive')) {
	function makezip($entries){
		$tmp=tempnam(sys_get_temp_dir(),'mad4b-test-'); $z=new ZipArchive(); $z->open($tmp,ZipArchive::OVERWRITE);
		foreach($entries as $name=>$content) $z->addFromString($name,$content); $z->close();
		$data=file_get_contents($tmp); unlink($tmp); return $data;
	}
	$docx = makezip(array('word/document.xml'=>'<w:document xmlns:w="w"><w:body><w:p><w:r><w:t>Brand Core</w:t></w:r></w:p><w:p><w:r><w:t>Tone Guide</w:t></w:r></w:p></w:body></w:document>'));
	$r=$normalize->invoke(null,'application/vnd.openxmlformats-officedocument.wordprocessingml.document',$docx,'brand.docx');
	nassert(!is_wp_error($r)&&!empty($r['complete'])&&false!==strpos($r['content'],'Brand Core'),'DOCX normalization must extract document text.',$r);

	$xlsx = makezip(array(
		'xl/sharedStrings.xml'=>'<sst><si><t>Audience</t></si><si><t>Egypt</t></si></sst>',
		'xl/worksheets/sheet1.xml'=>'<worksheet><sheetData><row><c t="s"><v>0</v></c><c t="s"><v>1</v></c></row></sheetData></worksheet>'
	));
	$r=$normalize->invoke(null,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',$xlsx,'plan.xlsx');
	nassert(!is_wp_error($r)&&!empty($r['complete'])&&false!==strpos($r['content'],'Audience')&&false!==strpos($r['content'],'Egypt'),'XLSX normalization must extract workbook values.',$r);

	$pptx = makezip(array(
		'ppt/slides/slide1.xml'=>'<p:sld xmlns:p="p" xmlns:a="a"><a:t>Campaign Strategy</a:t></p:sld>',
		'ppt/notesSlides/notesSlide1.xml'=>'<p:notes xmlns:p="p" xmlns:a="a"><a:t>Speaker note</a:t></p:notes>'
	));
	$r=$normalize->invoke(null,'application/vnd.openxmlformats-officedocument.presentationml.presentation',$pptx,'deck.pptx');
	nassert(!is_wp_error($r)&&!empty($r['complete'])&&false!==strpos($r['content'],'Campaign Strategy')&&false!==strpos($r['content'],'Speaker note'),'PPTX normalization must extract slides and notes.',$r);

	$odt=makezip(array('content.xml'=>'<office:document-content xmlns:office="office" xmlns:text="text"><text:p>Editorial Guide</text:p></office:document-content>'));
	$r=$normalize->invoke(null,'application/vnd.oasis.opendocument.text',$odt,'guide.odt');
	nassert(!is_wp_error($r)&&!empty($r['complete'])&&false!==strpos($r['content'],'Editorial Guide'),'ODT normalization must extract visible content.',$r);

	$formZip=makezip(array('form.json'=>'{"title":"Brand Survey","question":"Preferred tone"}','notes.txt'=>'Campaign research'));
	$r=$normalize->invoke(null,'application/zip',$formZip,'form.zip');
	nassert(!is_wp_error($r)&&!empty($r['complete'])&&false!==strpos($r['content'],'Brand Survey'),'Generic ZIP normalization must extract Google Forms/download text payloads.',$r);

	$epub=makezip(array('OEBPS/ch1.xhtml'=>'<html><body><h1>Writer Reference</h1><p>Structure</p></body></html>'));
	$r=$normalize->invoke(null,'application/epub+zip',$epub,'reference.epub');
	nassert(!is_wp_error($r)&&!empty($r['complete'])&&false!==strpos($r['content'],'Writer Reference'),'EPUB normalization must extract reading text.',$r);
}

echo "mad4b.site-control-plane.context-document-normalization.runtime.v2: PASS\n";
