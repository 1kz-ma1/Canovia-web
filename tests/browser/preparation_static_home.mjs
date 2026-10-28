// Actual UI interactions and synthetic records in fresh browser contexts only.
import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { join } from "node:path";

export async function homeStates({ browser, origin, stored }) {
    const errors = [],
        external = [];
    const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
        locale: "ja-JP",
    });
    context.on("page", (p) => p.on("pageerror", (e) => errors.push(e.message)));
    await context.route("**/*", (r) => {
        if (new URL(r.request().url()).origin === origin) return r.continue();
        external.push(r.request().url());
        return r.abort();
    });
    const page = await context.newPage();
    const read = () =>
        page.evaluate(async () => {
            const { repo } = await import("/src/app.mjs");
            return repo.read();
        });
    const home = async (state) => {
        await page.locator("[data-nav=home]").click();
        await page.locator(`[data-home-state="${state}"]`).waitFor();
    };
    const saved = async () => {
        await page
            .getByText(
                stored
                    ? "この端末に保存しました。"
                    : "この画面に反映しました。再読み込みすると消えます。",
                { exact: true },
            )
            .waitFor();
        await page.locator("#app[aria-busy=false]").waitFor();
    };
    const inspect = async (state) => {
        const before = await read();
        for (const width of [320, 390]) {
            await page.setViewportSize({ width, height: 844 });
            await page.evaluate(() => scrollTo(0, 0));
            assert.ok(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
                `${state} fits ${width}`,
            );
            assert.equal(
                await page.locator(".home-rails").getAttribute("aria-hidden"),
                "true",
            );
            assert.equal(
                await page
                    .locator(".home-hero")
                    .evaluate(
                        (el) => el.getAnimations({ subtree: true }).length,
                    ),
                0,
                "Rail motif stays static",
            );
            const entries = page.locator(
                state === "first"
                    ? "[data-home-choice]"
                    : "[data-home-primary], [data-home-secondary]",
            );
            assert.equal(await entries.count(), 2);
            for (const entry of await entries.all()) {
                const box = await entry.boundingBox();
                assert.ok(box.width >= 44 && box.height >= 44);
                const navTop = await page
                    .locator(".app-nav")
                    .evaluate((el) => el.getBoundingClientRect().top);
                assert.ok(
                    box.y + box.height <= navTop,
                    `${state} primary choices are visible above nav at ${width}`,
                );
            }
            assert.equal(
                await page.locator("[data-home-first]").count(),
                state === "first" ? 1 : 0,
                "Returning users do not receive the first-use guide",
            );
            assert.ok(
                await page
                    .getByText(
                        "AI支援・自動記録・外部サービス連携は、この先行版では提供していません。",
                        { exact: true },
                    )
                    .isVisible(),
            );
            if (process.env.PREPARATION_HOME_SCREENSHOTS) {
                const dir = join(
                    process.env.PREPARATION_HOME_SCREENSHOTS,
                    stored ? "B" : "C",
                );
                await mkdir(dir, { recursive: true });
                await page.screenshot({
                    path: join(dir, `home-${state}-${width}.png`),
                });
            }
        }
        assert.deepEqual(
            await read(),
            before,
            "Rendering home never writes state/revision/timestamps",
        );
        if (process.env.AXE_SOURCE) {
            await page.evaluate(await readFile(process.env.AXE_SOURCE, "utf8"));
            const violations = await page.evaluate(async () => {
                const r = await axe.run(document, {
                    runOnly: {
                        type: "tag",
                        values: ["wcag2a", "wcag2aa", "wcag21aa"],
                    },
                });
                return r.violations.map((v) => ({
                    id: v.id,
                    targets: v.nodes.map((n) => n.target),
                }));
            });
            assert.deepEqual(
                violations,
                [],
                `${state} automated accessibility`,
            );
        }
    };
    try {
        await page.goto(origin);
        await page.locator("[data-home-state=first]").waitFor();
        assert.equal(
            await page.locator("#app h1").getAttribute("aria-label"),
            "すべての一歩が、未来につながる。",
        );
        await inspect("first");
        // Plan is a first-class entry and does not create or require a diagnosis.
        await page.locator("[data-home-choice=plans]").click();
        await page.locator("#plan-form").waitFor();
        assert.equal((await read()).diagnosis, null);
        await home("first");
        await page.locator("[data-home-choice=diagnosis]").click();
        await page.locator("#diagnosis-form").waitFor();
        await page.locator("[name=consent]").check();
        await page.locator('[name=Q01][value="2"]').check();
        await page.locator("[name=Q02][value=unknown]").check();
        await page.locator("#next").click();
        await saved();
        await home("partial");
        await inspect("partial");
        assert.equal(
            await page.locator("[data-home-primary]").getAttribute("href"),
            "#diagnosis",
        );
        await page.locator("[data-home-primary]").click();
        await page.locator('[data-page="2"]').waitFor({ state: "visible" });
        assert.equal((await read()).diagnosis.step, 2);
        assert.ok(await page.locator("[name=Q02][value=unknown]").isChecked());
        await page.locator('[data-step="1"]').click();
        await saved();
        for (let step = 1; step <= 6; step++) {
            for (const input of await page
                .locator(`[data-page="${step}"] input[value="3"]`)
                .all())
                await input.check();
            if (step < 6) {
                await page.locator("#next").click();
                await saved();
            }
        }
        await page.locator("button[value=result]").click();
        await page.locator("#result-counts").waitFor();
        await home("answered");
        await inspect("answered");
        assert.equal(
            await page.locator("[data-home-primary]").getAttribute("href"),
            "#result",
        );
        await page.locator("[data-home-primary]").click();
        await page.locator("#result-counts").waitFor();
        await home("answered");
        await page.locator("[data-home-secondary]").click();
        await page.locator("#plan-form").waitFor();
        await page
            .getByLabel("目標", { exact: true })
            .fill("Synthetic home resume Plan");
        await page
            .getByLabel("現在地・計画のメモ", { exact: true })
            .fill("Write three sentences twice a week.");
        await page.locator("#plan-form button[type=submit]").click();
        await saved();
        const plan = (await read()).plans[0];
        await home("plans");
        await inspect("plans");
        assert.equal(
            await page
                .locator("[data-home-primary]")
                .getAttribute("data-open-plan"),
            plan.id,
        );
        await page.locator("[data-home-primary]").click();
        await page.locator("#plan-form").waitFor();
        assert.equal(
            await page.getByLabel("目標", { exact: true }).inputValue(),
            plan.title,
        );
        await home("plans");
        await page.reload();
        await page
            .locator(`[data-home-state="${stored ? "plans" : "first"}"]`)
            .waitFor();
        assert.equal((await read()).plans.length, stored ? 1 : 0);
        await page.keyboard.press("Tab");
        assert.ok(
            await page.evaluate(() => document.activeElement !== document.body),
        );
        assert.deepEqual(errors, []);
        assert.deepEqual(external, []);
        console.log(
            JSON.stringify({
                home_states: ["first", "partial", "answered", "plans"],
                profile: stored ? "B" : "C",
                home_render_read_only: true,
                optional_diagnosis: true,
                resume_step_and_plan: true,
                first_choices_above_nav: [320, 390],
                home_axe: process.env.AXE_SOURCE ? "PASS_SCOPED" : "NOT_RUN",
                op_playback: "NOT_IMPLEMENTED",
                device_verified: false,
            }),
        );
    } finally {
        await context.close();
    }
}
