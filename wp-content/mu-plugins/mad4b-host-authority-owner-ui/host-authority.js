/* MAD4B Host Authority v106: browser owner approval, existing Original305 REST only. */
(() => {
  "use strict";

  const config = window.MAD4BHostAuthorityV106;
  const app = document.getElementById("mad4b-host-authority-app");
  if (!app || !config || config.owner !== true) return;

  const $ = (id) => document.getElementById(id);
  const grantButton = $("mad4b-ha-grant");
  const commandButton = $("mad4b-ha-command");
  const refreshButton = $("mad4b-ha-refresh");
  const planField = $("mad4b-ha-plan");
  const confirmation = $("mad4b-ha-owner-confirm");
  const result = $("mad4b-ha-result");
  if (!grantButton || !commandButton || !planField || !confirmation || !result) return;

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
  let busy = false;

  const message = (text, isError = false) => {
    result.textContent = text; // Never interpolate REST/server data as markup.
    result.style.borderLeftColor = isError ? "#b32d2e" : "#2271b1";
  };

  const setState = () => {
    const pristine = config.phase !== "ANCHOR_STATE_REVIEW";
    grantButton.disabled = busy || !exactSource || !pristine || !!grantId ||
      !!requestId || !confirmation.checked;
    commandButton.disabled = busy || !exactSource || !pristine || !grantId ||
      !!requestId || !confirmation.checked ||
      !validDigest(planField.value.trim().toLowerCase());
    refreshButton.disabled = busy || !requestId;
  };

  const endpoint = (action) => {
    if (!/^(grants|commands|commands\/[a-f0-9]{32})$/.test(action)) {
      throw new Error("Unexpected Original305 route.");
    }
    // Resolve against the browser's public HTTPS origin, not the internal :8080.
    const url = new URL(config.restBase + action, window.location.origin);
    if (url.origin !== window.location.origin || url.protocol !== "https:") {
      throw new Error("Secure same-origin WordPress connection required.");
    }
    return url.href;
  };

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
    return Array.from(data, (value) => value.toString(16).padStart(2, "0")).join("");
  }

  const execute = async (runner) => {
    if (busy) return;
    busy = true;
    setState();
    try {
      await runner();
    } catch (error) {
      message(error instanceof Error ? error.message : "Request failed. No authorization was confirmed.", true);
    } finally {
      busy = false;
      setState();
    }
  };

  grantButton.addEventListener("click", () => execute(async () => {
    if (!confirmation.checked || !exactSource || grantId || requestId) {
      throw new Error("Owner confirmation and an unused Original328 bootstrap scope are required.");
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
      throw new Error("Original305 returned a grant without the expected non-executing bootstrap evidence. Refresh the page.");
    }
    grantId = receipt.grant_id;
    message("Bootstrap Grant staged by Original305. No Anchor was issued. The independently prepared signed plan is required to queue the Owner Job.");
  }));

  commandButton.addEventListener("click", () => execute(async () => {
    const digest = planField.value.trim().toLowerCase();
    if (!confirmation.checked || !exactSource || !validId(grantId) ||
        !validDigest(digest) || requestId) {
      throw new Error("A real owner Grant and an exact first-party plan SHA-256 are required.");
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
      throw new Error("Original305 returned an unexpected command receipt. Refresh and inspect the real request.");
    }
    requestId = accepted.request_id;
    message("Original305 Owner Job admitted and awaiting independent Original306/312/313 execution. Request ID: " +
      requestId + ". This is NOT an issued Host Anchor.");
  }));

  if (refreshButton) refreshButton.addEventListener("click", () => execute(async () => {
    if (!validId(requestId)) throw new Error("No Original305 request is present.");
    const receipt = await original305("commands/" + requestId);
    if (receipt.request_id !== requestId) {
      throw new Error("The returned Original305 request does not match the selected request.");
    }
    const status = typeof receipt.status === "string"
      ? receipt.status.replace(/[^a-zA-Z0-9_-]/g, "").slice(0, 100)
      : "UNKNOWN";
    message("Original305 request " + requestId + ": " + status +
      ". This status does not certify Anchor issuance. Original318 and Original313 must verify the final result.");
  }));

  confirmation.addEventListener("change", setState);
  planField.addEventListener("input", setState);
  if (requestId) message("Existing Original305 request: " + requestId +
    ". Use Read Request Status. An Original305 admission is not an Anchor receipt.");
  else if (grantId) message("An existing owner Bootstrap Grant is available. It has no Host execution authority.");
  else if (!exactSource) message("Origin, owner nonce or Original328 binding is not ready. Do not proceed.", true);
  setState();
})();
