/**
 * MAD4B IMP01: user-owned Google Sheets -> signed WordPress REVIEW inbox.
 * No publishing, WP All Import execution, privileged client OAuth forwarding,
 * or provider-side atomic CAS is implied.
 *
 * Script Properties required:
 * MAD4B_SPREADSHEET_ID, MAD4B_SHEET_NAME, MAD4B_PROFILE_SLUG,
 * MAD4B_SITE_UUID, MAD4B_WEBHOOK_URL, MAD4B_WEBHOOK_SECRET,
 * MAD4B_IDENTITY_FIELD, MAD4B_CURRENCY_ALLOWLIST_JSON
 *
 * Convert XLSX to native Google Sheets first; SpreadsheetApp.openById()
 * does not itself make an XLSX file into a native spreadsheet.
 */
function mad4bImportSettings_() {
  const props = PropertiesService.getScriptProperties();
  const get = key => {
    const v = props.getProperty(key);
    if (!v) throw new Error('Missing Script Property: ' + key);
    return v;
  };
  const url = get('MAD4B_WEBHOOK_URL');
  if (!/^https:\/\/[^\s]+\/wp-json\/mad4b\/v1\/activity-import\/intake$/.test(url)) {
    throw new Error('Use an exact enrolled HTTPS WordPress intake URL');
  }
  const secret = get('MAD4B_WEBHOOK_SECRET');
  if (secret.length < 32) throw new Error('Site-scoped webhook secret must be >=32 bytes');
  const currencies = JSON.parse(get('MAD4B_CURRENCY_ALLOWLIST_JSON'));
  if (!Array.isArray(currencies) || currencies.length > 20) throw new Error('Currency allowlist invalid');
  return {
    spreadsheet: get('MAD4B_SPREADSHEET_ID'),
    tab: get('MAD4B_SHEET_NAME'),
    profile: get('MAD4B_PROFILE_SLUG'),
    site: get('MAD4B_SITE_UUID'),
    url: url,
    secret: secret,
    idKey: get('MAD4B_IDENTITY_FIELD'),
    currencies: currencies
  };
}
function mad4bHex_(signedBytes) {
  return signedBytes.map(byte => ('0' + ((byte + 256) % 256).toString(16)).slice(-2)).join('');
}
function mad4bCell_(cell) {
  if (cell instanceof Date) return Math.floor(cell.getTime() / 1000);
  if (cell === null || cell === undefined) return '';
  if (typeof cell !== 'string' && typeof cell !== 'number' && typeof cell !== 'boolean')
    throw new Error('Nested/unsupported source cell');
  return cell;
}
function mad4bPushRatesForReview() {
  const cfg = mad4bImportSettings_();
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(30000)) throw new Error('Another Apps Script push is in progress');
  try {
    const sheet = SpreadsheetApp.openById(cfg.spreadsheet).getSheetByName(cfg.tab);
    if (!sheet) throw new Error('Configured sheet tab not found');
    const range = sheet.getDataRange();
    const formulas = range.getFormulas();
    if (formulas.some(row => row.some(Boolean)))
      throw new Error('Formula cells require separate approved transformation policy');
    const data = range.getValues();
    if (data.length < 2 || data.length > 501 || data[0].length > 80)
      throw new Error('Data outside bounded 1..500 record / 80 column envelope');
    const headers = data[0].map(String);
    if (headers.some(h => !/^[A-Za-z_][A-Za-z0-9_]{0,120}$/.test(h)) ||
        new Set(headers).size !== headers.length ||
        !headers.includes(cfg.idKey))
      throw new Error('Headers must be unique safe identifiers and contain unique-ID field');
    const rows = data.slice(1).filter(row => row.some(v => v !== '' && v !== null))
      .map(row => Object.fromEntries(headers.map((h, i) => [h, mad4bCell_(row[i])])));
    const fieldMapping = {}; // Bind approved meta keys in the governed site profile separately.
    const input = {
      profile_slug: cfg.profile, identity_field: cfg.idKey,
      headers: headers, rows: rows, field_mapping: fieldMapping,
      allowed_currencies: cfg.currencies
    };
    const payload = JSON.stringify({
      site_uuid: cfg.site, issued_at: Math.floor(Date.now() / 1000),
      nonce: Utilities.getUuid().replace(/[^A-Za-z0-9_-]/g, ''),
      input: input
    });
    if (payload.length > 900000) throw new Error('Signed source payload exceeds intake budget');
    const signature = mad4bHex_(Utilities.computeHmacSha256Signature(payload, cfg.secret));
    const response = UrlFetchApp.fetch(cfg.url, {
      method: 'post', contentType: 'application/json',
      payload: payload, muteHttpExceptions: true,
      headers: {'x-mad4b-signature': signature}
    });
    const code = response.getResponseCode();
    if (code < 200 || code >= 300)
      throw new Error('WordPress refused staged review, HTTP ' + code);
    // Never log raw spreadsheet values, HMAC, access tokens or Script Properties.
    const receipt = JSON.parse(response.getContentText());
    console.log(JSON.stringify({
      staged: Boolean(receipt.staged),
      plan_sha256: receipt.plan_sha256 || null,
      issues: receipt.issue_count || 0, post_writes: 0
    }));
    return receipt;
  } finally {
    lock.releaseLock();
  }
}
function mad4bInstallDailyReviewTrigger() {
  const name = 'mad4bPushRatesForReview';
  if (ScriptApp.getProjectTriggers().some(t => t.getHandlerFunction() === name))
    return 'existing_trigger';
  ScriptApp.newTrigger(name).timeBased().everyDays(1).create();
  return 'trigger_installed';
}
