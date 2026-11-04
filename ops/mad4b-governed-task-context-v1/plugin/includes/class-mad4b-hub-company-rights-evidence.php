<?php
/** Read-only, company-scoped proof inventory. Never grants ownership or distribution. */
defined('ABSPATH')||exit;
final class MAD4B_Hub_Company_Rights_Evidence_V1 {
    public const CONTRACT='mad4b.hub.company-rights-evidence.v1';
    public static function inspect(string $company,string $uuid,string $source):array {
        global $wpdb;
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
            ||!wp_is_uuid($uuid)||!wp_is_uuid($source))
            return ['contract'=>self::CONTRACT,'state'=>'INVALID_SCOPE',
                'registered_redistribution_licenses'=>0,
                'verified_owned_records'=>0,'full_distribution_authorized'=>false];
        $canonical=$wpdb->prefix.'mad4b_hub_canonical_entities';
        $publications=$wpdb->prefix.'mad4b_hub_publication_targets';
        $records=$wpdb->prefix.'mad4b_hub_content_records';
        $counts=['private_preview'=>0,'review_hold'=>0,'approved'=>0];
        $tableReady=static fn($t)=>
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))===$t;
        if($tableReady($canonical)) {
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT state,COUNT(*) n FROM $canonical
                 WHERE company_id=%s GROUP BY state",$company),ARRAY_A);
            foreach((array)$rows as $row)
                $counts[(string)$row['state']]=(int)$row['n'];
        }
        $publicationCount=0;$verifiedDestinationCount=0;
        if($tableReady($publications)) {
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT source_verified,COUNT(*) n FROM $publications
                  WHERE company_id=%s AND destination_site_uuid=%s
                  GROUP BY source_verified",$company,$uuid),ARRAY_A);
            foreach((array)$rows as $row) {
                $publicationCount+=(int)$row['n'];
                if(in_array($row['source_verified'],
                    ['local_wordpress','verified_remote_connector'],true))
                    $verifiedDestinationCount+=(int)$row['n'];
            }
        }
        $approvedReleases=0;
        if($tableReady($records)) {
            $approvedReleases=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $records WHERE
                 brand_id=%s AND site_uuid=%s AND status='approved'",
                $company,$source));
        }
        $licenses=get_option('mad4b_hub_usage_licenses_v1',[]);
        $licenseCount=0;
        if(is_array($licenses))foreach($licenses as $license) {
            if(is_array($license)
                &&($license['company_id']??'')===$company
                &&($license['status']??'')==='active'
                &&($license['commercial_redistribution_allowed']??false)===true)
                $licenseCount++;
        }
        // A verified destination reference alone never establishes copyright ownership.
        // The current platform has no source-post-level signed rights ledger with a
        // trusted Company binding; no positive ownership count can be inferred
        // from previews, WordPress publication, or generic usage policy options.
        $verifiedOwned=0;
        $blockers=[];
        if($licenseCount===0)$blockers[]='COMPANY_LICENSE_REGISTRY_MISSING';
        if($approvedReleases===0)$blockers[]='COMPANY_APPROVED_RELEASE_MISSING';
        if($publicationCount===0)$blockers[]='COMPANY_PUBLICATION_TARGET_MISSING';
        if($verifiedDestinationCount===0)$blockers[]='DESTINATION_POST_PROOF_MISSING';
        $blockers[]='SOURCE_POST_RIGHTS_BINDING_NOT_CERTIFIED';
        $blockers[]='COMPANY_PROFILE_AND_VENDOR_RECEIPTS_UNVERIFIED';
        return [
            'contract'=>self::CONTRACT,'state'=>'EVIDENCE_INCOMPLETE',
            'company_code'=>$company,'company_site_uuid'=>$uuid,
            'canonical_company_content_states'=>$counts,
            'source_approved_release_records'=>$approvedReleases,
            'publication_target_records'=>$publicationCount,
            'verified_destination_links'=>$verifiedDestinationCount,
            'registered_redistribution_licenses'=>$licenseCount,
            'verified_owned_records'=>$verifiedOwned,
            'certified_source_content_grants'=>0,
            'legal_reuse_permission'=>'not_determined_by_system',
            'full_distribution_authorized'=>false,
            'blockers'=>$blockers,
            'records_created'=>0,'publication_performed'=>false
        ];
    }
}
