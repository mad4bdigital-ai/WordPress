<?php
/**
 * Plugin Name: MAD4B Smart Itinerary Overnight Editor
 * Description: Context-filtered, read-only availability of overnight types in the native JetEngine Tour itinerary repeater.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Smart_Itinerary_Choices_V1',false)) {
final class MAD4B_Smart_Itinerary_Choices_V1 {
    const ACTION='mad4b_smart_itinerary_overnight_choices_v1';
    const NONCE='mad4b_smart_itinerary_overnight_editor_v1';
    const KINDS=['hotel','resort','cruise','sleeper_train','none'];
    const CITY=['cairo','luxor','aswan','sharm','hurghada'];
    const DEST=['cairo','luxor','aswan','sharm','hurghada','nile_cruise','train','none'];

    public static function canEdit(int $id):bool {
        return $id>0 && get_post_type($id)==='tours-and-activities'
          && current_user_can('edit_post',$id)
          && current_user_can('edit_others_posts');
    }

    private static function labels():array {
        $l=strtolower(substr(get_user_locale(),0,2));
        if($l==='ar') return [
            'title'=>'أهلية المبيت لهذه الرحلة','checking'=>'جارٍ التحقق من المنشآت والبرامج المؤهلة…',
            'ready'=>'الخيارات مبنية على مدينة المبيت ومستوى الباقة',
            'pending'=>'مؤهل للتخطيط فقط — التوافر والحجز غير مؤكدين',
            'saved'=>'الاختيار محفوظ لكنه لم يعد مؤهلًا؛ يلزم مراجعته قبل التسعير',
            'unavailable'=>'لا توجد خدمات مؤهلة لهذا الاختيار',
            'failed'=>'تعذر التحقق من الأهلية؛ لن تُعرض خيارات جديدة حتى إعادة التحقق',
            'departure'=>'يوم المغادرة: بدون مبيت','choose'=>'اختر المدينة لتصفية نوع المبيت',
            'nocatalog'=>'لا توجد خدمات مؤهلة في وجهات هذه الرحلة',
            'level'=>'تصفية مستويات الباقة','kind'=>'نوع المبيت','destination'=>'وجهة المبيت',
            'properties'=>'منشأة مؤهلة','no_tiers'=>'مستويات الباقة غير محددة لهذه الرحلة؛ راجع تصنيف Silver / Gold / Diamond',
            'no_resort'=>'لا توجد منشأة Resort معتمدة للتأهيل لهذه المدينة والمستوى',
            'no_train'=>'خدمة قطار النوم غير مؤهلة أو مرتبطة بمورد معتمد بعد',
            'no_cruise'=>'يجب ربط برنامج كروز مؤهل بهذه المرحلة أولًا'
        ];
        if($l==='es')return [
            'title'=>'Elegibilidad de pernoctación','checking'=>'Comprobando servicios elegibles…',
            'ready'=>'Opciones según destino y nivel de paquete',
            'pending'=>'Solo apto para planificar; sin inventario ni reservas confirmadas',
            'saved'=>'Selección existente sin elegibilidad actual; requiere revisión',
            'unavailable'=>'No hay servicios elegibles','failed'=>'No se pudo comprobar la elegibilidad',
            'departure'=>'Día de salida sin alojamiento','choose'=>'Elige destino de pernoctación',
            'nocatalog'=>'No hay opciones elegibles','properties'=>'establecimientos elegibles',
            'no_tiers'=>'Asigna el nivel Silver / Gold / Diamond a esta ruta',
            'no_resort'=>'No hay resort elegible en este destino y nivel',
            'no_train'=>'Proveedor de tren nocturno sin calificación verificada',
            'no_cruise'=>'Se requiere un programa de crucero válido para esta etapa',
            'level'=>'Niveles de paquete',
            'kind'=>'Tipo de alojamiento','destination'=>'Destino de pernoctación'
        ];
        return [
            'title'=>'Overnight eligibility for this tour',
            'checking'=>'Checking qualifying properties and programs…',
            'ready'=>'Options follow the overnight city and package tier',
            'pending'=>'Planning-eligible only; date, availability and booking unconfirmed',
            'saved'=>'Existing selection no longer qualifies; review before pricing',
            'unavailable'=>'No qualified service for this option',
            'failed'=>'Eligibility could not be verified; no new selections allowed',
            'departure'=>'Departure day: no overnight',
            'choose'=>'Choose overnight city to filter accommodation type',
            'nocatalog'=>'No qualifying services found in this tour context',
            'properties'=>'qualifying properties',
            'no_tiers'=>'This tour has no assigned Silver / Gold / Diamond package level',
            'no_resort'=>'No qualifying Resort property for this city and tier',
            'no_train'=>'Sleeper train supplier eligibility has not been verified',
            'no_cruise'=>'No verified cruise program is linked to this stage',
            'level'=>'Package tiers','kind'=>'Accommodation type','destination'=>'Overnight city'
        ];
    }

    private static function choiceLabels():array {
        $locale=strtolower(substr(get_user_locale(),0,2));
        if($locale==='ar') return [
            'kind'=>['hotel'=>'فندق','resort'=>'منتجع','cruise'=>'كروز نيلي',
                'sleeper_train'=>'قطار نوم','none'=>'بدون مبيت / المغادرة'],
            'destination'=>['cairo'=>'القاهرة / الجيزة','luxor'=>'الأقصر',
                'aswan'=>'مدينة أسوان','sharm'=>'شرم الشيخ','hurghada'=>'الغردقة',
                'nile_cruise'=>'رحلة كروز النيل','train'=>'قطار النوم','none'=>'لا توجد إقامة'],
            'tiers'=>[2353=>'Silver',2354=>'Gold',2356=>'Diamond']
        ];
        if($locale==='es') return [
            'kind'=>['hotel'=>'Hotel','resort'=>'Resort','cruise'=>'Crucero por el Nilo',
                'sleeper_train'=>'Tren nocturno','none'=>'Sin pernoctación / salida'],
            'destination'=>['cairo'=>'El Cairo / Guiza','luxor'=>'Lúxor','aswan'=>'Asuán',
                'sharm'=>'Sharm el-Sheij','hurghada'=>'Hurgada','nile_cruise'=>'Crucero por el Nilo',
                'train'=>'Tren nocturno','none'=>'Sin alojamiento'],
            'tiers'=>[2353=>'Silver',2354=>'Gold',2356=>'Diamond']
        ];
        return [
            'kind'=>['hotel'=>'Hotel','resort'=>'Resort','cruise'=>'Nile cruise',
                'sleeper_train'=>'Sleeper train','none'=>'No overnight / departure'],
            'destination'=>['cairo'=>'Cairo / Giza','luxor'=>'Luxor','aswan'=>'Aswan City',
                'sharm'=>'Sharm El Sheikh','hurghada'=>'Hurghada',
                'nile_cruise'=>'Nile cruise sailing','train'=>'Sleeper train',
                'none'=>'None'],
            'tiers'=>[2353=>'Silver',2354=>'Gold',2356=>'Diamond']
        ];
    }
    public static function program(int $tour):array {
        if (!self::canEdit($tour)) return [];
        if(!class_exists('MAD4B_Itinerary_Overnight_Source_V1',false) ||
           !class_exists('MAD4B_Package_Pricing_Lab_Readiness_V1',false))return [];
        $en=MAD4B_Itinerary_Overnight_Source_V1::canonical($tour);
        if(!$en)return [];
        $plan=get_post_meta($en,'_mad4b_package_accommodation_source_plan_v1',true);
        $bindings=get_post_meta($en,'_mad4b_package_stay_verified_room_program_links_v1',true);
        $raw=get_post_meta($en,'itinerary_item',true);
        if(!is_array($raw)||count($raw)<2||count($raw)>45)return [];
        // Do not project an English day offset onto a differently shaped
        // translated itinerary editor. Preserve legacy selections for review.
        if($tour!==$en){
            $localized=get_post_meta($tour,'itinerary_item',true);
            if(!is_array($localized)||array_keys($localized)!==array_keys($raw))return [];
        }
        $seed=get_post_meta($en,'_mad4b_itinerary_overnight_seed_v1',true);
        if(is_array($seed)&&($seed['contract']??'')===
           'mad4b.itinerary-owner-source-plan-nightly-seed.v1'){
           $projection=get_post_meta($en,'_mad4b_itinerary_overnight_projection_v1',true);
           $sync=get_post_meta($en,'_mad4b_itinerary_overnight_sync_state_v1',true);
           $digest=hash('sha256',wp_json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
           if(!is_array($projection)||!hash_equals((string)($projection['source_digest']??''),$digest)
              ||($sync['status']??'')!=='source_stage_geometry_matches')return [];
        }
        $tiers=wp_get_object_terms($en,'package_levels',['fields'=>'ids']);
        if(is_wp_error($tiers))$tiers=[];
        $known=[2353,2354,2356];
        $tiers=array_values(array_intersect($known,array_map('intval',$tiers)));
        // The stage plan is used solely to determine relevant route cities and
        // already verified cruise/rail programs; it never grants sales authority.
        $cities=[];
        foreach((array)($plan['stages']??[]) as $s){
            $city=sanitize_key((string)($s['destination_key']??''));
            if(in_array($city,self::CITY,true))$cities[$city]=true;
        }
        foreach((array)$raw as $day){
            if(!is_array($day))continue;
            $city=sanitize_key((string)($day['mad4b_overnight_destination']??''));
            if(in_array($city,self::CITY,true))$cities[$city]=true;
        }
        // A tour's own exact destination city classifications may introduce
        // a hotel night not yet present in its saved accommodation stage plan.
        $itineraryTerms=wp_get_object_terms($en,'destinations',['fields'=>'slugs']);
        if(!is_wp_error($itineraryTerms))foreach($itineraryTerms as $term){
            $city=match($term){
                'cairo'=>'cairo','luxor','luxor-city'=>'luxor',
                'aswan-city','aswan-gov'=>'aswan',
                'sharm-el-sheikh'=>'sharm','hurghada'=>'hurghada',
                default=>''
            };
            if(in_array($city,self::CITY,true))$cities[$city]=true;
        }
        $stageData=['cruise'=>[],'sleeper_train'=>[]];
        if(is_array($plan)&&is_array($bindings)){
            $byKey=[];
            foreach((array)($bindings['bindings']??[]) as $b)
                if(is_array($b)&&isset($b['stage_key']))$byKey[(string)$b['stage_key']]=$b;
            foreach((array)($plan['stages']??[]) as $stage){
                $kind=(string)($stage['lodging_kind']??'');
                if(!isset($stageData[$kind]))continue;
                $name=(string)($stage['stage_key']??'');
                $b=$byKey[$name]??[];
                $start=(int)($stage['source_day_start']??0);
                $length=(int)($stage['nights']??0);
                if($start<1||$length<1||$start+$length>count($raw))continue;
                $verified=false;$reason='';
                if($kind==='cruise'){
                    $program=$b['cruise_program']??[];
                    $termId=(int)($program['program_term_id_en']??0);
                    $term=$termId?get_term($termId,'programs'):null;
                    $verified=($term instanceof WP_Term)
                        && (int)($program['nights']??0)===$length
                        && ($program['status']??'')==='verified_source_program_duration_direction_only'
                        && ($program['route_from']??'')!==''
                        && ($program['route_to']??'')!=='';
                    $reason=$verified?'program_verified_no_vessel_assignment':'cruise_program_not_validated';
                    if($verified) {
                        // Reuse the same company-approved Property source
                        // receipts as hotel eligibility. A program alone cannot
                        // qualify a cruise without a published Cruise Property.
                        static $approvedCruiseCatalogPresent=null;
                        if($approvedCruiseCatalogPresent===null){
                            $approvedCruiseCatalogPresent=false;
                            $cruiseProperties=get_posts([
                                'post_type'=>'properties','post_status'=>'publish',
                                'fields'=>'ids','posts_per_page'=>500,
                                'suppress_filters'=>true,
                                'tax_query'=>[[
                                    'taxonomy'=>'property-types','field'=>'slug',
                                    'terms'=>['cruise','nile-cruise'],'operator'=>'IN'
                                ]]
                            ]);
                            foreach($cruiseProperties as $cruiseProperty){
                                $r=get_post_meta($cruiseProperty,
                                    '_mad4b_company_sheet_property_cost_sources_v1',true);
                                if(!is_array($r)||
                                   ($r['contract']??'')!=='mad4b.company-property-cost-source-matrix.v1'||
                                   ($r['checkout_authorized']??true)!==false)continue;
                                foreach((array)($r['sources']??[]) as $cost){
                                    if(($cost['source_tab']??'')==='Cruises'&&
                                       ($cost['cost_value_owner_approved']??false)===true){
                                        $approvedCruiseCatalogPresent=true;break 2;
                                    }
                                }
                            }
                        }
                        $verified=$approvedCruiseCatalogPresent;
                        if(!$verified)$reason='no_published_owner_approved_cruise_property';
                    }
                }
                // Train_line rows alone do not certify an overnight sleeper
                // supplier; keep historic choices visible with a review flag.
                if($kind==='sleeper_train'){
                    $verified=false;
                    $reason='sleeper_supplier_contract_not_confirmed';
                }
                for($day=$start-1;$day<$start-1+$length;$day++){
                    $stageData[$kind][$day]=[
                        'editorial_eligible'=>$verified,
                        'reason'=>$reason,'program_stage'=>$name,
                        'commercially_eligible'=>false
                    ];
                }
            }
        }
        // Reject post-wide generic city pools; no unrelated city appears
        // unless that city is part of itinerary-derived overnight context.
        return [
            'tour_id'=>$en,'viewed_post_id'=>$tour,
            'tiers'=>$tiers, 'cities'=>array_keys($cities),
            'itinerary_days'=>count($raw), 'existing_programs'=>$stageData,
            'plan_matches_source'=>is_array($plan)&&
              ($plan['tour_days']??0)===count($raw),
            'commercial_price_authorized'=>false
        ];
    }

    public static function matrix(int $tour):array {
        $p=self::program($tour);
        if(!$p)return [];
        $catalog=[];
        foreach($p['cities'] as $city){
            $catalog[$city]=['hotel'=>[],'resort'=>[]];
            $st=['destination_key'=>$city,'lodging_kind'=>'hotel',
                 'source_day_start'=>1,'nights'=>1];
            $scope=MAD4B_Package_Pricing_Lab_Readiness_V1::stageDestinationScope(
                $p['tour_id'],$st);
            if(($scope['status']??'')!=='mapped')continue;
            $ids=get_posts([
                'post_type'=>'properties','post_status'=>'publish',
                'fields'=>'ids','posts_per_page'=>250,'orderby'=>'ID','order'=>'ASC',
                'suppress_filters'=>true,
                'tax_query'=>['relation'=>'AND',[
                  'taxonomy'=>'destinations','field'=>'term_id',
                  'terms'=>$scope['accepted_term_ids'],
                  'include_children'=>false,'operator'=>'IN'
                ],[
                  'taxonomy'=>'package_levels','field'=>'term_id',
                  'terms'=>$p['tiers']?:[0],'include_children'=>false,'operator'=>'IN'
                ]],
                'meta_query'=>[[
                  'key'=>'prop_use_in_tour_rate_generation','value'=>'1','compare'=>'='
                ]]
            ]);
            foreach($ids as $id){
                $id=(int)$id;
                $en=(int)apply_filters('wpml_object_id',$id,'properties',false,'en');
                if($en!==$id)continue;
                $receipt=get_post_meta($id,'_mad4b_company_sheet_property_cost_sources_v1',true);
                if(!is_array($receipt) ||
                  ($receipt['contract']??'')!=='mad4b.company-property-cost-source-matrix.v1' ||
                  ($receipt['checkout_authorized']??true)!==false ||
                  !array_filter((array)($receipt['sources']??[]),
                    static fn($a)=>($a['source_tab']??'')==='Hotels'))continue;
                $validTiers=[];
                foreach($p['tiers'] as $t){
                    $elig=MAD4B_Package_Pricing_Lab_Readiness_V1::propertyEligibility(
                        $id,$t,$scope,'hotel');
                    if(($elig['eligible']??false)===true)$validTiers[]=$t;
                }
                if(!$validTiers)continue;
                $types=wp_get_object_terms($id,'property-types',['fields'=>'slugs']);
                if(is_wp_error($types))continue;
                $type=in_array('resort',(array)$types,true)?'resort'
                  :(in_array('hotel',(array)$types,true)?'hotel':'');
                if(!$type)continue;
                $catalog[$city][$type][]=[
                    'property_id'=>$id,'tier_ids'=>$validTiers
                ];
            }
        }
        $out=['contract'=>'mad4b.editorial-overnight-smart-options.v1',
          'tour_id'=>$p['tour_id'],
          'tiers'=>$p['tiers'],
          'days'=>$p['itinerary_days'],
          'cities'=>$p['cities'],
          'hotels'=>[],
          'day_specific'=>[],
          'kind_keys'=>self::KINDS,
          'destination_keys'=>self::DEST,
          'inventory_verified'=>false,'date_specific_price_verified'=>false,
          'checkout_authorized'=>false,'labels'=>self::labels(),
          'choice_labels'=>self::choiceLabels()
        ];
        foreach($catalog as $city=>$groups)foreach($groups as $type=>$matches){
            $out['hotels'][$city][$type]=[
                'count'=>count($matches),
                'property_ids'=>array_column($matches,'property_id'),
                'tiers'=>array_values(array_unique(array_merge(...array_map(
                  static fn($x)=>$x['tier_ids'],$matches) ?: [[]])))
            ];
        }
        // Cruise and sleeper stages must be considered within the specific
        // itinerary day; a verified program cannot be copied to any day.
        for($day=0;$day<$p['itinerary_days'];$day++){
            $out['day_specific'][(string)$day]=[
                'cruise'=>$p['existing_programs']['cruise'][$day]??[
                    'editorial_eligible'=>false,'reason'=>'no_native_cruise_program_at_this_day'],
                'sleeper_train'=>$p['existing_programs']['sleeper_train'][$day]??[
                    'editorial_eligible'=>false,'reason'=>'no_verified_sleeper_service_at_this_day'],
                'departure_only'=>($day===$p['itinerary_days']-1)
            ];
        }
        $out['day_specific']=(object)$out['day_specific'];
        return $out;
    }
    public static function ajax():void {
        if(!isset($_POST['tour_id']))wp_send_json_error(['code'=>'missing_tour_id'],400);
        $id=absint($_POST['tour_id']);
        if(!self::canEdit($id))wp_send_json_error(['code'=>'not_authorized'],403);
        check_ajax_referer(self::NONCE,'nonce');
        $res=self::matrix($id);
        if(!$res)wp_send_json_error(['code'=>'no_valid_structured_tour_context'],422);
        wp_send_json_success($res);
    }
    public static function adminAssets($hook):void {
        if(!in_array($hook,['post.php','post-new.php'],true))return;
        $screen=function_exists('get_current_screen')?get_current_screen():null;
        if(!$screen || ($screen->post_type??'')!=='tours-and-activities')return;
        $post=isset($_GET['post'])?absint($_GET['post']):0;
        if(!$post || !self::canEdit($post))return;
        $path=__DIR__.'/assets/mad4b-smart-itinerary-overnight-v1.js';
        if(!is_readable($path))return;
        $handle='mad4b-smart-itinerary-overnight-v1';
        wp_enqueue_script($handle,set_url_scheme(plugins_url('assets/mad4b-smart-itinerary-overnight-v1.js',__FILE__),'https'),
          [],substr(hash_file('sha256',$path),0,12),true);
        wp_add_inline_script($handle,
          'window.MAD4BSmartOvernightV1='.wp_json_encode([
            'ajax_url'=>admin_url('admin-ajax.php'),
            'action'=>self::ACTION,'nonce'=>wp_create_nonce(self::NONCE),
            'tour_id'=>$post,'labels'=>self::labels()
          ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';','before');
    }
}
add_action('wp_ajax_'.MAD4B_Smart_Itinerary_Choices_V1::ACTION,
  [MAD4B_Smart_Itinerary_Choices_V1::class,'ajax']);
add_action('admin_enqueue_scripts',
  [MAD4B_Smart_Itinerary_Choices_V1::class,'adminAssets'],60);
}

