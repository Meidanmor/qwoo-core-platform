// Packs the push notification libraries (Composer's vendor folder next to this file) into ONE file,
// includes/push-lib.phar, so a store has one file instead of ~1,150 (Hostinger counts every file).
// The plugin loads it only when a push is sent or keys are made (includes/push-lib.php).
//
// Run after changing vendor/ (composer update in this folder), with qwoo-dev's PHP (no PHP installed here):
//   C:\Users\meida\PhpstormProjects\qwoo-dev\tools\node\node.exe .build/push-lib/build.mjs
// Dot folders are never copied to stores (the platform's setup script skips them), so this folder stays here.
import { createRequire } from 'module'
import { readFileSync, writeFileSync, readdirSync, statSync } from 'fs'
import { join, relative, dirname } from 'path'
import { fileURLToPath } from 'url'

const here = dirname(fileURLToPath(import.meta.url))
const out = join(here, '..', '..', 'includes', 'push-lib.phar')
const require = createRequire('C:/Users/meida/PhpstormProjects/qwoo-dev/tools/package.json')
const { loadNodeRuntime } = require('@php-wasm/node')
const { PHP, setPhpIniEntries } = require('@php-wasm/universal')

// Files the code never reads: docs, tests, change logs. Licences stay.
const SKIP = /(^|\/)(tests?|docs?|\.github)(\/|$)|\.(md|rst|dist|xml|neon|yml|yaml)$/i
const files = []
;(function walk(dir) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name)
    if (statSync(p).isDirectory()) walk(p)
    else {
      const rel = relative(here, p).split('\\').join('/')
      if (!SKIP.test(rel.replace(/^vendor\//, ''))) files.push(rel)
    }
  }
})(join(here, 'vendor'))

const php = new PHP(await loadNodeRuntime('8.2', { emscriptenOptions: { processId: 1 } }))
await setPhpIniEntries(php, { 'phar.readonly': '0' })
php.mkdir('/src')
for (const rel of files) {
  php.mkdir('/src/' + dirname(rel))
  php.writeFile('/src/' + rel, readFileSync(join(here, rel)))
}
const r = await php.run({ code: `<?php
  $p = new Phar('/tmp/push-lib.phar');
  $p->startBuffering();
  $p->buildFromDirectory('/src');
  // Does nothing if opened in a browser.
  $p->setStub('<?php http_response_code(404); __HALT_COMPILER();');
  $p->stopBuffering();
  echo count($p);
` })
if (r.errors) { console.error(r.errors); process.exit(1) }
writeFileSync(out, php.readFileAsBuffer('/tmp/push-lib.phar'))
console.log(`push-lib.phar: ${r.text} files, ${Math.round(statSync(out).size / 1024)} KB`)
process.exit(0)
