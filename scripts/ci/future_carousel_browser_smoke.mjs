import assert from 'node:assert/strict';
import {chromium, webkit} from 'playwright';
import {execFileSync} from 'node:child_process';

const base = process.env.CANOVIA_FUTURE_BASE_URL;
if (base !== 'http://127.0.0.1:8797' || process.env.CANOVIA_FUTURE_FIXTURE !== '1'
    || process.env.CI !== 'true' || process.env.APP_ENV !== 'testing'
    || process.env.DB_CONNECTION !== 'sqlite' || process.env.NATIVE_AI_DRIVER !== 'disabled') {
    throw Error('DISPOSABLE FUTURE FIXTURE REQUIRED');
}
const engine = process.env.CANOVIA_FUTURE_ENGINE || 'chromium';
assert.ok(['chromium', 'webkit'].includes(engine));
const browser = await ({chromium,webkit})[engine].launch({headless:true,
    ...(engine === 'chromium' ? {args:['--no-sandbox'],executablePath:process.env.CANOVIA_BROWSER_EXECUTABLE_PATH || undefined} : {})});
const context = await browser.newContext({locale:'ja-JP',viewport:{width:390,height:844},hasTouch:true,reducedMotion:'reduce',serviceWorkers:'block'});
await context.route(/^https?:\/\//, route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
const page = await context.newPage();
page.setDefaultTimeout(20000);
const errors = [];
page.on('pageerror', error => errors.push(error.message));
const log = message => process.stdout.write('FUTURE_PASS '+message+'\n');
// WebKit can finish click dispatch before the POST redirect replaces the DOM.
// Wait for the actual server response and toggled form, then verify real counts.
const vote = async (targetPage, card, before, after, count) => {
    const action = await card.locator('form').getAttribute('action');
    const [response] = await Promise.all([
        targetPage.waitForResponse(r => r.url() === action && r.request().method() === 'POST'),
        card.getByRole('button', {name:before, exact:false}).click(),
    ]);
    assert.equal(response.status(),302);
    await card.getByRole('button',{name:after,exact:false}).waitFor();
    assert.equal(await card.locator('[data-support-count]').innerText(),count);
};

try {
    assert.equal((await page.goto(base+'/feedback')).status(),200);
    const publicCards = page.locator('[aria-label="開発ロードマップ"][data-future-carousel]');
    const viewport = publicCards.locator('[data-carousel-viewport]');
    await publicCards.locator('[data-carousel-controls]:not([hidden])').waitFor();
    assert.equal(await publicCards.locator('form').count(),0);
    assert.ok((await publicCards.locator('li').count()) >= 3);
    await publicCards.getByRole('button',{name:'開発ロードマップの2枚目へ',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-public-roadmap-key="first-use-choices"]')?.dataset.active==='true');
    const geometry = await viewport.evaluate(el => {
        const box=el.getBoundingClientRect(), cards=[...el.querySelectorAll('li')].map(c=>c.getBoundingClientRect());
        return {center:Math.abs((cards[1].left+cards[1].right)/2-(box.left+box.right)/2),left:cards[0].right>box.left,right:cards[2].left<box.right};
    });
    assert.ok(geometry.center < 3 && geometry.left && geometry.right,JSON.stringify(geometry));
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    await viewport.focus(); await page.keyboard.press('End');
    await page.waitForFunction(()=>document.querySelector('[data-public-roadmap-key="external-activity-sync"]')?.dataset.active==='true');
    assert.equal(await publicCards.getByRole('button',{name:'開発ロードマップの次のカード',exact:true}).isDisabled(),true);
    await page.keyboard.press('Home');
    await page.waitForFunction(()=>document.querySelector('[data-public-roadmap-key="progress-and-records"]')?.dataset.active==='true');
    assert.equal(await viewport.evaluate(el=>getComputedStyle(el).outlineStyle),'solid');
    assert.equal(await publicCards.locator('li').first().evaluate(el=>getComputedStyle(el).transitionDuration),'0s');
    // Real touch drag on Chromium; WebKit checks its native overflow/snap below.
    if (engine === 'chromium') {
        const cdp = await context.newCDPSession(page);
        const box=await viewport.boundingBox(); const y=box.y+100;
        await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:box.x+box.width*.85,y}]});
        for (let i=1;i<=8;i++) {
            await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:box.x+box.width*(.85-.07*i),y}]});
            await page.waitForTimeout(20);
        }
        await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
        await page.waitForFunction(()=>document.querySelector('#public-future-viewport').scrollLeft>50);
        await cdp.detach(); log('mobile-native-touch-swipe');
    } else {
        await viewport.evaluate(el=>el.scrollBy({left:300,behavior:'instant'}));
        await page.waitForFunction(()=>document.querySelector('#public-future-viewport').scrollLeft>50);
        log('mobile-webkit-native-overflow-and-snap');
    }
    log('peek-center-keyboard-reduced-motion-no-page-overflow');
    const support = page.locator('[data-support-feature="social.goal_peers"]');
    await vote(page,support,'この機能をSupport','Support済み・取り消す','1 Support');
    assert.equal(await support.locator('[data-support-count]').innerText(),'1 Support');
    const action=await support.locator('form').getAttribute('action');
    const token=await support.locator('input[name="_token"]').inputValue();
    // Same actor replay cannot manufacture a second DB vote.
    await page.request.post(action,{form:{_token:token}});
    await page.reload();
    assert.equal(await support.locator('[data-support-count]').innerText(),'1 Support');
    await vote(page,support,'Support済み・取り消す','この機能をSupport','0 Support');
    assert.equal(await support.locator('[data-support-count]').innerText(),'0 Support');
    log('guest-real-support-replay-and-cancellation');
    await page.goto(base+'/register');
    const register=page.locator('form[action$="/register"]');
    await register.locator('[name="name"]').fill('Future Synthetic');
    await register.locator('[name="email"]').fill('future-carousel@example.invalid');
    await register.locator('[name="password"]').fill('Future-Synthetic-only!password');
    await register.locator('[name="password_confirmation"]').fill('Future-Synthetic-only!password');
    await register.getByRole('button',{name:'登録してそのまま続ける'}).click();
    await page.waitForURL(url=>url.pathname!=='/register');
    await page.goto(base+'/feedback');
    await vote(page,support,'この機能をSupport','Support済み・取り消す','1 Support');
    assert.equal(await support.locator('[data-support-count]').innerText(),'1 Support');
    await vote(page,support,'Support済み・取り消す','この機能をSupport','0 Support');
    assert.equal(await support.locator('[data-support-count]').innerText(),'0 Support');
    log('registered-real-support-and-cancellation');
    await page.setViewportSize({width:1280,height:900});
    await publicCards.getByRole('button',{name:'開発ロードマップの2枚目へ',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-public-roadmap-key="first-use-choices"]')?.dataset.active==='true');
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    await publicCards.getByRole('button',{name:'開発ロードマップの次のカード',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-public-roadmap-key="external-activity-sync"]')?.dataset.active==='true');
    await publicCards.getByRole('button',{name:'開発ロードマップの前のカード',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-public-roadmap-key="first-use-choices"]')?.dataset.active==='true');
    // Trigger the failure path through a local HTTP error, rather than swapping UI text.
    await page.route('**/images/future/shared-context.webp',route=>route.fulfill({status:404,body:''}));
    await page.reload();
    const image=page.locator('[data-future-art]').first();
    await image.scrollIntoViewIfNeeded();
    await page.waitForFunction(()=>document.querySelector('[data-future-art]')?.alt.includes('表示できません'));
    assert.ok((await page.locator('[data-public-roadmap-key="progress-and-records"]').innerText()).includes('活動の記録'));
    await page.unroute('**/images/future/shared-context.webp');
    await page.reload();
    await page.evaluate(()=>window.scrollTo(0,0));
    if (process.env.CANOVIA_FUTURE_SCREENSHOT) await page.screenshot({path:process.env.CANOVIA_FUTURE_SCREENSHOT,fullPage:true});
    const nojs=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:844},serviceWorkers:'block'});
    await nojs.route(/^https?:\/\//, route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
    const plain=await nojs.newPage(); await plain.goto(base+'/feedback');
    assert.equal(await plain.locator('[data-carousel-controls]:visible').count(),0);
    assert.equal(await plain.locator('[data-support-feature="social.goal_peers"] button[type="submit"]').count(),1);
    assert.equal(await plain.locator('#public-future-viewport').evaluate(el=>getComputedStyle(el).overflowX),'auto');
    const plainCard=plain.locator('[data-support-feature="social.goal_peers"]');
    await vote(plain,plainCard,'この機能をSupport','Support済み・取り消す','1 Support');
    assert.equal(await plainCard.locator('[data-support-count]').innerText(),'1 Support');
    await vote(plain,plainCard,'Support済み・取り消す','この機能をSupport','0 Support');
    assert.equal(await plainCard.locator('[data-support-count]').innerText(),'0 Support');
    await nojs.close(); log('desktop-image-fallback-and-no-js-native-scroll-and-form');
    const fixture=JSON.parse(execFileSync('php',['scripts/ci/future_carousel_synthetic_fixture.php','--empty'],{env:process.env,encoding:'utf8'}));
    assert.equal(fixture.vote_count,0);
    await page.reload();
    await page.getByText('現在Supportを受け付けている候補はありません。',{exact:true}).waitFor();
    assert.equal(await page.locator('[data-future-carousel]').count(),1);
    assert.deepEqual(errors,[]);
    log('empty-state-and-no-client-errors');
} finally { await browser.close(); }
