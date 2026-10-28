/* Test Shopware markup and the real client code with a simulated local speech API. */
const {chromium, devices} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const origin = process.env.EL_SEARCH_TEST_URL || '';
const host = process.env.EL_SEARCH_TEST_HOST || '';
const target = new URL(origin);
if (target.protocol !== 'http:' || target.hostname !== '127.0.0.1' || !/^[a-zA-Z0-9.-]+$/.test(host)) {
    throw new Error('Set a loopback EL_SEARCH_TEST_URL and a test virtual host');
}
(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox', '--disable-web-security']});
    try {
        const context = await browser.newContext({...devices['iPhone 13'], viewport: {width: 390, height: 844}});
        await context.addInitScript(() => {
            class LocalSpeech {
                get processLocally() { return this._local || false; }
                set processLocally(value) { this._local = value; }
                start() {
                    window.testSpeech = {local: this.processLocally, lang: this.lang};
                    setTimeout(() => this.onresult({resultIndex: 0, results: [Object.assign([{transcript: 'bach'}], {isFinal: true})]}), 0);
                }
                stop() {}
                abort() {}
            }
            window.SpeechRecognition = LocalSpeech;
        });
        const page = await context.newPage();
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.hostname === host && url.protocol === 'http:') {
                url.hostname = target.hostname;
                url.port = target.port;
                return route.continue({url: url.href});
            }
            return url.origin === target.origin ? route.continue() : route.abort();
        });
        await page.goto(origin + '/de/', {waitUntil: 'domcontentloaded', timeout: 60000});
        await page.addStyleTag({content: '.sf-toolbar{display:none!important}'});
        const cookie = page.getByRole('button', {name: /Ablehnen|Reject all/i}).first();
        if (await cookie.isVisible().catch(() => false)) await cookie.click();
        await page.locator('.search-toggle-btn').filter({visible: true}).first().click();
        await page.waitForSelector('[data-el-voice]:visible');
        await page.locator('[data-el-voice]').click();
        await page.waitForFunction(() => document.querySelector('[data-el-query]').value === 'bach' && document.querySelector('[data-el-results]').getAttribute('aria-busy') === 'false' && document.querySelector('.el-search__card'));
        assert.deepEqual(await page.evaluate(() => testSpeech), {local: true, lang: 'de-DE'});
        assert.equal(await page.locator('[data-el-voice]').isVisible(), false);
        console.log('Shopware voice wiring passed with simulated local recognition; external requests were blocked.');
        await context.close();
    } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
