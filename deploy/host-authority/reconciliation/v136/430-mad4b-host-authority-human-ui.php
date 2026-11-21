<?php
/**
 * MAD4B Host v136 — human-readable presentation over the *existing*
 * v134/v135 REST and Admin UI. No authority, identity or workflow mutation.
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Host_V3_Human_UI', false)) {
final class MAD4B_Host_V3_Human_UI {
    const PAGE = 'mad4b-host-v3-owner-review';
    const REVIEW_PREFIX = 'mad4b_v3_remote_req_';

    public static function boot() {
        add_action('admin_footer', array(__CLASS__, 'render'), 30);
    }
    private static function allowed() {
        return is_admin()
            && isset($_GET['page']) && is_string($_GET['page'])
            && $_GET['page'] === self::PAGE
            && current_user_can('manage_options')
            && current_user_can('activate_plugins')
            && class_exists('MAD4B_Host_V3_Owner_Console', false)
            && class_exists('MAD4B_Host_V3_Remote_Handoff', false)
            && MAD4B_Host_V3_Owner_Console::site_binding_valid();
    }
    /**
     * Recover the owner's latest *existing* review, without copying a
     * request from another account and without creating a new approval.
     */
    public static function latest_owner_review() {
        if (!self::allowed()) return null;
        global $wpdb;
        if (!is_object($wpdb) || !is_string($wpdb->options)) return null;
        $like = $wpdb->esc_like(self::REVIEW_PREFIX) . '%';
        $query = $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 100",
            $like
        );
        $names = $wpdb->get_col($query);
        if (!is_array($names)) return null;
        $current = (int)get_current_user_id();
        foreach ($names as $name) {
            if (!is_string($name) || strpos($name, self::REVIEW_PREFIX) !== 0) continue;
            $record = get_option($name, false);
            $id = substr($name, strlen(self::REVIEW_PREFIX));
            if (!is_array($record) ||
                ($record['contract'] ?? '') !== 'mad4b.original305.remote-review-request.v1' ||
                ($record['site_uuid'] ?? '') !== MAD4B_Host_V3_Owner_Console::SITE ||
                ($record['company'] ?? '') !== 'mad4b-central' ||
                ($record['environment'] ?? '') !== 'staging' ||
                ($record['request_id'] ?? '') !== $id ||
                !preg_match('/^[a-f0-9]{32}$/D', $id) ||
                ($record['requested_by_user_id'] ?? null) !== $current ||
                !is_int($record['created_epoch'] ?? null) ||
                !is_int($record['expires_epoch'] ?? null)
            ) continue;
            return array(
                'request_id' => $id,
                'created_epoch' => $record['created_epoch'],
                'expires_epoch' => $record['expires_epoch'],
                'state' => (string)($record['state'] ?? ''),
                'expired' => $record['expires_epoch'] < time(),
            );
        }
        return null;
    }
    public static function render() {
        if (!self::allowed()) return;
        $bootstrap = array(
            'restBase' => rest_url('mad4b-host-v3/v1'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'latestReview' => self::latest_owner_review(),
            // The signed-in administrator's WP Profile language, not WPML or site content.
            'userLocale' => get_user_locale(),
        );
        $json = wp_json_encode($bootstrap,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return;

        // Render an actual, selectable input from the existing v134 contract.
        // This is server HTML, independent of DOMContentLoaded, JavaScript
        // hydration, and stale client-side state. It is NOT an authorization.
        $locale = strtolower(strtok(str_replace('-', '_', (string)get_user_locale()), '_'));
        $translations = array(
            'en' => array('copy'=>'Copy exact confirmation','label'=>'Exact review confirmation (not a signature)',
                'notice'=>'Your review is already recorded; do not submit another request.',
                'copied'=>'Exact confirmation copied. This does not authorize Write.',
                'manual'=>'Select the text and press Ctrl+C.'),
            'ar' => array('copy'=>'نسخ عبارة التأكيد','label'=>'عبارة تأكيد المراجعة (ليست توقيعًا)',
                'notice'=>'مراجعتك مسجّلة بالفعل؛ لا ترسل طلبًا جديدًا.',
                'copied'=>'تم نسخ التأكيد. هذا لا يمنح صلاحية الكتابة.',
                'manual'=>'حدّد النص واضغط Ctrl+C.'),
            'fr' => array('copy'=>'Copier la confirmation','label'=>'Confirmation de révision (pas une signature)',
                'notice'=>'Votre demande est enregistrée ; inutile de la renvoyer.',
                'copied'=>'Confirmation copiée. Cela n’autorise pas l’écriture.',
                'manual'=>'Sélectionnez le texte et appuyez sur Ctrl+C.'),
            'de' => array('copy'=>'Bestätigung kopieren','label'=>'Bestätigung der Prüfung (keine Unterschrift)',
                'notice'=>'Ihre Anfrage liegt bereits vor; nicht erneut senden.',
                'copied'=>'Bestätigung kopiert. Keine Schreibfreigabe.',
                'manual'=>'Text markieren und Strg+C drücken.'),
            'es' => array('copy'=>'Copiar confirmación','label'=>'Confirmación de revisión (no es firma)',
                'notice'=>'Tu solicitud ya está registrada; no la envíes de nuevo.',
                'copied'=>'Confirmación copiada. No autoriza la escritura.',
                'manual'=>'Selecciona el texto y pulsa Ctrl+C.'),
            'it' => array('copy'=>'Copia conferma','label'=>'Conferma della revisione (non è una firma)',
                'notice'=>'La richiesta è già registrata; non inviarne un’altra.',
                'copied'=>'Conferma copiata. Non autorizza la scrittura.',
                'manual'=>'Seleziona il testo e premi Ctrl+C.'),
        );
        $l = $translations[$locale] ?? $translations['en'];
        $copyable = MAD4B_Host_V3_Owner_Console::CONFIRM;
        $existing = is_array($bootstrap['latestReview']) &&
            is_string($bootstrap['latestReview']['request_id'] ?? null);
        ?>
<style id="mad4b-host-v136-human-css">
.mad4b-host-v3 .mad4b-human {
    max-width: 850px;
    background:#fff;
    border:1px solid #c3c4c7;
    margin:18px 0;
    padding:24px;
    box-sizing:border-box;
    color:#1d2327;
    font-size:14px;
    line-height:1.65;
}
.mad4b-host-v3 .mad4b-human * {box-sizing:border-box}
.mad4b-host-v3 .mad4b-human h2 {
    font-size:21px;
    line-height:1.5;
    margin:6px 0 10px;
}
.mad4b-host-v3 .mad4b-human h3 {font-size:15px;margin:20px 0 8px}
.mad4b-host-v3 .mad4b-human p {margin:8px 0}
.mad4b-host-v3 .mad4b-human .human-eyebrow {font-size:12px;color:#50575e;margin:0}
.mad4b-host-v3 .mad4b-human .human-muted {color:#50575e}
.mad4b-host-v3 .mad4b-human .human-steps {
    list-style:none;
    margin:18px 0;
    padding:0;
    border-top:1px solid #dcdcde;
}
.mad4b-host-v3 .mad4b-human .human-steps li {
    display:grid;
    grid-template-columns:26px minmax(0,1fr);
    gap:10px;
    align-items:start;
    padding:13px 0;
    margin:0;
    border-bottom:1px solid #f0f0f1;
}
.mad4b-host-v3 .mad4b-human .human-marker {
    border:1px solid #a7aaad;
    border-radius:50%;
    width:24px;
    height:24px;
    line-height:22px;
    text-align:center;
    font-weight:600;
    font-size:12px;
}
.mad4b-host-v3 .mad4b-human .human-marker.is-done {
    border-color:#2271b1;
    color:#135e96;
}
.mad4b-host-v3 .mad4b-human .human-step-label {font-weight:600}
.mad4b-host-v3 .mad4b-human .human-step-desc {color:#50575e;font-size:13px}
.mad4b-host-v3 .mad4b-human .human-actions {
    border-top:1px solid #dcdcde;
    padding-top:16px;
    margin-top:18px;
}
.mad4b-host-v3 .mad4b-human [hidden] {
    display:none !important;
}
.mad4b-host-v3 .mad4b-human .human-exact-copy {
    margin:12px 0 14px;
    padding:12px 14px;
    border:1px solid #dcdcde;
    border-radius:4px;
    background:#f6f7f7;
}
.mad4b-host-v3 .mad4b-human .human-exact-copy code {
    display:block;
    padding:10px;
    margin:8px 0;
    font-size:13px;
    user-select:text;
    overflow-wrap:anywhere;
}
.mad4b-host-v3 .mad4b-human .human-exact-copy .button {
    min-height:36px;
    padding-inline:12px;
}
.mad4b-host-v3 .mad4b-human .human-actions .button {
    min-height:38px;
    padding:6px 14px;
    font-size:14px;
    line-height:1.6;
}
.mad4b-host-v3 .mad4b-human .human-detail {
    border-top:1px solid #dcdcde;
    padding-top:14px;
    margin-top:20px;
}
.mad4b-host-v3 .mad4b-human .human-detail summary {
    cursor:pointer;
    font-weight:600;
    padding:4px 0;
}
.mad4b-host-v3 .mad4b-human .human-extra {margin-top:16px}
.mad4b-host-v3 .mad4b-human .human-extra .v3-card {
    margin:12px 0;
    padding:16px;
    box-shadow:none;
}
.mad4b-host-v3 .mad4b-human .human-status {min-height:1em;overflow-wrap:anywhere}
.mad4b-host-v3 .mad4b-human .human-request-id {
    unicode-bidi:isolate;
    direction:ltr;
    display:inline-block;
    font-family:monospace;
    font-size:12px;
    overflow-wrap:anywhere;
}
.mad4b-host-v3 .mad4b-human button:focus-visible,
.mad4b-host-v3 .mad4b-human summary:focus-visible {
    outline:2px solid #2271b1;
    outline-offset:3px;
}
@media(max-width:782px) {
  .mad4b-host-v3 .mad4b-human {padding:16px;margin:12px 0}
  .mad4b-host-v3 .mad4b-human h2 {font-size:18px}
  .mad4b-host-v3 .mad4b-human .human-actions .button {width:100%;margin:4px 0}
}
</style>
<style id="mad4b-host-v140-server-copy-style">
#mad4b-owner-copy-ssr-v140 {
  box-sizing:border-box; margin:12px 0; padding:12px;
  background:#f6f7f7; border:1px solid #c3c4c7; border-radius:4px;
  max-width:600px; color:#1d2327; line-height:1.5;
}
#mad4b-owner-copy-ssr-v140 label {display:block;font-weight:600;margin-bottom:6px}
#mad4b-owner-copy-ssr-v140 .v140-exact-input {
  display:block;width:100%;max-width:540px;min-height:38px;
  direction:ltr;text-align:left;font-size:13px;
  font-family:monospace;background:#fff;user-select:all;
  margin:8px 0;
}
#mad4b-owner-copy-ssr-v140 .button {min-height:36px;padding:6px 14px}
#mad4b-owner-copy-ssr-v140 .v140-message {font-size:13px;color:#50575e;margin:8px 0 0}
#mad4b-owner-copy-ssr-v140 [hidden] {display:none !important}
<?php if ($existing) : ?>
/* Once this owner has a review, the v134 confirmation/request form
   is obsolete. Hide it on the SERVER, not only after JS hydration. */
.mad4b-host-v3 section.v3-card[aria-labelledby="v3-review"] {display:none !important}
#mad4b-owner-copy-ssr-v140 {display:none !important}
<?php endif; ?>
</style>
<div id="mad4b-owner-copy-ssr-v140" data-ui-version="v141"
     <?php if ($existing) : ?>hidden<?php endif; ?> role="group" aria-label="<?php echo esc_attr($l['label']); ?>">
  <label for="mad4b-exact-confirm-select-v140"><?php echo esc_html($l['label']); ?></label>
  <input id="mad4b-exact-confirm-select-v140" class="v140-exact-input"
     type="text" readonly value="<?php echo esc_attr($copyable); ?>"
     autocomplete="off" spellcheck="false" aria-readonly="true">
  <button type="button" class="button button-secondary"
    id="mad4b-owner-copy-btn-v140"><?php echo esc_html($l['copy']); ?></button>
  <p class="v140-message" role="status" aria-live="polite"
     id="mad4b-owner-copy-status-v140"></p>
  <?php if ($existing) : ?>
  <p class="v140-message"><?php echo esc_html($l['notice']); ?></p>
  <?php endif; ?>
</div>
<script id="mad4b-owner-v140-copy-standalone">
(function(){
"use strict";
const area=document.getElementById("mad4b-owner-copy-ssr-v140");
const target=document.getElementById("v3-confirm");
const input=document.getElementById("mad4b-exact-confirm-select-v140");
const button=document.getElementById("mad4b-owner-copy-btn-v140");
const status=document.getElementById("mad4b-owner-copy-status-v140");
const copied=<?php echo wp_json_encode($l['copied'],JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const fallback=<?php echo wp_json_encode($l['manual'],JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
if(!area||!input||!button||!status)return;
if(target && typeof target.insertAdjacentElement==="function") {
  target.insertAdjacentElement("afterend",area);
}
input.addEventListener("focus",()=>input.select());
input.addEventListener("click",()=>input.select());
button.addEventListener("click",async()=>{
 try{
   if(!navigator.clipboard || typeof navigator.clipboard.writeText!=="function")
     throw Error("CLIPBOARD_NOT_AVAILABLE");
   await navigator.clipboard.writeText(input.value);
   status.textContent=copied;
 }catch(e){
   input.focus();
   input.select();
   status.textContent=fallback;
 }
});
<?php if ($existing) : ?>
const oldSubmit=document.getElementById("v3-submit");
if(oldSubmit){
  oldSubmit.hidden=true;
  oldSubmit.style.display="none";
  oldSubmit.disabled=true;
  oldSubmit.setAttribute("aria-hidden","true");
}
<?php endif; ?>
})();
</script>
<script id="mad4b-host-v136-human-script">
(function() {
"use strict";
const settings = <?php echo $json; ?>;
const languageCode = String(settings.userLocale || "en_US").split(/[-_]/)[0].toLowerCase();
const language = ["ar","en","fr","de","es","it"].includes(languageCode) ? languageCode : "en";
const intlLocale = languageCode === language ? String(settings.userLocale).replace("_","-") : "en-US";
const strings = {"ar":{"eyebrow":"Tours.dev · بيئة الاختبار","panelTitle":"اعتماد صلاحية الكتابة الدائمة","intro":"صلاحية الكتابة غير مفعّلة حاليًا. النظام ينتظر استكمال سجل الاعتمادات السابقة ثم توقيع المالك من الجهة المستقلة.","progress":"إحنا وصلنا لفين؟","identityTitle":"هوية الموقع","identityDesc":"تم التحقق من موقع Tours.dev ومفاتيح التحقق.","reviewTitle":"طلب مراجعة المالك","reviewInitial":"هنعرض حالة طلبك هنا.","historyTitle":"مراجعة سجل الموافقات السابقة","historyDesc":"المطلوب من النظام: إثبات أحدث اعتماد أصلي من الجهة المستقلة.","signatureTitle":"التوقيع وتفعيل الكتابة","signatureDesc":"في انتظار جهة توقيع المالك، ثم يتم إصدار صلاحية كتابة محكومة.","actionHeading":"إيه المطلوب منك دلوقتي؟","copyId":"نسخ رقم الطلب","help":"لن تحتاج إلى كتابة JSON أو استخدام SSH. تسجيل الطلب لا يعني صدور توقيع أو تفعيل الكتابة.","advancedTitle":"تفاصيل فنية للمسؤول التقني (اختياري)","advancedDesc":"البيانات الموقّعة وطلبات REST مخصصة لفريق التشغيل فقط، وليست حقولًا مطلوبة من المالك.","pageHeading":"صلاحية الكتابة الدائمة — Host Authority","reviewRecorded":"تم تسجيل طلب المراجعة","reviewRecordedDesc":"الطلب مسجّل، لكن لسه مش موافقة موقّعة.","existingTitle":"طلبك موجود بالفعل","currentExpires":"ينتهي الطلب الحالي: ","noDuplicate":"لا تحتاج إلى إنشاء طلب جديد الآن.","reviewExpired":"انتهت صلاحية طلب المراجعة","renewDesc":"ممكن تجدد الطلب، لكن اعتماد النظام الأصلي ما زال مطلوبًا.","newDesc":"يمكن تسجيل طلب مراجعة الآن، دون أي توقيع تلقائي.","renewHeading":"تجديد طلب المراجعة","requestHeading":"تسجيل طلب مراجعة المالك","offerNeeded":"التوقيع المستقل مطلوب بعد تسجيل الطلب.","missingPrereqs":"جهة التوقيع وسجل الاعتمادات السابقة لم يجهزا بعد. تسجيل الطلب لا يفعّل الصلاحية.","renewButton":"تجديد طلب المراجعة","requestButton":"تسجيل طلب مراجعة","requesting":"جارٍ تسجيل طلب المراجعة دون إصدار موافقة...","missingPlan":"لا توجد خطة مراجعة معتمدة لهذا الموقع.","missingId":"لم يمكن التحقق من رقم الطلب. راجع سجلات التشغيل.","requestSuccess":"تم تسجيل الطلب. لا توجد موافقة موقّعة أو صلاحية كتابة حتى الآن.","requestFailed":"لم يُسجّل طلب جديد: ","copySuccess":"تم نسخ رقم الطلب فقط، وليس بيانات التوقيع.","copyFailure":"تعذّر النسخ. يمكن لمسؤول النظام عرض رقم الطلب من السجل.","offerHelp":"وصلت شهادة أولية موقّعة. استكمال توقيع المالك يتطلب جهة توقيع مستقلة؛ التفاصيل متاحة للمسؤول التقني.","refreshFailed":"تعذّر جلب أحدث حالة. ما زال تفعيل الكتابة غير مؤكد؛ يرجى تحديث الصفحة.","dateUnknown":"غير معروف","invalidResponse":"تعذّر قراءة استجابة الموقع. جرّب تحديث الصفحة.","connectionFailed":"لم ينجح الاتصال. يرجى إعادة المحاولة.","accessibilityLabel":"حالة اعتماد الكتابة الدائمة","requestDenied":"هذه الجلسة لا تملك صلاحية تسجيل الطلب، أو انتهت صلاحيتها. حدّث الصفحة وسجّل الدخول إذا لزم الأمر.","reviewUnavailable":"الطلب غير متاح حاليًا. تحقّق من حالة الاعتماد أو حدّث الصفحة.","requestRateLimited":"يوجد طلب حديث بالفعل. لا تنشئ طلبًا مكررًا؛ حدّث الصفحة لمراجعة الطلب السابق.","temporaryFailure":"تعذّر التحقق من نتيجة العملية حاليًا. راجع حالة الطلب قبل إعادة الإرسال.","reviewExpiryWaitingDesc":"تم تسجيل مراجعتك. انتهت مدة الطلب، لكن السجل محفوظ لحين استكمال النظام شروط الاعتماد.","reviewReceivedTitle":"تم تسجيل مراجعتك — لا يلزم إجراء جديد الآن","reviewExpiredWaiting":"انتهت مدة طلب المراجعة، لكن سجلك محفوظ. يجب أن يكمل النظام التحقق من سجل Original313 وتوقيع المالك المستقل قبل الحاجة لأي طلب جديد.","technicalPendingHelp":"الخطوة التالية لدى جهة الاعتماد المستقلة، وليست إنشاء مراجعة WordPress أخرى. هذه الشاشة لا تصدر توقيعًا أو تفعّل الكتابة.","copyExactConfirmation":"نسخ عبارة التأكيد","exactConfirmationHint":"عبارة التأكيد الدقيقة (لطلب فني فقط، وليست توقيع المالك):","exactConfirmationCopied":"تم نسخ عبارة التأكيد. ليست توقيعًا ولا موافقة على الكتابة.","exactConfirmationFallback":"النسخ المباشر غير متاح. تم تحديد النص داخل حقل التأكيد؛ اضغط Ctrl+C.","reviewAlreadyRecordedNoResubmit":"المراجعة مسجّلة بالفعل. لا تُرسل طلبًا آخر؛ التحقق المستقل ما زال معلقًا."},"en":{"eyebrow":"Tours.dev · Staging","panelTitle":"Persistent write authorization","intro":"Persistent write is not enabled. The system is awaiting verification of earlier approvals and a signature from the independent owner identity.","progress":"Approval progress","identityTitle":"Site identity","identityDesc":"Tours.dev and its verification keys are confirmed.","reviewTitle":"Owner review request","reviewInitial":"Your review request status appears here.","historyTitle":"Verify previous approvals","historyDesc":"The system must verify the latest original approval with the independent authority.","signatureTitle":"Signature and write access","signatureDesc":"Waiting for the independent owner signer before a controlled write grant can be issued.","actionHeading":"What needs your attention?","copyId":"Copy request ID","help":"No JSON or SSH needed. Recording a request does not sign an approval or enable write access.","advancedTitle":"Technical details for administrators (optional)","advancedDesc":"Signed evidence and REST requests are for technical operators, not fields the owner needs to complete.","pageHeading":"Persistent write access — Host Authority","reviewRecorded":"Review request recorded","reviewRecordedDesc":"Your request is recorded, but it is not a signed approval.","existingTitle":"Your request already exists","currentExpires":"Current request expires: ","noDuplicate":"No need to create another request now.","reviewExpired":"Review request expired","renewDesc":"You can renew the request; independent approval is still required.","newDesc":"You can register a review request without creating a signature.","renewHeading":"Renew review request","requestHeading":"Register an owner review request","offerNeeded":"An independent signature is required after the request is recorded.","missingPrereqs":"The independent signer and previous approvals are not ready. Registering a request will not enable write access.","renewButton":"Renew review request","requestButton":"Register review request","requesting":"Recording the request (no approval will be issued)…","missingPlan":"No verified review plan is available for this site.","missingId":"The request ID could not be verified. Check the operation logs.","requestSuccess":"Request recorded. No signed approval or write access has been issued.","requestFailed":"No new request was recorded: ","copySuccess":"Request ID copied (not signing data).","copyFailure":"Could not copy. An administrator can find the request ID in the log.","offerHelp":"An initial signed certificate is available. The owner still needs an independent signer; technical details are available below.","refreshFailed":"Could not refresh status. Write access is still unconfirmed; reload the page.","dateUnknown":"Unknown","invalidResponse":"Could not read the site response. Reload the page.","connectionFailed":"Connection failed. Please try again.","accessibilityLabel":"Persistent write authorization status","requestDenied":"Your session cannot submit this request or has expired. Refresh the page and sign in if needed.","reviewUnavailable":"The request is not available yet. Check approval status or refresh the page.","requestRateLimited":"A recent request already exists. Do not create a duplicate; refresh to review it.","temporaryFailure":"The operation could not be verified. Check request status before trying again.","reviewExpiryWaitingDesc":"Your review was recorded. Its request window ended, but the record is preserved while the system verifies its prerequisites.","reviewReceivedTitle":"Your review is recorded — no further action now","reviewExpiredWaiting":"The review request window has ended, but your record is retained. Original313 predecessor verification and independent Owner signing must be completed by the system before a new request is needed.","technicalPendingHelp":"The next work is with the independent authority, not another WordPress review. This screen cannot issue a signature or enable write access.","copyExactConfirmation":"Copy exact confirmation","exactConfirmationHint":"Exact confirmation (technical request only; not an Owner signature):","exactConfirmationCopied":"Exact confirmation copied. This is not a signature or Write approval.","exactConfirmationFallback":"Clipboard unavailable. The text has been selected in the confirmation field; press Ctrl+C.","reviewAlreadyRecordedNoResubmit":"This review is already recorded. Do not submit another request; independent verification is still pending."},"fr":{"eyebrow":"Tours.dev · Préproduction","panelTitle":"Autorisation d’écriture permanente","intro":"L’écriture permanente est désactivée. Le système attend la vérification des autorisations précédentes et la signature du propriétaire indépendant.","progress":"Progression de l’approbation","identityTitle":"Identité du site","identityDesc":"Tours.dev et ses clés de vérification sont confirmés.","reviewTitle":"Demande de vérification du propriétaire","reviewInitial":"L’état de votre demande s’affiche ici.","historyTitle":"Vérifier les autorisations précédentes","historyDesc":"Le système doit vérifier la dernière approbation originale auprès de l’autorité indépendante.","signatureTitle":"Signature et accès en écriture","signatureDesc":"En attente de la signature indépendante du propriétaire avant d’accorder l’accès en écriture contrôlé.","actionHeading":"Quelle action est nécessaire ?","copyId":"Copier l’identifiant de demande","help":"Aucun JSON ni SSH nécessaire. Enregistrer une demande ne signe pas l’approbation et n’active pas l’écriture.","advancedTitle":"Détails techniques pour les administrateurs (facultatif)","advancedDesc":"Les preuves signées et les requêtes REST sont réservées aux techniciens, pas au propriétaire.","pageHeading":"Accès en écriture permanent — Host Authority","reviewRecorded":"Demande de vérification enregistrée","reviewRecordedDesc":"Votre demande est enregistrée, mais ce n’est pas une approbation signée.","existingTitle":"Votre demande existe déjà","currentExpires":"La demande actuelle expire le : ","noDuplicate":"Inutile de créer une autre demande maintenant.","reviewExpired":"Demande de vérification expirée","renewDesc":"Vous pouvez renouveler la demande ; l’approbation indépendante reste nécessaire.","newDesc":"Vous pouvez enregistrer une demande sans créer de signature.","renewHeading":"Renouveler la demande","requestHeading":"Enregistrer une demande de vérification","offerNeeded":"Une signature indépendante est nécessaire après l’enregistrement.","missingPrereqs":"Le signataire indépendant et les autorisations précédentes ne sont pas prêts. La demande n’active pas l’écriture.","renewButton":"Renouveler la demande","requestButton":"Enregistrer la demande","requesting":"Enregistrement de la demande (sans approbation)…","missingPlan":"Aucun plan de vérification validé pour ce site.","missingId":"L’identifiant n’a pas pu être vérifié. Consultez les journaux.","requestSuccess":"Demande enregistrée. Aucune autorisation signée ni accès en écriture accordé.","requestFailed":"Aucune nouvelle demande enregistrée : ","copySuccess":"Identifiant copié (pas les données de signature).","copyFailure":"Copie impossible. L’administrateur peut retrouver l’identifiant dans le journal.","offerHelp":"Un certificat initial signé est disponible. La signature indépendante du propriétaire reste nécessaire.","refreshFailed":"Impossible d’actualiser l’état. L’accès en écriture reste non confirmé ; rechargez la page.","dateUnknown":"Inconnu","invalidResponse":"Impossible de lire la réponse du site. Rechargez la page.","connectionFailed":"Connexion échouée. Réessayez.","accessibilityLabel":"État de l’autorisation d’écriture permanente","requestDenied":"Votre session ne permet pas cette demande ou a expiré. Actualisez la page et reconnectez-vous si nécessaire.","reviewUnavailable":"La demande n’est pas disponible pour le moment. Vérifiez l’état ou actualisez.","requestRateLimited":"Une demande récente existe déjà. Évitez un doublon ; actualisez pour la consulter.","temporaryFailure":"Le résultat n’a pas pu être vérifié. Consultez l’état avant de réessayer.","reviewExpiryWaitingDesc":"Votre demande a été enregistrée. Son délai est expiré, mais elle reste conservée en attendant les vérifications.","reviewReceivedTitle":"Votre demande est enregistrée — aucune autre action pour le moment","reviewExpiredWaiting":"Le délai de la demande est terminé, mais votre enregistrement est conservé. Le système doit d’abord vérifier Original313 et la signature indépendante du propriétaire.","technicalPendingHelp":"La suite dépend de l’autorité indépendante, pas d’une autre demande WordPress. Cette page ne peut ni signer ni activer l’écriture.","copyExactConfirmation":"Copier la confirmation exacte","exactConfirmationHint":"Confirmation exacte (demande technique uniquement, pas une signature du propriétaire) :","exactConfirmationCopied":"Confirmation copiée. Ce n’est ni une signature ni une autorisation d’écriture.","exactConfirmationFallback":"Presse-papiers indisponible. Le texte est sélectionné ; appuyez sur Ctrl+C.","reviewAlreadyRecordedNoResubmit":"Cette demande est déjà enregistrée. Ne renvoyez pas de demande ; la vérification indépendante reste en attente."},"de":{"eyebrow":"Tours.dev · Staging-Umgebung","panelTitle":"Freigabe dauerhafter Schreibrechte","intro":"Dauerhafte Schreibrechte sind nicht aktiviert. Es fehlen die Prüfung früherer Freigaben und die unabhängige Unterschrift des Eigentümers.","progress":"Status der Freigabe","identityTitle":"Website-Identität","identityDesc":"Tours.dev und die Prüfschlüssel sind bestätigt.","reviewTitle":"Prüfanfrage des Eigentümers","reviewInitial":"Hier erscheint der Status Ihrer Anfrage.","historyTitle":"Frühere Freigaben prüfen","historyDesc":"Das System muss die letzte Originalfreigabe bei der unabhängigen Stelle prüfen.","signatureTitle":"Unterschrift und Schreibzugriff","signatureDesc":"Die unabhängige Unterschrift des Eigentümers ist nötig, bevor Schreibrechte vergeben werden.","actionHeading":"Was ist jetzt zu tun?","copyId":"Anfrage-ID kopieren","help":"Kein JSON oder SSH nötig. Eine Anfrage ist keine unterschriebene Freigabe und aktiviert keine Schreibrechte.","advancedTitle":"Technische Details für Administratoren (optional)","advancedDesc":"Signierte Nachweise und REST-Anfragen sind für Techniker, nicht für den Eigentümer.","pageHeading":"Dauerhafter Schreibzugriff — Host Authority","reviewRecorded":"Prüfanfrage erfasst","reviewRecordedDesc":"Die Anfrage ist erfasst, aber noch keine signierte Freigabe.","existingTitle":"Ihre Anfrage liegt bereits vor","currentExpires":"Die aktuelle Anfrage läuft ab am: ","noDuplicate":"Sie müssen jetzt keine weitere Anfrage erstellen.","reviewExpired":"Prüfanfrage abgelaufen","renewDesc":"Sie können erneuern; die unabhängige Freigabe bleibt erforderlich.","newDesc":"Sie können eine Anfrage erfassen, ohne eine Unterschrift zu erstellen.","renewHeading":"Prüfanfrage erneuern","requestHeading":"Prüfanfrage des Eigentümers erfassen","offerNeeded":"Nach der Erfassung ist eine unabhängige Unterschrift nötig.","missingPrereqs":"Signaturdienst und frühere Freigaben sind noch nicht bereit. Eine Anfrage aktiviert keine Schreibrechte.","renewButton":"Anfrage erneuern","requestButton":"Anfrage erfassen","requesting":"Anfrage wird erfasst (keine Freigabe)…","missingPlan":"Kein verifizierter Prüfplan für diese Website verfügbar.","missingId":"Die Anfrage-ID konnte nicht bestätigt werden. Prüfen Sie die Protokolle.","requestSuccess":"Anfrage erfasst. Es wurden keine signierten Schreibrechte erteilt.","requestFailed":"Keine neue Anfrage erfasst: ","copySuccess":"Anfrage-ID kopiert (keine Signaturdaten).","copyFailure":"Kopieren fehlgeschlagen. Administratoren finden die ID im Protokoll.","offerHelp":"Ein erstes signiertes Zertifikat liegt vor. Die unabhängige Unterschrift des Eigentümers fehlt noch.","refreshFailed":"Status konnte nicht geladen werden. Schreibrechte sind nicht bestätigt; Seite neu laden.","dateUnknown":"Unbekannt","invalidResponse":"Website-Antwort nicht lesbar. Seite neu laden.","connectionFailed":"Verbindung fehlgeschlagen. Bitte erneut versuchen.","accessibilityLabel":"Status der dauerhaften Schreibfreigabe","requestDenied":"Ihre Sitzung ist nicht berechtigt oder abgelaufen. Seite aktualisieren und bei Bedarf erneut anmelden.","reviewUnavailable":"Die Anfrage ist noch nicht verfügbar. Status prüfen oder Seite aktualisieren.","requestRateLimited":"Eine aktuelle Anfrage existiert bereits. Keine doppelte Anfrage senden; Seite aktualisieren.","temporaryFailure":"Das Ergebnis konnte nicht überprüft werden. Status vor erneutem Versuch prüfen.","reviewExpiryWaitingDesc":"Ihre Anfrage ist erfasst. Das Zeitfenster ist abgelaufen, der Eintrag bleibt bis zur Systemprüfung erhalten.","reviewReceivedTitle":"Ihre Anfrage ist erfasst — derzeit ist keine weitere Aktion nötig","reviewExpiredWaiting":"Die Anfragefrist ist abgelaufen, der Nachweis bleibt erhalten. Zuerst müssen Original313 und die unabhängige Unterschrift des Eigentümers geprüft werden.","technicalPendingHelp":"Als Nächstes ist die unabhängige Stelle zuständig, nicht eine weitere WordPress-Anfrage. Diese Seite kann keine Signatur oder Schreibrechte erteilen.","copyExactConfirmation":"Exakte Bestätigung kopieren","exactConfirmationHint":"Exakte Bestätigung (nur technische Anfrage, keine Eigentümersignatur):","exactConfirmationCopied":"Bestätigung kopiert. Keine Signatur und keine Schreibfreigabe.","exactConfirmationFallback":"Zwischenablage nicht verfügbar. Text ist markiert; Strg+C drücken.","reviewAlreadyRecordedNoResubmit":"Diese Anfrage ist bereits erfasst. Nicht erneut senden; unabhängige Prüfung steht aus."},"es":{"eyebrow":"Tours.dev · Entorno de pruebas","panelTitle":"Autorización de escritura permanente","intro":"La escritura permanente está desactivada. Faltan verificar las autorizaciones anteriores y obtener la firma independiente del propietario.","progress":"Progreso de la aprobación","identityTitle":"Identidad del sitio","identityDesc":"Tours.dev y sus claves de verificación están confirmados.","reviewTitle":"Solicitud de revisión del propietario","reviewInitial":"Aquí aparece el estado de su solicitud.","historyTitle":"Verificar autorizaciones anteriores","historyDesc":"El sistema debe verificar la última autorización original ante la autoridad independiente.","signatureTitle":"Firma y acceso de escritura","signatureDesc":"Se necesita la firma independiente del propietario antes de conceder escritura controlada.","actionHeading":"¿Qué necesitas hacer ahora?","copyId":"Copiar ID de solicitud","help":"No necesitas JSON ni SSH. Registrar una solicitud no supone una firma ni activa la escritura.","advancedTitle":"Detalles técnicos para administradores (opcional)","advancedDesc":"Las pruebas firmadas y solicitudes REST son para técnicos, no campos para el propietario.","pageHeading":"Acceso de escritura permanente — Host Authority","reviewRecorded":"Solicitud de revisión registrada","reviewRecordedDesc":"La solicitud está registrada, pero aún no es una aprobación firmada.","existingTitle":"Tu solicitud ya existe","currentExpires":"La solicitud actual vence el: ","noDuplicate":"No necesitas crear otra solicitud ahora.","reviewExpired":"Solicitud de revisión caducada","renewDesc":"Puedes renovar la solicitud; sigue siendo necesaria la aprobación independiente.","newDesc":"Puedes registrar una solicitud sin generar una firma.","renewHeading":"Renovar solicitud de revisión","requestHeading":"Registrar revisión del propietario","offerNeeded":"Se requiere una firma independiente después de registrar la solicitud.","missingPrereqs":"El firmante independiente y las autorizaciones anteriores no están listos. La solicitud no activa la escritura.","renewButton":"Renovar solicitud","requestButton":"Registrar solicitud","requesting":"Registrando la solicitud (sin aprobación)…","missingPlan":"No hay un plan de revisión verificado para este sitio.","missingId":"No se pudo verificar el ID. Revisa los registros.","requestSuccess":"Solicitud registrada. No hay aprobación firmada ni acceso de escritura.","requestFailed":"No se registró una nueva solicitud: ","copySuccess":"ID copiado (no datos de firma).","copyFailure":"No se pudo copiar. El administrador puede ver el ID en el registro.","offerHelp":"Hay un certificado inicial firmado. Aún hace falta la firma independiente del propietario.","refreshFailed":"No se pudo actualizar el estado. El acceso de escritura sigue sin confirmar; recarga la página.","dateUnknown":"Desconocido","invalidResponse":"No se pudo leer la respuesta. Recarga la página.","connectionFailed":"Falló la conexión. Inténtalo de nuevo.","accessibilityLabel":"Estado de autorización de escritura permanente","requestDenied":"Tu sesión no tiene permiso o ha caducado. Actualiza la página y vuelve a iniciar sesión si es necesario.","reviewUnavailable":"La solicitud aún no está disponible. Consulta el estado o actualiza la página.","requestRateLimited":"Ya existe una solicitud reciente. Evita duplicarla; actualiza para consultarla.","temporaryFailure":"No se pudo verificar el resultado. Consulta el estado antes de reintentar.","reviewExpiryWaitingDesc":"Tu solicitud quedó registrada. Su plazo terminó, pero el registro se conserva mientras el sistema verifica los requisitos.","reviewReceivedTitle":"Tu revisión está registrada — no necesitas hacer nada más ahora","reviewExpiredWaiting":"El plazo terminó, pero tu registro se conserva. Primero el sistema debe verificar Original313 y la firma independiente del propietario.","technicalPendingHelp":"El siguiente paso corresponde a la autoridad independiente, no a otra solicitud de WordPress. Esta página no puede firmar ni activar la escritura.","copyExactConfirmation":"Copiar confirmación exacta","exactConfirmationHint":"Confirmación exacta (solo solicitud técnica; no es firma del propietario):","exactConfirmationCopied":"Confirmación copiada. No es una firma ni una autorización de escritura.","exactConfirmationFallback":"Portapapeles no disponible. Texto seleccionado; pulsa Ctrl+C.","reviewAlreadyRecordedNoResubmit":"Esta revisión ya está registrada. No envíes otra solicitud; la verificación independiente sigue pendiente."},"it":{"eyebrow":"Tours.dev · Ambiente di test","panelTitle":"Autorizzazione di scrittura permanente","intro":"La scrittura permanente non è attiva. Sono necessarie la verifica delle autorizzazioni precedenti e la firma indipendente del proprietario.","progress":"Avanzamento dell’autorizzazione","identityTitle":"Identità del sito","identityDesc":"Tours.dev e le relative chiavi di verifica sono confermati.","reviewTitle":"Richiesta di verifica del proprietario","reviewInitial":"Qui compare lo stato della richiesta.","historyTitle":"Verificare le autorizzazioni precedenti","historyDesc":"Il sistema deve verificare l’ultima autorizzazione originale con l’autorità indipendente.","signatureTitle":"Firma e accesso in scrittura","signatureDesc":"Serve la firma indipendente del proprietario prima del permesso di scrittura controllato.","actionHeading":"Cosa devi fare adesso?","copyId":"Copia ID richiesta","help":"Non servono JSON o SSH. La richiesta non costituisce una firma e non abilita la scrittura.","advancedTitle":"Dettagli tecnici per amministratori (facoltativo)","advancedDesc":"Le prove firmate e le richieste REST sono per i tecnici, non campi da compilare per il proprietario.","pageHeading":"Accesso di scrittura permanente — Host Authority","reviewRecorded":"Richiesta di verifica registrata","reviewRecordedDesc":"La richiesta è registrata, ma non è ancora un’autorizzazione firmata.","existingTitle":"La tua richiesta esiste già","currentExpires":"La richiesta attuale scade il: ","noDuplicate":"Non serve creare un’altra richiesta ora.","reviewExpired":"Richiesta di verifica scaduta","renewDesc":"Puoi rinnovare la richiesta; serve comunque l’approvazione indipendente.","newDesc":"Puoi registrare la richiesta senza creare una firma.","renewHeading":"Rinnova richiesta di verifica","requestHeading":"Registra una richiesta di verifica","offerNeeded":"Serve una firma indipendente dopo la registrazione.","missingPrereqs":"Il firmatario indipendente e le autorizzazioni precedenti non sono pronti. La richiesta non abilita la scrittura.","renewButton":"Rinnova richiesta","requestButton":"Registra richiesta","requesting":"Registrazione richiesta (senza approvazione)…","missingPlan":"Nessun piano di verifica convalidato per questo sito.","missingId":"Impossibile verificare l’ID. Controlla i registri.","requestSuccess":"Richiesta registrata. Nessuna approvazione firmata né accesso in scrittura.","requestFailed":"Nessuna nuova richiesta registrata: ","copySuccess":"ID copiato (non i dati di firma).","copyFailure":"Copia non riuscita. L’amministratore può vedere l’ID nel registro.","offerHelp":"È disponibile un certificato iniziale firmato. Serve ancora la firma indipendente del proprietario.","refreshFailed":"Impossibile aggiornare lo stato. L’accesso in scrittura non è confermato; ricarica la pagina.","dateUnknown":"Sconosciuto","invalidResponse":"Impossibile leggere la risposta. Ricarica la pagina.","connectionFailed":"Connessione non riuscita. Riprova.","accessibilityLabel":"Stato dell’autorizzazione di scrittura permanente","requestDenied":"La sessione non è autorizzata o è scaduta. Aggiorna la pagina e accedi di nuovo se necessario.","reviewUnavailable":"La richiesta non è ancora disponibile. Verifica lo stato o aggiorna la pagina.","requestRateLimited":"Esiste già una richiesta recente. Non duplicarla; aggiorna per visualizzarla.","temporaryFailure":"Impossibile verificare il risultato. Controlla lo stato prima di riprovare.","reviewExpiryWaitingDesc":"La richiesta è registrata. Il periodo è scaduto, ma il registro resta conservato durante le verifiche.","reviewReceivedTitle":"La revisione è registrata — per ora non devi fare altro","reviewExpiredWaiting":"Il periodo è terminato, ma il registro resta conservato. Prima il sistema deve verificare Original313 e la firma indipendente del proprietario.","technicalPendingHelp":"Il passo successivo spetta all’autorità indipendente, non a una nuova richiesta WordPress. Questa schermata non può firmare né abilitare la scrittura.","copyExactConfirmation":"Copia la conferma esatta","exactConfirmationHint":"Conferma esatta (solo richiesta tecnica; non è una firma del proprietario):","exactConfirmationCopied":"Conferma copiata. Non è una firma né un’autorizzazione alla scrittura.","exactConfirmationFallback":"Appunti non disponibili. Testo selezionato; premi Ctrl+C.","reviewAlreadyRecordedNoResubmit":"La revisione è già registrata. Non inviare un’altra richiesta; la verifica indipendente è ancora in corso."}};
const tr = key => strings[language][key] || strings.en[key] || key;
function elm(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (typeof text === "string") el.textContent = text;
    return el;
}
function formatDate(unix) {
    try {return new Intl.DateTimeFormat(intlLocale, {
        dateStyle:"medium",timeStyle:"short"
    }).format(new Date(unix * 1000));}
    catch(e){return tr("dateUnknown");}
}
async function api(path, body) {
    const response = await fetch(settings.restBase + path, {
        method:body ? "POST":"GET",
        credentials:"same-origin",
        cache:"no-store",
        headers:{
            "Accept":"application/json",
            "X-WP-Nonce":settings.restNonce,
            ...(body ? {"Content-Type":"application/json"}:{})
        },
        ...(body ? {body:JSON.stringify(body)}:{})
    });
    let data;
    try {data = await response.json();}
    catch(e){throw new Error(tr("invalidResponse"));}
    if (!response.ok) {
        // WordPress REST error text is generally English. Localize the Owner
        // explanation while retaining a short technical code for support.
        const key = response.status === 403 ? "requestDenied"
            : response.status === 409 ? "reviewUnavailable"
            : response.status === 429 ? "requestRateLimited"
            : response.status === 503 ? "temporaryFailure" : "connectionFailed";
        const code = typeof data.code === "string"
            && /^[a-z0-9_-]{1,80}$/i.test(data.code) ? " [" + data.code + "]" : "";
        throw new Error(tr(key) + code);
    }
    return data;
}
function init() {
    const page = document.querySelector(".mad4b-host-v3");
    const layout = page && page.querySelector(".v3-layout");
    if (!page || !layout || !layout.children[0]) return;
    const main = layout.children[0];
    const pane = elm("section","mad4b-human");
    pane.dir = language === "ar" ? "rtl" : "ltr";
    pane.setAttribute("lang", intlLocale);
    pane.setAttribute("aria-label",tr("accessibilityLabel"));
    pane.append(elm("p","human-eyebrow",tr("eyebrow")));
    pane.append(elm("h2","", tr("panelTitle")));
    const intro = elm("p","human-muted",
        tr("intro"));
    pane.append(intro);
    pane.append(elm("h3","", tr("progress")));
    const steps = elm("ol","human-steps");
    function step(mark,label,description,done) {
        const li=elm("li");
        const m=elm("span","human-marker"+(done?" is-done":""),mark);
        m.setAttribute("aria-hidden","true");
        const content=elm("div");
        content.append(elm("div","human-step-label",label));
        content.append(elm("div","human-step-desc",description));
        li.append(m,content);
        steps.append(li);
        return {label:content.firstChild,description:content.lastChild,marker:m};
    }
    step("✓",tr("identityTitle"), tr("identityDesc"),true);
    const reviewStep=step("2",tr("reviewTitle"),tr("reviewInitial"),false);
    step("3",tr("historyTitle"),
        tr("historyDesc"),false);
    step("4",tr("signatureTitle"),
        tr("signatureDesc"),false);
    pane.append(steps);
    const actions=elm("div","human-actions");
    const actionTitle=elm("h3","", tr("actionHeading"));
    actions.append(actionTitle);
    const status=elm("p","human-status");
    status.setAttribute("role","status");
    status.setAttribute("aria-live","polite");
    const primary=elm("button","button button-primary");
    primary.type="button";
    const secondary=elm("button","button");
    secondary.type="button";
    secondary.style.marginInlineStart="8px";
    secondary.textContent=tr("copyId");
    secondary.hidden=true;
    const help=elm("p","human-muted",
        tr("help"));
    actions.append(primary,secondary,status,help);
    pane.append(actions);
    const advanced=elm("details","human-detail");
    advanced.append(elm("summary","", tr("advancedTitle")));
    const advancedBody=elm("div","human-extra");
    advancedBody.append(elm("p","human-muted",
        tr("advancedDesc")));
    advanced.append(advancedBody);
    pane.append(advanced);
    main.prepend(pane);

    // Move legacy v134/v135 cards under optional technical details *after*
    // the replacement panel has been rendered. Keep their event handlers
    // intact; only the duplicated legacy action is no longer primary.
    const oldCards=Array.from(layout.querySelectorAll(".v3-card"))
        .filter(card=>!pane.contains(card));
    for (const card of oldCards) advancedBody.append(card);
    // The v135 card contains a *second* Create review request button.
    // The sole reviewed request now lives in v136's canonical flow.
    // Keep v135's signed-proof intake available when Original313 actually
    // publishes a verified offer; never leave duplicate submits actionable.
    const remoteCard=oldCards.find(card=>{
        if(typeof card.querySelector!=="function")return false;
        const h=card.querySelector("h2");
        return h && h.textContent==="Remote Owner Step-up";
    }) || null;
    if(remoteCard) {
        const remoteButtons=typeof remoteCard.querySelectorAll==="function"
            ? Array.from(remoteCard.querySelectorAll("button")) : [];
        for(const b of remoteButtons.slice(0,2)){
            b.hidden=true;b.style.display="none";b.disabled=true;
            b.setAttribute("aria-hidden","true");
        }
        remoteCard.hidden=true;
        remoteCard.style.display="none";
    }
    // v134 uses the exact protocol phrase as an input placeholder. A
    // placeholder is not selectable; render an explicit copyable value
    // alongside the original, keeping the signed request protocol intact.
    // The source is the v134-owned field, not another hardcoded value.
    const exactInput = typeof document.getElementById === "function"
        ? document.getElementById("v3-confirm") : null;
    if (!(typeof document.getElementById === "function" &&
        document.getElementById("mad4b-owner-copy-ssr-v140")) &&
        exactInput && typeof exactInput.placeholder === "string"
        && exactInput.placeholder.length > 0) {
        const exact = exactInput.placeholder;
        const copyArea=elm("div","human-exact-copy");
        const hint=elm("p","human-muted",tr("exactConfirmationHint"));
        const readable=elm("code","human-exact-readable",exact);
        readable.dir="ltr";
        const copyButton=elm("button","button",tr("copyExactConfirmation"));
        copyButton.type="button";
        const copyStatus=elm("p","human-status");
        copyStatus.setAttribute("role","status");
        copyStatus.setAttribute("aria-live","polite");
        copyButton.addEventListener("click",async ()=>{
            try {
                await navigator.clipboard.writeText(exact);
                copyStatus.textContent=tr("exactConfirmationCopied");
            } catch(error) {
                // Browser clipboard policies vary. Select a real input
                // so the owner can use native Ctrl+C without shell/JSON.
                exactInput.value=exact;
                if (typeof exactInput.focus === "function") exactInput.focus();
                if (typeof exactInput.select === "function") exactInput.select();
                copyStatus.textContent=tr("exactConfirmationFallback");
            }
        });
        copyArea.append(hint,readable,copyButton,copyStatus);
        // Place immediately after the legacy input; do not relocate its
        // event handlers or silently pre-submit any approval.
        if (exactInput.parentNode &&
            typeof exactInput.parentNode.insertBefore === "function") {
            exactInput.parentNode.insertBefore(copyArea,exactInput.nextSibling);
        }
        if (settings.latestReview &&
            typeof settings.latestReview.request_id === "string") {
            const duplicateSubmit=typeof document.getElementById === "function"
                ? document.getElementById("v3-submit") : null;
            if (duplicateSubmit) {
                // A previously recorded review has no reason to be
                // re-issued through the v134 legacy submission route.
                duplicateSubmit.hidden=true;
                duplicateSubmit.style.display="none";
                duplicateSubmit.disabled=true;
                duplicateSubmit.setAttribute("aria-hidden","true");
            }
            copyArea.append(elm("p","human-muted",tr("reviewAlreadyRecordedNoResubmit")));
        }
    }
    layout.style.display="block";
    if (layout.children[1]) layout.children[1].hidden=true;
    const heading=page.querySelector("h1");
    if (heading) heading.textContent=tr("pageHeading");
    const leading=page.querySelector(":scope > p");
    if (leading) leading.hidden=true;

    let review=settings.latestReview;
    let rootOffer=false;
    // A WordPress .button rule can override the browser UA [hidden] rule.
    // Synchronize the attribute, the inline display and the accessible
    // disabled state, with an author-style [hidden] guard as defense in depth.
    function showPrimary(show,label) {
        primary.hidden=!show;
        primary.style.display=show?"inline-flex":"none";
        primary.disabled=!show;
        primary.textContent=show?label:"";
        primary.setAttribute("aria-hidden",show?"false":"true");
        secondary.style.marginInlineStart=show?"8px":"0";
    }
    function showSecondary(show) {
        secondary.hidden=!show;
        secondary.style.display=show?"inline-flex":"none";
        secondary.disabled=!show;
        secondary.setAttribute("aria-hidden",show?"false":"true");
    }
    function renderReview() {
        const hasReview=!!(review && typeof review.request_id==="string");
        const active=hasReview && typeof review.expires_epoch==="number"
            && review.expires_epoch >= Math.floor(Date.now()/1000);
        showSecondary(hasReview);
        if (active) {
            reviewStep.marker.textContent="✓";
            reviewStep.marker.classList.add("is-done");
            reviewStep.label.textContent=tr("reviewRecorded");
            reviewStep.description.textContent=tr("reviewRecordedDesc");
            actionTitle.textContent=tr("existingTitle");
            status.textContent=tr("currentExpires")+formatDate(review.expires_epoch)
                +". "+tr("noDuplicate");
            showPrimary(false,"");
        } else if (hasReview && !rootOffer) {
            // Request expiry is not a reason to ask the Owner to repeat
            // work while no independent Original313 offer is ready.
            reviewStep.marker.textContent="✓";
            reviewStep.marker.classList.add("is-done");
            reviewStep.label.textContent=tr("reviewRecorded");
            reviewStep.description.textContent=tr("reviewExpiryWaitingDesc");
            actionTitle.textContent=tr("reviewReceivedTitle");
            status.textContent=tr("reviewExpiredWaiting");
            help.textContent=tr("technicalPendingHelp");
            showPrimary(false,"");
        } else {
            reviewStep.marker.textContent="2";
            reviewStep.marker.classList.remove("is-done");
            reviewStep.label.textContent=hasReview?tr("reviewExpired"):tr("reviewTitle");
            reviewStep.description.textContent=hasReview?tr("renewDesc"):tr("newDesc");
            actionTitle.textContent=hasReview?tr("renewHeading"):tr("requestHeading");
            status.textContent=rootOffer?tr("offerNeeded"):tr("missingPrereqs");
            showPrimary(true,hasReview?tr("renewButton"):tr("requestButton"));
        }
    }
    primary.addEventListener("click",async ()=>{
        primary.disabled=true;
        status.textContent=tr("requesting");
        try {
            const plan=await api("/review-plan");
            if (!plan || typeof plan.plan_sha256!=="string") {
                throw new Error(tr("missingPlan"));
            }
            const result=await api("/remote-requests",{
                expected_plan_sha256:plan.plan_sha256,
                confirmation:"REQUEST REMOTE V3 OWNER REVIEW"
            });
            if (!result.review || typeof result.review.request_id!=="string") {
                throw new Error(tr("missingId"));
            }
            review=result.review;
            renderReview();
            status.textContent=tr("requestSuccess");
        } catch (error) {
            renderReview();
            status.textContent=tr("requestFailed")+error.message;
        } finally {if (!primary.hidden) primary.disabled=false;}
    });
    secondary.addEventListener("click",async ()=>{
        if (!review || typeof review.request_id!=="string") return;
        try {
            await navigator.clipboard.writeText(review.request_id);
            status.textContent=tr("copySuccess");
        } catch (error) {
            status.textContent=tr("copyFailure");
        }
    });
    renderReview();
    api("/remote-status").then(result=>{
        rootOffer=result.signed_root_offer_present===true;
        if(remoteCard){
            remoteCard.hidden=!rootOffer;
            remoteCard.style.display=rootOffer?"block":"none";
        }
        if (!review || review.expires_epoch < Date.now()/1000) renderReview();
        if (rootOffer) {
            help.textContent=tr("offerHelp");
        }
    }).catch(()=>{
        help.textContent=tr("refreshFailed");
    });
}
if (document.readyState==="loading") {
    document.addEventListener("DOMContentLoaded",init);
} else {
    init();
}
})();
</script>
<?php
    }
}
MAD4B_Host_V3_Human_UI::boot();
}
