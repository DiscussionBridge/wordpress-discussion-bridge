const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");

const editorSource = fs.readFileSync(path.join(__dirname, "../blocks/from-discourse/index.js"), "utf8");

function editor(attributes) {
  let registered;
  const changes = [];
  const SelectControl = Symbol("SelectControl");
  const wp = {
    blocks: { registerBlockType(name, definition) {
      assert.equal(name, "discussionbridge/from-discourse");
      registered = definition;
    } },
    blockEditor: { useBlockProps: () => ({}) },
    components: { TextControl: Symbol("TextControl"), SelectControl },
    element: { createElement: (type, props, ...children) => ({ type, props, children }) },
    i18n: { __: (message) => message },
  };
  vm.runInNewContext(editorSource, { window: { wp } }, { filename: "actual-block-editor.js", timeout: 1000 });
  const before = { ...attributes };
  const output = registered.edit({ attributes, setAttributes: (value) => changes.push(value) });
  assert.deepEqual(attributes, before, "initial render must not convert saved values");
  assert.equal(changes.length, 0, "initial render must not save a default");
  const select = output.children.find((child) => child.type === SelectControl);
  assert.ok(select, "actual editor must expose its mode control");
  return { select: select.props, changes };
}

test("absent and canonical modes display the intended value without saving conversion", () => {
  assert.equal(editor({}).select.value, "interactive");
  for (const mode of ["none", "simple", "full", "interactive"]) {
    const { select, changes } = editor({ commentsMode: mode });
    assert.equal(select.value, mode);
    assert.ok(select.options.some((option) => option.value === mode && !option.disabled));
    assert.equal(changes.length, 0);
  }
});

test("explicit unsupported modes display an invalid state, never an Interactive substitute", () => {
  for (const mode of ["", null, undefined, false, 0, {}, "bridge", "fullInteractive", "full_interactive"]) {
    const { select, changes } = editor({ commentsMode: mode });
    assert.equal(select.value, "", "unsupported mode must not be depicted as Interactive");
    const invalidOption = select.options.find((option) => option.value === "");
    assert.ok(invalidOption && invalidOption.disabled);
    assert.match(invalidOption.label, /unsupported/i);
    assert.match(select.help, /not been changed/i);
    assert.equal(changes.length, 0);
  }
});

test("only a deliberate supported selection updates the saved mode", () => {
  const { select, changes } = editor({ commentsMode: "" });
  select.onChange("");
  select.onChange("fullInteractive");
  assert.equal(changes.length, 0);
  select.onChange("interactive");
  assert.equal(changes.length, 1);
  assert.equal(changes[0].commentsMode, "interactive");
});
