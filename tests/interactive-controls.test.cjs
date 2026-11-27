const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync, readdirSync } = require('node:fs');
const { join } = require('node:path');
const { runInNewContext } = require('node:vm');
const root = join(__dirname, '..');
const script = readFileSync(join(root, 'assets/app.js'), 'utf8');

function boot(ids, selectors, confirm = () => false) {
  const events = {};
  runInNewContext(script, {
    document: { getElementById: id => ids[id], querySelectorAll: s => selectors[s] || [], addEventListener: (name, fn) => { events[name] = fn; } },
    window: { addEventListener() {}, confirm },
  });
  return events;
}

function control(value = '') {
  const listeners = {};
  return { value, style: {}, dataset: {}, addEventListener: (name, fn) => { listeners[name] = fn; }, fire: name => listeners[name]?.() };
}

test('Taipei quick dates preserve the server calendar day across a month boundary', () => {
  const date = control();
  date.dataset.today = '2024-03-01';
  const today = control(), yesterday = control();
  today.getAttribute = () => '0';
  yesterday.getAttribute = () => '-1';
  boot({ entry_date: date }, { '.quick-date-btn': [today, yesterday] });
  today.fire('click'); assert.equal(date.value, '2024-03-01');
  yesterday.fire('click'); assert.equal(date.value, '2024-02-29');
});

test('editing recurring entries is wired through external listeners', () => {
  const modal = control(), open = control(), close = control();
  const fields = Object.fromEntries(['id','name','amount','type','category','start_date','next_due'].map(s => ['edit_recurring_' + s, control()]));
  open.dataset = { recurringId: '1', name: '固定帳單', amountCents: '3250', type: 'expense', category: '餐費', startDate: '2025-03-01', nextDueDate: '2025-04-01' };
  boot({ editRecurringModal: modal, ...fields }, { '[data-recurring-id]': [open], '[data-close-recurring]': [close] });
  open.fire('click'); assert.equal(modal.style.display, 'block');
  assert.equal(fields.edit_recurring_amount.value, '32.50');
  close.fire('click'); assert.equal(modal.style.display, 'none');
});

test('declining a confirmation stops submission, accepting permits it', () => {
  const form = { dataset: { confirm: '封存這個帳本？' } };
  let allow = false, canceled = 0;
  const events = boot({}, {}, message => { assert.equal(message, '封存這個帳本？'); return allow; });
  const request = { target: form, submitter: null, preventDefault: () => canceled++ };
  events.submit?.(request); assert.equal(canceled, 1);
  allow = true; events.submit?.(request); assert.equal(canceled, 1);
});

test('transfer reset clears selections and hides the difference', () => {
  const one = control('1'), two = control('2'), diff = control(), reset = control();
  boot({ entry_id_1: one, entry_id_2: two, diffPreview: diff, diffText: control(), resetPairButton: reset }, {});
  reset.fire('click');
  assert.equal(one.value, ''); assert.equal(two.value, ''); assert.equal(diff.style.display, 'none');
});

test('application templates do not use inline handlers blocked by CSP', () => {
  const templates = [root, join(root, 'admin')].flatMap(dir => readdirSync(dir).filter(f => f.endsWith('.php')).map(f => join(dir, f)));
  const offenders = templates.filter(f => /\son(?:click|submit|change|input|keydown)\s*=/i.test(readFileSync(f, 'utf8')));
  assert.deepEqual(offenders.map(f => f.slice(root.length + 1)), []);
});
