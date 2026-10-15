#!/usr/bin/env node
/**
 * Operator-run LIVE Web smoke. NEVER run automatically, in CI, or before the
 * operator checks the actual Aiven applied migration ledger and restore plan.
 * GET can wake the Free Web process, which unconditionally runs Laravel
 * 'php artisan migrate --force' before serving ANY response.
 *
 * No credential, cookie, custom URL, redirect follow, POST, or data mutation
 * is requested by this client. That DOES NOT guarantee server-side no writes.
 */
import https from 'node:https';
import { pathToFileURL } from 'node:url';

export const WEB_HOST = 'pacekeeper-d3mm.onrender.com';
export const STATIC_ORIGIN = 'https://pacekeeper-welcome.onrender.com';
const FLAGS = Object.freeze([
  'CANOVIA_WEB_WAKE_OWNER_APPROVED',
  'CANOVIA_WEB_LIVE_SHA_OPERATOR_VERIFIED',
  'CANOVIA_WEB_DB_LEDGER_OPERATOR_VERIFIED',
  'CANOVIA_WEB_RESTORE_RISK_OWNER_ACCEPTED',
]);
const MAX_BYTES = 32768;
const TIMEOUT_MS = 15000;

export function operatorGate(args, env) {
  if (args.length !== 1 || args[0] !== '--live-operator-approved') {
    return 'BLOCK_EXPLICIT_INVOCATION_REQUIRED';
  }
  if (env.CI === 'true' || env.GITHUB_ACTIONS === 'true') return 'BLOCK_CI_FORBIDDEN';
  if (FLAGS.some(key => env[key] !== 'YES')) return 'BLOCK_INDEPENDENT_OWNER_AND_AIVEN_PRECHECK_REQUIRED';
  // This is intentionally NOT a generic curl proxy or configurable target.
  if (env.CANOVIA_WEB_SMOKE_URL || env.DB_URL || env.DATABASE_URL || env.DB_PASSWORD
      || env.MYSQL_PWD || env.CANOVIA_WEB_LOGIN_COOKIE) {
    return 'BLOCK_CREDENTIAL_OR_ALTERNATE_TARGET';
  }
  return 'OPERATOR_PRECONDITIONS_SELF_ATTESTED_REVIEW_ONLY';
}

export function classifyHealth({status, cors, expose, ready, location}) {
  if (location || (status >= 300 && status < 400)) return 'BLOCK_HEALTH_REDIRECT';
  if (status !== 204) return 'BLOCK_HEALTH_NOT_204';
  if (cors !== '*' && cors !== STATIC_ORIGIN) return 'BLOCK_HEALTH_CORS';
  if (!String(expose || '').toLowerCase().split(',').map(x=>x.trim()).includes('x-canovia-ready')) {
    return 'BLOCK_HEALTH_READY_HEADER_NOT_EXPOSED';
  }
  if (ready !== '1') return 'BLOCK_HEALTH_NOT_READY';
  return 'HEALTH_HEADERS_REVIEW_REQUIRED_NOT_RELEASE';
}

export function classifyFeedback({status, contentType, location, snippet}) {
  if (location || (status >= 300 && status < 400)) return 'BLOCK_FEEDBACK_REDIRECT';
  if (status !== 200) return 'BLOCK_FEEDBACK_NOT_200';
  if (!/^text\/html(?:[;\s]|$)/i.test(contentType || '')) return 'BLOCK_FEEDBACK_NOT_HTML';
  // This deliberately does not claim the exact page's content or identity.
  if (!snippet || !(/Canovia|PaceKeeper|Support|フィードバック|応援|支援/i).test(snippet)) {
    return 'BLOCK_FEEDBACK_PAGE_NOT_RECOGNIZED';
  }
  return 'FEEDBACK_GUEST_HTML_REVIEW_REQUIRED_NOT_RELEASE';
}

function GETFixed(path) {
  if (path !== '/health' && path !== '/feedback') throw new Error('UNLISTED_PATH');
  return new Promise(resolve => {
    let done = false, size = 0;
    const chunks = [];
    const finish = v => {
      if (done) return;
      done = true;
      req.destroy();
      resolve(v);
    };
    const req = https.request({
      hostname: WEB_HOST,
      port: 443,
      method: 'GET',
      path,
      timeout: TIMEOUT_MS,
      rejectUnauthorized: true,
      servername: WEB_HOST,
      headers: {
        'Origin': STATIC_ORIGIN,
        'Accept': 'text/html, */*',
        'User-Agent': 'Canovia-Owner-Limited-Prelaunch-Smoke/1',
        // Do not send existing user cookies, API keys or credentials.
        'Cache-Control': 'no-store',
      },
    }, res => {
      res.on('data', chunk => {
        size += chunk.length;
        if (size > MAX_BYTES) return finish({status: 0, code: 'BLOCK_RESPONSE_TOO_LARGE'});
        chunks.push(chunk);
      });
      res.on('error', () => finish({status: 0, code: 'BLOCK_RESPONSE_ERROR'}));
      res.on('end', () => finish({
        status: res.statusCode ?? 0,
        cors: res.headers['access-control-allow-origin'] ?? '',
        expose: res.headers['access-control-expose-headers'] ?? '',
        ready: res.headers['x-canovia-ready'] ?? '',
        location: !!res.headers.location,
        contentType: res.headers['content-type'] ?? '',
        snippet: Buffer.concat(chunks).toString('utf8'),
      }));
    });
    req.on('timeout', () => finish({status: 0, code: 'BLOCK_TIMEOUT'}));
    req.on('error', () => finish({status: 0, code: 'BLOCK_REQUEST_ERROR'}));
    req.end();
  });
}

async function main() {
  const gate = operatorGate(process.argv.slice(2), process.env);
  if (!gate.startsWith('OPERATOR_')) {
    console.log(JSON.stringify({result: 'BLOCK', code: gate, releaseAuthorized: false}));
    process.exitCode = 2;
    return;
  }

  // First and only mandatory live GET. A server-side startup can run
  // migrations even if this single request is GET.
  const health = await GETFixed('/health');
  const healthCode = health.code || classifyHealth(health);
  const response = {
    result: healthCode.startsWith('BLOCK') ? 'BLOCK' : 'REVIEW_REQUIRED',
    code: healthCode,
    webHost: WEB_HOST,
    ownerPreconditionsTechnicallyVerified: false,
    serverSideWritesExcluded: false,
    releaseAuthorized: false,
  };
  // Do not print raw HTML, server headers, request tokens, session IDs, or DB.
  if (response.result === 'BLOCK') {
    console.log(JSON.stringify(response));
    process.exitCode = 2;
    return;
  }

  // Optional GET of guest Feedback. This can create a DB session under
  // Laravel's web middleware. It requires a separate deliberate opt-in.
  if (process.env.CANOVIA_WEB_GUEST_FEEDBACK_OWNER_APPROVED === 'YES') {
    const guest = await GETFixed('/feedback');
    const guestCode = guest.code || classifyFeedback(guest);
    response.feedbackCode = guestCode;
    if (guestCode.startsWith('BLOCK')) {
      response.result = 'BLOCK';
      response.code = 'BLOCK_GUEST_FEEDBACK_SMOKE';
      process.exitCode = 2;
    }
  } else {
    response.feedbackCode = 'NOT_TESTED_GUEST_DB_SESSION_RISK';
  }
  console.log(JSON.stringify(response));
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch(() => {
    console.log(JSON.stringify({result: 'BLOCK', code: 'BLOCK_UNEXPECTED_SMOKE_FAILURE', releaseAuthorized: false}));
    process.exitCode = 2;
  });
}
