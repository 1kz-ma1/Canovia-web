// A deployment origin must be separately reviewed and owner-approved in a PR.
export const PREVIEW_ORIGIN = null;
export function originAllowed(url) {
 const u=new URL(url);
 return (['127.0.0.1','localhost','[::1]'].includes(u.hostname) && ['http:','https:'].includes(u.protocol)) || (PREVIEW_ORIGIN!==null&&u.origin===PREVIEW_ORIGIN&&u.protocol==='https:');
}
