<?php
/**
 * Plugin Name: MAD4B Licensed Image Attribution Registry
 * Description: Company-scoped, accessible license attributions on a dedicated page; never prints a global footer banner.
 * Version: 1.9.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Media_Attribution_Registry_V1', false)) {
final class MAD4B_Media_Attribution_Registry_V1 {
 const OPTION = 'mad4b_licensed_media_credits_v1'; // Read-only legacy backup; never append new records.
 const CCT = 'media_rights';
 const PAGE_SIZE = 12;
 private static function t(string $lang,string $key): string {
  static $labels=[
   'en'=>['title'=>'Image Attributions & Licenses','eyebrow'=>'LICENSED PHOTOGRAPH','byline'=>'Photograph by ','original'=>'View original','license'=>'License details','intro_title'=>'Photography credits','intro'=>'We credit the original creators of externally sourced photography. Each image includes its attribution and licensing information.','count'=>'credited photographs','note_title'=>'About image licensing','note'=>'Original creators retain their rights. Creative Commons licenses may require attribution and share-alike treatment for adaptations. Review the individual license before reusing an image.','prev'=>'Previous','next'=>'Next','nav'=>'Credit pages','footer'=>'Image credits & licenses'],
   'es'=>['title'=>'Créditos de imágenes y licencias','eyebrow'=>'FOTOGRAFÍA CON LICENCIA','byline'=>'Fotografía de ','original'=>'Ver original','license'=>'Ver licencia','intro_title'=>'Créditos de fotografía','intro'=>'Agradecemos a los autores originales. Las atribuciones y condiciones de licencia se muestran con cada imagen.','count'=>'imágenes acreditadas','note_title'=>'Sobre los derechos de imagen','note'=>'Los autores conservan sus derechos. Consulte la licencia individual antes de reutilizar una imagen.','prev'=>'Anterior','next'=>'Siguiente','nav'=>'Páginas de créditos','footer'=>'Créditos de imágenes'],
   'fr'=>['title'=>'Crédits photographiques et licences','eyebrow'=>'PHOTOGRAPHIE SOUS LICENCE','byline'=>'Photo de ','original'=>'Voir l’original','license'=>'Détails de la licence','intro_title'=>'Crédits photographiques','intro'=>'Nous mentionnons les créateurs des photographies provenant de sources externes et les conditions de leurs licences.','count'=>'photographies créditées','note_title'=>'À propos des licences','note'=>'Les auteurs conservent leurs droits. Vérifiez les conditions de chaque licence avant toute réutilisation.','prev'=>'Précédent','next'=>'Suivant','nav'=>'Pages des crédits','footer'=>'Crédits photos et licences'],
   'de'=>['title'=>'Bildnachweise und Lizenzen','eyebrow'=>'LIZENZIERTES FOTO','byline'=>'Foto von ','original'=>'Original ansehen','license'=>'Lizenzdetails','intro_title'=>'Bildnachweise','intro'=>'Wir nennen die Urheber extern bezogener Fotografien und die jeweiligen Lizenzbedingungen.','count'=>'nachgewiesene Fotografien','note_title'=>'Zu den Bildlizenzen','note'=>'Die Urheber behalten ihre Rechte. Bitte prüfen Sie die jeweiligen Lizenzbedingungen vor einer Wiederverwendung.','prev'=>'Zurück','next'=>'Weiter','nav'=>'Bildnachweis-Seiten','footer'=>'Bildnachweise und Lizenzen'],
   'it'=>['title'=>'Crediti fotografici e licenze','eyebrow'=>'FOTOGRAFIA CON LICENZA','byline'=>'Foto di ','original'=>'Vedi l’originale','license'=>'Dettagli licenza','intro_title'=>'Crediti fotografici','intro'=>'Riconosciamo gli autori delle fotografie provenienti da fonti esterne e le relative licenze.','count'=>'fotografie accreditate','note_title'=>'Informazioni sulle licenze','note'=>'Gli autori conservano i propri diritti. Verifica i termini delle singole licenze prima di riutilizzare le immagini.','prev'=>'Precedente','next'=>'Successivo','nav'=>'Pagine dei crediti','footer'=>'Crediti fotografici e licenze'],
   'ar'=>['title'=>'نسب الصور والتراخيص','eyebrow'=>'صورة مرخّصة','byline'=>'تصوير: ','original'=>'عرض المصدر الأصلي','license'=>'تفاصيل الترخيص','intro_title'=>'حقوق ونسب الصور','intro'=>'نوضح أسماء أصحاب الصور المستخدمة من مصادر خارجية وروابط المصادر وتفاصيل تراخيصها.','count'=>'صورة موثقة الحقوق','note_title'=>'حول تراخيص الصور','note'=>'يحتفظ أصحاب الصور بحقوقهم. يُرجى مراجعة شروط كل ترخيص قبل إعادة استخدام الصورة.','prev'=>'السابق','next'=>'التالي','nav'=>'صفحات حقوق الصور','footer'=>'حقوق الصور والتراخيص'],
  ];
  return $labels[$lang][$key]??$labels['en'][$key]??$key;
 }

 public static function footer_label(string $lang): string {return self::t($lang,'footer');}
 public static function invalidate_public_pages(): void {
  // Invalidate only the registered WPML rights archive pages, not global cache.
  // WPML resolves translated permalinks only in their own language context.
  $base = get_page_by_path('image-attribution', OBJECT, 'page');
  if (!$base || get_post_status($base->ID) !== 'publish') return;
  $previous = apply_filters('wpml_current_language', null);
  try {
   $available = apply_filters('wpml_active_languages', null, ['skip_missing'=>0]);
   $langs = is_array($available) && $available ? array_keys($available) : ['en'];
   foreach (array_slice($langs, 0, 12) as $lang) {
    if (has_action('wpml_switch_language')) do_action('wpml_switch_language', $lang);
    $pid = apply_filters('wpml_object_id', $base->ID, 'page', false, $lang);
    if (!$pid || get_post_status((int)$pid) !== 'publish') continue;
    clean_post_cache((int)$pid);
    // Elementor stores rendered Shortcode output per library template. Clear
    // only the template attached to this translated rights page, otherwise
    // newly inserted CCT credits can remain invisible despite page-cache purge.
    $page_content = (string) get_post_field('post_content', (int)$pid);
    if (preg_match('/\[elementor-template\s+id=[\"\']?(\d+)/', $page_content, $template_match)) {
     $template_id = absint($template_match[1]);
     if ($template_id && get_post_type($template_id) === 'elementor_library') {
      delete_post_meta($template_id, '_elementor_element_cache');
     }
    }
    $url = get_permalink((int)$pid);
    if (is_string($url) && str_starts_with($url, home_url('/'))) {
     do_action('litespeed_purge_url', $url);
    }
   }
  } finally {
   if (has_action('wpml_switch_language') && is_string($previous) && $previous !== '') {
    do_action('wpml_switch_language', $previous);
   }
  }
 }
 private static function factory() {
  if (!class_exists('Jet_Engine\\Modules\\Custom_Content_Types\\Module', false)) return false;
  $manager = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager;
  return $manager ? $manager->get_content_types(self::CCT) : false;
 }
 public static function credit_record(string $key): ?array {
  $factory = self::factory();
  if (!$factory || !preg_match('/^[a-zA-Z0-9_:-]{4,120}$/D', $key)) return null;
  $factory->db->set_format_flag(ARRAY_A);
  $rows = $factory->db->query(['credit_key' => $key], 2);
  if (count($rows) > 1) throw new RuntimeException('MEDIA_RIGHTS_DUPLICATE_KEY');
  return $rows[0] ?? null;
 }
 public static function upsert_credit(string $key, array $row): bool {
  $factory = self::factory();
  if (!$factory || !preg_match('/^[a-zA-Z0-9_:-]{4,120}$/D', $key)) return false;
  $required=['subject','author','source_url','license_url','license_label'];
  foreach ($required as $field) if (empty($row[$field]) || !is_string($row[$field])) return false;
  foreach (['source_url','license_url'] as $field) {
   $url=wp_parse_url($row[$field]);
   if (!is_array($url) || ($url['scheme']??'')!=='https' || empty($url['host'])) return false;
  }
  $fields=['credit_key'=>$key,'subject'=>sanitize_text_field($row['subject']),'author'=>sanitize_text_field($row['author']),
   'source_url'=>esc_url_raw($row['source_url']),'license_url'=>esc_url_raw($row['license_url']),
   'license_label'=>sanitize_text_field($row['license_label']),
   'source_sha256'=>sanitize_text_field((string)($row['source_sha256']??'')),
   'attachment_id'=>absint($row['attachment_id']??0),'cct_status'=>'publish'];
  $existing=self::credit_record($key);
  if ($existing) $factory->db->update($fields,['_ID'=>(int)$existing['_ID']]);
  else if (!$factory->db->insert($fields)) return false;
  $saved=self::credit_record($key);
  if (!$saved) return false;
  foreach ($fields as $field=>$value) if ((string)($saved[$field]??'')!==(string)$value) return false;
  self::invalidate_public_pages();
  return true;
 }
 public static function restore_credit(string $key, ?array $original): bool {
  $factory=self::factory();if (!$factory) return false;
  $current=self::credit_record($key);
  if (!$original) {
   if ($current) $factory->db->delete(['_ID'=>(int)$current['_ID']]);
   $removed = self::credit_record($key)===null;
   if ($removed) self::invalidate_public_pages();
   return $removed;
  }
  return self::upsert_credit($key,$original);
 }
 public static function render(): string {
  // Bounded, server-side pagination against JetEngine's native CCT table.
  $factory=self::factory();
  if (!$factory) return '<p class="wayfa-credits-unavailable">Media credits are temporarily unavailable.</p>';
  global $wpdb;
  $table=$factory->db->table();
  $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE cct_status=%s",'publish'));
  $pages=max(1,(int)ceil($total/self::PAGE_SIZE));
  $page=min($pages,max(1,absint($_GET['credits_page']??1)));
  $factory->db->set_format_flag(ARRAY_A);
  $records=$factory->db->query(['cct_status'=>'publish'],self::PAGE_SIZE,($page-1)*self::PAGE_SIZE);
  if (!is_array($records)) $records=[];
  $lang = (string) apply_filters('wpml_current_language', 'en');
  $es = $lang === 'es';
  $title = self::t($lang,'title');
  $subtitle = $es
   ? 'Reconocemos a los fotógrafos y creadores cuyas imágenes enriquecen nuestras historias de viaje.'
   : 'Recognizing the photographers and creators behind the imagery that brings our journeys to life.';
  $cards = [];
  foreach ($records as $item) {
   if (!is_array($item) || empty($item['author']) || empty($item['source_url']) || empty($item['license_url'])) continue;
   $urls = [];
   foreach (['source_url', 'license_url'] as $key) {
    $candidate = wp_parse_url((string) $item[$key]);
    if (!is_array($candidate) || ($candidate['scheme'] ?? '') !== 'https' || empty($candidate['host'])) continue 2;
    $urls[$key] = esc_url((string) $item[$key]);
   }
   $subject = esc_html((string) ($item['subject'] ?? 'Photograph'));
   $author = esc_html((string) $item['author']);
   $license = esc_html((string) ($item['license_label'] ?? 'Creative Commons'));
   $attachment = isset($item['attachment_id']) ? absint($item['attachment_id']) : 0;
   $image = '';
   if ($attachment && get_post_type($attachment) === 'attachment' && str_starts_with((string) get_post_mime_type($attachment), 'image/')) {
    $image = wp_get_attachment_image($attachment, 'large', false, [
     'class' => 'wayfa-credits-card-image', 'loading' => 'lazy', 'decoding' => 'async',
     'alt' => (string) ($item['subject'] ?? 'Licensed photograph'),
    ]);
   }
   $cards[] = '<article class="wayfa-credits-card">'
    . '<div class="wayfa-credits-image">' . ($image ?: '<div class="wayfa-credits-image-placeholder" aria-hidden="true"></div>') . '</div>'
    . '<div class="wayfa-credits-card-body">'
    . '<span class="wayfa-credits-eyebrow">' . self::t($lang,'eyebrow') . '</span>'
    . '<h2>' . $subject . '</h2>'
    . '<p class="wayfa-credits-byline">' . self::t($lang,'byline') . '<strong>' . $author . '</strong></p>'
    . '<div class="wayfa-credits-card-bottom"><span class="wayfa-credits-license">' . $license . '</span>'
    . '<div class="wayfa-credits-actions"><a href="' . $urls['source_url'] . '" target="_blank" rel="noopener noreferrer license">' . self::t($lang,'original') . ' <span aria-hidden="true">↗</span></a>'
    . '<a href="' . $urls['license_url'] . '" target="_blank" rel="noopener noreferrer license">' . self::t($lang,'license') . ' <span aria-hidden="true">↗</span></a></div></div></div></article>';
  }
  // Header, typography and layout are editable in native Elementor template.
  $markup = '<section class="wayfa-credits-page" dir="' . ($lang==='ar'?'rtl':'ltr') . '" aria-label="' . esc_attr($title) . '">'
   . '<div class="wayfa-credits-main"><div class="wayfa-credits-intro"><div><h2>' . self::t($lang,'intro_title') . '</h2>'
   . '<p>' . self::t($lang,'intro') . '</p></div>'
   . '<span class="wayfa-credits-count">' . $total . ' ' . self::t($lang,'count') . '</span></div>'
   . '<div class="wayfa-credits-grid">' . implode('', $cards) . '</div>'
   . self::pagination($page,$pages,$lang)
   . '<div class="wayfa-credits-note"><div class="wayfa-credits-note-icon" aria-hidden="true">ⓘ</div><div><strong>' . self::t($lang,'note_title') . '</strong><p>'
   . self::t($lang,'note')
   . '</p></div></div></div></section>';
  $css = <<<'CSS'
<style id="wayfa-credits-page-style">
.wayfa-credits-page{--wf-navy:#102f41;--wf-ink:#193649;--wf-gold:#c49b5e;--wf-muted:#617482;font-family:inherit;color:var(--wf-ink);width:100%;max-width:none;margin:0;background:#faf9f6}
.wayfa-credits-page *{box-sizing:border-box}
.wayfa-credits-hero{background:linear-gradient(110deg,#102f41 0%,#1c4658 100%);color:#fff;padding:78px 24px 82px;position:relative;overflow:hidden}
.wayfa-credits-hero:after{content:"";position:absolute;right:-90px;top:-130px;width:480px;height:480px;border:1px solid rgba(230,202,157,.15);border-radius:50%;box-shadow:0 0 0 75px rgba(230,202,157,.035),0 0 0 155px rgba(230,202,157,.025);pointer-events:none}
.wayfa-credits-inner,.wayfa-credits-main{max-width:1120px;margin:auto}
.wayfa-credits-kicker{font-size:12px;letter-spacing:.22em;font-weight:700;color:#e6c89d;margin-bottom:21px}
.wayfa-credits-kicker span{margin:0 12px;color:#8fa7b2}
.wayfa-credits-hero h1{font-size:clamp(36px,5vw,60px);line-height:1.1;letter-spacing:-.035em;color:#fff;margin:0 0 21px;font-weight:700}
.wayfa-credits-hero p{max-width:645px;font-size:17px;line-height:1.7;color:#d6e2e7;margin:0}
.wayfa-credits-main{padding:58px 24px 86px}
.wayfa-credits-intro{display:flex;align-items:end;justify-content:space-between;gap:24px;margin-bottom:29px}
.wayfa-credits-intro h2{font-size:27px;font-weight:700;color:var(--wf-navy);line-height:1.25;margin:0 0 11px}
.wayfa-credits-intro p{font-size:15px;line-height:1.7;color:var(--wf-muted);margin:0;max-width:710px}
.wayfa-credits-count{border:1px solid #e5dfd4;border-radius:30px;padding:9px 16px;color:#667681;font-size:12px;white-space:nowrap;background:white}
.wayfa-credits-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:26px}
.wayfa-credits-card{background:white;border:1px solid #e7e9e7;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(15,49,65,.045);transition:box-shadow .2s ease,transform .2s ease}
.wayfa-credits-card:hover{transform:translateY(-3px);box-shadow:0 15px 35px rgba(15,49,65,.11)}
.wayfa-credits-image{aspect-ratio:16/9;overflow:hidden;background:#e5e8e8}
.wayfa-credits-card-image{display:block;width:100%;height:100%;object-fit:cover}
.wayfa-credits-image-placeholder{width:100%;height:100%;background:linear-gradient(125deg,#c8d6d8,#eae5d9)}
.wayfa-credits-card-body{padding:28px 30px 29px}
.wayfa-credits-eyebrow{font-size:10px;font-weight:800;letter-spacing:.14em;color:#ac8751;display:block;margin-bottom:11px}
.wayfa-credits-card h2{font-size:24px;line-height:1.25;font-weight:700;color:var(--wf-navy);margin:0 0 10px}
.wayfa-credits-byline{color:#687985;font-size:14px;line-height:1.6;margin:0 0 25px}
.wayfa-credits-byline strong{color:#294b5a;font-weight:600}
.wayfa-credits-card-bottom{display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between;padding-top:20px;border-top:1px solid #eef0ee}
.wayfa-credits-license{display:inline-flex;border:1px solid #e5d8c1;background:#f9f5ec;color:#866337;font-weight:700;font-size:11px;letter-spacing:.03em;border-radius:5px;padding:8px 11px;white-space:nowrap}
.wayfa-credits-actions{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.wayfa-credits-actions a{color:#1a4e66;text-decoration:none;font-size:12px;font-weight:700;white-space:nowrap}
.wayfa-credits-actions a:hover{text-decoration:underline;color:#a67d45}
.wayfa-credits-actions a:focus-visible{outline:2px solid #c49b5e;outline-offset:4px}
.wayfa-credits-pagination{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:8px;margin-top:32px}.wayfa-credits-pagination a,.wayfa-credits-pagination span{min-width:40px;padding:10px 13px;text-align:center;text-decoration:none;border:1px solid #d6dedf;border-radius:7px;background:white;color:#183c4e}.wayfa-credits-pagination span[aria-current=page]{background:#173c50;color:white}.wayfa-credits-pagination a:hover{border-color:#b99c71}.wayfa-credits-note{margin-top:38px;background:#f0eee8;border:1px solid #e8e1d5;border-radius:12px;padding:23px 28px;display:flex;align-items:start;gap:16px}
.wayfa-credits-note-icon{font-size:22px;color:#9a784b;line-height:1.1}
.wayfa-credits-note strong{font-size:15px;color:#213f4c}
.wayfa-credits-note p{font-size:13px;line-height:1.75;color:#586e78;margin:7px 0 0}
@media(max-width:760px){.wayfa-credits-hero{padding:56px 20px 58px}.wayfa-credits-hero p{font-size:15px}.wayfa-credits-main{padding:36px 17px 54px}.wayfa-credits-intro{display:block}.wayfa-credits-count{display:inline-block;margin-top:17px}.wayfa-credits-grid{grid-template-columns:1fr;gap:20px}.wayfa-credits-card-body{padding:23px 22px}.wayfa-credits-card h2{font-size:21px}.wayfa-credits-note{padding:19px 18px}.wayfa-credits-hero:after{right:-260px}}
@media(prefers-reduced-motion:reduce){.wayfa-credits-card{transition:none}.wayfa-credits-card:hover{transform:none}}

/* Remove the two real outer frames: Astra site container + Elementor e-con default 10px. */
body.elementor-template-full-width:has(.wayfa-credits-elementor-root) #content.site-content,
body.elementor-template-full-width:has(.wayfa-credits-elementor-root) #content.site-content>.ast-container{
 width:100%!important;max-width:none!important;margin:0!important;padding:0!important;}
