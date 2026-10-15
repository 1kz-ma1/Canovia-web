#!/usr/bin/env node
/**
 * Safe, no-credential preflight of public DNS/TLS and the CURRENT Render Static
 * HTML. This is NOT an application readiness test, a redirect follower, a
 * production deploy, an Aiven operation, or a custom-domain origin parity
 * assertion. Never send an HTTP request to the Web/Laravel origins.
 */
import dns from 'node:dns/promises';
import tls from 'node:tls';
import https from 'node:https';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

export const WEB_CONFIGURED = 'app.canovia.com';
export const WEB_RENDER = 'pacekeeper-d3mm.onrender.com';
export const STATIC_RENDER = 'pacekeeper-welcome.onrender.com';
const ALLOWED_HOSTS = Object.freeze([WEB_CONFIGURED, WEB_RENDER, STATIC_RENDER]);
const STATIC_PATHS = Object.freeze(['/index.html', '/intro.html']);
const MAX_RESPONSE_BYTES = 100_000;

export function approvedHost(host) {
  return ALLOWED_HOSTS.includes(host);
}

export function requireConfiguredWebOrigin(configSource) {
  const found = configSource.match(/appUrl:\s*'https:\/\/([^/'\s]+)'/);
  if (!found || !approvedHost(found[1])) {
    return { status: 'BLOCK_CONFIG_UNEXPECTED_WEB_ORIGIN' };
  }
  // Confirm static-only rollout cannot activate never-deployed Web features.
  if (!/publicRoadmapReady:\s*false/.test(configSource)
      || !/supportAnchorReady:\s*false/.test(configSource)) {
    return { status: 'BLOCK_STATIC_RELEASE_FLAGS_NOT_OFF' };
  }
  return { status: 'CONFIG_REVIEW_ONLY', host: found[1] };
}

export function classifyIntro(statusCode, body) {
  if (statusCode === 404 || statusCode === 403) return 'INTRO_NOT_LIVE';
  if (statusCode !== 200) return 'INTRO_ENDPOINT_UNVERIFIED';
  return body.includes('やったことを、') && body.includes('開発ロードマップ')
    ? 'INTRO_HTML_VISIBLE'
    : 'INTRO_HTML_UNVERIFIED';
}

export function classifyReadOnlyResults(probes) {
  const tlsVerified = ALLOWED_HOSTS.every(h => probes.tls?.[h] === 'TLS_VALID');
  if (!tlsVerified) return 'BLOCK_PUBLIC_DNS_OR_TLS_UNVERIFIED';
  if (probes.staticIndex !== 'STATIC_OLD_OR_NEW_INDEX_VISIBLE') {
    return 'BLOCK_STATIC_INDEX_UNVERIFIED';
  }
  // Visible /intro.html would not certify that the latest main was deployed.
  return 'REVIEW_REQUIRED_NO_PRODUCTION_GO';
}

function codeForNetworkError(e) {
  const code = e && typeof e.code === 'string' ? e.code : '';
  if (['ENOTFOUND', 'EAI_AGAIN'].includes(code)) return 'DNS_UNVERIFIED';
  if (['ETIMEDOUT', 'ESOCKETTIMEDOUT'].includes(code)) return 'TIMEOUT';
  if (code.startsWith('ERR_TLS') || code.includes('CERT') || code.includes('SSL')) return 'TLS_INVALID';
  return 'HTTPS_UNVERIFIED';
}

async function probeTLS(host) {
  if (!approvedHost(host)) return 'BLOCK_UNLISTED_HOST';
  try {
    await dns.lookup(host);
  } catch (error) {
    return codeForNetworkError(error);
  }
  return await new Promise(resolve => {
    let done = false;
    const finish = status => {
      if (done) return;
      done = true;
      socket.destroy();
      resolve(status);
    };
    const socket = tls.connect({
      host, port: 443, servername: host, rejectUnauthorized: true,
      timeout: 8000,
    });
    socket.once('secureConnect', () => finish(socket.authorized ? 'TLS_VALID' : 'TLS_INVALID'));
    socket.once('timeout', () => finish('TIMEOUT'));
    socket.once('error', error => finish(codeForNetworkError(error)));
  });
}

