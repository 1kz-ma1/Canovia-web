import { bank, future } from "./data.mjs";
import { score } from "./scoring.mjs";
import { validate, saveDiagnosis } from "./model.mjs";
import { Repository } from "./repository.mjs";
import { PROFILE, ADOPTED_ORIGIN } from "./settings.mjs";
import { planView, bindPlans } from "./plan-ui.mjs";
import * as backupUI from "./backup-ui.mjs";

const root = document.querySelector("#app"),
    message = document.querySelector("#message");
export const h = (s) =>
    String(s).replace(
        /[&<>"']/g,
        (c) =>
            ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;",
            })[c],
    );
export let repo, state;
let busy = false,
    dirty = false,
    currentView = "home",
    editing;
const stored = PROFILE === "B";
const errors = {
    CONFLICT:
        "別のタブで変更されました。入力を控えてから再読み込みしてください。",
    STORAGE_FULL:
        "保存領域が足りません。入力を控えてください。データは削除していません。",
    UNSUPPORTED_SCHEMA:
        "保存済み記録の版を確認できません。推定や削除は行いません。",
};
const paths = {
    spark: "m12 3 2.4 6.6L21 12l-6.6 2.4L12 21l-2.4-6.6L3 12l6.6-2.4Z",
    arrow: "M5 12h14m-6-6 6 6-6 6",
    plan: "M8 4h12v16H4V8m0-4 4 4M8 12h8m-8 4h5",
    home: "m3 10 9-7 9 7v10h-6v-6H9v6H3Z",
    shield: "m12 3 8 3v6c0 4-5 7-8 9-3-2-8-5-8-9V6Zm-4 9 3 3 5-6",
    future: "M4 18a8 8 0 1 1 16 0M2 21h20M12 2v3M3 7l2 2m16-2-2 2M8 17l4-5 4 5",
    check: "m5 12 4 4L19 6",
    book: "M3 4h7l2 2 2-2h7v15h-7l-2 2-2-2H3Zm9 2v15",
    plus: "M12 4v16M4 12h16",
    backup: "M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6",
    clock: "M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0",
};
export const icon = (name, extra = "") =>
    `<svg class="icon ${extra}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${paths[name] || paths.spark}"></path></svg>`;
const count = () => Object.keys(state.diagnosis?.answers || {}).length;
const newest = () =>
    [...state.plans].sort((a, b) => b.updated_at.localeCompare(a.updated_at));
const dateLabel = (value) =>
    new Intl.DateTimeFormat("ja-JP", { month: "long", day: "numeric" }).format(
        new Date(value),
    );
const link = (href, label, secondary = false) =>
    `<a class="button ${secondary ? "button-secondary" : "button-primary"}" href="#${href}">${label}${icon("arrow")}</a>`;