body.elementor-template-full-width:has(.wayfa-credits-elementor-root) .wayfa-credits-elementor-root{
 --padding-top:0;--padding-right:0;--padding-bottom:0;--padding-left:0;
 padding:0!important;margin:0!important;border:0!important;box-shadow:none!important;}
body.elementor-template-full-width:has(.wayfa-credits-elementor-root) .wayfa-credits-elementor-root>.wayfa-credits-hero,
body.elementor-template-full-width:has(.wayfa-credits-elementor-root) .wayfa-credits-elementor-root>.wayfa-credits-elementor-list{
 --padding-top:0;--padding-right:0;--padding-bottom:0;--padding-left:0;
 padding:0!important;margin:0!important;border:0!important;box-shadow:none!important;}
.wayfa-credits-card,.wayfa-credits-card>.wayfa-credits-image,.wayfa-credits-card>.wayfa-credits-image img{
 border-radius:inherit;}
.wayfa-credits-card{border-radius:var(--border-radius,16px)!important;overflow:hidden}
.wayfa-credits-card>.wayfa-credits-image{border-radius:inherit;overflow:hidden;clip-path:inset(0 round var(--border-radius,16px))}

/* Full-width rights archive: keep content fluid while inheriting radius from each card. */
.wayfa-credits-page{width:100%!important;max-width:none!important}
.wayfa-credits-main{max-width:none!important;width:100%!important;padding-inline:clamp(18px,3vw,64px)!important}
.wayfa-credits-grid{width:100%;max-width:none!important}
.wayfa-credits-card{--wayfa-card-radius:var(--border-radius,16px);border-radius:var(--wayfa-card-radius)!important;overflow:hidden;isolation:isolate}
.wayfa-credits-card>.wayfa-credits-image{border-radius:inherit;overflow:hidden}
.wayfa-credits-card>.wayfa-credits-image>.wayfa-credits-card-image{border-radius:inherit;display:block;width:100%;height:100%;object-fit:cover}
.wayfa-credits-card>.wayfa-credits-image img{border-radius:inherit;clip-path:inset(0 round var(--wayfa-card-radius))}
.wayfa-credits-elementor-root{width:100%!important;max-width:none!important}
@media(max-width:680px){.wayfa-credits-main{padding-inline:16px!important}}


