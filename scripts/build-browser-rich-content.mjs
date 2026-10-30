import { createRequire } from "node:module";
import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { managedOutput } from "./managed-output.mjs";
import { bundledLicense } from "./bundled-license.mjs";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const dependencyRoot = process.env.DISCUSSIONBRIDGE_BUILD_DEPENDENCY_ROOT ?? root;
const output = path.resolve(root, process.env.DISCUSSIONBRIDGE_BUILD_OUTPUT_DIR ?? "assets");
if (!output.startsWith(`${root}${path.sep}`)) throw new Error("Build output must remain inside the WordPress component.");
for (const directory of [output, path.join(output, "THIRD_PARTY_LICENSES")]) {
  managedOutput(root, directory, "directory");
}
const previousProvenance = path.join(output, "browser-rich-content-provenance.json");
const previousStat = managedOutput(root, previousProvenance);
const previousNotices = previousStat ? JSON.parse(fs.readFileSync(previousProvenance, "utf8")).bundledPackages ?? [] : [];
if (!Array.isArray(previousNotices) || new Set(previousNotices.map(item => item.file)).size !== previousNotices.length) throw new Error("Invalid previous renderer notice ledger");
const verifyPreviousNotice = previous => {
  if (!/^THIRD_PARTY_LICENSES\/[A-Za-z0-9_.-]+\.txt$/.test(previous.file)) throw new Error("Unsafe previous renderer notice.");
  const file = path.join(output, previous.file);
  const stat = managedOutput(root, file);
  if (!stat) return null;
  if (!stat.isFile() || stat.isSymbolicLink()) throw new Error("Linked/non-file previous renderer notice; preserve it.");
  const bytes = fs.readFileSync(file);
  if (bytes.length !== previous.bytes || createHash("sha256").update(bytes).digest("hex") !== previous.sha256) throw new Error("Modified previous renderer notice; preserve and reconcile it.");
  return file;
};
// Refuse changed recorded inputs before replacing any generated output.
for (const previous of previousNotices) verifyPreviousNotice(previous);
const bundleTarget = path.join(output, "discussionbridge-rich-content.js");
managedOutput(root, bundleTarget);
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
const result = await build({ absWorkingDir: root, entryPoints: [path.join(root, "resources/js/browser-rich-content.mjs")], outfile: bundleTarget, write: false, bundle: true, format: "iife", minify: true, nodePaths: [path.join(dependencyRoot, "node_modules")], metafile: true, plugins: [cssPlugin] });
if (result.outputFiles.length !== 1 || path.resolve(result.outputFiles[0].path) !== bundleTarget) throw new Error("Unexpected renderer build output");
const digest = file => {
  const bytes = fs.readFileSync(file);
  return { bytes: bytes.length, sha256: createHash("sha256").update(bytes).digest("hex") };
};
const dependencies = Object.fromEntries(["esbuild", "mermaid", "katex", "dompurify"].map(name => {
  const metadata = JSON.parse(fs.readFileSync(path.join(dependencyRoot, "node_modules", name, "package.json"), "utf8"));
  return [name, { version: metadata.version, license: metadata.license }];
}));
const notices = new Map();
const stagedNotices = new Map();
for (const input of Object.keys(result.metafile.inputs)) {
  let directory = path.dirname(path.resolve(root, input));
  while (directory !== path.dirname(directory)) {
    const manifest = path.join(directory, "package.json");
    if (fs.existsSync(manifest)) {
      const metadata = JSON.parse(fs.readFileSync(manifest, "utf8"));
      if (directory !== root && typeof metadata.name === "string" && typeof metadata.version === "string") {
        const key = `${metadata.name}@${metadata.version}`;
        if (!notices.has(key)) {
          const license = bundledLicense(directory, metadata);
          const relative = `THIRD_PARTY_LICENSES/${key.replace(/[^a-zA-Z0-9_.-]/g, "_")}.txt`;
          const target = path.join(output, relative);
          if (stagedNotices.has(target)) throw new Error("Colliding generated license names");
          const stat = managedOutput(root, target);
          if (stat && !previousNotices.some(item => item.file === relative) && !fs.readFileSync(target).equals(license.bytes)) throw new Error(`Unowned renderer notice; preserve it: ${relative}`);
          stagedNotices.set(target, license.bytes);
          notices.set(key, { name: metadata.name, version: metadata.version, license: metadata.license, file: relative, bytes: license.bytes.length, sha256: createHash("sha256").update(license.bytes).digest("hex"), licenseSource: license.licenseSource });
        }
      }
      break;
    }
    directory = path.dirname(directory);
  }
}
// All dependency notices and managed destinations are valid before changing
// the bundle or any notice. Unknown and edited owned files are never swept.
const inputRecords = Object.fromEntries(["package-lock.json", "resources/js/browser-rich-content.mjs", "scripts/build-browser-rich-content.mjs", "scripts/bundled-license.mjs", "scripts/managed-output.mjs"].map(file => [file, digest(path.join(root, file))]));
fs.mkdirSync(path.join(output, "THIRD_PARTY_LICENSES"), { recursive: true });
fs.writeFileSync(bundleTarget, result.outputFiles[0].contents);
for (const [target, bytes] of stagedNotices) fs.writeFileSync(target, bytes);
// Remove only obsolete files owned by the preceding generated ledger, and only
// while their exact bytes still match it. Never prune arbitrary operator files.
const currentNotices = new Set([...notices.values()].map(item => item.file));
for (const previous of previousNotices) {
  if (currentNotices.has(previous.file)) continue;
  const file = verifyPreviousNotice(previous);
  if (file) fs.unlinkSync(file);
}
fs.writeFileSync(path.join(output, "browser-rich-content-provenance.json"), `${JSON.stringify({
  format: 1, build: "npm ci && npm run build", dependencies,
  localFontInputs: Object.fromEntries([...fontInputs.entries()].sort(([left], [right]) => left < right ? -1 : left > right ? 1 : 0)),
  bundledPackages: [...notices.entries()].sort(([left], [right]) => left < right ? -1 : left > right ? 1 : 0).map(([, value]) => value),
  inputs: inputRecords,
  outputs: { "discussionbridge-rich-content.js": digest(path.join(output, "discussionbridge-rich-content.js")) },
}, null, 2)}\n`);
