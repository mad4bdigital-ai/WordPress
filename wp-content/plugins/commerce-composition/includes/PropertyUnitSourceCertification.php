<?php
declare(strict_types=1);
namespace Commerce\Composition;

/** Read-only JetEngine ownership certification for Property Rate → Unit. */
final class PropertyUnitSourceCertification
{
    private static function deny(string $code):\WC_Data_Exception {
        return new \WC_Data_Exception($code,'Property Rate / Unit ownership not certified');
    }

    private static function relationId():int {
        if(!function_exists('jet_engine') || !is_object(jet_engine()->relations??null))
            throw self::deny('commerce_property_jetengine_relation_provider_missing');
        $matches=[];
        foreach((array)jet_engine()->relations->get_active_relations() as $relation){
            if(!is_object($relation)||!method_exists($relation,'get_id')
                ||!method_exists($relation,'get_args'))continue;
            $args=$relation->get_args();
            if(($args['parent_object']??null)==='posts::properties'
                &&($args['child_object']??null)==='posts::units')
                $matches[]=(int)$relation->get_id();
        }
        $matches=array_values(array_unique($matches));
        if(count($matches)!==1||$matches[0]<1)
            throw self::deny('commerce_property_unit_relation_ambiguous');
        return $matches[0];
    }

    public static function inspect(int $rateId,int $propertyId):array {
        global $wpdb;
        if(get_post_type($rateId)!=='property-rates'
            ||get_post_type($propertyId)!=='properties')
            throw self::deny('commerce_property_rate_parent_type_invalid');
        $raw=get_post_meta($rateId,'prop_related_unit_id',true);
        if(!is_scalar($raw)||!ctype_digit((string)$raw)||(int)$raw<1)
            throw self::deny('commerce_property_rate_unit_missing');
        $unitId=(int)$raw;
        $unit=get_post($unitId);
        if(!$unit||$unit->post_type!=='units'||$unit->post_status!=='publish')
            throw self::deny('commerce_property_unit_unpublished');
        $relationId=self::relationId();
        $table=$wpdb->prefix.'jet_rel_default';
        // Count all parents of this unit in the source relation, not only
        // expected pairs. Multiple owners or duplicates are not certifiable.
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT _ID,parent_object_id,child_object_id FROM {$table}
             WHERE rel_id=%s AND child_object_id=%d ORDER BY _ID LIMIT 3",
             (string)$relationId,$unitId),ARRAY_A);
        if(!is_array($rows)||count($rows)!==1)
            throw self::deny('commerce_property_unit_relation_missing_or_ambiguous');
        $row=$rows[0];
        if((int)$row['parent_object_id']!==$propertyId)
            throw self::deny('commerce_property_unit_wrong_owner');
        return [
            'contract'=>'commerce.property-rate-unit-ownership.v1',
            'rate_id'=>$rateId,'property_id'=>$propertyId,'unit_id'=>$unitId,
            'jet_relation_id'=>$relationId,'jet_relation_row_id'=>(int)$row['_ID'],
            'ownership_verified'=>true,'availability_certified'=>false,
            'financial_authorized'=>false
        ];
    }
}
