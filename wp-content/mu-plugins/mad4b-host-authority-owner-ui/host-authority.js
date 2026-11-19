/* MAD4B Host Authority v106: browser owner approval, existing Original305 REST only. */
/* v114: state-driven owner console; existing REST contracts and authority gates remain unchanged. */
(() => {
  "use strict";
  const config = window.MAD4BHostAuthorityV106;
  const app = document.getElementById("mad4b-host-authority-app");
  if (!app || !config || config.owner !== true) return;

  const $ = (id) => document.getElementById(id);
  const grantButton = $("mad4b-ha-grant");
  const commandButton = $("mad4b-ha-command");
  const refreshButton = $("mad4b-ha-refresh");
  const copyButton = $("mad4b-ha-copy");
  const dispatchButton = $("mad4b-ha-dispatch");
  const planField = $("mad4b-ha-plan");
  const planSummary = $("mad4b-ha-plan-summary");
  const planEditor = $("mad4b-ha-plan-editor");
  const editPlan = $("mad4b-ha-edit-plan");
  const expiredNote = $("mad4b-ha-expired-note");
  const confirmation = $("mad4b-ha-owner-confirm");
  const commandConfirmation = $("mad4b-ha-command-confirm") || confirmation;
  const result = $("mad4b-ha-result");
  const grantPanel = $("mad4b-ha-grant-panel");
  const commandPanel = $("mad4b-ha-command-panel");
  const trackerPanel = $("mad4b-ha-tracker");
  const trackerId = $("mad4b-ha-request-id");
  const trackerStatus = $("mad4b-ha-tracker-status");
  const trackerTitle = $("mad4b-ha-tracker-title");
  const checkedAt = $("mad4b-ha-checked-at");
  const headline = $("mad4b-ha-overall-state");
  if (!grantButton || !commandButton || !planField ||
      !confirmation || !commandConfirmation || !result) return;

  const validId = (s) => typeof s === "string" && /^[a-f0-9]{32}$/.test(s);
  const validDigest = (s) => typeof s === "string" && /^[a-f0-9]{64}$/.test(s);
  const validSite = (s) => typeof s === "string" &&
    /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(s);
  const rootedPlan = (digest, source) => validDigest(digest) &&
    source === "previous_owner_request_reference";
  let planEdited = false;
  if (rootedPlan(config.planSha256,config.planSource)) {
    planField.value = config.planSha256;
    if (planEditor) planEditor.hidden = true;
    if (editPlan) editPlan.hidden = false;
    if (planSummary) planSummary.textContent =
      "Root Plan loaded automatically from the exact owner request. " +
      "Independent Original312/313 verification remains required.";
  }
  const exactSource = config.operation === "company.host-authority.anchor.issue" &&
    config.hostId === "hosting-2" && /^u[0-9]{9}$/.test(config.hostPrincipal || "") &&
    validId(config.transportId) && validSite(config.siteUuid) && !!config.nonce &&
    typeof config.restBase === "string" && config.restBase.startsWith("/wp-json/") &&
    !config.restBase.includes("//") && !config.restBase.includes(":8080");

  let grantId = validId(config.grantId) ? config.grantId : "";
  // Expired Owner Request is audit history only; it cannot be dispatched.
  let requestExpired = config.requestExpired === true && config.brokerJobExists !== true;
  let requestId = !requestExpired && validId(config.requestId) ? config.requestId : "";
  let requestExpiresEpoch = !requestExpired && Number.isInteger(config.requestExpiresEpoch)
    ? config.requestExpiresEpoch : 0;
  let brokerJobExists = !requestExpired && config.brokerJobExists === true;
  let brokerJobState = !requestExpired && typeof config.brokerJobState === "string"
    ? config.brokerJobState : "";
  let signatureWindowExpired = config.signatureWindowExpired === true;
  let requestStatus = typeof config.requestStatus === "string"
    ? config.requestStatus : "ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY";
  let busy = false;
  let refreshing = false;
  let lastError = false;
  let phase = config.phase;
  let anchorState = typeof config.anchorState === "string"
    ? config.anchorState : "MISSING_OR_INVALID";
  let anchorRevision = Number.isInteger(config.anchorRevision)
    ? config.anchorRevision : 0;

  function humanBrokerStatus(status) {
    if (status === "QUEUED_FOR_VPS_BROKER_CLAIM") {
      return "Original306 queued — waiting for independent signed Claim";
    }
    if (status === "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY") {
      return "Original306 Claim accepted — Original312/313 pending";
    }
    return "Original306: " +
      String(status).replace(/[^a-zA-Z0-9_-]/g,"").slice(0,90);
  }

  function humanStatus(status) {
    if (status === "ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY") {
      return "Accepted — awaiting independent Host verification";
    }
    if (status === "EXPIRED_OWNER_REQUEST_RENEWAL_REQUIRED") {
      return "Expired — a fresh Owner Request is required";
    }
    if (status === "QUEUED") return "Queued — awaiting independent processing";
    // Do not convert any Original305 status into an Anchor-issued claim.
    return status.replace(/[^a-zA-Z0-9_-]/g, "").slice(0, 100) || "UNKNOWN";
  }

  const message = (text, isError = false) => {
    lastError = isError;
    result.textContent = text; // Never render REST data as markup.
    if (result.style) result.style.borderLeftColor = isError ? "#b32d2e" : "#2271b1";
    if ("hidden" in result) result.hidden = false;
    if ("classList" in result && result.classList) {
      result.classList.toggle("is-error", isError);
    }
  };

  function trackerUpdate() {
    if (trackerId) trackerId.textContent = requestId;
    if (trackerStatus) trackerStatus.textContent = anchorRevision > 0
      ? "Host Anchor revision " + anchorRevision +
        " observed — inspect independent verification before enabling writes"
      : signatureWindowExpired
      ? "Original306 Claim expired. Original312/313 have not issued a signed Enrollment or Anchor."
      : brokerJobExists
      ? humanBrokerStatus(brokerJobState || "QUEUED_FOR_VPS_BROKER_CLAIM")
      : "Current status: " + humanStatus(requestStatus) +
        " · Original306 Dispatch not confirmed";
    if (headline) headline.textContent = anchorRevision > 0
      ? "Host Anchor changed — independent review required"
      : signatureWindowExpired
      ? "Original306 Claim expired — Original312/313 not issued"
      : requestId
      ? (brokerJobExists
        ? brokerJobState === "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
          ? "Original306 Claim accepted"
          : "Original306 Broker Job queued"
        : "Owner Request accepted — dispatch pending")
      : requestExpired ? "Owner Request expired — renewal required"
      : grantId ? "Bootstrap Grant completed" : "Owner approval required";
  }

  function step(id, state) {
    const node = $("mad4b-ha-step-" + id);
    if (node && node.classList) {
      node.classList.toggle("is-done", state === "done");
      node.classList.toggle("is-current", state === "current");
    }
  }

  const setState = () => {
    const pristine = phase !== "ANCHOR_STATE_REVIEW" &&
      phase !== "INDEPENDENT_SIGNATURE_WINDOW_EXPIRED" &&
      !signatureWindowExpired &&
      anchorRevision === 0 && anchorState === "MISSING_OR_INVALID";
    const hasGrant = validId(grantId);
    const hasRequest = validId(requestId);
    grantButton.disabled = busy || !exactSource || !pristine || hasGrant ||
      hasRequest || !confirmation.checked;
    commandButton.disabled = busy || !exactSource || !pristine || !hasGrant ||
      hasRequest || !commandConfirmation.checked ||
      !validDigest(planField.value.trim().toLowerCase());
    if (refreshButton) refreshButton.disabled = busy || !hasRequest;
    if (copyButton) copyButton.disabled = !hasRequest;
    if (dispatchButton) {
      dispatchButton.hidden = !hasRequest || brokerJobExists;
      dispatchButton.disabled = busy || !exactSource || !hasRequest ||
        brokerJobExists || (requestExpiresEpoch > 0 &&
          Math.floor(Date.now()/1000) >= requestExpiresEpoch);
    }
    if (grantPanel) grantPanel.hidden = !pristine || hasGrant || hasRequest;
    if (commandPanel) commandPanel.hidden = !pristine || !hasGrant || hasRequest;
    if (trackerPanel) trackerPanel.hidden = !hasRequest;
    if (expiredNote) expiredNote.hidden = !requestExpired;
    if (hasRequest && "hidden" in result) result.hidden = !lastError;
    step("grant", hasGrant ? "done" : "current");
    step("request", hasRequest ? "done" : hasGrant ? "current" : "");
    step("verify", brokerJobExists && !signatureWindowExpired ? "current" : "");
    const requestStep = $("mad4b-ha-step-request-status");
    if (requestStep) requestStep.textContent = hasRequest
      ? "Accepted"
      : requestExpired ? "Expired — fresh owner approval" : "Not submitted";
    const verifyStep = $("mad4b-ha-step-verify-status");
    if (verifyStep) verifyStep.textContent = signatureWindowExpired
        ? "Expired · Original312/313 not issued"
        : brokerJobState === "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
        ? "Original306 claimed · 312/313 pending"
        : brokerJobExists ? "Original306 queued · 312/313 pending"
        : "Original306 / 312 / 313";
    trackerUpdate();
  };

  function endpoint(action) {
    if (!/^(grants|commands|commands\/[a-f0-9]{32}|broker-dispatch|broker-jobs\/[a-f0-9]{32}|host-authority-owner-state)$/.test(action)) {
      throw new Error("Unexpected Original305 route.");
    }
    const url = new URL(config.restBase + action, window.location.origin);
    if (url.origin !== window.location.origin || url.protocol !== "https:") {
      throw new Error("Secure same-origin WordPress connection required.");
    }
    return url.href;
  }

  async function original305(action, payload) {
    const options = {
      method: payload === undefined ? "GET" : "POST",
      credentials: "same-origin",
      cache: "no-store",
      redirect: "error",
      headers: { "X-WP-Nonce": config.nonce, "Accept": "application/json" }
    };
    if (payload !== undefined) {
      options.headers["Content-Type"] = "application/json";
      options.body = JSON.stringify(payload);
    }
    const response = await fetch(endpoint(action), options);
    const body = await response.json().catch(() => null);
    if (!response.ok || !body || typeof body !== "object") {
      const code = body && typeof body.code === "string"
        ? body.code.replace(/[^a-zA-Z0-9_-]/g, "").slice(0, 96)
        : "HTTP_" + String(response.status);
      throw new Error("Original305 rejected this request: " + code);
    }
    return body;
  }

  // This is the same authenticated Original305 owner session. Original306
  // independently rechecks its own job admission and never grants host writes.
  async function dispatchOriginal306() {
    if (!validId(requestId) || brokerJobExists) return;
    if (requestExpiresEpoch > 0 &&
        Math.floor(Date.now()/1000) >= requestExpiresEpoch) {
      throw new Error("Original305 request expired. Refresh to renew with fresh owner consent.");
    }
    const receipt = await original305("broker-dispatch", {request_id: requestId});
    if (receipt.job_id !== requestId ||
        receipt.company !== "all-royal-egypt" ||
        receipt.environment !== "staging" ||
        receipt.state !== "QUEUED_FOR_VPS_BROKER_CLAIM" ||
        receipt.host_v2_bootstrap_only !== true ||
        receipt.executed !== false ||
        receipt.plan_sha256 !== planField.value.trim().toLowerCase()) {
      throw new Error("Original306 returned unexpected Broker Job. Refresh to inspect signed state.");
    }
    brokerJobExists = true;
    brokerJobState = receipt.state;
    if (checkedAt) checkedAt.textContent =
      "Original306 Broker Job queued. Awaiting separate signed claim and verification.";
    trackerUpdate();
  }

  // All reads use a private REST GET. The backend rechecks the current
  // WP owner, original328 binding, and exact company/staging scope on every
  // request. Never authorize, dispatch, or sign from a status refresh.
  async function refreshOwnerState(automatic = false) {
    if (refreshing || (automatic && busy)) return;
    refreshing = true;
    try {
      const s = await original305("host-authority-owner-state");
      if (s.company !== "all-royal-egypt" || s.environment !== "staging" ||
          s.owner_verified !== true || s.original328_active !== true ||
          typeof s.phase !== "string" || typeof s.request_expired !== "boolean" ||
          typeof s.broker_job_exists !== "boolean" ||
          typeof s.signature_window_expired !== "boolean" ||
          !Number.isInteger(s.server_epoch)) {
        throw new Error("Owner state did not match the enrolled Staging identity.");
      }
      if (grantId && s.grant_id !== grantId) {
        throw new Error("Bootstrap Grant changed. Reload owner authentication.");
      }
      const formerlyExpired = requestExpired;
      phase = s.phase;
      anchorState = typeof s.anchor_state === "string"
        ? s.anchor_state : "UNKNOWN";
      anchorRevision = Number.isInteger(s.anchor_revision)
        ? s.anchor_revision : 0;
      if (!grantId && validId(s.grant_id)) grantId = s.grant_id;
      brokerJobExists = s.broker_job_exists;
      brokerJobState = typeof s.broker_job_state === "string"
        ? s.broker_job_state : "";
      signatureWindowExpired = s.signature_window_expired;
      requestExpired = s.request_expired && !brokerJobExists;
      requestId = requestExpired ? "" : validId(s.request_id) ? s.request_id : "";
      requestStatus = typeof s.request_status === "string"
        ? s.request_status : "UNKNOWN";
      requestExpiresEpoch = Number.isInteger(s.request_expires_epoch)
        ? s.request_expires_epoch : 0;
      if (requestExpired && !formerlyExpired) {
        // Fresh owner consent is mandatory for a renewed Original305 POST.
        commandConfirmation.checked = false;
      }
      if (!planEdited && rootedPlan(s.plan_sha256,s.plan_source)) {
        planField.value = s.plan_sha256;
        if (planEditor) planEditor.hidden = true;
        if (editPlan) editPlan.hidden = false;
        if (planSummary) planSummary.textContent =
          "Root Plan loaded automatically from the exact owner request. " +
          "Original312/313 independent verification remains required.";
      }
      if (requestExpired && planSummary) {
        planSummary.textContent = validDigest(planField.value)
          ? "Previous Owner Request expired. The same Root Plan reference is available; " +
            "renewal still requires fresh owner consent."
          : "Previous Owner Request expired. A verified Root Plan is required.";
      }
      setState();
      lastError = false;
      if ("hidden" in result && requestId) result.hidden = true;
      if (checkedAt) {
        checkedAt.textContent = "Updated " + new Date(s.server_epoch*1000)
          .toLocaleTimeString() + " · private read-only AJAX";
      }
    } catch (err) {
      if (!automatic) throw err;
      if (checkedAt) checkedAt.textContent =
        "Automatic status check unavailable. Use Check latest status to retry.";
    } finally {
      refreshing = false;
    }
  }

  function randomHex32() {
    if (!window.crypto || typeof window.crypto.getRandomValues !== "function") {
      throw new Error("Browser secure random generator is unavailable.");
    }
    const data = new Uint8Array(16);
    window.crypto.getRandomValues(data);
    return Array.from(data, value => value.toString(16).padStart(2, "0")).join("");
  }

  const execute = async (runner) => {
    if (busy) return;
    busy = true;
    setState();
    try {
      await runner();
    } catch (error) {
      message(error instanceof Error ? error.message :
        "Request failed. No authorization was confirmed.", true);
    } finally {
      busy = false;
      setState();
    }
  };

  grantButton.addEventListener("click", () => execute(async () => {
    if (!confirmation.checked || !exactSource || grantId || requestId) {
      throw new Error("Owner confirmation and unused Original328 scope are required.");
    }
    message("Requesting a non-executing owner Bootstrap Grant from Original305…");
    const receipt = await original305("grants", {
      operation: config.operation,
      host_id: config.hostId,
      host_principal: config.hostPrincipal,
      grant_mode: "persistent",
      transport_id: config.transportId,
      reason: "Verified owner approval for first Host Authority Anchor — All Royal Egypt Staging"
    });
    if (!validId(receipt.grant_id) ||
        receipt.company !== "all-royal-egypt" ||
        receipt.environment !== "staging" ||
        receipt.operation !== config.operation ||
        receipt.host_v2_bootstrap_only !== true ||
        receipt.execution_authorized !== false ||
        receipt.host_v2_execution_authorized !== false ||
        receipt.state !== "CENTRAL_OPERATION_SCOPE_GRANTED") {
      throw new Error("Original305 returned unexpected Grant evidence. Refresh for verified readback.");
    }
    grantId = receipt.grant_id;
    message("Bootstrap Grant completed. No Anchor was issued. Continue with the independently verified Root Plan.");
  }));

  commandButton.addEventListener("click", () => execute(async () => {
    const digest = planField.value.trim().toLowerCase();
    if (!commandConfirmation.checked || !exactSource || !validId(grantId) ||
        !validDigest(digest) || requestId) {
      throw new Error("Owner consent, an existing Grant, and an exact first-party Root Plan SHA-256 are required.");
    }
    const argv = ["mad4b", "host-anchor", "issue", "--plan_sha256=" + digest];
    const witness = {
      authority_mode: "bootstrap",
      operation_id: config.operation,
      plan_sha256: digest,
      site_uuid: config.siteUuid,
      target_host_id: config.hostId
    };
    message("Submitting the signed plan reference to Original305…");
    const accepted = await original305("commands", {
      grant_id: grantId,
      command: null,
      argv,
      verification: witness,
      summary: "Owner-approved first Host Authority Anchor bootstrap — All Royal Egypt Staging",
      correlation_id: randomHex32(),
      target_scope: "host"
    });
    if (!validId(accepted.request_id) ||
        accepted.company !== "all-royal-egypt" ||
        accepted.environment !== "staging" ||
        accepted.host_v2_bootstrap_only !== true ||
        accepted.execution_performed !== false ||
        accepted.status !== "ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY") {
      throw new Error("Original305 returned unexpected request evidence. Refresh to inspect.");
    }
    requestId = accepted.request_id;
    requestStatus = accepted.status;
    requestExpired = false;
    requestExpiresEpoch = Number.isInteger(accepted.expires_epoch)
      ? accepted.expires_epoch : 0;
    brokerJobExists = false;
    // Persist the accepted request in the UI BEFORE dispatch, so a failed
    // broker call never causes a second owner POST or silent duplicate.
    setState();
    if (trackerTitle && typeof trackerTitle.focus === "function") trackerTitle.focus();
    message("Owner Request accepted. Submitting it to Original306 Broker…");
    await dispatchOriginal306();
    message("Owner Request and Original306 Broker Job queued. Original312/313 are still pending; NO Host Anchor was issued.");
  }));

  if (refreshButton) refreshButton.addEventListener("click",
    () => execute(async () => {
      await refreshOwnerState(false);
    }));

  if (dispatchButton) dispatchButton.addEventListener("click", () => execute(async () => {
    if (requestExpiresEpoch > 0 &&
        Math.floor(Date.now()/1000) >= requestExpiresEpoch) {
      throw new Error("Original305 request window expired. Reload and renew with owner consent.");
    }
    if (brokerJobExists) return;
    message("Retrying Original306 dispatch for the same accepted request; no new Owner Request.");
    await dispatchOriginal306();
    message("Original306 Broker Job queued. Awaiting independent signed Claim.");
  }));

  if (copyButton) copyButton.addEventListener("click", async () => {
    if (!validId(requestId)) return;
    try {
      if (typeof navigator === "undefined" || !navigator.clipboard ||
          typeof navigator.clipboard.writeText !== "function") {
        throw new Error("Clipboard permission unavailable");
      }
      await navigator.clipboard.writeText(requestId);
      if (checkedAt) checkedAt.textContent = "Request ID copied.";
    } catch (error) {
      if (checkedAt) checkedAt.textContent =
        "Copy is unavailable in this browser. Select the Request ID above to copy it.";
    }
  });

  confirmation.addEventListener("change", setState);
  if (commandConfirmation !== confirmation) {
    commandConfirmation.addEventListener("change", setState);
  }
  planField.addEventListener("input", () => {
    planEdited = true; // Never override manual typing from background AJAX.
    setState();
  });
  if (editPlan) editPlan.addEventListener("click", () => {
    planEdited = true;
    if (planEditor) planEditor.hidden = false;
    editPlan.hidden = true;
    if (planSummary) planSummary.textContent =
      "Manual override enabled. Use only an independently prepared first-party Root Plan.";
    if (typeof planField.focus === "function") planField.focus();
    setState();
  });

  setState();
  // At most one owner-only GET per interval. Do not poll in hidden tabs,
  // and do not keep a request open while an owner POST is in flight.
  if (typeof window.setTimeout === "function") {
    const tick = async () => {
      if (document.visibilityState !== "hidden" && !busy &&
          (requestId || grantId)) {
        await refreshOwnerState(true);
      }
      if (phase !== "ANCHOR_STATE_REVIEW" &&
          phase !== "INDEPENDENT_SIGNATURE_WINDOW_EXPIRED" &&
          anchorRevision === 0) {
        window.setTimeout(tick, 30000);
      }
    };
    window.setTimeout(tick, 650);
  }
  if (!requestId && grantId) {
    message(requestExpired
      ? validDigest(planField.value)
        ? "Previous request expired; Root Plan reference recovered automatically. Only fresh owner consent is required to renew."
        : "Previous Owner Request expired. Enter the independently prepared Root Plan before fresh owner consent."
      : validDigest(planField.value)
        ? "Bootstrap Grant complete. Root Plan loaded automatically; confirm the owner request when needed."
        : "Bootstrap Grant completed. Enter the verified Root Plan and confirm submission.");
  } else if (!requestId && !grantId && exactSource) {
    message(confirmation.checked
      ? "Owner controls ready. Approve Bootstrap Grant is available."
      : "Owner controls ready. Check the approval box to enable Bootstrap Grant.");
  } else if (!exactSource && !requestId) {
    message("Origin, owner nonce or Original328 binding is not ready. Do not proceed.", true);
  }
})();
