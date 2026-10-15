import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { readFileSync } from 'node:fs';
import {
  WEB_HOST, STATIC_ORIGIN, operatorGate, classifyHealth, classifyFeedback,
} from '../../scripts/ops/prelaunch_web_live_smoke.mjs';

const approved = {
  CANOVIA_WEB_WAKE_OWNER_APPROVED: 'YES',
  CANOVIA_WEB_LIVE_SHA_OPERATOR_VERIFIED: 'YES',
  CANOVIA_WEB_DB_LEDGER_OPERATOR_VERIFIED: 'YES',
  CANOVIA_WEB_RESTORE_RISK_OWNER_ACCEPTED: 'YES',
};

test('operator-only: every independent production precondition must be affirmed', () => {
  assert.equal(operatorGate([], approved), 'BLOCK_EXPLICIT_INVOCATION_REQUIRED');
  assert.equal(operatorGate(['--live-operator-approved'], {}), 'BLOCK_INDEPENDENT_OWNER_AND_AIVEN_PRECHECK_REQUIRED');
  for (const key of Object.keys(approved)) {
    const env = {...approved};
    delete env[key];
    assert.equal(operatorGate(['--live-operator-approved'], env),
      'BLOCK_INDEPENDENT_OWNER_AND_AIVEN_PRECHECK_REQUIRED', key);
  }
  for (const environment of [{CI:'true'}, {GITHUB_ACTIONS:'true'}]) {
    assert.equal(operatorGate(['--live-operator-approved'], {...approved, ...environment}), 'BLOCK_CI_FORBIDDEN');
  }
  for (const key of ['CANOVIA_WEB_SMOKE_URL', 'DB_URL', 'DATABASE_URL',
    'DB_PASSWORD', 'MYSQL_PWD', 'CANOVIA_WEB_LOGIN_COOKIE']) {
    assert.equal(operatorGate(['--live-operator-approved'], {...approved, [key]:'forbidden'}),
      'BLOCK_CREDENTIAL_OR_ALTERNATE_TARGET');
  }
  assert.equal(operatorGate(['--live-operator-approved'], approved),
    'OPERATOR_PRECONDITIONS_SELF_ATTESTED_REVIEW_ONLY');
});

test('204+ CORS+exposed Ready never becomes production GO', () => {
  assert.equal(WEB_HOST, 'pacekeeper-d3mm.onrender.com');
  assert.equal(STATIC_ORIGIN, 'https://pacekeeper-welcome.onrender.com');
  const ready = {status:204, cors:'*', expose:'X-Canovia-Ready, X-PaceKeeper-Ready', ready:'1'};
  assert.equal(classifyHealth(ready), 'HEALTH_HEADERS_REVIEW_REQUIRED_NOT_RELEASE');
  assert.equal(classifyHealth({...ready, cors:STATIC_ORIGIN}), 'HEALTH_HEADERS_REVIEW_REQUIRED_NOT_RELEASE');
  for (const [patch, code] of [
    [{status:503}, 'BLOCK_HEALTH_NOT_204'],
    [{status:302, location:true}, 'BLOCK_HEALTH_REDIRECT'],
    [{cors:'https://evil.example'}, 'BLOCK_HEALTH_CORS'],
    [{expose:''}, 'BLOCK_HEALTH_READY_HEADER_NOT_EXPOSED'],
    [{ready:'0'}, 'BLOCK_HEALTH_NOT_READY'],
  ]) assert.equal(classifyHealth({...ready,...patch}), code);
});

test('guest /feedback is separately gated and cannot self-certify auth/session', () => {
  const expected = {status:200, contentType:'text/html; charset=UTF-8',
    location:false, snippet:'<title>Canovia Support</title>'};
  assert.equal(classifyFeedback(expected), 'FEEDBACK_GUEST_HTML_REVIEW_REQUIRED_NOT_RELEASE');
  for (const [patch, code] of [
    [{status:302, location:true}, 'BLOCK_FEEDBACK_REDIRECT'],
    [{status:401}, 'BLOCK_FEEDBACK_NOT_200'],
    [{contentType:'application/json'}, 'BLOCK_FEEDBACK_NOT_HTML'],
    [{snippet:'<html><body>unrelated</body></html>'}, 'BLOCK_FEEDBACK_PAGE_NOT_RECOGNIZED'],
  ]) assert.equal(classifyFeedback({...expected,...patch}), code);
});

test('default invocation blocks live Web before constructing any network request', () => {
  const path = fileURLToPath(new URL('../../scripts/ops/prelaunch_web_live_smoke.mjs', import.meta.url));
  const p = spawnSync(process.execPath, [path], {
    encoding: 'utf8', timeout: 5000, env: {PATH: process.env.PATH ?? ''},
  });
  assert.equal(p.status, 2);
  assert.equal(JSON.parse(p.stdout).code, 'BLOCK_EXPLICIT_INVOCATION_REQUIRED');
  assert.equal(JSON.parse(p.stdout).releaseAuthorized, false);
  const source = readFileSync(path, 'utf8');
  assert.match(source, /path !== '\/health' && path !== '\/feedback'/);
  assert.match(source, /CANOVIA_WEB_GUEST_FEEDBACK_OWNER_APPROVED/);
  assert.match(source, /serverSideWritesExcluded: false/);
  assert.doesNotMatch(source, /method:\s*'(?:POST|PUT|PATCH|DELETE)'/);
});
