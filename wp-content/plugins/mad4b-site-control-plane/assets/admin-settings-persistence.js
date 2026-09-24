(function () {
	"use strict";

	function cfg() {
		return window.MAD4BAdminSettingsPersistence || {};
	}

	function feedbackNode(form) {
		return form.querySelector("[data-mad4b-settings-feedback]");
	}

	function setFeedback(form, message, ok, code) {
		var node = feedbackNode(form);
		if (!node) return;
		node.className = "mad4b-settings-feedback " + (ok ? "is-success" : "is-error");
		node.style.margin = "8px 0";
		node.style.fontWeight = "600";
		node.style.color = ok ? "#008a20" : "#b32d2e";
		node.textContent = (code ? code + " · " : "") + (message || "");
	}

	function clearOneTimeConfirmations(form) {
		form.querySelectorAll("[data-mad4b-one-time-confirm]").forEach(function (field) {
			if (field.type === "checkbox" || field.type === "radio") field.checked = false;
			else field.value = "";
		});
	}

	async function refreshSelector(selector) {
		if (!selector) return;
		var response = await fetch(window.location.href, {
			credentials: "same-origin",
			cache: "no-store",
			headers: { "Cache-Control": "no-cache", "X-MAD4B-Settings-Readback": "1" }
		});
		if (!response.ok) throw new Error(cfg().refreshFailed || "Persisted view refresh failed.");
		var html = await response.text();
		var doc = new DOMParser().parseFromString(html, "text/html");
		var current = document.querySelector(selector);
		var next = doc.querySelector(selector);
		if (current && next) current.replaceWith(next);
	}

	document.addEventListener("submit", async function (event) {
		var form = event.target.closest(".mad4b-settings-ajax-form");
		if (!form) return;
		event.preventDefault();
		if (form.dataset.mad4bBusy === "1") return;

		form.dataset.mad4bBusy = "1";
		form.setAttribute("aria-busy", "true");
		var controls = Array.prototype.slice.call(form.querySelectorAll("button,input,select,textarea"));
		var priorDisabled = controls.map(function (control) { return control.disabled; });
		controls.forEach(function (control) { control.disabled = true; });
		setFeedback(form, cfg().saving || "Saving…", true, "");

		try {
			var body = new URLSearchParams(new FormData(form));
			var response = await fetch(cfg().ajaxUrl || window.ajaxurl, {
				method: "POST",
				credentials: "same-origin",
				cache: "no-store",
				headers: {
					"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
					"X-Requested-With": "XMLHttpRequest",
					"Accept": "application/json"
				},
				body: body.toString()
			});
			var responseText = await response.text();
			var trimmedResponse = (responseText || "").trim();

			// WordPress admin-ajax returns the literal sentinel "0" when the
			// requested action is not registered in that request lifecycle.
			// Ordinary settings forms also retain their existing admin-post.php
			// endpoint, so fall back only for this exact sentinel. Restore
			// controls first because disabled controls are excluded from native
			// form submission. Governance/destructive actions never opt into
			// this shared settings form contract.
			if (response.status === 400 && trimmedResponse === "0") {
				controls.forEach(function (control, index) { control.disabled = priorDisabled[index]; });
				form.removeAttribute("aria-busy");
				delete form.dataset.mad4bBusy;
				if (window.HTMLFormElement && window.HTMLFormElement.prototype && typeof window.HTMLFormElement.prototype.submit === "function") {
					window.HTMLFormElement.prototype.submit.call(form);
					return;
				}
				var sentinelFailure = new Error("AJAX settings action was not registered and native fallback is unavailable.");
				sentinelFailure.mad4bCode = "mad4b_settings_ajax_action_unregistered";
				throw sentinelFailure;
			}

			var payload;
			try {
				payload = JSON.parse(responseText);
			} catch (parseError) {
				var nonJsonFailure = new Error("Settings endpoint returned a non-JSON response (HTTP " + response.status + ").");
				nonJsonFailure.mad4bCode = "mad4b_settings_non_json_response";
				throw nonJsonFailure;
			}
			if (!payload || !payload.success) {
				var failure = new Error(payload && payload.data && payload.data.message ? payload.data.message : (cfg().failed || "Settings could not be persisted."));
				failure.mad4bCode = payload && payload.data && payload.data.code ? payload.data.code : "mad4b_settings_persist_failed";
				throw failure;
			}
			if (!payload.data || payload.data.persistence_verified !== true) {
				var verifyFailure = new Error("Server did not confirm persisted readback.");
				verifyFailure.mad4bCode = "mad4b_settings_readback_unverified";
				throw verifyFailure;
			}

			clearOneTimeConfirmations(form);
			if (payload.data.readback && payload.data.readback.revision !== undefined) {
				var revision = form.querySelector('input[name="expected_revision"]');
				if (revision) revision.value = String(payload.data.readback.revision);
			}
			if (payload.data.readback && payload.data.readback.profile_digest) {
				var digest = form.querySelector('input[name="expected_profile_digest"]');
				if (digest) digest.value = String(payload.data.readback.profile_digest);
			}
			var successMessage = payload.data.message || cfg().saved || "Saved and verified.";
			var selector = form.getAttribute("data-mad4b-refresh-selector") || "";
			var feedbackForm = form;
			if (selector) {
				await refreshSelector(selector);
				var refreshed = document.querySelector(selector);
				if (refreshed) {
					feedbackForm = refreshed.matches && refreshed.matches(".mad4b-settings-ajax-form")
						? refreshed
						: (refreshed.querySelector ? refreshed.querySelector(".mad4b-settings-ajax-form") || form : form);
				}
			}
			setFeedback(feedbackForm, successMessage, true, "");
			document.dispatchEvent(new CustomEvent("mad4b:settings-persisted", { detail: payload.data }));
		} catch (error) {
			setFeedback(form, error && error.message ? error.message : (cfg().failed || "Settings could not be persisted."), false, error && error.mad4bCode ? error.mad4bCode : "");
		} finally {
			form.removeAttribute("aria-busy");
			delete form.dataset.mad4bBusy;
			controls.forEach(function (control, index) { control.disabled = priorDisabled[index]; });
		}
	});
})();