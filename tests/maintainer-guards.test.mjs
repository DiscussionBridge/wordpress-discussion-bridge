import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { tmpdir } from "node:os";
import { createHash } from "node:crypto";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import test from "node:test";
import { bundledLicense } from "../scripts/bundled-license.mjs";

const root = fileURLToPath(new URL("../", import.meta.url));
const hash = bytes => createHash("sha256").update(bytes).digest("hex");
const inputs = ["scripts/build-browser-rich-content.mjs", "scripts/refresh-contract.mjs", "scripts/bundled-license.mjs", "scripts/managed-output.mjs", "resources/js/browser-rich-content.mjs", "src/CmsArtifacts.php", "discussionbridge-release.json", "package.json", "package-lock.json"];
function fixture() {
  const parent = fs.mkdtempSync(path.join(tmpdir(), "wp-maintainer-guard-"));
  const component = path.join(parent, "component");
  for (const name of inputs) {
    fs.mkdirSync(path.dirname(path.join(component, name)), {recursive:true});
    fs.copyFileSync(path.join(root, name), path.join(component, name));
  }
  return {parent, component};
}
function cleanup(parent) {
  const actual = fs.realpathSync(parent);
  assert.equal(path.dirname(actual), fs.realpathSync(tmpdir()));
  assert.ok(path.basename(actual).startsWith("wp-maintainer-guard-"));
  fs.rmSync(actual, {recursive:true});
}
function snapshot(parent) {
  const rows = [];
  function walk(at, relative = "") {
    for (const name of fs.readdirSync(at).sort()) {
      const file = path.join(at,name), key = path.join(relative,name), stat = fs.lstatSync(file);
      if (stat.isSymbolicLink()) rows.push([key,"link",fs.readlinkSync(file)]);
      else if (stat.isDirectory()) { rows.push([key,"directory"]);walk(file,key); }
      else rows.push([key,"file",hash(fs.readFileSync(file))]);
    }
  }
  walk(parent);
  return rows;
}
function run(component, script) {
  return spawnSync(process.execPath, [path.join(component,"scripts",script), ...(script === "refresh-contract.mjs" ? [path.join(component,"no-selected-source")] : [])], {
    cwd:component, encoding:"utf8", timeout:90000, windowsHide:true,
    env:{...process.env,DISCUSSIONBRIDGE_BUILD_DEPENDENCY_ROOT:root,DISCUSSIONBRIDGE_BUILD_OUTPUT_DIR:"assets"},
  });
}

for (const [script, relative, kind] of [
  ["build-browser-rich-content.mjs","assets","directory"],
  ["build-browser-rich-content.mjs","assets/THIRD_PARTY_LICENSES","directory"],
  ["build-browser-rich-content.mjs","assets/discussionbridge-rich-content.js","file"],
  ["build-browser-rich-content.mjs","assets/browser-rich-content-provenance.json","file"],
  ["refresh-contract.mjs","src/private/cms-v4/idna","directory"],
  ["refresh-contract.mjs","src/private/cms-v4/PROVENANCE.json","file"],
  ["refresh-contract.mjs","src/private/cms-v4/CmsCodec.php","file"],
]) for (const live of [true,false]) {
  test(`${script} refuses ${live ? "live" : "dangling"} ${relative} before any write`, t => {
    const {parent,component} = fixture();
    try {
      const outside = path.join(parent,"outside");
      if (live) {
        if (kind === "directory") { fs.mkdirSync(outside);fs.writeFileSync(path.join(outside,"sentinel"),"preserve"); }
        else fs.writeFileSync(outside,"preserve");
      }
      const link = path.join(component,relative);
      fs.mkdirSync(path.dirname(link),{recursive:true});
      try { fs.symlinkSync(outside,link,kind === "directory" && process.platform === "win32" ? "junction" : kind === "directory" ? "dir" : "file"); }
      catch(error) { if (process.platform === "win32" && error.code === "EPERM") { t.skip("Windows file-link privilege unavailable; Linux CI must execute this case");return; }throw error; }
      const before = snapshot(parent);
      const result = run(component,script);
      assert.equal(result.error,undefined,result.error?.message);
      assert.notEqual(result.status,0);
      assert.match(result.stderr,/Invalid or linked managed output/);
      assert.deepEqual(snapshot(parent),before);
    } finally { cleanup(parent); }
  });
}

for (const kind of ["wrapper","release"]) test(`refresh refuses invalid ${kind} before reading source or changing managed bytes`, () => {
  const {parent,component} = fixture();
  try {
    const codec = path.join(component,"src/private/cms-v4/CmsCodec.php");
    fs.mkdirSync(path.dirname(codec),{recursive:true});
    fs.writeFileSync(codec,"Retained managed preimage\n");
    if (kind === "wrapper") {
      const file = path.join(component,"src/CmsArtifacts.php");
      fs.writeFileSync(file,fs.readFileSync(file,"utf8").replace("public const CANDIDATE =", "public const INVALID_CANDIDATE ="));
    } else fs.writeFileSync(path.join(component,"discussionbridge-release.json"),"{invalid");
    const before = snapshot(parent);
    const result = run(component,"refresh-contract.mjs");
    assert.equal(result.error,undefined,result.error?.message);
    assert.notEqual(result.status,0);
    assert.match(result.stderr,kind === "wrapper" ? /Ambiguous component pin: CANDIDATE/ : /SyntaxError/);
    assert.deepEqual(snapshot(parent),before);
  } finally { cleanup(parent); }
});

test("a new unowned notice collision does not partially replace a renderer", () => {
  const {parent,component} = fixture();
  try {
    fs.mkdirSync(path.join(component,"assets/THIRD_PARTY_LICENSES"),{recursive:true});
    fs.writeFileSync(path.join(component,"assets/discussionbridge-rich-content.js"),"Keep prior bundle\n");
    fs.writeFileSync(path.join(component,"assets/THIRD_PARTY_LICENSES/fastdom_1.0.12.txt"),"Operator file, not generated\n");
    const before = snapshot(parent);
    const result = run(component,"build-browser-rich-content.mjs");
    assert.equal(result.error,undefined,result.error?.message);
    assert.notEqual(result.status,0);
    assert.match(result.stderr,/Unowned renderer notice/);
    assert.deepEqual(snapshot(parent),before);
  } finally { cleanup(parent); }
});

test("actual FastDom license is source-pinned and missing or modified required notices refuse", () => {
  const parent = fs.mkdtempSync(path.join(tmpdir(),"wp-maintainer-guard-"));
  try {
    const readme = path.join(parent,"README.md");
    fs.copyFileSync(path.join(root,"node_modules/fastdom/README.md"),readme);
    const metadata = {name:"fastdom",version:"1.0.12",license:"MIT"};
    const notice = bundledLicense(parent,metadata);
    assert.equal(hash(notice.bytes),"df2ae14f12f31864acef768195afe4584b08a257f25925f49884619279f02f37");
    assert.throws(()=>bundledLicense(parent,{...metadata,name:"unknown-license-package"}),/Required bundled license unavailable/);
    fs.appendFileSync(readme,"edited");
    assert.throws(()=>bundledLicense(parent,metadata),/Unexpected FastDom README/);
    fs.unlinkSync(readme);
    assert.throws(()=>bundledLicense(parent,metadata),/ENOENT/);
  } finally { cleanup(parent); }
});
