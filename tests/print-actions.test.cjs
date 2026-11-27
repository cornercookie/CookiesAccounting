// Local development check only; Node.js is not required on the deployed site.
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { runInNewContext } = require('node:vm');
const assert = require('node:assert/strict');
const { test } = require('node:test');

const script = readFileSync(join(__dirname, '..', 'assets', 'app.js'), 'utf8');

function control(value) {
  const handlers = new Map();
  return {
    value,
    addEventListener: (name, handler) => handlers.set(name, handler),
    fire: (name) => handlers.get(name)?.(),
  };
}

function load({ printButton, select, csv, json }) {
  let printCount = 0;
  const window = { location: { origin: 'https://test.invalid' }, print: () => { printCount++; } };
  const document = {
    addEventListener() {},
    getElementById: (id) => id === 'exportYmSelect' ? select : null,
    querySelectorAll: (selector) => selector === '[data-print-page]' && printButton ? [printButton] : [],
    querySelector: (selector) => selector.includes('format=csv') ? csv : selector.includes('format=json') ? json : null,
  };
  runInNewContext(script, { URL, window, document });
  return () => printCount;
}

test('the print control calls the browser print function', () => {
  const printButton = control();
  const prints = load({ printButton });
  printButton.fire('click');
  assert.equal(prints(), 1);
});

test('changing the export month updates the CSV and JSON destinations', () => {
  const select = control('2025-02');
  const csv = { href: 'https://test.invalid/subdir/export_csv.php?ledger_id=1&format=csv&ym=2025-02' };
  const json = { href: 'https://test.invalid/subdir/export_csv.php?ledger_id=1&format=json&ym=2025-02' };
  load({ select, csv, json });
  select.value = '2025-03';
  select.fire('change');
  for (const link of [csv, json]) {
    const url = new URL(link.href, 'https://test.invalid/subdir/ledger.php');
    assert.equal(url.searchParams.get('ym'), '2025-03');
    assert.equal(url.searchParams.get('ledger_id'), '1');
    assert.equal(url.pathname, '/subdir/export_csv.php');
  }
});
