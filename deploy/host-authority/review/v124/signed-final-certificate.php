<?php
/**
 * First Host Anchor Original313 Final Receipt UI verification (read-only).
 *
 * All Royal Egypt / Staging, Revision 1 only. Uses the pinned ORIGINAL
 * independent public key, the enrolled ORIGINAL Broker public key, and the
 * immutable WordPress original318 signed readback. An option, a filename, or
 * a successful POST alone can NEVER certify the Host Anchor.
 */
defined('ABSPATH') || exit;
final class MAD4B_Host_Authority_Signed_Certificate_V123 {
    const OPTION='mad4b_host_authority_first_final_certificate_v123';
    const CONTRACT='mad4b.original313.host-anchor-final-owner-display.v123';
    const INDEPENDENT_PUB_B64='4jwcLgOUf2d/j2uDYbVysCnkSMJW64cXnhMPaeix11Y=';
    const JOB='5b21b7aae2de8e3ef9342ae2ceaa2802';
    const LEDGER='mad4b.original-host-current-revision.v1';
    const CHECKPOINT='mad4b.original-host-current-revision-checkpoint.v1';
    const RECEIPT='mad4b.original313.host-anchor-final-receipt.v1';
    const ISSUANCE='company.host-authority.anchor.issue';
    const LEDGER_FIELDS=array('contract','company','environment','site_uuid',
        'target_host_id','ssh_principal','revision','grant_revision',
        'anchor_nonce','state','event_id','previous_event_sha256',
        'issued_epoch','expires_epoch');
    const CHECKPOINT_FIELDS=array('contract','company','environment','site_uuid',
        'revision','grant_revision','anchor_nonce','state','event_sha256',
        'issued_epoch','expires_epoch');
    const RECEIPT_FIELDS=array('contract','job_id','operation_id','company',
        'environment','site_uuid','revision','anchor_nonce','state',
        'event_sha256','checkpoint_sha256','wp_signed_message_sha256',
        'issued_epoch');

    private static function exact_keys(array $value,array $keys):bool {
        $actual=array_keys($value);
        sort($actual,SORT_STRING);
        sort($keys,SORT_STRING);
        return $actual===$keys;
    }
    private static function signed_body(array $row,array $fields,
        string $domain,string $pub):?string {
        if(!self::exact_keys($row,array_merge($fields,array('signature')))
            ||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            ||!is_string($row['signature']??null)) return null;
        $sig=base64_decode($row['signature'],true);
        if(!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
            return null;
        $body=array();
        foreach($fields as $field)$body[$field]=$row[$field];
        ksort($body,SORT_STRING);
        // Bit-for-bit Python json.dumps(sort_keys=True, separators=(',',':'),
        // ensure_ascii=False), for the original ASCII identity fields.
        $canonical=wp_json_encode($body,
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($canonical))return null;
        $signed=$domain."\n".$canonical;
        return sodium_crypto_sign_verify_detached($sig,$signed,$pub)
            ?hash('sha256',$signed):null;
    }
    public static function verified_summary(array $anchor):?array {
        if(!function_exists('sodium_crypto_sign_verify_detached')
            ||!class_exists('MAD4B_Central_VPS_Broker_V1',false)
            ||($anchor['state']??null)!=='ACTIVE'
            ||($anchor['revision']??null)!==1
            ||($anchor['readback_verified']??null)!==true)
            return null;
        $bundle=get_option(self::OPTION,false);
        if(!is_array($bundle)
            ||!self::exact_keys($bundle,array('contract','job_id',
                'revision_event','checkpoint','receipt'))
            ||($bundle['contract']??null)!==self::CONTRACT
            ||($bundle['job_id']??null)!==self::JOB) return null;
        $ledger=$bundle['revision_event'];
        $cp=$bundle['checkpoint'];
        $receipt=$bundle['receipt'];
        if(!is_array($ledger)||!is_array($cp)||!is_array($receipt)
            ||!self::exact_keys($ledger,array_merge(self::LEDGER_FIELDS,
                array('signature')))
            ||!self::exact_keys($cp,array_merge(self::CHECKPOINT_FIELDS,
                array('signature')))
            ||!self::exact_keys($receipt,array_merge(self::RECEIPT_FIELDS,
                array('signature'))))
            return null;
        $independent=base64_decode(self::INDEPENDENT_PUB_B64,true);
        $broker=base64_decode((string)get_option(
            MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
        if(!is_string($independent)||strlen($independent)!==32
            ||!is_string($broker)||strlen($broker)!==32
            ||hash_equals($independent,$broker)) return null;
        $event_sha=self::signed_body($ledger,self::LEDGER_FIELDS,
            'MAD4B_ORIGINAL_HOST_LEDGER_V1',$broker);
        $checkpoint_sha=self::signed_body($cp,self::CHECKPOINT_FIELDS,
            'MAD4B_ORIGINAL_HOST_CHECKPOINT_V1',$independent);
        $receipt_sha=self::signed_body($receipt,self::RECEIPT_FIELDS,
            'MAD4B_ORIGINAL313_HOST_FINAL_RECEIPT_V1',$independent);
        if(!$event_sha||!$checkpoint_sha||!$receipt_sha) return null;
        if(($ledger['contract']??null)!==self::LEDGER
            ||($cp['contract']??null)!==self::CHECKPOINT
            ||($receipt['contract']??null)!==self::RECEIPT
            ||($ledger['event_id']??null)!==self::JOB
            ||($receipt['job_id']??null)!==self::JOB
            ||($receipt['operation_id']??null)!==self::ISSUANCE
            ||($ledger['target_host_id']??null)!=='hosting-2'
            ||!preg_match('/^[a-f0-9]{32}$/D',
                (string)($ledger['anchor_nonce']??''))) return null;
        foreach(array($ledger,$cp,$receipt) as $row) {
            if(($row['company']??null)!=='all-royal-egypt'
                ||($row['environment']??null)!=='staging'
                ||($row['revision']??null)!==1
                ||($row['state']??null)!=='ACTIVE'
                ||($row['site_uuid']??null)!==$ledger['site_uuid']
                ||($row['anchor_nonce']??null)!==$ledger['anchor_nonce'])
                return null;
        }
        if(($cp['event_sha256']??null)!==$event_sha
            ||($receipt['event_sha256']??null)!==$event_sha
            ||($receipt['checkpoint_sha256']??null)!==$checkpoint_sha
            ||($anchor['anchor_nonce']??null)!==$ledger['anchor_nonce']
            ||($receipt['wp_signed_message_sha256']??null)!==
                ($anchor['signed_anchor_message_sha256']??null)
            ||!is_int($receipt['issued_epoch']??null)
            ||$receipt['issued_epoch']<=0
            ||$receipt['issued_epoch']>time()+10
            ||($ledger['grant_revision']??null)!==($cp['grant_revision']??null))
            return null;
        return array(
            'verified'=>true,
            'revision'=>1,
            'job_id'=>self::JOB,
            'issued_epoch'=>$receipt['issued_epoch'],
            'state'=>'ACTIVE',
            'certificate'=>'Original313 Final Receipt',
            'readback_verified'=>true
        );
    }
}
