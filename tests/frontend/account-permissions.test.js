const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const rootPath = path.resolve(__dirname, '..', '..');
const accountEdit = fs.readFileSync(path.join(rootPath, 'src/views/pages/auth/account-edit.php'), 'utf8');

function extractFunction(source, name) {
  const start = source.indexOf(`function ${name}(`);
  assert.notEqual(start, -1, `Expected ${name} in production source`);
  const open = source.indexOf('{', start);
  let depth = 0; let quote = null; let escaped = false;
  for (let i = open; i < source.length; i += 1) {
    const char = source[i];
    if (quote) { if (escaped) escaped = false; else if (char === '\\') escaped = true; else if (char === quote) quote = null; continue; }
    if (char === '"' || char === "'" || char === '`') { quote = char; continue; }
    if (char === '{') depth += 1;
    if (char === '}' && --depth === 0) return source.slice(start, i + 1);
  }
  throw new Error(`Could not extract ${name}`);
}

test('account permission pairs work after soft navigation without duplicate listeners', () => {
  function checkbox(name, checked) {
    const listeners = [];
    return {
      name,
      checked,
      dataset: {},
      addEventListener(event, callback) { if (event === 'change') listeners.push(callback); },
      listeners,
    };
  }

  const allow = checkbox('allow_projects_view', true);
  const deny = checkbox('deny_projects_view', false);
  const form = { dataset: {} };
  const root = {
    querySelector(selector) {
      if (selector === '#account-edit-form') return form;
      if (selector.includes('input[name="allow_projects_view"]')) return allow;
      if (selector.includes('input[name="deny_projects_view"]')) return deny;
      return null;
    },
    querySelectorAll(selector) {
      if (selector.includes('input[name^="allow_"]')) return [allow, deny];
      return [];
    },
  };
  const context = vm.createContext({ document: root, window: {} });
  vm.runInContext(extractFunction(accountEdit, 'initAccountEditForm'), context);

  context.initAccountEditForm({ root });
  context.initAccountEditForm({ root });
  assert.equal(allow.listeners.length, 1);
  assert.equal(deny.listeners.length, 1);

  allow.checked = false;
  allow.listeners[0].call(allow);
  assert.equal(deny.checked, true);

  deny.checked = false;
  deny.listeners[0].call(deny);
  assert.equal(allow.checked, true);
});
