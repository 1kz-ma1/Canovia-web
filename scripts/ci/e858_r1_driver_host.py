#!/usr/bin/env python3
"""Fixed owned-Docker R1 profile; the existing parser takes series/SHA only."""
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parent))
import e858_phase1_host

if __name__ == '__main__':
    raise SystemExit(e858_phase1_host.main('r1-driver'))
