<?php
require dirname(__DIR__) . '/includes/Acceptance/SemanticQueryEvaluator.php';

use ETG\DynamicFilterSEOBridge\Acceptance\SemanticQueryEvaluator;

final class FakeQuery {
    public $final_query = null;
    public $current_query = null;
    private $ids; private $perPage; private $page = 1; private $stuck;
    public function __construct(array $ids, int $perPage, bool $stuck=false){$this->ids=$ids;$this->perPage=$perPage;$this->stuck=$stuck;}
    public function setup_query(){}
    public function reset_query(){$this->page=1;}
    public function set_filtered_prop($key,$value){ if ('_page'===$key && !$this->stuck) $this->page=(int)$value; }
    public function get_items_total_count(){ return count($this->ids); }
    public function get_items_per_page(){ return $this->perPage; }
    public function get_current_items_page(){ return $this->page; }
    public function get_items(){
        if ($this->perPage <= 0) return array_map(fn($id)=>(object)['ID'=>$id], $this->ids);
        $offset=max(0,($this->page-1)*$this->perPage);
        return array_map(fn($id)=>(object)['ID'=>$id], array_slice($this->ids,$offset,$this->perPage));
    }
}
function evalDataset(int $n,int $perPage,bool $stuck=false):array{
    $q=new FakeQuery(range(1,$n),$perPage,$stuck);
    $e=new SemanticQueryEvaluator(null, static fn()=>['resolved'=>true,'reason'=>'ok','query'=>$q]);
    return $e->evaluate(['provider'=>'jet-engine','query_id'=>'q'],[],['taxonomy_query'=>['x']]);
}
function need($cond,$msg){if(!$cond){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$a=evalDataset(50,25); need($a['state']==='ok','50 state'); need($a['proof_mode']==='full_ids','50 mode'); need($a['proof_complete']===true,'50 complete'); need(count($a['ids'])===50,'50 ids');
$b=evalDataset(250,50); need($b['state']==='ok','250 state'); need($b['proof_mode']==='full_digest','250 mode'); need($b['proof_complete']===true,'250 complete'); need(count($b['ids'])===0,'250 no exposed ids'); need($b['proof_item_count']===250,'250 proof count'); need(strlen($b['identity_digest'])===64 && strlen($b['order_digest'])===64,'250 digest');
$c=evalDataset(5001,100); need($c['state']==='blocked','5001 blocked'); need(in_array('dataset_exceeds_digest_ceiling',$c['blocking_reasons'],true),'5001 reason');
$d=evalDataset(250,50,true); need($d['state']==='blocked','stuck blocked'); need($d['infrastructure_failure']===true,'stuck infra'); need(in_array($d['pagination_failure'],['paged_query_items_do_not_advance','duplicate_page_signature','pagination_no_progress'],true),'stuck reason');
echo "SEMANTIC_QUERY_MATRIX=PASS\n";
