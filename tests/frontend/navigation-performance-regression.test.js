const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');
const navigation = fs.readFileSync(path.join(root, 'public/assets/navigation.js'), 'utf8');

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

test('one soft navigation performs exactly one HTML request', async () => {
  let requestCount = 0;
  const main = {
    innerHTML: '<h1>Clients</h1>',
    querySelectorAll() { return []; },
  };
  const parsedDocument = {
    querySelector(selector) {
      if (selector === '.main-content') return main;
      if (selector === 'meta[name="project-alpha-version"]') return { getAttribute: () => 'test-version' };
      return null;
    },
    querySelectorAll() { return []; },
  };
  const currentDocument = {
    readyState: 'loading',
    addEventListener() {},
    querySelector(selector) {
      if (selector === 'meta[name="project-alpha-version"]') return { getAttribute: () => 'test-version' };
      return null;
    },
    querySelectorAll() { return []; },
  };
  class DOMParserMock { parseFromString() { return parsedDocument; } }
  const context = vm.createContext({
    URL,
    URLSearchParams,
    Date,
    Map,
    Array,
    Promise,
    CustomEvent: class {},
    Event: class {},
    DOMParser: DOMParserMock,
    document: currentDocument,
    window: {
      location: {
        origin: 'https://pa.example.test',
        href: 'https://pa.example.test/?page=home',
        hostname: 'pa.example.test',
        pathname: '/',
        search: '?page=home',
      },
    },
    fetch: async () => {
      requestCount += 1;
      return { ok: true, url: 'https://pa.example.test/?page=client/clients-list', text: async () => '<html></html>' };
    },
    console,
  });
  vm.runInContext(navigation, context);

  const result = await context.loadPageContent('client/clients-list');
  assert.equal(requestCount, 1);
  assert.equal(result.html, '<h1>Clients</h1>');
  assert.equal(result.reloadRequired, undefined);
});

test('full-navigation links are not converted into duplicate soft requests', () => {
  let prevented = 0; let navigated = 0;
  const context = vm.createContext({
    URL,
    window: { location: { hostname: 'pa.example.test' } },
    navigateToPage() { navigated += 1; },
  });
  vm.runInContext(extractFunction(navigation, 'handleNavigation'), context);

  const link = {
    hostname: 'pa.example.test',
    href: 'https://pa.example.test/?page=invoice/invoice-print&id=7',
    hasAttribute(name) { return name === 'data-skip-nav'; },
  };
  context.handleNavigation({
    target: { closest: () => link },
    preventDefault() { prevented += 1; },
    metaKey: false,
    ctrlKey: false,
  });

  assert.equal(prevented, 0);
  assert.equal(navigated, 0);
});
