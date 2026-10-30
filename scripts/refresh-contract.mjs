// Maintainer-only offline pairing. Operators install the complete normal ZIP.
import fs from "node:fs";
import path from "node:path";
import { createHash } from "node:crypto";
import { fileURLToPath } from "node:url";
import { managedOutput } from "./managed-output.mjs";

if (process.argv.length !== 3) throw new Error("Usage: node scripts/refresh-contract.mjs <reviewed-contract-root>");
const root = fileURLToPath(new URL("../", import.meta.url));
const source = path.resolve(process.argv[2]);
const pins = {
  commit: "09d77c0209835c2d996aad294d161f167c7bd838",
  candidate: "discussionbridge-v4-core-20261029-r7",
  normative: "808042688cbde88fac0acdf1187e4cb8c251e13025bfcf09cf3a3c361ca02003",
  schema: "e6a15a6d70f7bc8b8ee82aa22a0b9c2ff1bbf2da6277f7087118be9e026ce5c0",
  codec: "502df4f0324ac9b65c0068f1025d71290d149522e98a29382763816f01a20555",
  idna: "478d6e5c8f96255f7124e5bd0d28f79975d4b8466fea097ef2edb2fb1151c282",
};
const hash = bytes => createHash("sha256").update(bytes).digest("hex");
const fixturePins = {
  "positive-examples.json":"b3ea942e9fe6b824ab2a0af5f4eecf46b672d544856fea53929d60a359225b30",
  "url-domain-policy.json":"e6fa88694fe1142f8754c2e50cbee752a9755d63324247aaa9db465450d5672e",
  "url-contextj.json":"5cf9aee605cd002a75d929da35fe0f6bcb99b2dd83a78a71751dc24b95adadd8",
};
const target = path.join(root, "src/private/cms-v4");
for (const name of ["src", "src/private", "src/private/cms-v4", "src/private/cms-v4/idna", "tests", "tests/fixtures", "tests/fixtures/cms-v4"]) managedOutput(root, path.join(root, name), "directory");
for (const name of ["src/CmsArtifacts.php", "discussionbridge-release.json", ...Object.keys(fixturePins).map(name => `tests/fixtures/cms-v4/${name}`)]) managedOutput(root, path.join(root, name));
for (const name of ["PROVENANCE.json", "CmsCodec.php", "cms-schema.json"]) managedOutput(root, path.join(target, name));
// Fail on unusable target metadata even before reading the selected source.
// Dynamic dependency destinations are checked below, before the first write.
const wrapper = path.join(root,"src/CmsArtifacts.php");
let text = fs.readFileSync(wrapper,"utf8");
for (const [name,value] of [["CANDIDATE",pins.candidate],["CODEC_SHA256",pins.codec],["SCHEMA_SHA256",pins.schema]]) {
  const pattern = new RegExp(`public const ${name} = '[^']*';`,"g");
  if ([...text.matchAll(pattern)].length !== 1) throw new Error(`Ambiguous component pin: ${name}`);
  text = text.replace(pattern,`public const ${name} = '${value}';`);
}
const releaseFile = path.join(root,"discussionbridge-release.json");
const release = JSON.parse(fs.readFileSync(releaseFile,"utf8"));
if (!release || typeof release !== "object" || Array.isArray(release)) throw new Error("Invalid component release metadata");
release.candidate_id = pins.candidate;
release.contract = {schema_sha256:pins.schema,php_sha256:pins.codec,normative_sha256:pins.normative,php_dependencies_sha256:pins.idna};
const safe = name => typeof name === "string" && /^[A-Za-z0-9_./-]+$/.test(name)
  && !name.startsWith("/") && !name.split("/").some(part => ["", ".", ".."].includes(part));
