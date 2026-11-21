<?php
/**
 * Plugin Name: MAD4B WPML Structural Elementor Sync
 * Description: English master tree -> linked WPML Elementor layouts, locale-aware references and explicit detach controls.
 * Version: 0.2.0-staging
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'staging' ||
    wp_parse_url(home_url('/'), PHP_URL_HOST) !== 'tours.dev.mad4b.com') return;

final class MAD4B_WPML_Structural_Sync_V2 {
    private static array $lookup = [];
    private static array $used = [];
    private static int $master = 0;
    private static string $lang = '';
    private static array $issues = [];
    private static int $changes = 0;

    private static function record(string $code, array $data = []): void {
        if (count(self::$issues) < 250) self::$issues[] = ['code' => $code, 'lang' => self::$lang] + $data;
    }
    private static function language(int $id): string {
        $detail = apply_filters('wpml_post_language_details', null, $id);
        return is_array($detail) ? (string) ($detail['language_code'] ?? '') : '';
    }
    private static function local_id(int $source, string $type, string $widget, string $field): int {
        if ($source < 1 || get_post_type($source) !== $type) {
            self::record('invalid_source_reference', ['widget'=>$widget,'field'=>$field,'source_id'=>$source]);
            return 0;
        }
        $local = (int) apply_filters('wpml_object_id', $source, $type, false, self::$lang);
        if (!$local || get_post_type($local) !== $type || self::language($local) !== self::$lang
            || get_post_status($local) !== 'publish') {
            self::record('missing_linked_reference', ['widget'=>$widget,'field'=>$field,'source_id'=>$source,'post_type'=>$type]);
            return 0;
        }
        return $local;
    }
    private static function ref_type(string $key): string {
        return match ($key) {
            'template_id','popup_id','header_template','footer_template' => 'elementor_library',
            'listing_id','lisitng_id','listing_item_id' => 'jet-engine',
            'filter_id','filterid' => 'jet-smart-filters',
            default => '',
        };
    }
    private static function map_ref($value, string $type, string $widget, string $key, bool &$pending) {
        if (is_array($value)) {
            $out=[];
            foreach ($value as $v) {
                if (!(is_int($v) || ctype_digit((string)$v))) { $pending = true;self::record('invalid_reference_member',['widget'=>$widget,'field'=>$key]);return $value; }
                $ref=self::local_id((int)$v,$type,$widget,$key);
                if (!$ref) {$pending=true;return $value;}
                $out[]=is_int($v)?$ref:(string)$ref;
            }
            return $out;
        }
        if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value<=0) return $value;
        $id=self::local_id((int)$value,$type,$widget,$key);
        if (!$id) {$pending=true;return $value;}
        return is_int($value)?$id:(string)$id;
    }
    private static function canonical_url(string $url): string {
        if ($url === '' || str_starts_with($url,'#') || str_starts_with($url,'mailto:')
            || str_starts_with($url,'tel:') || str_starts_with($url,'javascript:')) return '';
        $parts=wp_parse_url($url);
        if ($parts===false || !is_array($parts)) return '';
        $site=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        if (!empty($parts['host']) && strtolower($parts['host']) !== $site) return '';
        $path=(string)($parts['path']??'/');
        return home_url('/'.ltrim($path,'/'));
    }
    private static function map_url(string $value, string $widget, string $field, bool &$pending): string {
        if ($value === '' || str_starts_with($value,'#')) return $value;
        $base=self::canonical_url($value);
        if ($base==='') return $value; // External and non-HTTP actions remain authored.
        $post_id=url_to_postid($base);
        $output='';
        if ($post_id) {
            $post=get_post($post_id);
            $target=$post?self::local_id($post_id,$post->post_type,$widget,$field):0;
            if (!$target) {$pending=true;return $value;}
            $output=(string)get_permalink($target);
        } else {
            $path=trim((string)wp_parse_url($base,PHP_URL_PATH),'/');
            foreach (get_post_types(['public'=>true],'names') as $type) {
                $type_object=get_post_type_object($type);
                if (!$type_object || !$type_object->has_archive) continue;
                $global_sitepress=$GLOBALS['sitepress']??null;
                if (is_object($global_sitepress)) $global_sitepress->switch_lang(
                    (string)(apply_filters('wpml_default_language',null)?:'en'));
                $archive=get_post_type_archive_link($type);
                if (trim((string)wp_parse_url($archive,PHP_URL_PATH),'/')!==$path) continue;
                if (is_object($global_sitepress)) $global_sitepress->switch_lang(self::$lang);
                $output=(string)get_post_type_archive_link($type);
                break;
            }
            if ($output==='') {
                $converted=apply_filters('wpml_permalink',$base,self::$lang,true);
                if (is_string($converted) && $converted!=='' &&
                    str_starts_with($converted, (string)apply_filters('wpml_home_url',home_url('/'),self::$lang))) {
                    $output=$converted;
                }
            }
        }
        if ($output==='') {
            self::record('unresolved_internal_url',['widget'=>$widget,'field'=>$field,'url'=>$value]);
            $pending=true;return $value;
        }
        $parts=wp_parse_url($value);
        if (!empty($parts['query'])) $output=add_query_arg([], $output).'?'.$parts['query'];
        if (!empty($parts['fragment'])) $output.='#'.$parts['fragment'];
        return $output;
    }
    private static function text_key(string $key): bool {
        return in_array($key, [
            'title','item_title','text','editor','description','caption','search_placeholder_text',
            'search_submit_label','search_category_select_placeholder','results_counter_text',
            'full_results_btn_text','negative_search','server_error','placeholder','placeholder_text',
            'button_text','tab_title','label','heading','subheading','before_text','after_text',
            'featured_read_more_text','read_more_text','read_less_text','show_more_text','show_less_text',
            'browse_label','custom_home_page_label','search_results_title','link_label','apply_button_text',
            'not_found_message','item_text','tab_content','button_label','subtitle','content','text_content','html',
        ], true);
    }
    private static function fallback(string $value): string {
        $sets=[
            'ar'=>['Enhance Your Adventure:'=>'عزّز مغامرتك:','Optional Excursions'=>'الرحلات الاختيارية','Grid'=>'شبكة','List'=>'قائمة','No data was found'=>'لم يتم العثور على بيانات','Quick View'=>'عرض سريع','Select Tour Type'=>'اختر نوع الرحلة','Filter Tour Style'=>'تصفية حسب نمط الرحلة','Filter Durations'=>'تصفية حسب المدة','Filter Guide Languages'=>'تصفية حسب لغات المرشد','Good for'=>'مناسب لـ','Day of Weak'=>'يوم الأسبوع','Day Time'=>'وقت الرحلة','Tour Season'=>'موسم الرحلة','Apply filter'=>'تطبيق الفلتر','Read More'=>'اقرأ المزيد','Read Less'=>'عرض أقل',
                'Search ...'=>'بحث ...','Go'=>'بحث','Tour Type'=>'نوع الرحلة','Visas'=>'التأشيرات',
                'Tours &amp; Activities'=>'الرحلات والأنشطة','Hotels &amp; Properties'=>'الفنادق والإقامة','Transportations'=>'الانتقالات',
                'Results'=>'النتائج','See all results'=>'عرض كل النتائج',
                'Sorry, but nothing matched your search terms.'=>'لم نعثر على نتائج مطابقة لبحثك.',
                'Sorry, but we cannot handle your search query now. Please, try again later!'=>'تعذر تنفيذ البحث الآن. حاول مجددًا لاحقًا.',
            ],
            'fr'=>['Enhance Your Adventure:'=>'Enrichissez votre aventure :','Optional Excursions'=>'Excursions facultatives','Grid'=>'Grille','List'=>'Liste','No data was found'=>'Aucune donnée disponible','Quick View'=>'Aperçu rapide','Select Tour Type'=>'Sélectionner le type de visite','Filter Tour Style'=>'Filtrer le style de visite','Filter Durations'=>'Filtrer les durées','Filter Guide Languages'=>'Filtrer les langues du guide','Good for'=>'Idéal pour','Day of Weak'=>'Jour de la semaine','Day Time'=>'Moment de la journée','Tour Season'=>'Saison du voyage','Apply filter'=>'Appliquer le filtre','Read More'=>'Lire la suite','Read Less'=>'Afficher moins','Search ...'=>'Rechercher ...','Go'=>'Rechercher','Tour Type'=>'Type de circuit','Results'=>'Résultats','See all results'=>'Voir tous les résultats','Visas'=>'Visas',
                'Tours &amp; Activities'=>'Excursions et activités','Hotels &amp; Properties'=>'Hôtels et hébergements','Transportations'=>'Transports',
                'Sorry, but nothing matched your search terms.'=>'Aucun résultat ne correspond à votre recherche.',
                'Sorry, but we cannot handle your search query now. Please, try again later!'=>'Recherche indisponible. Veuillez réessayer plus tard.'],
            'de'=>['Enhance Your Adventure:'=>'Bereichern Sie Ihr Abenteuer:','Optional Excursions'=>'Optionale Ausflüge','Grid'=>'Raster','List'=>'Liste','No data was found'=>'Keine Daten gefunden','Quick View'=>'Schnellansicht','Select Tour Type'=>'Reiseart auswählen','Filter Tour Style'=>'Reisestil filtern','Filter Durations'=>'Dauer filtern','Filter Guide Languages'=>'Reiseleitersprachen filtern','Good for'=>'Geeignet für','Day of Weak'=>'Wochentag','Day Time'=>'Tageszeit','Tour Season'=>'Reisesaison','Apply filter'=>'Filter anwenden','Read More'=>'Mehr lesen','Read Less'=>'Weniger anzeigen','Search ...'=>'Suchen ...','Go'=>'Suchen','Tour Type'=>'Reiseart','Results'=>'Ergebnisse','See all results'=>'Alle Ergebnisse ansehen','Visas'=>'Visa',
                'Tours &amp; Activities'=>'Touren und Aktivitäten','Hotels &amp; Properties'=>'Hotels und Unterkünfte','Transportations'=>'Transfers',
                'Sorry, but nothing matched your search terms.'=>'Keine Ergebnisse für Ihre Suche gefunden.',
                'Sorry, but we cannot handle your search query now. Please, try again later!'=>'Die Suche ist momentan nicht verfügbar. Bitte später erneut versuchen.'],
            'es'=>['Enhance Your Adventure:'=>'Mejora tu aventura:','Optional Excursions'=>'Excursiones opcionales','Grid'=>'Cuadrícula','List'=>'Lista','No data was found'=>'No se encontraron datos','Quick View'=>'Vista rápida','Select Tour Type'=>'Seleccionar tipo de tour','Filter Tour Style'=>'Filtrar estilo de tour','Filter Durations'=>'Filtrar duración','Filter Guide Languages'=>'Filtrar idiomas del guía','Good for'=>'Ideal para','Day of Weak'=>'Día de la semana','Day Time'=>'Momento del día','Tour Season'=>'Temporada del tour','Apply filter'=>'Aplicar filtro','Read More'=>'Leer más','Read Less'=>'Mostrar menos','Search ...'=>'Buscar ...','Go'=>'Buscar','Tour Type'=>'Tipo de tour','Results'=>'Resultados','See all results'=>'Ver todos los resultados','Visas'=>'Visados',
                'Tours &amp; Activities'=>'Tours y actividades','Hotels &amp; Properties'=>'Hoteles y alojamientos','Transportations'=>'Traslados',
                'Sorry, but nothing matched your search terms.'=>'No se encontraron resultados para tu búsqueda.',
                'Sorry, but we cannot handle your search query now. Please, try again later!'=>'La búsqueda no está disponible ahora. Inténtalo más tarde.'],
            'it'=>['Enhance Your Adventure:'=>'Arricchisci la tua avventura:','Optional Excursions'=>'Escursioni facoltative','Grid'=>'Griglia','List'=>'Elenco','No data was found'=>'Nessun dato trovato','Quick View'=>'Anteprima rapida','Select Tour Type'=>'Seleziona il tipo di tour','Filter Tour Style'=>'Filtra lo stile del tour','Filter Durations'=>'Filtra la durata','Filter Guide Languages'=>'Filtra le lingue della guida','Good for'=>'Ideale per','Day of Weak'=>'Giorno della settimana','Day Time'=>'Momento della giornata','Tour Season'=>'Stagione del tour','Apply filter'=>'Applica filtro','Read More'=>'Leggi di più','Read Less'=>'Mostra meno','Search ...'=>'Cerca ...','Go'=>'Cerca','Tour Type'=>'Tipo di tour','Results'=>'Risultati','See all results'=>'Mostra tutti i risultati','Visas'=>'Visti',
                'Tours &amp; Activities'=>'Tour e attività','Hotels &amp; Properties'=>'Hotel e alloggi','Transportations'=>'Trasferimenti',
                'Sorry, but nothing matched your search terms.'=>'Nessun risultato corrisponde alla ricerca.',
                'Sorry, but we cannot handle your search query now. Please, try again later!'=>'Ricerca non disponibile. Riprova più tardi.'],
        ];
        return (string)($sets[self::$lang][$value]??'');
    }
    private static function translate(string $value,string $widget,string $key,bool &$pending) {
        if (trim(wp_strip_all_tags($value))==='' || is_numeric($value) || strlen($value)>2000) return $value;
        $domain='MAD4B Elementor Master';
        $token=self::$master.'.'.$widget.'.'.$key;
        do_action('wpml_register_single_string',$domain,$token,$value);
        $tr=apply_filters('wpml_translate_single_string',$value,$domain,$token,self::$lang);
        if (is_string($tr)&&trim($tr)!=='' && $tr!==$value) return $tr;
        $fallback=self::fallback($value);
        if ($fallback!=='')return $fallback;
        $pending=true;
        self::record('missing_widget_text_translation',['widget'=>$widget,'field'=>$key,'source_sha'=>hash('sha256',$value)]);
        return $value;
    }
    private static function localize_settings(array $source,?array $existing,string $widget,bool &$pending): array {
        $target=$source;
        foreach ($source as $k=>$v) {
            $key=(string)$k;
            if ((in_array($key,['image','thumbnail_placeholder','background_image','media','gallery'],true)
                || str_ends_with($key,'_image') || str_ends_with($key,'_media'))
                && is_array($v)) {
                if (isset($existing[$key]) && is_array($existing[$key]))$target[$key]=$existing[$key];
                elseif (isset($v['url']) && self::canonical_url((string)$v['url'])==='') {
                    $attachment=absint($v['id']??0);
                    $local=$attachment ? wp_get_attachment_url($attachment) : false;
                    if ($local && self::canonical_url((string)$local)!=='') $target[$key]['url']=$local;
                    else self::record('external_media_not_localized',['widget'=>$widget,'field'=>$key]);
                }
                continue;
            }
            $ref=self::ref_type($key);
            if ($ref!=='') {
                $failed=false;
                $mapped=self::map_ref($v,$ref,$widget,$key,$failed);
                if ($failed && isset($existing[$key]) && $existing[$key]!=='' && $existing[$key]!==[]) {
                    $target[$key]=$existing[$key];
                    self::record('existing_local_reference_preserved',['widget'=>$widget,'field'=>$key]);
                } else {
                    $target[$key]=$mapped;
                    if ($failed)$pending=true;
                }
                continue;
            }
            if ($key==='shortcode'&&is_string($v)) {
                $target[$key]=preg_replace_callback('/\[elementor-template\b([^\]]*\bid\s*=\s*["\']?)(\d+)(["\']?[^\]]*)\]/',
                    static function($m)use($widget,&$pending) {
                        $id=self::local_id((int)$m[2],'elementor_library',$widget,'shortcode');
                        if (!$id){$pending=true;return $m[0];}
                        return '[elementor-template'.$m[1].$id.$m[3].']';
                    },$v);
                if ($pending && isset($existing[$key]) && is_string($existing[$key])
                    && trim($existing[$key])!=='') {
                    $target[$key]=$existing[$key];
                    $pending=false;
                    self::record('existing_local_shortcode_preserved',['widget'=>$widget,'field'=>$key]);
                }
                continue;
            }
            if (($key==='url'||str_ends_with($key,'_url')||in_array($key,['href','redirect','redirect_url'],true)) && is_string($v)) {
                $failed=false;
                $mapped=self::map_url($v,$widget,$key,$failed);
                if ($failed && isset($existing[$key]) && is_string($existing[$key])
                    && trim($existing[$key])!=='') {
                    $target[$key]=$existing[$key];
                    self::record('existing_local_url_preserved',['widget'=>$widget,'field'=>$key]);
                } else {
                    $target[$key]=$mapped;
                    if($failed)$pending=true;
                }
                continue;
            }
            if (self::text_key($key) && is_string($v)) {
                $localized=$existing[$key]??'';
                if (is_string($localized) && trim($localized)!=='' && $localized!==$v)
                    $target[$key]=$localized;
                elseif (self::$lang!=='en') {
                    $failed=false;
                    $target[$key]=self::translate($v,$widget,$key,$failed);
                    if ($failed && isset($existing[$key]) && is_string($existing[$key])
                        && trim($existing[$key])!=='') {
                        $target[$key]=$existing[$key];
                        self::record('existing_untranslated_text_preserved',['widget'=>$widget,'field'=>$key]);
                    } elseif($failed) $pending=true;
                }
                continue;
            }
            if (is_array($v) && $key!=='__dynamic__') {
                $local=is_array($existing[$key]??null)?$existing[$key]:null;
                $target[$key]=self::localize_settings($v,$local,$widget.'.'.$key,$pending);
            }
            if (self::$lang==='ar' && in_array($key,['align','text_align','content_horizontal_position'],true)
                && in_array($v,['left','right'],true)) {
                $target[$key]=$v==='left'?'right':'left';
            }
        }
        // Elementor dynamic-tag definitions are owned by the source. Never
        // flatten them into static URLs/text while copying a new widget.
        if (isset($source['__dynamic__']))$target['__dynamic__']=$source['__dynamic__'];
        return $target;
    }
    private static function build_lookup(array $tree): void {
        foreach($tree as $node) {
            if (!is_array($node))continue;
            $id=(string)($node['id']??'');
            if($id!=='')self::$lookup[$id][]=$node;
            self::build_lookup((array)($node['elements']??[]));
        }
    }
    private static function reconcile_nodes(array $source,array $local,int $depth=0): array {
        if($depth>45){self::record('elementor_tree_depth_exceeded');return $local;}
        $direct=[];
        foreach($local as $node)if(is_array($node)&&isset($node['id']))
            $direct[(string)$node['id']][]=$node;
        $out=[];
        foreach($source as $position=>$s) {
            if(!is_array($s) || empty($s['id']) || empty($s['elType'])) continue;
            $id=(string)$s['id'];
            $candidate=null;
            foreach($direct[$id]??[] as $n)if(true){
                // IDs can repeat in a corrupted Elementor tree. Prefer the same sibling.
                if(($n['elType']??'')===($s['elType']??'')&&($n['widgetType']??'')===($s['widgetType']??'')){
                    $candidate=$n;break;
                }
            }
            if($candidate===null) {
                foreach(self::$lookup[$id]??[] as $n) {
                    if (($n['elType']??'')===($s['elType']??'') && ($n['widgetType']??'')===($s['widgetType']??'')) {
                        $candidate=$n;break;
                    }
                }
            }
            if($candidate!==null && ($candidate['settings']['mad4b_wpml_sync_mode']??'')==='detach'){
                $out[]=$candidate;continue;
            }
            if (($s['settings']['mad4b_wpml_sync_mode']??'')==='detach') {
                if($candidate!==null)$out[]=$candidate;
                continue;
            }
            $new=$candidate===null;
            if($new){self::$changes++;self::record('widget_created_from_master',['widget'=>$id,'type'=>$s['widgetType']??$s['elType']]);}
            $node=$s;
            $pending=false;
            $candidate_pending=$candidate!==null && str_contains(
                (string)($candidate['settings']['_css_classes']??''), 'mad4b-wpml-needs-translation');
            // Pending nodes must not be mistaken for valid existing translations
            // on the next cycle; resolve again from WPML or remain hidden.
            $node['settings']=self::localize_settings((array)($s['settings']??[]),
                ($candidate===null || $candidate_pending)?null:(array)($candidate['settings']??[]),$id,$pending);
            // Existing locale JetSmartFilters keep their native filter IDs and provider
            // contracts. English owns layout/styling; locale runtime owns bindings.
            // Copying an English filter_id, apply mode or condition can silently
            // disconnect a working locale filter after a structural reconciliation.
            if ($candidate !== null && str_starts_with((string)($s['widgetType'] ?? ''), 'jet-smart-filters-')) {
                $localeSettings=(array)($candidate['settings']??[]);
                foreach (['filter_id','query_id','additional_providers_list',
                           'additional_providers_enabled','apply_type',
                           'display_condition_list','jedv_conditions','__dynamic__'] as $key) {
                    if (array_key_exists($key,$localeSettings))$node['settings'][$key]=$localeSettings[$key];
                    else unset($node['settings'][$key]);
                }
            }
            $node['elements']=self::reconcile_nodes((array)($s['elements']??[]),
                $candidate===null?[]:(array)($candidate['elements']??[]),$depth+1);
            if($pending) {
                $node['settings']['_css_classes']=trim((string)($node['settings']['_css_classes']??'').' mad4b-wpml-needs-translation');
                self::record('widget_pending_locale_content',['widget'=>$id]);
            } else {
                $node['settings']['_css_classes']=trim(str_replace('mad4b-wpml-needs-translation','',
                    (string)($node['settings']['_css_classes']??'')));
            }
            $out[]=$node;
        }
        // Keep explicitly detached target widgets even if removed from English.
        $source_ids=array_fill_keys(array_map(static fn($x)=>(string)($x['id']??''),$source),true);
        foreach($local as $n)if(is_array($n) && !isset($source_ids[(string)($n['id']??'')])
            && ($n['settings']['mad4b_wpml_sync_mode']??'')==='detach')$out[]=$n;
        return $out;
    }
    public static function plan(array $src,array $dst,string $lang,int $master,int $target,array &$issues,int &$changed): array {
        if($master<=0||$target<=0||$lang==='')return $dst;
        self::$master=$master;self::$lang=$lang;self::$issues=[];self::$changes=0;self::$lookup=[];self::$used=[];
        self::build_lookup($dst);
        $sitepress=$GLOBALS['sitepress']??null;
        $previous=(string)(apply_filters('wpml_current_language',null)?:'en');
        if(is_object($sitepress))$sitepress->switch_lang($lang);
        try {
            $result=self::reconcile_nodes($src,$dst);
        } finally {
            if(is_object($sitepress))$sitepress->switch_lang($previous);
        }
        if($result!==$dst)$changed+=1+self::$changes;
        $issues=array_merge($issues,self::$issues);
        return $result;
    }
    public static function register_control($element,$section_id,$args): void {
        if(!in_array((string)$section_id,['_section_style','section_advanced','section_layout_container','section_layout'],true))return;
        if($element->get_controls('mad4b_wpml_sync_mode'))return;
        $element->start_controls_section('mad4b_wpml_sync_section',[
            'label'=>'WPML Element Sync','tab'=>\Elementor\Controls_Manager::TAB_ADVANCED,
        ]);
        $element->add_control('mad4b_wpml_sync_mode',[
            'label'=>'Detach this widget from English',
            'type'=>\Elementor\Controls_Manager::SWITCHER,
            'label_on'=>'Detached','label_off'=>'Inherit',
            'return_value'=>'detach','default'=>'',
            'description'=>'Off: English controls widget structure and settings; texts, links and references use WPML. On: keep this locale widget unchanged.',
        ]);
        $element->end_controls_section();
    }
}
add_action('elementor/element/after_section_end',['MAD4B_WPML_Structural_Sync_V2','register_control'],25,3);
add_action('wp_enqueue_scripts',static function(){
    wp_register_style('mad4b-wpml-structure-visibility',false,[], '0.2.0');
    wp_enqueue_style('mad4b-wpml-structure-visibility');
    wp_add_inline_style('mad4b-wpml-structure-visibility',
        '.mad4b-wpml-needs-translation{display:none!important}');
},110);

/**
 * New untranslated Elementor content is stored for WPML editor review but
 * not emitted as hidden English markup to public pages. Existing widgets
 * with an approved local override are never pruned.
 */
