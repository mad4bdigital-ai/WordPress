<?php
/** Pure local DOCX/XLSX/PDF bytes; no Drive API, macros, or WordPress DB writes. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');
class WP_Error {private $c; public function __construct($c,$msg=''){$this->c=$c;}public function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_check_invalid_utf8($v,$strip=false){return (string)$v;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function expect($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-context-local-document-normalizer.php';
$c='MAD4B_SCP_Context_Local_Document_Normalizer';
$x=$c::normalize_local_spreadsheet_bytes('application/json','{}');expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_context_spreadsheet_mime_invalid','Reject non-OOXML Excel');
$x=$c::normalize_local_pdf_bytes('garbage');expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_context_pdf_invalid','Reject fake PDF');
$x=$c::normalize_local_pdf_bytes(str_repeat('x',10485761));expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_context_local_pdf_limit','Bounded PDF size');
$x=$c::normalize_local_docx_bytes('application/zip','invalid');expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_context_docx_mime_invalid','Reject generic ZIP and macros');
$x=$c::normalize_local_docx_bytes('application/vnd.openxmlformats-officedocument.wordprocessingml.document',str_repeat('z',10485761));expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_context_docx_size_invalid','Bounded DOCX size');
if(class_exists('ZipArchive')){
 $tmp=tempnam(sys_get_temp_dir(),'md4bdox-');$zip=new ZipArchive();expect($zip->open($tmp,ZipArchive::OVERWRITE)===true,'Open temporary DOCX fixture');
 $zip->addFromString('word/document.xml','<w:document xmlns:w="urn:example"><w:body><w:p><w:r><w:t>Luxor source evidence</w:t></w:r></w:p></w:body></w:document>');$zip->close();
 $bytes=file_get_contents($tmp);unlink($tmp);
 $x=$c::normalize_local_docx_bytes('application/vnd.openxmlformats-officedocument.wordprocessingml.document',$bytes);
 expect(is_array($x)&&str_contains($x['content'],'Luxor source evidence')&&!empty($x['complete']),'Extract bounded read-only DOCX text');
 $xlsx=file_get_contents(__DIR__.'/fixtures/context-media-spreadsheet-sample.xlsx');expect(is_string($xlsx)&&strlen($xlsx)>10,'Existing XLSX fixture');
 $x=$c::normalize_local_spreadsheet_bytes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',$xlsx);
 expect(is_array($x)&&!empty($x['content'])&&$x['normalization_status']==='ready','Structured XLSX read without macro execution');
}else{
 $x=$c::normalize_local_docx_bytes('application/vnd.openxmlformats-officedocument.wordprocessingml.document','not-a-zip');
 expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_context_ziparchive_unavailable','Missing ZipArchive fails closed');
}
echo "PASS: local document normalization bounded, OOXML-only, PDF invalid, ZIP fallback and no remote calls\n";
