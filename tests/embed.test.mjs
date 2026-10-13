import { readFileSync } from "node:fs";
import vm from "node:vm";
import test from "node:test";
import assert from "node:assert/strict";

test("independent native iframe messages require their exact origin and window", () => {
  const frame = (topic, mode) => ({
    src: `https://forum.example/embed/comments?topic_id=${topic}`,
    contentWindow: {}, dataset: { discussionbridgeEmbed: mode }, style: {},
    getBoundingClientRect: () => ({ top: topic }),
  });
  const first = frame(42, "full"), second = frame(43, "full"), interactive = frame(44, "interactive");
  let listener;
  const scrolls = [];
  const context = {
    URL, Number, Math,
    document: { querySelectorAll: () => [first, second, interactive] },
    window: { scrollY: 100, addEventListener: (type, fn) => { assert.equal(type, "message"); listener = fn; },
      scrollTo: (...args) => scrolls.push(args) },
  };
  vm.runInNewContext(readFileSync(new URL("../assets/discussionbridge-embed.js", import.meta.url), "utf8"), context);
  const send = (source, origin, data) => listener({ source, origin, data });
  send(first.contentWindow, "https://forum.example", { type: "discourse-resize", height: 900 });
  assert.equal(first.style.height, "900px");
  assert.equal(second.style.height, undefined);
  send(second.contentWindow, "https://forum.example.attacker.test", { type: "discourse-resize", height: 600 });
  send({}, "https://forum.example", { type: "discourse-resize", height: 600 });
  for (const height of ["800", -1, NaN, Infinity, 100001]) {
    send(second.contentWindow, "https://forum.example", { type: "discourse-resize", height });
  }
  assert.equal(second.style.height, undefined);
  send(second.contentWindow, "https://forum.example", { type: "discourse-resize", height: 600 });
  assert.equal(second.style.height, "600px");
  send(interactive.contentWindow, "https://forum.example", { type: "discourse-resize", height: 900 });
  assert.equal(interactive.style.height, undefined);
  send(second.contentWindow, "https://forum.example", { type: "discourse-scroll", top: 20 });
  assert.deepEqual(scrolls, [[0, 163]]);
});
