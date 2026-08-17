const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const rootPath = path.resolve(__dirname, '..', '..');
const page = fs.readFileSync(path.join(rootPath, 'src/views/pages/settings/external-ops.php'), 'utf8');
const scriptStart = page.lastIndexOf('<script>');
const scriptEnd = page.indexOf('</script>', scriptStart);
assert.notEqual(scriptStart, -1);
assert.notEqual(scriptEnd, -1);
const directoryScript = page.slice(scriptStart + '<script>'.length, scriptEnd);

function loadInitializer(root, extras = {}) {
  let initializer;
  const window = {
    ProjectAlpha: {
      registerPage(pageName, callback) {
        assert.equal(pageName, 'settings');
        initializer = callback;
      },
    },
    confirm: () => true,
    ...extras.window,
  };
  vm.runInContext(directoryScript, vm.createContext({
    Array,
    DOMParser: extras.DOMParser || class {},
    Error,
    fetch: extras.fetch || (() => Promise.reject(new Error('unexpected fetch'))),
    window,
    document: root,
  }));
  assert.equal(typeof initializer, 'function');
  return initializer;
}

test('grant access is an idempotent exact-match typeahead', () => {
  const listeners = new Map();
  const search = {
    value: '',
    validityMessage: '',
    getAttribute(name) { return name === 'list' ? 'external-ops-eligible-users' : null; },
    addEventListener(name, callback) { listeners.set(`search:${name}`, callback); },
    setCustomValidity(message) { this.validityMessage = message; },
    reportValidity() {},
  };
  const userId = { value: '' };
  const form = {
    dataset: {},
    querySelector(selector) {
      if (selector === '[data-external-ops-user-search]') return search;
      if (selector === '[data-external-ops-user-id]') return userId;
      return null;
    },
    addEventListener(name, callback) { listeners.set(`form:${name}`, callback); },
  };
  const options = [
    { value: 'Colin Smith - colin@example.test', dataset: { userId: '17' } },
    { value: 'Avery Jones - avery@example.test', dataset: { userId: '22' } },
  ];
  const list = { querySelectorAll: () => options };
  const root = {
    querySelectorAll(selector) {
      if (selector === '[data-external-ops-grant-form]') return [form];
      if (selector === '[data-external-ops-detail-link]') return [];
      return [];
    },
    querySelector(selector) {
      if (selector === '#external-ops-eligible-users') return list;
      return null;
    },
  };
  const initialize = loadInitializer(root);
  initialize({ root });
  initialize({ root });

  assert.equal(listeners.size, 3, 'repeated settings initialization must not duplicate handlers');
  search.value = 'colin smith - COLIN@example.test';
  listeners.get('search:input')();
  assert.equal(userId.value, '17');
  assert.equal(search.validityMessage, '');

  search.value = 'Colin';
  listeners.get('search:input')();
  assert.equal(userId.value, '');
  assert.match(search.validityMessage, /suggestions/);
});

test('details load into the current settings page without changing navigation state', async () => {
  let clickHandler;
  let fetchCount = 0;
  let prevented = 0;
  let stopped = 0;
  const detail = { focus() {}, scrollIntoView() {} };
  const detailRegion = {
    innerHTML: '',
    setAttribute() {},
    removeAttribute() {},
    querySelector(selector) { return selector === '#integration-access-detail' ? detail : null; },
  };
  const loadedRegion = {
    innerHTML: '<div id="integration-access-detail">Colin</div>',
    querySelector(selector) { return selector === '#integration-access-detail' ? {} : null; },
  };
  const link = {
    dataset: {},
    href: 'https://pa.example.test/?page=settings&tab=external-ops&access_user_id=17#integration-access-detail',
    addEventListener(name, callback) { if (name === 'click') clickHandler = callback; },
  };
  const root = {
    querySelectorAll(selector) {
      if (selector === '[data-external-ops-grant-form]') return [];
      if (selector === '[data-external-ops-detail-link]') return [link];
      return [];
    },
    querySelector(selector) {
      if (selector === '[data-external-ops-detail-region]') return detailRegion;
      return null;
    },
  };
  class DOMParserMock {
    parseFromString() { return { querySelector: () => loadedRegion }; }
  }
  const initialize = loadInitializer(root, {
    DOMParser: DOMParserMock,
    fetch: async () => {
      fetchCount += 1;
      return { ok: true, text: async () => '<html></html>' };
    },
  });
  initialize({ root });
  assert.equal(typeof clickHandler, 'function');
  await clickHandler({
    metaKey: false,
    ctrlKey: false,
    shiftKey: false,
    altKey: false,
    preventDefault() { prevented += 1; },
    stopPropagation() { stopped += 1; },
  });

  assert.equal(fetchCount, 1);
  assert.equal(prevented, 1);
  assert.equal(stopped, 1);
  assert.match(detailRegion.innerHTML, /Colin/);
  assert.doesNotMatch(directoryScript, /pushState|replaceState|location\s*=/);
});
