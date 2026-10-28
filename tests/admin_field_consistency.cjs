/* Keep the selectable administration fields aligned with the PHP parser. */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const {execFileSync}=require('node:child_process');
const root=path.join(__dirname,'..');
const script=fs.readFileSync(path.join(root,'src/Resources/app/administration/src/main.js'),'utf8');
const context={Shopware:{Component:{register(){}},Mixin:{getByName(){return {};}}}};
vm.runInNewContext(script+'; globalThis.fieldSnapshot = {labels: Object.keys(indexFieldLabels), defaults: defaultIndexFields};',context);
const php="require $argv[1].'/src/Search/IndexFields.php'; echo json_encode([ShopwareMeilisearch\\Search\\IndexFields::STANDARD, ShopwareMeilisearch\\Search\\IndexFields::DEFAULTS]);";
const [allowed,defaults]=JSON.parse(execFileSync('php',['-r',php,root],{encoding:'utf8'}));
assert.deepEqual(Array.from(context.fieldSnapshot.labels).sort(),allowed.slice().sort(),'Selectable standard fields match PHP allowlist');
assert.deepEqual(Array.from(context.fieldSnapshot.defaults),defaults,'UI defaults match PHP defaults in order');
console.log('Administration field allowlist and defaults match the indexer.');