async function staticGET(path) {
  if (!STATIC_PATHS.includes(path)) return { status: 'BLOCK_UNLISTED_PATH' };
  return await new Promise(resolve => {
    let finished = false;
    const finish = status => {
      if (finished) return;
      finished = true;
      request.destroy();
      resolve(status);
    };
    const request = https.get({
      hostname: STATIC_RENDER, port: 443, path, method: 'GET',
      timeout: 8000, maxRedirects: 0,
      headers: { 'User-Agent': 'Canovia-Static-ReadOnly-Preflight/1', 'Accept': 'text/html' },
    }, response => {
      const code = response.statusCode || 0;
      if (code >= 300 && code < 400) {
        response.destroy();
        finish({ status: 'REDIRECT_BLOCKED' });
        return;
      }
      let length = 0;
      const chunks = [];
      response.on('data', chunk => {
        length += chunk.length;
        if (length > MAX_RESPONSE_BYTES) {
          response.destroy();
          finish({ status: 'BODY_TOO_LARGE' });
        } else chunks.push(chunk);
      });
      response.on('error', error => finish({ status: codeForNetworkError(error) }));
      response.on('end', () => {
        if (finished) return;
        const html = Buffer.concat(chunks).toString('utf8');
        if (path === '/intro.html') {
          finish({ status: classifyIntro(code, html) });
        } else {
          finish({ status: code === 200 && html.includes('data-open') && html.includes('data-status')
            ? 'STATIC_OLD_OR_NEW_INDEX_VISIBLE'
            : 'INDEX_HTML_UNVERIFIED' });
        }
      });
    });
    request.on('timeout', () => finish({ status: 'TIMEOUT' }));
    request.on('error', error => finish({ status: codeForNetworkError(error) }));
  });
}

async function main() {
  if (process.argv.slice(2).join(' ') !== '--live-read-only'
      || process.env.CANOVIA_STATIC_URL_PROBE_APPROVED !== 'YES') {
    console.log(JSON.stringify({ result: 'BLOCK', code: 'EXPLICIT_READONLY_GATE_REQUIRED' }));
    process.exitCode = 2;
    return;
  }
  const configPath = fileURLToPath(new URL('../../static-welcome/config.js', import.meta.url));
  const config = requireConfiguredWebOrigin(readFileSync(configPath, 'utf8'));
  if (config.status !== 'CONFIG_REVIEW_ONLY') {
    console.log(JSON.stringify({ result: 'BLOCK', code: config.status }));
    process.exitCode = 2;
    return;
  }
  const tlsResults = Object.fromEntries(await Promise.all(
    ALLOWED_HOSTS.map(async host => [host, await probeTLS(host)])
  ));
  // Static Render only; NO HTTP requests to app.canovia.com or Web Render.
  const index = await staticGET('/index.html');
  const intro = await staticGET('/intro.html');
  const result = { tls: tlsResults, staticIndex: index.status, intro: intro.status };
  const code = classifyReadOnlyResults(result);
  console.log(JSON.stringify({
    result: code.startsWith('BLOCK_') ? 'BLOCK' : 'REVIEW_REQUIRED',
    code,
    tls: tlsResults, staticIndex: index.status, intro: intro.status,
    webHttpRequests: 0, credentialRequests: 0, deploys: 0, databaseOperations: 0,
    customDomainSameOriginVerified: false, deviceAccepted: false,
    releaseAuthorized: false,
  }));
  if (code.startsWith('BLOCK_')) process.exitCode = 2;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  main().catch(() => {
    console.log(JSON.stringify({ result: 'BLOCK', code: 'UNEXPECTED_PROBE_FAILURE', releaseAuthorized: false }));
    process.exitCode = 2;
  });
}
