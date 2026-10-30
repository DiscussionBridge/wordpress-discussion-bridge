import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = fileURLToPath(new URL("../", import.meta.url));
test("normal renderer rebuild prunes only exact owned obsolete notices and refuses modified ones before writing", () => {
  const scratch = path.join(root, ".tmp");
  fs.mkdirSync(scratch, {recursive:true});
  const output = fs.mkdtempSync(path.join(scratch, "renderer-ownership-"));
  const run = () => spawnSync(process.execPath, ["scripts/build-browser-rich-content.mjs"], {
    cwd:root, encoding:"utf8", timeout:90000, windowsHide:true,
    env:{...process.env, DISCUSSIONBRIDGE_BUILD_OUTPUT_DIR:path.relative(root,output)},
  });
  const success = result => { assert.equal(result.error,undefined,result.error?.message); assert.equal(result.status,0,result.stderr); };
  const hash = bytes => createHash("sha256").update(bytes).digest("hex");
  const ledger = path.join(output,"browser-rich-content-provenance.json");
  const staleName = "THIRD_PARTY_LICENSES/owned_obsolete_0.0.0.txt";
  const stale = path.join(output,staleName);
  const addStale = () => {
    const bytes = Buffer.from("An old generated notice.\n");
    fs.writeFileSync(stale,bytes);
    const provenance = JSON.parse(fs.readFileSync(ledger,"utf8"));
    provenance.bundledPackages.push({name:"owned-obsolete",version:"0.0.0",license:"MIT",file:staleName,bytes:bytes.length,sha256:hash(bytes)});
    fs.writeFileSync(ledger,JSON.stringify(provenance)+"\n");
  };
  try {
    success(run());
    const unknown = path.join(output,"THIRD_PARTY_LICENSES/operator-note.txt");
    fs.writeFileSync(unknown,"Keep this unrecorded file.\n");
    addStale();
    success(run());
    assert.equal(fs.existsSync(stale),false);
    assert.equal(fs.readFileSync(unknown,"utf8"),"Keep this unrecorded file.\n");
    addStale();
    fs.appendFileSync(stale,"Operator edit.\n");
    const bundle = fs.readFileSync(path.join(output,"discussionbridge-rich-content.js"));
    const before = fs.readFileSync(ledger);
    const failed = run();
    assert.equal(failed.error,undefined,failed.error?.message);
    assert.notEqual(failed.status,0);
    assert.match(failed.stderr,/Modified previous renderer notice/);
    assert.deepEqual(fs.readFileSync(ledger),before);
    assert.deepEqual(fs.readFileSync(path.join(output,"discussionbridge-rich-content.js")),bundle);
    assert.match(fs.readFileSync(stale,"utf8"),/Operator edit/);
    assert.equal(fs.readFileSync(unknown,"utf8"),"Keep this unrecorded file.\n");
  } finally {
    const resolved = fs.realpathSync(output);
    assert.equal(path.dirname(resolved),fs.realpathSync(scratch));
    assert.ok(path.basename(resolved).startsWith("renderer-ownership-"));
    fs.rmSync(resolved,{recursive:true});
  }
});
