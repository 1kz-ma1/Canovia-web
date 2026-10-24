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
ITEM_KEYS = frozenset(('step', 'title', 'meaning', 'risk', 'stop', 'state'))

# Fixed PUBLIC Japanese copy. Never insert a target, credential, hostname,
# private operator fact, signed authorization, or discovered remote status.
# The risk and stop columns are explanatory: no control is being executed.
DETAILS = (
    ('Phase1の目的と読み取り範囲',
     '固定e858候補のQ0/Q1・Control観測と限定的なmetadata確認だけを対象とする。'
     'TLS接続は最大2本、固定SQLは通常15件・最大42件、接続上限2秒・全体120秒。'
     'Full17、利用者データ行、DDL、GRANTは対象外。',
     '想定外のSQLや取得範囲の拡大により、機密性・可用性・監査の信頼性を損なうおそれがある。',
     '固定SQL・対象・時間・件数・読み取り範囲のいずれかが不一致なら開始せず中止する。'
     '既存の承認やこの資料だけで範囲を広げない。'),
    ('認証情報の保護',
     '実行時のパスワードは私的な端末入力（TTY）を前提とし、公開Issue・ログ・'
     'コマンド引数・環境変数へ記載しない。',
     'プロセスメモリやOSの記録から情報が完全消去される保証はない。'
     '誤った入力経路や画面共有でも漏えいし得る。',
     '入力先が不明・表示された・記録された疑いがあれば操作を停止する。'
     '秘密そのものをチャットやGitHubへ貼らず、個別の安全な取扱いを確認する。'),
    ('Macの異常終了と遠隔実行状態',
     '1台のMacがControl/Data接続を管理する。Mac側が停止しても、'
     'DB側の問い合わせが終了したとは限らない。',
     '通信切断・タイムアウト・Mac再起動後に遠隔で処理が続くかもしれない。',
     '再接続・自動再実行を行わず、状態をREMOTE_TERMINATION_UNVERIFIEDとして扱う。'
     '独立した遠隔確認が必要な場合は私的な管理者手順へ引き継ぐ。'),
    ('接続先識別とフェイルオーバー',
     '対象のサービス・DB・接続先・CA・現在の監査権限を私的に別経路で照合し、'
     'TLSのホスト名・証明書とセッション識別の整合を確認する設計である。',
     'プロキシ・切替・接続IDの再利用により、想定と違うDBへ到達する可能性がある。'
     'サーバUUIDの自己申告だけでは接続先の独立証明にならない。',
     'DNS/TLS/CA/ホスト名/DB/利用者/サーバ識別が不一致・不明なら中止する。'
     'TLSの検証緩和や別サーバへの自動切替はしない。'),
    ('正常終了と強制終了の違い',
     '今回の監査用principalには他セッションをKILLする権限を追加しない。'
     'COM_QUIT送信・ローカルcloseと遠隔問い合わせの終了確認は別の事実である。',
     '送信・切断ができても、遠隔クエリが終了した証明にならない場合がある。',
     '遠隔終了を独立して確認できなければ不明として停止する。'
     '権限追加、独断のKILL、古いGOによる再取得はしない。'),
    ('最小権限と再実行防止の限界',
     '対象は既存の限定監査権限と有効期間を前提とする。'
     'B方式は本人が1回だけ監督し、失敗や中断後は同じ許可で再試行しない。',
     '権限変更・同意撤回・期限切れのほか、端末の複製や保存状態の巻戻しでは'
     '人的な再実行防止を技術的に破り得る。',
     '権限・期限・過去試行・本人意思が不明なら停止する。'
     '失敗・中断・再起動後は新しい個別承認なしに再試行しない。'),
    ('証拠と本番リリースの分離',
     'Phase1の限定metadata確認は、物理INDEX/FK/TRIGGERの完全性、'
     'migration適用・実バックアップ復元・Web/iPhone受入を証明しない。',
     '限定監査や合成テストの成功を本番公開の安全確認と誤認するおそれがある。',
     '未確認の独立証拠を未確認のまま残し、DDL・DB移行・production統合・'
     'Renderデプロイを別の承認なしに進めない。'),
    ('実接続直前の新しい本人判断',
     '将来の別工程で、本人と実行者を確認し、固定source/release・採用tooling・'
     'SQL/reference・単一の対象/CA/権限・試行ID・UTC有効期間・P1/R1〜R6の受入・'
     '既存試行状態を照合して初めて個別判断する。',
     '以前の「進めて」、この資料の表示・自己申告・GitHubコメント・合成署名を'
     '実Aiven監査の許可と取り違える危険がある。',
     '独立照合や明示の新規許可が一つでも欠ければ実接続を開始しない。'
     'この資料とコードの現時点では常にNO-GOとする。'),
)

def template():
    """Public constant briefing. Never puts private host/password/record in output."""
    return {
        'kind': KIND, 'mode': MODE, 'scope': 'phase1',
        'source_sha': SOURCE, 'release_ref': RELEASE,
        'items': [{'step': step, 'title': title, 'meaning': meaning,
                   'risk': risk, 'stop': stop, 'state': 'PENDING'}
                  for step, (title, meaning, risk, stop) in zip(STEPS, DETAILS)],
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
                   for k in ('step', 'title', 'meaning', 'risk', 'stop')):
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
        '以下は一般的な停止・判断基準の説明であり、実際の機器・接続・本人を検証しません。',
        '確認状況はすべて未確認。表示や自己申告を承認に変換できません。',
        '',
    ]
    for item in packet['items']:
        lines += [
            '## ' + item['step'] + ' — ' + item['title'],
            '確認状況: 未確認',
            '確認すること: ' + item['meaning'],
            '起こり得るリスク: ' + item['risk'],
            '不一致・異常時の対応: ' + item['stop'], '',
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
