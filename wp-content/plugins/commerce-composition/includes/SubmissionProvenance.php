<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * A form ID or a guessed submission ID is not sufficient to authorize a Cart
 * write. The saved Fluent Forms entry must exist and belong to that form.
 * Only source identity is retrieved, never raw personally identifiable answers.
 */
final class SubmissionProvenance
{
    public static function verify(int $submissionId,int $formId): bool|\WP_Error
    {
        if($submissionId<1||$formId<1)
            return new \WP_Error('submission_identity_invalid');
        $provided=apply_filters('commerce_composition_submission_source',null,
            $submissionId,$formId);
        if($provided!==null){
            if(!is_bool($provided))return new \WP_Error('submission_provider_invalid');
            return $provided?true:new \WP_Error('submission_not_verified');
        }
        global $wpdb;
        $table=$wpdb->prefix.'fluentform_submissions';
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))
            return new \WP_Error('submission_table_invalid');
        // Deliberately retrieve only the owning form ID, not response or user PII.
        $sql='SELECT form_id FROM '.$table.' WHERE id=%d LIMIT 1';
        $actual=$wpdb->get_var($wpdb->prepare($sql,$submissionId));
        if($actual===null)return new \WP_Error('submission_not_found');
        if((int)$actual!==$formId)return new \WP_Error('submission_form_mismatch');
        return true;
    }
}