/* Image Rights visual system v2: full-width Elementor composition. */
.wayfa-credits-elementor-root{--width:100%;--content-width:100%;width:100%;max-width:none!important;gap:0!important;overflow:visible}
.wayfa-credits-elementor-root>.wayfa-credits-hero,
.wayfa-credits-elementor-root>.wayfa-credits-elementor-list{--width:100%;--content-width:100%;width:100%;max-width:none!important}
.wayfa-credits-elementor-list .elementor-widget-shortcode,
.wayfa-credits-elementor-list .elementor-widget-container,
.wayfa-credits-elementor-list .elementor-shortcode{width:100%;max-width:none!important;min-width:0}
.wayfa-credits-hero{min-height:310px;padding:64px clamp(24px,5vw,72px)!important;justify-content:center;border-radius:0!important;background:radial-gradient(circle at 84% 22%,rgba(207,170,112,.15),transparent 32%),linear-gradient(112deg,#0e293b 0%,#163b4f 75%,#234d5d 100%)}
.wayfa-credits-hero .wayfa-credits-inner{--width:100%;--content-width:100%;width:100%;max-width:1180px!important;margin:0 auto!important;gap:12px!important;position:relative;z-index:1}
.wayfa-credits-hero .wayfa-credits-kicker{font-size:12px;letter-spacing:.18em;font-weight:750;color:#e9c98d;margin:0 0 4px!important}
.wayfa-credits-hero .elementor-heading-title,
.wayfa-credits-hero h1.elementor-heading-title{color:#fff!important;font-size:clamp(36px,4.1vw,58px)!important;line-height:1.12!important;letter-spacing:-.025em!important;font-weight:750!important;text-shadow:none!important;margin:0!important;max-width:890px}
.wayfa-credits-hero .elementor-widget-text-editor{color:#dce9ee!important;font-size:16px!important;line-height:1.7!important;max-width:760px}
.wayfa-credits-hero .elementor-widget-text-editor p{color:#dce9ee!important;margin:0!important;max-width:760px}
.wayfa-credits-hero .elementor-widget-heading{margin-bottom:0!important}
.wayfa-credits-hero:after{right:-100px;top:-250px;width:650px;height:650px;border:1px solid rgba(233,201,141,.22);box-shadow:0 0 0 75px rgba(233,201,141,.035),0 0 0 155px rgba(233,201,141,.025)}
.wayfa-credits-page{background:#f8f7f4!important}
.wayfa-credits-main{width:100%!important;max-width:1200px!important;margin:0 auto!important;padding:45px clamp(18px,3.8vw,48px) 75px!important}
.wayfa-credits-intro{margin-bottom:25px;align-items:center;gap:22px}
.wayfa-credits-intro h2{font-size:clamp(24px,2.3vw,31px);color:#17394b;margin-bottom:9px}
.wayfa-credits-intro p{max-width:720px;font-size:15px;color:#506c7c;line-height:1.65}
.wayfa-credits-count{color:#31536a;border-color:#e2d8c9;background:#fff9ef;font-weight:650;padding:11px 17px;font-size:12px}
.wayfa-credits-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:25px}
.wayfa-credits-card{border:1px solid #e2e6e6;border-radius:14px;box-shadow:0 9px 28px rgba(11,46,66,.075)}
.wayfa-credits-image{aspect-ratio:1.85;max-height:310px}
.wayfa-credits-card-body{padding:25px 27px 26px}
.wayfa-credits-card h2{font-size:clamp(21px,2vw,25px);color:#17394b}
.wayfa-credits-note{margin-top:32px}
@media (max-width:900px){.wayfa-credits-hero{min-height:265px;padding:50px 24px!important}.wayfa-credits-main{padding:35px 22px 55px!important}.wayfa-credits-grid{gap:18px}.wayfa-credits-card-body{padding:21px 20px}.wayfa-credits-card-bottom{align-items:flex-start}}
@media (max-width:680px){.wayfa-credits-hero{min-height:0;padding:42px 22px 45px!important}.wayfa-credits-hero .elementor-heading-title{font-size:clamp(32px,8vw,42px)!important}.wayfa-credits-hero .elementor-widget-text-editor{font-size:14px!important}.wayfa-credits-intro{display:block}.wayfa-credits-main{padding:30px 15px 52px!important}.wayfa-credits-grid{grid-template-columns:1fr;gap:19px}.wayfa-credits-count{margin-top:14px}.wayfa-credits-image{aspect-ratio:1.68}.wayfa-credits-note{margin-top:24px}}

</style>
CSS;
  return $css . $markup;
 }
 private static function pagination(int $page,int $pages,string $lang): string {
  if ($pages<2) return '';
  $out='<nav class="wayfa-credits-pagination" aria-label="'.self::t($lang,'nav').'">';
  if ($page>1) $out.='<a href="'.esc_url(add_query_arg('credits_page',$page-1,get_permalink())).'">'.self::t($lang,'prev').'</a>';
  for ($i=max(1,$page-2);$i<=min($pages,$page+2);$i++) {
   $out.=$i===$page?'<span aria-current="page">'.$i.'</span>':'<a href="'.esc_url(add_query_arg('credits_page',$i,get_permalink())).'">'.$i.'</a>';
  }
  if ($page<$pages) $out.='<a href="'.esc_url(add_query_arg('credits_page',$page+1,get_permalink())).'">'.self::t($lang,'next').'</a>';
  return $out.'</nav>';
 }
}
// Native JetEngine admin edits and deletions invalidate only the two rights pages.
add_action('jet-engine/custom-content-types/update-item/media_rights',
 static function() { MAD4B_Media_Attribution_Registry_V1::invalidate_public_pages(); }, 10, 3);
add_action('jet-engine/custom-content-types/delete-item/media_rights',
 static function() { MAD4B_Media_Attribution_Registry_V1::invalidate_public_pages(); }, 10, 3);
// Reuse Elementor's native Legal shortcode on every footer template. The
// existing shortcode and cookie-preferences links remain untouched.
add_filter('do_shortcode_tag', static function($output,$tag,$attrs,$match) {
 if ($tag !== 'mad4b_footer_legal' || !is_string($output) || !str_contains($output,'</nav>') || str_contains($output,'mad4b-media-rights-footer-link')) return $output;
 $lang=(string)apply_filters('wpml_current_language','en');
 $base=get_page_by_path('image-attribution',OBJECT,'page');
 if (!$base || get_post_status($base->ID)!=='publish') return $output;
 $id=(int)apply_filters('wpml_object_id',$base->ID,'page',false,$lang);
 if (!$id || get_post_status($id)!=='publish') return $output;
 $url=get_permalink($id);
 if (!is_string($url) || !str_starts_with($url,home_url('/'))) return $output;
 $label=MAD4B_Media_Attribution_Registry_V1::footer_label($lang);
 $link='<a class="mad4b-media-rights-footer-link" href="'.esc_url($url).'">'.esc_html($label).'</a>';
 return str_replace('</nav>',$link.'</nav>',$output);
},70,4);
// Shortcode only. JetEngine CCT is canonical; legacy option remains backup;
// no global footer output, and no changes to rights evidence.
add_shortcode('mad4b_media_credits', static function() {return MAD4B_Media_Attribution_Registry_V1::render();});
}
