<?php
declare(strict_types=1);
/**
 * REV145: central MariaDB state fence for independently verified signed permits.
 * This is NOT a permission issuer. Do not instantiate using tenant DB credentials
 * in production. Use a separate Authority Service DB identity.
 */
final class MAD4B_Authority_Ledger {
    private mysqli $db;
    private string $permits;
    private string $events;
    public function __construct(mysqli $connection,string $prefix='mad4b_authority_') {
        if(!preg_match('/^[a-z][a-z0-9_]{0,40}$/D',$prefix))throw new InvalidArgumentException('Invalid schema prefix');
        $this->db=$connection;
        $this->permits=$prefix.'permits';
        $this->events=$prefix.'events';
    }
    /** Operator migration, not automatically executed on WordPress load. */
    public function install():void {
        if(!$this->db->query("CREATE TABLE IF NOT EXISTS `{$this->permits}` (
          `permit_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `intent_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `operation_id` VARCHAR(120) NOT NULL,
          `nonce` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `site_uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `environment` VARCHAR(16) NOT NULL,
          `origin` VARCHAR(255) NOT NULL,
          `action` VARCHAR(48) NOT NULL,
          `artifact_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `plan_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `approval_mode` VARCHAR(24) NOT NULL DEFAULT 'independent_actors',
          `owner_actor_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
          `owner_enrollment_receipt_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
          `state` VARCHAR(24) NOT NULL DEFAULT 'issued',
          `issued_at` BIGINT NOT NULL,
          `expires_at` BIGINT NOT NULL,
          `reserved_at` BIGINT DEFAULT NULL,
          `finalized_at` BIGINT DEFAULT NULL,
          `reservation_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
          `outcome_receipt_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
          `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
          PRIMARY KEY (`permit_sha256`), UNIQUE KEY `nonce_unique` (`nonce`),
          UNIQUE KEY `operation_unique` (`operation_id`), KEY `scope` (`site_uuid`,`environment`,`state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"))throw new RuntimeException("Authority permits DDL failed");
        if(!$this->db->query("CREATE TABLE IF NOT EXISTS `{$this->events}` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `permit_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `event_code` VARCHAR(48) NOT NULL,
          `state_before` VARCHAR(24) NOT NULL,
          `state_after` VARCHAR(24) NOT NULL,
          `event_at` BIGINT NOT NULL,
          `receipt_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
          PRIMARY KEY (`id`), KEY `permit_chain` (`permit_sha256`,`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"))throw new RuntimeException("Authority audit DDL failed");
    }
    private function txn(callable $fn):mixed {
        if(!$this->db->begin_transaction())throw new RuntimeException('Authority transaction start failed');
        try {$r=$fn();if(!$this->db->commit())throw new RuntimeException('Authority commit failed');return $r;}
        catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    private static function hash(string $h):bool{return preg_match('/^[a-f0-9]{64}$/D',$h)===1;}
    private static function token(string $v):bool{return preg_match('/^[a-f0-9]{32,64}$/D',$v)===1;}
    private function event(string $permit,string $code,string $before,string $after,int $now,?string $receipt=null):void {
        $sql="INSERT INTO `{$this->events}` (permit_sha256,event_code,state_before,state_after,event_at,receipt_sha256) VALUES (?,?,?,?,?,?)";
        $s=$this->db->prepare($sql);$s->bind_param('ssssis',$permit,$code,$before,$after,$now,$receipt);if(!$s->execute())throw new RuntimeException('Authority statement failed', (int)$s->errno);$s->close();
    }
    private function row(string $sha,bool $lock=false):?array {
        $sql="SELECT * FROM `{$this->permits}` WHERE permit_sha256=?".($lock?' FOR UPDATE':'');
        $s=$this->db->prepare($sql);$s->bind_param('s',$sha);if(!$s->execute())throw new RuntimeException('Authority select failed', (int)$s->errno);
        $r=$s->get_result()->fetch_assoc();$s->close();return $r?:null;
    }
    /** Register only an already independently verified CA permit. */
    public function registerVerified(array $permitEnvelope,array $trust,array $intent,int $now,
        ?callable $ownerGrantActive=null):array {
        if(!class_exists('MAD4B_Independent_Authority'))throw new LogicException('Policy core not loaded');
        $verified=MAD4B_Independent_Authority::verifyForExecution(
            $permitEnvelope,$trust,$intent,static fn()=>true,$now,$ownerGrantActive);
        // The callback above is ONLY an envelope/scope preflight. Replay safety
        // comes from unique constraints and transactional state transitions below.
        if(($verified['valid']??false)!==true) return ['registered'=>false,'reason'=>$verified['code']??'invalid_permit'];
        $p=MAD4B_Independent_Authority::verified($permitEnvelope,$trust,MAD4B_Independent_Authority::CONTRACT)['payload'];
        if(($p['operation_id']??null)!==($intent['operation_id']??null))return ['registered'=>false,'reason'=>'operation_id_mismatch'];
        $sha=$verified['permit_sha256'];$nonce=$p['nonce'];$operation=$p['operation_id'];
        $site=$p['site_uuid'];$env=$p['environment'];$origin=$p['origin'];$action=$p['action'];$artifact=$p['artifact_sha256'];$plan=$p['plan_sha256'];
        $intentSha=$verified['intent_sha256'];$issued=(int)$p['issued_at'];$exp=(int)$p['expires_at'];
        $mode=$p['approval_mode']??'independent_actors';
        $actorSha=$p['owner_actor_sha256']??null;
        $enrollmentSha=$p['owner_enrollment_receipt_sha256']??null;
        if(!self::hash($sha)||!self::token($nonce)||$env!=='staging')return ['registered'=>false,'reason'=>'invalid_scope'];
        try{return $this->txn(function()use($sha,$intentSha,$operation,$nonce,$site,$env,$origin,$action,$artifact,$plan,$mode,$actorSha,$enrollmentSha,$issued,$exp,$now){
            $sql="INSERT INTO `{$this->permits}` (permit_sha256,intent_sha256,operation_id,nonce,site_uuid,environment,origin,action,artifact_sha256,plan_sha256,approval_mode,owner_actor_sha256,owner_enrollment_receipt_sha256,issued_at,expires_at,state) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'issued')";
            $s=$this->db->prepare($sql);$s->bind_param('sssssssssssssii',$sha,$intentSha,$operation,$nonce,$site,$env,$origin,$action,$artifact,$plan,$mode,$actorSha,$enrollmentSha,$issued,$exp);if(!$s->execute())throw new RuntimeException('Authority statement failed', (int)$s->errno);$s->close();
            $this->event($sha,'permit_registered','absent','issued',$now);
            return ['registered'=>true,'state'=>'issued','permit_sha256'=>$sha];
        });}catch(Throwable $e){if((int)$e->getCode()===1062)return ['registered'=>false,'reason'=>'duplicate_nonce_operation_or_permit'];throw $e;}
    }
    /** Exactly one caller receives a token. On crash: NO automatic reset/re-issue. */
    public function reserve(string $sha,string $site,string $artifact,string $plan,int $now,
        ?callable $ownerGrantActive=null):array {
        if(!self::hash($sha)||!self::hash($artifact)||!self::hash($plan))return ['reserved'=>false,'reason'=>'invalid_identity'];
        return $this->txn(function()use($sha,$site,$artifact,$plan,$now,$ownerGrantActive){
            $r=$this->row($sha,true);
            if(!$r||$r['state']!=='issued'||$r['site_uuid']!==$site||
                $r['environment']!=='staging'||$r['artifact_sha256']!==$artifact||
                $r['plan_sha256']!==$plan||(int)$r['expires_at']<=$now)
                return ['reserved'=>false,'reason'=>'missing_expired_scope_or_consumed'];
            if(($r['approval_mode']??'independent_actors')==='single_owner' &&
                ($ownerGrantActive===null||
                 $ownerGrantActive($r['owner_enrollment_receipt_sha256'],
                     $r['owner_actor_sha256'],$r['site_uuid'],$r['action'])!==true))
                return ['reserved'=>false,'reason'=>'owner_grant_revoked_at_reservation'];

            $token=bin2hex(random_bytes(32));$tokenSha=hash('sha256',$token);
            $s=$this->db->prepare("UPDATE `{$this->permits}` SET state='reserved',reservation_sha256=?,reserved_at=?,revision=revision+1 WHERE permit_sha256=? AND state='issued'");
            $s->bind_param('sis',$tokenSha,$now,$sha);if(!$s->execute())throw new RuntimeException('Authority CAS failed: '.(int)$this->db->errno);$ok=$s->affected_rows===1;$s->close();
            if(!$ok)throw new RuntimeException('Concurrent CAS rejected reservation');
            $this->event($sha,'permit_reserved','issued','reserved',$now);
            return ['reserved'=>true,'reservation_token'=>$token,'state'=>'reserved','permit_sha256'=>$sha];
        });
    }
    /**
     * Mark applied ONLY when an independently signed healthy completion
     * certificate matches the reserved permit and site. A raw SHA or callback
     * returning true can never transition a permit to applied.
     * Failure and rollback remain unresolved until independently certified.
     */
    public function completeCertified(string $sha,string $token,array $certificateEnvelope,array $certificateTrust,int $now):array {
        if(!self::hash($sha)||!self::token($token)||!class_exists('MAD4B_Independent_Authority'))
            return ['completed'=>false,'reason'=>'invalid_completion_input'];
        $proof=MAD4B_Independent_Authority::verified($certificateEnvelope,$certificateTrust,
            MAD4B_Independent_Authority::CERTIFICATE);
        if(($proof['valid']??false)!==true)return ['completed'=>false,'reason'=>'untrusted_certificate'];
        $cert=$proof['payload'];
        if(!is_array($cert)||array_diff(array_keys($cert),['contract','issuer','permit_sha256','runtime_receipt_sha256','site_uuid','environment','origin','artifact_sha256','runtime_sha256','action','result','issued_at','expires_at']) ||
            ($cert['issuer']??null)!=='mad4b.local-operational-authority'||
            ($cert['contract']??null)!==MAD4B_Independent_Authority::CERTIFICATE||
            ($cert['permit_sha256']??null)!==$sha||($cert['environment']??null)!=='staging'||
            ($cert['result']??null)!=='healthy_attested'||!self::hash((string)($cert['runtime_receipt_sha256']??''))||
            !self::hash((string)($cert['runtime_sha256']??''))||
            !is_int($cert['issued_at']??null)||!is_int($cert['expires_at']??null)||
            $cert['issued_at']>$now||$cert['issued_at']<$now-300||$cert['expires_at']<=$now)
            return ['completed'=>false,'reason'=>'certificate_scope_or_freshness'];
        $receiptSha=$proof['receipt_sha256'];
        return $this->txn(function()use($sha,$token,$cert,$receiptSha,$now){
            $r=$this->row($sha,true);
            if(!$r||$r['state']!=='reserved'||!hash_equals((string)$r['reservation_sha256'],hash('sha256',$token))||
                ($cert['site_uuid']??null)!==$r['site_uuid']||
                ($cert['origin']??null)!==$r['origin']||
                ($cert['action']??null)!==$r['action']||
                ($cert['artifact_sha256']??null)!==$r['artifact_sha256'])
                return ['completed'=>false,'reason'=>'reservation_or_certificate_scope_mismatch'];
            // Reservation remains nonreplayable even if the original permit
            // expires while an operator reconciles a partial deployment.
            $sql="UPDATE `{$this->permits}` SET state='applied',outcome_receipt_sha256=?,finalized_at=?,revision=revision+1 WHERE permit_sha256=? AND state='reserved'";
            $s=$this->db->prepare($sql);$s->bind_param('sis',$receiptSha,$now,$sha);if(!$s->execute())throw new RuntimeException('Authority CAS failed: '.(int)$this->db->errno);$ok=$s->affected_rows===1;$s->close();
            if(!$ok)throw new RuntimeException('Completion CAS failed');
            $this->event($sha,'permit_applied','reserved','applied',$now,$receiptSha);
            return ['completed'=>true,'state'=>'applied','certificate_sha256'=>$receiptSha];
        });
    }
    /** Revocation is permitted only before reservation; otherwise reconcile/stop worker. */
    public function revokeUnreserved(string $sha,int $now):array {
        if(!self::hash($sha))return ['revoked'=>false,'reason'=>'invalid_sha'];
        return $this->txn(function()use($sha,$now){
            $r=$this->row($sha,true);
            if(!$r||$r['state']!=='issued')return ['revoked'=>false,'reason'=>'already_reserved_or_missing'];
            $s=$this->db->prepare("UPDATE `{$this->permits}` SET state='revoked',revision=revision+1,finalized_at=? WHERE permit_sha256=? AND state='issued'");
            $s->bind_param('is',$now,$sha);if(!$s->execute())throw new RuntimeException('Authority CAS failed: '.(int)$this->db->errno);$ok=$s->affected_rows===1;$s->close();
            if(!$ok)throw new RuntimeException('Revocation CAS failed');
            $this->event($sha,'permit_revoked','issued','revoked',$now);
            return ['revoked'=>true,'state'=>'revoked'];
        });
    }
    public function inspect(string $sha):?array {
        if(!self::hash($sha))return null;
        $row=$this->row($sha);if(!$row)return null;
        unset($row['reservation_sha256'],$row['nonce']);return $row;
    }
    public function eventCount(string $sha):int {
        $s=$this->db->prepare("SELECT COUNT(*) FROM `{$this->events}` WHERE permit_sha256=?");
        $s->bind_param('s',$sha);if(!$s->execute())throw new RuntimeException('Authority count failed: '.(int)$this->db->errno);$s->bind_result($n);$s->fetch();$s->close();return (int)$n;
    }
}