export function notice(text, error = false) {
    message.textContent = text;
    message.className = error ? "notice error" : "notice good";
    message.setAttribute("role", error ? "alert" : "status");
}
function focusScreen() {
    const heading = root.querySelector("h1");
    if (heading) {
        heading.tabIndex = -1;
        heading.focus({ preventScroll: true });
    }
    scrollTo({ top: 0, behavior: "instant" });
}
export async function act(mutate, view, planId = editing) {
    if (busy) {
        notice("処理中です。この操作はまだ保存・反映されていません。", true);
        return false;
    }
    busy = true;
    root.setAttribute("aria-busy", "true");
    const buttons = [...root.querySelectorAll("button")].map((b) => [
        b,
        b.disabled,
    ]);
    buttons.forEach(([b]) => (b.disabled = true));
    try {
        state = await repo.change(state.revision, mutate);
        dirty = false;
        render(view, planId);
        notice(
            stored
                ? "この端末に保存しました。"
                : "この画面に反映しました。再読み込みすると消えます。",
        );
        return true;
    } catch (e) {
        notice(
            errors[e.message] ||
                "保存・反映できませんでした。入力を控え、状態を確認してください。",
            true,
        );
        return false;
    } finally {
        busy = false;
        root.setAttribute("aria-busy", "false");
        buttons.forEach(([b, disabled]) => {
            if (b.isConnected) b.disabled = disabled;
        });
    }
}
function requestRender(view, id) {
    if (busy) {
        notice("保存処理中です。完了するまでお待ちください。", true);
        return;
    }
    if (
        dirty &&
        !confirm("まだ保存・反映していない入力を破棄して移動しますか？")
    )
        return;
    dirty = false;
    render(view, id);
    if (view === "plans") {
        const editor = root.querySelector("#plan-form h2");
        editor.tabIndex = -1;
        editor.focus({ preventScroll: true });
        root.querySelector("#plan-form").scrollIntoView({
            block: "start",
            behavior: "instant",
        });
    } else focusScreen();
}
function heading(kicker, title, description, extra = "") {
    return `<div class="screen-heading ${h(extra)}"><p class="eyebrow">${kicker}</p><h1 aria-label="${h(title)}">${title
        .split("、")
        .map(
            (part, i) =>
                `<span>${h(part)}${i === 0 && title.includes("、") ? "、" : ""}</span>`,
        )
        .join("")}</h1><p class="lead">${description}</p></div>`;
}
function home() {
    const n = count(),
        plans = newest(),
        started = !!state.diagnosis;
    const first = !started && !plans.length;
    const homeState = plans.length
        ? "plans"
        : started
          ? n === 30
              ? "answered"
              : "partial"
          : "first";
    const title = plans.length
        ? "あなたの一歩が、ここに。"
        : started
          ? n === 30
              ? "気づきを、次の一歩に。"
              : "途中から、続けてみよう。"
          : "すべての一歩が、未来につながる。";
    const description = first
        ? "小さな行動から、可能性が広がる未来へ。"
        : plans.length
          ? stored
              ? "保存した目標と、次の一歩をここから。"
              : "入力した目標と、次の一歩をここから。"
          : n === 30
            ? "30問を選択済み。いまの結果から、次の一歩を考えましょう。"
            : `${n} / 30問を選択済み。前回の続きから、自分のペースで。`;
    const railArt = `<svg class="home-rails" viewBox="0 0 280 100" fill="none" aria-hidden="true" focusable="false"><g class="rail-lines"><path d="M18 22h70c24 0 24 28 48 28h62"></path><path d="M18 50h180"></path><path d="M18 78h70c24 0 24-28 48-28h62"></path></g><g class="rail-lights"><circle cx="18" cy="22" r="3"></circle><circle cx="42" cy="50" r="4"></circle><circle cx="18" cy="78" r="3"></circle></g><circle class="rail-halo" cx="222" cy="50" r="25"></circle><circle class="rail-hub" cx="222" cy="50" r="17"></circle><path class="rail-core" d="m222 40 2.7 7.3L232 50l-7.3 2.7L222 60l-2.7-7.3L212 50l7.3-2.7Z"></path></svg>`;
    const primary = plans.length
        ? `<a class="button button-primary" href="#plans" data-home-primary data-open-plan="${plans[0].id}">Planをひらく ${icon("arrow")}</a>`
        : `<a class="button button-primary" href="#${n === 30 ? "result" : "diagnosis"}" data-home-primary>${n === 30 ? "結果をふりかえる" : "診断の続きをひらく"} ${icon("arrow")}</a>`;
    const secondary = plans.length
        ? `<a class="button button-secondary" href="#diagnosis" data-home-secondary>関心の診断をひらく ${icon("spark")}</a>`
        : `<a class="button button-secondary" href="#plans" data-home-secondary>診断なしでPlanを作る ${icon("plan")}</a>`;
    const overview = `<section class="section-space"><div class="section-title"><h2>いまの準備</h2><span class="subtle">${stored ? "この端末の記録" : "この画面の入力"}</span></div><div class="overview-grid"><a class="overview-card ${started ? "" : "overview-empty"}" href="#${started ? "result" : "diagnosis"}">${icon("spark")}<span>関心の診断<strong>${started ? `${n} / 30<small>問を選択済み</small>` : "まだ記録なし"}</strong></span>${icon("arrow")}</a><a class="overview-card" href="#plans">${icon("plan")}<span>自分のPlan<strong>${plans.length}<small>件${stored ? "を保存" : "を入力"}</small></strong></span>${icon("arrow")}</a></div></section>`;
    return `<div class="home-experience" data-home-state="${homeState}"><section class="home-hero ${first ? "home-hero-first" : "home-hero-returning"}"><div class="home-hero-top"><p class="eyebrow">${first ? "Canoviaが目指す未来" : "あなたの、これから。"}</p>${railArt}</div><h1 aria-label="${h(title)}">${title
        .split("、")
        .map(
            (part, i) =>
                `<span>${h(part)}${i === 0 && title.includes("、") ? "、" : ""}</span>`,
        )
        .join(
            "",
        )}</h1><p class="hero-description">${h(description)}</p>${first ? "" : `<div class="hero-resume-actions">${primary}${secondary}</div>`}</section>
    ${first ? `<section class="home-now section-space" data-home-first aria-labelledby="home-now-heading"><div class="section-title"><h2 id="home-now-heading">先行版で、いまできること</h2></div><div class="home-choices"><a class="home-choice" href="#diagnosis" data-home-choice="diagnosis"><span class="icon-bubble">${icon("spark")}</span><span><small class="choice-kicker">01 · 30問の診断</small><strong>関心を見つける</strong><small>回答をヒントに、いまの自分を整理。</small></span>${icon("arrow")}</a><a class="home-choice" href="#plans" data-home-choice="plans"><span class="icon-bubble">${icon("plan")}</span><span><small class="choice-kicker">02 · 自分の目標・Plan</small><strong>目標と、次の一歩を書く</strong><small>診断なしで、ここから始められます。</small></span>${icon("arrow")}</a></div><p class="home-record-note">${icon("shield")}${stored ? "記録はこの端末に保存。クラウド同期はありません。" : "この画面だけのPreview。再読み込みで入力が消えます。"}</p></section>` : overview}
    ${plans.length ? `<section class="section-space"><div class="section-title"><h2>最近のPlan</h2><a href="#plans">すべて見る</a></div><a class="recent-plan card" href="#plans" data-open-plan="${plans[0].id}"><span class="pill">${h(plans[0].category || "自分の目標")}</span><h3>${h(plans[0].title)}</h3><p class="line-clamp">${h(plans[0].description || "メモはまだありません。編集から次の一歩を残せます。")}</p><div class="card-bottom"><time datetime="${plans[0].updated_at}">${dateLabel(plans[0].updated_at)}に更新</time>${icon("arrow")}</div></a></section>` : ""}
    <section class="home-future card section-space" aria-labelledby="home-future-heading"><span class="pill">将来構想</span><h2 id="home-future-heading">一歩が、つながる未来へ。</h2><p>関心や目標から、次の行動を支えられるCanoviaを目指しています。</p><p class="field-hint">AI支援・自動記録・外部サービス連携は、この先行版では提供していません。</p><a class="text-link" href="#future">これからのCanoviaを見る ${icon("arrow")}</a></section>
    ${first ? `<details class="explanation home-guide"><summary>はじめての方へ：記録とバックアップ</summary><p>診断は任意です。Planから始めても大丈夫。${stored ? "記録はこの端末に保存され、別端末には同期されません。" : "保存なしのPreviewです。再読み込み・終了で入力は消えます。"}大切な記録は、データ管理からJSONをExportし、別の安全な場所へ保管してください。</p></details>` : ""}</div>`;
}
function diagnosis() {
    const answers = state.diagnosis?.answers || {},
        step = state.diagnosis?.step || 1;
    return (
        heading(
            "DISCOVER · 関心を見つける",
            "30問で関心を見つける",
            "日常の選び方から、今の関心を整理します。",
            "diagnosis-heading",
        ) +
        `<p class="scale-help" id="answer-scale-help">中央は「判断できない」。未回答・中立点とは別です。</p><details class="explanation diagnosis-instructions"><summary>回答のしかた・診断について</summary><p>左の緑が「あてはまる」、右の紫が「あてはまらない」。大きい円ほど強い回答です。中央の「わからない」は判断できない場合の独立した回答で、0点や中立点として採点しません。回答は任意です。能力・達成度・性格を判定する検査ではなく、心理学的な妥当性は未検証です。未回答も肯定・否定として採点しません。</p></details>
  <form method="post" id="diagnosis-form"><div class="diagnosis-progress"><div><strong id="question-progress"></strong><span class="subtle">回答は任意</span></div><progress max="30" value="${Object.keys(answers).length}" aria-label="選択済みの質問数"></progress><div class="page-steps" aria-label="質問のページ">${Array.from({ length: 6 }, (_, i) => `<button type="button" data-step="${i + 1}" aria-label="質問 ${i * 5 + 1}〜${i * 5 + 5}へ" ${i + 1 === step ? 'aria-current="step"' : ""}>${i + 1}</button>`).join("")}</div></div>
  <label class="consent-row"><input type="checkbox" name="consent" required ${state.diagnosis ? "checked" : ""}><span>${stored ? "回答・結果と自分の修正をこの端末に保存することに同意します。クラウド保存ではありません。" : "入力はこの画面だけに反映され、再読み込み・終了で失われることを確認しました。"}</span></label>
  ${Array.from(
      { length: 6 },
      (_, page) =>
          `<section data-page="${page + 1}" ${page + 1 !== step ? "hidden" : ""} aria-label="質問 ${page * 5 + 1}〜${page * 5 + 5}">${Object.entries(
              bank.items,
          )
              .slice(page * 5, page * 5 + 5)
              .map(
                  ([id, item]) =>
                      `<fieldset class="question-card card"><legend><span class="question-number">QUESTION ${id.slice(1)}</span>${h(item.text)}</legend><div class="response-scale">${[
                          ["3", "あてはまる", "positive", "large"],
                          ["2", "ややあてはまる", "positive", "medium"],
                          ["unknown", "わからない", "neutral", "small"],
                          ["1", "あまりあてはまらない", "negative", "medium"],
                          ["0", "あてはまらない", "negative", "large"],
                      ]
                          .map(
                              ([value, label, tone, size]) =>
                                  `<label class="scale-choice scale-${tone} scale-${size}" title="${label}"><input type="radio" name="${id}" value="${value}" ${value === "unknown" ? 'aria-describedby="answer-scale-help"' : ""} ${String(answers[id]) === value ? "checked" : ""}><span class="scale-circle" aria-hidden="true">${icon("check")}</span><span class="scale-option-text">${label}</span></label>`,
                          )
                          .join(
                              "",
                          )}</div><div class="scale-labels" aria-hidden="true"><span class="scale-label-positive">あてはまる</span><span class="scale-label-neutral">わからない</span><span class="scale-label-negative">あてはまらない</span></div><div class="question-bottom"><span data-answer-state="${id}" class="subtle">${Object.hasOwn(answers, id) ? "選択済み" : "未回答"}</span><button class="button-text" type="button" data-unanswer="${id}" aria-label="質問 ${id.slice(1)}を未回答に戻す">未回答に戻す</button></div></fieldset>`,
              )
              .join("")}</section>`,
  ).join("")}
  <div class="page-navigation"><button class="button button-secondary" type="button" id="previous">前の5問</button><button class="button button-primary" type="button" id="next">次の5問</button></div><p class="subtle" id="diagnosis-save-help">${stored ? "前後に進むときも、選択内容をこの端末に保存します。" : "前後に進むときも、この画面に選択内容を反映します。"}</p>
  <section class="card save-panel"><h2>今日は、ここまででも大丈夫。</h2><p>未回答があっても結果を見られます。いつでも回答を見直せます。</p><div class="button-stack"><button name="action" value="save" type="submit" class="button button-secondary">${stored ? "途中保存する" : "途中の内容を反映する"}</button><button name="action" value="result" type="submit" class="button button-primary">今の回答で結果を見る</button></div></section></form>`
    );
}
function result() {
    if (!state.diagnosis)
        return (
            heading(
                "REFLECT · 関心をふりかえる",
                "まだ、関心の記録はありません。",
                "任意の30問で、今の自分の選び方をふりかえってみませんか。",
            ) +
            `<section class="card empty-state">${icon("spark")}<h2>気になるところから、少しずつ。</h2><p>答えられる質問だけで大丈夫です。診断を使わずPlanを作ることもできます。</p>${link("diagnosis", "関心を見つける")}${link("plans", "自分の目標・Planへ", true)}</section>`
        );
    const r = score(state.diagnosis.answers),
        labels = {
            insufficient_evidence:
                "まだ判断材料が足りません。各分野の基本3問のうち2問以上の回答が必要です。",
            no_relative_signal:
                "今の回答からは相対的な割合を求められません。能力や関心の欠如を意味しません。",
        };
    const ready = Object.entries(r.categories)
        .filter(([, v]) => v.share_percent !== null)
        .sort((a, b) => b[1].score - a[1].score);
    const high = ready
        .filter(([, v]) => v.score === ready[0]?.[1].score)
        .map(([, v]) => v.label);
    const choice = (field, selected) =>
        Object.entries(bank.categories)
            .map(
                ([id, label]) =>
                    `<label class="interest-choice"><input type="checkbox" name="${field}" value="${id}" ${selected.includes(id) ? "checked" : ""}><span>${h(label)}</span></label>`,
            )
            .join("");
    return (
        heading(
            "REFLECT · 関心をふりかえる",
            "関心のふりかえり",
            "今の回答から見た、相対的な関心のバランスです。能力・達成度・適性の判定ではありません。",
        ) +
        `<section class="result-summary"><span class="icon-bubble">${icon("spark")}</span><div><h2>${high.length && r.status === "ready" ? "今、目を向けていること" : "もう少し、ゆっくり見つけよう"}</h2><p>${high.length && r.status === "ready" ? h(high.length > 3 ? `${high.length}つの分野が同じ得点で並んでいます。` : high.join("・")) : "答えられる質問から、少しずつ。"}</p></div></section>
  <section class="card"><div class="section-title"><h2>関心のバランス</h2><span class="pill">今回の回答</span></div><p id="result-counts" class="result-counts">回答 ${r.answered_count}問 ／ わからない ${r.unknown_count}問 ／ 未回答 ${r.unanswered_count}問</p><p class="subtle">${labels[r.status] || "割合は回答できた分野の間でのバランスです。低い値は苦手という意味ではありません。結果に合わせる必要はありません。"}</p><ul class="chart-list">${Object.entries(
      r.categories,
  )
      .map(
          ([id, c]) =>
              `<li class="chart-row" data-category="${id}"><span class="chart-label"><img src="./assets/${id.toLowerCase()}.webp" width="44" height="44" alt=""><strong>${h(c.label)}</strong></span><span class="chart-value">${c.share_percent === null ? "材料不足" : c.share_percent + "<small>%</small>"}</span>${c.share_percent === null ? "" : `<meter min="0" max="100" value="${c.share_percent}" aria-label="${h(c.label)} ${c.share_percent}%"></meter>`}</li>`,
      )
      .join(
          "",
      )}</ul><details class="explanation"><summary>割合の出し方</summary><p>0〜3点を分野ごとに集計し、複数分野の設問は半分ずつ配分します。unknownと未回答は分母から除き、基本3問中2問以上の回答がある分野で割合を配分します。表示上の丸めにより、同点でも1%の差が出ることがあります。心理尺度ではありません。</p></details><a class="text-link" href="#diagnosis">回答を見直す・再計算する ${icon("arrow")}</a></section>
  <form method="post" id="review-form" class="card section-space"><h2>自分の感覚も、大切に。</h2><p>グラフと違っても大丈夫。選んだ関心は、点数とは別に残します。</p><fieldset><legend>自分で選ぶ、今の関心（任意）</legend><div class="interest-grid">${choice("confirmed", state.diagnosis.confirmed_categories)}</div></fieldset><details class="explanation"><summary>準備したいことも残す（任意）</summary><fieldset><legend>準備したいこと（任意）</legend><p class="subtle">希望を端末に記録するだけで、送信・投票や機能提供を意味しません。</p><div class="interest-grid">${choice("support", state.diagnosis.requested_support)}</div></fieldset></details><button class="button button-secondary" type="submit">関心・希望を${stored ? "保存" : "反映"}する</button></form>
  <section class="next-step section-space"><p class="eyebrow">気づきから、一歩へ</p><h2>やってみたいことを、言葉に。</h2><p>結果はヒントのひとつ。目標と、まず試すことは自分で決められます。</p>${link("plans", "自分の目標・Planへ")}<a class="text-link" href="#future">Canovia Futureを見る ${icon("arrow")}</a></section>
  <details class="explanation danger-zone"><summary>診断の記録を管理する</summary><p>診断だけを削除できます。Planは保持します。</p><button class="button button-danger" id="delete-diagnosis" type="button">診断の記録を削除する</button></details>`
    );
}
function futureView() {
    const labels = {
        planned: "将来構想",
        in_development: "開発中",
        ready: "検証中・一般提供未承認",
    };
    return (
        heading(
            "EXPLORE · これからのCanovia",
            "Canovia Future",
            "自分の可能性を、もっと広く。開発中の体験と、これから目指すことをご紹介します。",
        ) +
        `<div class="info-banner">${icon("future")}<p>ここに掲載した機能が、今すぐ使えるわけではありません。現在使えるのは、関心の診断と端末内のPlan・データ管理です。</p></div><div class="future-grid">${future.map((v, i) => `<article class="card future-card"><div class="future-art future-art-${i}" aria-hidden="true">${icon(["plan", "spark", "future"][i])}</div><div class="future-content"><div class="card-bottom"><span class="pill">${h(labels[v.status])}</span><span class="subtle">${h(v.area)}</span></div><h2>${h(v.title)}</h2><p>${h(v.description)}</p></div></article>`).join("")}</div><p class="subtle">投票・応援数の表示はありません。公開時期・機能提供を約束するものではありません。</p>${link("plans", "いまの自分のPlanへ", true)}`
    );
}
export function render(
    view = location.hash.slice(1) || "home",
    planId = editing,
) {
    if (
        !["home", "diagnosis", "result", "future", "plans", "data"].includes(
            view,
        )
    )
        view = "home";
    editing = planId;
    if (view === "plans" && editing === undefined)
        editing = newest()[0]?.id ?? null;
    currentView = view;
    history.replaceState(null, "", "#" + view);
    root.innerHTML =
        view === "home"
            ? home()
            : view === "diagnosis"
              ? diagnosis()
              : view === "result"
                ? result()
                : view === "future"
                  ? futureView()
                  : view === "plans"
                    ? planView(state, editing, h, PROFILE)
                    : backupUI.markup();
    document.querySelectorAll("[data-nav]").forEach((a) => {
        const active =
            a.dataset.nav === (view === "result" ? "diagnosis" : view);
        if (active) a.setAttribute("aria-current", "page");
        else a.removeAttribute("aria-current");
    });
    document.title =
        {
            home: "ホーム",
            diagnosis: "関心を見つける",
            result: "関心のふりかえり",
            future: "Canovia Future",
            plans: "目標とPlan",
            data: "データを守る",
        }[view] + " | Canovia";
    if (view === "plans") bindPlans(root, state, editing, act, requestRender);
    if (view === "diagnosis") bindDiagnosis();
    if (view === "data")
        backupUI.install(root, {
            repo,
            h,
            notice,
            busy: () => busy,
            replace: async (operation, onFailure) => {
                if (busy) return;
                busy = true;
                root.setAttribute("aria-busy", "true");
                try {
                    state = validate(await operation());
                    dirty = false;
                    render("data");
                    notice(
                        stored
                            ? "Importしました。この端末で内容を確認し、改めてExportしてください。"
                            : "この画面にImportしました。再読み込みすると消えます。",
                    );
                } catch {
                    onFailure();
                } finally {
                    busy = false;
                    root.setAttribute("aria-busy", "false");
                }
            },
        });
    const review = root.querySelector("#review-form");
    if (review)
        review.onsubmit = (e) => {
            e.preventDefault();
            const f = new FormData(review);
            act((s) => {
                s.diagnosis.confirmed_categories = f.getAll("confirmed");
                s.diagnosis.requested_support = f.getAll("support");
                s.diagnosis.updated_at = new Date().toISOString();
            }, "result");
        };
    root.querySelector("#delete-diagnosis")?.addEventListener("click", () => {
        if (confirm("この端末の診断回答・修正を削除します。Planは保持します。"))
            act((s) => {
                s.diagnosis = null;
            }, "diagnosis");
    });
    root.querySelectorAll("[data-open-plan]").forEach((a) =>
        a.addEventListener("click", () => {
            editing = a.dataset.openPlan;
        }),
    );
    root.querySelectorAll("input:not([type=file]),textarea").forEach((v) =>
        v.addEventListener("input", () => {
            dirty = true;
            const status = root.querySelector("#editor-save-state");
            if (status) {
                status.textContent = "未保存の変更があります";
                status.className = "save-state unsaved";
            }
        }),
    );
}
function bindDiagnosis() {
    const form = root.querySelector("#diagnosis-form");
    let step = state.diagnosis?.step || 1;
    const update = () => {
        const n = form.querySelectorAll("input[type=radio]:checked").length;
        root.querySelector("#question-progress").textContent =
            `${step} / 6ページ · 選択済み ${n} / 30問`;
        form.querySelector("progress").value = n;
        root.querySelector("#previous").disabled = step === 1;
        root.querySelector("#next").disabled = step === 6;
        form.querySelectorAll("[data-answer-state]").forEach((el) => {
            el.textContent = form.querySelector(
                `input[name="${el.dataset.answerState}"]:checked`,
            )
                ? "選択済み"
                : "未回答";
        });
    };
    const answers = () => {
        const values = new FormData(form),
            out = {};
        for (const id of Object.keys(bank.items)) {
            const v = values.get(id);
            if (v !== null) out[id] = v === "unknown" ? v : Number(v);
        }
        return out;
    };
    const move = async (next) => {
        if (!form.reportValidity()) return;
        const values = answers();
        if (await act((s) => saveDiagnosis(s, values, next), "diagnosis")) {
            const legend = root.querySelector(
                "[data-page]:not([hidden]) legend",
            );
            legend.tabIndex = -1;
            legend.focus({ preventScroll: true });
            legend.scrollIntoView({ block: "start", behavior: "instant" });
        }
    };
    root.querySelector("#previous").onclick = () => move(step - 1);
    root.querySelector("#next").onclick = () => move(step + 1);
    form.querySelectorAll("[data-step]").forEach(
        (b) => (b.onclick = () => move(Number(b.dataset.step))),
    );
    form.onchange = update;
    form.querySelectorAll("[data-unanswer]").forEach(
        (b) =>
            (b.onclick = () => {
                form.querySelectorAll(
                    `input[name="${b.dataset.unanswer}"]`,
                ).forEach((v) => (v.checked = false));
                dirty = true;
                update();
            }),
    );
    form.onsubmit = async (e) => {
        e.preventDefault();
        const values = answers(),
            target = e.submitter?.value === "result" ? "result" : "diagnosis";
        if (await act((s) => saveDiagnosis(s, values, step), target))
            focusScreen();
    };
    update();
}
addEventListener("hashchange", () => {
    if (!repo) return;
    if (busy) {
        history.replaceState(null, "", "#" + currentView);
        notice("保存処理中です。完了するまでお待ちください。", true);
        return;
    }
    if (
        dirty &&
        !confirm("まだ保存・反映していない入力を破棄して移動しますか？")
    ) {
        history.replaceState(null, "", "#" + currentView);
        return;
    }
    dirty = false;
    notice("");
    render();
    focusScreen();
});
addEventListener("beforeunload", (e) => {
    if (dirty) {
        e.preventDefault();
        e.returnValue = "";
    }
});
try {
    const local =
        ["127.0.0.1", "localhost", "[::1]"].includes(location.hostname) &&
        ["http:", "https:"].includes(location.protocol);
    const accepted =
        ADOPTED_ORIGIN === null ? local : location.origin === ADOPTED_ORIGIN;
    if (
        !accepted ||
        !["/", "/index.html"].includes(location.pathname) ||
        location.href.split("#")[0].includes("?") ||
        navigator.serviceWorker?.controller
    )
        throw Error("ORIGIN_NOT_ACCEPTED");
    if (navigator.serviceWorker) {
        try {
            if ((await navigator.serviceWorker.getRegistrations()).length)
                throw Error("EXISTING_SW");
        } catch {
            throw Error("ORIGIN_NOT_ACCEPTED");
        }
    }
    repo = await Repository.open();
    state = validate(await repo.read());
    message.textContent = "";
    document.querySelector(".local-badge").textContent = stored
        ? "端末内"
        : "保存なし";
    document.querySelector("#storage-notice").textContent = stored
        ? "この端末に保存 · クラウド同期なし"
        : "保存なしのPreview · 再読み込み・終了で入力が消えます";
    repo.onchange = () =>
        notice(
            "別のタブで変更されました。入力を控えてから再読み込みしてください。",
            true,
        );
    render();
} catch (e) {
    const text =
        errors[e.message] ||
        (e.message === "ORIGIN_NOT_ACCEPTED"
            ? "この配信先・配信処理は未採用です。記録を書き換えず終了しました。"
            : "保存領域を開けませんでした。記録を削除せず、設定や対応する版を確認してください。");
    notice(text, true);
    root.innerHTML = "";
    const errorPanel = document.querySelector("#startup-error");
    errorPanel.hidden = false;
    errorPanel.innerHTML = `<section class="card empty-state"><h1>記録を開けませんでした</h1><p>データの削除や別の保存方式への切替は行っていません。設定と対応する版を確認してください。</p><button class="button button-secondary" id="retry-load" type="button">画面を再読み込み</button></section>`;
    errorPanel.querySelector("#retry-load").onclick = () => location.reload();
}
