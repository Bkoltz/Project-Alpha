const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/public-client-onboarding.js'), 'utf8');

function control(properties = {}) {
  const listeners = {};
  return {
    addEventListener(type, callback) { (listeners[type] ||= []).push(callback); },
    dispatch(type) { for (const callback of listeners[type] || []) callback(); },
    listenerCount(type) { return (listeners[type] || []).length; },
    ...properties,
  };
}

test('individual and organization modes toggle company fields accessibly and idempotently', () => {
  const individual = control({ value: 'consumer' });
  const organization = control({ value: 'business' });
  let selected = individual;
  const organizationName = { required: false };
  const organizationFields = {
    hidden: false,
    querySelector(selector) { return selector === '[name="organization_name"]' ? organizationName : null; },
  };
  const contactNameLabel = { textContent: '' };
  const form = {
    dataset: {},
    querySelectorAll() { return [individual, organization]; },
    querySelector(selector) {
      return {
        '[data-onboarding-type]:checked': selected,
        '[data-organization-fields]': organizationFields,
        '[data-contact-name-label]': contactNameLabel,
      }[selector] || null;
    },
  };
  const context = vm.createContext({
    document: { readyState: 'complete', querySelector: () => form },
  });

  vm.runInContext(script, context);
  vm.runInContext(script, context);
  assert.equal(individual.listenerCount('change'), 1);
  assert.equal(organizationFields.hidden, true);
  assert.equal(organizationName.required, false);
  assert.equal(contactNameLabel.textContent, 'Full name');

  selected = organization;
  organization.dispatch('change');
  assert.equal(organizationFields.hidden, false);
  assert.equal(organizationName.required, true);
  assert.equal(contactNameLabel.textContent, 'Contact name');
});
