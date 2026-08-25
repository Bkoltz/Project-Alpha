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

test('soft navigation retains JSON data scripts but executes JavaScript', () => {
  const context = vm.createContext({});
  vm.runInContext(extractFunction(navigation, 'isExecutableScript'), context);
  const script = type => ({ getAttribute: name => name === 'type' ? type : null });
  assert.equal(context.isExecutableScript(script('application/json')), false);
  assert.equal(context.isExecutableScript(script('importmap')), false);
  assert.equal(context.isExecutableScript(script('')), true);
  assert.equal(context.isExecutableScript(script('module')), true);
  assert.match(navigation, /\.filter\(isExecutableScript\)/);
});

test('inline settings scripts are not converted to CSP-blocked blob URLs', async () => {
  let appended;
  const document = {
    createElement() { return { addEventListener() {}, set textContent(value) { this.code = value; } }; },
    body: { appendChild(script) { appended = script; } },
  };
  const context = vm.createContext({ cachedScripts: [], console, document, Promise });
  vm.runInContext(extractFunction(navigation, 'appendPageScript'), context);
  await context.appendPageScript({ src: null, code: 'window.__settingsReady = true;', type: '' });
  assert.equal(appended.code, 'window.__settingsReady = true;');
  assert.doesNotMatch(extractFunction(navigation, 'appendPageScript'), /new Blob|createObjectURL/);
});

test('settings refresh layout and repeated-script guards remain present', () => {
  const css = fs.readFileSync(path.join(root, 'public/assets/settings.css'), 'utf8');
  const settingsScript = fs.readFileSync(path.join(root, 'public/assets/js/settings-page.js'), 'utf8');
  const settingsSidebar = fs.readFileSync(path.join(root, 'src/views/pages/settings/sidebar.php'), 'utf8');
  const itemLibrary = fs.readFileSync(path.join(root, 'src/views/pages/settings/item-library.php'), 'utf8');
  const documents = fs.readFileSync(path.join(root, 'src/views/pages/settings/documents.php'), 'utf8');
  const links = fs.readFileSync(path.join(root, 'src/views/pages/settings/links.php'), 'utf8');
  const customization = fs.readFileSync(path.join(root, 'public/assets/js/customization-logic.js'), 'utf8');
  const docCustomization = fs.readFileSync(path.join(root, 'public/assets/js/document-customization-logic.js'), 'utf8');
  assert.match(itemLibrary, /type="application\/json" id="bundleChoicesData"/);
  assert.match(css, /\.settings-page \[hidden\]\s*\{\s*display:\s*none\s*!important;/s);
  assert.match(css, /\.settings-content fieldset\s*\{[\s\S]*?max-width:\s*100%;[\s\S]*?min-width:\s*0;/);
  assert.match(css, /\.settings-tab-list\s*\{[\s\S]*?overflow-x:\s*auto;/);
  assert.match(settingsSidebar, /data-settings-nav-disclosure open/);
  assert.match(settingsSidebar, /class="settings-nav-toggle"/);
  assert.match(css, /@media \(max-width: 800px\)[\s\S]*?\.settings-nav-toggle\s*\{[\s\S]*?display:\s*grid;/);
  assert.match(settingsScript, /window\.matchMedia\('\(max-width: 800px\)'\)/);
  assert.match(settingsScript, /navigationDisclosure\.open = !compactNavigation\.matches/);
  assert.match(documents, /class="settings-tab-list"/);
  assert.match(links, /repeat\(4,minmax\(0,1fr\)\)/);
  assert.match(customization, /var draggedElement = null;/);
  assert.match(docCustomization, /var fieldsList = document\.getElementById\('fieldsList'\);/);
  assert.match(docCustomization, /var draggedElement = null;/);
});
