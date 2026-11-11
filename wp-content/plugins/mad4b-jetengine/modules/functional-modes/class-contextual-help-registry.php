<?php
/**
 * MAD4B Contextual Pricing Help v1.
 *
 * Only describes existing JetEngine / Woo / Pricing Lab fields. Never
 * writes terms, options, rates, WCML settings, translations or policies.
 * Only resolves term descriptions when an authorized user opens that
 * specific dimension. All output is plain text and bounded.
 */
defined('ABSPATH') || exit;

final class MAD4B_Pricing_Contextual_Help_V1 {
    public const ACTION = 'mad4b_pricing_contextual_help_v1';
    private const WPML_CONTEXT = 'MAD4B Pricing Contextual Help';

    public static function permitted(): bool {
        return current_user_can('commerce_manage_pricing')
            && current_user_can('manage_woocommerce');
    }

    private static function language(): string {
        $locale=(string)get_user_locale();
        $code=strtolower(substr(str_replace('_','-',$locale),0,2));
        return in_array($code,['en','ar','fr','de','es','it'],true)?$code:'en';
    }
    public static function clean($value, int $limit=350): string {
        $text=is_scalar($value)?(string)$value:'';
        $text=wp_strip_all_tags($text,true);
        $text=html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=preg_replace('/\s+/u',' ',$text);
        $text=trim((string)$text);
        return function_exists('mb_substr')?mb_substr($text,0,$limit):substr($text,0,$limit);
    }

    // Descriptions localize using the WordPress user admin language.
    // WPML String Translation can override any message without requiring a
    // DB registration/write on a normal read or admin page load.
    private static function translate(string $id, string $english, array $other=[]): string {
        $locale=self::language();
        $text=(string)($other[$locale]??$english);
        $wpml=apply_filters('wpml_translate_single_string',$text,
            self::WPML_CONTEXT,$id,$locale);
        return self::clean(is_string($wpml)?$wpml:$text,620);
    }

