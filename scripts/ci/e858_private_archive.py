#!/usr/bin/env python3
"""Build inert public archive only. No endpoint/target/CA/password/input switch."""
import json
import os
from pathlib import Path
import sys
sys.path.insert(0,str(Path(__file__).resolve().parent))
import e858_b_offline as b
from e858_phase1.private_distribution import build, verify, _public_file, _sha, CAP


def main(argv=None):
    fd=None
    try:
        parser=b.Parser(allow_abbrev=False,add_help=False)
        for name in ('tooling-sha','wheel','output'): parser.add_argument('--'+name,required=True)
        args=parser.parse_args(argv)
        raw,pins=build(b.ROOT,args.tooling_sha,_public_file(args.wheel,131072))
        output=Path(args.output).absolute()
        if output.resolve().is_relative_to(b.ROOT.resolve()) or any(p.is_symlink() for p in output.parents): raise ValueError()
        fd=os.open(output,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|os.O_CLOEXEC,0o600)
        with os.fdopen(fd,'wb') as file:
            fd=None
            file.write(raw);file.flush();os.fsync(file.fileno())
        result=verify(_public_file(output,CAP),_sha(raw),pins);code=0
    except BaseException:
        result=b.outcome('INERT_ARCHIVE_REJECTED');code=2
    finally:
        if fd is not None:
            try: os.close(fd)
            except BaseException: pass
    print(json.dumps(result,sort_keys=True));return code


if __name__=='__main__': raise SystemExit(main())
