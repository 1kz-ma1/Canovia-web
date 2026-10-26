import {exportJSON,previewImport,restore,MAX_BYTES} from './backup.mjs';
export const markup=()=>`<section class="page-card"><h1>データを守る</h1><p>端末やブラウザの消去・紛失、保存領域の不足に備え、Exportを別の安全な場所へ保管してください。Exportがなければ、消去後に復旧できません。プライベートブラウズや別端末での復元は保証しません。クラウドへのバックアップはありません。JSONは暗号化されず、checksumは本人証明ではありません。</p><button id="export" type="button">JSONをExportする</button><label>JSONをImportする（2MiBまで）<input id="import-file" type="file" accept=".json,application/json"></label><div id="import-preview" role="status"></div><p>Importは内容確認後だけ実行します。異なる内容の診断や同じUUIDの記録は上書きしません。正式版への自動移行ではありません。</p></section>`;
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
      box.innerHTML=`<p>${ctx.h(file.name)}：追加 ${preview.added}件／一致して保持 ${preview.skipped}件。既存の異なる内容は上書きしません。</p><button id="import-confirm" type="button">確認してImportする</button><button id="import-cancel" type="button">取り消す</button>`;
      box.querySelector('#import-cancel').onclick=()=>{pending=null;box.textContent='Importを取り消しました。';};
      box.querySelector('#import-confirm').onclick=async()=>{
        if(!pending||ctx.busy())return;const selected=pending;pending=null;input.disabled=true;box.querySelectorAll('button').forEach(b=>b.disabled=true);
        await ctx.replace(()=>restore(ctx.repo,selected.raw,selected.preview),()=>{box.textContent='Importできませんでした。衝突や別タブの変更を確認し、ファイルを再選択してください。既存データは保持されています。';input.disabled=false;});
      };
    }catch(e){if(request!==generation)return;box.textContent=e.message==='DUPLICATE_IMPORT'?'同じデータはImport済みです。二重取込は行いません。':'Importできません。形式・版・サイズ・破損・既存記録との衝突を確認してください。';}
  };
}
