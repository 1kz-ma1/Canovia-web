"""Only missing B1 contracts; existing B input/TLS/hash test suites are reused."""
import contextlib
import copy
from dataclasses import replace
import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/ci'))
from e858_tls.control_contract import Attempt, Binding, ExpectedTarget, Observation, Block
from e858_b_offline import DECLARATIONS, SOURCE, RELEASE, template, digest

CANARY = 'PRIVATE_CREDENTIAL_INTERNAL_ID_RAW_METADATA_NEVER_LOG'
NOW = 1000
TARGET = ExpectedTarget('1'*64, '11111111-1111-1111-1111-111111111111', CANARY, '2'*64, '3'*64)
DATA = Binding(TARGET.target, TARGET.server_uuid, TARGET.principal, 123, '4'*64, TARGET.ca, TARGET.san)
CONTROL = replace(DATA, cid=124, generation='5'*64)
SQL = {str(k): hashlib.sha256(str(k).encode()).hexdigest() for k in range(14)}
PINS = dict(source_sha=SOURCE, release_ref=RELEASE, tooling_sha='a'*40,
            sql_sha256=digest(SQL), reference_sha256={'8.0':'b'*64,'8.4':'c'*64})


def packet():
    value = template(copy.deepcopy(PINS), NOW)
    value.update(declarations=DECLARATIONS, attempt_id='d'*32)
    return value


def trial(**kwargs):
    values = dict(packet=packet(), pins=copy.deepcopy(PINS), expected_pins=PINS,
                  sql_digests=SQL, now=NOW, expected_target=TARGET)
    values.update(kwargs)
    return Attempt(**values)


def obs(sequence=1, **kwargs):
    values = dict(data=DATA, control=CONTROL, sequence=sequence, observed_at=NOW,
                  visible=True, exists=True, running=True, continuity=True)
    values.update(kwargs)
    return Observation(**values)


def active(scope=('OBSERVE','KILL_QUERY','KILL_CONNECTION')):
    value = trial(); value.bind(DATA, CONTROL, obs(), NOW, scope)
    return value


