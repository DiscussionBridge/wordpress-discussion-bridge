import { createRequire } from "node:module";
import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const dependencyRoot = process.env.DISCUSSIONBRIDGE_BUILD_DEPENDENCY_ROOT ?? root;
const output = path.resolve(root, process.env.DISCUSSIONBRIDGE_BUILD_OUTPUT_DIR ?? "assets");
if (!output.startsWith(`${root}${path.sep}`)) throw new Error("Build output must remain inside the WordPress component.");
fs.mkdirSync(output, { recursive: true });
const require = createRequire(import.meta.url);
const { build } = require(require.resolve("esbuild", { paths: [dependencyRoot] }));
const fontInputs = new Map();
const cssPlugin = {
  name: "local-katex-css-fonts",
  setup(builder) {
    builder.onLoad({ filter: /[\\/]katex[\\/]dist[\\/]katex\.min\.css$/ }, async args => {
      const directory = path.dirname(args.path);
      const contents = fs.readFileSync(args.path, "utf8").replace(/url\((fonts\/[^)]+)\)/gu, (_, relative) => {
        const font = path.resolve(directory, relative);
        if (!font.startsWith(`${path.join(directory, "fonts")}${path.sep}`)) throw new Error("KaTeX font path escaped its package.");
        const bytes = fs.readFileSync(font);
        const extension = path.extname(font).slice(1);
        if (!["woff2", "woff", "ttf"].includes(extension)) throw new Error("Unsupported KaTeX font format.");
        fontInputs.set(`katex/dist/${relative}`, { bytes: bytes.length, sha256: createHash("sha256").update(bytes).digest("hex") });
        return `url(data:font/${extension};base64,${bytes.toString("base64")})`;
      });
      return { contents, loader: "text", resolveDir: directory };
    });
  },
};
const result = await build({ absWorkingDir: root, entryPoints: ["resources/js/browser-rich-content.mjs"], outfile: path.join(output, "discussionbridge-rich-content.js"), bundle: true, format: "iife", minify: true, nodePaths: [path.join(dependencyRoot, "node_modules")], metafile: true, plugins: [cssPlugin] });
const digest = file => {
  const bytes = fs.readFileSync(file);
  return { bytes: bytes.length, sha256: createHash("sha256").update(bytes).digest("hex") };
};
const dependencies = Object.fromEntries(["esbuild", "mermaid", "katex", "dompurify"].map(name => {
  const metadata = JSON.parse(fs.readFileSync(path.join(dependencyRoot, "node_modules", name, "package.json"), "utf8"));
  return [name, { version: metadata.version, license: metadata.license }];
}));
const notices = new Map();
for (const input of Object.keys(result.metafile.inputs)) {
  let directory = path.dirname(path.resolve(root, input));
  while (directory !== path.dirname(directory)) {
    const manifest = path.join(directory, "package.json");
    if (fs.existsSync(manifest)) {
      const metadata = JSON.parse(fs.readFileSync(manifest, "utf8"));
      if (directory !== root && typeof metadata.name === "string" && typeof metadata.version === "string") {
        const key = `${metadata.name}@${metadata.version}`;
        if (!notices.has(key)) {
          const license = fs.readdirSync(directory).find(file => /^licen[cs]e(?:\.(?:md|txt))?$/i.test(file));
          const text = license ? fs.readFileSync(path.join(directory, license), "utf8") : `License declared by package: ${JSON.stringify(metadata.license)}\nSource: ${JSON.stringify(metadata.repository)}\n`;
          const relative = `THIRD_PARTY_LICENSES/${key.replace(/[^a-zA-Z0-9_.-]/g, "_")}.txt`;
          fs.mkdirSync(path.join(output, "THIRD_PARTY_LICENSES"), { recursive: true });
          const target = path.join(output, relative);
          fs.writeFileSync(target, text);
          notices.set(key, { name: metadata.name, version: metadata.version, license: metadata.license, file: relative, ...digest(target) });
        }
      }
      break;
    }
    directory = path.dirname(directory);
  }
}
fs.writeFileSync(path.join(output, "browser-rich-content-provenance.json"), `${JSON.stringify({
  format: 1, build: "npm ci && npm run build", dependencies,
  localFontInputs: Object.fromEntries([...fontInputs.entries()].sort(([left], [right]) => left < right ? -1 : left > right ? 1 : 0)),
  bundledPackages: [...notices.entries()].sort(([left], [right]) => left < right ? -1 : left > right ? 1 : 0).map(([, value]) => value),
  inputs: Object.fromEntries(["package-lock.json", "resources/js/browser-rich-content.mjs", "scripts/build-browser-rich-content.mjs"].map(file => [file, digest(path.join(root, file))])),
  outputs: { "discussionbridge-rich-content.js": digest(path.join(output, "discussionbridge-rich-content.js")) },
}, null, 2)}\n`);
