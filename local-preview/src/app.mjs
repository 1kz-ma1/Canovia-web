import {Repository} from './repository.mjs';
try { const repo = await Repository.open(); await repo.read(); document.querySelector('#message').textContent = '端末内の保存領域を利用できます。'; }
catch { document.querySelector('#message').textContent = '保存領域を利用できません。保存済みデータは削除せず、別の通常ブラウザでお試しください。'; }
