"""One offline application review. No opener, input, execution or GO method.

Consumes an already bound synthetic ReviewedEntry and releases it on success
or failure. This composes the real review/setup checks, not an approval record.
The future independent live activation must not use its result as authority.
"""
import hashlib

from e858_b_offline import SHA, SOURCE, RELEASE
from . import practical as p
from .private_binding import Block, HEX, Q0_SHA, Q1_SHA


def _phase1_program(entry,tooling_sha,bundle_sha256):
    """Shared pre-input checks, not an activation API or approval decision."""
    if type(entry) is not p.ReviewedEntry:raise Block()
    entry.check()
    if entry.secret is not None or type(entry.review) is not p.PrivateReview:raise Block()
    private=entry.review.check();program=entry.review.program
    if (private['scope']!='phase1' or type(program) is not p.Program
            or program.pins['source_sha']!=SOURCE or program.pins['release_ref']!=RELEASE
            or program.pins['tooling_sha']!=tooling_sha or program.sha!=bundle_sha256):raise Block()
    return program


class Phase1ApplicationReview:
    """Transfer one already acquired entry; public pins are not credentials.

    No private-path reader, trusted-TTY acquisition, credential capture, driver
    factory, SQL or socket is introduced. Existing entry/review checks own those
    private observations; caller/person/provenance remain external trust gates.
    This is deliberately not accepted by Adapter/PrivateWire as an execution
    permit. There is no run/connect/activate method, flag or alternate backend.
    """
    def __init__(self,entry,tooling_sha,bundle_sha256):
        self.entry=None;self.used=False
        try:
            if type(entry) is not p.ReviewedEntry:raise Block()
            self.entry=entry
            if (type(tooling_sha) is not str or not SHA.fullmatch(tooling_sha)
                    or type(bundle_sha256) is not str or not HEX.fullmatch(bundle_sha256)):raise Block()
            self.tooling_sha=tooling_sha;self.bundle_sha256=bundle_sha256
        except BaseException:
            try:self.close()
            finally:raise Block() from None

    def review(self):
        engine=None
        try:
            if self.used or self.entry is None:raise Block()
            self.used=True
            entry=self.entry
            _phase1_program(entry,self.tooling_sha,self.bundle_sha256)
            # Reuse the same fixed-target identity validator and sealed engine
            # setup as the owned fixture. It cannot capture or connect. The
            # default Adapter.prepare()/PrivateWire.connect() stay unchanged.
            engine=p.Adapter(entry,'phase1')
            config,bundle=engine.prepare_reviewed()
            if (bundle or hashlib.sha256(config['q0']).hexdigest()!=Q0_SHA
                    or hashlib.sha256(config['q1']).hexdigest()!=Q1_SHA):raise Block()
            entry.check()
            return dict(result='PHASE1_APPLICATION_REVIEWED_OFFLINE',scope='phase1',
                source_sha=SOURCE,tooling_sha=self.tooling_sha,bundle_sha256=self.bundle_sha256,
                sql_sent=0,tls_connections=0,credentials_read=False,execution_allowed=False,
                owner_approval_verified=False,live_tooling_adopted=False,b2_owner_go=False,
                effective_principal='REAL_SESSION_UNVERIFIED',
                remote_termination='REMOTE_TERMINATION_UNVERIFIED',
                real_aiven_connected=False,production_go=False)
        except BaseException:raise Block() from None
        finally:
            okay=True
            if engine is not None and engine.validator is not None:
                try:okay=engine.validator.close() and okay
                except BaseException:okay=False
            try:self.close()
            except BaseException:okay=False
            if not okay:raise Block() from None

    def close(self):
        self.used=True
        entry=self.entry;self.entry=None
        if entry is not None:
            try:entry.close()
            except BaseException:raise Block() from None

    def __repr__(self):return '<Phase1 offline application review; no execution authority>'
