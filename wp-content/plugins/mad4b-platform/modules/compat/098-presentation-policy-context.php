<?php
/** Shared presentation decisions backed by existing company service/destination policies. */
defined('ABSPATH') || exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) && (!defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') || !MAD4B_PRESENTATION_RUNTIME_ENABLED)) return;
// Keep native registration idempotent during an in-place company rollout.
if(class_exists('Presentation_Policy_Context_v1',false)) return;
final class Presentation_Policy_Context_v1 {
    private static ?WeakMap $queries = null;
    public static function decide(string $service, string $surface, string $action, int $destination = 0, string $channel = ''): array {
        $owner = function_exists('service_channel_owner') ? service_channel_owner($service) : null;
        $mode = $owner && function_exists('service_channel_mode') ? service_channel_mode($owner) : 'disabled';
        $result = ['allowed'=>false,'state'=>'hidden','reason'=>'invalid_context','service'=>$owner,
            'surface'=>$surface,'action'=>$action,'destination_id'=>$destination,'mode'=>$mode];
        if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) && (!defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') || !MAD4B_PRESENTATION_RUNTIME_ENABLED)) { $result['reason']='runtime_disabled';return $result; }
        if (!$owner || !in_array($surface,['navigation','archive','destination','tour_component'],true)
            || !in_array($action,['discover','offer'],true) || $destination < 0) return $result;
        if ($mode === 'disabled') { $result['reason']='service_disabled'; return $result; }
        $expected=$surface==='tour_component' ? 'tour_component' : ($surface==='destination' && $action==='discover' ? 'destination_discovery' : 'standalone');
        if ($channel==='') $channel=$expected;
        if (!in_array($channel,['standalone','tour_component','destination_discovery'],true)
            || ($surface==='tour_component' && $channel!=='tour_component')
            || ($surface!=='tour_component' && $channel==='tour_component')
            || ($action==='offer' && $channel==='destination_discovery')
            || ($surface==='archive' && $channel!=='standalone')
            || ($surface==='navigation' && $channel==='destination_discovery' && !$destination)) return $result;
        $result['channel']=$channel;
        if ($surface === 'tour_component') {
            if (!function_exists('shared_product_verified_presentation_tour_id') || !shared_product_verified_presentation_tour_id()) {
                $result['reason']='verified_tour_required'; return $result;
            }
        }
        if (!service_channel_allowed($owner,$channel)) {
            $result['reason']='service_component_only'; return $result;
        }
        if ($surface==='navigation' && $action!=='discover') {
            $result['reason']='navigation_is_discovery'; return $result;
        }
        if ($surface==='destination' && !$destination) {
            $result['reason']='destination_required'; return $result;
        }
        if ($destination) {
            $editorial = function_exists('shared_presentation_destination_editorial_allowed_v1')
                && shared_presentation_destination_editorial_allowed_v1($destination);
            if (!$editorial) { $result['reason']='destination_unavailable'; return $result; }
            $sale = function_exists('shared_presentation_destination_commercial_allowed_v1')
                && shared_presentation_destination_commercial_allowed_v1($destination);
            if ($action==='offer' && !$sale) {
                $result['state']='discovery';$result['reason']='destination_editorial_only';return $result;
            }
            $result['state']=$sale ? 'available' : 'discovery';
        } else $result['state']=$surface==='tour_component' ? 'component_only' : ($channel==='destination_discovery' ? 'discovery' : 'available');
        $result['allowed']=true;$result['reason']='policy_allowed';
        return $result;
    }
    /** Only a server-resolved, configured catalogue source can acquire this context. */
    public static function navigation_query(string $service): ?object {
        $decision=self::decide($service,'navigation','discover');
        if (!$decision['allowed'] || !function_exists('product_navigation_source')) return null;
        $service=(string)$decision['service'];
        $source=product_navigation_source($service);
        $definition=product_navigation_registry()[$service]??null;
        if (!$definition || empty($source['query_id']) || !class_exists('Jet_Engine\Query_Builder\Manager')) return null;
        $query=Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id((int)$source['query_id']);
        if (!$query || $query->query_type!=='posts'
            || !in_array($definition['post_type'],(array)($query->query['post_type']??[]),true)) return null;
        $copy=clone $query;
        $copy->query_id='product_navigation_'.$service;
        self::$queries ??= new WeakMap();
        self::$queries[$copy]=['service'=>$service,'surface'=>'navigation','action'=>'discover'];
        return $copy;
    }
    public static function query_decision($query): ?array {
        if (!is_object($query) || !self::$queries || !isset(self::$queries[$query])) return null;
        $context=self::$queries[$query];
        return self::decide($context['service'],$context['surface'],$context['action']);
    }
    public static function catalogue_filters_apply($query, array $contract): bool {
        return is_object($query) && self::query_decision($query)===null
            && (int)($query->id??0)===(int)($contract['query_id']??0)
            && 'jet-engine/'.($query->query_id??'')===($contract['provider']??null);
    }
}

add_action('elementor/dynamic_tags/register',static function($manager){
    $manager->register_group('presentation-policy',['title'=>'Company presentation policy']);
    if(!class_exists('Company_Presentation_Policy_Tag_v1',false)){
        abstract class Company_Presentation_Policy_Tag_v1 extends \Elementor\Core\DynamicTags\Tag {
            protected string $output='allowed';
            public function get_name(){return 'company-presentation-policy-'.$this->output;}
            public function get_title(){return 'Company policy: '.ucfirst($this->output);}
            public function get_group(){return 'presentation-policy';}
            public function get_categories(){
                $categories=[\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,\Elementor\Modules\DynamicTags\Module::POST_META_CATEGORY];
                if($this->output==='allowed')$categories[]=\Elementor\Modules\DynamicTags\Module::NUMBER_CATEGORY;
                return $categories;
            }
            protected function register_controls(){
                $this->add_control('service',['label'=>'Service','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'tours','options'=>['tours'=>'Tours','properties'=>'Accommodation','transportations'=>'Transportation','visas'=>'Visas']]);
                $this->add_control('surface',['label'=>'Presentation context','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'navigation','options'=>['navigation'=>'Navigation','archive'=>'Service archive','destination'=>'Destination content','tour_component'=>'Within the current tour']]);
                $this->add_control('action',['label'=>'Purpose','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'discover','options'=>['discover'=>'Discover','offer'=>'Show offers']]);
                $this->add_control('channel',['label'=>'Service channel','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'','options'=>[''=>'Use context default','standalone'=>'Standalone','destination_discovery'=>'Destination discovery','tour_component'=>'Tour component']]);
                $this->add_control('destination_id',['label'=>'Target destination ID','type'=>\Elementor\Controls_Manager::NUMBER,'min'=>0,'default'=>0,'description'=>'0 uses the current destination only for Destination content. Navigation otherwise evaluates the company catalogue. This controls presentation, not booking authorization.']);
            }
            public function render(){
                $surface=(string)$this->get_settings('surface');$id=(int)$this->get_settings('destination_id');
                if(!$id && $surface==='destination' && function_exists('shared_presentation_destination_requested_term_v1'))$id=shared_presentation_destination_requested_term_v1();
                $d=Presentation_Policy_Context_v1::decide((string)$this->get_settings('service'),$surface,(string)$this->get_settings('action'),$id,(string)$this->get_settings('channel'));
                echo esc_html($this->output==='allowed' ? ($d['allowed']?'1':'0') : (string)$d[$this->output]);
            }
        }
        class Company_Presentation_Allowed_Tag_v1 extends Company_Presentation_Policy_Tag_v1 {}
        class Company_Presentation_State_Tag_v1 extends Company_Presentation_Policy_Tag_v1 {protected string $output='state';}
        class Company_Presentation_Reason_Tag_v1 extends Company_Presentation_Policy_Tag_v1 {protected string $output='reason';}
    }
    $manager->register(new Company_Presentation_Allowed_Tag_v1());
    $manager->register(new Company_Presentation_State_Tag_v1());
    $manager->register(new Company_Presentation_Reason_Tag_v1());
});
