import assert from "node:assert/strict";
import {spawnSync} from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import {tmpdir} from "node:os";
import {fileURLToPath} from "node:url";
import test from "node:test";

const root = fileURLToPath(new URL("../", import.meta.url));
const php = process.env.DISCUSSIONBRIDGE_PHP_BINARY ?? "php";
for (const fault of ["intact", "codec", "schema", "dependency-manifest", "autoload", "missing-license"]) {
  test(`actual WordPress artifact loader ${fault}`, () => {
    const temporary = fs.mkdtempSync(path.join(tmpdir(), "discussionbridge-wp-integrity-"));
    try {
      const source = path.join(temporary, "src");
      fs.mkdirSync(source);
      fs.copyFileSync(path.join(root,"src/CmsArtifacts.php"),path.join(source,"CmsArtifacts.php"));
      fs.cpSync(path.join(root,"src/private"),path.join(source,"private"),{recursive:true});
      const privateRoot = path.join(source,"private/cms-v4");
      const sentinel = path.join(temporary,"executed.txt");
      const injection = `\nfile_put_contents(${JSON.stringify(sentinel.replaceAll("\\","/"))}, "executed");\n`;
      if (fault === "codec") fs.appendFileSync(path.join(privateRoot,"CmsCodec.php"),injection);
      if (fault === "schema") fs.appendFileSync(path.join(privateRoot,"cms-schema.json")," ");
      if (fault === "dependency-manifest") fs.appendFileSync(path.join(privateRoot,"idna/DEPENDENCIES.json")," ");
      if (fault === "autoload") fs.appendFileSync(path.join(privateRoot,"idna/vendor/autoload.php"),injection);
      if (fault === "missing-license") fs.unlinkSync(path.join(privateRoot,"idna/vendor/rowbot/idna/LICENSE"));
      const program = path.join(temporary,"probe.php");
      fs.writeFileSync(program, `<?php require __DIR__ . '/src/CmsArtifacts.php'; $c = \\DiscussionBridge\\WordPress\\CmsArtifacts::codec(); $c->validate('Url', 'https://é.example/'); for ($i=0;$i<3;++$i) { if (class_exists('Composer\\\\InstalledVersions')) throw new RuntimeException('Private SDK exposed global Composer'); } if (class_exists('Composer\\\\Autoload\\\\ClassLoader',false)) throw new RuntimeException('Private SDK installed global Composer engine'); echo 'loaded';`);
      const result = spawnSync(php,["-n",program],{encoding:"utf8",windowsHide:true,timeout:20000});
      assert.equal(result.error,undefined,result.error?.message);
      assert.equal(result.status,fault === "intact" ? 0 : 255,result.stderr || result.stdout);
      if (fault !== "intact") {
        const expected = fault === "codec" || fault === "schema" ? /installed CMS development artifact/
          : fault === "dependency-manifest" ? /private IDNA identity mismatch/ : /private IDNA member mismatch/;
        assert.match(result.stderr + result.stdout,expected,"Failure did not come from the intended integrity boundary");
      }
      assert.equal(fs.existsSync(sentinel),false,"Unverified source was executed before its integrity check");
      if (fault === "intact") assert.equal(result.stdout,"loaded");
    } finally {
      const resolved = fs.realpathSync(temporary), parent = fs.realpathSync(tmpdir());
      assert.equal(path.dirname(resolved),parent);
      assert.ok(path.basename(resolved).startsWith("discussionbridge-wp-integrity-"));
      fs.rmSync(resolved,{recursive:true});
    }
  });
}