function read(name, expected) {
  if (!safe(name)) throw new Error("Unsafe contract member");
  const file = path.join(source, name), stat = fs.lstatSync(file);
  if (!stat.isFile() || stat.isSymbolicLink()) throw new Error("Linked/non-file contract member");
  const bytes = fs.readFileSync(file);
  if (expected && hash(bytes) !== expected) throw new Error(`Wrong reviewed member: ${name}`);
  return bytes;
}
function inventory(base, prefix = "") {
  return fs.readdirSync(base).flatMap(name => {
    const file = path.join(base, name), stat = fs.lstatSync(file);
    if (stat.isSymbolicLink()) throw new Error("Linked contract member");
    if (stat.isDirectory()) return inventory(file, `${prefix}${name}/`);
    if (!stat.isFile()) throw new Error("Special contract member");
    return [`${prefix}${name}`];
  });
}
const candidate = JSON.parse(read("CANDIDATE.json"));
const artifacts = JSON.parse(read("ARTIFACTS.json"));
if (candidate.candidate_id !== pins.candidate || artifacts.candidate_id !== pins.candidate
  || candidate.wire_version !== "v4" || candidate.implementation_version !== "0.4.0-alpha.1"
  || candidate.release_version !== null) throw new Error("Unexpected contract candidate");
read("normative/CONTRACT.json", pins.normative);
const manifestBytes = read("src/php/idna/DEPENDENCIES.json", pins.idna);
const dependencies = JSON.parse(manifestBytes);
if (dependencies.php_minimum !== "8.1" || dependencies.additional_mandatory_extensions.length !== 0
  || dependencies.namespace !== "DiscussionBridge\\CmsV4\\IdnaVendor") throw new Error("Private runtime requirements changed");
const members = new Map([
  ["CmsCodec.php", read("src/php/CmsCodec.php", pins.codec)],
  ["cms-schema.json", read("src/cms-schema.json", pins.schema)],
  ["idna/DEPENDENCIES.json", manifestBytes],
]);
for (const item of dependencies.members) {
  if (!safe(item.path) || members.has(`idna/${item.path}`)) throw new Error("Unsafe/duplicate dependency member");
  const bytes = read(`src/php/idna/${item.path}`, item.sha256);
  if (bytes.length !== item.bytes) throw new Error("Private dependency size mismatch");
  members.set(`idna/${item.path}`, bytes);
}
const expected = new Set([...members.keys()].filter(name => name.startsWith("idna/")).map(name => name.slice(5)));
const actual = inventory(path.join(source, "src/php/idna"));
if (actual.length !== expected.size || actual.some(name => !expected.has(name))) throw new Error("Private dependency inventory mismatch");
const fixtures = Object.entries(fixturePins).map(([name,sha]) => ({name, bytes:read(`fixtures/${name}`,sha)}));
for (const name of ["PROVENANCE.json", ...members.keys()]) {
  managedOutput(root, path.join(target, name));
}
if (fs.existsSync(path.join(target, "idna")) && inventory(path.join(target, "idna")).some(name => !expected.has(name))) throw new Error("Reconcile unmanaged old private members before refreshing");
for (const [name, bytes] of members) {
  const file = path.join(target, name);
  fs.mkdirSync(path.dirname(file), {recursive:true});
  fs.writeFileSync(file, bytes);
}
const provenance = {
  candidate_id:pins.candidate, implementation_version:candidate.implementation_version, wire_version:"v4",
  release_version:null, status:candidate.status,
  source:{repository:"https://github.com/DiscussionBridge/discussionbridge-adapter-contract",commit:pins.commit},
  normative_sha256:pins.normative, dependency_manifest_sha256:pins.idna,
  members:[...members].sort(([a],[b]) => a < b ? -1 : a > b ? 1 : 0).map(([name,bytes]) => ({path:name,bytes:bytes.length,sha256:hash(bytes)})),
  fixtures:fixtures.map(({name,bytes}) => ({path:`tests/fixtures/cms-v4/${name}`,bytes:bytes.length,sha256:hash(bytes)})),
  limitation:"Exact SDK bytes only; no connection/native ownership/installed-platform/release authority.",
};
fs.writeFileSync(path.join(target,"PROVENANCE.json"),JSON.stringify(provenance,null,2)+"\n");
for (const {name,bytes} of fixtures) {
  fs.mkdirSync(path.join(root,"tests/fixtures/cms-v4"),{recursive:true});
  fs.writeFileSync(path.join(root,"tests/fixtures/cms-v4",name),bytes);
}
fs.writeFileSync(wrapper,text);
fs.writeFileSync(releaseFile,JSON.stringify(release,null,2)+"\n");
console.log(JSON.stringify({candidate:pins.candidate,private_members:members.size,fixtures:fixtures.length,source_commit:pins.commit}));
