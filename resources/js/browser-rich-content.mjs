import mermaid from "mermaid";
import katex from "katex";
import katexStyles from "katex/dist/katex.min.css";

const selector = "pre > code.lang-mermaid, pre > code.language-mermaid";
const mathSelector = "pre > code.lang-math, pre > code.language-math";

export async function render(root = document) {
  const codes = [...root.querySelectorAll(selector)];
  if (codes.length) mermaid.initialize({ startOnLoad: false, securityLevel: "strict", htmlLabels: false, maxTextSize: 65536 });
  for (const code of codes) {
    const original = code.parentElement;
    if (!original?.isConnected) continue;
    const container = document.createElement("div");
    container.className = "mermaid discussionbridge-mermaid";
    container.textContent = code.textContent ?? "";
    original.replaceWith(container);
    try { await mermaid.run({ nodes: [container], suppressErrors: false }); }
    catch { if (container.isConnected) container.replaceWith(original); }
  }
  for (const code of root.querySelectorAll(mathSelector)) {
    const original = code.parentElement;
    if (!original?.isConnected) continue;
    const container = document.createElement("div");
    container.className = "discussionbridge-math";
    try {
      katex.render(code.textContent ?? "", container, { displayMode: true, throwOnError: true, trust: false, strict: "error", maxExpand: 1000, maxSize: 10, output: "htmlAndMathml" });
      original.replaceWith(container);
    } catch { /* Keep native math source intact on a render failure. */ }
  }
}

const style = document.createElement("style");
style.textContent = katexStyles + ".discussionbridge-mermaid,.discussionbridge-math{max-width:100%;overflow-x:auto}.discussionbridge-mermaid svg{display:block;height:auto;max-width:100%;margin-inline:auto}";
document.head.append(style);
const start = () => render().catch(() => {});
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start, { once: true });
else start();
