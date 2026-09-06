/* ====================================================
   Tab Switching + Background Polling
   ==================================================== */
(function($) {
    $(document).on('click', '.wpl-tab-btn', function() {
        var tab = $(this).data('tab');
        $('.wpl-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.wpl-tab-pane').hide();
        $('#wpl-tab-' + tab).show();
        if (tab === 'requests' && !window._wplReqLoaded) {
            window._wplReqLoaded = true;
            if (typeof wplLoadMyRequests === 'function') wplLoadMyRequests();
        }
    });

    // ====== Background Polling كل 30 ثانية ======
    // يشتغل دايماً في الـ background حتى لو التبويبة مش مفتوحة
    $(document).ready(function() {
        if (!document.getElementById('wpl-requests-container')) return;

        // أول polling بعد 5 ثواني من فتح الصفحة
        setTimeout(function() {
            wplSilentPoll();
        }, 5000);

        // ثم كل 30 ثانية
        setInterval(function() {
            wplSilentPoll();
        }, 30000);
    });

    // ====== Silent Poll — بيشوف في الـ background بدون ما يبيّن loading ======
    window.wplSilentPoll = function() {
        jQuery.post(WPL.ajax_url, {
            action: 'wpl_fetch_my_requests',
            nonce:  WPL.nonce,
        }, function(res) {
            if (!res || !res.success) return;
            var data = res.data || [];
            var getAI = window.getAutoInstalled || function(){ return []; };
            var autoInstalled = getAI();

            var hasPending = data.some(function(r){ return r.status === 'pending'; });
            // حدّث أيقونة التبويبة
            var dot = document.querySelector('.wpl-tab-btn[data-tab="requests"] .wpl-tab-dot');
            if (dot) dot.style.display = hasPending ? 'inline-block' : 'none';

            var needsInstall = false;
            data.forEach(function(r) {
                if (r.status === 'done' &&
                    r.matched_products && r.matched_products.length &&
                    autoInstalled.indexOf(r.id) === -1 &&
                    autoInstalled.indexOf('order_' + r.order_number) === -1) {
                    needsInstall = true;
                }
            });

            if (needsInstall) {
                // لو التبويبة مفتوحة — حدّثها عادي
                var isRequestsTabActive = document.querySelector('.wpl-tab-btn[data-tab="requests"].active');
                if (isRequestsTabActive) {
                    if (typeof wplLoadMyRequests === 'function') wplLoadMyRequests();
                } else {
                    // لو التبويبة مش مفتوحة — ثبّت في الـ background وبيّن notification
                    data.forEach(function(r) {
                        if (r.status === 'done' &&
                            r.matched_products && r.matched_products.length &&
                            autoInstalled.indexOf(r.id) === -1 &&
                            autoInstalled.indexOf('order_' + r.order_number) === -1) {
                            wplAutoInstallBackground(r);
                        }
                    });
                }
            }
        });
    };

    // ====== تثبيت في الـ background مع notification ======
    function wplAutoInstallBackground(req) {
        var matched = req.matched_products || [];
        if (!matched.length) return;

        jQuery.post(WPL.ajax_url, { action: 'wpl_fetch_products', nonce: WPL.nonce, search: '', category: '', wpl_ids: WPL.wpl_ids || '' }, function(res) {
            if (!res.success) return; // السيريال مش متحقق — التثبيت سيُعاد عند التحقق
            var products = res.data || [];
            var filesToInstall = [];

            // مطابقة بـ wpl_id مباشرة
            var matchedIds = matched.map(function(m) {
                return typeof m === 'object' && m.wpl_id != null ? String(m.wpl_id) : null;
            }).filter(Boolean);

            products.forEach(function(p) {
                if (matchedIds.indexOf(String(p.id)) === -1) return;
                var isTheme = (p.category === 'theme');
                p.files.forEach(function(f) {
                    filesToInstall.push({ filename: f.filename, is_theme: isTheme });
                });
            });

            if (!filesToInstall.length) {
                markAutoInstalled(req.id);
                return;
            }

            showInstallNotification(req.order_number, filesToInstall.length);

            function installNext(index) {
                if (index >= filesToInstall.length) {
                    markAutoInstalled(req.id);
                    updateInstallNotification(req.order_number, filesToInstall.length, true);
                    return;
                }
                var item    = filesToInstall[index];
                var isTheme = item.is_theme || false;
                updateInstallNotification(req.order_number, 0, false, index + 1, filesToInstall.length, item.filename);
                jQuery.post(WPL.ajax_url, { action: 'wpl_install_file', nonce: WPL.nonce, filename: item.filename, is_theme: isTheme ? '1' : '0' }, function(r) {
                    var pluginFile = r && r.data && r.data.plugin_file ? r.data.plugin_file : '';
                    var rIsTheme   = (r && r.data && r.data.is_theme !== undefined && r.data.is_theme !== null) ? !!r.data.is_theme : isTheme;
                    if (pluginFile) {
                        jQuery.post(WPL.ajax_url, { action: 'wpl_toggle_plugin', nonce: WPL.nonce, plugin_file: pluginFile, toggle_action: 'activate', is_theme: rIsTheme ? '1' : '0' }, function() {
                            installNext(index + 1);
                        }).fail(function() { installNext(index + 1); });
                    } else {
                        installNext(index + 1);
                    }
                }).fail(function() { installNext(index + 1); });
            }
            installNext(0);
        });
    }

    // ====== Notification Bar ======
    function showInstallNotification(orderNum, total) {
        var existing = document.getElementById('wpl-notif-' + orderNum);
        if (existing) return;

        var notif = document.createElement('div');
        notif.id = 'wpl-notif-' + orderNum;
        notif.style.cssText = 'position:fixed;bottom:24px;left:24px;z-index:99999;background:#0f1f3d;color:#fff;border-radius:14px;padding:16px 20px;min-width:320px;box-shadow:0 8px 32px rgba(0,0,0,.4);direction:rtl;font-family:inherit';
        notif.innerHTML =
            '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">' +
                '<span style="font-size:20px">⚡</span>' +
                '<div>' +
                    '<strong style="font-size:13px;display:block">تثبيت تلقائي — طلب #' + orderNum + '</strong>' +
                    '<span id="wpl-notif-sub-' + orderNum + '" style="font-size:11px;color:rgba(255,255,255,.6)">جاري التحضير...</span>' +
                '</div>' +
            '</div>' +
            '<div style="background:rgba(255,255,255,.15);border-radius:6px;overflow:hidden;height:6px">' +
                '<div id="wpl-notif-bar-' + orderNum + '" style="background:#0ea5e9;height:100%;width:0%;transition:width .4s ease"></div>' +
            '</div>';
        document.body.appendChild(notif);
    }

    function updateInstallNotification(orderNum, total, done, current, of, filename) {
        var bar  = document.getElementById('wpl-notif-bar-' + orderNum);
        var sub  = document.getElementById('wpl-notif-sub-' + orderNum);
        var wrap = document.getElementById('wpl-notif-' + orderNum);

        if (done) {
            if (bar) bar.style.width = '100%';
            if (sub) sub.textContent = '✅ تم تثبيت ' + total + ' ملف بنجاح!';
            if (wrap) setTimeout(function() { if(wrap.parentNode) wrap.parentNode.removeChild(wrap); }, 4000);
            // حدّث تبويبة الطلبات
            if (typeof wplLoadMyRequests === 'function') {
                var isRequestsTabActive = document.querySelector('.wpl-tab-btn[data-tab="requests"].active');
                if (isRequestsTabActive) wplLoadMyRequests();
            }
        } else {
            if (bar) bar.style.width = Math.round((current / of) * 100) + '%';
            if (sub) sub.textContent = '⬇️ (' + current + '/' + of + ') ' + filename;
        }
    }

})(jQuery);

