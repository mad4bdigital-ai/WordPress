<?php
namespace DynamicFilterSEOBridge\REST;
use DynamicFilterSEOBridge\Context\ContextValueResolver as Values;
use DynamicFilterSEOBridge\Context\PageContextResolver as Pages;
use DynamicFilterSEOBridge\Context\WooCommerceContextResolver as Woo;
use DynamicFilterSEOBridge\Diagnostics\ContextCoverageMatrix as Matrix;
use DynamicFilterSEOBridge\Runtime\BootGuard;

/** Admin-only, read-only REST diagnostic surfaces; available during Safe Boot. */
final class ContextPreviewController {
    const NAMESPACE='dfsb-admin/v1';
    public function register(): void {add_action('rest_api_init',array($this,'routes'));}
    public static function permit(): bool {return current_user_can('manage_options');}
    public function routes(): void {
        foreach(array('/catalog'=>'catalog','/preview'=>'preview',
            '/integrations'=>'integrations','/issues'=>'issues','/objects'=>'objects') as $uri=>$method){
            register_rest_route(self::NAMESPACE,$uri,array(
                'methods'=>\WP_REST_Server::READABLE,
                'callback'=>array($this,$method),
                'permission_callback'=>array(__CLASS__,'permit'),
                'args'=>$uri==='/preview'?array(
                    'scope'=>array('type'=>'string','required'=>true,'enum'=>array('post','taxonomy','archive','posts_home','shop')),
                    'id'=>array('type'=>'integer','default'=>0,'minimum'=>0),
                    'type'=>array('type'=>'string','default'=>'','maxLength'=>64),
                ):array(),
            ));
        }
    }
    private function output(array $data): \WP_REST_Response {
        $r=new \WP_REST_Response($data,200);
        $r->header('Cache-Control','private, no-store, max-age=0');
        return $r;
    }
    public function catalog(\WP_REST_Request $request): \WP_REST_Response {
        $a=Pages::catalog(100);
        return $this->output(array('contract'=>'dfsb.admin.catalog.v1','read_only'=>true,
            'post_types'=>$a['post_types'],'taxonomies'=>$a['taxonomies'],
            'truncated'=>$a['truncated'],'tag_fields'=>Values::fields(),
            'shortcodes'=>array('dfsb_context_value','dfsb_context_image','dfsb_context_heading','dfsb_context_editorial'),
            'elementor_tag'=>'dfsb-context-value',
            'elementor_image_tag'=>'dfsb-context-image',
            'safe_boot_held'=>(bool)BootGuard::status()['effective_hold']));
    }
    public function objects(\WP_REST_Request $request) {
        $source=sanitize_key((string)($request->get_param('source')??'post'));
        $type=sanitize_key((string)($request->get_param('type')??''));
        $search=sanitize_text_field((string)($request->get_param('search')??''));
        $search=function_exists('mb_substr')?mb_substr($search,0,75):substr($search,0,75);
        $limit=max(1,min(30,absint($request->get_param('limit')??20)));
        $out=array();
        if($source==='post' && Pages::publicType($type)){
            $items=get_posts(array('post_type'=>$type,'post_status'=>'publish',
                'posts_per_page'=>$limit,'fields'=>'ids','suppress_filters'=>true,
                'orderby'=>'modified','order'=>'DESC','s'=>$search));
            foreach($items as $id){
                $c=Pages::forPost((int)$id);
                if(($c['page_kind']??'')!=='singular')continue;
                $out[]=array('id'=>(int)$id,'title'=>(string)$c['title'],
                    'language'=>(string)($c['language']??''),'type'=>$type);
            }
        }elseif($source==='taxonomy'){
            $tax=get_taxonomy($type);
            if($tax && !empty($tax->public)){
                $terms=get_terms(array('taxonomy'=>$type,'hide_empty'=>false,
                    'number'=>$limit,'search'=>$search,'orderby'=>'name'));
                if(!is_wp_error($terms))foreach($terms as $term){
                    $c=Pages::forTaxonomy($type,(int)$term->term_id);
                    if(($c['page_kind']??'')!=='taxonomy')continue;
                    $out[]=array('id'=>(int)$term->term_id,'title'=>(string)$term->name,
                        'language'=>(string)($c['language']??''),'type'=>$type);
                }
            }
        }else return new \WP_Error('dfsb_invalid_source','Select a public CPT or taxonomy.',array('status'=>400));
        return $this->output(array('contract'=>'dfsb.admin.objects.v1',
            'read_only'=>true,'source'=>$source,'type'=>$type,'items'=>$out,
            'limit'=>$limit,'cursor'=>null,'not_authorizing'=>true));
    }
    public function preview(\WP_REST_Request $request) {
        $scope=(string)$request->get_param('scope');
        $id=absint($request->get_param('id'));
        $type=sanitize_key((string)$request->get_param('type'));
        if(in_array($scope,array('post','taxonomy'),true)&&$id<=0)
            return new \WP_Error('dfsb_invalid_id','A positive object ID is required.',array('status'=>400));
        if(in_array($scope,array('taxonomy','archive'),true)&&$type==='')
            return new \WP_Error('dfsb_missing_type','A registered CPT or taxonomy is required.',array('status'=>400));
        $ctx=Values::context($scope,$id,$type);
        if(in_array(($ctx['page_kind']??'unresolved'),array('unresolved','commerce_transaction'),true))
            return new \WP_Error('dfsb_source_not_public','The selected source is not published and public.',array('status'=>404));
        $values=Values::project($ctx);
        $matrix=Matrix::forContext($ctx);
        return $this->output(array('contract'=>'dfsb.admin.preview.v1',
            'read_only'=>true,'authorizing'=>false,'scope'=>$scope,
            'object_id'=>(int)($ctx['object_id']??0),'post_id'=>(int)($ctx['post_id']??0),
            'term_id'=>(int)($ctx['term_id']??0),
            'post_type'=>(string)($ctx['post_type']??''),
            'taxonomy'=>(string)($ctx['taxonomy']??''),
            'page_kind'=>(string)$ctx['page_kind'],'language'=>(string)($ctx['language']??''),
            'value_contract'=>$values,
            'image_value'=>Values::image($ctx),
            'checks'=>$matrix['checks'],
            'presentation'=>$matrix['presentation'],'providers'=>$matrix['provider'],
            'publication_allowed'=>false,'safe_boot_held'=>(bool)BootGuard::status()['effective_hold']));
    }
    public function integrations(\WP_REST_Request $request): \WP_REST_Response {
        $status=array('elementor'=>class_exists('Elementor\Plugin'),
            'jetengine'=>function_exists('jet_engine'),
            'jetsmartfilters'=>function_exists('jet_smart_filters'),
            'rankmath'=>class_exists('RankMath'),
            'wpml'=>defined('ICL_SITEPRESS_VERSION'),
            'woocommerce'=>Woo::available(),
            'fluentforms'=>function_exists('wpFluentForm'),
            'fluentcrm'=>function_exists('FluentCrmApi'),
            'fluentboards'=>class_exists('FluentBoards\App\Services\TaskService'),
            'fluent_smtp'=>defined('FLUENTMAIL'),
            'wp_erp'=>function_exists('erp_get_employee'),
            'mad4b_readiness'=>class_exists('MAD4B_SCP_Content_Readiness_Alerts'));
        return $this->output(array('contract'=>'dfsb.admin.integrations.v1',
            'read_only'=>true,'providers'=>$status,
            'notifications'=>'owned_by_mad4b_readiness',
            'delivery'=>'not_authorized_in_diagnostic_requests',
            'commercial_write_authorized'=>false));
    }
    public function issues(\WP_REST_Request $request): \WP_REST_Response {
        $owner='MAD4B_SCP_Content_Readiness_Alerts';
        $summary=class_exists($owner)?$owner::summary():array();
        $ledger=(array)get_option('mad4b_content_readiness_issues_v1',array());
        $open=array();
        foreach($ledger as $issue){
            if(!is_array($issue)||($issue['status']??'open')==='resolved')continue;
            $open[]=array('code'=>sanitize_key((string)($issue['code']??'')),
                'severity'=>sanitize_key((string)($issue['severity']??'medium')),
                'object_id'=>(int)($issue['object_id']??0),
                'post_type'=>sanitize_key((string)($issue['post_type']??'')),
                'board_linked'=>!empty($issue['board_task_id']));
            if(count($open)>=30)break;
        }
        // Preview the same source issues that an explicitly approved SCP
        // Scan would reconcile, without mutating its ledger or sending tasks.
        $fresh=(new \DynamicFilterSEOBridge\Integration\GapAlertSource())->append(array());
        $detected=array();
        if(is_array($fresh))foreach(array_slice($fresh,0,12) as $row){
            if(!is_array($row))continue;
            $key=(string)($row['id']??'');
            $stored=is_array($ledger[$key]??null)?$ledger[$key]:array();
            $detected[]=array('code'=>sanitize_key((string)($row['code']??'')),
                'severity'=>sanitize_key((string)($row['severity']??'medium')),
                'label'=>sanitize_text_field((string)($row['label']??'')),
                'persisted'=>!empty($stored),
                'stored_status'=>$stored['status']??'not_scanned_yet');
        }
        return $this->output(array('contract'=>'dfsb.admin.issues.v1','read_only'=>true,
            'registry'=>'mad4b_content_readiness_issues_v1',
            'summary'=>$summary,'sample_open'=>$open,'sample_limited'=>count($open)>=30,
            'detected_source_gaps'=>$detected,
            'detection_available'=>is_array($fresh),
            'board_tasks'=>'explicit_governed_action_only',
            'outbound_messages'=>'not_dispatched_by_preview'));
    }
}