    private static function builtins(): array {
        return [
          'editor'=>['Define who qualifies, how the price is adjusted, where the policy applies, and preview its impact before saving an inactive Draft.',
            'ar'=>'حدّد الرحلات المؤهلة، وقاعدة تغيير السعر، ونطاق التطبيق، ثم عاين التأثير قبل حفظ مسودة غير نشطة.','fr'=>'Définissez les voyages ciblés, la règle de prix, la portée et vérifiez l’impact avant de sauvegarder un brouillon inactif.','de'=>'Zielreisen, Preisregel und Geltungsbereich festlegen; Auswirkungen vor dem Speichern eines inaktiven Entwurfs prüfen.','es'=>'Define las actividades objetivo, la regla de precio y el alcance; previsualiza el impacto antes de guardar un borrador inactivo.','it'=>'Definisci i tour interessati, la regola di prezzo e l’ambito; verifica l’impatto prima di salvare una bozza inattiva.'],
          'targeting'=>['Only tours whose saved JetEngine terms match these conditions will be considered. Selecting a term here does not add that term to any tour.',
            'ar'=>'تُطابق الرحلة فقط إذا كانت تحمل المصطلحات المحددة بالفعل في JetEngine. الاختيار هنا لا يضيف تصنيفات جديدة إلى أي رحلة.','fr'=>'Seuls les voyages dont les termes JetEngine correspondent sont concernés.','de'=>'Nur Touren mit passenden JetEngine-Begriffen kommen infrage.','es'=>'Solo coincidirán los tours con los términos JetEngine seleccionados.','it'=>'Corrispondono solo i tour con i termini JetEngine selezionati.'],
          'pricing'=>['Uses the native Booking Experience pricing template. A 90% multiplier means a 10% reduction, not a 90% discount. Preview never changes a published Rate.',
            'ar'=>'يستخدم قالب التسعير الأصلي. النسبة 90% تعني خصم 10%، وليس خصم 90%. المعاينة لا تغيّر سعرًا منشورًا.','fr'=>'Un multiplicateur de 90 % signifie une réduction de 10 %, pas de 90 %.','de'=>'90 % Multiplikator bedeutet 10 % Rabatt, nicht 90 %.','es'=>'Un multiplicador del 90 % significa un descuento del 10 %, no del 90 %.','it'=>'Un moltiplicatore del 90% indica uno sconto del 10%, non del 90%.'],
          'scope'=>['Without explicit groups the default WPML-language rule inherits across published translations. Adding a group restricts this Draft to its selected display languages and/or markets.',
            'ar'=>'بدون مجموعات تُورَّث سياسة اللغة الافتراضية لترجمات الرحلات المنشورة. إضافة مجموعة تُقيّد هذه المسودة باللغات أو الأسواق المحددة.','fr'=>'Sans groupe, la règle de langue WPML par défaut s’applique aux traductions publiées.','de'=>'Ohne Gruppen gilt die WPML-Standardsprache für veröffentlichte Übersetzungen.','es'=>'Sin grupos, la política del idioma WPML predeterminado se hereda en las traducciones publicadas.','it'=>'Senza gruppi la politica WPML predefinita si applica alle traduzioni pubblicate.'],
          'impact'=>['Runs a bounded read-only preview of matching tours, rate examples and conflicts. Saving a Draft does not activate checkout pricing or discounts.',
            'ar'=>'تعرض معاينة قراءة فقط للرحلات والأسعار والتعارضات. حفظ المسودة لا يفعّل خصمًا ولا يغيّر أسعار Checkout.','fr'=>'Aperçu en lecture seule. Enregistrer un brouillon n’active aucune remise.','de'=>'Schreibgeschützte Vorschau. Ein Entwurf aktiviert keinen Rabatt.','es'=>'Vista previa de solo lectura. Guardar un borrador no activa descuentos.','it'=>'Anteprima in sola lettura. Salvare una bozza non attiva sconti.'],
          'match'=>['Match ANY (OR) accepts a tour carrying at least one selected value. Match ALL (AND) requires all selected values on the same tour.',
            'ar'=>'ANY (OR): يكفي مصطلح واحد مختار. ALL (AND): يجب أن تحمل الرحلة كل المصطلحات المختارة معًا.','fr'=>'OR : au moins un terme ; AND : tous les termes sur le même voyage.','de'=>'OR: mindestens ein Begriff; AND: alle Begriffe für dieselbe Tour.','es'=>'OR: al menos un término; AND: todos los términos en el mismo tour.','it'=>'OR: almeno un termine; AND: tutti i termini sullo stesso tour.'],
          'match.any'=>['ANY / OR: at least one of the selected values is sufficient. More selected values can broaden the group.',
            'ar'=>'ANY / OR: يكفي وجود مصطلح واحد. اختيار مصطلحات إضافية قد يوسّع عدد الرحلات المطابقة.','es'=>'OR: basta con un valor seleccionado.','fr'=>'OR : un seul terme sélectionné suffit.','de'=>'OR: Ein ausgewählter Wert genügt.','it'=>'OR: basta un valore selezionato.'],
          'match.all'=>['ALL / AND: a tour must carry every selected value simultaneously. More selected values can reduce matches, possibly to zero.',
            'ar'=>'ALL / AND: يجب وجود كل المصطلحات المختارة في الرحلة نفسها. إضافة شروط قد تقلّل المطابقات للصفر.','es'=>'AND: el tour debe tener todos los valores seleccionados.','fr'=>'AND : tous les termes doivent être présents.','de'=>'AND: Alle ausgewählten Werte müssen vorhanden sein.','it'=>'AND: tutti i termini devono essere presenti.'],
          'term'=>['Only tours already tagged with the selected value are eligible. Its business definition comes from the native WordPress taxonomy description when one is available.',
            'ar'=>'تتأهل الرحلات التي تحمل هذا المصطلح بالفعل. يُؤخذ معناه من وصف المصطلح الأصلي في WordPress إن كان مسجلًا.'],
          'no_description'=>['No description has been defined for this native term. Edit its taxonomy description to add one; the term match still works.',
            'ar'=>'لا يوجد وصف أصلي مسجل لهذا المصطلح. يمكن إضافته من وصف المصطلح في Taxonomy، والمطابقة تعمل بالفعل.'],
          'templates'=>['Choose a reusable Booking Experience pricing template. This control does not overwrite Tour Rate prices.',
            'ar'=>'اختر قالب تسعير من Booking Experience. الحقل لا يكتب فوق أسعار Tour Rates المحفوظة.'],
          'price_basis'=>['The draft percent preview uses the current effective published Tour Rate for selected audience and occupancy. Validity is checked at server current time (no travel date chosen). Tour direct starting price and Price Sources weights do not become this preview base.',
            'ar'=>'معاينة النسبة تعتمد على سعر الـTour Rate المنشور الفعّال للفئة والإشغال المحددين. الصلاحية تُفحص بالوقت الحالي (بدون تاريخ سفر محدد). سعر بداية الرحلة وأوزان Price Sources لا يحددان أساس هذه المعاينة.'],
          'template.percent'=>['Multiply the published Tour Rate’s effective price (regular, or active sale if configured) by this percentage. 94% means 6% off that Rate price. Existing sale discounts require conflict review. Tour direct starting price is not the base in this Draft preview.',
            'ar'=>'تُطبَّق النسبة على السعر الفعّال للـTour Rate المنشور (السعر العادي أو التخفيض النشط). 94% تعني خصم 6% من هذا السعر. أي خصم موجود يحتاج مراجعة تعارض؛ سعر بداية الرحلة المباشر ليس أساس معاينة المسودة.'],
          'template.unchanged'=>['Keep the existing source price unchanged. No percentage or currency input is required.',
            'ar'=>'يحافظ على السعر الأصلي دون تعديل، ولا يحتاج نسبة أو عملة جديدة.'],
          'template.fixed'=>['Use the specified fixed price in the selected currency; source-rate and WCML rules still need validation.',
            'ar'=>'سعر ثابت بالعملة المختارة، مع استمرار التحقق من قواعد الريت وWCML.'],
          'template.delta'=>['Adjust the original source price by a fixed amount. It is not a percentage multiplier.',
            'ar'=>'تغيير السعر بمبلغ ثابت، وليس نسبة مئوية.'],
          'template.floor'=>['Set the minimum price threshold for the proposed calculation.',
            'ar'=>'ضع حدًا أدنى للسعر المقترح.'],
          'template.cap'=>['Set the maximum price threshold for the proposed calculation.',
            'ar'=>'ضع حدًا أقصى للسعر المقترح.'],
          'rate_kind.tour'=>['Create a Tour Rate Draft linked to an existing Tour.',
            'ar'=>'إنشاء مسودة Tour Rate مرتبطة برحلة قائمة.'],
          'rate_kind.accommodation'=>['Create an accommodation or unit Rate Draft linked to an existing property/unit.',
            'ar'=>'إنشاء مسودة سعر إقامة مرتبطة بعقار أو وحدة قائمة.'],
          'rate_kind.transport'=>['Create a Transport Rate Draft linked to an existing transportation parent.',
            'ar'=>'إنشاء مسودة Transport Rate مرتبطة بوسيلة نقل قائمة.'],
          'pricing_rule_kind.markup_percent'=>['Markup increases supplier cost by the entered percent (e.g. 20% on 100 = 120).',
            'ar'=>'Markup تزيد تكلفة المورد بالنسبة المحددة؛ 20% على 100 تعني 120.'],
          'pricing_rule_kind.target_margin_percent'=>['Target margin is a percent of the final selling price, not of cost (20% margin on cost 100 gives 125).',
            'ar'=>'Target Margin نسبة من سعر البيع النهائي لا التكلفة؛ هامش 20% مع تكلفة 100 يعطي 125.'],
          'pricing_rule_kind.fixed_profit'=>['Add an explicit fixed profit amount to supplier cost.',
            'ar'=>'إضافة مبلغ ربح ثابت لتكلفة المورد.'],
          'pricing_locale_mode.inherit'=>['Inherit current source Rate pricing behavior without language-specific amount override.',
            'ar'=>'وراثة سلوك الريت الأصلي بدون تجاوز مبلغ خاص بلغة العرض.'],
          'pricing_locale_mode.independent'=>['Create a separate language-specific draft. It does not overwrite translated Rates.',
            'ar'=>'إنشاء مسودة مستقلة للغة محددة، من غير تعديل الريتات المترجمة.'],
          'inventory_policy.on_request'=>['Supplier confirmation is required; this setting alone does not confirm available stock.',
            'ar'=>'يتطلب تأكيد المورد؛ لا يعني وحده وجود مخزون مؤكد.'],
          'inventory_policy.allotment'=>['Inventory is managed as allotment, subject to configured stock controls.',
            'ar'=>'المخزون يتبع نظام الحصص وفق إعدادات التوفر.'],

          'multiplier'=>['100% is the original source price. 90% is a 10% discount; 110% is a 10% increase. The saved number remains precise even if the slider moves in 0.5% steps.',
            'ar'=>'100% = السعر الأصلي، 90% = خصم 10%، 110% = زيادة 10%. القيمة المكتوبة تظل دقيقة حتى لو يتحرك السلايدر بخطوات 0.5%.'],
          'audience'=>['Select which existing Rate audience is used for the preview. It does not change the customer identity or nationality.',
            'ar'=>'حدد فئة السعر المستخدمة في المعاينة. لا يغيّر هوية العميل أو جنسيته.'],
          'occupancy'=>['Choose the rate occupancy (Single, Double or Triple) for the price simulation; the underlying saved Rate is unchanged.',
            'ar'=>'اختر إشغال السعر Single أو Double أو Triple للمعاينة فقط، دون تغيير الريت المحفوظ.'],
          'currency'=>['Available currency codes come from JetEngine Glossary and the company currency configuration. WooCommerce and WCML still own financial validation and conversion.',
            'ar'=>'العملات من JetEngine Glossary وإعدادات الشركة؛ والتحقق المالي والتحويل مسؤولية WooCommerce وWCML.'],
          'groups'=>['Inside one group, selected language and market filters combine with AND. Several groups combine with OR. Any explicit group ends unrestricted inheritance for this Draft.',
            'ar'=>'داخل المجموعة الواحدة تتجمع شروط اللغة والسوق بـAND، وبين المجموعات بـOR. وجود مجموعة يُنهي الوراثة العامة لهذه المسودة.'],
          'slider'=>['This slider moves the numeric price multiplier; manual values remain authoritative. It never applies prices until the actual commercial workflow approves them.',
            'ar'=>'السلايدر يحرّك نسبة السعر الرقمية، والكتابة اليدوية تظل المرجع الدقيق. لا يُفعّل أسعارًا تجاريًا من نفسه.'],
          'rate_kind'=>['Choose the existing Rate CPT type to create an unpublished Draft. This does not create a new product type.',
            'ar'=>'اختر نوع الـRate الحالي لإنشاء مسودة غير منشورة، وليس منتجًا جديدًا.'],
          'parent_id'=>['Provide the ID of an existing Tour, Property or Transport parent. The generator validates its relation.',
            'ar'=>'اكتب ID لرحلة أو عقار أو وسيلة نقل مسجلة بالفعل؛ يتحقق المحرك من علاقتها بالـRate.'],
          'amount'=>['Original source Rate amount. A new Draft is created after validation; existing published Rates remain unchanged.',
            'ar'=>'مبلغ الريت الأصلي. يتم إنشاء مسودة بعد التحقق ولا تُعدَّل الأسعار المنشورة.'],
          'price_unit'=>['The unit must match the selected Rate type. Choices are read from the registered JetEngine unit Glossary.',
            'ar'=>'يجب توافق وحدة التسعير مع نوع الـRate، والاختيارات من Glossary المسجلة في JetEngine.'],
          'pricing_rule_kind'=>['Markup is a percentage of cost; target margin is a percentage of the final selling price. They produce different prices.',
            'ar'=>'Markup نسبة من التكلفة، بينما Target Margin نسبة من سعر البيع النهائي؛ والنتيجتان مختلفتان.'],
          'valid_from'=>['Start date for the new Rate validity period. The date must be compatible with the Rate policy.',
            'ar'=>'تاريخ بداية صلاحية الـRate الجديد، ويجب توافقه مع سياسة الصلاحية.'],
          'valid_to'=>['End date for the new Rate validity period; it must not precede Valid from.',
            'ar'=>'تاريخ نهاية صلاحية الـRate الجديد، ولا يصح أن يسبق البداية.'],
          'default_currency'=>['Leave blank to inherit the WooCommerce store currency. Selecting a code does not change WCML FX or checkout.',
            'ar'=>'اتركه فارغًا لوراثة عملة WooCommerce. اختياره لا يغيّر أسعار الصرف أو Checkout.'],
          'currency_picker'=>['This controls which supported JetEngine Glossary currencies appear in NEW Rate and Pricing Lab dropdowns. It does not enable WCML currencies.',
            'ar'=>'يحدد العملات الظاهرة في قوائم إنشاء الـRates والمسودات الجديدة، ولا يفعّل عملات WCML.'],
          'currency_picker.glossary'=>['Show every JetEngine Glossary currency recognized by WooCommerce, not only the WCML display currencies.',
            'ar'=>'عرض كل عملات JetEngine Glossary التي يتعرف عليها WooCommerce، وليس عملات WCML فقط.'],
          'currency_picker.wcml_only'=>['Show only the currently enabled WCML display currencies as new-pricing authoring choices. WCML remains the FX authority.',
            'ar'=>'عرض عملات WCML المفعّلة حاليًا فقط لاختيارات إنشاء الأسعار الجديدة؛ وتظل التحويلات لدى WCML.'],
          'currency_policy'=>['Inherit recognized currencies by default, or restrict the currencies allowed for NEW pricing Drafts. Existing Rates remain untouched.',
            'ar'=>'وراثة العملات المعترف بها افتراضيًا، أو تقييد عملات المسودات الجديدة فقط؛ الأسعار المحفوظة لا تتغير.'],
          'currency_policy.restrict'=>['Only selected currencies are accepted for new Rate Generation and Pricing Lab Drafts. Store base currency must remain selected.',
            'ar'=>'تُقبل العملات المحددة فقط عند إنشاء مسودات جديدة، ويجب الاحتفاظ بعملة المتجر الأساسية.'],
          'currency_policy.inherit_woocommerce'=>['Use WooCommerce-recognized currencies from the JetEngine catalog, with no extra company authoring restriction.',
            'ar'=>'استخدم العملات المعترف بها لدى WooCommerce من كتالوج JetEngine بدون تقييد إضافي.'],
          'bindings'=>['Manage policy references by Rate, Tour, Taxonomy or Global scope. A binding changes the preview resolution, not the saved Tour Rate amount or checkout.',
            'ar'=>'إدارة مراجع السياسات حسب Rate أو Tour أو Taxonomy أو Global؛ تؤثر في قراءة الوراثة لا مبلغ الريت المحفوظ أو Checkout.'],

          'pricing_engine_enabled'=>['Allows the Tour pricing policy layer to be considered by the server provider; it does not enable payments or booking by itself.',
            'ar'=>'يتيح النظر في سياسات التسعير لمحرك الرحلات، لكنه لا يفعّل الحجز أو الدفع وحده.'],
          'booking_enabled'=>['Live booking eligibility is separate from payment gateway and WooCommerce checkout configuration.',
            'ar'=>'أهلية الحجز المباشر مستقلة عن بوابات الدفع وإعدادات WooCommerce Checkout.'],
          'stop_sale_global'=>['Global Stop Sale prevents eligible sales when enforced by the booking provider. Review its commercial implications before changing.',
            'ar'=>'إيقاف البيع العام قد يمنع الحجوزات المؤهلة عند تطبيقه في المحرك. راجع أثره التجاري قبل تغييره.'],
          'save'=>['Save changes stores native JetEngine settings. Save Draft stores an inactive pricing proposal. Neither button activates a discount by itself.',
            'ar'=>'Save changes يحفظ إعدادات JetEngine، وSave Draft يحفظ مقترحًا غير نشط. لا يُفعّل أي منهما خصمًا بمفرده.'],
          'source'=>['This is native source metadata. It is used only as a matching condition, not as a calculated price or payment rule.',
            'ar'=>'دي بيانات مصدر أصلية تُستخدم كشرط مطابقة فقط، وليست سعرًا محسوبًا أو قاعدة دفع.'],
        ];
    }

