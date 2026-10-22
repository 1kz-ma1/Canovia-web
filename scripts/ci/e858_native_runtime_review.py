#!/usr/bin/env python3
"""Owner-local read-only native runtime inventory; never a real Phase1 entry.

Run only after a clean source review on a separately chosen Mac. This does not
read target/CA/password, open any terminal, access network, or grant GO.
The output is intentionally redacted, and the process exits 2 on uncertainty.
"""
import json
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))

from e858_phase1.private_native_runtime import observe_native_runtime


def main(argv=None):
    try:
        args = sys.argv[1:] if argv is None else argv
        if args:
            raise ValueError()
        value = observe_native_runtime()
        return_code = 0
    except BaseException:
        value = dict(result='INERT_NATIVE_RUNTIME_OBSERVATION_BLOCKED',
                     runtime_complete=False, execution_allowed=False,
                     live_tooling_adopted=False, real_aiven_connected=False)
        return_code = 2
    print(json.dumps(value, sort_keys=True, separators=(',', ':')))
    return return_code


if __name__ == '__main__':
    raise SystemExit(main())
