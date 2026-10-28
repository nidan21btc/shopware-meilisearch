/* Verify cart icon alignment against the shop theme's button line-height. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const css = fs.readFileSync(path.join(__dirname, '../src/Resources/public/storefront/css/search.css'), 'utf8');
(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
    try {
        for (const width of [320, 390, 1024, 1440]) {
            const page = await browser.newPage({viewport: {width, height: 800}});
            await page.setContent('<div class="el-search"><button class="btn btn-buy el-search__cart" type="button"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M0 0h24v24H0z"/></svg></button></div>');
            await page.addStyleTag({content: '.btn {display:inline-block;line-height:38px}'});
            await page.addStyleTag({content: css});
            const geometry = await page.locator('.el-search__cart').evaluate(button => {
                const b = button.getBoundingClientRect(), s = button.querySelector('svg').getBoundingClientRect();
                return {width: b.width, height: b.height, left: s.left - b.left, right: b.right - s.right, top: s.top - b.top, bottom: b.bottom - s.bottom};
            });
            assert.equal(geometry.width, 40);
            assert.equal(geometry.height, 40);
            assert(Math.abs(geometry.left - geometry.right) <= 1, 'Icon must be horizontally centered');
            assert(Math.abs(geometry.top - geometry.bottom) <= 1, 'Icon must be vertically centered');
            assert(geometry.top >= 0 && geometry.bottom >= 0, 'Icon must fit inside button');
            await page.close();
        }
        console.log('Cart icon remains centered and within the button at four viewport widths.');
    } finally { await browser.close(); }
})().catch(error => {console.error(error.message); process.exitCode = 1;});
