const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

test('registered override controls initialize once and confirm definition changes', () => {
  const listeners = {};
  const listenerCounts = {};
  const mode = { value: 'adjustment', addEventListener(type, callback) { listeners[`mode:${type}`] = callback; listenerCounts[`mode:${type}`] = (listenerCounts[`mode:${type}`] || 0) + 1; } };
  const definition = { value: '10', disabled: false, required: false };
  const reason = { disabled: false, required: false };
  const definitionWrap = { hidden: false, querySelector: () => definition };
  const reasonWrap = { hidden: false, querySelector: () => reason };
  const warning = { hidden: false };
  const form = { dataset: {}, addEventListener(type, callback) { listeners[`form:${type}`] = callback; listenerCounts[`form:${type}`] = (listenerCounts[`form:${type}`] || 0) + 1; } };
  const panel = {
    dataset: {},
    closest: () => form,
    querySelector(selector) {
      return {
        '[data-pricing-override-mode]': mode,
        '[data-pricing-override-definition]': definitionWrap,
        '[data-pricing-override-reason]': reasonWrap,
        '[data-pricing-override-warning]': warning,
      }[selector] || null;
    },
  };
  let initializer;
  let routes;
  let confirmation = '';
  let confirmations = 0;
  const documentMock = { readyState: 'complete', querySelectorAll(selector) { return selector === '[data-pricing-override]' ? [panel] : []; } };
  const context = vm.createContext({
    document: documentMock,
    window: {
      ProjectAlpha: { registerPage(pageRoutes, callback) { routes = pageRoutes; initializer = callback; } },
      confirm(message) { confirmation = message; confirmations += 1; return false; },
    },
  });
  vm.runInContext(read('public/assets/js/pricing-adjustments.js'), context);
  assert.deepEqual(Array.from(routes), ['settings', 'quote/quotes-edit', 'contract/contracts-edit', 'invoice/invoices-edit']);
  assert.equal(initializer.pageInitializerId, 'pricing-adjustments');
  initializer({ root: documentMock });
  initializer({ root: documentMock });
  assert.equal(listenerCounts['mode:change'], 1);
  assert.equal(listenerCounts['form:submit'], 1);
  assert.equal(definitionWrap.hidden, false);
  assert.equal(reason.required, true);
  assert.equal(warning.hidden, false);

  let prevented = 0;
  listeners['form:submit']({ preventDefault() { prevented += 1; } });
  assert.equal(prevented, 0, 'unchanged override does not prompt');
  assert.equal(confirmations, 0);
  definition.value = '20';
  listeners['form:submit']({ preventDefault() { prevented += 1; } });
  assert.equal(prevented, 1);
  assert.match(confirmation, /change the document total/i);

  mode.value = 'inherit';
  listeners['mode:change']();
  assert.equal(definitionWrap.hidden, true);
  assert.equal(reasonWrap.hidden, true);
  assert.equal(warning.hidden, true);
});

test('pricing management UI is explicit, responsive, and default-off safe', () => {
  const page = read('src/views/pages/settings/pricing-adjustments.php');
  const css = read('public/assets/settings.css');
  const handler = read('src/controllers/settings/pricing_adjustments_handler.php');
  const helper = read('src/utils/document_pricing_adjustments.php');
  assert.match(page, /Pricing adjustments are off/);
  assert.match(page, /Database update required/);
  assert.match(page, /min="0\.0001"/);
  assert.match(page, />Deactivate</);
  assert.doesNotMatch(page, /active assignment/);
  assert.doesNotMatch(page, /Â/);
  assert.match(css, /\.pricing-definition-actions-wrap/);
  assert.match(css, /\.pricing-assignment-panel/);
  assert.match(css, /\.pricing-override-panel/);
  assert.match(css, /\.pricing-provenance/);
  assert.match(css, /@media\(max-width:760px\)/);
  assert.match(css, /pricing-override-grid\{grid-template-columns:1fr\}/);
  assert.match(handler, /csrf_validate\(\)/);
  assert.match(handler, /financial\.manage/);
  assert.match(handler, /catch\(DomainException/);
  assert.match(handler, /Unable to update pricing adjustments\./);
  const unexpectedBlock = handler.slice(handler.indexOf('}catch(Throwable'));
  assert.doesNotMatch(unexpectedBlock, /rawurlencode\(\$error->getMessage\(\)\)/);
  assert.match(unexpectedBlock, /rawurlencode\('Unable to update pricing adjustments\.'\)/);
  assert.match(helper, /derived_from_snapshot_id FROM document_pricing_adjustment_snapshots/);
  assert.match(helper, /affects_total FROM invoice_adjustments/);
  assert.match(helper, /user_can\(\$pdo,\$actor,'financial\.manage',0\)/);
});

test('assignment, override, client-safe rows, and long-term delivery use shared paths', () => {
  for (const [file, marker] of [
    ['src/views/pages/project/projects-edit.php', 'pricing_adjustment_assignment_controls'],
    ['src/views/pages/contract/contracts-edit.php', 'pricing_adjustment_assignment_controls'],
    ['src/views/pages/contract/contracts-edit.php', 'pricing_adjustment_override_controls'],
    ['src/views/pages/quote/quotes-edit.php', 'pricing_adjustment_override_controls'],
    ['src/views/pages/invoice/invoices-edit.php', 'pricing_adjustment_override_controls'],
  ]) assert.match(read(file), new RegExp(marker), file);

  for (const file of [
    'src/views/pages/quote/quote-details.php',
    'src/views/pages/quote/long-term-quote-details.php',
    'src/views/pages/contract/contract-details.php',
    'src/views/pages/contract/long-term-contract-details.php',
    'src/views/pages/invoice/invoice-details.php',
  ]) {
    const source = read(file);
    assert.match(source, /pricing_adjustment_client_row/, file);
    assert.match(source, /!defined\('PDF_MODE'\).*?!defined\('PUBLIC_VIEW'\).*?pricing_adjustment_staff_provenance/s, file);
  }

  const publicView = read('src/views/public/doc-wrapper.php');
  assert.match(publicView, /long-term-quote-details\.php/);
  assert.match(publicView, /long-term-contract-details\.php/);
  const attachment = read('src/utils/document_pdf.php');
  assert.match(attachment, /long-term-quote-details\.php/);
  assert.match(attachment, /long-term-contract-details\.php/);
  assert.match(read('src/controllers/quote/quote_pdf.php'), /long-term-quote-details\.php/);
  assert.match(read('src/controllers/contract/contract_pdf.php'), /long-term-contract-details\.php/);
});
