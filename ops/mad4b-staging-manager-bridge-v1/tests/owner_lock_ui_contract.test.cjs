'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const ui = fs.readFileSync(path.join(root, '320-mad4b-owner-approval-console.php'), 'utf8');
const original = fs.readFileSync(path.join(root, '..', 'mad4b-environment-manager-assignment-v1', '317-mad4b-environment-manager-assignment.php'), 'utf8');

const html = ui.match(/pattern="([^"]+)"/);
assert.ok(html, 'HTML company slug pattern must exist');
const browserRegex = new RegExp(html[1], 'v');
assert.equal(browserRegex.test('all-royal-egypt'), true);
assert.equal(browserRegex.test('egypt-tour-gates'), true);
assert.equal(browserRegex.test('Bad Company'), false);
console.log('HTML_PATTERN_UNICODE_V_FLAG_PASS');

assert.match(original, /owner_action_session_verified'\]=self::owner\(\$request\)/);
assert.match(original, /SELECT GET_LOCK\(%s,3\)/);
assert.match(original, /SELECT IS_USED_LOCK\(%s\)/);
assert.match(original, /assignment_lock_backend_unavailable/);
assert.match(original, /assignment_locked',423/);
assert.match(original, /SELECT RELEASE_LOCK\(%s\)/);
assert.match(original, /'remote_write_granted'=>false/);
console.log('ORIGINAL_LOCK_FAIL_CLOSED_CLASSIFICATION_PASS');

assert.match(ui, /owner_action_session_verified === true/);
assert.match(ui, /const actionResult = root.querySelector/);
assert.match(ui, /await read\(false\)/);
assert.match(ui, /HTTP ' \+ e.data.status/);
assert.match(ui, /assignment_locked/);
assert.match(ui, /assignment_lock_backend_unavailable/);
assert.match(ui, /style.color = failure/);
console.log('OWNER_ERROR_VISIBILITY_AND_RECHECK_PASS');
