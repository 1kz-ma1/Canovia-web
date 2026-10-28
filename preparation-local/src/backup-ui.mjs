import {exportJSON,previewImport,restore,MAX_BYTES} from './backup.mjs';
export const markup=()=>`<div class="screen-heading"><p class="eyebrow">KEEP · 大切な記録を守る</p><h1>データを守る</h1><p class="lead">あなたの記録を、あなたの手元に。<br>バックアップを別の安全な場所へ残しましょう。</p></div>
<div class="info-banner"><span class="backup-symbol" aria-hidden="true">↗</span><p>クラウドへのバックアップはありません。Exportがなければ、消去後に復旧できません。</p></div>
<div class="backup-grid"><section class="card backup-card"><span class="eyebrow">01 · バックアップをつくる</span><h2>記録を、ファイルに残す。</h2><p>診断とPlanをまとめてJSONに書き出します。保存先でファイルを確認し、別の安全な場所にも保管してください。</p><button id="export" class="button button-primary" type="button">JSONをExportする</button><p class="field-hint">JSONは暗号化されません。ほかの人に見られない保存先を選んでください。</p></section>
<section class="card backup-card"><span class="eyebrow">02 · バックアップから戻す</span><h2>保存した記録を、ひらく。</h2><p>ファイルを選ぶと内容を検証します。まだ保存はされず、確認後にだけImportします。</p><label class="file-label" for="import-file">JSONをImportする（2MiBまで）</label><input id="import-file" type="file" accept=".json,application/json"><div id="import-preview" class="import-preview" role="status"></div><p class="field-hint">異なる内容の診断や同じUUIDの記録は上書きしません。正式版への自動移行ではありません。</p></section></div>
<details class="explanation card"><summary>保存とバックアップについて</summary><p>端末やブラウザの消去・紛失、保存領域の不足に備えてください。プライベートブラウズや別端末での復元は保証しません。checksumは本人証明ではありません。</p></details>`;
export function install(root,ctx){
  let pending=null,generation=0;
  root.querySelector('#export').onclick=async()=>{
    try{const raw=await exportJSON(await ctx.repo.read()),url=URL.createObjectURL(new Blob([raw],{type:'application/json'}));const a=document.createElement('a');a.href=url;a.download='canovia-preparation.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);ctx.notice('Exportを作成しました。保存先のファイルを確認し、別の安全な場所へ保管してください。');}
    catch{ctx.notice('Exportできませんでした。端末内の記録は変更していません。',true);}
  };
  const input=root.querySelector('#import-file'),box=root.querySelector('#import-preview');
  input.onchange=async()=>{
    const request=++generation;pending=null;box.textContent='内容を検証しています…';
    try{
      const file=input.files[0];if(!file||file.size>MAX_BYTES)throw Error();const raw=await file.text(),preview=await previewImport(await ctx.repo.read(),raw);
      if(request!==generation)return;pending={raw,preview};
      box.innerHTML=`<p class="eyebrow">内容を確認しました · まだ保存されていません</p><p><strong>${ctx.h(file.name)}</strong><br>追加 ${preview.added}件／一致して保持 ${preview.skipped}件。既存の異なる内容は上書きしません。</p><div class="button-stack"><button id="import-confirm" class="button button-primary" type="button">確認してImportする</button><button id="import-cancel" class="button button-secondary" type="button">取り消す</button></div>`;
      box.querySelector('#import-cancel').onclick=()=>{pending=null;box.textContent='Importを取り消しました。';};
      box.querySelector('#import-confirm').onclick=async()=>{
        if(!pending||ctx.busy())return;const selected=pending;pending=null;input.disabled=true;box.querySelectorAll('button').forEach(b=>b.disabled=true);
        await ctx.replace(()=>restore(ctx.repo,selected.raw,selected.preview),()=>{box.textContent='Importできませんでした。衝突や別タブの変更を確認し、ファイルを再選択してください。既存データは保持されています。';input.disabled=false;});
      };
    }catch(e){if(request!==generation)return;box.textContent=e.message==='DUPLICATE_IMPORT'?'同じデータはImport済みです。二重取込は行いません。':'Importできません。形式・版・サイズ・破損・既存記録との衝突を確認してください。';}
  };
}
