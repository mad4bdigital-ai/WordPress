<?php
/**
 * Plugin Name: MAD4B UAEL Tour Gallery Addon
 * Description: Tour-specific itinerary categorization and uniform image proportions using UAEL's native filtering, Masonry and Fancybox.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_UAEL_Tour_Gallery_Addon_V1 {
 const VERSION='0.1.0';
 private static array $categories=[];
 private static array $tab_labels=[];
 private static ?string $widget_scope=null;
 private static array $widget_meta=[];
 public static function enabled(int $post=0):bool {
    $trusted=class_exists('MAD4B_UAEL_Server_Defer_V1',false)
       &&MAD4B_UAEL_Server_Defer_V1::in_deferred_render();
    if(!$trusted && (is_admin()||is_preview()||(defined('REST_REQUEST')&&REST_REQUEST)))return false;
    // Wayfatours' theme-level main-tour helper intentionally rejects
    // admin-ajax requests, even when our signed endpoint establishes the
    // published singular tour. Trust ONLY that verified endpoint context.
    if(!$trusted){
       if(wp_doing_ajax() || !function_exists('mad4b_is_main_tour_singular')
          || !mad4b_is_main_tour_singular())return false;
    }elseif(!is_singular('tours-and-activities')
       ||(int)get_queried_object_id()<1)return false;
    if(!$post)$post=(int)get_queried_object_id();
    if(get_post_status($post)!=='publish'||get_post_type($post)!=='tours-and-activities')return false;
    $mode=(string)get_option('mad4b_uael_tour_gallery_mode','off');
    return $mode==='active'||($mode==='canary'&&isset($_GET['mad4b_uael_gallery_canary']));
 }
 private static function verified_gallery_program():int {
    return class_exists('MAD4B_UAEL_Server_Defer_V1',false)
       &&MAD4B_UAEL_Server_Defer_V1::in_deferred_render()
      ?MAD4B_UAEL_Server_Defer_V1::selected_program():0;
 }
 private static function current_story(int $tour):array {
    $chosen=self::verified_gallery_program();
    $story=MAD4B_Tour_Story_Gallery_V1::build($tour,false,$chosen);
    // A multi-program Cruise without a chosen Program still needs its
    // native gallery plus the program selector. Its Timeline must remain
    // withheld until the visitor explicitly chooses a linked Program.
    if(!$chosen &&!empty($story['program_selection_required'])
       &&((int)($story['image_count']??0)<3 ||empty($story['sections'])))
       $story=MAD4B_Tour_Story_Gallery_V1::build($tour,true);
    return $story;
 }
 public static function can_render(int $post=0):bool {
    if(!self::enabled($post))return false;
    if(!$post)$post=(int)get_queried_object_id();
    if(!class_exists('MAD4B_Tour_Story_Gallery_V1',false))return false;
    static $readback=[];
    $lang=(string)apply_filters('wpml_current_language','en');
    $key=$post.'|'.$lang.'|'.self::verified_gallery_program()
      .'|'.get_option('mad4b_story_gallery_media_revision_v1',1);
    if(!array_key_exists($key,$readback)){
       $story=self::current_story($post);
       $readback[$key]=(int)($story['image_count']??0)>=3 &&!empty($story['sections']);
    }
    return $readback[$key];
 }
 public static function is_target($widget):bool {
    return is_object($widget)&&method_exists($widget,'get_name')&&method_exists($widget,'get_id')
      &&$widget->get_name()==='uael-image-gallery' && $widget->get_id()==='1e96a81';
 }
 public static function controls($element):void {
    if(!is_object($element)||!method_exists($element,'start_controls_section'))return;
    $element->start_controls_section('mad4b_uael_story_section',[
      'label'=>__('MAD4B · Itinerary Gallery','mad4b'),'tab'=>\Elementor\Controls_Manager::TAB_CONTENT
    ]);
    $element->add_control('mad4b_uael_story_enable',[
      'label'=>__('Group by Itinerary and Destinations','mad4b'),
      'type'=>\Elementor\Controls_Manager::SWITCHER,
      'label_on'=>__('On','mad4b'),'label_off'=>__('Off','mad4b'),
      'return_value'=>'yes','default'=>'yes'
    ]);
    $element->add_control('mad4b_uael_ratio',[
      'label'=>__('Uniform Image Proportions','mad4b'),
      'type'=>\Elementor\Controls_Manager::SELECT,'default'=>'4-3',
      'options'=>[
        'original'=>__('Original proportions','mad4b'),
        '1-1'=>__('Square 1:1','mad4b'),
        '4-3'=>__('Landscape 4:3','mad4b'),
        '16-9'=>__('Wide 16:9','mad4b'),
        '3-4'=>__('Portrait 3:4','mad4b')
      ]
    ]);
    $element->add_control('mad4b_uael_timeline',[
      'label'=>__('Show Itinerary Day Labels','mad4b'),
      'type'=>\Elementor\Controls_Manager::SWITCHER,
      'return_value'=>'yes','default'=>'yes'
    ]);
    $element->add_control('mad4b_uael_defer',[
      'label'=>__('Defer Gallery DOM Until Popup Opens','mad4b'),
      'type'=>\Elementor\Controls_Manager::SWITCHER,
      'return_value'=>'yes','default'=>'yes'
    ]);
    $element->end_controls_section();
 }
 private static function captions(int $post=0):array {
    // A translated tour can be rendered inside Elementor's English popup
    // template context. The visitor's tour post is the authoritative locale,
    // not a transient global WPML switch during widget rendering.
    $details=$post>0?apply_filters('wpml_post_language_details',null,$post):null;
    $lang=(string)(is_array($details)?($details['language_code']??''):'');
    if($lang==='')$lang=(string)apply_filters('wpml_current_language','en');
    return match($lang) {
      'ar'=>['all'=>'كل الصور','day'=>'اليوم','days'=>'الأيام','photos'=>'صورة','tour'=>'صور الرحلة'],
      'es'=>['all'=>'Todas las fotos','day'=>'Día','days'=>'Días','photos'=>'fotos','tour'=>'Fotos del viaje'],
      'fr'=>['all'=>'Toutes les photos','day'=>'Jour','days'=>'Jours','photos'=>'photos','tour'=>'Photos du voyage'],
      'de'=>['all'=>'Alle Fotos','day'=>'Tag','days'=>'Tage','photos'=>'Fotos','tour'=>'Reisefotos'],
      'it'=>['all'=>'Tutte le foto','day'=>'Giorno','days'=>'Giorni','photos'=>'foto','tour'=>'Foto del viaggio'],
      default=>['all'=>'All Photos','day'=>'Day','days'=>'Days','photos'=>'photos','tour'=>'Tour Photos'],
    };
 }
 /**
  * Match a Cruise photo to an exact day using its OWN verified destination
  * term and the canonical Program's day text. Never round-robin a landmark
  * into an unrelated day just because both belong to Luxor or Aswan.
  * Unmatched / optional-only photos remain available in UAEL's All Photos.
  */
 private static function photo_program_day(array $photo,array $policy):int {
    $sourceId=absint($photo['source_term_id']??0);
    $days=(array)($policy['canonical_days']??[]);
    if(!$sourceId||!$days)return 0;
    static $names=[];
    if(!array_key_exists($sourceId,$names)){
       global $wpdb;
       $names[$sourceId]=(string)$wpdb->get_var($wpdb->prepare(
         "SELECT t.name FROM {$wpdb->terms} t
          INNER JOIN {$wpdb->term_taxonomy} tx ON tx.term_id=t.term_id
          WHERE t.term_id=%d AND tx.taxonomy=%s LIMIT 1",$sourceId,'destinations'));
    }
    $termName=trim((string)$names[$sourceId]);
    if($termName==='')return 0;
    $normalize=static function(string $value):string {
       $text=remove_accents(mb_strtolower(
         html_entity_decode(wp_strip_all_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8'),'UTF-8'));
       return trim((string)preg_replace('/[^\p{L}\p{N}]+/u',' ',$text));
    };
    $needle=$normalize($termName);
    if(mb_strlen($needle,'UTF-8')<4)return 0;
    $best=0;$bestScore=0;
    foreach($days as $row){
       if(!is_array($row))continue;
       $day=absint($row['day']??0);
       if(!$day)continue;
       $title=$normalize((string)($row['title']??''));
       $detail=$normalize((string)($row['description']??''));
       $inTitle=str_contains(' '.$title.' ',' '.$needle.' ');
       $inDetail=str_contains(' '.$detail.' ',' '.$needle.' ');
       if(!$inTitle&&!$inDetail)continue;
       $score=($inTitle?4:0)+($inDetail?2:0);
       // A place followed by optional excursion wording alone does not
       // authorize a photo under a guaranteed itinerary day.
       $optional='/(?:^| )'.preg_quote($needle,'/').
          '(?: [\p{L}\p{N}]+){0,3} (?:optional|opcional|optionnel|facultatif)(?: |$)/u';
       if(!$inTitle&&preg_match($optional,' '.$detail.' '))continue;
       if(preg_match('/\b(?:sightseeing|visit|explore|tour|temples)\b/u',$title))$score++;
       if(preg_match('/\b(?:departure|sailing to|transfer only)\b/u',$title))$score--;
       if($score>$bestScore){$bestScore=$score;$best=$day;}
    }
    return $best;
 }
 /**
  * Day-tour photo provenance: match the image's named landmark to exactly
  * one visit stage. In particular, generic city photos must never be
  * round-robined into Hotel Pickup, lunch or Return to Hotel.
  * Ambiguous/unmatched media stays in the original UAEL All Photos set.
  */
 private static function photo_stage_match(array $photo,array $stages):int {
    $normalize=static function($text):string {
      $text=remove_accents(mb_strtolower(
        html_entity_decode(wp_strip_all_tags((string)$text),ENT_QUOTES|ENT_HTML5,'UTF-8'),'UTF-8'));
      return trim((string)preg_replace('/[^\p{L}\p{N}]+/u',' ',$text));
    };
    $alt=$normalize($photo['alt']??'');
    if(mb_strlen($alt)<5)return 0;
    $avoid=['and','with','from','about','your','into','visit','tour','tours',
       'private','egypt','egyptian','discover','discovery','amazing',
       'experience','journey','great','local','view','views','hotel',
       'pickup','pick','return','start','stop','end','lunch','breakfast',
       'sightseeing','day','days','city','travel','grand','temple','museum',
       'the','this','photo','photos','night','show'];
    $allTokens=[];$perStage=[];
    foreach($stages as $n=>$stage){
      $title=$normalize($stage['title']??'');
      $tokens=array_filter(explode(' ',$title),static fn($w)=>
        mb_strlen($w)>=5&&!in_array($w,$avoid,true));
      $tokens=array_values(array_unique($tokens));
      $perStage[$n]=['title'=>$title,'tokens'=>$tokens];
      foreach($tokens as $token)$allTokens[$token]=($allTokens[$token]??0)+1;
    }
    $matches=[];
    foreach($perStage as $n=>$stage){
      $title=$stage['title'];
      if($title==='')continue;
      $text=$title.' '.$normalize($stages[$n]['summary']??'');
      if(str_contains($alt,'sound and light')&&!str_contains($text,'sound and light'))
        continue;
      if(str_contains($alt,'by night')&&!str_contains($text,'by night'))continue;
      $score=0;
      if(mb_strlen($title)>=10 &&str_contains(' '.$alt.' ',' '.$title.' '))
        $score=20;
      foreach($stage['tokens'] as $token)
        if(($allTokens[$token]??0)===1
          &&str_contains(' '.$alt.' ',' '.$token.' '))$score+=8;
      if($score>=8)$matches[$n]=$score;
    }
    if(!$matches)return 0;
    arsort($matches,SORT_NUMERIC);
    $values=array_values($matches);
    if(count($values)>1&&$values[0]===$values[1])return 0;
    return (int)array_key_first($matches);
 }
 private static function timeline_manifest(int $post,array $story,array $locale):array {
    $canonical=(int)($story['canonical_post_id']??$post);
    // An empty JetEngine repeater often reads as '' instead of an array.
    // Casting '' to (array) produces [0 => ''], which incorrectly bypasses
    // the canonical tour fallback for partially translated WPML tours.
    $policy=class_exists('MAD4B_Gallery_Itinerary_Policy_V1',false)
      ?MAD4B_Gallery_Itinerary_Policy_V1::resolve(
          $post,$canonical,self::verified_gallery_program()):[];
    if(!empty($policy['selection_required']))return [];
    $program_authoritative=($policy['authority']??'')==='taxonomy:programs:program_itinerary';
    $is_stage=class_exists('MAD4B_Gallery_Stage_Policy_V1',false)
      &&MAD4B_Gallery_Stage_Policy_V1::enabled($canonical);
    $stages=$is_stage?MAD4B_Gallery_Stage_Policy_V1::rows($post,$canonical):[];
    $raw=$is_stage?$stages:($program_authoritative?
      (array)($policy['days']??[]):get_post_meta($post,'itinerary_item',true));
    $usable=static function($rows):bool {
        if(!is_array($rows))return false;
        foreach($rows as $row)
            if(is_array($row)
                &&(trim((string)($row['title']??''))!==''
                    ||absint($row['number']??0)>0))return true;
        return false;
    };
    if($is_stage && !$stages)return []; // No invented stops for an empty one-day itinerary.
    if(!$usable($raw) && $program_authoritative)return [];
    if(!$usable($raw) && ($policy['authority']??'')==='taxonomy:programs')return [];
    if(!$usable($raw))$raw=get_post_meta($canonical,'itinerary_item',true);
    if(!$usable($raw))return [];
    $days=[];
    foreach($raw as $index=>$row){
      if(!is_array($row))continue;
      $num=$is_stage?(int)($row['stage']??0):(int)($row['day']??$row['number']??0);
      if(!$is_stage && !$num&&preg_match('/\b(?:Day|Día|Jour|Tag|Giorno)\s*(\d+)/iu',(string)($row['title']??''),$m))$num=(int)$m[1];
      if(!$num)$num=count($days)+1;
      if($num<1||$num>45)continue;
      $title=html_entity_decode(wp_strip_all_tags((string)($row['title']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
      $title=trim(preg_replace('/^(?:Day|Día|Jour|Tag|Giorno|اليوم)\s*\d+\s*[:\-–.]?\s*/iu','',$title));
      $detail=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags((string)($row['description']??$row['detail']??''))));
      $days[$num]=['day'=>$num,'title'=>mb_substr($title,0,135),'summary'=>mb_substr($detail,0,190),
           'photos'=>[],'optional'=>(bool)preg_match('/\boptional\b|\bopcional\b|\bfacultatif\b|\boptionnel\b/iu',(string)($row['title']??'')),
           'start_time'=>$is_stage?(string)($row['start_time']??''):'',
           'end_time'=>$is_stage?(string)($row['end_time']??''):'',
           'time_label'=>$is_stage?(string)($row['time_label']??''):''];
    }
    if(!$days)return [];
    ksort($days,SORT_NUMERIC);
    // Image provenance is locale-neutral. Match photo landmarks to source
    // stage text even when the visitor sees translated stage titles.
    $photoStageEvidence=$days;
    if($is_stage && $post!==$canonical){
      foreach(MAD4B_Gallery_Stage_Policy_V1::rows($canonical,$canonical) as $sourceStage){
         $idx=(int)($sourceStage['stage']??0);
         if($idx>0&&isset($photoStageEvidence[$idx])){
           $photoStageEvidence[$idx]['title']=$sourceStage['title'];
           $photoStageEvidence[$idx]['summary']=$sourceStage['description'];
         }
      }
    }
    $used=[];$overview=[];
    foreach((array)($story['sections']??[]) as $section){
      $section_days=array_values(array_filter(array_unique(array_map('intval',(array)($section['days']??[]))),
           static fn($d)=>isset($days[$d])));
      // A day explicitly naming the destination is valid context even if
      // the photo-quality engine excluded logistics from coverage thresholds.
      $name=trim((string)($section['title']??''));
      if($name!==''&&($section['id']??'')!=='tour')foreach($days as $num=>$entry){
         if(in_array($num,$section_days,true))continue;
         if(mb_stripos($entry['title'],$name)!==false)$section_days[]=$num;
      }
      sort($section_days,SORT_NUMERIC);
      $i=0;
      foreach((array)($section['images']??[]) as $photo){
        $hash=(string)($photo['origin']??'');$url=(string)($photo['url']??'');
        if($hash===''||$url===''||isset($used[$hash]))continue;
        $used[$hash]=true;
        $item=['id'=>(int)($photo['id']??0),'url'=>$url,
          'thumbnail'=>(string)($photo['thumbnail']??$url),
          'alt'=>mb_substr((string)($photo['alt']??''),0,150),
          'destination'=>mb_substr($name,0,75),'source'=>(string)($photo['source']??'')];
        if($program_authoritative){
          // A precise landmark term beats a broad city/category day list.
          // If no Program day explicitly contains the landmark, do not
          // attribute this photo to any day. Keep it in Tour/All Photos.
          $num=self::photo_program_day($photo,$policy);
          if(!$num || !isset($days[$num])){$overview[]=$item;continue;}
          $days[$num]['photos'][]=$item;
        }elseif($is_stage){
          $num=self::photo_stage_match($item,$photoStageEvidence);
          if(!$num||!isset($days[$num])){$overview[]=$item;continue;}
          $days[$num]['photos'][]=$item;
        }else{
          if(!$section_days){$overview[]=$item;continue;}
          $num=$section_days[$i%count($section_days)];$days[$num]['photos'][]=$item;
          $i++;
        }
      }
    }
    $stageLabels=match((string)($locale['day']??'Day')){
      'اليوم'=>['stage'=>'محطة','plural'=>'مراحل','title'=>'مراحل الزيارة','fallback'=>'لا توجد صورة موثّقة لهذه المرحلة'],
      'Día'=>['stage'=>'Parada','plural'=>'paradas','title'=>'Etapas de la visita','fallback'=>'Sin foto verificada de esta etapa'],
      'Jour'=>['stage'=>'Étape','plural'=>'étapes','title'=>'Étapes de la visite','fallback'=>'Aucune photo vérifiée pour cette étape'],
      'Tag'=>['stage'=>'Stopp','plural'=>'Stopps','title'=>'Besuchsablauf','fallback'=>'Kein verifiziertes Foto für diesen Stopp'],
      'Giorno'=>['stage'=>'Tappa','plural'=>'tappe','title'=>'Tappe della visita','fallback'=>'Nessuna foto verificata per questa tappa'],
      default=>['stage'=>'Stop','plural'=>'stops','title'=>'Visit Stages','fallback'=>'No verified photo for this stop']
    };
    return ['days'=>array_values($days),'overview'=>array_slice($overview,0,32),
      'timeline_unit'=>$is_stage?'stage':'day',
      'time_source'=>$is_stage?'confirmed_stage_time_only':'not_applicable',
      'times_confirmed_count'=>$is_stage?count(array_filter($days,static fn($d)=>!empty($d['start_time']))):0,
      'day_label'=>$is_stage?$stageLabels['stage']:$locale['day'],
      'days_plural'=>$is_stage?$stageLabels['plural']:$locale['days'],'photos_label'=>$locale['photos'],
      'optional_label'=>match((string)($locale['day']??'Day')){
        'اليوم'=>'اختياري','Día'=>'Opcional','Jour'=>'Facultatif','Tag'=>'Optional',
        'Giorno'=>'Facoltativo',default=>'Optional'},
      'timeline_title'=>$is_stage?$stageLabels['title']:match((string)($locale['day']??'Day')){
        'اليوم'=>'البرنامج اليومي','Día'=>'Itinerario día a día','Jour'=>'Programme jour par jour',
        'Tag'=>'Reiseverlauf nach Tagen','Giorno'=>'Itinerario giorno per giorno',
        default=>'Itinerary Timeline'},
      'all_title'=>$locale['all'],'overview_title'=>$locale['tour'],
      'fallback_text'=>$is_stage?$stageLabels['fallback']:match((string)($locale['day']??'Day')){
        'اليوم'=>'لا توجد صورة مؤكدة لهذا اليوم','Día'=>'Sin foto verificada de este día',
        'Jour'=>'Pas de photo vérifiée pour cette journée',
        'Tag'=>'Kein verifiziertes Bild für diesen Tag',
        'Giorno'=>'Nessuna foto verificata per questo giorno',
        default=>'No verified image for this day'},
      'is_rtl'=>(string)($locale['day']??'')==='اليوم',
      'itinerary_source'=>$is_stage?'tour:itinerary_item:stages':
        ($program_authoritative?'programs:program_itinerary':'tour:itinerary_item'),
      'tour_mode'=>(string)($policy['mode']??''),
      'program_id'=>$program_authoritative?(int)($policy['program_id']??0):0,
      'program_label'=>$program_authoritative?(string)($policy['program_name']??''):'',
      'day_photo_coverage'=>count(array_filter($days,static fn($d)=>count($d['photos'])>0)),
      'days_without_verified_photos'=>array_values(array_map('intval',array_keys(array_filter(
        $days,static fn($d)=>!count($d['photos']))))),
      'unassigned_verified_photo_count'=>count($overview),
      'program_prefix'=>match((string)($locale['day']??'Day')){
        'اليوم'=>'برنامج الرحلة','Día'=>'Programa del crucero',
        'Jour'=>'Programme de croisière','Tag'=>'Kreuzfahrtprogramm',
        'Giorno'=>'Programma della crociera',default=>'Cruise Program'},
      'unique_photo_count'=>count($used)];
 }
 /**
  * Read-only operational snapshot for governed Content Job intake.
  * No arbitrary media discovery, no public requests and no rights grants.
  * The native UAEL timeline remains the sole source of visible coverage.
  */
 public static function coverage_snapshot(int $tour):array {
    if($tour<1||get_post_type($tour)!=='tours-and-activities'
      ||get_post_status($tour)!=='publish'
      ||!class_exists('MAD4B_Tour_Story_Gallery_V1',false))
      return ['status'=>'unavailable'];
    $story=MAD4B_Tour_Story_Gallery_V1::build($tour);
    $locale=self::captions($tour);
    $data=self::timeline_manifest($tour,$story,$locale);
    if(!$data)return ['status'=>'timeline_not_available'];
    $days=[];
    foreach((array)($data['days']??[]) as $day)
      $days[]=['day'=>absint($day['day']??0),
        'title'=>(string)($day['title']??''),
        'photo_count'=>count((array)($day['photos']??[])),
        'optional'=>(bool)($day['optional']??false)];
    return ['status'=>'ready','source'=>(string)($data['itinerary_source']??''),
      'program_id'=>(int)($data['program_id']??0),'tour_mode'=>(string)($data['tour_mode']??''),
      'days'=>$days,'unassigned_verified_count'=>(int)($data['unassigned_verified_photo_count']??0),
      'total_verified_photos'=>(int)($story['image_count']??0),
      'media_revision'=>(string)get_option('mad4b_story_gallery_media_revision_v1','1')];
 }
 /**
  * Native UAEL category label. Stage numbers are itinerary row indexes, NOT
  * multi-day travel days; only label a verified stop when it exists in the
  * canonical/translated stage timeline. Keep native UAEL category slugs,
  * membership, photos, order and Fancybox entirely unchanged.
  */
 public static function category_label(array $section,array $locale,array $timeline,
                                      bool $is_stage,bool $show_timeline):string {
    $title=($section['id']??'')==='tour'?(string)($locale['tour']??'Tour Photos'):
       (string)($section['title']??'');
    if(!$show_timeline)return $title;
    $indices=array_values(array_unique(array_filter(array_map('intval',
       (array)($section['days']??[])),static fn($n)=>$n>0)));
    if(!$indices)return $title;
    if($is_stage){
       $verified=[];
       foreach((array)($timeline['days']??[]) as $stage){
          if(!is_array($stage))continue;
          $number=(int)($stage['day']??0);
          if($number>0)$verified[$number]=true;
       }
       $indices=array_values(array_filter($indices,
         static fn($n)=>isset($verified[$n])));
       // No confirmed stage index = no Stops or Days prefix. Do not
       // reinterpret old destination day indexes as a real visit schedule.
       if(!$indices)return $title;
       $unit=count($indices)>1?(string)($timeline['days_plural']??''):
         (string)($timeline['day_label']??'');
       if($unit==='')return $title;
       $unit=mb_convert_case($unit,MB_CASE_TITLE,'UTF-8');
    }else{
       $unit=count($indices)>1?(string)($locale['days']??'Days'):
         (string)($locale['day']??'Day');
    }
    return $unit.' '.implode(', ',$indices).' · '.$title;
 }
 public static function before($widget):void {
    if(!self::is_target($widget))return;
    if((string)$widget->get_settings('mad4b_uael_story_enable')!=='yes')return;
    if(!self::can_render((int)get_queried_object_id()))return;
    if(!class_exists('MAD4B_Tour_Story_Gallery_V1',false))return;
    $tour=(int)get_queried_object_id();
    $story=self::current_story($tour);
    $sections=(array)($story['sections']??[]);
    if(count($sections)<1 ||(int)($story['image_count']??0)<3)return;
    $pictures=[];$ids=[];$per_id=[];$labels=[];
    $locale=self::captions($tour);
    // The existing timeline is authoritative for distinguishing verified
    // visit stops from multi-day itinerary days. Build it once for both
    // category labels and the deferred UAEL timeline JSON.
    $timeline_plan=self::timeline_manifest($tour,$story,$locale);
    $canonical=(int)($story['canonical_post_id']??$tour);
    $is_stage=class_exists('MAD4B_Gallery_Stage_Policy_V1',false)
      &&MAD4B_Gallery_Stage_Policy_V1::enabled($canonical);
    $show_timeline=(string)$widget->get_settings('mad4b_uael_timeline')==='yes';
    foreach($sections as $order=>$section){
      $sid=is_numeric($section['id']??null)?(int)$section['id']:0;
      $slug='mad4b-s'.($sid?:'tour').'-o'.$order;
      $labels[$slug]=self::category_label(
         $section,$locale,$timeline_plan,$is_stage,$show_timeline);
      foreach((array)($section['images']??[])as $photo){
        $id=absint($photo['id']??0);
        if(!$id || get_post_type($id)!=='attachment'
          ||(class_exists('MAD4B_Tour_Watermark_Guard_V1',false)
               &&!MAD4B_Tour_Watermark_Guard_V1::approved_for_public($id)))continue;
        $origin=(string)($photo['origin']??$id);
        if(!isset($ids[$origin])){
          $ids[$origin]=$id;
          $pictures[]=['id'=>$id,'url'=>(string)($photo['url']??wp_get_attachment_url($id))];
        }
        if(!isset($per_id[$id]))$per_id[$id]=[];
        $per_id[$id][$slug]=true;
      }
    }
    if(count($pictures)<3)return;
    self::$categories=array_map(static fn($a)=>implode(',',array_keys($a)),$per_id);
    self::$tab_labels=$labels;
    self::$widget_scope=$widget->get_id();
    $settings=$widget->get_settings();
    $dyn=(array)($settings['__dynamic__']??[]);
    unset($dyn['wp_gallery']);
    $widget->set_settings('__dynamic__',$dyn);
    $widget->set_settings('wp_gallery',$pictures);
    $widget->set_settings('gallery_rand','');
    $widget->set_settings('gallery_style','masonry');
    $widget->set_settings('masonry_filters_enable','yes');
    $widget->set_settings('filters_all_text',$locale['all']);
    $widget->set_settings('click_action','lightbox');
    if(!in_array((string)($settings['image_size']??''),['medium','medium_large','large'],true))
       $widget->set_settings('image_size','medium_large');
    if(method_exists($widget,'reset_render_state'))$widget->reset_render_state();
    $aspect=(string)($settings['mad4b_uael_ratio']??'4-3');
    if(!in_array($aspect,['original','1-1','4-3','16-9','3-4'],true))$aspect='4-3';
    $widget->add_render_attribute('_wrapper','data-mad4b-uael-story-gallery','1');
    $widget->add_render_attribute('_wrapper','data-mad4b-uael-ratio',$aspect);
    self::$widget_meta=[
       'post_id'=>$tour,'widget_id'=>$widget->get_id(),'images'=>count($pictures),
       'sections'=>count($sections),'ratio'=>$aspect,
       'defer'=>($settings['mad4b_uael_defer']??'yes')==='yes',
       'timeline'=>($settings['mad4b_uael_timeline']??'yes')==='yes',
       'timeline_plan'=>$timeline_plan,
    ];
 }
 public static function category_meta($value,$post_id,$key,$single,$meta_type){
    if(self::$widget_scope===null ||$key!=='uael-categories' ||!isset(self::$categories[(int)$post_id]))return $value;
    $label=self::$categories[(int)$post_id];
    return $single?$label:[$label];
 }
 public static function native_tabs($tabs){
    if(self::$widget_scope===null)return $tabs;
    $ordered=[];
    foreach(self::$tab_labels as $slug=>$label)if(array_key_exists($slug,(array)$tabs))$ordered[$slug]=$label;
    return $ordered?:$tabs;
 }
 public static function rendered($html,$widget,$args=[]){
    if(self::$widget_scope===null||!self::is_target($widget))return $html;
    // This HTML is produced by the native UAEL widget. The <template>
    // is inert until the Elementor popup opens; no duplicate gallery engine.
    $meta=self::$widget_meta;
    $wrapper='<div class="mad4b-uael-tour-gallery-host" data-mad4b-uael-tour="1"';
    $wrapper.=' data-mad4b-aspect="'.esc_attr($meta['ratio']).'"';
    $wrapper.=' data-mad4b-tour-id="'.(int)$meta['post_id'].'"';
    $wrapper.=' data-mad4b-count="'.(int)$meta['images'].'"';
    if(!empty($meta['timeline'])&&!empty($meta['timeline_plan']['days'])){
        $manifest=wp_json_encode($meta['timeline_plan'],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $wrapper.=' data-mad4b-timeline="'.esc_attr($manifest).'"';
    }
    $wrapper.='>';
    $server_fetch=class_exists('MAD4B_UAEL_Server_Defer_V1',false)
       &&MAD4B_UAEL_Server_Defer_V1::in_deferred_render();
    if($meta['defer']&&!$server_fetch){
       $html=$wrapper.'<template data-mad4b-uael-native-template="1">'.$html.'</template></div>';
    }else $html=$wrapper.$html.'</div>';
    return $html;
 }
 public static function after($widget):void {
    if(!self::is_target($widget))return;
    self::$categories=[];self::$tab_labels=[];self::$widget_scope=null;self::$widget_meta=[];
 }
 public static function enqueue():void {
    if(!self::enabled())return;
    $base=content_url('mu-plugins/assets/');
    wp_enqueue_style('mad4b-uael-tour-addon',$base.'mad4b-uael-tour-addon-v2-6-20261112.css',[],'0.2.6');
    wp_enqueue_script('mad4b-uael-tour-addon',$base.'mad4b-uael-tour-addon-v2-8-20261112.js',['jquery'],'0.2.8',true);
 }
}
add_action('elementor/element/uael-image-gallery/section_content_images/after_section_end',
 [MAD4B_UAEL_Tour_Gallery_Addon_V1::class,'controls'],12,1);
add_action('elementor/frontend/widget/before_render',
 [MAD4B_UAEL_Tour_Gallery_Addon_V1::class,'before'],9,1);
add_filter('get_post_metadata',[MAD4B_UAEL_Tour_Gallery_Addon_V1::class,'category_meta'],12,5);
add_filter('uael_image_gallery_tabs',[MAD4B_UAEL_Tour_Gallery_Addon_V1::class,'native_tabs'],22,1);
add_filter('elementor/widget/render_content',
 [MAD4B_UAEL_Tour_Gallery_Addon_V1::class,'rendered'],23,3);
add_action('elementor/frontend/widget/after_render',
 [MAD4B_UAEL_Tour_Gallery_Addon_V1::class,'after'],999,1);
add_action('wp_enqueue_scripts',[MAD4B_UAEL_Tour_Gallery_Addon_V1::class,'enqueue'],65);
