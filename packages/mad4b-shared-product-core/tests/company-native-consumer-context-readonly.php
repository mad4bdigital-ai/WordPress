<?php
$class='MAD4B_Company_Term_Eligibility_V1';
$id=15902;
$new=static function(string $name,string $query,string $provider='jet-engine') {
    return new class($name,$query,$provider) {
        function __construct(private string $name,private string $query,private string $provider){}
        function get_name(){return 'jet-smart-filters-checkboxes';}
        function get_id(){return $this->name;}
        function get_settings_for_display(){return ['filter_id'=>['15902'],
            'query_id'=>$this->query,'content_provider'=>$this->provider];}
    };
};
$outer=$new('audit-outer','property_query_archive');
$inner=$new('audit-inner','trans_query_archive');
$foreign=$new('audit-foreign','tours_query_archive','epro-archive');
$key=static fn()=>array_keys(($class::consumer_scoped_binding($id)['providers']??[]));
$initial=$key();
$class::begin_facet_render($outer);$a=$key();
$class::begin_facet_render($foreign);$f=$key();
$class::begin_facet_render($inner);$b=$key();
$class::finish_facet_render($inner);$c=$key();
$class::finish_facet_render($outer);$d=$key();
$class::finish_facet_render($foreign);
$checks=[
    'outer'=>($a===['property_query_archive']),
    'foreign_did_not_override'=>($f===['property_query_archive']),
    'inner'=>($b===['trans_query_archive']),
    'outer_restored'=>($c===['property_query_archive']),
    'reset'=>($d===$initial)
];
echo wp_json_encode(['site'=>home_url(),'PASS'=>!in_array(false,$checks,true),
    'checks'=>$checks,'keys'=>['before'=>$initial,'outer'=>$a,'foreign'=>$f,
        'inner'=>$b,'restored'=>$c,'after'=>$d]],JSON_UNESCAPED_SLASHES).PHP_EOL;
