import {exportJSON,previewImport,restore,MAX_BYTES} from './backup.mjs';
export function install(ctx){
 let pending=null,generation=0;
 ctx.extensions.push(()=>{
  const panel=document.createElement('section');panel.className='card';panel.id='backup';panel.innerHTML=`<h2>データを守る</h2><p>データはこの端末だけに保存されています。定期的にJSONバックアップを別の安全な場所へ保存してください。JSONは暗号化されていません。</p><button id="export">バックアップを保存</button><label>JSONから復元（8MiBまで）<input type="file" id="import-file" accept=".json,application/json"></label><div id="restore-preview" role="status"></div>`;document.querySelector('#extensions').append(panel);
  panel.querySelector('#export').onclick=async()=>{
   try{const raw=await exportJSON(await ctx.read());const url=URL.createObjectURL(new Blob([raw],{type:'application/json'}));const a=document.createElement('a');a.href=url;a.download='canovia-local-backup.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);ctx.notice('バックアップを作成しました。保存先でファイルを確認してください。')}
   catch{ctx.notice('バックアップを作成できませんでした。端末内のデータは変更していません。',true)}
  };
  panel.querySelector('#import-file').onchange=async e=>{
   const selectedGeneration=++generation;pending=null;const box=panel.querySelector('#restore-preview');box.textContent='内容を検証しています…';
   try{const file=e.target.files[0];if(!file||file.size>MAX_BYTES)throw new Error();const raw=await file.text();const preview=await previewImport(ctx.state(),raw);if(selectedGeneration!==generation)return;pending={raw,preview};
    box.innerHTML=`<p>${ctx.escape(file.name)}：破損・形式・参照を検査しました。追加 ${preview.added}件／一致しているため保持 ${preview.skipped}件。既存の異なる内容は上書きしません。</p><button id="restore-confirm">確認して復元する</button><button id="restore-cancel" class="secondary">取り消す</button>`;
    box.querySelector('#restore-cancel').onclick=()=>{pending=null;box.textContent='復元を取り消しました。'};
    box.querySelector('#restore-confirm').onclick=async()=>{
     const selected=pending;if(!selected)return;pending=null;panel.querySelector('#import-file').disabled=true;box.querySelectorAll('button').forEach(b=>b.disabled=true);
     try{await ctx.replace(()=>restore(ctx.repo(),selected.raw,selected.preview));ctx.notice('復元しました。内容を確認し、改めてバックアップしてください。')}
     catch{box.textContent='復元できませんでした。内容の衝突・タブ更新などを確認してください。既存データは保持されています。'}
     finally{panel.querySelector('#import-file').disabled=false}
    };
   }catch(e){if(selectedGeneration!==generation)return;box.textContent=e.message==='DUPLICATE_IMPORT'?'同じデータは復元済みです。二重取込は行いません。':'復元できません。形式・破損・既存データとの衝突を確認してください。';}
  };
 });
}
