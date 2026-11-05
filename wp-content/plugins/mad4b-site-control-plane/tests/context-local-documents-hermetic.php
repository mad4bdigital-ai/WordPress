<?php
define('ABSPATH','/tmp/');
class WP_Error {private $code;public function __construct($c,$msg=''){$this->code=$c;}public function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_check_invalid_utf8($t,$strip=false){return preg_match('//u',$t)?$t:'';}
function sanitize_key($t){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$t));}
require dirname(__DIR__).'/includes/class-mad4b-scp-context-local-document-normalizer.php';
$makezip=static function(array $entries){
 $temp=tempnam(sys_get_temp_dir(),'mad4b-ztest-');
 $zip=new ZipArchive();
 if($zip->open($temp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot create test archive');
 foreach($entries as $name=>$content)$zip->addFromString($name,$content);
 $zip->close();$bytes=file_get_contents($temp);unlink($temp);return $bytes;
};
$docx=$makezip([
 '[Content_Types].xml'=>'<Types/>',
 'word/document.xml'=>'<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>First brand sentence</w:t></w:r></w:p></w:body></w:document>'
]);
$a=MAD4B_SCP_Context_Local_Document_Normalizer::normalize_local_docx_bytes('application/vnd.openxmlformats-officedocument.wordprocessingml.document',$docx);
$checks=[];
$checks['docx_content']=!is_wp_error($a)&&!empty($a['complete'])&&str_contains($a['content'],'First brand sentence');
$checks['docx_mime_denied']=is_wp_error(MAD4B_SCP_Context_Local_Document_Normalizer::normalize_local_docx_bytes('application/x-zip',$docx));
$pdf="%PDF-1.4\n1 0 obj << /Length 25 >>\nstream\nBT (PDF sample) Tj ET\nendstream\nendobj\n%%EOF";
$b=MAD4B_SCP_Context_Local_Document_Normalizer::normalize_local_pdf_bytes($pdf);
$checks['pdf_content']=!is_wp_error($b)&&!empty($b['complete'])&&str_contains($b['content'],'PDF sample');
$xlsx=$makezip([
 'xl/workbook.xml'=>'<?xml version="1.0"?><workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
 'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0"?><Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
 'xl/worksheets/sheet1.xml'=>'<?xml version="1.0"?><worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Sheet brand cell</t></is></c></row></sheetData></worksheet>'
]);
$c=MAD4B_SCP_Context_Local_Document_Normalizer::normalize_local_spreadsheet_bytes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',$xlsx);
$checks['xlsx_content']=!is_wp_error($c)&&!empty($c['complete'])&&str_contains($c['content'],'Sheet brand cell');
if(is_wp_error($c))echo 'XLSX_ERROR '.$c->get_error_code()."\n";
$checks['xlsx_rejects_oversize']=is_wp_error(MAD4B_SCP_Context_Local_Document_Normalizer::normalize_local_spreadsheet_bytes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',str_repeat('a',10485761)));
foreach($checks as $name=>$ok)echo ($ok?'PASS':'FAIL')." $name\n";
echo 'RESULT '.count(array_filter($checks)).'/'.count($checks)."\n";
exit(count(array_filter($checks))===count($checks)?0:1);

