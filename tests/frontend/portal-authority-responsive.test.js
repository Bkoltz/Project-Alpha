const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');

test('portal authority controls remain native-keyboard operable at 320/390 widths', () => {
  const page = fs.readFileSync(path.join(root, 'src/views/pages/settings/external-ops.php'), 'utf8');
  assert.match(page, /@media\(max-width:390px\)/);
  assert.match(page, /grid-template-columns:minmax\(0,1fr\)/);
  assert.match(page, /width:calc\(100vw - 40px\)/);
  assert.doesNotMatch(page, /onclick=/i);
  assert.match(page, /<details><summary class="btn btn-sm">Edit profile<\/summary>/);
  assert.match(page, /<button class="btn btn-primary">Save manager authority<\/button>/);
  assert.match(page, /name="action" value="set-portal-workspace-link"/);
  assert.match(page, /Profile workspace allowlist/);
  assert.match(page, /No workspaces authorized/);
  assert.match(page, /role="alert"/);
});

test('portal catalog UI exposes only explicit client-safe publication fields', () => {
  const page = fs.readFileSync(path.join(root, 'src/views/pages/settings/item-library.php'), 'utf8');
  const script = fs.readFileSync(path.join(root, 'public/assets/js/item-library.js'), 'utf8');
  for (const field of ['portal_requestable','portal_category','portal_display_order','portal_geometry_requirement','portal_summary','portal_questions_json']) {
    assert.match(page, new RegExp(`name="${field}"`));
  }
  assert.match(page, /Internal pricing policy, fulfillment notes, compensation, raw files, and processing details are never included/);
  assert.match(script, /portal_category/);
});
