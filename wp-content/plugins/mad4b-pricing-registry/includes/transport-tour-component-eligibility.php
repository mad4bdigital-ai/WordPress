<?php
/**
 * Align Transportation Tour Component opt-in with Property Tour Rate Generation.
 *
 * The opt-in is a distinct editorial/status control: it does not publish a
 * draft, grant availability, create a rate or authorize checkout.
 */
defined('ABSPATH') || exit;
final class MAD4B_Transport_Tour_Component_Eligibility_V1 {
    public const FIELD='trans_use_in_tour_rate_generation';
    public const CONTRACT='mad4b.transport-tour-component-eligibility.v1';
    private const CATEGORY_BY_MODE=[
        568=>'vehicles',
        578=>'train',
        594=>'air',
    ];
    public static function inspection(int $post_id):array {
        $result=[
            'contract'=>self::CONTRACT,'post_id'=>$post_id,
            'opted_in'=>false,'qualifies_as_tour_component'=>false,
            'post_status'=>'missing','availability'=>null,'mode'=>null,
            'reason_codes'=>[],'rate_approved'=>false,
            'stock_verified'=>false,'checkout_authorized'=>false
        ];
        if($post_id<=0||get_post_type($post_id)!=='transportations'){
            $result['reason_codes'][]='not_a_transportation';return $result;
        }
        $result['post_status']=(string)get_post_status($post_id);
        $result['opted_in']=get_post_meta($post_id,self::FIELD,true)==='1';
        $result['availability']=(string)get_post_meta($post_id,'trans_available_status',true);
        $result['mode']=(int)get_post_meta($post_id,'trans_mode',true);
        if(!$result['opted_in'])$result['reason_codes'][]='tour_component_status_no';
        if($result['post_status']!=='publish')$result['reason_codes'][]='transport_not_published';
        if($result['availability']!=='1')$result['reason_codes'][]='transport_not_available';
        if(function_exists('service_channel_allowed') &&
            !service_channel_allowed('transportations','tour_component'))
            $result['reason_codes'][]='company_tour_component_channel_disabled';
        $category=self::CATEGORY_BY_MODE[$result['mode']]??null;
        if($category===null)$result['reason_codes'][]='unsupported_or_unclassified_transport_mode';
        if($category!==null && $result['opted_in'] &&
           $result['post_status']==='publish' && $result['availability']==='1') {
            if(class_exists('Shared_Product_Presentation_Transport_Context_v1') &&
                method_exists('Shared_Product_Presentation_Transport_Context_v1','candidate')){
                $native=Shared_Product_Presentation_Transport_Context_v1::candidate($post_id,$category);
                if(empty($native['ok']))$result['reason_codes'][]=
                    (string)($native['code']??'native_transport_identity_unverified');
            }else{
                $result['reason_codes'][]='native_transport_identity_not_loaded';
            }
        }
        $result['reason_codes']=array_values(array_unique($result['reason_codes']));
        $result['qualifies_as_tour_component']=!$result['reason_codes'];
        $result['status']=$result['qualifies_as_tour_component']
            ?'component_qualified':'requires_qualification';
        return $result;
    }
    public static function eligible(int $post_id):bool {
        return self::inspection($post_id)['qualifies_as_tour_component'];
    }
    /**
     * Existing Tour Rate related options producer at priority 20 provides
     * related_options.transportation grouped by train/vehicle/air.
     * Filter just before its visual options builder at priority 30.
     */
    public static function filter_tour_rate_results($results) {
        if(!is_array($results)||!is_array($results['results']??null))
            return $results;
        foreach($results['results'] as &$entry){
            if(!is_array($entry)||
                !is_array($entry['related_options']['transportation']??null))continue;
            foreach($entry['related_options']['transportation'] as $mode=>&$rows){
                if(!is_array($rows))continue;
                $rows=array_values(array_filter($rows,static function($item):bool{
                    return is_array($item)&&isset($item['id']) &&
                        self::eligible((int)$item['id']);
                }));
            }
            unset($rows);
        }
        unset($entry);
        return $results;
    }
    /** Native JetEngine Tour transport listing query IDs used by current templates. */
    public static function filter_tour_query_items($items,$query) {
        if(!is_array($items)||!is_object($query))return $items;
        $id=(int)($query->id??0);
        if(!in_array($id,[258,259,260],true))return $items;
        $type=method_exists($query,'get_query_type')
            ?(string)$query->get_query_type():(string)($query->query_type??'');
        if($type!=='posts')return $items;
        return array_values(array_filter($items,static function($item):bool{
            $id=$item instanceof WP_Post ?(int)$item->ID:
                (is_array($item)&&isset($item['ID'])?(int)$item['ID']:0);
            return $id>0&&self::eligible($id);
        }));
    }
    public static function rest():void {
        register_rest_route('mad4b-pricing/v1','/transport-tour-component-status',[
            'methods'=>'GET',
            'callback'=>static function($request){
                $id=absint($request->get_param('post_id'));
                if($id>0)return rest_ensure_response(self::inspection($id));
                $all=get_posts([
                    'post_type'=>'transportations','post_status'=>['publish','draft'],
                    'posts_per_page'=>1000,'fields'=>'ids','suppress_filters'=>true
                ]);
                $enabled=0;$qualified=0;
                foreach($all as $item){
                    $ins=self::inspection((int)$item);
                    if($ins['opted_in'])$enabled++;
                    if($ins['qualifies_as_tour_component'])$qualified++;
                }
                return rest_ensure_response([
                    'contract'=>self::CONTRACT,
                    'total'=>count($all),'status_yes'=>$enabled,
                    'qualified_for_tour_component'=>$qualified,
                    'rate_approval_not_implied'=>true,
                    'checkout_authorized'=>false
                ]);
            },
            'permission_callback'=>static function(){
                return class_exists('MAD4B_Transport_Self_Pricing_Controls_V1') &&
                    MAD4B_Transport_Self_Pricing_Controls_V1::permitted();
            }
        ]);
    }
    public static function boot():void {
        add_filter('shared_product_tour_rate_search_results',
            [__CLASS__,'filter_tour_rate_results'],25,3);
        add_filter('jet-engine/query-builder/query/items',
            [__CLASS__,'filter_tour_query_items'],25,2);
        add_action('rest_api_init',[__CLASS__,'rest']);
    }
}
MAD4B_Transport_Tour_Component_Eligibility_V1::boot();
