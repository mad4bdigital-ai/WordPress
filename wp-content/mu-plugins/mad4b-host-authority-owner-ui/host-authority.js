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
  const planField = $("mad4b-ha-plan");
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
  const exactSource = config.operation === "company.host-authority.anchor.issue" &&
    config.hostId === "hosting-2" && /^u[0-9]{9}$/.test(config.hostPrincipal || "") &&
    validId(config.transportId) && validSite(config.siteUuid) && !!config.nonce &&
    typeof config.restBase === "string" && config.restBase.startsWith("/wp-json/") &&
    !config.restBase.includes("//") && !config.restBase.includes(":8080");

  let grantId = validId(config.grantId) ? config.grantId : "";
  let requestId = validId(config.requestId) ? config.requestId : "";
  let requestStatus = typeof config.requestStatus === "string"
    ? config.requestStatus : "ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY";
  let busy = false;
  let lastError = false;

  function humanStatus(status) {
    if (status === "ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY") {
      return "Accepted — awaiting independent Host verification";
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
    if (trackerStatus) trackerStatus.textContent = "Current status: " + humanStatus(requestStatus);
    if (headline) headline.textContent = requestId ? "Owner request accepted" :
      grantId ? "Bootstrap Grant completed" : "Owner approval required";
  }

  function step(id, state) {
    const node = $("mad4b-ha-step-" + id);
    if (node && node.classList) {
      node.classList.toggle("is-done", state === "done");
      node.classList.toggle("is-current", state === "current");
    }
  }

  const setState = () => {
    const pristine = config.phase !== "ANCHOR_STATE_REVIEW";
    const hasGrant = validId(grantId);
    const hasRequest = validId(requestId);
    grantButton.disabled = busy || !exactSource || !pristine || hasGrant ||
      hasRequest || !confirmation.checked;
    commandButton.disabled = busy || !exactSource || !pristine || !hasGrant ||
      hasRequest || !commandConfirmation.checked ||
      !validDigest(planField.value.trim().toLowerCase());
    if (refreshButton) refreshButton.disabled = busy || !hasRequest;
    if (copyButton) copyButton.disabled = !hasRequest;
    if (grantPanel) grantPanel.hidden = hasGrant || hasRequest;
    if (commandPanel) commandPanel.hidden = !hasGrant || hasRequest;
    if (trackerPanel) trackerPanel.hidden = !hasRequest;
    if (hasRequest && "hidden" in result) result.hidden = !lastError;
    step("grant", hasGrant ? "done" : "current");
    step("request", hasRequest ? "done" : hasGrant ? "current" : "");
    step("verify", hasRequest ? "current" : "");
    trackerUpdate();
  };

  function endpoint(action) {
    if (!/^(grants|commands|commands\/[a-f0-9]{32})$/.test(action)) {
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
    if (checkedAt) checkedAt.textContent = "Request submitted. Select Check latest status for a new readback.";
    message("Original305 Owner Job admitted. This is NOT an issued Host Anchor. Request ID: " + requestId);
    // Show the tracking panel immediately; never repeat an Owner action.
    setState();
    if (trackerTitle && typeof trackerTitle.focus === "function") trackerTitle.focus();
  }));

  if (refreshButton) refreshButton.addEventListener("click", () => execute(async () => {
    if (!validId(requestId)) throw new Error("No Original305 request is present.");
    const receipt = await original305("commands/" + requestId);
    if (receipt.request_id !== requestId) {
      throw new Error("Original305 request ID does not match the selected request.");
    }
    requestStatus = typeof receipt.status === "string" ? receipt.status : "UNKNOWN";
    lastError = false;
    if ("hidden" in result) result.hidden = true;
    trackerUpdate();
    if (checkedAt) checkedAt.textContent = "Last checked: " +
      new Date().toLocaleTimeString() + " · Original305 status only, not an Anchor receipt.";
    // Keep the active tracker visible instead of replacing it with a raw server string.
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
  planField.addEventListener("input", setState);

  setState();
  if (!requestId && grantId) {
    message("Bootstrap Grant completed. Enter the verified Root Plan and confirm submission.");
  } else if (!requestId && !grantId && exactSource) {
    message(confirmation.checked
      ? "Owner controls ready. Approve Bootstrap Grant is available."
      : "Owner controls ready. Check the approval box to enable Bootstrap Grant.");
  } else if (!exactSource && !requestId) {
    message("Origin, owner nonce or Original328 binding is not ready. Do not proceed.", true);
  }
})();
