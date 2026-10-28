import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";
import vm from "node:vm";

const source = fs.readFileSync(new URL("../resources/js/browser-rich-content.mjs", import.meta.url), "utf8")
  .replace(/^import .*;\r?\n/gmu, "").replace("export async function", "async function") + "\nglobalThis.renderContent = render;";

function fixture() {
  const codes = [];
  const math = [];
  const replacements = [];
  const events = new Map();
  const calls = [];
  const document = {
    readyState: "loading", querySelectorAll: selector => selector.includes('mermaid') ? codes : math,
    createElement: () => ({ isConnected: true, replaceWith: original => replacements.push({ restored: original }) }),
    head: { append() {} }, addEventListener: (name, callback) => events.set(name, callback),
  };
  const context = vm.createContext({ document, katexStyles: "local-font-css", mermaid: { initialize: options => calls.push({ initialize: options }), run: async options => calls.push({ nodes: options.nodes }) }, katex: { render: (text, container, options) => calls.push({ math: text, options }) } });
  const add = (textContent, kind = "mermaid") => {
    const list = kind === "mermaid" ? codes : math;
    const original = { isConnected: true, replaceWith: container => { replacements.push({ container, kind }); list.splice(list.indexOf(code), 1); } };
    const code = { textContent, parentElement: original };
    list.push(code);
  };
  vm.runInContext(source, context);
  return { context, events, calls, replacements, add };
}

test("selected WordPress wrapper uses source text and strict Mermaid controls", async () => {
  const current = fixture();
  current.add('<img onerror="bad()"> graph TD; Source-->Destination');
  await current.events.get("DOMContentLoaded")();
  assert.equal(current.calls[0].initialize.securityLevel, "strict");
  assert.equal(current.calls[0].initialize.htmlLabels, false);
  assert.equal(current.calls[0].initialize.maxTextSize, 65536);
  assert.equal(Object.hasOwn(current.replacements[0].container, "innerHTML"), false);
  assert.match(current.replacements[0].container.textContent, /<img onerror=/);
});

test("ordinary WordPress content does not initialize Mermaid", async () => {
  const current = fixture();
  await current.events.get("DOMContentLoaded")();
  assert.equal(current.calls.length, 0);
});

test("failed diagrams restore their original WordPress source blocks", async () => {
  const current = fixture();
  current.add("invalid diagram");
  current.context.mermaid.run = async () => { throw new Error("Parse failed"); };
  await current.context.renderContent();
  assert.ok(current.replacements.some(item => item.restored));
});

test("selected math uses local styles and bounded untrusted commands, preserving failed source", async () => {
  const current = fixture();
  current.add("x^2", "math");
  await current.context.renderContent();
  assert.equal(current.calls[0].options.trust, false);
  assert.equal(current.calls[0].options.output, "htmlAndMathml");
  assert.equal(current.replacements[0].kind, "math");
  current.add("bad math", "math");
  current.context.katex.render = () => { throw new Error("Parse failed"); };
  const before = current.replacements.length;
  await current.context.renderContent();
  assert.equal(current.replacements.length, before);
});
