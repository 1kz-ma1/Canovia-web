// Synthetic data only. Shares the closed, loopback-only fixture and production CSP.
// This is browser UI evidence, not iPhone/WKWebView or cold-offline acceptance.
import { chromium, webkit } from "playwright";
import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import net from "node:net";
import { readFile } from "node:fs/promises";

const directory = process.env.PREPARATION_DIRECTORY;
assert.equal(
    JSON.parse(await readFile(directory + "/manifest.json", "utf8")).profile,
    "B",
);
const probe = net.createServer();
await new Promise((r) => probe.listen(0, "127.0.0.1", r));
const port = probe.address().port;
await new Promise((r) => probe.close(r));
const origin = `http://127.0.0.1:${port}`;
const server = spawn(
    "python3",
    [
        "-B",
        "scripts/ci/preparation_static_serve.py",
        "--directory",
        directory,
        "--port",
        String(port),
    ],
    { stdio: ["ignore", "pipe", "pipe"] },
);
const output = [];
for (const stream of [server.stdout, server.stderr])
    stream.on("data", (b) => output.push(b));
let browser;
try {
    let ready = false;
    for (let i = 0; i < 80; i++) {
        assert.equal(server.exitCode, null);
        try {
            if ((await fetch(origin)).ok) {
                ready = true;
                break;
            }
        } catch {}
        await new Promise((r) => setTimeout(r, 100));
    }
    assert.ok(ready);
    const engine = process.env.BROWSER_ENGINE === "webkit" ? webkit : chromium;
    browser = await engine.launch({
        headless: true,
        ...(process.env.CANOVIA_BROWSER_EXECUTABLE_PATH
            ? { executablePath: process.env.CANOVIA_BROWSER_EXECUTABLE_PATH }
            : {}),
    });
    const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
    });
    const errors = [],
        external = [];
    context.on("page", (p) => p.on("pageerror", (e) => errors.push(e.message)));
    await context.route("**/*", (r) => {
        if (new URL(r.request().url()).origin === origin) return r.continue();
        external.push(r.request().url());
        return r.abort();
    });
    const page = await context.newPage(),
        read = () =>
            page.evaluate(async () => {
                const { repo } = await import("/src/app.mjs");
                return repo.read();
            });
    const nav = async (route) => {
        await page.locator(`[data-nav="${route}"]`).click();
        await page
            .locator(`[data-nav="${route}"][aria-current=page]`)
            .waitFor();
    };
    const saved = async () => {
        await page
            .getByText("この端末に保存しました。", { exact: true })
            .waitFor();
        await page.locator("#app[aria-busy=false]").waitFor();
    };
    await page.goto(origin);
    await page.locator("#app h1").waitFor();
    assert.equal(
        await page.locator("#app h1").getAttribute("aria-label"),
        "自分の関心から、次の準備へ。",
    );
    await nav("diagnosis");
    await page.locator("input[name=Q01][value=unknown]").focus();
    await page.keyboard.press("Space");
    await page.locator("#next").click();
    assert.equal(
        (await read()).diagnosis,
        null,
        "No consent means no write or page advance",
    );
    assert.ok(await page.locator('[data-page="1"]').isVisible());
    await page.locator("input[name=consent]").check();
    await page.locator('input[name=Q02][value="0"]').check();
    await page.locator("#next").click();
    await saved();
    let state = await read();
    assert.deepEqual(state.diagnosis.answers, { Q01: "unknown", Q02: 0 });
    assert.equal(state.diagnosis.step, 2);
    assert.equal(
        await page.evaluate(
            () => document.activeElement.closest("[data-page]")?.dataset.page,
        ),
        "2",
        "New question receives keyboard focus",
    );
    await page.reload();
    await page.locator('[data-page="2"]').waitFor({ state: "visible" });
    await page.locator('input[name=Q06][value="3"]').check();
    await page.locator("#previous").click();
    await saved();
    assert.ok(await page.locator("input[name=Q01][value=unknown]").isChecked());
    await page.locator("[data-unanswer=Q01]").click();
    await page.locator('[data-step="3"]').click();
    await saved();
    state = await read();
    assert.deepEqual(state.diagnosis.answers, { Q02: 0, Q06: 3 });
    assert.equal(state.diagnosis.step, 3);
    // Circle order is deliberately independent from numerical/scoring order.
    await page.locator('[data-step="1"]').click();
    await saved();
    const options = [
        ["3", "あてはまる"],
        ["2", "ややあてはまる"],
        ["unknown", "わからない"],
        ["1", "あまりあてはまらない"],
        ["0", "あてはまらない"],
    ];
    for (const [value, label] of options) {
        const question = page.locator(".question-card").first();
        const radio = question.getByRole("radio", { name: label, exact: true });
        assert.equal(await radio.getAttribute("value"), value);
        await radio.locator("..").click();
        assert.equal(await question.locator("input:checked").count(), 1);
        assert.ok(await radio.isChecked());
        await question
            .locator(".scale-circle")
            .evaluateAll((els) =>
                Promise.all(
                    els.flatMap((el) =>
                        el.getAnimations().map((a) => a.finished),
                    ),
                ),
            );
        const fills = await question.locator("input").evaluateAll((inputs) =>
            inputs.map((input) => {
                const style = getComputedStyle(input.nextElementSibling);
                return {
                    checked: input.checked,
                    bg: style.backgroundColor,
                    border: style.borderTopColor,
                    animation: style.animationName,
                };
            }),
        );
        assert.ok(
            fills.every((v) =>
                v.checked
                    ? v.bg === v.border && v.animation === "scale-select"
                    : v.bg === "rgba(0, 0, 0, 0)",
            ),
        );

        assert.equal(
            await question
                .locator("input:checked + .scale-circle .icon")
                .evaluate((el) => getComputedStyle(el).opacity),
            "1",
        );
        assert.equal(
            await question
                .locator("input:not(:checked) + .scale-circle .icon")
                .evaluateAll((els) =>
                    els.every((el) => getComputedStyle(el).opacity === "0"),
                ),
            true,
        );
        await page.locator("button[value=save]").click();
        await saved();
        assert.equal(
            (await read()).diagnosis.answers.Q01,
            value === "unknown" ? value : Number(value),
        );
        await page.reload();
        await page.locator("#diagnosis-form").waitFor();
        assert.ok(
            await page.locator(`input[name=Q01][value="${value}"]`).isChecked(),
        );
    }
    await page.locator("input[name=Q01][value=unknown]").check();
    await page.locator("button[value=save]").click();
    await saved();
    assert.equal((await read()).diagnosis.answers.Q01, "unknown");
    await page.locator("[data-unanswer=Q01]").click();
    await page.locator("button[value=save]").click();
    await saved();
    assert.equal(Object.hasOwn((await read()).diagnosis.answers, "Q01"), false);
    await page.locator('input[name=Q01][value="3"]').focus();
    await page.keyboard.press("Space");
    await page.keyboard.press("ArrowRight");
    assert.ok(await page.locator('input[name=Q01][value="2"]').isChecked());
    await page.keyboard.press("ArrowRight");
    assert.ok(await page.locator("input[name=Q01][value=unknown]").isChecked());
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.locator('input[name=Q01][value="3"]').check();
    assert.equal(
        await page
            .locator("input[name=Q01]:checked + .scale-circle")
            .evaluate((el) => getComputedStyle(el).animationName),
        "none",
    );
    await page.emulateMedia({ reducedMotion: "no-preference" });
    await page.locator("#next").click();
    await saved();
    await page.locator("#previous").click();
    await saved();
    assert.ok(await page.locator('input[name=Q01][value="3"]').isChecked());
    await nav("home");
    assert.ok(
        await page
            .getByText("途中から、続けてみよう。", { exact: true })
            .isVisible(),
    );

    await nav("plans");
    assert.ok(
        await page
            .getByText("最初の一歩を、ここから。", { exact: true })
            .isVisible(),
    );
    await page
        .getByLabel("目標", { exact: true })
        .fill("Synthetic mobile Plan");
    assert.ok(
        await page
            .getByText("未保存の変更があります", { exact: true })
            .isVisible(),
    );
    page.once("dialog", (d) => d.dismiss());
    await page.locator("#new-plan").click();
    assert.equal(
        await page.getByLabel("目標", { exact: true }).inputValue(),
        "Synthetic mobile Plan",
    );
    assert.equal((await read()).plans.length, 0);
    await page
        .getByLabel("現在地・計画のメモ", { exact: true })
        .fill("Synthetic first step");
    await page.locator("#plan-form button[type=submit]").click();
    await saved();
    const planId = (await read()).plans[0].id;
    await page.getByLabel("目標", { exact: true }).fill("Unsaved switch input");
    page.once("dialog", (d) => d.dismiss());
    await page.locator("[data-edit-plan]").click();
    assert.equal(
        await page.getByLabel("目標", { exact: true }).inputValue(),
        "Unsaved switch input",
    );
    page.once("dialog", (d) => d.accept());
    await page.locator("#new-plan").click();
    assert.equal(
        await page.getByLabel("目標", { exact: true }).inputValue(),
        "",
    );
    assert.equal((await read()).plans.length, 1);
    await page.locator("[data-edit-plan]").click();
    assert.equal(
        await page.evaluate(() => document.activeElement.textContent),
        "Planを編集する",
    );

    // Hold the real transaction before commit: navigation must not discard inputs or claim success.
    await page.evaluate(async () => {
        const { repo } = await import("/src/app.mjs");
        const change = repo.change.bind(repo);
        repo.change = async (...args) => {
            await new Promise((r) => (window.finishSyntheticSave = r));
            return change(...args);
        };
    });
    await page
        .getByLabel("目標", { exact: true })
        .fill("Synthetic mobile Plan edited");
    await page.locator("#plan-form button[type=submit]").click();
    await page.locator("[data-nav=home]").click();
    await page
        .getByText("保存処理中です。完了するまでお待ちください。", {
            exact: true,
        })
        .waitFor();
    assert.equal(new URL(page.url()).hash, "#plans");
    assert.equal((await read()).plans[0].title, "Synthetic mobile Plan");
    assert.equal(await page.locator("#app").getAttribute("aria-busy"), "true");
    await page.evaluate(() => window.finishSyntheticSave());
    await saved();
    assert.equal((await read()).plans[0].id, planId);
    await page.reload();
    await page.locator("#plan-form").waitFor();
    assert.equal(
        await page.getByLabel("目標", { exact: true }).inputValue(),
        "Synthetic mobile Plan edited",
    );
    await nav("home");
    assert.ok(
        await page
            .getByText("あなたの一歩が、ここに。", { exact: true })
            .isVisible(),
    );

    // Load all routes before disconnecting; only this already-open application's interactions are supported here.
    for (const route of ["diagnosis", "future", "plans", "data", "home"])
        await nav(route);
    await context.setOffline(true);
    await nav("plans");
    await page
        .getByLabel("目標", { exact: true })
        .fill("Synthetic offline edit");
    await page.locator("#plan-form button[type=submit]").click();
    await saved();
    assert.equal((await read()).plans[0].title, "Synthetic offline edit");
    await nav("data");
    const download = page.waitForEvent("download");
    await page.locator("#export").click();
    assert.ok((await readFile(await (await download).path())).length > 0);
    await context.setOffline(false);

    const audited = [];
    for (const width of [320, 375, 390, 430, 1280]) {
        await page.setViewportSize({ width, height: 844 });
        for (const route of ["home", "diagnosis", "plans", "future", "data"]) {
            await nav(route);
            assert.ok(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
                `${route} fits ${width}px`,
            );
            assert.equal(
                await page
                    .locator(`[data-nav="${route}"]`)
                    .getAttribute("aria-current"),
                "page",
            );
            if (route === "diagnosis") {
                const rows = await page
                    .locator("[data-page]:not([hidden]) .response-scale")
                    .evaluateAll((els) =>
                        els.map((row) =>
                            [...row.querySelectorAll("input")].map((input) => {
                                const r = input.getBoundingClientRect(),
                                    circle =
                                        input.nextElementSibling.getBoundingClientRect(),
                                    style = getComputedStyle(input);
                                return {
                                    value: input.value,
                                    x: r.x,
                                    y: r.y,
                                    width: r.width,
                                    height: r.height,
                                    circle: circle.width,
                                    display: style.display,
                                    visibility: style.visibility,
                                };
                            }),
                        ),
                    );
                assert.equal(rows.length, 5);
                for (const row of rows) {
                    assert.deepEqual(
                        row.map((v) => v.value),
                        ["3", "2", "unknown", "1", "0"],
                    );
                    assert.deepEqual(
                        row.map((v) => v.circle),
                        [44, 32, 22, 32, 44],
                    );
                    assert.ok(
                        row.every(
                            (v) =>
                                v.width >= 44 &&
                                v.height >= 44 &&
                                v.display !== "none" &&
                                v.visibility !== "hidden",
                        ),
                    );
                    assert.ok(row.every((v) => Math.abs(v.y - row[0].y) < 1));
                    assert.ok(
                        row
                            .slice(1)
                            .every((v, i) => v.x >= row[i].x + row[i].width),
                    );
                }
                if (width === 390) {
                    const row = rows[0];
                    const navTop = await page
                        .locator(".app-nav")
                        .evaluate((el) => el.getBoundingClientRect().top);
                    assert.ok(
                        row[0].y + row[0].height < navTop,
                        "First answer row is above bottom navigation at 390x844",
                    );
                }
            }
            if (process.env.AXE_SOURCE && width === 390) {
                await page.evaluate(
                    await readFile(process.env.AXE_SOURCE, "utf8"),
                );
                const result = await page.evaluate(async () => {
                    const r = await axe.run(document, {
                        runOnly: {
                            type: "tag",
                            values: ["wcag2a", "wcag2aa", "wcag21aa"],
                        },
                    });
                    return r.violations.map((v) => ({
                        id: v.id,
                        impact: v.impact,
                        nodes: v.nodes.map((n) => n.target),
                    }));
                });
                assert.deepEqual(
                    result,
                    [],
                    `Automated axe violations on ${route}: ${JSON.stringify(result)}`,
                );
                audited.push(route);
            }
        }
    }
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    assert.equal(Buffer.concat(output).toString(), "");
    console.log(
        JSON.stringify({
            engine: process.env.BROWSER_ENGINE || "chromium",
            consent_and_page_restoration: "PASS",
            unknown_zero_unanswered: "PASS",
            circle_order_values_44px_and_single_row: "PASS",
            all_five_values_saved_and_reloaded: "PASS",
            radio_arrows_and_reduced_motion: "PASS",
            dirty_switch_and_commit_guard: "PASS",
            open_page_offline_save_export: "PASS",
            widths: [320, 375, 390, 430, 1280],
            axe_routes: audited,
            cold_offline_boot: "NOT_TESTED_NOT_GUARANTEED",
            iphone_wkwebview: "NOT_TESTED",
            release_approved: false,
        }),
    );
} finally {
    if (browser) await browser.close();
    server.kill("SIGTERM");
    await new Promise((r) =>
        server.exitCode !== null || server.signalCode !== null
            ? r()
            : server.once("exit", r),
    );
}
