(function () {
	'use strict';

	var cfg = window.MAD4BLocalOAuthCanary || null;
	if (!cfg) return;

	var storageKey = 'mad4b_local_oauth_canary_v1';
	var startButton = document.getElementById('mad4b-local-oauth-canary-start');
	var resultNode = document.getElementById('mad4b-local-oauth-canary-result');

	function render(kind, title, details) {
		if (!resultNode) return;
		var border = kind === 'success' ? '#00a32a' : (kind === 'pending' ? '#dba617' : '#d63638');
		var text = '<strong>' + escapeHtml(title) + '</strong>';
		if (details) text += '<br>' + escapeHtml(details);
		resultNode.innerHTML = '<div style="padding:12px;border-left:4px solid ' + border + ';background:#fff">' + text + '</div>';
	}

	function escapeHtml(value) {
		return String(value).replace(/[&<>"]/g, function (char) {
			return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[char];
		});
	}

	function base64Url(bytes) {
		var binary = '';
		for (var i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
		return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
	}

	function randomValue(length) {
		var bytes = new Uint8Array(length);
		window.crypto.getRandomValues(bytes);
		return base64Url(bytes);
	}

	async function pkceChallenge(verifier) {
		var bytes = new TextEncoder().encode(verifier);
		var digest = await window.crypto.subtle.digest('SHA-256', bytes);
		return base64Url(new Uint8Array(digest));
	}

	function cleanupUrl() {
		var url = new URL(window.location.href);
		['code', 'state', 'iss', 'error', 'error_description', 'mad4b_oauth_canary'].forEach(function (key) {
			url.searchParams.delete(key);
		});
		window.history.replaceState({}, document.title, url.toString());
	}

	async function startCanary() {
		if (!cfg.canRun) {
			render('error', 'Canary is not ready.', 'Complete the explicit Staging configuration and reload this page.');
			return;
		}
		if (!window.crypto || !window.crypto.subtle || !window.sessionStorage) {
			render('error', 'Browser crypto/session support is unavailable.', 'Use a modern browser or the bundled PowerShell fallback.');
			return;
		}

		var verifier = randomValue(48);
		var state = randomValue(32);
		var challenge = await pkceChallenge(verifier);
		var record = {
			state: state,
			verifier: verifier,
			createdAt: Date.now(),
			issuer: cfg.issuer,
			resource: cfg.resource,
			clientId: cfg.clientId,
			redirectUri: cfg.redirectUri
		};
		window.sessionStorage.setItem(storageKey, JSON.stringify(record));

		var authorize = new URL(cfg.authorizationEndpoint);
		authorize.searchParams.set('response_type', 'code');
		authorize.searchParams.set('client_id', cfg.clientId);
		authorize.searchParams.set('redirect_uri', cfg.redirectUri);
		authorize.searchParams.set('scope', cfg.scope);
		authorize.searchParams.set('resource', cfg.resource);
		authorize.searchParams.set('code_challenge', challenge);
		authorize.searchParams.set('code_challenge_method', 'S256');
		authorize.searchParams.set('state', state);

		render('pending', 'Opening WordPress authorization…', 'Complete login/consent in this browser.');
		window.location.assign(authorize.toString());
	}

	async function finishCanary() {
		var url = new URL(window.location.href);
		if (url.searchParams.get('mad4b_oauth_canary') !== cfg.callbackMarker) return;

		var stored = window.sessionStorage.getItem(storageKey);
		if (!stored) {
			cleanupUrl();
			render('error', 'Canary session is missing.', 'Start a new canary from this page.');
			return;
		}

		var record;
		try {
			record = JSON.parse(stored);
		} catch (error) {
			window.sessionStorage.removeItem(storageKey);
			cleanupUrl();
			render('error', 'Canary session is invalid.', 'Start a new canary.');
			return;
		}

		var errorCode = url.searchParams.get('error');
		if (errorCode) {
			var description = url.searchParams.get('error_description') || '';
			window.sessionStorage.removeItem(storageKey);
			cleanupUrl();
			render('error', 'OAuth authorization failed: ' + errorCode, description);
			return;
		}

		var code = url.searchParams.get('code') || '';
		var state = url.searchParams.get('state') || '';
		var issuer = url.searchParams.get('iss') || '';
		if (!code || !state || state !== record.state || issuer !== record.issuer || Date.now() - Number(record.createdAt || 0) > 300000) {
			window.sessionStorage.removeItem(storageKey);
			cleanupUrl();
			render('error', 'OAuth callback validation failed.', 'Code/state/issuer/session-age validation did not pass.');
			return;
		}

		render('pending', 'Exchanging authorization code…', 'Token values will not be rendered or persisted.');
		var form = new URLSearchParams();
		form.set('grant_type', 'authorization_code');
		form.set('code', code);
		form.set('client_id', record.clientId);
		form.set('redirect_uri', record.redirectUri);
		form.set('code_verifier', record.verifier);
		form.set('resource', record.resource);

		var accessToken = '';
		try {
			var tokenResponse = await window.fetch(cfg.tokenEndpoint, {
				method: 'POST',
				credentials: 'omit',
				cache: 'no-store',
				headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
				body: form.toString()
			});
			var tokenPayload = await tokenResponse.json();
			if (!tokenResponse.ok || !tokenPayload || typeof tokenPayload.access_token !== 'string' || !tokenPayload.access_token) {
				throw new Error('Token endpoint returned HTTP ' + tokenResponse.status + '.');
			}
			accessToken = tokenPayload.access_token;
			if (Object.prototype.hasOwnProperty.call(tokenPayload, 'refresh_token')) delete tokenPayload.refresh_token;
			if (Object.prototype.hasOwnProperty.call(tokenPayload, 'access_token')) delete tokenPayload.access_token;

			var anonymousProbe = await window.fetch(cfg.resource, {
				method: 'POST',
				credentials: 'omit',
				cache: 'no-store',
				headers: {'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream'},
				body: JSON.stringify({jsonrpc: '2.0', id: 0, method: 'ping'})
			});
			if (anonymousProbe.status !== 401) throw new Error('Anonymous MCP ingress must fail closed with HTTP 401; received ' + anonymousProbe.status + '.');

			var probe = await window.fetch(cfg.resource, {
				method: 'POST',
				credentials: 'omit',
				cache: 'no-store',
				headers: {
					'Authorization': 'Bearer ' + accessToken,
					'Content-Type': 'application/json',
					'Accept': 'application/json, text/event-stream'
				},
				body: JSON.stringify({jsonrpc: '2.0', id: 1, method: 'ping'})
			});
			var denied = [401, 403, 404, 405, 503].indexOf(probe.status) !== -1 || probe.status >= 500;
			if (denied) throw new Error('Authenticated MCP ingress returned HTTP ' + probe.status + '.');
			render('success', 'MAD4B Local OAuth Browser Canary: PASS', 'Anonymous ingress was denied with HTTP 401, then Authorization Code + PKCE S256 + token exchange + bearer-only acceptance passed. MCP HTTP status: ' + probe.status + '. External-client certification is still required.');
		} catch (error) {
			render('error', 'MAD4B Local OAuth Browser Canary: FAIL', error && error.message ? error.message : String(error));
		} finally {
			accessToken = '';
			window.sessionStorage.removeItem(storageKey);
			cleanupUrl();
		}
	}

	if (startButton) startButton.addEventListener('click', function () {
		startCanary().catch(function (error) {
			render('error', 'Unable to start canary.', error && error.message ? error.message : String(error));
		});
	});

	finishCanary().catch(function (error) {
		window.sessionStorage.removeItem(storageKey);
		cleanupUrl();
		render('error', 'Unable to complete canary.', error && error.message ? error.message : String(error));
	});
}());