add_filter('elementor/frontend/builder_content_data', static function($tree, $document_id) {
    if (!is_array($tree) || is_admin() || isset($_GET['elementor-preview'])) return $tree;
    if (class_exists('Elementor\Plugin') && isset(\Elementor\Plugin::$instance->editor)
        && \Elementor\Plugin::$instance->editor->is_edit_mode()) return $tree;
    $visit=static function(array $nodes) use (&$visit): array {
        $out=[];
        foreach($nodes as $node) {
            if (!is_array($node))continue;
            if (($node['settings']['mad4b_wpml_sync_mode']??'')!=='detach'
                && str_contains((string)($node['settings']['_css_classes']??''),
                    'mad4b-wpml-needs-translation'))continue;
            if(!empty($node['elements']) && is_array($node['elements']))
                $node['elements']=$visit($node['elements']);
            $out[]=$node;
        }
        return $out;
    };
    return $visit($tree);
}, 9000, 2);

/** Re-evaluate pending linked templates when WPML editors publish a translation. */
add_action('save_post_elementor_library', static function($id,$post) {
    if (!$post || $post->post_status !== 'publish'
        || !class_exists('MAD4B_WPML_Design_Reconciliation_V1')) return;
    $default=(string)(apply_filters('wpml_default_language',null)?:'en');
    $language=apply_filters('wpml_post_language_details',null,(int)$id);
    if (!is_array($language)||($language['language_code']??'')===$default) return;
    $original=(int)apply_filters('wpml_object_id',(int)$id,'elementor_library',false,$default);
    if (!$original || $original===(int)$id) return;
    $parents=get_posts([
        'post_type'=>['page','elementor_library'],'post_status'=>['publish','draft'],
        'numberposts'=>45,'fields'=>'ids','suppress_filters'=>true,
        'meta_query'=>[['key'=>'_elementor_data','value'=>(string)$original,'compare'=>'LIKE']]
    ]);
    foreach($parents as $parent) {
        $raw=(string)get_post_meta($parent,'_elementor_data',true);
        if (!str_contains($raw,'"template_id":"'.$original.'"')
            && !str_contains($raw,'"template_id":'.$original)
            && !str_contains($raw,'elementor-template id=')) continue;
        $details=apply_filters('wpml_post_language_details',null,(int)$parent);
        if (!is_array($details)||($details['language_code']??'')!==$default)continue;
        MAD4B_WPML_Design_Reconciliation_V1::enqueue((int)$parent);
    }
}, 110, 2);

