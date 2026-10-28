/* Simulated local-only speech API; no real microphone or remote service. */
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const script = fs.readFileSync(path.join(root, 'src/Resources/public/storefront/js/search.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'src/Resources/public/storefront/css/search.css'), 'utf8');
const fixture = `<!doctype html><html lang="de-DE"><body><button class="search-toggle-btn">Search</button>
<dialog class="el-search" data-el-search data-endpoint="/search" data-loading="Loading" data-error="Error" data-locale="de-DE" data-voice-start="Start" data-voice-stop="Stop" data-voice-listening="Listening" data-voice-error="Local recognition unavailable">
<button data-el-close>Close</button><form data-el-query-form><input type="search" data-el-query><button type="button" data-el-clear hidden>Clear</button><button type="button" class="el-search__voice" data-el-voice hidden>Voice</button></form>
<p data-el-status></p><p data-el-voice-notice hidden></p><div data-el-results></div><div data-el-network-error hidden></div></dialog></body></html>`;

(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
    try {
        async function pageWith(mode, locale = 'de-DE') {
            const page = await browser.newPage({viewport: {width: 390, height: 844}});
            await page.addInitScript((kind) => {
                window.voiceCalls = [];
                window.voiceAborts = 0;
                window.voiceMode = 'result';
                class FakeSpeech {
                    get processLocally() { return this._local || false; }
                    set processLocally(value) { this._local = value; }
                    static available() { throw new Error('Availability must not be checked remotely'); }
                    static install() { throw new Error('Language packs must not be downloaded'); }
                    start() {
                        window.voiceCalls.push({local: this.processLocally, lang: this.lang});
                        window.lastSpeech = this;
                        if (window.voiceMode === 'result') setTimeout(() => this.onresult({resultIndex: 0, results: [Object.assign([{transcript: 'orchid'}], {isFinal: true})]}), 0);
                        if (window.voiceMode === 'error') setTimeout(() => this.onerror({error: 'language-not-supported'}), 0);
                    }
                    abort() { window.voiceAborts++; this.onend?.(); }
                    stop() {}
                }
                if (kind === 'local') window.SpeechRecognition = FakeSpeech;
                else if (kind === 'online-only') {
                    window.SpeechRecognition = class { start() { window.voiceCalls.push({local: false}); } };
                } else {
                    delete window.SpeechRecognition;
                    window.webkitSpeechRecognition = FakeSpeech;
                }
            }, mode);
            const requests = [];
            await page.route('https://speech.test/**', route => {
                const url = new URL(route.request().url());
                if (url.pathname === '/') return route.fulfill({contentType: 'text/html', body: fixture.replace('data-locale="de-DE"', `data-locale="${locale}"`)});
                requests.push(url);
                return route.fulfill({contentType: 'text/html', body: `<section data-el-result-status="ok"><p class="result">${url.searchParams.get('q') || ''}</p></section>`});
            });
            await page.goto('https://speech.test/');
            await page.addStyleTag({content: css});
            await page.addScriptTag({type: 'module', content: script});
            await page.locator('.search-toggle-btn').click();
            await page.waitForSelector('[data-el-result-status]', {state: 'attached'});
            return {page, requests};
        }

        const unsupported = await pageWith('unsupported');
        assert.equal(await unsupported.page.locator('[data-el-voice]').isVisible(), false, 'A prefixed API must never be used');
        assert.equal(await unsupported.page.evaluate(() => voiceCalls.length), 0);
        await unsupported.page.close();
        const onlineOnly = await pageWith('online-only');
        assert.equal(await onlineOnly.page.locator('[data-el-voice]').isVisible(), false, 'An online-only API must never be used');
        assert.equal(await onlineOnly.page.evaluate(() => voiceCalls.length), 0);
        await onlineOnly.page.close();

        for (const locale of ['en-GB', 'es-ES', 'fr-FR', 'it-IT']) {
            const translated = await pageWith('local', locale);
            await translated.page.locator('[data-el-voice]').click();
            await translated.page.waitForFunction(() => document.querySelector('.result')?.textContent === 'orchid');
            assert.deepEqual(await translated.page.evaluate(() => voiceCalls), [{local: true, lang: locale}]);
            await translated.page.close();
        }

        const {page, requests} = await pageWith('local');
        assert.equal(await page.locator('[data-el-voice]').isVisible(), true);
        await page.locator('[data-el-voice]').click();
        await page.waitForFunction(() => document.querySelector('.result')?.textContent === 'orchid');
        assert.deepEqual(await page.evaluate(() => voiceCalls), [{local: true, lang: 'de-DE'}]);
        assert.equal(requests.at(-1).searchParams.get('q'), 'orchid');
        assert.equal(await page.locator('[data-el-voice]').isVisible(), false, 'Voice button should yield to the clear button');

        await page.locator('[data-el-clear]').click();
        await page.waitForFunction(() => document.querySelector('.result')?.textContent === '');
        await page.evaluate(() => { voiceMode = 'error'; });
        await page.locator('[data-el-voice]').click();
        await page.waitForFunction(() => document.querySelector('[data-el-voice-notice]')?.textContent === 'Local recognition unavailable');
        assert.equal(await page.locator('[data-el-voice-notice]').textContent(), 'Local recognition unavailable');
        assert.equal(await page.evaluate(() => voiceCalls.length), 2, 'No online fallback may be started');
        assert.equal(await page.evaluate(() => voiceAborts), 1, 'Recognition must stop after an error');

        await page.evaluate(() => { voiceMode = 'waiting'; });
        await page.locator('[data-el-voice]').click();
        assert.equal(await page.locator('[data-el-voice]').getAttribute('aria-pressed'), 'true');
        await page.locator('[data-el-query]').fill('typed');
        await page.waitForFunction(() => document.querySelector('.result')?.textContent === 'typed');
        assert.equal(await page.evaluate(() => voiceAborts), 2, 'Typing must abort an active recognition');
        await page.evaluate(() => lastSpeech.onresult({resultIndex: 0, results: [Object.assign([{transcript: 'stale'}], {isFinal: true})]}));
        assert.equal(await page.locator('[data-el-query]').inputValue(), 'typed');
        await page.locator('[data-el-clear]').click();
        await page.waitForFunction(() => document.querySelector('.result')?.textContent === '');
        await page.locator('[data-el-voice]').click();
        await page.locator('[data-el-close]').click();
        await page.waitForFunction(() => !document.querySelector('[data-el-search]').open);
        assert.equal(await page.evaluate(() => voiceAborts), 3, 'Closing the overlay must abort recognition');
        await page.evaluate(() => lastSpeech.onresult({resultIndex: 0, results: [Object.assign([{transcript: 'stale'}], {isFinal: true})]}));
        assert.notEqual(await page.locator('[data-el-query]').inputValue(), 'stale');
        assert.equal(await page.evaluate(() => voiceCalls.every(call => call.local)), true);
        await page.close();

        const timed = await pageWith('local');
        await timed.page.evaluate(() => {
            const original = window.setTimeout;
            window.setTimeout = (callback, delay, ...args) => original(callback, delay === 12000 ? 20 : delay, ...args);
            voiceMode = 'waiting';
        });
        await timed.page.locator('[data-el-voice]').click();
        await timed.page.waitForFunction(() => document.querySelector('[data-el-voice-notice]')?.textContent === 'Local recognition unavailable');
        assert.equal(await timed.page.evaluate(() => voiceAborts), 1, 'A stuck recognition must be aborted');
        assert.deepEqual(await timed.page.evaluate(() => voiceCalls), [{local: true, lang: 'de-DE'}]);
        await timed.page.close();
        console.log('Voice checks passed: local-only capability, language, result, no cloud fallback and cancellation.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