(function ($) {
    'use strict';

    var allProducts = []; // cache المنتجات بعد التحميل

    /* ====== رسالة API Key قديم ====== */
window.wplShowApiKeyError = function() {
        // ✅ v2.7.4: نحوّل العميل لصفحة طلباته على السيرفر — من هناك بينزّل البلجن الجديد
        var downloadUrl = 'https://wordpresslicenses.com/profile/orders';

        var finalHtml =
            '<div id="wpl-api-key-msg" style="padding:20px 28px 24px;direction:rtl">' +
                '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">' +
                    '<span style="font-size:30px;line-height:1">⚠️</span>' +
                    '<div>' +
                        '<div style="font-size:15px;font-weight:800;color:#fca5a5;margin-bottom:3px">تحديث مطلوب</div>' +
                        '<div style="font-size:12px;color:rgba(255,255,255,.55);line-height:1.6">الإضافة الحالية غير مربوطة بحسابك — قم بتحميل نسخة جديدة من حسابك</div>' +
                    '</div>' +
                '</div>' +
                '<a href="' + downloadUrl + '"' +
                   ' style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;box-sizing:border-box;' +
                   'background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;text-decoration:none;' +
                   'border-radius:12px;padding:13px;font-size:14px;font-weight:700;margin-bottom:14px">' +
                    '⬇️ تحميل آخر إصدار' +
                '</a>' +
                '<div style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:14px">' +
                    '<div style="font-size:11px;font-weight:700;color:rgba(255,255,255,.4);letter-spacing:1px;margin-bottom:10px">📋 خطوات التحديث</div>' +
                    '<div style="display:flex;flex-direction:column;gap:7px">' +
                        '<div style="display:flex;align-items:flex-start;gap:9px;font-size:12px;color:rgba(255,255,255,.7)">' +
                            '<span style="background:#3b82f6;color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;margin-top:1px">1</span>' +
                            '<span>اضغط الزرار أعلاه وحمّل ملف الإضافة</span>' +
                        '</div>' +
                        '<div style="display:flex;align-items:flex-start;gap:9px;font-size:12px;color:rgba(255,255,255,.7)">' +
                            '<span style="background:#3b82f6;color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;margin-top:1px">2</span>' +
                            '<span>احذف الإضافة الحالية من <strong style="color:#fff">الإضافات</strong></span>' +
                        '</div>' +
                        '<div style="display:flex;align-items:flex-start;gap:9px;font-size:12px;color:rgba(255,255,255,.7)">' +
                            '<span style="background:#3b82f6;color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;margin-top:1px">3</span>' +
                            '<span>ارفع الملف الجديد من <strong style="color:#fff">الإضافات ← رفع إضافة</strong></span>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';

        function _injectInHeroCard(heroCard) {
            ['wpl-robot-fields','wpl-robot-prog','wpl-session-btn-area'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });
            var existing = document.getElementById('wpl-api-key-msg');
            if (existing) existing.remove();
            var div = document.createElement('div');
            div.innerHTML = finalHtml;
            heroCard.appendChild(div.firstChild);
            var msg = document.getElementById('wpl-api-key-msg');
            if (msg) { msg.style.opacity='0'; msg.style.transition='opacity .35s'; setTimeout(function(){ msg.style.opacity='1'; },30); }
            var bBig = document.getElementById('wpl-b-big') || document.getElementById('wpl-b-big-s');
            var bSub = document.getElementById('wpl-b-sub') || document.getElementById('wpl-b-sub-s');
            if (bBig) bBig.textContent = '⚠️ يلزم تحديث الإضافة';
            if (bSub) bSub.textContent = 'النسخة الحالية غير متوافقة مع السيرفر';
            heroCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // ① hero card (العميل العادي أو الـ session)
        var heroCard = document.getElementById('wpl-hero-card') || document.getElementById('wpl-hero-card-session');
        if (heroCard) { _injectInHeroCard(heroCard); return; }

        // ② fallback — products-container (مانيوال tab)
        var prodContainer = document.getElementById('wpl-products-container');
        if (prodContainer) {
            prodContainer.innerHTML =
                '<div style="direction:rtl;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);' +
                'border-radius:14px;padding:4px 0;max-width:480px;margin:0 auto">' +
                finalHtml.replace('id="wpl-api-key-msg"','id="wpl-api-key-msg-2"') + '</div>';
            prodContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };;

    $(document).ready(function () {
        // شغّل بس لو السيريال متحقق (gate wrapper مش موجود أو مخفي من PHP)
        var gate = document.getElementById('wpl-serial-gate-wrapper');
        if (!gate || gate.style.display === 'none') {
            wplLoadProducts();
        }

        // ===== استكمل الـ bg polling لو في job شغّال من جلسة سابقة =====
        jQuery.post(WPL.ajax_url, { action: 'wpl_get_bg_status', nonce: WPL.nonce }, function(res) {
            if (!res.success) return;
            var job = res.data || {};
            if (job.status === 'queued' || job.status === 'running') {
                // في job شغّال — استكمل الـ polling
                var targetDiv = document.getElementById('wpl-act-success')
                             || document.getElementById('wpl-act-success-session');
                var heroCard  = document.getElementById('wpl-hero-card') || document.getElementById('wpl-hero-card-session');
                if (heroCard) {
                    // أخفي الـ fields وأظهر الـ progress
                    var fields = document.getElementById('wpl-robot-fields');
                    if (fields) fields.style.display = 'none';
                }
                wplStartBgPolling(job.order_number || '', targetDiv);
                // أظهر تبويبة الطلبات
                var tabsNav = document.getElementById('wpl-tabs-nav');
                if (tabsNav) tabsNav.style.display = 'flex';
                jQuery('.wpl-tab-btn').removeClass('active');
                jQuery('.wpl-tab-btn[data-tab="requests"]').addClass('active');
                jQuery('.wpl-tab-pane').hide();
                jQuery('#wpl-tab-requests').show();
            }
        });
    });

    // expose globally so PHP inline script can call it after serial verified
    window.wplLoadProducts = function() { wplLoadProducts(); };

    /* ====== فلترة client-side فورية ====== */
    window.wplFilterProducts = function (q) {
        var clearBtn = document.getElementById('wpl-search-clear');
        if (clearBtn) clearBtn.style.display = q ? 'block' : 'none';

        var query = q.trim().toLowerCase();
        if (!query) {
            renderProducts(allProducts);
            return;
        }
        var filtered = allProducts.filter(function(p) {
            return p.name.toLowerCase().indexOf(query) !== -1 ||
                   (p.category_label && p.category_label.toLowerCase().indexOf(query) !== -1);
        });
        if (!filtered.length) {
            $('#wpl-products-container').html(
                '<div class="wpl-no-results">🔍 لا توجد نتائج لـ "<strong>' + esc(q) + '</strong>"</div>'
            );
        } else {
            renderProducts(filtered);
        }
    };

    window.wplClearSearch = function() {
        var inp = document.getElementById('wpl-search');
        if (inp) { inp.value = ''; inp.focus(); }
        var clearBtn = document.getElementById('wpl-search-clear');
        if (clearBtn) clearBtn.style.display = 'none';
        renderProducts(allProducts);
    };

    /* ====== تحميل المنتجات (مرة واحدة فقط — الفلترة client-side) ====== */
    function wplLoadProducts() {
        $('#wpl-products-container').html(
            '<div class="wpl-loading"><span class="wpl-spinner"></span> جاري التحميل...</div>'
        );

        $.post(WPL.ajax_url, {
            action: 'wpl_fetch_products',
            nonce:  WPL.nonce,
            search: '',
            wpl_ids: WPL.wpl_ids || '',
        }, function (res) {
            if (!res.success) {
                var errData = (res.data && typeof res.data === 'object') ? res.data : {};
                if (errData.api_key_invalid) { wplShowApiKeyError(); return; }
                $('#wpl-products-container').html('<div class="wpl-error">❌ ' + (errData.message || res.data || 'خطأ') + '</div>');
                return;
            }
            // الـ response الآن { products, files_changed }
            var data = res.data || {};
            var products     = Array.isArray(data) ? data : (data.products || []);
            var filesChanged = !Array.isArray(data) && data.files_changed;

            // في جلسة الدعم (wpl_session) لا يوجد wpl-tabs-nav — تجاهل filesChanged
            var isWplSession = !document.getElementById('wpl-tabs-nav');

            // لو في ملفات جديدة على السيرفر — اطلب تفعيل من الأول (للعميل العادي فقط)
            if (filesChanged && !isWplSession) {
                var gate = document.getElementById('wpl-serial-gate-wrapper');
                var tabs = document.getElementById('wpl-tabs-nav');
                if (gate) gate.style.display = 'block';
                if (tabs) tabs.style.display  = 'none';
                jQuery('.wpl-tab-pane').hide();
                // رسالة إشعار
                var serialInp = document.getElementById('wpl-serial-input');
                var errEl = document.getElementById('wpl-serial-error');
                if (errEl) {
                    errEl.textContent = '🆕 تم إضافة ملفات جديدة! أدخل السيريال ورقم الطلب لتثبيتها.';
                    errEl.style.display = 'block';
                }
                if (serialInp) serialInp.focus();
                return;
            }

            allProducts = products;

            // فلتر client-side: لو في wpl_ids محددة → أظهر بس المنتجات دي
            // (يحمي من حالة السيرفر ما يفلترش صح)
            var allowedIds = (WPL.wpl_ids || '').split(',').filter(Boolean);
            if (allowedIds.length && !isWplSession) {
                allProducts = allProducts.filter(function(p) {
                    return allowedIds.indexOf(String(p.id)) !== -1;
                });
            }

            $('#wpl-products-count').text(allProducts.length + ' منتج');
            renderProducts(allProducts);
        }).fail(function () {
            $('#wpl-products-container').html('<div class="wpl-error">❌ تعذّر الاتصال.</div>');
        });
    }

    /* ====== رسم المنتجات ====== */
    function renderProducts(products) {
        if (!products.length) {
            $('#wpl-products-container').html('<div class="wpl-empty">لا توجد نتائج.</div>');
            return;
        }

        var html = products.map(function (p) {
            var allDone = p.files.length > 0 && p.files.every(function(f) {
                return f.status === 'active' || f.status === 'installed';
            });

            var installableFiles = p.files.filter(function(f){ return f.status === 'not_installed'; });
            var footerHtml = '';
            if (installableFiles.length > 0) {
                footerHtml = '<div class="wpl-product-footer">' +
                    '<label class="wpl-activate-label">' +
                        '<input type="checkbox" class="wpl-do-activate" checked>' +
                        '<span>تفعيل تلقائي بعد التثبيت</span>' +
                    '</label>' +
                    '<button class="wpl-btn wpl-btn--primary wpl-install-btn">⬇️ تثبيت المحدد</button>' +
                '</div>';
            }

            var tableHtml = p.files.length
                ? '<table class="wpl-table"><thead><tr>' +
                  '<th class="wpl-td-cb"></th>' +
                  '<th>الاسم</th><th>الحجم</th><th>الحالة</th><th>إجراءات</th>' +
                  '</tr></thead><tbody>' +
                  p.files.map(renderFileRow).join('') +
                  '</tbody></table>'
                : '<div class="wpl-empty">لا توجد ملفات.</div>';

            return '<div class="wpl-product" data-id="' + esc(p.id) + '">' +
                '<div class="wpl-product-head" onclick="wplToggleProduct(this)">' +
                    '<div class="wpl-product-title">' +
                        '<span class="wpl-chevron">▶</span>' +
                        '<strong>' + esc(p.name) + '</strong>' +
                        '<span class="wpl-badge wpl-badge--blue">' + esc(p.category_label) + '</span>' +
                        '<span class="wpl-badge wpl-badge--grey">' + p.files_count + ' ملف · ' + esc(p.total_size_human) + '</span>' +
                    '</div>' +
                    '<span class="wpl-all-done-badge">' + (allDone ? '<span class="wpl-badge wpl-badge--green">✅ مثبّت</span>' : '') + '</span>' +
                '</div>' +
                '<div class="wpl-product-body" style="display:none">' +
                    tableHtml + footerHtml +
                '</div>' +
            '</div>';
        }).join('');

        $('#wpl-products-container').html(html);
        bindActions();
    }

    function renderFileRow(f) {
        var statusBadge = getStatusBadge(f.status);
        var actionCell  = getActionCell(f);
        var canInstall  = f.status === 'not_installed';

        var cbCell = canInstall
            ? '<input type="checkbox" class="wpl-cb" data-file="' + esc(f.filename) + '" checked>'
            : '';

        return '<tr data-filename="' + esc(f.filename) + '" data-status="' + esc(f.status) + '" data-plugin="' + esc(f.plugin_file || '') + '" data-is-theme="' + (f.is_theme ? '1' : '0') + '">' +
            '<td class="wpl-td-cb">' + cbCell + '</td>' +
            '<td><strong>' + esc(f.label) + '</strong>' +
                '<small class="wpl-fname">' + esc(f.filename) + '</small></td>' +
            '<td>' + esc(f.size_human) + '</td>' +
            '<td class="wpl-status-cell">' + statusBadge + '</td>' +
            '<td class="wpl-actions">' + actionCell + '</td>' +
            '</tr>';
    }

    function getStatusBadge(status) {
        if (status === 'active')    return '<span class="wpl-badge wpl-badge--green">✅ مفعّل</span>';
        if (status === 'installed') return '<span class="wpl-badge wpl-badge--blue">مثبّت</span>';
        return '<span class="wpl-badge wpl-badge--grey">غير مثبّت</span>';
    }

    function getActionCell(f) {
        var file    = typeof f === 'string' ? f : f.filename;
        var plugin  = typeof f === 'object' ? (f.plugin_file || '') : '';
        var status  = typeof f === 'object' ? f.status : f;
        var isTheme = typeof f === 'object' ? (f.is_theme ? '1' : '0') : '0';
        var activateLabel = isTheme === '1' ? '🎨 تفعيل كقالب' : '▶️ تفعيل';

        if (status === 'active')
            return (isTheme === '1' ? '<span class="wpl-badge wpl-badge--green" style="font-size:11px">✅ القالب النشط</span> ' : '') +
                   '<button class="wpl-btn wpl-btn--replace" data-file="' + esc(file) + '" data-is-theme="' + isTheme + '">🔄 استبدال</button>';
        if (status === 'installed')
            return '<button class="wpl-btn wpl-btn--activate" data-plugin="' + esc(plugin) + '" data-file="' + esc(file) + '" data-is-theme="' + isTheme + '">' + activateLabel + '</button>' +
                   ' <button class="wpl-btn wpl-btn--replace" data-file="' + esc(file) + '" data-is-theme="' + isTheme + '">🔄 استبدال</button>';
        return '<button class="wpl-btn wpl-btn--install-single" data-file="' + esc(file) + '" data-is-theme="' + isTheme + '">⬇️ تثبيت</button>';
    }

    /* ====== تحديث صف واحد بدون reload ====== */
    function updateRow(filename, newStatus, pluginFile, isTheme) {
        var row = $('tr[data-filename="' + filename + '"]');
        if (!row.length) return;

        row.attr('data-status', newStatus);
        if (pluginFile) row.attr('data-plugin', pluginFile);
        if (isTheme !== undefined) row.attr('data-is-theme', isTheme ? '1' : '0');

        // تحديث بادج الحالة
        row.find('.wpl-status-cell').html(getStatusBadge(newStatus));

        // تحديث خلية الإجراءات
        var f = {
            filename:    filename,
            plugin_file: pluginFile || row.attr('data-plugin') || '',
            status:      newStatus,
            is_theme:    row.attr('data-is-theme') === '1',
        };
        row.find('.wpl-actions').html(getActionCell(f));

        // تحديث الـ checkbox — اختفي لو مثبّت/مفعّل
        if (newStatus !== 'not_installed') {
            row.find('.wpl-td-cb').html('');
        }

        // تحقق هل كل ملفات المنتج اتثبّتت
        var product = row.closest('.wpl-product');
        checkAllDone(product);

        // تحديث footer زرار تثبيت المحدد
        refreshFooter(product);
    }

    /* ====== تحقق هل كل ملفات المنتج مثبّتة ====== */
    function checkAllDone(product) {
        var allDone = true;
        product.find('tbody tr').each(function () {
            var s = $(this).attr('data-status');
            if (s !== 'active' && s !== 'installed') { allDone = false; return false; }
        });
        var badgeWrap = product.find('.wpl-all-done-badge');
        badgeWrap.html(allDone ? '<span class="wpl-badge wpl-badge--green">✅ مثبّت</span>' : '');
    }

    /* ====== تحديث footer بعد كل تثبيت ====== */
    function refreshFooter(product) {
        var notInstalled = product.find('tbody tr').filter(function () {
            return $(this).attr('data-status') === 'not_installed';
        });

        var footer = product.find('.wpl-product-footer');

        if (!notInstalled.length) {
            // مفيش ملفات محتاجة تثبيت — اخفي الـ footer
            footer.remove();
        } else {
            // لو الـ footer اتشال وفيه ملفات جديدة محتاجة تثبيت — أعد إنشاءه
            if (!footer.length) {
                var newFooter = '<div class="wpl-product-footer">' +
                    '<label class="wpl-activate-label">' +
                        '<input type="checkbox" class="wpl-do-activate" checked>' +
                        '<span>تفعيل تلقائي بعد التثبيت</span>' +
                    '</label>' +
                    '<button class="wpl-btn wpl-btn--primary wpl-install-btn">⬇️ تثبيت المحدد</button>' +
                '</div>';
                product.find('.wpl-product-body').append(newFooter);
            }
        }
    }

    /* ====== Toggle فتح/غلق المنتج ====== */
    window.wplToggleProduct = function(head) {
        var body    = $(head).next('.wpl-product-body');
        var chevron = $(head).find('.wpl-chevron');
        var isOpen  = body.is(':visible');
        body.slideToggle(200);
        chevron.text(isOpen ? '▶' : '▼');
    };

    /* ====== ربط الأزرار ====== */
    function bindActions() {

        // تثبيت فردي — بدون تفعيل تلقائي
        $(document).off('click', '.wpl-btn--install-single').on('click', '.wpl-btn--install-single', function () {
            var filename = $(this).data('file');
            var isTheme  = $(this).data('is-theme') === '1' || $(this).data('is-theme') === 1;
            setRowLoading(filename, isTheme ? 'جاري تثبيت القالب...' : 'جاري التثبيت...');
            installFile(filename, true, function(newStatus, pluginFile, resIsTheme) {
                updateRow(filename, newStatus, pluginFile, resIsTheme);
            }, isTheme);
        });

        // تفعيل فردي — بدون reload
        $(document).off('click', '.wpl-btn--activate').on('click', '.wpl-btn--activate', function () {
            var pluginFile = $(this).data('plugin');
            var filename   = $(this).data('file');
            var isTheme    = $(this).data('is-theme') === '1' || $(this).data('is-theme') === 1;
            setRowLoading(filename, isTheme ? 'جاري تفعيل القالب...' : 'جاري التفعيل...');
            activatePlugin(pluginFile, isTheme, function(ok) {
                updateRow(filename, ok ? 'active' : 'installed', pluginFile, isTheme);
            });
        });

        // استبدال — بدون confirm (الـ PHP بيتعامل مع الاستبدال تلقائياً)
        $(document).off('click', '.wpl-btn--replace').on('click', '.wpl-btn--replace', function () {
            var filename = $(this).data('file');
            var isTheme  = $(this).data('is-theme') === '1' || $(this).data('is-theme') === 1;
            setRowLoading(filename, isTheme ? '🔄 جاري استبدال القالب...' : '🔄 جاري الاستبدال...');
            installFile(filename, true, function(newStatus, pluginFile, resIsTheme) {
                updateRow(filename, newStatus, pluginFile, resIsTheme);
            }, isTheme);
        });

        // تحميل ZIP
        $(document).off('click', '.wpl-btn--download-only').on('click', '.wpl-btn--download-only', function () {
            var filename = $(this).data('file');
            var btn      = $(this);
            btn.prop('disabled', true).text('⏳');
            $.post(WPL.ajax_url, { action: 'wpl_get_download_url', nonce: WPL.nonce, filename: filename }, function (res) {
                btn.prop('disabled', false).text('💾');
                if (!res.success) return;
                var a = document.createElement('a');
                a.href = res.data.download_url; a.target = '_blank';
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
            });
        });

        // تثبيت المحدد (footer)
        $(document).off('click', '.wpl-install-btn').on('click', '.wpl-install-btn', function () {
            var product  = $(this).closest('.wpl-product');
            var selected = [];
            product.find('.wpl-cb:checked').each(function () {
                var row     = $(this).closest('tr');
                var isTheme = row.attr('data-is-theme') === '1';
                selected.push({ filename: $(this).data('file'), is_theme: isTheme });
            });
            if (!selected.length) { alert('حدد ملفاً على الأقل.'); return; }
            var doActivate = product.find('.wpl-do-activate').is(':checked');
            $(this).prop('disabled', true).text('جاري التثبيت...');
            installQueue(selected, 0, doActivate, function() {
                product.find('.wpl-install-btn').prop('disabled', false).text('⬇️ تثبيت المحدد');
            });
        });
    }

    /* ====== تثبيت ملف واحد (Promise-style callback) ====== */
    window.installFile = function installFile(filename, doActivate, callback, isTheme) {
        $.post(WPL.ajax_url, { action: 'wpl_install_file', nonce: WPL.nonce, filename: filename, is_theme: isTheme ? '1' : '0' },
        function (res) {
            if (!res.success) {
                // لو الأوردر ملغي — أظهر رسالة وأعد تحميل الصفحة
                if (res.data && res.data.cancelled) {
                    var msg = res.data.message || 'عذراً، هذا السيريال لطلب ملغي.';
                    var gate = document.getElementById('wpl-serial-gate-wrapper');
                    var tabs = document.getElementById('wpl-tabs-nav');
                    var errEl = document.getElementById('wpl-serial-error');
                    if (gate) gate.style.display = 'block';
                    if (tabs) tabs.style.display = 'none';
                    jQuery('.wpl-tab-pane').hide();
                    if (errEl) { errEl.textContent = '🚫 ' + msg; errEl.style.display = 'block'; }
                    if (callback) callback('not_installed', '', isTheme);
                    return;
                }
                showRowError(filename, res.data || 'فشل التثبيت');
                if (callback) callback('not_installed', '', isTheme);
                return;
            }
            var pluginFile = res.data.plugin_file || '';
            var resIsTheme = (res.data.is_theme !== undefined && res.data.is_theme !== null) ? !!res.data.is_theme : isTheme;
            if (doActivate && pluginFile) {
                activatePlugin(pluginFile, resIsTheme, function(ok) {
                    if (callback) callback(ok ? 'active' : 'installed', pluginFile, resIsTheme);
                });
            } else {
                if (callback) callback('installed', pluginFile, resIsTheme);
            }
        }).fail(function () {
            showRowError(filename, 'خطأ في الاتصال');
            if (callback) callback('not_installed', '', isTheme);
        });
    }

    /* ====== تفعيل بلجن ====== */
    function activatePlugin(pluginFile, isTheme, callback) {
        $.post(WPL.ajax_url, {
            action: 'wpl_toggle_plugin', nonce: WPL.nonce,
            plugin_file: pluginFile, toggle_action: 'activate',
            is_theme: isTheme ? '1' : '0'
        }, function(res) {
            if (res && !res.success && res.data) {
                // عرض رسالة الخطأ للمستخدم
                var msg = typeof res.data === 'string' ? res.data : 'فشل التفعيل.';
                alert('⚠️ ' + msg);
            }
            if (callback) callback(res && res.success);
        }).fail(function() {
            if (callback) callback(false);
        });
    }

    /* ====== تثبيت متسلسل بدون reload ====== */
    window.installQueue = function installQueue(files, index, doActivate, onAllDone) {
        if (index >= files.length) {
            if (onAllDone) onAllDone();
            return;
        }

        var item     = typeof files[index] === 'object' ? files[index] : { filename: files[index], is_theme: false };
        var filename = item.filename;
        var isTheme  = item.is_theme || false;
        setRowLoading(filename, (isTheme ? 'جاري تثبيت القالب' : 'جاري التثبيت') + ' (' + (index + 1) + '/' + files.length + ')...');

        installFile(filename, doActivate, function(newStatus, pluginFile, resIsTheme) {
            updateRow(filename, newStatus, pluginFile, resIsTheme);
            installQueue(files, index + 1, doActivate, onAllDone);
        }, isTheme);
    }

    /* ====== Helpers ====== */
    function setRowLoading(filename, msg) {
        $('tr[data-filename="' + filename + '"]').find('.wpl-actions').html(
            '<span class="wpl-spinner-sm"></span> ' + msg
        );
    }
    function showRowError(filename, msg) {
        $('tr[data-filename="' + filename + '"]').find('.wpl-actions').html(
            '<span class="wpl-error-sm">❌ ' + msg + '</span>'
        );
    }
    function esc(str) { return $('<div>').text(str || '').html(); }

})(jQuery);

/* ====================================================
   طلب التفعيل السريع
   ==================================================== */
(function($) {
    var wplScreenshotBase64 = '';

    /* معاينة الصورة بعد الاختيار */
    window.wplPreviewScreenshot = function(input) {
        var file = input.files[0];
        if (!file) return;

        // حد أقصى 3MB
        if (file.size > 3 * 1024 * 1024) {
            wplActShowError('حجم الصورة كبير جداً — الحد الأقصى 3MB.');
            input.value = '';
            return;
        }

        var reader = new FileReader();
        reader.onload = function(e) {
            wplScreenshotBase64 = e.target.result;
            document.getElementById('wpl-preview-img').src = wplScreenshotBase64;
            document.getElementById('wpl-screenshot-preview').style.display = 'block';
            document.getElementById('wpl-file-text').textContent = '✅ ' + file.name;
            document.getElementById('wpl-file-icon').textContent = '🖼';
            var label = document.getElementById('wpl-file-label-wrap');
            if (label) label.classList.add('has-file');
        };
        reader.readAsDataURL(file);
    };

    /* إزالة الصورة */
    window.wplRemoveScreenshot = function() {
        wplScreenshotBase64 = '';
        var inp = document.getElementById('wpl-screenshot-input');
        if (inp) inp.value = '';
        var preview = document.getElementById('wpl-screenshot-preview');
        if (preview) preview.style.display = 'none';
        var txt = document.getElementById('wpl-file-text');
        if (txt) txt.textContent = 'اضغط لرفع صورة التحويل';
        var icon = document.getElementById('wpl-file-icon');
        if (icon) icon.textContent = '📷';
        var label = document.getElementById('wpl-file-label-wrap');
        if (label) label.classList.remove('has-file');
    };

    /* مسح الـ error */
    window.wplActClearError = function() {
        var el = document.getElementById('wpl-act-error');
        if (el) el.style.display = 'none';
    };

    function wplActShowError(msg) {
        var el = document.getElementById('wpl-act-error');
        if (!el) return;
        el.textContent = msg;
        el.style.display = 'block';
    }

    /* إرسال الطلب */
    // دالة مشتركة لبناء install UI
    // Reset hero card to fresh input state
    // Show inline new order form inside hero card
    window.wplShowNewOrderForm = function() {
        var hcS = document.getElementById('wpl-hero-card-session');
        var hcN = document.getElementById('wpl-hero-card');
        var hc  = hcS || hcN;
        if (!hc) return;

        // Fade out
        hc.style.transition = 'opacity .35s ease';
        hc.style.opacity = '0';
        setTimeout(function() {
            // Rebuild hero card with inline form
            hc.innerHTML =
                // Glow overlay
                '<div style="position:absolute;inset:0;background:radial-gradient(ellipse at 80% 20%,rgba(59,130,246,.08) 0%,transparent 60%);pointer-events:none"></div>' +

                // Robot + bubble row
                '<div style="padding:22px 28px 0;display:flex;align-items:flex-start;gap:18px;position:relative">' +
                    '<div style="flex:1">' +
                        '<div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.35);letter-spacing:1.8px;margin-bottom:10px">WORDPRESS LICENSES</div>' +
                        '<div id="wpl-bubble" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:16px;border-top-right-radius:4px;padding:14px 18px;direction:rtl">' +
                            '<div style="font-size:16px;font-weight:700;color:#fff;margin-bottom:3px">أهلاً تاني! 👋 عايز طلب جديد؟</div>' +
                            '<div style="font-size:12px;color:rgba(255,255,255,.6)">أدخل السيريال ورقم الطلب الجديد</div>' +
                        '</div>' +
                    '</div>' +
                    '<img id="wpl-robot-img" src="https://wordpresslicenses.com/wp-content/uploads/2026/03/125887871_Graident-Ai-Robot-scaled.jpg"' +
                        ' style="width:80px;height:80px;object-fit:contain;flex-shrink:0;filter:drop-shadow(0 8px 24px rgba(59,130,246,.3))">' +
                '</div>' +

                // Inline form
                '<div style="padding:18px 28px 24px;direction:rtl">' +
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">' +
                        '<div>' +
                            '<label style="display:block;font-size:10px;font-weight:700;color:rgba(255,255,255,.45);letter-spacing:1.2px;margin-bottom:6px">🔑 السيريال</label>' +
                            '<input type="text" id="wpl-new-serial-input" placeholder="WPL-XXXX-XXXX-XXXX"' +
                                ' autocomplete="off"' +
                                ' style="width:100%;text-align:right;direction:rtl;letter-spacing:.5px;background:rgba(255,255,255,.07);color:#fff;border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px 14px;font-size:13px;outline:none;font-family:inherit">' +
                        '</div>' +
                        '<div>' +
                            '<label style="display:block;font-size:10px;font-weight:700;color:rgba(255,255,255,.45);letter-spacing:1.2px;margin-bottom:6px">📦 رقم الطلب</label>' +
                            '<input type="text" id="wpl-new-order-input" placeholder="مثال: 39593"' +
                                ' autocomplete="off"' +
                                ' style="width:100%;text-align:right;direction:rtl;background:rgba(255,255,255,.07);color:#fff;border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px 14px;font-size:13px;outline:none;font-family:inherit">' +
                        '</div>' +
                    '</div>' +
                    '<button onclick="wplSubmitNewOrder()"' +
                        ' style="width:100%;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border:none;border-radius:12px;padding:13px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;letter-spacing:.3px;margin-bottom:8px">' +
                        '⚡ تحقق وثبّت الآن' +
                    '</button>' +
                    '<p id="wpl-new-order-error" style="display:none;text-align:center;font-size:12px;color:#fca5a5;margin-top:6px"></p>' +
                '</div>';

            // Fade in
            hc.style.opacity = '0';
            hc.style.position = 'relative';
            setTimeout(function() {
                hc.style.transition = 'opacity .5s ease';
                hc.style.opacity = '1';
                var inp = document.getElementById('wpl-new-serial-input');
                if (inp) inp.focus();
            }, 50);
        }, 350);
    };

    // Submit the new order from inside hero card
    window.wplSubmitNewOrder = function() {
        var serial = (document.getElementById('wpl-new-serial-input') || {value:''}).value.trim();
        var order  = (document.getElementById('wpl-new-order-input')  || {value:''}).value.trim().replace(/^#/,'');
        var errEl  = document.getElementById('wpl-new-order-error');
        if (!serial) { if(errEl){errEl.textContent='برجاء إدخال السيريال.';errEl.style.display='block';} return; }
        if (!order)  { if(errEl){errEl.textContent='برجاء إدخال رقم الطلب.';errEl.style.display='block';} return; }
        if (errEl) errEl.style.display='none';

        // Inject into real inputs and trigger verify
        var sInp = document.getElementById('wpl-serial-input') || document.getElementById('wpl-gate-order-input');
        var oInp = document.getElementById('wpl-gate-order-input');
        // Create hidden inputs if needed for wplVerifySerial to pick up
        var tmpS = document.getElementById('wpl-serial-input');
        var tmpO = document.getElementById('wpl-gate-order-input');
        if (!tmpS) {
            tmpS = document.createElement('input'); tmpS.id='wpl-serial-input'; tmpS.type='hidden';
            document.body.appendChild(tmpS);
        }
        if (!tmpO) {
            tmpO = document.createElement('input'); tmpO.id='wpl-gate-order-input'; tmpO.type='hidden';
            document.body.appendChild(tmpO);
        }
        tmpS.value = serial;
        tmpO.value = order;

        // Show robot "working" state inside hero card
        var hcS = document.getElementById('wpl-hero-card-session');
        var hcN = document.getElementById('wpl-hero-card');
        var hc  = hcS || hcN;
        if (hc) {
            hc.style.transition = 'opacity .3s';
            hc.style.opacity = '0';
            setTimeout(function(){
                hc.innerHTML =
                    '<div style="position:absolute;inset:0;background:radial-gradient(ellipse at 80% 20%,rgba(59,130,246,.08) 0%,transparent 60%);pointer-events:none"></div>' +
                    '<div style="padding:24px 28px 0;display:flex;align-items:flex-start;gap:18px;position:relative">' +
                        '<div style="flex:1">' +
                            '<div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.35);letter-spacing:1.8px;margin-bottom:10px">WORDPRESS LICENSES</div>' +
                            '<div id="wpl-bubble" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:16px;border-top-right-radius:4px;padding:14px 18px">' +
                                '<div id="wpl-b-big" style="font-size:16px;font-weight:700;color:#fff;margin-bottom:3px">ممتاز! السيريال صح ✓</div>' +
                                '<div id="wpl-b-sub" style="font-size:12px;color:rgba(255,255,255,.65)">أنا بشوف طلبك وبجهّز ملفاتك...</div>' +
                            '</div>' +
                        '</div>' +
                        '<img id="wpl-robot-img" src="https://wordpresslicenses.com/wp-content/uploads/2026/03/125887871_Graident-Ai-Robot-scaled.jpg"' +
                            ' class="wpl-robot-talk"' +
                            ' style="width:80px;height:80px;object-fit:contain;flex-shrink:0;filter:drop-shadow(0 8px 24px rgba(59,130,246,.3))">' +
                    '</div>' +
                    '<div id="wpl-robot-prog" style="padding:0 28px 24px;display:none;opacity:0;transition:opacity .4s">' +
                        '<div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:6px">' +
                            '<div><span id="wpl-robot-prog-n" style="font-size:48px;font-weight:800;color:#fff;line-height:1">0</span>' +
                            '<span style="font-size:16px;color:rgba(255,255,255,.4);margin-right:4px"> / </span>' +
                            '<span id="wpl-robot-prog-total" style="font-size:16px;color:rgba(255,255,255,.4)">0</span></div>' +
                            '<div style="font-size:12px;color:rgba(255,255,255,.4);padding-bottom:8px">ملف</div>' +
                        '</div>' +
                        '<div style="height:5px;border-radius:3px;background:rgba(255,255,255,.1);overflow:hidden;margin-bottom:8px">' +
                            '<div id="wpl-robot-prog-fill" style="height:100%;border-radius:3px;background:linear-gradient(90deg,#3b82f6,#60a5fa);width:0%;transition:width .35s ease"></div>' +
                        '</div>' +
                        '<div id="wpl-robot-prog-file" style="font-size:12px;color:rgba(255,255,255,.45);text-align:right"></div>' +
                    '</div>';
                hc.style.opacity='0';
                setTimeout(function(){ hc.style.transition='opacity .5s'; hc.style.opacity='1'; },50);
            }, 300);
        }

        // Direct AJAX flow - bypass tab-switching logic of wplVerifySerial
        setTimeout(function(){
            jQuery.post(WPL.ajax_url, {
                action: 'wpl_verify_serial',
                nonce:  WPL.nonce,
                serial: serial,
                order_number: order
            }, function(res) {
                if (!res.success) {
                    var hcS2 = document.getElementById('wpl-hero-card-session');
                    var hcN2 = document.getElementById('wpl-hero-card');
                    var hc2  = hcS2 || hcN2;
                    var errData2 = (res.data && typeof res.data === 'object') ? res.data : {};
                    var errMsg = errData2.message || res.data || 'السيريال غير صحيح.';
                    if (errData2.api_key_invalid) { wplShowApiKeyError(); return; }
                    if (hc2) {
                        hc2.style.opacity='0';
                        setTimeout(function(){
                            // Restore form with error
                            window.wplShowNewOrderForm();
                            setTimeout(function(){
                                var errEl = document.getElementById('wpl-new-order-error');
                                if(errEl){ errEl.textContent=errMsg; errEl.style.display='block'; }
                            }, 500);
                        }, 300);
                    }
                    return;
                }
                // Serial OK — update WPL state
                window._wplSerialVerified = true;
                if (order) {
                    var nums = (WPL.order_numbers||'').split(',').filter(Boolean);
                    if (nums.indexOf(order)===-1) nums.push(order);
                    WPL.order_numbers = nums.join(',');
                }
                // حدّث wpl_ids من matched_products اللي رجعوا مع verify_serial مباشرة
                var vsMatchedProds = (res.data && res.data.matched_products) ? res.data.matched_products : [];
                if (vsMatchedProds.length) {
                    var vsIds = (WPL.wpl_ids || '').split(',').filter(Boolean);
                    vsMatchedProds.forEach(function(m) {
                        var id = typeof m === 'object' ? String(m.wpl_id || '') : '';
                        if (id && vsIds.indexOf(id) === -1) vsIds.push(id);
                    });
                    WPL.wpl_ids = vsIds.join(',');
                    var mBtn = document.querySelector('.wpl-tab-btn[data-tab="files"]');
                    if (mBtn) mBtn.style.display = '';
                }
                // Send activation request then install
                jQuery.post(WPL.ajax_url, {
                    action:          'wpl_send_activation_request',
                    nonce:           WPL.nonce,
                    order_number:    order,
                    screenshot:      '',
                    serial_verified: '1'
                }, function(ares) {
                    // ✅ FIX v2.6.9: لو السيرفر رجع error (زي domain verification fail)،
                    //        اعرض الرسالة الحقيقية مش "مش لاقي ملفات"
                    if ( ! ares.success ) {
                        var errMsg = (ares.data && typeof ares.data === 'string')
                            ? ares.data
                            : (ares.data && ares.data.message) || 'حدث خطأ أثناء إرسال الطلب';
                        var setFnE = typeof window.wplRobotSetMsgSession==='function' && document.getElementById('wpl-bubble-s')
                                     ? window.wplRobotSetMsgSession : window.wplRobotSetMsg;
                        if (typeof setFnE === 'function') {
                            setFnE('❌ ' + errMsg, 'تواصل مع الدعم لو المشكلة استمرت', false);
                        }
                        // اعرض كارت خطأ واضح في الـ hero card
                        var hcE = document.getElementById('wpl-hero-card-session') || document.getElementById('wpl-hero-card');
                        if (hcE) {
                            hcE.style.transition = 'opacity .4s ease';
                            hcE.style.opacity    = '0';
                            setTimeout(function(){
                                hcE.innerHTML =
                                    '<div style="padding:28px 24px;text-align:center;direction:rtl">' +
                                        '<div style="font-size:48px;margin-bottom:12px">⚠️</div>' +
                                        '<div style="font-size:18px;font-weight:700;color:#fff;margin-bottom:10px">تعذّر إرسال الطلب</div>' +
                                        '<div style="font-size:13px;color:rgba(255,255,255,.7);line-height:1.7;margin:0 auto 18px;max-width:380px">' +
                                            errMsg +
                                        '</div>' +
                                        '<a href="https://wa.me/201092522686" target="_blank"' +
                                           ' style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;text-decoration:none;border-radius:12px;padding:11px 22px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:6px">' +
                                            '💬 تواصل مع الدعم' +
                                        '</a>' +
                                    '</div>';
                                hcE.style.opacity = '0';
                                setTimeout(function(){ hcE.style.transition='opacity .5s'; hcE.style.opacity='1'; }, 50);
                            }, 400);
                        }
                        return;
                    }

                    var aData = ares.data || {};
                    window._wplInstantReqId    = aData.req_id || null;
                    window._wplInstantOrderNum = order;
                    var matchedProds = aData.matched_products || [];
                    // Update WPL.wpl_ids with new matched product IDs so fetch works
                    if (matchedProds.length) {
                        var existingIds = (WPL.wpl_ids || '').split(',').filter(Boolean);
                        matchedProds.forEach(function(m) {
                            var id = typeof m === 'object' ? String(m.wpl_id || '') : '';
                            if (id && existingIds.indexOf(id) === -1) existingIds.push(id);
                        });
                        WPL.wpl_ids = existingIds.join(',');
                    }
                    // Use a dummy div — robot prog IDs already exist in hero card
                    var dummy = document.createElement('div');
                    if (typeof window._wplBuildInstallUI === 'function') {
                        if (matchedProds.length) {
                            window._wplBuildInstallUI(matchedProds, order, dummy);
                        } else {
                            // No files — show card with product names (both unmatched and no_files)
                            var unmatchedProds = aData.unmatched_products || [];
                            var noFilesProds   = aData.no_files_products  || [];
                            // ادمجهم في قائمة واحدة
                            var allMissing = [].concat(unmatchedProds, noFilesProds);
                            // fallback: لو مفيش بيانات مفصّلة، استخدم order_products (أسماء strings)
                            if (allMissing.length === 0 && aData.order_products) {
                                allMissing = aData.order_products;
                            }
                            wplShowNoFilesCard(order, allMissing);
                            // ✅ FIX: فضل بتعمل polling — لو background job شغّال وخلص بنجاح
                            // (ممكن يكون الطلب اتبعت قبل وفيه job شغّال) → ابدّل الكارت لـ "تم التنصيب"
                            wplStartBgPolling(order, dummy);
                        }
                    }
                    if (typeof wplLoadMyRequests === 'function') setTimeout(wplLoadMyRequests, 400);
                }).fail(function(){
                    var setFn = typeof window.wplRobotSetMsgSession==='function' && document.getElementById('wpl-bubble-s')
                                ? window.wplRobotSetMsgSession : window.wplRobotSetMsg;
                    if(typeof setFn==='function') setFn('❌ تعذّر الاتصال','حاول مجدداً', false);
                });
            }).fail(function(){
                var setFn2 = typeof window.wplRobotSetMsgSession==='function' && document.getElementById('wpl-bubble-s')
                             ? window.wplRobotSetMsgSession : window.wplRobotSetMsg;
                if(typeof setFn2==='function') setFn2('❌ تعذّر الاتصال','حاول مجدداً', false);
            });
        }, 500);
    };

    window.wplResetHeroCard = function() {
        // Determine if session or normal
        var hcS = document.getElementById('wpl-hero-card-session');
        var hcN = document.getElementById('wpl-hero-card');
        var hc  = hcS || hcN;
        if (!hc) return;

        // Fade out
        hc.style.transition = 'opacity .4s ease';
        hc.style.opacity = '0';

        setTimeout(function() {
            if (hcS) {
                // Session: restore button area
                var btnArea = document.getElementById('wpl-session-btn-area');
                var prog    = document.getElementById('wpl-robot-prog-s');
                if (btnArea) { btnArea.style.display = 'block'; btnArea.style.opacity = '1'; }
                if (prog)    { prog.style.display = 'none'; prog.style.opacity = '0'; }
                if (typeof window.wplRobotSetMsgSession === 'function') {
                    window.wplRobotSetMsgSession('أهلاً! أنا جاهز أثبّتلك الملفات','اضغط زرار التثبيت وأنا هتولى كل حاجة', true);
                }
            } else {
                // Normal: restore input fields
                var fields  = document.getElementById('wpl-robot-fields');
                var prog    = document.getElementById('wpl-robot-prog');
                var actForm = document.getElementById('wpl-act-form');
                var actSucc = document.getElementById('wpl-act-success');
                if (fields) {
                    fields.style.display = 'block';
                    fields.style.opacity = '0';
                    fields.style.transform = 'translateY(8px)';
                    setTimeout(function() {
                        fields.style.transition = 'opacity .35s, transform .35s';
                        fields.style.opacity = '1';
                        fields.style.transform = 'translateY(0)';
                    }, 50);
                    // Clear inputs
                    var sInp = document.getElementById('wpl-serial-input');
                    var oInp = document.getElementById('wpl-gate-order-input');
                    if (sInp) sInp.value = '';
                    if (oInp) oInp.value = '';
                    // Reset button
                    var btn = document.getElementById('wpl-robot-go-btn');
                    if (btn) { btn.disabled = false; btn.textContent = '⚡ تحقق وثبّت الآن'; }
                }
                if (prog)    { prog.style.display = 'none'; prog.style.opacity = '0'; }
                if (actForm) { actForm.style.display = 'block'; }
                if (actSucc) { actSucc.style.display = 'none'; actSucc.innerHTML = ''; }
                if (typeof window.wplRobotSetMsg === 'function') {
                    window.wplRobotSetMsg('أهلاً! عايز تفعّل طلب جديد؟','أدخل السيريال ورقم الطلب وأنا هتولى كل حاجة', true);
                }
            }

            // Fade in
            hc.style.opacity = '0';
            setTimeout(function() {
                hc.style.transition = 'opacity .5s ease';
                hc.style.opacity = '1';
            }, 50);
        }, 400);
    };

    /* ====== Background Polling — يشتغل حتى لو المستخدم خرج ورجع ====== */
    var _wplBgPollTimer = null;

    // ====== كارت "مش لاقيين ملفات" مع أسماء المنتجات ======
    window.wplShowNoFilesCard = function(orderNum, unmatchedProds) {
        var hc = document.getElementById('wpl-hero-card-session') || document.getElementById('wpl-hero-card');
        if (!hc) return;

        var prodsHtml = '';
        if (unmatchedProds && unmatchedProds.length) {
            prodsHtml =
                '<div style="background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3);border-radius:10px;padding:10px 14px;margin:0 auto 18px;max-width:420px;text-align:right">' +
                    '<div style="font-size:11px;font-weight:700;color:#fbbf24;letter-spacing:.5px;margin-bottom:6px">⚠️ المنتجات التالية مش عندها ملفات:</div>' +
                    unmatchedProds.map(function(p){
                        // الـ product ممكن يكون object {woo_name, wc_id, wpl_id, wpl_name} أو string (للتوافق مع النسخ القديمة)
                        var name  = '';
                        var wcId  = '';
                        var wplId = '';
                        if (typeof p === 'object' && p) {
                            name  = p.woo_name || p.wpl_name || p.name || '';
                            wcId  = p.wc_id  || '';
                            wplId = p.wpl_id || '';
                        } else {
                            name = p;
                        }
                        var badges = '';
                        if (wcId) {
                            badges += '<span style="display:inline-block;background:rgba(59,130,246,.2);color:#60a5fa;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;margin-right:6px;direction:ltr">WC #' + wcId + '</span>';
                        }
                        if (wplId) {
                            badges += '<span style="display:inline-block;background:rgba(139,92,246,.2);color:#a78bfa;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;margin-right:4px;direction:ltr">WPL ' + wplId.substring(0, 12) + '</span>';
                        }
                        return '<div style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,.07)">' +
                                   '<div style="font-size:12px;color:rgba(255,255,255,.85);font-weight:600">' + (name || '—') + '</div>' +
                                   (badges ? '<div style="margin-top:3px">' + badges + '</div>' : '') +
                               '</div>';
                    }).join('') +
                '</div>';
        }

        hc.style.transition = 'opacity .4s ease';
        hc.style.opacity    = '0';
        setTimeout(function(){
            hc.innerHTML =
                '<div style="position:absolute;inset:0;background:radial-gradient(ellipse at 80% 20%,rgba(251,191,36,.06) 0%,transparent 60%);pointer-events:none"></div>' +
                '<div style="padding:28px 24px;text-align:center;direction:rtl;position:relative">' +
                    '<img src="https://wordpresslicenses.com/wp-content/uploads/2026/03/125887871_Graident-Ai-Robot-scaled.jpg"' +
                        ' style="width:64px;height:64px;object-fit:contain;margin-bottom:14px;filter:drop-shadow(0 4px 16px rgba(251,191,36,.3))">' +
                    '<div style="font-size:18px;font-weight:700;color:#fff;margin-bottom:8px">⚠️ مش لاقيين ملفات للطلب ده</div>' +
                    '<div style="font-size:13px;color:rgba(255,255,255,.6);line-height:1.7;margin:0 auto 16px;max-width:340px">' +
                        'الطلب اتبعت للمسؤول للمراجعة' +
                        (orderNum ? ' <span style="color:rgba(255,255,255,.35);font-size:11px">(#' + orderNum + ')</span>' : '') +
                    '</div>' +
                    prodsHtml +
                    '<div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">' +
                        '<a href="https://wa.me/201092522686" target="_blank"' +
                            ' style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;text-decoration:none;border-radius:12px;padding:11px 22px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:6px">' +
                            '💬 تواصل مع الدعم' +
                        '</a>' +
                        '<button onclick="wplShowNewOrderForm()"' +
                            ' style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#fff;border-radius:12px;padding:11px 22px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit">' +
                            '⚡ طلب تفعيل جديد' +
                        '</button>' +
                    '</div>' +
                '</div>';
            hc.style.opacity = '0';
            setTimeout(function(){ hc.style.transition='opacity .5s'; hc.style.opacity='1'; }, 50);
        }, 400);
    };

    function wplStartBgPolling(orderNum, targetDiv) {
        if (_wplBgPollTimer) clearInterval(_wplBgPollTimer);

        // عرض الـ UI الأولي فوراً
        _wplShowBgUI(orderNum, targetDiv, null);

        // اسأل عن الحالة كل ثانيتين
        _wplBgPollTimer = setInterval(function() {
            jQuery.post(WPL.ajax_url, { action: 'wpl_get_bg_status', nonce: WPL.nonce }, function(res) {
                if (!res.success) return;
                var job = res.data || {};
                _wplShowBgUI(orderNum, targetDiv, job);

                if (job.status === 'complete' || job.status === 'error') {
                    clearInterval(_wplBgPollTimer);
                    _wplBgPollTimer = null;
                    // حدّث "طلباتي" في الـ background
                    if (typeof wplLoadMyRequests === 'function') setTimeout(wplLoadMyRequests, 500);
                    if (job.status === 'complete' && job.done > 0) {
                        if (window._wplInstantReqId && typeof markAutoInstalled === 'function')
                            markAutoInstalled(window._wplInstantReqId);
                        if (orderNum && typeof markAutoInstalled === 'function')
                            markAutoInstalled('order_' + orderNum);
                    }
                }
            });
        }, 2000);
    }

    function _wplShowBgUI(orderNum, targetDiv, job) {
        var status   = job ? job.status  : 'queued';
        var total    = job ? (job.total  || 0) : 0;
        var done     = job ? (job.done   || 0) : 0;
        var files    = job ? (job.files  || []) : [];
        var missing  = job ? (job.missing_prods || []) : [];
        var pct      = total > 0 ? Math.round((done / total) * 100) : 0;

        // ① حدّث الـ hero card robot
        var heroCard = document.getElementById('wpl-hero-card') || document.getElementById('wpl-hero-card-session');
        var suffix   = document.getElementById('wpl-hero-card-session') ? '-s' : '';
        var rFields  = document.getElementById('wpl-robot-fields');
        var rProg    = document.getElementById('wpl-robot-prog' + suffix);
        var rProgN   = document.getElementById('wpl-robot-prog-n' + suffix);
        var rProgTot = document.getElementById('wpl-robot-prog-total' + suffix);
        var rProgFill= document.getElementById('wpl-robot-prog-fill' + suffix);
        var rProgFile= document.getElementById('wpl-robot-prog-file' + suffix);
        var setMsg   = suffix === '-s' ? window.wplRobotSetMsgSession : window.wplRobotSetMsg;

        if (rFields) rFields.style.display = 'none';
        if (rProg) { rProg.style.display = 'block'; setTimeout(function(){ rProg.style.opacity='1'; }, 50); }

        // حالة: اكتمل
        if (status === 'complete') {
            if (rProgN)    rProgN.textContent    = done;
            if (rProgTot)  rProgTot.textContent  = total || done;
            if (rProgFill) rProgFill.style.width = '100%';
            if (rProgFile) rProgFile.textContent = done > 0 ? '✓ تم تثبيت ' + done + ' ملف' : 'اكتمل';
            if (typeof setMsg === 'function') setMsg('خلصنا! كل حاجة تمام ✓', 'الملفات جاهزة — مش محتاج تعمل حاجة', false);
            // اعرض كارت الاكتمال في الـ hero card
            if (heroCard) {
                setTimeout(function(){
                    heroCard.style.transition = 'opacity .8s'; heroCard.style.opacity = '0';
                    setTimeout(function(){
                        var missingHtml = '';
                        if (missing.length) {
                            missingHtml =
                                '<div style="background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3);border-radius:10px;padding:12px 14px;margin:0 auto 16px;max-width:440px;text-align:right">' +
                                    '<div style="font-size:12px;font-weight:700;color:#fbbf24;letter-spacing:.3px;margin-bottom:4px">⚠️ منتجات هيتم تنصيبها يدوياً</div>' +
                                    '<div style="font-size:11px;color:rgba(255,255,255,.65);margin-bottom:10px;line-height:1.6">فريق الدعم هيتواصل معاك على الواتساب ويبعتلك الملفات دي قريب — مش محتاج تعمل حاجة</div>' +
                                    missing.map(function(p){
                                        var name  = '';
                                        var wcId  = '';
                                        var wplId = '';
                                        if (typeof p === 'object' && p) {
                                            name  = p.woo_name || p.wpl_name || p.name || '';
                                            wcId  = p.wc_id  || '';
                                            wplId = p.wpl_id || '';
                                        } else {
                                            name = p;
                                        }
                                        var badges = '';
                                        if (wcId) {
                                            badges += '<span style="display:inline-block;background:rgba(59,130,246,.2);color:#60a5fa;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;margin-right:6px;direction:ltr">WC #' + wcId + '</span>';
                                        }
                                        if (wplId) {
                                            badges += '<span style="display:inline-block;background:rgba(139,92,246,.2);color:#a78bfa;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;margin-right:4px;direction:ltr">WPL ' + wplId.substring(0, 12) + '</span>';
                                        }
                                        return '<div style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,.07)">' +
                                                   '<div style="font-size:12px;color:rgba(255,255,255,.85);font-weight:600">' + (name || '—') + '</div>' +
                                                   (badges ? '<div style="margin-top:3px">' + badges + '</div>' : '') +
                                               '</div>';
                                    }).join('') +
                                '</div>';
                        }
                        heroCard.innerHTML =
                            '<div style="padding:28px 24px;text-align:center;direction:rtl">' +
                                '<img src="https://wordpresslicenses.com/wp-content/uploads/2026/03/125887871_Graident-Ai-Robot-scaled.jpg"' +
                                    ' style="width:64px;height:64px;object-fit:contain;margin-bottom:14px;filter:drop-shadow(0 4px 16px rgba(59,130,246,.35))">' +
                                (done > 0
                                    ? '<div style="font-size:18px;font-weight:700;color:#fff;margin-bottom:8px">✅ تم التنصيب!</div>' +
                                      '<div style="font-size:13px;color:rgba(255,255,255,.6);line-height:1.7;max-width:360px;margin:0 auto 16px">' +
                                          'تم تنصيب <strong style="color:#fff">' + done + '</strong> ملف بنجاح' +
                                          (missing.length
                                              ? '<br><span style="color:rgba(255,255,255,.5);font-size:12px">باقي ' + missing.length + ' منتج هيركّبه فريق الدعم يدوياً</span>'
                                              : '<br><span style="color:rgba(255,255,255,.4);font-size:12px">المسؤول هيأكدلك الأوردر</span>'
                                          ) +
                                      '</div>'
                                    : '<div style="font-size:18px;font-weight:700;color:#fbbf24;margin-bottom:8px">⏳ طلبك وصلنا</div>' +
                                      '<div style="font-size:13px;color:rgba(255,255,255,.6);line-height:1.7;max-width:360px;margin:0 auto 16px">' +
                                          'ملفاتك هيجهّزها فريق الدعم ويبعتهالك على الواتساب قريب' +
                                      '</div>'
                                ) +
                                missingHtml +
                                '<div style="display:flex;gap:12px;justify-content:center;margin-bottom:16px;flex-wrap:wrap">' +
                                    (done > 0 ? '<span style="background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:#4ade80;font-size:11px;padding:5px 14px;border-radius:20px;font-weight:600">✅ مثبّتة</span>' : '') +
                                    '<span style="background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);color:#60a5fa;font-size:11px;padding:5px 14px;border-radius:20px;font-weight:600">⏳ قيد المراجعة</span>' +
                                '</div>' +
                                '<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">' +
                                    '<a href="https://wa.me/201092522686" target="_blank" style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;text-decoration:none;border-radius:12px;padding:11px 22px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:6px">💬 تواصل مع الدعم</a>' +
                                    '<button onclick="wplShowNewOrderForm()" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);border:none;color:#fff;border-radius:12px;padding:12px 22px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit">⚡ طلب جديد</button>' +
                                '</div>' +
                            '</div>';
                        heroCard.style.opacity = '0'; heroCard.style.transition = 'opacity .6s';
                        setTimeout(function(){ heroCard.style.opacity = '1'; }, 50);
                    }, 900);
                }, 1500);
            }
        } else if (status === 'error') {
            if (typeof setMsg === 'function') setMsg('❌ حدث خطأ', job.error || 'تعذّر التنصيب', false);
            if (rProgFile) rProgFile.textContent = '❌ ' + (job.error || 'خطأ');
        } else {
            // running / queued
            var currentFile = files.filter(function(f){ return f.status === 'installing'; })[0];
            if (rProgN)    rProgN.textContent    = done;
            if (rProgTot)  rProgTot.textContent  = total || '...';
            if (rProgFill) rProgFill.style.width = pct + '%';
            if (rProgFile) rProgFile.textContent = currentFile ? currentFile.label || currentFile.filename : (status === 'queued' ? '⏳ جاري التحضير...' : '⬇️ جاري التحميل...');
            if (typeof setMsg === 'function') {
                if (status === 'queued') setMsg('جاري التحضير...', 'بنجهّز ملفاتك', true);
                else setMsg('نزّلت ' + done + ' من ' + (total||'?'), currentFile ? (currentFile.label || currentFile.filename) : '...', true);
            }
        }

        // ② حدّث الـ targetDiv (progress card)
        if (!targetDiv) targetDiv = document.getElementById('wpl-act-success');
        if (!targetDiv) return;
        targetDiv.style.display = 'block';

        // أنشئ أو حدّث بطاقة التقدم
        var card = document.getElementById('wpl-bg-progress-card');
        if (!card) {
            targetDiv.innerHTML =
                '<div id="wpl-bg-progress-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;direction:rtl">' +
                    '<div style="background:#0f172a;padding:14px 18px;display:flex;align-items:center;gap:10px">' +
                        '<span style="font-size:20px">⚡</span>' +
                        '<div>' +
                            '<div style="color:#fff;font-size:14px;font-weight:700">جاري التفعيل التلقائي</div>' +
                            '<div style="color:rgba(255,255,255,.5);font-size:12px">طلب رقم: <strong style="color:rgba(255,255,255,.8)">#' + orderNum + '</strong></div>' +
                        '</div>' +
                    '</div>' +
                    '<div style="padding:16px 18px">' +
                        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">' +
                            '<span id="wpl-bg-lbl" style="font-size:12px;color:#6b7280">⏳ جاري التحضير...</span>' +
                            '<span id="wpl-bg-pct" style="font-size:12px;font-weight:700;color:#0f172a">0%</span>' +
                        '</div>' +
                        '<div style="background:#f1f5f9;border-radius:20px;height:8px;overflow:hidden">' +
                            '<div id="wpl-bg-bar" style="height:100%;background:linear-gradient(90deg,#0f172a,#3b82f6);border-radius:20px;width:0%;transition:width .4s ease"></div>' +
                        '</div>' +
                        '<div id="wpl-bg-files" style="margin-top:12px;display:flex;flex-direction:column;gap:4px"></div>' +
                        (missing.length
                            ? '<div id="wpl-bg-missing" style="margin-top:10px;padding:10px 12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;font-size:12px;color:#92400e;direction:rtl">' +
                                '<strong>⚠️ منتجات مش عندها ملفات على السيرفر:</strong><br>' +
                                '<span style="color:#b45309">' + missing.join('<br>') + '</span>' +
                              '</div>'
                            : '') +
                        '<div style="margin-top:10px;padding:8px 10px;background:#f0f9ff;border-radius:8px;font-size:11px;color:#0369a1;text-align:center">' +
                            '💡 ممكن تخرج من الصفحة — التنصيب بيكمل في الـ background تلقائياً' +
                        '</div>' +
                    '</div>' +
                '</div>';
            card = document.getElementById('wpl-bg-progress-card');
        }

        // حدّث progress
        var barEl  = document.getElementById('wpl-bg-bar');
        var lblEl  = document.getElementById('wpl-bg-lbl');
        var pctEl  = document.getElementById('wpl-bg-pct');
        var listEl = document.getElementById('wpl-bg-files');

        if (barEl)  barEl.style.width  = pct + '%';
        if (pctEl)  pctEl.textContent  = pct + '%';

        var errCount = job ? (job.errors || 0) : 0;
        if (status === 'complete') {
            if (errCount > 0) {
                if (lblEl) lblEl.textContent = '⚠️ تم ' + done + ' من ' + (total||done) + ' — ' + errCount + ' ملف فشل (راجع القائمة أدناه)';
                if (barEl) { barEl.style.width = (Math.round(done/(total||done)*100)) + '%'; barEl.style.background = '#f59e0b'; }
                if (pctEl) { pctEl.textContent = done + '/' + (total||done); pctEl.style.color = '#d97706'; }
            } else {
                if (lblEl) lblEl.textContent = '🎉 تم تنصيب ' + done + ' من ' + (total||done) + ' ملف!';
                if (barEl) barEl.style.width = '100%';
                if (pctEl) pctEl.textContent = '100%';
            }
        } else if (status === 'error') {
            if (lblEl) lblEl.textContent = '❌ خطأ: ' + (job.error || 'تعذّر التنصيب');
        } else {
            var cur = files.filter(function(f){ return f.status === 'installing'; })[0];
            if (lblEl) lblEl.textContent = cur
                ? '⬇️ جاري تنصيب: ' + (cur.label || cur.filename)
                : (status === 'queued' ? '⏳ جاري التحضير...' : '⬇️ جاري التنصيب (' + (done+1) + '/' + (total||'?') + ')');
        }

        // حدّث قائمة الملفات
        if (listEl && files.length) {
            listEl.innerHTML = '';
            files.forEach(function(f, i) {
                var icon = f.status === 'done' ? '✅' : f.status === 'error' ? '❌' : f.status === 'installing' ? '🔄' : '⏳';
                var stColor = f.status === 'done' ? '#059669' : f.status === 'error' ? '#dc2626' : f.status === 'installing' ? '#3b82f6' : '#9ca3af';
                var stText  = f.status === 'done' ? 'تم' : f.status === 'error' ? (f.error || 'خطأ') : f.status === 'installing' ? 'جاري...' : 'انتظار';
                var row = document.createElement('div');
                row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f1f5f9;font-size:12px';
                row.innerHTML =
                    '<span style="font-size:13px;flex-shrink:0">' + icon + '</span>' +
                    '<span style="flex:1;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + (f.product_name||'') + '">' +
                        (f.label || f.filename) +
                        (f.product_name ? '<span style="color:#9ca3af;font-size:10px;margin-right:4px">— ' + f.product_name + '</span>' : '') +
                    '</span>' +
                    '<span style="color:' + stColor + ';font-size:11px;white-space:nowrap">' + stText + '</span>';
                listEl.appendChild(row);
            });

            // أضف المنتجات الناقصة
            if (missing.length && !document.getElementById('wpl-bg-missing')) {
                var missingDiv = document.createElement('div');
                missingDiv.id = 'wpl-bg-missing';
                missingDiv.style.cssText = 'margin-top:10px;padding:10px 12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;font-size:12px;color:#92400e;direction:rtl';
                missingDiv.innerHTML = '<strong>⚠️ منتجات مش عندها ملفات:</strong><br><span style="color:#b45309">' + missing.join('<br>') + '</span>';
                listEl.after(missingDiv);
            }
        }
    }

    // ====== عند تحميل الصفحة: لو في job شغّال → استأنف الـ polling ======
    $(document).ready(function() {
        jQuery.post(WPL.ajax_url, { action: 'wpl_get_bg_status', nonce: WPL.nonce }, function(res) {
            if (!res.success) return;
            var job = res.data || {};
            if (job.status === 'running' || job.status === 'queued') {
                // في job شغّال — أظهر الـ polling UI تلقائياً
                var orderNum = job.order_number || '';
                var targetDiv = document.getElementById('wpl-act-success');
                if (targetDiv) {
                    targetDiv.style.display = 'block';
                    var actForm = document.getElementById('wpl-act-form');
                    if (actForm) actForm.style.display = 'none';
                }
                wplStartBgPolling(orderNum, targetDiv);
            }
        });
    });

    window._wplBuildInstallUI = function(matched, orderNum, targetDiv) {
        if (!targetDiv) targetDiv = document.getElementById('wpl-act-success');
        // ابدأ polling فوراً — الـ background PHP هيتولى التنصيب
        wplStartBgPolling(orderNum, targetDiv);
    };


        // تحميل أوردر معين تلقائياً (للعميل عبر الرابط المؤقت)
    window.wplAutoInstallOrder = function(orderNum) {
        var formDiv    = document.getElementById('wpl-act-form-session');
        var successDiv = document.getElementById('wpl-act-success-session');
        if (!formDiv || !successDiv) return;
        formDiv.style.display    = 'none';
        successDiv.style.display = 'block';
        successDiv.innerHTML = '';
        // Robot: show preparing state
        if(typeof window.wplRobotSetMsg==='function'){
            window.wplRobotSetMsg('ممتاز! بشوف طلبك...','أنا بجهّز ملفاتك — متروحش بعيد',true);
        }

        $.post(WPL.ajax_url, {
            action:          'wpl_send_activation_request',
            nonce:           WPL.nonce,
            order_number:    orderNum,
            screenshot:      '',
            serial_verified: '1',
        }, function(res) {
            var aData = (res.success && res.data) || {};
            window._wplInstantReqId      = aData.req_id || null;
            window._wplInstantOrderNum   = orderNum;
            // حدّث WPL.wpl_ids من الـ matched_products
            var matchedProds = aData.matched_products || [];
            if (matchedProds.length) {
                var existingIds = (WPL.wpl_ids || '').split(',').filter(Boolean);
                matchedProds.forEach(function(m) {
                    var id = typeof m === 'object' ? (m.wpl_id || '') : '';
                    if (id && existingIds.indexOf(id) === -1) existingIds.push(id);
                });
                WPL.wpl_ids = existingIds.join(',');
            }
            if (typeof wplLoadMyRequests === 'function') wplLoadMyRequests();

            if (typeof window._wplBuildInstallUI === 'function') {
                if (matchedProds.length) {
                    window._wplBuildInstallUI(matchedProds, orderNum, successDiv);
                } else {
                    var unmatchedList = [].concat(
                        aData.unmatched_products || [],
                        aData.no_files_products  || []
                    );
                    // اطلع الاسم من كل object/string
                    var unmatchedNames = unmatchedList.map(function(p){
                        if (typeof p === 'object' && p) return p.woo_name || p.wpl_name || '';
                        return p;
                    }).filter(function(n){ return n; });
                    if (unmatchedNames.length === 0 && aData.order_products) {
                        unmatchedNames = aData.order_products;
                    }
                    var unmatchedHtml = unmatchedNames.length
                        ? '<div style="margin-top:10px;font-size:12px;color:#b45309">المنتجات: <strong>' + unmatchedNames.join('، ') + '</strong></div>'
                        : '';
                    successDiv.innerHTML =
                        '<div style="background:#ecfdf5;border:1px solid #86efac;border-radius:10px;padding:18px;text-align:right;color:#065f46;font-size:13px;direction:rtl;line-height:1.7">' +
                        '<div style="font-size:15px;font-weight:700;margin-bottom:6px">✅ تم استلام طلبك بنجاح</div>' +
                        '<div style="color:#047857">فريق الدعم هيجهّز ملفاتك ويبعتهالك على واتساب في أقرب وقت. مش محتاج تعمل حاجة تاني.</div>' +
                        unmatchedHtml +
                        '</div>';
                    if (typeof wplLoadMyRequests === 'function') wplLoadMyRequests();
                }
            }
        }).fail(function() {
            successDiv.innerHTML = '<div style="color:#dc2626;padding:16px;text-align:center">❌ تعذّر الاتصال — حاول مجدداً.</div>';
            formDiv.style.display = 'block';
            successDiv.style.display = 'none';
        });
    };

        window.wplSendActivationRequest = function() {
        var orderInput  = document.getElementById('wpl-order-input') || document.getElementById('wpl-order-input-session');
        var serialInput = document.getElementById('wpl-act-serial-input');
        var submitBtn   = document.getElementById('wpl-act-submit');
        var orderNumber = orderInput ? orderInput.value.trim() : '';
        var actSerial   = serialInput ? serialInput.value.trim() : '';

        wplActClearError();

        // إلغاء أي retry timer شغال
        if (typeof _wplRetryTimer !== 'undefined' && _wplRetryTimer) {
            clearInterval(_wplRetryTimer);
            _wplRetryTimer = null;
        }

        if (!actSerial && serialInput) {
            wplActShowError('برجاء إدخال السيريال.');
            serialInput.focus();
            return;
        }
        if (!orderNumber) {
            wplActShowError('برجاء إدخال رقم الطلب.');
            if (orderInput) orderInput.focus();
            return;
        }

        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = '⏳ جاري الإرسال...'; }
        // Robot: show installing state
        var setMsgFn = typeof window.wplRobotSetMsgSession==='function' && document.getElementById('wpl-bubble-s')
                       ? window.wplRobotSetMsgSession : (typeof window.wplRobotSetMsg==='function' ? window.wplRobotSetMsg : null);
        if(setMsgFn) setMsgFn('ممتاز! بشوف طلبك...','أنا بجهّز ملفاتك — متروحش بعيد',true);

        var actSerialEl  = document.getElementById('wpl-act-serial-input');
        var actSerialVal = actSerialEl ? actSerialEl.value.trim() : '';

        $.post(WPL.ajax_url, {
            action:          'wpl_send_activation_request',
            nonce:           WPL.nonce,
            order_number:    orderNumber,
            email:           '',
            screenshot:      '',
            act_serial:      actSerialVal,
            serial_verified: (window._wplSerialVerified || actSerialVal) ? '1' : '0',
        }, function(res) {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = '⚡ إرسال طلب التفعيل'; }

            if (res.success) {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = '⚡ إرسال طلب التفعيل'; }

                var autoApproved = res.data && res.data.auto_approved;
                var matched      = (res.data && res.data.matched_products) ? res.data.matched_products : [];
                var reqId        = (res.data && res.data.req_id) ? res.data.req_id : null;
                window._wplInstantReqId = reqId;
                window._wplInstantOrderNum = orderNumber;
                // حدّث WPL.wpl_ids من الـ matched_products
                if (matched.length) {
                    var existingIds = (WPL.wpl_ids || '').split(',').filter(Boolean);
                    matched.forEach(function(m) {
                        var id = typeof m === 'object' ? (m.wpl_id || '') : '';
                        if (id && existingIds.indexOf(id) === -1) existingIds.push(id);
                    });
                    WPL.wpl_ids = existingIds.join(',');
                }
                var formDiv      = document.getElementById('wpl-act-form');
                var successDiv   = document.getElementById('wpl-act-success');

                formDiv.style.display    = 'none';
                successDiv.style.display = 'block';

                // حدّث طلباتي فوراً بعد نجاح الإرسال
                if (typeof wplLoadMyRequests === 'function') wplLoadMyRequests();

                if (autoApproved && !matched.length) {
                    var unmCombined = [].concat(
                        (res.data && res.data.unmatched_products) || [],
                        (res.data && res.data.no_files_products)  || []
                    );
                    var unmNames = unmCombined.map(function(p){
                        if (typeof p === 'object' && p) return p.woo_name || p.wpl_name || '';
                        return p;
                    }).filter(function(n){ return n; });
                    if (unmNames.length === 0 && res.data && res.data.order_products) {
                        unmNames = res.data.order_products;
                    }
                    var unmHtml  = unmNames.length ? '<div style="margin-top:10px;font-size:12px;color:#b45309">المنتجات: <strong>' + unmNames.join('، ') + '</strong></div>' : '';
                    successDiv.innerHTML =
                        '<div style="background:#ecfdf5;border:1px solid #86efac;border-radius:10px;padding:18px;text-align:right;color:#065f46;font-size:13px;direction:rtl;line-height:1.7">' +
                        '<div style="font-size:15px;font-weight:700;margin-bottom:6px">✅ تم استلام طلبك بنجاح</div>' +
                        '<div style="color:#047857">فريق الدعم هيجهّز ملفاتك ويبعتهالك على واتساب في أقرب وقت. مش محتاج تعمل حاجة تاني.</div>' +
                        unmHtml +
                        '</div>';
                    if (typeof wplLoadMyRequests === 'function') wplLoadMyRequests();
                    return;
                }
                if (autoApproved && matched.length) {
                    successDiv.innerHTML =
                        '<div class="wpl-install-status-card">' +
                            // الهيدر
                            '<div class="wpl-isc-header">' +
                                '<span class="wpl-isc-header__icon">⚡</span>' +
                                '<div>' +
                                    '<div class="wpl-isc-header__title">جاري التفعيل التلقائي</div>' +
                                    '<div class="wpl-isc-header__sub">رقم الطلب: <strong>#' + orderNumber + '</strong></div>' +
                                '</div>' +
                            '</div>' +
                            // الـ statuses
                            '<div class="wpl-isc-statuses">' +
                                '<div class="wpl-isc-status-row">' +
                                    '<span class="wpl-isc-status-lbl">حالة الطلب</span>' +
                                    '<span class="wpl-badge wpl-badge--pending" id="wpl-req-status-badge">⏳ قيد التنفيذ</span>' +
                                '</div>' +
                                '<div class="wpl-isc-status-row">' +
                                    '<span class="wpl-isc-status-lbl">حالة الملفات</span>' +
                                    '<span class="wpl-badge wpl-badge--installing" id="wpl-files-status-badge">🔄 جاري التنصيب</span>' +
                                '</div>' +
                            '</div>' +
                            // Progress bar
                            '<div class="wpl-isc-progress-wrap">' +
                                '<div class="wpl-isc-progress-track">' +
                                    '<div class="wpl-isc-progress-bar" id="wpl-instant-bar"></div>' +
                                '</div>' +
                                '<div class="wpl-isc-progress-label" id="wpl-instant-label">⏳ جاري التحضير...</div>' +
                            '</div>' +
                            // زرار بعد الانتهاء
                            '<button class="wpl-req-submit" style="display:none;margin-top:4px" id="wpl-instant-done-btn" onclick="wplWplSessionReset()">⚡ تنصيب إضافات طلب آخر</button>' +
                        '</div>';

                    // جيب المنتجات وثبّتها
                    function wplDoInstallProducts() {
                    $.post(WPL.ajax_url, { action: 'wpl_fetch_products', nonce: WPL.nonce, search: '', category: '', wpl_ids: WPL.wpl_ids || '' }, function(pRes) {
                        if (!pRes.success) {
                            var lbl = document.getElementById('wpl-instant-label');
                            if (lbl) lbl.innerHTML =
                                '⚠️ يجب إدخال السيريال أولاً من تبويب <strong>الملفات</strong> — ثم اضغط إعادة المحاولة.' +
                                '<br><button class="wpl-btn wpl-btn--warning" style="margin-top:10px" onclick="wplDoInstallProducts()">🔄 إعادة المحاولة</button>';
                            window.wplDoInstallProducts = wplDoInstallProducts;
                            return;
                        }
                        var pRawData = pRes.data || {};
                        var products = Array.isArray(pRawData) ? pRawData : (pRawData.products || []);
                        var filesToInstall = [];
                        var matchedIds  = matched.map(function(m){ return typeof m==='object' && m.wpl_id != null ? String(m.wpl_id) : null; }).filter(Boolean);

                        products.forEach(function(p) {
                            if (matchedIds.indexOf(String(p.id)) === -1) return;
                            var isTheme = (p.category === 'theme');
                            p.files.forEach(function(f) { filesToInstall.push({ filename: f.filename, is_theme: isTheme }); });
                        });

                        var total = filesToInstall.length;
                        if (!total) {
                            document.getElementById('wpl-files-status-badge').className = 'wpl-badge wpl-badge--done';
                            document.getElementById('wpl-files-status-badge').textContent = '✅ تم التنصيب';
                            document.getElementById('wpl-req-status-badge').className = 'wpl-badge wpl-badge--pending';
                            document.getElementById('wpl-req-status-badge').textContent = '⏳ بانتظار تأكيد الأدمن';
                            document.getElementById('wpl-instant-label').textContent = '✅ الملفات مثبّتة — بانتظار تأكيد الأدمن';
                            document.getElementById('wpl-instant-bar').style.width = '100%';
                            document.getElementById('wpl-instant-done-btn').style.display = 'inline-block';
                            setTimeout(function() { wplLoadMyRequests(); }, 300);
                            return;
                        }

                        var installedCount = 0;
                        var activatedCount = 0;
                        function installNext(idx) {
                            if (idx >= total) {
                                document.getElementById('wpl-instant-bar').style.width = '100%';
                                document.getElementById('wpl-files-status-badge').className = 'wpl-badge wpl-badge--done';
                                // اعرض المنصّب/الكل — لو كل الملفات اتنصبت اعرض total
                                var doneLabel = installedCount >= total
                                    ? '✅ تم التنصيب (' + total + '/' + total + ')'
                                    : '✅ تم التنصيب (' + installedCount + '/' + total + ')';
                                document.getElementById('wpl-files-status-badge').textContent = doneLabel;
                                document.getElementById('wpl-req-status-badge').className = 'wpl-badge wpl-badge--pending';
                                document.getElementById('wpl-req-status-badge').textContent = '⏳ بانتظار تأكيد الأدمن';
                                var summaryLabel = installedCount >= total
                                    ? '🎉 تم تنصيب ' + total + ' ملف بنجاح — بانتظار تأكيد الأدمن'
                                    : '⚠️ تم تنصيب ' + installedCount + ' من ' + total + ' ملف — بانتظار تأكيد الأدمن';
                                document.getElementById('wpl-instant-label').textContent = summaryLabel;
                                document.getElementById('wpl-instant-done-btn').style.display = 'inline-block';
                                if (window._wplInstantReqId && window.markAutoInstalled) window.markAutoInstalled(window._wplInstantReqId);
                                if (window._wplInstantOrderNum && window.markAutoInstalled) window.markAutoInstalled('order_' + window._wplInstantOrderNum);
                                setTimeout(function() { wplLoadMyRequests(); }, 300);
                                return;
                            }

                            var pct = Math.round((idx / total) * 100);
                            document.getElementById('wpl-instant-bar').style.width = pct + '%';
                            document.getElementById('wpl-instant-label').textContent = '⬇️ (' + (idx+1) + '/' + total + ') جاري تنصيب: ' + filesToInstall[idx].filename;
                            document.getElementById('wpl-files-status-badge').textContent = '⬇️ جاري التنصيب (' + (idx+1) + '/' + total + ')';

                            var curFile    = filesToInstall[idx];
                            var curIsTheme = curFile.is_theme || false;
                            $.post(WPL.ajax_url, { action: 'wpl_install_file', nonce: WPL.nonce, filename: curFile.filename, is_theme: curIsTheme ? '1' : '0' }, function(r) {
                                if (r && r.success) installedCount++; // عدّ التنصيب الناجح بغض النظر عن التفعيل
                                var pluginFile = r && r.data && r.data.plugin_file ? r.data.plugin_file : '';
                                var rIsTheme   = (r && r.data && r.data.is_theme !== undefined && r.data.is_theme !== null) ? !!r.data.is_theme : curIsTheme;
                                if (pluginFile) {
                                    document.getElementById('wpl-instant-label').textContent = '▶️ (' + (idx+1) + '/' + total + ') جاري تنشيط: ' + curFile.filename;
                                    document.getElementById('wpl-files-status-badge').textContent = '▶️ جاري التنشيط (' + (idx+1) + '/' + total + ')';
                                    $.post(WPL.ajax_url, { action: 'wpl_toggle_plugin', nonce: WPL.nonce, plugin_file: pluginFile, toggle_action: 'activate', is_theme: rIsTheme ? '1' : '0' }, function(ar) {
                                        if (ar && ar.success) activatedCount++;
                                        installNext(idx + 1);
                                    }).fail(function() { installNext(idx + 1); });
                                } else {
                                    installNext(idx + 1);
                                }
                            }).fail(function() { installNext(idx + 1); });
                        }
                        installNext(0);
                    }).fail(function() {
                        // 403 أو خطأ شبكة — اعرض رسالة مع زرار إعادة المحاولة
                        var lbl = document.getElementById('wpl-instant-label');
                        if (lbl) lbl.innerHTML =
                            '⚠️ يجب إدخال السيريال أولاً من تبويب <strong>الملفات</strong> — ثم اضغط إعادة المحاولة.' +
                            '<br><button class="wpl-btn wpl-btn--warning" style="margin-top:10px" onclick="wplDoInstallProducts()">🔄 إعادة المحاولة</button>';
                        window.wplDoInstallProducts = wplDoInstallProducts;
                    });
                    } // end wplDoInstallProducts
                    window.wplDoInstallProducts = wplDoInstallProducts;
                    wplDoInstallProducts();

                    wplLockTokenButtons(false);

                } else {
                    // pending — الأدمن هيوافق يدوياً
                    successDiv.innerHTML =
                        '<div class="wpl-install-status-card">' +
                            '<div class="wpl-isc-header">' +
                                '<span class="wpl-isc-header__icon">✅</span>' +
                                '<div>' +
                                    '<div class="wpl-isc-header__title">تم إرسال الطلب بنجاح!</div>' +
                                    '<div class="wpl-isc-header__sub">رقم الطلب: <strong>#' + orderNumber + '</strong></div>' +
                                '</div>' +
                            '</div>' +
                            '<div class="wpl-isc-statuses">' +
                                '<div class="wpl-isc-status-row">' +
                                    '<span class="wpl-isc-status-lbl">حالة الطلب</span>' +
                                    '<span class="wpl-badge wpl-badge--pending">⏳ بانتظار مراجعة الأدمن</span>' +
                                '</div>' +
                            '</div>' +
                            '<p style="font-size:13px;color:#64748b;margin:0;padding:0 4px;direction:rtl">سيتم مراجعة طلبك وتفعيل إضافاتك في أقرب وقت ممكن.</p>' +
                            '<button class="wpl-req-submit" style="margin-top:4px" onclick="wplWplSessionReset()">⚡ تقديم طلب جديد</button>' +
                        '</div>';
                    if (typeof wplLoadMyRequests === 'function') wplLoadMyRequests();
                    if (typeof wplLockTokenButtons === 'function') wplLockTokenButtons(true);
                }
            } else {
                wplActShowError(res.data || 'حدث خطأ، حاول مرة أخرى.');
            }
        }).fail(function() {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = '⚡ إرسال طلب التفعيل'; }
            wplShowRetryCountdown(orderNumber);
        });
    };

    /* ====== Auto-Retry Countdown ====== */
    var _wplRetryTimer = null;

    function wplShowRetryCountdown(orderNumber) {
        var seconds = 10;
        var errEl = document.getElementById('wpl-act-error');
        if (!errEl) return;

        if (_wplRetryTimer) { clearInterval(_wplRetryTimer); _wplRetryTimer = null; }

        function render(s) {
            var circ   = 94.2;
            var offset = circ - (circ * s / 10);
            errEl.style.display = 'block';
            errEl.innerHTML =
                '<div style="display:flex;align-items:center;gap:14px;direction:rtl;flex-wrap:wrap">' +
                    // دايرة العداد
                    '<div style="position:relative;width:48px;height:48px;flex-shrink:0">' +
                        '<svg width="48" height="48" style="transform:rotate(-90deg)">' +
                            '<circle cx="24" cy="24" r="20" fill="none" stroke="#bae6fd" stroke-width="3.5"/>' +
                            '<circle cx="24" cy="24" r="20" fill="none" stroke="#0ea5e9" stroke-width="3.5"' +
                                ' stroke-dasharray="' + circ + '" stroke-dashoffset="' + offset + '"' +
                                ' style="transition:stroke-dashoffset 1s linear;stroke-linecap:round"/>' +
                        '</svg>' +
                        '<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#0f1f3d">' + s + '</span>' +
                    '</div>' +
                    // النص
                    '<div style="flex:1;min-width:180px">' +
                        '<div style="font-size:14px;font-weight:700;color:#0f1f3d;margin-bottom:4px">⏳ السيرفر مشغول لحظياً</div>' +
                        '<div style="font-size:12px;color:#0369a1;line-height:1.6">' +
                            'لا تقلق — سيتم إعادة الإرسال تلقائياً خلال <strong>' + s + '</strong> ' + (s === 1 ? 'ثانية' : 'ثوانٍ') +
                        '</div>' +
                    '</div>' +
                    // زرار إلغاء
                    '<button onclick="wplCancelRetry()" style="background:none;border:1.5px solid #bae6fd;border-radius:8px;padding:6px 12px;font-size:11px;color:#0369a1;cursor:pointer;font-family:inherit;white-space:nowrap;flex-shrink:0;transition:all .2s" onmouseover="this.style.background=\'#e0f2fe\'" onmouseout="this.style.background=\'none\'">✕ إلغاء</button>' +
                '</div>';
        }

        render(seconds);

        _wplRetryTimer = setInterval(function() {
            seconds--;
            if (seconds <= 0) {
                clearInterval(_wplRetryTimer);
                _wplRetryTimer = null;
                var el = document.getElementById('wpl-act-error');
                if (el) {
                    el.style.display = 'block';
                    el.innerHTML = '<div style="color:#0369a1;font-size:13px;direction:rtl;display:flex;align-items:center;gap:8px"><span style="animation:wpl-spin 1s linear infinite;display:inline-block">🔄</span> جاري إعادة الإرسال...</div>';
                }
                if (typeof window.wplSendActivationRequest === 'function') {
                    window.wplSendActivationRequest();
                }
            } else {
                render(seconds);
            }
        }, 1000);
    }

    window.wplCancelRetry = function() {
        if (_wplRetryTimer) { clearInterval(_wplRetryTimer); _wplRetryTimer = null; }
        wplActShowError('تعذّر الاتصال بالسيرفر — اضغط الزرار لإعادة المحاولة يدوياً.');
    };

    /* بلّغ السيرفر إن التنصيب اكتمل */
    window.wplMarkInstalled = function(orderNum) {
        jQuery.post(WPL.ajax_url, {
            action:       'wpl_mark_installed',
            nonce:        WPL.nonce,
            order_number: orderNum,
        });
    };

    /* إعادة ضبط الفورم لطلب جديد */
    window.wplResetActivationForm = function() {
        var orderInput = document.getElementById('wpl-order-input');
        if (orderInput) orderInput.value = '';
        wplRemoveScreenshot();
        wplActClearError();
        document.getElementById('wpl-act-success').style.display = 'none';
        document.getElementById('wpl-act-form').style.display    = 'block';
    };

    /* قفل/فتح أزرار الرابط المؤقت */
    window.wplLockTokenButtons = function(lock) {
        var warn = document.getElementById('wpl-pending-warning');
        ['wpl_generate_token', 'wpl_disable_token'].forEach(function(action) {
            var btn = document.querySelector('input[name="action"][value="' + action + '"]');
            if (btn) {
                var button = btn.closest('form').querySelector('button[type="submit"]');
                if (button) {
                    button.disabled = lock;
                    button.title    = lock ? 'يوجد طلب تفعيل قيد الانتظار' : '';
                }
            }
        });
        if (warn) warn.style.display = lock ? 'block' : 'none';
    };

    /* Enter في حقل رقم الطلب */
    $(document).ready(function() {
        var inp = document.getElementById('wpl-order-input');
        if (inp) {
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') window.wplSendActivationRequest();
            });
        }
    });

    /* إعادة ضبط للـ WPL session (بدون scroll أو تعقيد) */
    window.wplWplSessionReset = function() {
        var actForm    = document.getElementById('wpl-act-form');
        var actSuccess = document.getElementById('wpl-act-success');
        var actSubmit  = document.getElementById('wpl-act-submit');
        var orderInput = document.getElementById('wpl-order-input');
        if (actSuccess) actSuccess.style.display = 'none';
        if (actForm)    actForm.style.display    = 'block';
        if (actSubmit)  { actSubmit.disabled = false; actSubmit.textContent = '⚡ تثبيت'; }
        if (orderInput) { orderInput.value = ''; orderInput.focus(); }
        wplRemoveScreenshot();
        // مسح الـ error
        var errEl = document.getElementById('wpl-act-error');
        if (errEl) errEl.style.display = 'none';
        // أعد تحميل المنتجات
        if (typeof wplLoadProducts === 'function') wplLoadProducts();
    };

})(jQuery);

