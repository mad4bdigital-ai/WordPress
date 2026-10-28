<?php
declare(strict_types=1);
namespace Commerce\Composition;

use DomainException;

/**
 * Generic product-kind classification from trusted Post Type metadata and taxonomy terms.
 * There is no baked-in "tour"/"package" business mapping, and no inference from pricing tiers.
 */
final class Classification
{
    private static function token(mixed $v): string
    {
        if (!is_string($v)||!preg_match('/^[a-z][a-z0-9_-]{0,99}$/D',$v))
            throw new DomainException('classification_identifier_invalid');
        return $v;
    }
    /** WordPress term slugs may be numeric or percent-encoded for RTL languages. */
    private static function termSlug(mixed $v): string
    {
        if(!is_string($v)||strlen($v)>240||
           !preg_match('/^(?:[a-z0-9_-]|%[0-9a-fA-F]{2}){1,200}$/D',$v))
            throw new DomainException('classification_term_slug_invalid');
        return $v;
    }
    public static function kinds(array $registry, string $postType, callable $termsForTaxonomy, callable $metaValue): array
    {
        if (($registry['schema'] ?? null)!==1 || !is_array($registry['definitions']??null) ||
            !array_is_list($registry['definitions']) || count($registry['definitions'])>100)
            throw new DomainException('classification_registry_invalid');
        self::token($postType);
        $matched=[];$known=[];
        foreach ($registry['definitions'] as $definition) {
            if (!is_array($definition)) throw new DomainException('classification_rule_invalid');
            $kind=self::token($definition['kind']??null);
            $type=self::token($definition['post_type']??null);
            $key=$type.'|'.$kind;
            if (isset($known[$key])) throw new DomainException('classification_rule_duplicate');
            $known[$key]=true;
            $all=$definition['all']??null;
            if (!is_array($all)||!array_is_list($all)||$all===[]||count($all)>30)
                throw new DomainException('classification_conditions_invalid');
            $ok=true;
            foreach($all as $condition){
                if(!is_array($condition))throw new DomainException('classification_condition_invalid');
                $source=$condition['source']??null;
                if($source==='taxonomy'){
                    $tax=self::token($condition['taxonomy']??null);
                    $slugs=$condition['term_slugs']??null;
                    if(!is_array($slugs)||!array_is_list($slugs)||!$slugs||count($slugs)>60)
                        throw new DomainException('classification_taxonomy_terms_invalid');
                    foreach($slugs as $slug)self::termSlug($slug);
                    if($type!==$postType)continue;
                    $got=$termsForTaxonomy($tax);
                    if(!is_array($got))throw new DomainException('classification_terms_provider_invalid');
                    foreach($got as $slug)self::termSlug($slug);
                    $found=(bool)array_intersect($slugs,$got);
                } elseif($source==='meta'){
                    $keyName=$condition['key']??null;
                    if(!is_string($keyName)||!preg_match('/^_?[a-zA-Z][a-zA-Z0-9_-]{0,119}$/D',$keyName))
                        throw new DomainException('classification_meta_key_invalid');
                    $comparisons=array_intersect(['equals','contains','one_of','exists'],array_keys($condition));
                    if(count($comparisons)!==1)throw new DomainException('classification_meta_operator_invalid');
                    $operator=reset($comparisons);
                    $actual=$type===$postType?$metaValue($keyName):null;
                    if($operator==='equals'){
                        $expected=$condition['equals'];
                        if(!is_string($expected)&&!is_bool($expected)&&!is_int($expected))
                            throw new DomainException('classification_meta_value_invalid');
                        $found=$actual===$expected;
                    }elseif($operator==='contains'){
                        $expected=$condition['contains'];
                        if(!is_scalar($expected)||!is_array($actual)||count($actual)>500)
                            $found=false;
                        else $found=in_array($expected,$actual,true);
                    }elseif($operator==='one_of'){
                        $expected=$condition['one_of'];
                        if(!is_array($expected)||!array_is_list($expected)||!$expected||count($expected)>60)
                            throw new DomainException('classification_meta_values_invalid');
                        foreach($expected as $v)
                            if(!is_string($v)&&!is_int($v)&&!is_bool($v))
                                throw new DomainException('classification_meta_values_invalid');
                        $found=is_scalar($actual)&&in_array($actual,$expected,true);
                    }else{
                        $expected=$condition['exists'];
                        if(!is_bool($expected))throw new DomainException('classification_meta_exists_invalid');
                        $exists=$actual!==null && $actual!=='';
                        $found=$expected===$exists;
                    }
                } else {
                    throw new DomainException('classification_condition_source_invalid');
                }
                $negate=$condition['not']??false;
                if(!is_bool($negate))throw new DomainException('classification_negation_invalid');
                if($negate)$found=!$found;
                if(!$found)$ok=false;
            }
            if($type===$postType && $ok)$matched[]=$kind;
        }
        return $matched;
    }
}