    public static function messages(): array {
        static $cache=null;
        if(is_array($cache))return $cache;
        $raw=self::builtins();
        $out=[];
        foreach($raw as $key=>$spec){
            $text=array_shift($spec);
            $out[$key]=self::translate($key,$text,$spec);
        }
        $custom=apply_filters('mad4b/pricing/contextual_help_messages',$out,self::language());
        if(is_array($custom))foreach($custom as $key=>$value)
            if(is_string($key)&&preg_match('/^[a-z0-9_.-]{2,80}$/D',$key))
                $out[$key]=self::clean($value,620);
        return $cache=$out;
    }

    public static function native_field_notes(): array {
        // Additional help uses the native field ID so renaming labels will
        // not lose explanations. JetEngine Description retains priority.
        return [
            'pricing_engine_enabled'=>'pricing_engine_enabled',
            'booking_enabled'=>'booking_enabled',
            'stop_sale_global'=>'stop_sale_global',
            'default_currency'=>'default_currency',
            'shared_price_source_direct_weight'=>'source',
            'shared_price_source_rate_weight'=>'source',
        ];
    }
    private static function page_allowed(): bool {
        $page=sanitize_key((string)($_GET['page']??''));
        return in_array($page,[
            'dynamic-pricing-controls','etg-dynamic-pricing-controls',
            'mad4b-pricing-lab','mad4b-pricing-workspace',
            'mad4b-pricing-currencies','mad4b-policy-bindings',
        ],true)
          ||($page==='wc-settings'
            &&sanitize_key((string)($_GET['tab']??''))==='rate_generation');
    }
    public static function boot(): void {
        if(!is_admin())return;
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],40);
        add_action('wp_ajax_'.self::ACTION,[__CLASS__,'ajax']);
    }
    public static function assets(): void {
        if(!self::permitted()||!self::page_allowed())return;
        $dir=__DIR__.'/assets/';
        $url=plugins_url('mad4b-jetengine/modules/functional-modes/assets/');
        wp_enqueue_style('mad4b-pricing-context-help',$url.'contextual-help.css',[],
            (string)filemtime($dir.'contextual-help.css'));
        wp_enqueue_script('mad4b-pricing-context-help',$url.'contextual-help.js',[],
            (string)filemtime($dir.'contextual-help.js'),true);
        wp_add_inline_script('mad4b-pricing-context-help',
            'window.MAD4BPricingHelpData='.wp_json_encode([
                'messages'=>self::messages(),
                'fields'=>self::native_field_notes(),
                'language'=>self::language(),
                'ajax_url'=>admin_url('admin-ajax.php'),
                'action'=>self::ACTION,
                'nonce'=>wp_create_nonce(self::ACTION),
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).';','before');
    }

    public static function dimension_help(array $dimension): array {
        $id=(string)($dimension['id']??'');
        $language=self::language();
        $notes=[];
        if(!preg_match('/^(taxonomy|meta|metabox):[a-z0-9_-]{2,80}$/D',$id))
            return ['dimension'=>[],'options'=>[]];
        $taxonomy=($dimension['source']??'')==='taxonomy'
            ?(string)($dimension['key']??substr($id,9)):'';
        $definition=$taxonomy?get_taxonomy($taxonomy):null;
        $taxDesc=$definition?self::clean($definition->description??''): '';
        $level=self::messages();
        foreach(array_slice((array)($dimension['options']??[]),0,100) as $item) {
            if(!is_array($item))continue;
            $value=(string)($item['id']??'');
            $label=self::clean($item['label']??$value,120);
            if($value===''||strlen($value)>90)continue;
            $desc='';
            $source='fallback';
            if($definition&&ctype_digit($value)) {
                $term=get_term((int)$value,$taxonomy);
                if($term instanceof WP_Term && !is_wp_error($term)) {
                    $translatedId=(int)apply_filters('wpml_object_id',
                      (int)$term->term_id,$taxonomy,false,$language);
                    if($translatedId>0 && $translatedId!==(int)$term->term_id){
                        $translated=get_term($translatedId,$taxonomy);
                        if($translated instanceof WP_Term&&!is_wp_error($translated)
                            &&trim((string)$translated->description)!=='')$term=$translated;
                    }
                    $desc=self::clean($term->description??'');
                    if($desc!=='')$source='wordpress_term_description';
                }
            }
            // Some JetEngine definitions supply a specific manual option
            // description. Never infer meaning from "Underage", tags, etc.
            if($desc==='') {
                $desc=$label.': '.$level['term'];
                $source='generic_fallback';
            }
            $notes[$value]=[
                'title'=>$label,'text'=>$desc,'source'=>$source
            ];
        }
        return ['dimension'=>[
            'title'=>self::clean($dimension['label']??$id,130),
            'text'=>$taxDesc!==''?$taxDesc:($level['targeting']??''),
            'source'=>$taxDesc!==''?'taxonomy_description':'shared_help',
        ],'options'=>$notes];
    }

    public static function ajax(): void {
        if(!self::permitted())wp_send_json_error(['message'=>'Permission required'],403);
        check_ajax_referer(self::ACTION,'nonce');
        $id=isset($_POST['dimension_id'])&&is_string($_POST['dimension_id'])
            ?sanitize_text_field(wp_unslash($_POST['dimension_id'])):'';
        if(!preg_match('/^(taxonomy|meta|metabox):[a-z0-9_-]{2,80}$/D',$id))
            wp_send_json_error(['message'=>'Invalid dimension'],400);
        $scope=MAD4B_JetEngine_Pricing_Dimensions_V1::language_policy();
        $bridge='MAD4B_JetEngine_Strategy_Schema_Bridge_V1';
        $available=array_fill_keys(
            array_column(MAD4B_JetEngine_Pricing_Dimensions_V1::available(),'id'),true);
        if(!isset($available[$id]))wp_send_json_error(['message'=>'Not available'],404);
        $catalog=$bridge::catalog([$scope['default_language']],false);
        foreach($catalog['dimensions'] as $dimension)
            if(($dimension['id']??'')===$id)
                wp_send_json_success(self::dimension_help($dimension));
        wp_send_json_error(['message'=>'Dimension not found'],404);
    }
}