/* ====================================================
   متابعة طلبات التفعيل
   ==================================================== */
(function($) {


    // ====== مسح الكاش ======
    window.wplClearCache = function() {
        localStorage.removeItem("wpl_auto_installed");
        localStorage.removeItem("wpl_failed_installs");
        jQuery.post(WPL.ajax_url, { action: "wpl_clear_cache", nonce: WPL.nonce });
        var btn = document.querySelector('button[onclick="wplClearCache()"]');
        if (btn) { var orig = btn.textContent; btn.textContent = "✅ تم المسح"; btn.disabled = true; setTimeout(function(){ btn.textContent = orig; btn.disabled = false; }, 2000); }
        setTimeout(function() { wplLoadMyRequests(); }, 600);
    };

    // ====== تتبع الطلبات اللي اتثبّتت تلقائياً (محلي في WP) ======
    function getAutoInstalled() {
        try { return JSON.parse(localStorage.getItem('wpl_auto_installed') || '[]'); }
        catch(e) { return []; }
    }
    function markAutoInstalled(reqId) {
        var arr = getAutoInstalled();
        if (arr.indexOf(reqId) === -1) arr.push(reqId);
        localStorage.setItem('wpl_auto_installed', JSON.stringify(arr));
    }
    // expose globally عشان الـ IIFEs التانية تقدر تستخدمهم
    window.getAutoInstalled  = getAutoInstalled;
    window.markAutoInstalled = markAutoInstalled;
    function getFailedInstalls() {
        try { return JSON.parse(localStorage.getItem('wpl_failed_installs') || '[]'); }
        catch(e) { return []; }
    }
    function markFailedInstall(reqId) {
        var arr = getFailedInstalls();
        if (arr.indexOf(reqId) === -1) arr.push(reqId);
        localStorage.setItem('wpl_failed_installs', JSON.stringify(arr));
    }

    // ====== تثبيت تلقائي لمنتجات من matched_products ======
    function wplAutoInstallForRequest(req) {
        var matched = req.matched_products || [];
        if (!matched.length) return;

        $.post(WPL.ajax_url, { action: 'wpl_fetch_products', nonce: WPL.nonce, search: '', category: '', wpl_ids: WPL.wpl_ids || '' }, function(res) {
            if (!res.success) {
                // السيريال مش متحقق — ما نعلّمش فشل، نسيب الطلب يتعاد عند التحقق من السيريال
                return;
            }
            var products = res.data || [];
            var filesToInstall = [];

            var matchedIds = matched.map(function(m) {
                return typeof m === 'object' && m.wpl_id != null ? String(m.wpl_id) : null;
            }).filter(Boolean);

            products.forEach(function(p) {
                if (matchedIds.indexOf(String(p.id)) === -1) return;
                var isTheme = (p.category === 'theme');
                p.files.forEach(function(f) { filesToInstall.push({ filename: f.filename, is_theme: isTheme }); });
            });

            if (!filesToInstall.length) {
                markAutoInstalled(req.id);
                wplLoadMyRequests();
                return;
            }

            showAutoInstallProgress(req.order_number, filesToInstall.length);
            var failCount     = 0;
            var installedCount = 0;

            function installNext(index) {
                if (index >= filesToInstall.length) {
                    if (failCount > 0 && failCount === filesToInstall.length) {
                        markFailedInstall(req.id);
                    } else {
                        markAutoInstalled(req.id);
                    }
                    hideAutoInstallProgress(req.order_number, installedCount, filesToInstall.length);
                    setTimeout(function() {
                        wplLoadMyRequests();
                        if (typeof wplLoadProducts === 'function') wplLoadProducts();
                    }, 300);
                    return;
                }
                var item    = filesToInstall[index];
                var isTheme = item.is_theme || false;
                updateAutoInstallProgress(req.order_number, index + 1, filesToInstall.length, item.filename);

                $.post(WPL.ajax_url, { action: 'wpl_install_file', nonce: WPL.nonce, filename: item.filename, is_theme: isTheme ? '1' : '0' }, function(r) {
                    if (r && r.success) installedCount++;
                    var pluginFile = r && r.data && r.data.plugin_file ? r.data.plugin_file : '';
                    var rIsTheme   = (r && r.data && r.data.is_theme !== undefined && r.data.is_theme !== null) ? !!r.data.is_theme : isTheme;
                    if (pluginFile) {
                        $.post(WPL.ajax_url, { action: 'wpl_toggle_plugin', nonce: WPL.nonce, plugin_file: pluginFile, toggle_action: 'activate', is_theme: rIsTheme ? '1' : '0' }, function() {
                            installNext(index + 1);
                        }).fail(function() { installNext(index + 1); });
                    } else {
                        installNext(index + 1);
                    }
                }).fail(function() { failCount++; installNext(index + 1); });
            }
            installNext(0);
        }).fail(function() {
            // 403 = سيريال مش متحقق — ما نعلّمش فشل، نسيب للـ polling يعيد المحاولة
            // خطأ شبكة حقيقي: نعلّم فشل
        });
    }

    // ====== Progress UI ======
    function showAutoInstallProgress(orderNum, total) {
        var container = document.getElementById('wpl-requests-container');
        if (!container) return;
        var bar = document.getElementById('wpl-auto-progress-' + orderNum);
        if (bar) return;
        var div = document.createElement('div');
        div.id = 'wpl-auto-progress-' + orderNum;
        div.style.cssText = 'background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:16px 20px;margin-bottom:12px;direction:rtl';
        div.innerHTML =
            '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">' +
                '<span style="font-size:18px">⚡</span>' +
                '<strong style="color:#166534;font-size:14px">جاري التثبيت التلقائي للطلب #' + orderNum + '</strong>' +
            '</div>' +
            '<div style="background:#dcfce7;border-radius:6px;overflow:hidden;height:8px;margin-bottom:8px">' +
                '<div id="wpl-prog-bar-' + orderNum + '" style="background:#0ea5e9;height:100%;width:0%;transition:width .4s"></div>' +
            '</div>' +
            '<div id="wpl-prog-label-' + orderNum + '" style="font-size:12px;color:#166534">⏳ جاري التحضير...</div>';
        container.insertBefore(div, container.firstChild);
    }

    function updateAutoInstallProgress(orderNum, done, total, filename) {
        var bar   = document.getElementById('wpl-prog-bar-'   + orderNum);
        var label = document.getElementById('wpl-prog-label-' + orderNum);
        if (bar)   bar.style.width = Math.round((done / total) * 100) + '%';
        if (label) label.textContent = '⬇️ (' + done + '/' + total + ') ' + filename;
    }

    function hideAutoInstallProgress(orderNum, installedCount, total) {
        var div   = document.getElementById('wpl-auto-progress-' + orderNum);
        var bar   = document.getElementById('wpl-prog-bar-'      + orderNum);
        var label = document.getElementById('wpl-prog-label-'    + orderNum);
        if (bar)   bar.style.width = '100%';
        var msg = installedCount >= total
            ? '✅ تم تثبيت ' + total + '/' + total + ' ملف بنجاح!'
            : '⚠️ تم تثبيت ' + installedCount + '/' + total + ' ملف';
        if (label) label.innerHTML = msg;
        if (div)   setTimeout(function() { if(div.parentNode) div.parentNode.removeChild(div); }, 4000);
    }

    // ====== تحميل الطلبات + منطق التثبيت التلقائي ======
    window.wplLoadMyRequests = function() {
        var container = document.getElementById('wpl-requests-container');
        if (!container) return;

        container.innerHTML = '<div class="wpl-loading"><span class="wpl-spinner"></span> جاري التحميل...</div>';

        $.post(WPL.ajax_url, {
            action: 'wpl_fetch_my_requests',
            nonce:  WPL.nonce,
        }, function(res) {
            if (!res || !res.success) {
                container.innerHTML = '<div class="wpl-empty" style="padding:20px;text-align:center;color:#94a3b8;font-size:13px">لا توجد طلبات مسجّلة بعد.</div>';
                return;
            }
            var data = res.data || [];
            if (!data.length) {
                container.innerHTML = '<div class="wpl-empty" style="padding:20px;text-align:center;color:#94a3b8;font-size:13px">لا توجد طلبات مسجّلة بعد.</div>';
                return;
            }

            var hasPending = data.some(function(r){ return r.status === 'pending'; });
            window.wplLockTokenButtons(hasPending);

            // ====== تثبيت تلقائي للطلبات المقبولة ======
            var autoInstalled = getAutoInstalled();
            data.forEach(function(r) {
                if (r.status === 'done' &&
                    r.matched_products && r.matched_products.length &&
                    autoInstalled.indexOf(r.id) === -1 &&
                    autoInstalled.indexOf('order_' + r.order_number) === -1) {
                    wplAutoInstallForRequest(r);
                }
            });

            // ====== رسم الطلبات ======
            var html = '<div class="wpl-req-list">';
            data.forEach(function(r) {
                var statusHtml, statusClass;
                if (r.status === 'pending') {
                    statusClass = 'wpl-req-status--pending';
                    statusHtml  = '⏳ قيد الانتظار';
                } else if (r.status === 'done') {
                    statusClass = 'wpl-req-status--done';
                    statusHtml  = '✅ تم التفعيل';
                } else if (r.status === 'rejected') {
                    statusClass = 'wpl-req-status--rejected';
                    statusHtml  = '❌ مرفوض';
                } else {
                    statusClass = '';
                    statusHtml  = r.status;
                }

                // حالة الملفات — تظهر دايماً بـ inline styles بالكامل
                var hasMatched   = r.matched_products && r.matched_products.length;
                var alreadyDone  = getAutoInstalled().indexOf(r.id) !== -1 ||
                                   getAutoInstalled().indexOf('order_' + r.order_number) !== -1;
                var failedInstall = getFailedInstalls().indexOf(r.id) !== -1;
                var S = 'display:inline-block;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;';
                var filesStatusHtml = '';
                if (r.status === 'rejected') {
                    filesStatusHtml = '<span style="' + S + 'background:#fee2e2;color:#991b1b">❌ ملغى</span>';
                } else if (failedInstall) {
                    filesStatusHtml = '<span style="' + S + 'background:#fff7ed;color:#c2410c">⚠️ فشل التنصيب</span>';
                } else if (alreadyDone) {
                    filesStatusHtml = '<span style="' + S + 'background:#dcfce7;color:#166534">✅ تم التنصيب</span>';
                } else if (r.status === 'approved' || r.status === 'done') {
                    // approved = بيتنصب دلوقتي
                    filesStatusHtml = '<span style="' + S + 'background:#dbeafe;color:#1e40af">⏳ قيد التنصيب</span>';
                } else {
                    // pending = لسه استنى
                    filesStatusHtml = '<span style="' + S + 'background:#fef9c3;color:#854d0e">⏳ قيد التنصيب</span>';
                }

                var date = new Date(r.created * 1000);
                var dateStr = ('0'+date.getDate()).slice(-2) + '/' +
                              ('0'+(date.getMonth()+1)).slice(-2) + '/' +
                              date.getFullYear() + ' ' +
                              ('0'+date.getHours()).slice(-2) + ':' +
                              ('0'+date.getMinutes()).slice(-2);

                // أسماء WooCommerce
                var productsHtml = '';
                if (r.products && r.products.length) {
                    productsHtml = r.products.map(function(p) {
                        return '<span class="wpl-badge wpl-badge--blue" style="font-size:11px">' + esc(p) + '</span>';
                    }).join(' ');
                }

                // الملفات المطابقة في WPL
                var matchedHtml = '';
                if (r.matched_products && r.matched_products.length) {
                    matchedHtml = r.matched_products.map(function(m) {
                        var wplName = typeof m === 'object' ? (m.wpl_name || '') : m;
                        var wcName  = typeof m === 'object' ? (m.wc_name  || '') : '';
                        var wcId    = typeof m === 'object' ? (m.wc_item_id || 0) : 0;
                        return '<span class="wpl-badge" style="background:#dcfce7;color:#166534;font-size:11px">📦 ' + esc(wplName) +
                               (wcId ? ' <span style="opacity:.7;font-size:10px">#' + wcId + '</span>' : '') + '</span>';
                    }).join(' ');
                }

                html += '<div class="wpl-req-item">' +
                    '<div class="wpl-req-main">' +
                        '<div class="wpl-req-order">' +
                            '<span class="wpl-req-icon">🛒</span>' +
                            '<strong>طلب رقم #' + esc(r.order_number) + '</strong>' +
                        '</div>' +
                        (matchedHtml ? '<div class="wpl-req-products" style="margin-bottom:3px">' + matchedHtml + '</div>' : '') +
                        (productsHtml ? '<div class="wpl-req-products">' + productsHtml + '</div>' : '') +
                        '<div class="wpl-req-date">📅 ' + dateStr + '</div>' +
                    '</div>' +
                    '<div class="wpl-req-statuses">' +
                        '<div class="wpl-req-status-row">' +
                            '<span class="wpl-req-status-label">حالة الطلب</span>' +
                            '<span class="wpl-req-status ' + statusClass + '">' + statusHtml + '</span>' +
                        '</div>' +
                        '<div class="wpl-req-status-row">' +
                            '<span class="wpl-req-status-label">حالة الملفات</span>' +
                            filesStatusHtml +
                        '</div>' +
                    '</div>' +
                '</div>';
            });
            html += '</div>';
            container.innerHTML = html;
        }).fail(function() {
            container.innerHTML = '<div class="wpl-error">❌ تعذّر تحميل الطلبات.</div>';
        });
    };

    function esc(str) { return $('<div>').text(str || '').html(); }

    // Requests load only when tab clicked or manually triggered

})(jQuery);

