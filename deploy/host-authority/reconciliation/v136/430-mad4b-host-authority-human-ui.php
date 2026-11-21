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
        );
        $json = wp_json_encode($bootstrap,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return;
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
.mad4b-host-v3 .mad4b-human .human-actions .button {min-height:36px}
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
<script id="mad4b-host-v136-human-script">
(function() {
"use strict";
const settings = <?php echo $json; ?>;
function elm(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (typeof text === "string") el.textContent = text;
    return el;
}
function formatDate(unix) {
    try {return new Intl.DateTimeFormat("ar-EG", {
        dateStyle:"medium",timeStyle:"short"
    }).format(new Date(unix * 1000));}
    catch(e){return "غير معروف";}
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
    catch(e){throw new Error("تعذّر قراءة استجابة الموقع. جرّب تحديث الصفحة.");}
    if (!response.ok) throw new Error(
        typeof data.message === "string" ?
        data.message : "لم ينجح الاتصال. يرجى إعادة المحاولة."
    );
    return data;
}
function init() {
    const page = document.querySelector(".mad4b-host-v3");
    const layout = page && page.querySelector(".v3-layout");
    if (!page || !layout || !layout.children[0]) return;
    const main = layout.children[0];
    const pane = elm("section","mad4b-human");
    pane.dir = "rtl";
    pane.setAttribute("aria-label","حالة اعتماد الكتابة الدائمة");
    pane.append(elm("p","human-eyebrow","Tours.dev · بيئة الاختبار"));
    pane.append(elm("h2","", "اعتماد صلاحية الكتابة الدائمة"));
    const intro = elm("p","human-muted",
        "صلاحية الكتابة غير مفعّلة حاليًا. النظام ينتظر استكمال سجل الاعتمادات السابقة ثم توقيع المالك من الجهة المستقلة.");
    pane.append(intro);
    pane.append(elm("h3","", "إحنا وصلنا لفين؟"));
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
    step("✓","هوية الموقع", "تم التحقق من موقع Tours.dev ومفاتيح التحقق.",true);
    const reviewStep=step("2","طلب مراجعة المالك","هنعرض حالة طلبك هنا.",false);
    step("3","مراجعة سجل الموافقات السابقة",
        "المطلوب من النظام: إثبات أحدث اعتماد أصلي من الجهة المستقلة.",false);
    step("4","التوقيع وتفعيل الكتابة",
        "في انتظار جهة توقيع المالك، ثم يتم إصدار صلاحية كتابة محكومة.",false);
    pane.append(steps);
    const actions=elm("div","human-actions");
    const actionTitle=elm("h3","", "إيه المطلوب منك دلوقتي؟");
    actions.append(actionTitle);
    const status=elm("p","human-status");
    status.setAttribute("role","status");
    status.setAttribute("aria-live","polite");
    const primary=elm("button","button button-primary");
    primary.type="button";
    const secondary=elm("button","button");
    secondary.type="button";
    secondary.style.marginInlineStart="8px";
    secondary.textContent="نسخ رقم الطلب";
    secondary.hidden=true;
    const help=elm("p","human-muted",
        "لن تحتاج إلى كتابة JSON أو استخدام SSH. تسجيل الطلب لا يعني صدور توقيع أو تفعيل الكتابة.");
    actions.append(primary,secondary,status,help);
    pane.append(actions);
    const advanced=elm("details","human-detail");
    advanced.append(elm("summary","", "تفاصيل فنية للمسؤول التقني (اختياري)"));
    const advancedBody=elm("div","human-extra");
    advancedBody.append(elm("p","human-muted",
        "البيانات الموقّعة وطلبات REST مخصصة لفريق التشغيل فقط، وليست حقولًا مطلوبة من المالك."));
    advanced.append(advancedBody);
    pane.append(advanced);
    main.prepend(pane);

    // Move legacy v134/v135 cards under optional technical details *after*
    // the replacement panel has been rendered. Keep their event handlers
    // intact; only the duplicated legacy action is no longer primary.
    const oldCards=Array.from(layout.querySelectorAll(".v3-card"))
        .filter(card=>!pane.contains(card));
    for (const card of oldCards) advancedBody.append(card);
    layout.style.display="block";
    if (layout.children[1]) layout.children[1].hidden=true;
    const heading=page.querySelector("h1");
    if (heading) heading.textContent="صلاحية الكتابة الدائمة — Host Authority";
    const leading=page.querySelector(":scope > p");
    if (leading) leading.hidden=true;

    let review=settings.latestReview;
    let rootOffer=false;
    function renderReview() {
        const active=review && typeof review.expires_epoch==="number"
            && review.expires_epoch >= Math.floor(Date.now()/1000);
        secondary.hidden=!(review && typeof review.request_id==="string");
        if (review && active) {
            reviewStep.marker.textContent="✓";
            reviewStep.marker.classList.add("is-done");
            reviewStep.label.textContent="تم تسجيل طلب المراجعة";
            reviewStep.description.textContent=
                "الطلب مسجّل، لكن لسه مش موافقة موقّعة.";
            actionTitle.textContent="طلبك موجود بالفعل";
            status.textContent="ينتهي الطلب الحالي: "+formatDate(review.expires_epoch)
                +". لا تحتاج إلى إنشاء طلب جديد الآن.";
            primary.hidden=true;
        } else {
            reviewStep.marker.textContent="2";
            reviewStep.marker.classList.remove("is-done");
            reviewStep.label.textContent=review?"انتهت صلاحية طلب المراجعة":"طلب مراجعة المالك";
            reviewStep.description.textContent=review?
                "ممكن تجدد الطلب، لكن اعتماد النظام الأصلي ما زال مطلوبًا.":
                "يمكن تسجيل طلب مراجعة الآن، دون أي توقيع تلقائي.";
            actionTitle.textContent=review?
                "تجديد طلب المراجعة":"تسجيل طلب مراجعة المالك";
            status.textContent=rootOffer?
                "التوقيع المستقل مطلوب بعد تسجيل الطلب.":
                "جهة التوقيع وسجل الاعتمادات السابقة لم يجهزا بعد. تسجيل الطلب لا يفعّل الصلاحية.";
            primary.textContent=review?"تجديد طلب المراجعة":"تسجيل طلب مراجعة";
            primary.hidden=false;
        }
    }
    primary.addEventListener("click",async ()=>{
        primary.disabled=true;
        status.textContent="جارٍ تسجيل طلب المراجعة دون إصدار موافقة...";
        try {
            const plan=await api("/review-plan");
            if (!plan || typeof plan.plan_sha256!=="string") {
                throw new Error("لا توجد خطة مراجعة معتمدة لهذا الموقع.");
            }
            const result=await api("/remote-requests",{
                expected_plan_sha256:plan.plan_sha256,
                confirmation:"REQUEST REMOTE V3 OWNER REVIEW"
            });
            if (!result.review || typeof result.review.request_id!=="string") {
                throw new Error("لم يمكن التحقق من رقم الطلب. راجع سجلات التشغيل.");
            }
            review=result.review;
            renderReview();
            status.textContent="تم تسجيل الطلب. لا توجد موافقة موقّعة أو صلاحية كتابة حتى الآن.";
        } catch (error) {
            renderReview();
            status.textContent="لم يُسجّل طلب جديد: "+error.message;
        } finally {primary.disabled=false;}
    });
    secondary.addEventListener("click",async ()=>{
        if (!review || typeof review.request_id!=="string") return;
        try {
            await navigator.clipboard.writeText(review.request_id);
            status.textContent="تم نسخ رقم الطلب فقط، وليس بيانات التوقيع.";
        } catch (error) {
            status.textContent="تعذّر النسخ. يمكن لمسؤول النظام عرض رقم الطلب من السجل.";
        }
    });
    renderReview();
    api("/remote-status").then(result=>{
        rootOffer=result.signed_root_offer_present===true;
        if (!review || review.expires_epoch < Date.now()/1000) renderReview();
        if (rootOffer) {
            help.textContent="وصلت شهادة أولية موقّعة. استكمال توقيع المالك يتطلب جهة توقيع مستقلة؛ التفاصيل متاحة للمسؤول التقني.";
        }
    }).catch(()=>{
        help.textContent="تعذّر جلب أحدث حالة. ما زال تفعيل الكتابة غير مؤكد؛ يرجى تحديث الصفحة.";
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
