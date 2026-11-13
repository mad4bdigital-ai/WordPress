<?php
/** Strict offline cross-language proof check, NO WordPress boot or signer. */
require '/work/ops/dynamic-operation-registry-v1/php/class-mad4b-dynamic-313-verifier.php';
$fixture=json_decode(file_get_contents('/fixture/source-original313-proof.json'),true,512,JSON_THROW_ON_ERROR);
$check=static fn($proof,$authority)=>MAD4B_Dynamic_313_Proof_V1::verify(
  $proof,$fixture['trusted'],$fixture['original_job'],$authority,2000000000);
$accepted=$check($fixture['proof'],$fixture['original312']);
$badSignature=$fixture['proof'];$badSignature['signature']=base64_encode(str_repeat('!',64));
$deniedSig=$check($badSignature,$fixture['original312']);
$badSource=$fixture['proof'];$badSource['payload']['output_sha256']=str_repeat('0',64);
$deniedSource=$check($badSource,$fixture['original312']);
$badAuthority=$fixture['original312'];$badAuthority['transport_id']=str_repeat('7',32);
$deniedAuthority=$check($fixture['proof'],$badAuthority);
if (($accepted['verified']??false)!==true ||
    ($accepted['original_313_certified']??true)!==false ||
    ($deniedSig['verified']??true)!==false ||
    ($deniedSource['verified']??true)!==false ||
    ($deniedAuthority['verified']??true)!==false) {
 fwrite(STDERR,json_encode(['accept'=>$accepted,'sig'=>$deniedSig,
      'source'=>$deniedSource,'authority'=>$deniedAuthority]));
 exit(2);
}
echo json_encode(['state'=>'PASS','signed_dynamic_proof_verified'=>true,
 'bad_signature_refused'=>true,'tampered_postimage_refused'=>true,
 'changed_312_transport_refused'=>true,
 'actual_original313_receipt_issued'=>false,
 'production_site_modified'=>false],JSON_THROW_ON_ERROR);
