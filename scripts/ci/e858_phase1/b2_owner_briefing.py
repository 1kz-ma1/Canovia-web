"""e858 B2 owner-readable review rehearsal, NOT approval or an execution permit.

Entirely offline, no private inputs. A person may mark a scope as self-reported
read or declined; such a statement is neither owner authentication nor B2 GO.
No signer, Keychain, Secure Enclave, credential, target reader, file write,
network, clock or retry/connection API exists. Not included in the public B1
archive and not imported by the live gate.
"""
import argparse
import sys

from e858_b_offline import SOURCE, RELEASE
class Block(Exception):
    """Non-authorizing briefing format error, no private payload echo."""


KIND = 'E858_B2_HUMAN_REVIEW_BRIEFING_ONLY_V0'
MODE = 'B_HUMAN_SUPERVISED_PROCEDURAL_NO_RETRY'
STATES = ('PENDING', 'SELF_REPORTED_READ_NOT_VERIFIED', 'DECLINED')
STEPS = ('P1', 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'B2')
TOP_KEYS = frozenset(('kind', 'mode', 'scope', 'source_sha', 'release_ref',
                      'items', 'b2_fresh_owner_go', 'execution_allowed'))
ITEM_KEYS = frozenset(('step', 'title', 'meaning', 'state'))
DETAILS = (
    ('Phase1 purpose and boundary',
     'Read-only fixed Q0/Q1 and Control observation, <=2 TLS; '
     'normal 15/max 42 fixed SQL, 2s connection and 120s attempt. '
     'No Full17, application rows, DDL, GRANT or real connection in this briefing.'),
    ('Private password custody',
     'Private TTY entry keeps sensitive material in process memory; '
     'memory erasure, OS memory and recording cannot be guaranteed.'),
    ('Mac and remote-stop uncertainty',
     'Same Mac controls both TLS connections; outage may leave remote state unknown. '
     'Never repeat with a prior instruction or assume local close is remote stop.'),
    ('Failover and connection identity',
     'Proxy/failover/connection-ID reuse or changed target identity is terminal STOP. '
     'A matching claimed UUID alone is not independent identity.'),
    ('Normal close versus KILL',
     'Phase1 has no KILL permission: COM_QUIT write/local close is not a '
     'guarantee of remote query termination on failures.'),
    ('Privilege, revocation and replay gaps',
     'Unknown effective grants, changed permission, expiry or consent withdrawal '
     'means STOP. B has human no-retry, not durable rollback-resistant one-shot.'),
    ('Evidence and release separation',
     'Synthetic metadata cannot establish real trigger/index/FK, migration/restore, '
     'production release or iPhone acceptance. Never infer production GO.'),
    ('Fresh owner decision before any attempt',
     'A later separate authentic owner/operator review must bind the actual '
     'single target, approved tooling and exact runtime, CA, SQL/reference, '
     'P1/R1-R6, attempt/window and prior-attempt state. '
     'Neither this briefing nor a GitHub comment authorizes any connection.'),
)


def template():
    """Public constant briefing. Never puts private host/password/record in output."""
    return {
        'kind': KIND, 'mode': MODE, 'scope': 'phase1',
        'source_sha': SOURCE, 'release_ref': RELEASE,
        'items': [{'step': step, 'title': title, 'meaning': meaning,
                   'state': 'PENDING'}
                  for step, (title, meaning) in zip(STEPS, DETAILS)],
        'b2_fresh_owner_go': 'NOT_GRANTED',
        'execution_allowed': False,
    }


def assess(packet):
    """Strictly classify self-reported reading; always deny authority.

    This deliberately does not accept APPROVED/GO or any private data. It does
    not check a signer, authenticate a human or turn PENDING into a real consent.
    """
    try:
        if type(packet) is not dict or set(packet) != TOP_KEYS:
            raise Block()
        baseline = template()
        for name in TOP_KEYS - {'items'}:
            if type(packet[name]) is not type(baseline[name]) or packet[name] != baseline[name]:
                raise Block()
        items = packet['items']
        if type(items) is not list or len(items) != len(STEPS):
            raise Block()
        pending, declined, self_reported = [], [], []
        for i, original in enumerate(baseline['items']):
            item = items[i]
            if type(item) is not dict or set(item) != ITEM_KEYS:
                raise Block()
            if any(type(item[k]) is not str or item[k] != original[k]
                   for k in ('step', 'title', 'meaning')):
                raise Block()
            if type(item['state']) is not str or item['state'] not in STATES:
                raise Block()
            if item['state'] == 'PENDING':
                pending.append(original['step'])
            elif item['state'] == 'DECLINED':
                declined.append(original['step'])
            else:
                self_reported.append(original['step'])
        return {
            'result': 'B2_BRIEFING_SELF_REPORT_ONLY_NOT_APPROVAL',
            'pending_steps': pending,
            'declined_steps': declined,
            'self_reported_read_steps': self_reported,
            'authentic_owner_approval_verified': False,
            'operator_authentication_verified': False,
            'fresh_owner_go_granted': False,
            'independent_key_authority_verified': False,
            'durable_one_shot_verified': False,
            'execution_allowed': False,
            'real_aiven_connected': False,
            'production_go': False,
        }
    except BaseException:
        raise Block() from None


def render_read_only():
    """Static human-facing text; no external inputs or grant/GO vocabulary."""
    packet = template()
    lines = [
        '# e858 B2 — 本人確認用レビュー資料（承認ではありません）',
        '',
        '方式: B（本人監督・単発・再実行禁止）',
        '対象: 固定e858 Phase1。実接続・認証・署名・GOは未許可。',
        '資料に記載された確認内容は、実際に確認済みという意味ではありません。',
        '',
    ]
    for item in packet['items']:
        lines += [
            '## ' + item['step'] + ' — ' + item['title'],
            '確認状況: 未確認',
            item['meaning'], '',
        ]
    lines += [
        '---',
        '未確認の独立条件: 現在の実対象/CA/権限、採用済みtooling、本人と',
        'オペレーターの実認証、P1/R1〜R6受入、新しいB2個別承認、',
        '遠隔終了、再試行禁止の人的管理、必要な本番リリース審査。',
        '',
        '結果: TECHNICAL_WORK_REMAINING / REAL_AIVEN_NOT_RUN / PRODUCTION_NO_GO_HOLD',
    ]
    return '\n'.join(lines)


def authorize(*_args, **_kwargs):
    raise Block()


class NoEchoParser(argparse.ArgumentParser):
    """Suppress untrusted argument text even for malformed operator commands."""
    def error(self, _message):
        raise Block()

    def exit(self, status=0, message=None):
        raise Block()


def main(argv=None):
    parser = NoEchoParser(
        add_help=False,
        description='Print public B2 scope briefing; cannot approve or connect.')
    parser.add_argument('--print-public-briefing', action='store_true', required=True)
    try:
        parser.parse_args(argv)
        print(render_read_only())
        return 0
    except BaseException:
        # Invalid argv, including private target strings, is never echoed.
        return 2


if __name__ == '__main__':
    sys.exit(main())
