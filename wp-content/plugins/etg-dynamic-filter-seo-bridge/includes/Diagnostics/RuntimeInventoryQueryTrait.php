<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

trait RuntimeInventoryQueryTrait {
    private function queries(): array {
        $raw = array();
        $available = false;
        $source = 'unavailable';
        if ( $this->queryProvider ) {
            try {
                $candidate = $this->iterableArray( call_user_func( $this->queryProvider ) );
                if ( is_array( $candidate ) ) { $raw=$candidate; $available=true; $source='injected_test_provider'; }
                else { $source='injected_test_provider_invalid'; }
            } catch ( \Throwable $error ) { $source='injected_test_provider_exception'; }
        } elseif ( class_exists( '\\Jet_Engine\\Query_Builder\\Manager' ) && method_exists( '\\Jet_Engine\\Query_Builder\\Manager', 'instance' ) ) {
            try {
                $manager = \Jet_Engine\Query_Builder\Manager::instance();
                if ( is_object( $manager ) && method_exists( $manager, 'get_queries' ) ) {
                    $candidate = $this->iterableArray( $manager->get_queries() );
                    if ( is_array( $candidate ) ) { $raw=$candidate; $available=true; $source='jet_engine_query_builder_manager_get_queries'; }
                    else { $source='jet_engine_query_builder_manager_invalid_result'; }
                }
            } catch ( \Throwable $error ) { $source='jet_engine_query_builder_manager_exception'; }
        }

        $records = array();
        foreach ( $raw as $query ) {
            if ( ! is_object( $query ) ) { continue; }
            $id = isset( $query->id ) && is_scalar( $query->id ) ? sanitize_text_field( (string) $query->id ) : '';
            $customId = isset( $query->query_id ) && is_scalar( $query->query_id ) ? sanitize_key( (string) $query->query_id ) : '';
            $type = method_exists( $query, 'get_query_type' ) ? sanitize_key( (string) $query->get_query_type() ) : '';
            $key = $this->queryIdentityKey( $id, $customId );
            $args = array();
            if ( 'posts' === $type && method_exists( $query, 'get_query_args' ) ) { $args = $query->get_query_args(); }
            $args = is_array( $args ) ? $args : array();
            $postTypes = $this->postTypesFromArgs( $args );
            $taxonomies = array_values( array_unique( $this->taxonomiesFromArgs( $args ) ) ); sort( $taxonomies, SORT_STRING );
            $bounded = 'posts' !== $type || ( ! empty( $postTypes ) && ! in_array( 'any', $postTypes, true ) );
            $structuralKey = hash( 'sha256', $this->encode( array( 'post_types'=>$postTypes, 'post_type_bounded'=>$bounded, 'taxonomies'=>$taxonomies ) ) );
            $records[] = array(
                'id'=>$id,'custom_query_id'=>$customId,'type'=>$type,'identity_key'=>$key,'post_types'=>$postTypes,
                'post_type_bounded'=>$bounded,'taxonomies'=>$taxonomies,'structural_key'=>$structuralKey,
            );
        }

        usort( $records, static function ( $a, $b ) {
            foreach ( array( 'identity_key','id','type','structural_key' ) as $field ) { $cmp=strcmp((string)$a[$field],(string)$b[$field]); if(0!==$cmp){return $cmp;} }
            return 0;
        } );

        $identityMap = array();
        foreach ( $records as $record ) {
            $key=(string)$record['identity_key']; if(''===$key){continue;}
            if(!isset($identityMap[$key])){$identityMap[$key]=array();}
            $identityMap[$key][]=$this->publicQueryRecord($record);
        }
        $conflicts=array();$conflictCount=0;
        foreach($identityMap as $key=>$matches){
            if(count($matches)<2){continue;}$conflictCount++;
            if(count($conflicts)<self::MAX_QUERY_IDENTITY_CONFLICTS){$conflicts[]=array('identity_key'=>$key,'count'=>count($matches),'records'=>array_slice($matches,0,self::MAX_QUERY_CONFLICT_RECORDS));}
        }

        $items=array();
        foreach(array_slice($records,0,self::MAX_QUERIES) as $record){$items[]=$this->publicQueryRecord($record);}
        $identityItems=array();
        foreach(array_slice($records,0,self::MAX_QUERY_IDENTITIES) as $record){$identityItems[]=$this->publicQueryRecord($record);}

        return array(
            'data'=>array(
                'available'=>$available,'source'=>$source,
                'identity_conflict_count'=>$conflictCount,'identity_conflicts_truncated'=>$conflictCount>count($conflicts),'identity_conflicts'=>$conflicts,
                'identity_index_complete'=>count($records)<=self::MAX_QUERY_IDENTITIES,
                'identity_index'=>$identityItems,
                'queries'=>$items,
            ),
            'availability'=>array('available'=>$available,'source'=>$source),
            'completeness'=>$this->completeness(count($records),count($items),self::MAX_QUERIES),
            'identity_completeness'=>$this->completeness(count($records),count($identityItems),self::MAX_QUERY_IDENTITIES),
        );
    }

    private function publicQueryRecord( array $record ): array {
        return array(
            'id'=>(string)$record['id'],'custom_query_id'=>(string)$record['custom_query_id'],'identity_key'=>(string)$record['identity_key'],
            'type'=>(string)$record['type'],'post_types'=>(array)$record['post_types'],'post_type_bounded'=>!empty($record['post_type_bounded']),'taxonomies'=>(array)$record['taxonomies'],
        );
    }

    private function queryIdentityKey( string $id, string $customId ): string { $key=''!==$customId?$customId:sanitize_key($id); return sanitize_key($key); }

    private function postTypesFromArgs( array $args ): array {
        $value=$args['post_type']??array();$list=is_array($value)?$value:array($value);
        $out=array_values(array_unique(array_filter(array_map(static function($item){$item=strtolower(trim((string)$item));return'any'===$item?'any':sanitize_key($item);},$list))));
        sort($out,SORT_STRING);return $out;
    }

    private function taxonomiesFromArgs( array $args ): array {
        $out=array();$walk=function($value)use(&$walk,&$out){if(!is_array($value)){return;}if(isset($value['taxonomy'])&&is_scalar($value['taxonomy'])){$taxonomy=sanitize_key((string)$value['taxonomy']);if(''!==$taxonomy){$out[]=$taxonomy;}}foreach($value as $child){if(is_array($child)){$walk($child);}}};
        if(isset($args['tax_query'])){$walk($args['tax_query']);}return $out;
    }
}
