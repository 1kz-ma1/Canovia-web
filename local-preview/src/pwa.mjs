import {originAllowed} from './config.mjs';
export async function install(){
 const status=document.querySelector('#offline-state');const update=()=>{status.textContent=navigator.onLine?'端末内保存・定期バックアップを忘れずに':'オフラインです。端末内で記録できます。'};
 update();addEventListener('online',update);addEventListener('offline',update);
 if(!originAllowed(location.href)||!['/','/index.html'].includes(location.pathname)||!('serviceWorker'in navigator))return;
 try{
  const registration=await navigator.serviceWorker.register('./sw.js',{scope:'./'});
  registration.addEventListener('updatefound',()=>{if(navigator.serviceWorker.controller)status.textContent='更新の準備中です。バックアップ後、すべてのPreviewタブを閉じて開き直してください。'});
 }catch{status.textContent='オフライン用の準備ができませんでした。接続中に再読み込みして確認してください。'}
}
