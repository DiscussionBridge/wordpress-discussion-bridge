import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Actual browser asset, minimal in-memory DOM boundary; no browser, network,
// filesystem writes, packages or generated output.
const source = readFileSync(new URL('../resources/js/cms-source-retry.js', import.meta.url), 'utf8');
let listener;
const submitted = [];
class Element {
  constructor(button = null) { this.button = button; }
  closest(selector) { assert.equal(selector, '[data-discussionbridge-retry]'); return this.button; }
}
const document = {
  addEventListener(name, callback) { assert.equal(name, 'click'); listener = callback; },
  createElement(tag) {
    return { tag, children: [], appendChild(child) { this.children.push(child); }, submit() { submitted.push(this); } };
  },
  body: { children: [], appendChild(child) { this.children.push(child); } }
};
vm.runInNewContext(source, { document, Element });
assert.equal(typeof listener, 'function');
listener({ target: {} });
listener({ target: new Element() });
assert.equal(submitted.length, 0);
const button = { disabled: false, dataset: { actionUrl: 'https://wordpress.example/wp-admin/admin-post.php',
  postId: '7', requestIdentity: 'a'.repeat(64), nonce: 'synthetic-native-nonce' } };
const nestedTarget = new Element(button);
listener({ target: nestedTarget });
assert.equal(submitted.length, 1);
assert.equal(document.body.children[0], submitted[0]);
assert.equal(submitted[0].method, 'post');
assert.equal(submitted[0].action, button.dataset.actionUrl);
assert.deepEqual(Object.fromEntries(submitted[0].children.map(x => [x.name, x.value])), {
  action: 'discussionbridge_retry', post_id: '7', request_identity: 'a'.repeat(64), _wpnonce: 'synthetic-native-nonce'
});
assert.ok(submitted[0].children.every(x => x.type === 'hidden'));
assert.equal(button.disabled, true);
listener({ target: nestedTarget });
assert.equal(submitted.length, 1);
console.log(JSON.stringify({ result: 'passed', observations: ['ignored unrelated click', 'submitted digest-bound POST', 'separate native form', 'disabled duplicate click'],
  boundary: 'actual native admin asset with in-memory DOM; installed browser and native CSRF/session unexecuted' }));
