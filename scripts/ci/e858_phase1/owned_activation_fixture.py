"""OWNED SYNTHETIC Phase1 activation seam, EXCLUDED from the public B1 bundle.

This is an executable *fixture boundary* for isolated Docker only. It exercises
the same reviewed launcher/order controls used by the dormant candidate, but
has no target/credential CLI, permit loader, external DNS, owner GO, or live
activation API. It cannot authorize an Aiven connection. Never ship this in
the B1 public review archive or use it as a B2 approval credential.
"""
from .launcher_candidate import LauncherCandidate
from .private_binding import Block
from .protocol import Gate
from . import practical as p, runtime as r


class OwnedPhase1Activation:
    """Owned test-namespace admission, once per entry, with fail-closed stop."""

    def __init__(self, entry, fixture):
        self._launcher = None
        try:
            if (type(entry) is not p.ReviewedEntry or type(fixture) is not dict
                    or fixture.get('kind') != 'OWNED_INTERNAL_DOCKER_ONLY'
                    or not isinstance(fixture.get('owned_ips'), list)
                    or not fixture['owned_ips']
                    or type(fixture.get('pins')) is not dict
                    or type(fixture.get('bundle_sha256')) is not str):
                raise Block()
            target = entry.target
            if (target is None
                    or target.host not in ('mysql.synthetic.invalid', 'mismatch.synthetic.invalid')
                    or target.port != 3306 or target.database != r.DB
                    or target.principal != 'e858_review_inspector'
                    or entry.review is None
                    or entry.review.check()['scope'] != 'phase1'):
                raise Block()
            self._launcher = LauncherCandidate(
                entry, fixture['pins']['tooling_sha'], fixture['bundle_sha256'])
        except BaseException:
            self.close()
            raise Block() from None

    def before_input(self):
        try:
            if self._launcher is None:
                raise Block()
            self._launcher._before_input()
        except BaseException:
            self.close()
            raise Block() from None

    def before_connection(self, gate):
        try:
            if self._launcher is None or type(gate) is not Gate:
                raise Block()
            self._launcher._connection(gate)
        except BaseException:
            self.close()
            raise Block() from None

    @property
    def state(self):
        return self._launcher.state if self._launcher is not None else 'STOPPED'

    @property
    def entry(self):
        return self._launcher.entry if self._launcher is not None else None

    def run(self):
        """No generic activation: fixture's existing sealed driver owns I/O."""
        self.close()
        raise Block()

    def close(self):
        if self._launcher is not None:
            self._launcher.close()
            self._launcher = None

    def __repr__(self):
        return '<owned synthetic Phase1 activation; no live authority>'