class ControlBoundaryTests(unittest.TestCase):
    def blocked(self, value, fn):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            with self.assertRaises(Block) as raised:
                fn()
        for text in (out.getvalue(), err.getvalue(), str(raised.exception), repr(value), json.dumps(value.report())):
            self.assertNotIn(CANARY, text)
            self.assertNotIn('123', text)
        self.assertEqual('STOPPED', value.state)
        self.assertEqual('REMOTE_TERMINATION_UNVERIFIED', value.report()['remote'])
        self.assertFalse(value.report()['execution_allowed'])
        return raised.exception

    def test_valid_scope_only_returns_non_executable_synthetic_result(self):
        value = active()
        result = value.check_control('KILL_QUERY', obs(2), NOW)
        self.assertFalse(result['execution_allowed'] or result['owner_approval_verified'])
        self.assertNotIn('cid', result)
        self.assertNotIn('sql', result)
        value.check_metadata('1', SQL['1'], NOW)
        result = value.finish(obs(3, exists=False, running=False), NOW)
        self.assertEqual('COMPLETED', result['state'])
        self.assertTrue(result['query_ended'] and result['connection_ended'])
        self.assertFalse(result['release_authorized'])

    def test_scope_outside_cid_and_identity_cannot_be_substituted(self):
        for field, change in [('cid', 999), ('server_uuid', '22222222-2222-2222-2222-222222222222'),
                              ('principal', 'other'), ('target', '9'*64), ('generation', '9'*64),
                              ('ca', '9'*64), ('san', '9'*64)]:
            for channel in ('data','control'):
                with self.subTest(field=field,channel=channel):
                    value = active()
                    identity = replace(DATA if channel=='data' else CONTROL, **{field:change})
                    self.blocked(value, lambda: value.check_control('KILL_CONNECTION', obs(2,**{channel:identity}), NOW))

    def test_matching_but_wrong_target_pair_rejected_against_expected(self):
        for key, change in [('target','9'*64),('principal','other'),('server_uuid','22222222-2222-2222-2222-222222222222'),('ca','9'*64),('san','9'*64)]:
            value = trial(); d=replace(DATA,**{key:change}); c=replace(CONTROL,**{key:change})
            self.blocked(value, lambda: value.bind(d,c,obs(data=d,control=c),NOW))

    def test_data_and_control_must_be_distinct_connections_same_principal(self):
        for change in (dict(cid=DATA.cid),dict(generation=DATA.generation),dict(principal='root'),dict(target='9'*64)):
            value = trial(); c=replace(CONTROL,**change)
            self.blocked(value, lambda: value.bind(DATA,c,obs(control=c),NOW))

    def test_scope_is_closed_and_no_admin_fallback(self):
        for scope in (('OBSERVE','ADMIN'),('KILL_CONNECTION',),('OBSERVE','OBSERVE'),('OBSERVE',[])):
            value=trial();self.blocked(value,lambda:value.bind(DATA,CONTROL,obs(),NOW,scope))
        value=active(('OBSERVE',))
        self.blocked(value, lambda:value.check_control('KILL_CONNECTION',obs(2),NOW))

    def test_weak_tls_or_unverified_chain_rejected_on_either_session(self):
        for tls in ('DISABLED','REQUIRED','VERIFY_CA'):
            for channel in ('data','control'):
                value=trial();d=replace(DATA,tls=tls) if channel=='data' else DATA
                c=replace(CONTROL,tls=tls) if channel=='control' else CONTROL
                self.blocked(value,lambda:value.bind(d,c,obs(data=d,control=c),NOW))
        value=trial();d=replace(DATA,tls_verified=False)
        self.blocked(value,lambda:value.bind(d,CONTROL,obs(data=d),NOW))

    def test_missing_excess_unknown_grants_reject_before_bind(self):
        for grants in ('MISSING','EXCESS','UNKNOWN'):
            for channel in ('data','control'):
                value=trial();d=replace(DATA,grants=grants) if channel=='data' else DATA
                c=replace(CONTROL,grants=grants) if channel=='control' else CONTROL
                self.blocked(value,lambda:value.bind(d,c,obs(data=d,control=c),NOW))

    def test_malformed_binding_never_echoes_input(self):
        for change in (dict(cid=True),dict(cid=-1),dict(cid=2**32),dict(target=CANARY),dict(generation=''),dict(server_uuid=CANARY)):
            value=trial();d=replace(DATA,**change)
            self.blocked(value,lambda:value.bind(d,CONTROL,obs(data=d),NOW))
        for raw in (CANARY,{'cid':123},None):
            value=trial();self.blocked(value,lambda:value.bind(raw,CONTROL,obs(),NOW))

    def test_stale_observation_time_or_sequence_rejected(self):
        for change in (dict(sequence=1),dict(sequence=3),dict(observed_at=NOW-1),dict(observed_at=NOW+1)):
            value=active();self.blocked(value,lambda:value.check_control('KILL_QUERY',obs(**({'sequence':2}|change)),NOW))

    def test_cid_reuse_even_after_observed_absence_rejected(self):
        value=active();value.observe(obs(2,exists=False,running=False),NOW)
        self.blocked(value,lambda:value.observe(obs(3),NOW))

    def test_unobservable_control_server_switch_or_observer_loss_stops(self):
        for change in (dict(visible=False),dict(continuity=False),dict(observer_connected=False),
                       dict(control=replace(CONTROL,server_uuid='22222222-2222-2222-2222-222222222222'))):
            value=active();self.blocked(value,lambda:value.finish(obs(2,exists=False,running=False,**change),NOW))

    def test_initial_empty_view_cannot_certify_termination(self):
        value=trial();self.blocked(value,lambda:value.bind(DATA,CONTROL,obs(exists=False,running=False),NOW))

    def test_idle_is_query_end_but_not_connection_termination(self):
        value=active();state=value.observe(obs(2,running=False),NOW)
        self.assertTrue(state['query_ended']);self.assertFalse(state['connection_ended'])
        self.blocked(value,lambda:value.finish(obs(3,running=False),NOW))

    def test_request_success_local_close_console_button_are_not_evidence(self):
        for _ in ('KILL_ACCEPTED','LOCAL_EXIT','CONSOLE_TERMINATE_SHOWN'):
            value=active();self.blocked(value,lambda:value.finish(obs(2),NOW))

    def test_request_failure_cannot_be_upgraded_by_later_cleanup(self):
        for key in ('query_request_failed','connection_request_failed'):
            value=active();self.blocked(value,lambda:value.finish(obs(2,exists=False,running=False),NOW,**{key:True}))
            self.blocked(value,lambda:value.finish(obs(3,exists=False,running=False),NOW))

    def test_retry_after_failure_interrupt_or_success_is_always_rejected(self):
        for ending in ('interrupt','failure','success'):
            value=active()
            if ending=='success':value.finish(obs(2,exists=False,running=False),NOW)
            elif ending=='interrupt':self.blocked(value,value.interrupt)
            else:self.blocked(value,lambda:value.observe(obs(2,visible=False),NOW))
            self.blocked(value,lambda:value.bind(DATA,CONTROL,obs(),NOW))
            self.blocked(value,lambda:value.check_metadata('1',SQL['1'],NOW))

    def test_same_go_packet_reuse_and_interrupted_prior_attempt_rejected(self):
        for prior in ('COMPLETED','INTERRUPTED','BLOCK'):
            p=packet();p['prior_attempt']=prior
            with self.assertRaisesRegex(Block,'PREFLIGHT_REJECTED'):trial(packet=p)
        # A fresh object cannot prove cross-process anti-replay. No real permit
        # is ever returned even with a copied, syntactically valid packet.
        self.assertFalse(trial().report()['execution_allowed'])

    def test_metadata_scope_free_sql_export_and_secret_leak_rejected(self):
        for key, sha, export in [(CANARY,SQL['1'],False),('1',CANARY,False),('1',SQL['1'],True)]:
            value=active();self.blocked(value,lambda:value.check_metadata(key,sha,NOW,export=export))
        value=active();self.blocked(value,lambda:value.check_control(CANARY,obs(2),NOW))
        for obj in (DATA,CONTROL,TARGET,obs()):self.assertNotIn(CANARY,repr(obj))

    def test_pin_or_bundle_tampering_and_forged_scope_rejected(self):
        for key in ('source_sha','tooling_sha','sql_sha256','reference_sha256','release_ref'):
            changed=copy.deepcopy(PINS);changed[key]=CANARY
            with self.assertRaises(Block):trial(pins=changed)
            p=packet();p['pins']=changed
            with self.assertRaises(Block):trial(packet=p)
        changed=dict(SQL);changed['1']='0'*64
        with self.assertRaises(Block):trial(sql_digests=changed)
        for declaration in ('operator','scope','tls','grants'):
            p=packet();p['declarations']=dict(DECLARATIONS,**{declaration:CANARY})
            with self.assertRaises(Block):trial(packet=p)

    def test_expired_unapproved_and_rollback_clock_stop(self):
        p=packet();p['declarations']={}
        with self.assertRaises(Block):trial(packet=p)
        with self.assertRaises(Block):trial(now=NOW+120)
        for now in (NOW-1,NOW+120,True):
            value=active();self.blocked(value,lambda:value.check_metadata('1',SQL['1'],now))

    def test_model_has_no_network_executor_or_cli_surface(self):
        import ast
        source=(ROOT/'scripts/ci/e858_tls/control_contract.py').read_text()
        tree=ast.parse(source)
        imported={n.names[0].name for n in ast.walk(tree) if isinstance(n,ast.Import)}
        self.assertFalse(imported & {'socket','ssl','subprocess','pymysql','requests'})
        self.assertFalse(hasattr(Attempt,'terminate'))
        self.assertFalse(hasattr(Attempt,'execute'))
        import socket
        with patch.object(socket,'create_connection',side_effect=AssertionError('NETWORK_FORBIDDEN')):
            value=active();self.assertFalse(value.report()['execution_allowed'])


if __name__=='__main__':unittest.main()
