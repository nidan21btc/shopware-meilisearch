/* Read-only card layout checks against a loopback Shopware test storefront. */
const assert = require('assert');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const origin = new URL(process.env.EL_SEARCH_TEST_URL || '');
const host = process.env.EL_SEARCH_TEST_HOST || '';
if (origin.protocol !== 'http:' || origin.hostname !== '127.0.0.1' || !/^[A-Za-z0-9.-]+$/.test(host)) throw Error('Loopback test storefront required.');
(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox', '--disable-web-security']});
    try {
        for (const width of [320, 390]) for (const locale of ['de', 'en', 'es', 'fr', 'it']) {
            const page = await browser.newPage({viewport: {width, height: 844}});
            await page.route('**/*', route => {
                const url = new URL(route.request().url());
                if (url.hostname === host && url.protocol === 'http:') {
                    url.hostname = origin.hostname; url.port = origin.port;
                    return route.continue({url: url.href});
                }
                return url.origin === origin.origin ? route.continue() : route.abort();
            });
            await page.goto(`${origin.origin}/${locale}/`, {waitUntil: 'domcontentloaded', timeout: 60000});
            await page.addStyleTag({content: '.sf-toolbar{display:none!important}'});
            const reject = page.getByRole('button', {name: /Ablehnen|Reject all|Rechazar|Refuser|Rifiuta/i}).first();
            if (await reject.isVisible().catch(() => false)) await reject.click();
            await page.locator('.search-toggle-btn').filter({visible: true}).first().click();
            await page.locator('[data-el-query]').fill('bach');
            await page.locator('[data-el-show-all]').waitFor({state: 'visible', timeout: 30000});
            const checks = await page.locator('.el-search__card').evaluateAll(cards => cards.slice(0, 10).map(card => {
                const image = card.querySelector('.el-search__image').getBoundingClientRect();
                const title = card.querySelector('.el-search__name'); const name = title.getBoundingClientRect();
                const bottom = card.querySelector('.el-search__card-bottom').getBoundingClientRect();
                const quantity = card.querySelector('.el-search__variant-quantity')?.getBoundingClientRect();
                const options = card.querySelector('.el-search__variant-options')?.getBoundingClientRect();
                const action = card.querySelector('.product-action').getBoundingClientRect(); const box = card.getBoundingClientRect();
                return {imageSquare: Math.abs(image.width - image.height) <= 1, imageBeforeName: image.bottom <= name.top + 1, nameBeforeBottom: name.bottom <= bottom.top + 1,
                    quantityFits: !quantity || (name.bottom <= quantity.top + 1 && quantity.bottom <= bottom.top + 1),
                    optionsFit: !options || ((quantity?.bottom ?? name.bottom) <= options.top + 1 && options.bottom <= bottom.top + 1),
                    textFits: title.scrollHeight <= title.clientHeight + 1, cardFits: card.scrollWidth <= card.clientWidth + 1,
                    actionFits: action.right <= box.right + 1 && action.left >= box.left - 1};
            }));
            assert(checks.length > 0, 'Expected visible result cards');
            assert(checks.every(item => Object.values(item).every(Boolean)), `Card layout failed for ${locale} at ${width}px`);
            await page.close();
        }
        console.log('Mobile result cards fit without overlap at 320px and 390px in five languages.');
    } finally { await browser.close(); }
})().catch(error => {console.error(error.message); process.exitCode = 1;});
