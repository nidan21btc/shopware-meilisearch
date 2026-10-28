/* Synthetic loopback Basic-auth regression; never use real shop credentials here. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {chromium, firefox} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const source = fs.readFileSync(process.env.SEARCH_SCRIPT || path.join(__dirname, '../src/Resources/public/storefront/js/search.js'), 'utf8');
const user = 'synthetic-user';
const password = 'synthetic-password';
const authorization = 'Basic ' + Buffer.from(user + ':' + password).toString('base64');
const html = `<!doctype html><button class="search-toggle-btn">Search</button>
<dialog data-el-search data-locale="en-GB" data-endpoint="/test/de/shopware-meilisearch" data-loading="Loading" data-error="Unavailable">
<form data-el-query-form><input type="search" data-el-query><button data-el-clear type="button" hidden>Clear</button></form>
<button data-el-close>Close</button><p data-el-status></p><div data-el-results></div>
<div data-el-network-error hidden><button data-el-retry>Retry</button></div></dialog>
<script type="module" src="/search.js"></script>`;
(async () => {
    let acceptedSearches = 0;
    let searchChallenges = 0;
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://127.0.0.1');
        const isSearch = url.pathname === '/test/de/shopware-meilisearch';
        if (req.headers.authorization !== authorization) {
            if (isSearch) searchChallenges++;
            res.writeHead(401, {'WWW-Authenticate': 'Basic realm="Synthetic search regression"'});
            res.end();
            return;
        }
        if (isSearch) {
            acceptedSearches++;
            res.writeHead(200, {'Content-Type': 'text/html', 'Cache-Control': 'no-store'});
            res.end('<section data-el-result-status="OK">Synthetic result</section>');
        } else if (url.pathname === '/search.js') {
            res.writeHead(200, {'Content-Type': 'text/javascript'});
            res.end(source);
        } else {
            res.writeHead(200, {'Content-Type': 'text/html'});
            res.end(html);
        }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port;
    const types = {chromium, firefox};
    try {
        for (const name of (process.env.TEST_BROWSERS || 'firefox,chromium').split(',')) {
            assert(types[name], 'Unknown test browser');
            const options = {headless: true};
            if (name === 'chromium') options.args = ['--no-sandbox', '--no-proxy-server'];
            else options.firefoxUserPrefs = {'network.proxy.type': 0};
            const browser = await types[name].launch(options);
            try {
                const page = await browser.newPage();
                const requests = [];
                page.on('request', request => {
                    if (new URL(request.url()).pathname === '/test/de/shopware-meilisearch') requests.push(request);
                });
                await page.goto(`http://${user}:${password}@127.0.0.1:${port}/test/de`);
                assert(await page.evaluate(() => Boolean(new URL(document.baseURI).username)), 'Fixture must retain a credentialed document URL');
                await page.locator('.search-toggle-btn').click();
                await page.locator('[data-el-result-status="OK"]').waitFor({timeout: 7000});
                await page.locator('[data-el-query]').fill('fixture query');
                await page.waitForResponse(response => new URL(response.url()).searchParams.get('q') === 'fixture query' && response.status() === 200);
                await page.locator('[data-el-result-status="OK"]').waitFor();
                assert(await page.locator('[data-el-network-error]').isHidden(), 'Credentialed navigation must not leave the unavailable view');
                assert(requests.length >= 2, 'Initial and typed search must reach the server');
                for (const request of requests) {
                    const target = new URL(request.url());
                    assert.equal(target.username, '', 'Fetch URL must not contain a username');
                    assert.equal(target.password, '', 'Fetch URL must not contain a password');
                    assert.equal(target.origin, `http://127.0.0.1:${port}`, 'Search must remain same-origin');
                }
                console.log(name + ': authenticated initial and typed searches work from a credentialed page URL.');
            } finally {
                await browser.close();
            }
        }
        assert(acceptedSearches >= 2, 'Authenticated search requests must succeed');
        console.log('Protected search accepted; no credential-bearing fetch URL. Search challenges: ' + searchChallenges);
    } finally {
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