/** WPML String Translation saves need no English Elementor edit. Re-check pending sources hourly. */
add_action('init', static function() {
    if (get_option('mad4b_wpml_structural_sync_v2_enabled','no')!=='yes') return;
    if(!wp_next_scheduled('mad4b_wpml_structural_recheck_v2'))
        wp_schedule_event(time()+3600,'hourly','mad4b_wpml_structural_recheck_v2');
}, 95);
add_action('mad4b_wpml_structural_recheck_v2', static function() {
    if (!class_exists('MAD4B_WPML_Design_Reconciliation_V1'))return;
    global $wpdb;
    $ids=$wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
         WHERE meta_key=%s AND meta_value LIKE %s LIMIT 14",
        '_elementor_data','%mad4b-wpml-needs-translation%'
    ));
    $default=(string)(apply_filters('wpml_default_language',null)?:'en');
    $queued=[];
    foreach($ids as $id) {
        $type=get_post_type((int)$id);
        if (!in_array($type,['page','elementor_library'],true))continue;
        $source=(int)apply_filters('wpml_object_id',(int)$id,$type,false,$default);
        if (!$source||isset($queued[$source])||get_post_status($source)!=='publish')continue;
        $details=apply_filters('wpml_post_language_details',null,$source);
        if(!is_array($details)||($details['language_code']??'')!==$default)continue;
        $queued[$source]=true;
        MAD4B_WPML_Design_Reconciliation_V1::enqueue($source);
    }
}, 10);
