<?php
/**
 * Plugin Name: MAD4B Tourism Nile Content Hub Catalog
 * Version: 0.1.0
 * Staging-only published, owner-pinned, company-controlled source catalog.
 * Native WP terms reused, no shared media, no commercial rates.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tourism_Nile_Catalog_V1 {
 const CONTRACT='mad4b.tourism.public-content-hub-catalog.v1';
 const OWNER='tourism-almallahgroup';
 const HOST='tours.dev.mad4b.com';
 const CPT='mad4b_hub_nile';
 const PAGE_OPT='mad4b_tourism_nile_catalog_pages_v1';
 const SHORTCODE='mad4b_tourism_nile_catalog';
 public static function enabled(): bool {
  return wp_get_environment_type()==='staging'
   && strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))===self::HOST
   && class_exists('MAD4B_Hub_Nile_Publisher_V1');
 }
 private static function read_records(string $kind): array {
  $ids=get_posts([
    'post_type'=>self::CPT,'post_status'=>'publish','numberposts'=>100,
    'fields'=>'ids','suppress_filters'=>true,
    'meta_query'=>[
      ['key'=>'_mad4b_hub_source_company','value'=>self::OWNER],
      ['key'=>'_mad4b_hub_source_kind','value'=>$kind],
    ],
  ]);
  $all=[];
  foreach($ids as $id){
    $id=(int)$id;
    if((string)get_post_meta($id,'_mad4b_hub_bookable',true)!=='no' ||
       (string)get_post_meta($id,'_mad4b_hub_commerce_approved',true)!=='no')return [];
    $raw=(string)get_post_meta($id,'_mad4b_hub_source_payload_json',true);
    if($raw===''||strlen($raw)>100000)return [];
    $item=json_decode($raw,true);
    if(!is_array($item)||($item['source_company']??'')!==self::OWNER)return [];
    $digest=hash('sha256',wp_json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    if(!hash_equals((string)get_post_meta($id,'_mad4b_hub_source_sha256',true),$digest))return [];
    $idKey=$kind==='nile_category'?'source_term_id':'source_tour_id';
    $sourceId=(int)($item[$idKey]??0);
    if($sourceId<1||isset($all[$sourceId]))return [];
    if($kind==='nile_category'){
      if(($item['taxonomy']??'')!=='tour-types_jet' ||
         !in_array($item['source_language_code']??null,['en','ar'],true) ||
         (string)get_post_meta($id,'_mad4b_hub_target_taxonomy',true)!=='tour-types' ||
         (int)get_post_meta($id,'_mad4b_hub_target_term_id',true)<1)return [];
      $item['_native_target_id']=(int)get_post_meta($id,'_mad4b_hub_target_term_id',true);
    }else{
      if(($item['import_status']??'')!=='reference_only' ||
         ($item['publish_allowed']??true)!==false ||
         !in_array($item['translation_source']['language_code']??null,['en','ar'],true))return [];
    }
    $all[$sourceId]=$item;
  }
  return $all;
 }
 private static function catalog(): array {
  $categories=self::read_records('nile_category');
  $tours=self::read_records('nile_tour');
  if(count($categories)!==18 || count($tours)!==35)return [];
  return ['categories'=>$categories,'tours'=>$tours];
 }
 private static function lang(array $attrs): string {
  $forced=(string)($attrs['lang']??'');
  if(in_array($forced,['en','ar'],true))return $forced;
  $wpml=apply_filters('wpml_current_language',null);
  return $wpml==='ar'?'ar':'en';
 }
 private static function browse_url(string $language): string {
  $ids=get_option(self::PAGE_OPT,[]);
  $pageId=(int)($ids[$language]??0);
  $page=get_post($pageId);
  if(!$page || $page->post_status!=='publish' || $page->post_type!=='page')return '';
  $url=get_permalink($pageId);
  if(!is_string($url))return '';
  $translated=apply_filters('wpml_permalink',$url,$language,true);
  $url=is_string($translated)&&$translated!==''?$translated:$url;
  return strtolower((string)wp_parse_url($url,PHP_URL_HOST))===self::HOST?$url:'';
 }
 private static function href(string $url,array $params): string {
  if(!$url)return '#';
  return esc_url(add_query_arg($params,$url));
 }
 /**
  * Nested category links inside the EXISTING Elementor Home Tours template.
  * Source→canonical term mappings are already certified by the admission gate.
  * These are navigation links, NOT fake JetSmartFilters post query results.
  */
 private static function render_home_subcategories(array $cats,array $tours,string $lang,string $browse): string {
  $arabic=$lang==='ar';
  $roots=array_values(array_filter($cats,static fn($c)=>(int)($c['parent_source_term_id']??-1)===0));
  if(count($roots)!==1)return '';
  $rootId=(int)$roots[0]['source_term_id'];
  $children=array_values(array_filter($cats,static fn($c)=>(int)($c['parent_source_term_id']??-1)===$rootId));
  if(count($children)!==8)return '';
  usort($children,static fn($a,$b)=>strcmp((string)$a['name'],(string)$b['name']));
  ob_start(); ?>
  <section class="mad4b-nile-subcats" id="mad4b-nile-home-subcategories"
    lang="<?php echo esc_attr($lang); ?>" dir="<?php echo $arabic?'rtl':'ltr'; ?>"
    data-source-owner="<?php echo esc_attr(self::OWNER); ?>"
    data-canonical-taxonomy="tour-types" aria-labelledby="mad4b-nile-home-subcats-title">
    <style>
      .mad4b-nile-subcats{font-family:inherit;color:var(--e-global-color-text,var(--ast-global-color-3,inherit));padding:22px 0 14px;max-width:100%;box-sizing:border-box}
      .mad4b-nile-subcats *{box-sizing:border-box}
      .mad4b-nile-subcats h3{font-size:clamp(1.15rem,2vw,1.6rem);line-height:1.35;font-weight:700;margin:0 0 8px;color:var(--e-global-color-primary,var(--ast-global-color-2,inherit))}
      .mad4b-nile-subcats .mad4b-nile-subcats-intro{margin:0 0 16px;font-size:.94rem;line-height:1.6}
      .mad4b-nile-subcats-list{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
      .mad4b-nile-subcats-list a{display:flex;flex-direction:column;justify-content:center;gap:5px;min-height:74px;border-radius:10px;border:1px solid var(--ast-global-color-7,#ddd);background:var(--ast-global-color-5,#fff);padding:12px 14px;text-decoration:none!important;color:inherit!important;transition:border-color .15s,transform .15s}
      .mad4b-nile-subcats-list a:hover,.mad4b-nile-subcats-list a:focus-visible{border-color:var(--e-global-color-primary,var(--ast-global-color-0,#555));transform:translateY(-1px)}
      .mad4b-nile-subcats-list strong{font-size:.93rem;line-height:1.4}
      .mad4b-nile-subcats-list small{font-size:.78rem;opacity:.74}
      .mad4b-nile-subcats-end{margin:14px 0 0}
      .mad4b-nile-subcats-end a{color:var(--e-global-color-primary,var(--ast-global-color-0,inherit));font-weight:650;text-decoration:underline;text-underline-offset:3px}
      @media(max-width:900px){.mad4b-nile-subcats-list{grid-template-columns:repeat(3,minmax(0,1fr))}}
      @media(max-width:600px){.mad4b-nile-subcats{padding:18px 0 10px}.mad4b-nile-subcats-list{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.mad4b-nile-subcats-list a{min-height:75px;padding:11px}}
      .mad4b-nile-subcats a:focus-visible{outline:2px solid var(--e-global-color-primary,var(--ast-global-color-0,#333));outline-offset:3px}
    </style>
    <h3 id="mad4b-nile-home-subcats-title"><?php echo esc_html($arabic?'أنواع الرحلات النيلية':'Nile Cruise Categories'); ?></h3>
    <p class="mad4b-nile-subcats-intro"><?php echo esc_html($arabic?'استكشف تصنيفات المراكب وبرامجها من مصدر الشركة.':'Browse Nile cruise styles and itineraries from the company catalog.'); ?></p>
    <nav aria-label="<?php echo esc_attr($arabic?'التصنيفات الفرعية للرحلات النيلية':'Nile Cruises subcategories'); ?>">
      <ul class="mad4b-nile-subcats-list">
        <?php foreach($children as $child):
          $sourceId=(int)$child['source_term_id'];
          $count=0;foreach($tours as $tour){
            $ids=array_map('intval',(array)($tour['source_taxonomy_term_ids']??[]));
            if(in_array($sourceId,$ids,true))$count++;
          }
          $url=self::href($browse,['nile_category'=>(string)$child['slug']]);
        ?>
        <li><a href="<?php echo esc_url($url); ?>" data-canonical-term-id="<?php echo esc_attr((string)(int)$child['_native_target_id']); ?>">
            <strong><?php echo esc_html((string)$child['name']); ?></strong>
            <small><?php echo esc_html(sprintf($arabic?'%d رحلة':'%d cruises',$count)); ?></small>
        </a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <p class="mad4b-nile-subcats-end"><a href="<?php echo esc_url($browse); ?>"><?php echo esc_html($arabic?'عرض جميع الرحلات النيلية':'See all Nile Cruises'); ?></a></p>
  </section>
  <?php return (string)ob_get_clean();
 }
 private static function detail_html(array $tour,bool $arabic,string $browse): string {
  $title=(string)$tour['title'];
  $summary=(string)($tour['summary']??'');
  $text=(string)($tour['source_content_text']??'');
  $runs=$tour['program_reference_snapshot']['cruise_run_variants']??[];
  $itinerary=$tour['program_reference_snapshot']['itinerary_days']??[];
  ob_start(); ?>
  <article class="mad4b-ship-detail">
    <p class="mad4b-ship-kicker"><?php echo esc_html($arabic?'محتوى الرحلة من مصدر الشركة':'Tour information from the company source'); ?></p>
    <h2><?php echo esc_html($title); ?></h2>
    <p><?php echo esc_html($summary); ?></p>
    <?php if($text && trim($text)!==trim($summary)): ?><p><?php echo esc_html(wp_trim_words($text,180)); ?></p><?php endif; ?>
    <?php if(is_array($runs) && $runs): ?>
      <h3><?php echo esc_html($arabic?'برامج الإبحار':'Cruise itineraries'); ?></h3>
      <div class="mad4b-ship-grid">
       <?php foreach(array_slice($runs,0,12) as $run): ?>
       <div class="mad4b-ship-card">
         <strong><?php echo esc_html((string)($run['program_label']??'')); ?></strong>
         <p><?php echo esc_html(implode(' · ',array_filter([(string)($run['origin_label']??''),(string)($run['day_label']??''),(string)($run['time_label']??'')]))); ?></p>
       </div>
       <?php endforeach; ?>
      </div>
    <?php elseif(is_array($itinerary) && $itinerary): ?>
      <h3><?php echo esc_html($arabic?'برنامج الرحلة':'Itinerary'); ?></h3>
      <div class="mad4b-ship-itinerary">
       <?php foreach(array_slice($itinerary,0,25) as $day):if(!is_array($day))continue; ?>
        <details><summary><?php echo esc_html(trim((string)($day['day_label']??'').' '.(string)($day['title']??''))); ?></summary>
         <p><?php echo esc_html((string)($day['description_text']??'')); ?></p></details>
       <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="mad4b-ship-disclaimer"><?php echo esc_html($arabic?'الأسعار التاريخية لا تمثل عرض بيع أو حجزًا متاحًا.':'Historical prices are not current offers. Booking is unavailable.'); ?></p>
    <p><a class="mad4b-ship-link" href="<?php echo esc_url($browse); ?>"><?php echo esc_html($arabic?'العودة إلى جميع المراكب':'View all Nile cruises'); ?></a></p>
  </article>
  <?php return (string)ob_get_clean();
 }
 public static function render($atts=[]): string {
  if(!self::enabled())return '';
  $atts=shortcode_atts(['lang'=>'','mode'=>'browse'],$atts,self::SHORTCODE);
  $lang=self::lang($atts);$arabic=$lang==='ar';
  $mode=in_array($atts['mode'],['home','browse','home_subcategories'],true)?$atts['mode']:'browse';
  $catalog=self::catalog();
  if(!$catalog)return '<p role="status">'.esc_html($arabic?'محتوى المراكب غير متاح مؤقتًا':'Nile source catalog temporarily unavailable').'</p>';
  $categories=array_values(array_filter($catalog['categories'],static fn($c)=>($c['source_language_code']??'')===$lang));
  $tours=array_values(array_filter($catalog['tours'],static fn($t)=>($t['translation_source']['language_code']??'')===$lang));
  if(count($categories)!==9||!$tours)return '';
  $browse=self::browse_url($lang);
  if(!$browse)return '';
  $root=0;
  foreach($categories as $item)if((int)$item['parent_source_term_id']===0)$root=(int)$item['source_term_id'];
  if(!$root)return '';
  if($mode==='home_subcategories')return self::render_home_subcategories($categories,$tours,$lang,$browse);
  $catKeys=[];foreach($categories as $item)$catKeys[(int)$item['source_term_id']]=$item;
  $selected='';
  if($mode==='browse' && isset($_GET['nile_category']) && is_string($_GET['nile_category'])){
    $requested=sanitize_title(wp_unslash($_GET['nile_category']));
    foreach($categories as $item)if($item['slug']===$requested){$selected=$requested;break;}
  }
  $tourParam=0;
  if($mode==='browse' && isset($_GET['nile_tour']) && is_scalar($_GET['nile_tour'])){
    $tourParam=(int)filter_var((string)$_GET['nile_tour'],FILTER_VALIDATE_INT);
  }
  $detail=null;
  if($mode==='browse' && $tourParam>0)foreach($tours as $t)if((int)$t['source_tour_id']===$tourParam)$detail=$t;
  $allCount=count($tours);
  $name=$arabic?'الرحلات النيلية':'Nile Cruises';
  $subtitle=$arabic?'استكشف أنواع المراكب والبرامج النيلية من محتوى الشركة المعتمد.':
   'Explore Nile cruise styles and itineraries from the verified company catalog.';
  usort($categories,static function($a,$b) use($root){
    if((int)$a['source_term_id']===$root)return -1;
    if((int)$b['source_term_id']===$root)return 1;
    return strcmp((string)$a['name'],(string)$b['name']);
  });
  $display=[];
  foreach($tours as $tour){
    $tax=array_map('intval',(array)($tour['source_taxonomy_term_ids']??[]));
    if($selected){
      $target=null;foreach($categories as $c)if($c['slug']===$selected)$target=$c;
      if(!$target || !in_array((int)$target['source_term_id'],$tax,true))continue;
    }
    $display[]=$tour;
  }
  usort($display,static fn($a,$b)=>strcmp((string)$a['title'],(string)$b['title']));
  if($mode==='home')$display=array_slice($display,0,6);
  $sectionId=$mode==='home'?'mad4b-nile-home':'mad4b-nile-browse';
  ob_start();?>
  <style>
  .mad4b-nile-catalog{color:var(--e-global-color-text,var(--ast-global-color-3,inherit));font-family:inherit;margin:0 auto;max-width:1320px;padding:32px 20px;box-sizing:border-box}
  .mad4b-nile-catalog *{box-sizing:border-box}
  .mad4b-nile-catalog h2{font-size:clamp(1.6rem,2.3vw,2.3rem);line-height:1.3;font-weight:700;margin:0 0 10px;color:var(--e-global-color-primary,var(--ast-global-color-2,inherit))}
  .mad4b-nile-catalog h3{font-size:1.12rem;font-weight:700;line-height:1.5;color:var(--e-global-color-primary,var(--ast-global-color-2,inherit));margin:0}
  .mad4b-nile-catalog p{line-height:1.65}
  .mad4b-nile-catalog .mad4b-ship-intro{max-width:780px;margin:0 0 22px}
  .mad4b-ship-cats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 28px;padding:0;list-style:none}
  .mad4b-ship-cat{height:100%;text-decoration:none!important;display:flex;flex-direction:column;gap:5px;background:var(--ast-global-color-5,#fff);border:1px solid var(--ast-global-color-7,#ddd);border-radius:9px;padding:14px 16px;color:inherit!important;transition:border-color .15s,transform .15s}
  .mad4b-ship-cat:hover,.mad4b-ship-cat:focus-visible{border-color:var(--e-global-color-primary,var(--ast-global-color-0,#666));transform:translateY(-1px)}
  .mad4b-ship-cat[aria-current="true"]{border-width:2px;border-color:var(--e-global-color-primary,var(--ast-global-color-0,#666))}
  .mad4b-ship-cat strong{font-size:.96rem;line-height:1.4}.mad4b-ship-cat span{font-size:.81rem;opacity:.75}
  .mad4b-ship-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
  .mad4b-ship-card{border:1px solid var(--ast-global-color-7,#ddd);border-radius:10px;background:var(--ast-global-color-5,#fff);padding:18px;min-width:0}
  .mad4b-ship-card h3{margin:0 0 10px}.mad4b-ship-card p{font-size:.94rem;margin:0 0 14px}
  .mad4b-ship-link{font-weight:700;color:var(--e-global-color-primary,var(--ast-global-color-0,inherit));text-decoration:underline;text-underline-offset:3px}
  .mad4b-ship-disclaimer{font-size:.85rem;opacity:.82;margin:24px 0 0}
  .mad4b-ship-itinerary details{border-bottom:1px solid var(--ast-global-color-7,#ddd);padding:10px 0}.mad4b-ship-itinerary summary{cursor:pointer;font-weight:600}
  .mad4b-ship-detail{border-top:1px solid var(--ast-global-color-7,#ddd);padding-top:24px}.mad4b-ship-kicker{font-size:.85rem;opacity:.7}
  .mad4b-nile-catalog :focus-visible{outline:2px solid var(--e-global-color-primary,var(--ast-global-color-0,#333));outline-offset:3px}
  @media(max-width:1000px){.mad4b-ship-cats{grid-template-columns:repeat(3,minmax(0,1fr))}.mad4b-ship-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:600px){.mad4b-nile-catalog{padding:22px 14px}.mad4b-ship-cats{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.mad4b-ship-cat{padding:12px;min-height:80px}.mad4b-ship-grid{grid-template-columns:1fr}}
  </style>
  <section class="mad4b-nile-catalog" id="<?php echo esc_attr($sectionId); ?>" lang="<?php echo esc_attr($lang); ?>" dir="<?php echo $arabic?'rtl':'ltr'; ?>" data-source-owner="<?php echo esc_attr(self::OWNER); ?>">
    <header><h2><?php echo esc_html($name); ?></h2><p class="mad4b-ship-intro"><?php echo esc_html($subtitle); ?></p></header>
    <nav aria-label="<?php echo esc_attr($arabic?'تصنيفات المراكب النيلية':'Nile cruise categories'); ?>">
    <ul class="mad4b-ship-cats">
    <?php foreach($categories as $cat):
       $termId=(int)$cat['source_term_id'];
       $isRoot=$termId===$root;
       $count=$isRoot?$allCount:0;
       if(!$isRoot)foreach($tours as $tour)if(in_array($termId,array_map('intval',(array)($tour['source_taxonomy_term_ids']??[])),true))$count++;
       $url=$isRoot?$browse:self::href($browse,['nile_category'=>(string)$cat['slug']]);
       $active=$mode==='browse' && ($isRoot?$selected==='':$selected===$cat['slug']);
    ?>
      <li><a class="mad4b-ship-cat" href="<?php echo esc_url($url); ?>"<?php if($active): ?> aria-current="true"<?php endif; ?>>
        <strong><?php echo esc_html($cat['name']); ?></strong>
        <span><?php echo esc_html(sprintf($arabic?'%d رحلة':'%d cruises',$count)); ?></span>
      </a></li>
    <?php endforeach; ?>
    </ul></nav>
    <?php if($detail): ?>
      <?php echo self::detail_html($detail,$arabic,$browse); ?>
    <?php else: ?>
      <div class="mad4b-ship-grid" id="mad4b-nile-tours">
      <?php foreach($display as $tour):
        $tourId=(int)$tour['source_tour_id'];
        $title=(string)$tour['title'];
        $summary=(string)($tour['summary']??'');
        $link=self::href($browse,['nile_tour'=>$tourId]);
      ?>
        <article class="mad4b-ship-card" data-tour-source="<?php echo esc_attr((string)$tourId); ?>">
          <h3><?php echo esc_html($title); ?></h3>
          <p><?php echo esc_html(wp_trim_words(wp_strip_all_tags($summary),28)); ?></p>
          <a class="mad4b-ship-link" href="<?php echo esc_url($link); ?>"><?php echo esc_html($arabic?'تفاصيل الرحلة':'View itinerary'); ?></a>
        </article>
      <?php endforeach; ?>
      </div>
      <?php if(!$display): ?><p role="status"><?php echo esc_html($arabic?'لا توجد رحلات متاحة ضمن هذا التصنيف حاليًا':'No published cruises in this category yet.'); ?></p><?php endif; ?>
      <?php if($mode==='home'): ?><p><a class="mad4b-ship-link" href="<?php echo esc_url($browse); ?>"><?php echo esc_html($arabic?'عرض جميع المراكب والتصنيفات':'Explore all Nile cruises and categories'); ?></a></p><?php endif; ?>
      <p class="mad4b-ship-disclaimer"><?php echo esc_html($arabic?'بيانات المحتوى من موقع Tourism. الأسعار التاريخية ليست عروض بيع أو حجز.':'Content from the Tourism company catalog. Historic rates are not live offers or bookable prices.'); ?></p>
    <?php endif; ?>
  </section>
  <?php return (string)ob_get_clean();
 }
 public static function boot(): void {
  if(!self::enabled())return;
  add_shortcode(self::SHORTCODE,[__CLASS__,'render']);
 }
}
add_action('init',['MAD4B_Tourism_Nile_Catalog_V1','boot'],27);
