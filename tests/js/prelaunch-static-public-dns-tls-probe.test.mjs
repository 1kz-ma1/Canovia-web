import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import {
  WEB_CONFIGURED, WEB_RENDER, STATIC_RENDER, approvedHost,
  requireConfiguredWebOrigin, classifyIntro, classifyReadOnlyResults,
} from '../../scripts/ci/prelaunch_static_public_dns_tls_probe.mjs';

test('only the three reviewed public hostnames are allowed, never arbitrary URL', () => {
  assert.deepEqual([WEB_CONFIGURED, WEB_RENDER, STATIC_RENDER], [
    'app.canovia.com', 'pacekeeper-d3mm.onrender.com',
    'pacekeeper-welcome.onrender.com',
  ]);
  for (const host of ['evil.example', '127.0.0.1', 'localhost',
    'app.canovia.com.evil.test', 'http://app.canovia.com',
    'pacekeeper-welcome.onrender.com:443', '::1', '10.0.0.1']) {
    assert.equal(approvedHost(host), false, host);
  }
});

test('current static rollout flags must be explicitly false', () => {
  const actual = readFileSync(new URL('../../static-welcome/config.js', import.meta.url), 'utf8');
  assert.deepEqual(requireConfiguredWebOrigin(actual), {
    status: 'CONFIG_REVIEW_ONLY', host: 'app.canovia.com',
  });
  for (const text of [
    actual.replace('publicRoadmapReady: false', 'publicRoadmapReady: true'),
    actual.replace('supportAnchorReady: false', 'supportAnchorReady: true'),
  ]) {
    assert.equal(requireConfiguredWebOrigin(text).status, 'BLOCK_STATIC_RELEASE_FLAGS_NOT_OFF');
  }
  assert.equal(requireConfiguredWebOrigin("appUrl: 'https://unapproved.test'\npublicRoadmapReady: false\nsupportAnchorReady: false").status,
    'BLOCK_CONFIG_UNEXPECTED_WEB_ORIGIN');
});

test('old live static without intro is expected; real intro still needs manual release acceptance', () => {
  assert.equal(classifyIntro(404, ''), 'INTRO_NOT_LIVE');
  assert.equal(classifyIntro(403, ''), 'INTRO_NOT_LIVE');
  assert.equal(classifyIntro(200, '<p>old welcome</p>'), 'INTRO_HTML_UNVERIFIED');
  assert.equal(classifyIntro(503, ''), 'INTRO_ENDPOINT_UNVERIFIED');
  assert.equal(classifyIntro(200, '<h1>やったことを、</h1>開発ロードマップは準備中'), 'INTRO_HTML_VISIBLE');
});

test('TLS status and old index determine a HOLD, never release GO', () => {
  const good = {
    tls: {
      [WEB_CONFIGURED]: 'TLS_VALID',
      [WEB_RENDER]: 'TLS_VALID',
      [STATIC_RENDER]: 'TLS_VALID',
    },
    staticIndex: 'STATIC_OLD_OR_NEW_INDEX_VISIBLE',
  };
  assert.equal(classifyReadOnlyResults(good), 'REVIEW_REQUIRED_NO_PRODUCTION_GO');
  for (const host of [WEB_CONFIGURED, WEB_RENDER, STATIC_RENDER]) {
    assert.equal(classifyReadOnlyResults({
      ...good, tls: { ...good.tls, [host]: 'DNS_UNVERIFIED' },
    }), 'BLOCK_PUBLIC_DNS_OR_TLS_UNVERIFIED');
  }
  assert.equal(classifyReadOnlyResults({ ...good, staticIndex: 'INDEX_HTML_UNVERIFIED' }),
    'BLOCK_STATIC_INDEX_UNVERIFIED');
});

test('probe is fail-closed without the explicit operator event; no network attempted', () => {
  const script = fileURLToPath(new URL('../../scripts/ci/prelaunch_static_public_dns_tls_probe.mjs', import.meta.url));
  const run = spawnSync(process.execPath, [script], {
    encoding: 'utf8', env: { PATH: process.env.PATH || '' }, timeout: 5000,
  });
  assert.equal(run.status, 2);
  assert.equal(JSON.parse(run.stdout).code, 'EXPLICIT_READONLY_GATE_REQUIRED');
  const content = readFileSync(script, 'utf8');
  assert.match(content, /NO HTTP requests to app\.canovia\.com or Web Render/);
  assert.match(content, /releaseAuthorized: false/);
  assert.match(content, /STATIC_PATHS\.includes\(path\)/);
  assert.doesNotMatch(content, /fetch\(\x60https:\/\/app\.canovia\.com/);
});
