<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Immutable server-owned association of a saved Form submission to its real
 * parent source and optional Rate. Cannot be populated from customer fields.
 */
final class SubmissionContext
{
    private static function table():string
    {
        global $wpdb;
        return $wpdb->prefix.'commerce_submission_contexts';
    }
    public static function install():true|\WP_Error
    {
        global $wpdb;
        $table=self::table();
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))
            return new \WP_Error('submission_context_table_invalid');
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL,
            submission_id bigint(20) unsigned NOT NULL,
            parent_id bigint(20) unsigned NOT NULL,
            rate_id bigint(20) unsigned NULL DEFAULT NULL,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            seal_sha256 char(64) NOT NULL,
            created_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY saved_submission (form_id,submission_id)
        ) {$wpdb->get_charset_collate()};");
        return self::ready()?true:new \WP_Error('submission_context_install_failed');
    }
    public static function ready():bool
    {
        global $wpdb;
        $table=self::table();
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))return false;
        return $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $table
        ))==='InnoDB';
    }
    private static function seal(int $formId,int $submissionId,int $parent,
        ?int $rate,int $actor):string
    {
        return hash_hmac('sha256',wp_json_encode([
            'source_context:v1',$formId,$submissionId,$parent,$rate,$actor
        ],JSON_UNESCAPED_SLASHES),wp_salt('secure_auth'));
    }
    private static function persist(int $formId,int $submissionId,int $parent,
        ?int $rate,int $actor):true|\WP_Error
    {
        global $wpdb;
        if(!self::ready()||$formId<1||$submissionId<1||$parent<1||
            ($rate!==null&&$rate<1))
            return new \WP_Error('submission_context_identity_invalid');
        $verify=SubmissionProvenance::verify($submissionId,$formId);
        if(is_wp_error($verify))return $verify;
        // Never create a durable proof for an unavailable booking/template.
        $descriptor=FormRouter::describe($parent,$rate);
        if(is_wp_error($descriptor))return $descriptor;
        if(!$descriptor['booking_available']||
            (int)$descriptor['booking_form_id']!==$formId)
            return new \WP_Error('submission_context_booking_policy_disallows');
        $table=self::table();
        $seal=self::seal($formId,$submissionId,$parent,$rate,$actor);
        $rateSql=$rate===null?'NULL':$wpdb->prepare('%d',$rate);
        $insert=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table
             (form_id,submission_id,parent_id,rate_id,actor_id,seal_sha256,created_utc)
             VALUES (%d,%d,%d,$rateSql,%d,%s,%s)",
            $formId,$submissionId,$parent,
            $actor,$seal,gmdate('Y-m-d H:i:s')
        ));
        if($insert===false)return new \WP_Error('submission_context_insert_failed');
        $stored=self::read($formId,$submissionId);
        if(is_wp_error($stored))return $stored;
        if($stored['parent_id']!==$parent||$stored['rate_id']!==$rate||
           $stored['actor_id']!==$actor)
            return new \WP_Error('submission_context_immutable_conflict');
        return true;
    }
    /** Explicit owner-only backfill or verified adapter provision. */
    public static function saveTrusted(int $formId,int $submissionId,int $parent,
        ?int $rate):true|\WP_Error
    {
        if(!current_user_can('manage_options'))
            return new \WP_Error('submission_context_permission_denied');
        return self::persist($formId,$submissionId,$parent,$rate,get_current_user_id());
    }
    /** Capture only an already-saved original Form + server-side verified context. */
    public static function capture($submissionId,$formData,$form):void
    {
        if(!is_object($form)||!is_numeric($form->id??null)||
            !is_numeric($submissionId))return;
        $sid=(int)$submissionId;$fid=(int)$form->id;
        if($sid<1||$fid<1)return;
        $ctx=apply_filters('commerce_composition_trusted_submission_context',null,
            $sid,$fid);
        if(!is_array($ctx)||!is_int($ctx['parent_id']??null))return;
        $rate=isset($ctx['rate_id'])?(int)$ctx['rate_id']:null;
        // Only PHP event providers, never untrusted form POST values.
        self::persist($fid,$sid,$ctx['parent_id'],$rate,0);
    }
    public static function read(int $formId,int $submissionId):array|\WP_Error
    {
        global $wpdb;
        if(!self::ready()||$formId<1||$submissionId<1)
            return new \WP_Error('submission_context_missing');
        $table=self::table();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT form_id,submission_id,parent_id,rate_id,actor_id,seal_sha256
             FROM $table WHERE form_id=%d AND submission_id=%d",
            $formId,$submissionId
        ),ARRAY_A);
        if(!is_array($row))return new \WP_Error('submission_context_missing');
        $parent=(int)$row['parent_id'];
        $rate=$row['rate_id']===null?null:(int)$row['rate_id'];
        $actor=(int)$row['actor_id'];
        if(!hash_equals((string)$row['seal_sha256'],
            self::seal($formId,$submissionId,$parent,$rate,$actor)))
            return new \WP_Error('submission_context_seal_invalid');
        $verified=SubmissionProvenance::verify($submissionId,$formId);
        if(is_wp_error($verified))return $verified;
        return [
            'parent_id'=>$parent,'rate_id'=>$rate,'actor_id'=>$actor,
            'form_id'=>$formId,'submission_id'=>$submissionId
        ];
    }
}
