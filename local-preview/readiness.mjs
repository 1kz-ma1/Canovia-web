import {PREVIEW_ORIGIN} from './src/config.mjs';
import {pack} from './src/validation-pack.mjs';
export function readiness(){return {status:'RELEASE_NO_GO',implemented:true,deployed:false,release_approved:false,device_verified:false,blockers:[...(PREVIEW_ORIGIN===null?['INDEPENDENT_ORIGIN_NOT_ADOPTED']:[]),...(pack.review_status!=='approved'?['QUESTION_PUBLICATION_NOT_APPROVED']:[]),'DEVICE_ACCEPTANCE_NOT_RECORDED','OWNER_RELEASE_GO_NOT_GRANTED']}}
if(import.meta.url===new URL(process.argv[1]||'',`file://${process.cwd()}/`).href)console.log(JSON.stringify(readiness()));
