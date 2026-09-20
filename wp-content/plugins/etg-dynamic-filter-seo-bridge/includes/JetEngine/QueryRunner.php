<?php
namespace ETG\DynamicFilterSEOBridge\JetEngine;

final class QueryRunner {
    const MAX_ITEMS = 100;

    private $optionsCache = null;
    private $itemsCache = array();
    private $active = array();

    public function options(): array {
        if (null !== $this->optionsCache) { return $this->optionsCache; }
        $this->optionsCache = array();
        if (!class_exists('\\Jet_Engine\\Query_Builder\\Manager')) { return $this->optionsCache; }
        try {
            $manager=\Jet_Engine\Query_Builder\Manager::instance();
            if (!is_object($manager)||!method_exists($manager,'get_queries_for_options')) { return $this->optionsCache; }
            $options=(array)$manager->get_queries_for_options();
            foreach ($options as $id=>$label) {
                if (!is_numeric($id)) { continue; }
                $id=(int)$id; if ($id<1) { continue; }
                $this->optionsCache[$id]=sanitize_text_field((string)$label);
            }
            ksort($this->optionsCache,SORT_NUMERIC);
        } catch (\Throwable $e) {}
        return $this->optionsCache;
    }

    public function items(int $queryId,int $limit=30):array {
        $queryId=max(0,$queryId);$limit=max(1,min(self::MAX_ITEMS,$limit));
        if(!$queryId||!class_exists('\\Jet_Engine\\Query_Builder\\Manager')){return array();}
        $cacheKey=$queryId.':'.$limit;
        if(array_key_exists($cacheKey,$this->itemsCache)){return$this->itemsCache[$cacheKey];}
        if(!empty($this->active[$queryId])){return array();}
        $this->active[$queryId]=true;
        try{
            $manager=\Jet_Engine\Query_Builder\Manager::instance();
            if(!is_object($manager)||!method_exists($manager,'get_query_by_id')){return$this->itemsCache[$cacheKey]=array();}
            $query=$manager->get_query_by_id($queryId);
            if(!is_object($query)||!method_exists($query,'get_items')){return$this->itemsCache[$cacheKey]=array();}
            $items=$query->get_items();
            if($items instanceof \Traversable){$items=iterator_to_array($items,false);}
            return$this->itemsCache[$cacheKey]=is_array($items)?array_slice(array_values($items),0,$limit):array();
        }catch(\Throwable $e){return$this->itemsCache[$cacheKey]=array();}
        finally{unset($this->active[$queryId]);}
    }

    public function describe(int $queryId):array {
        $options=$this->options();
        return array('id'=>$queryId,'label'=>(string)($options[$queryId]??''),'available'=>isset($options[$queryId]),'authorizing'=>false,'read_only'=>true,'guarded'=>true,'max_items'=>self::MAX_ITEMS);
    }
}
