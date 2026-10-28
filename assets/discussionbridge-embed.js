/* Each selected iframe retains its own native Discourse topic and message source. */
(() => {
  "use strict";
  window.addEventListener("message", (event) => {
    if (!event || !event.data || typeof event.data !== "object") return;
    for (const frame of document.querySelectorAll("iframe[data-discussionbridge-embed]")) {
      if (event.source !== frame.contentWindow) continue;
      let origin;
      try { origin = new URL(frame.src).origin; } catch { continue; }
      if (event.origin !== origin) continue;
      if (event.data.type === "discourse-resize" && frame.dataset.discussionbridgeEmbed !== "interactive") {
        const height = event.data.height;
        if (typeof height === "number" && Number.isFinite(height) && height > 0 && height <= 100000) {
          frame.style.height = Math.ceil(height) + "px";
        }
      } else if (event.data.type === "discourse-scroll") {
        const top = event.data.top;
        if (typeof top === "number" && Number.isFinite(top) && top >= 0 && top <= 100000) {
          window.scrollTo(0, frame.getBoundingClientRect().top + window.scrollY + top);
        }
      }
    }
  }, false);
})();
