#!/usr/bin/env python3
"""Reuse Full94 B1 lifecycle; only synthetic worker/principal/package differ."""
import hashlib
import json
import os
from pathlib import Path
import sys
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parent))
import e858_tls_host as h
import e858_full94_tls as full
import e858_b1_host as previous
from e858_phase1 import b1_package
from e858_phase1_host import mysql

original_run=h.run


def exercise(work,stem,token,network,image,owned,head,series):
    def run(argv,*args,**kwargs):
        if argv[-1:]!=['e858_phase1.b1_fixtures']:return original_run(argv,*args,**kwargs)
        # Fixture-only principal is deliberately different from #829's driver
        # constant. The generic engine must use the sealed private target.
        mysql(stem+'-server',"CREATE USER 'e858_review_inspector'@'%' IDENTIFIED BY 'ci-only-tls-inspector' REQUIRE SSL;\n"
            "GRANT REFERENCES ON canovia_p0_ci.* TO 'e858_review_inspector'@'%';\n"
            "GRANT SELECT ON canovia_p0_ci.migrations TO 'e858_review_inspector'@'%';")
        archive,pins=b1_package.build(h.ROOT,head,(work/'build/pymysql-1.1.2-py3-none-any.whl').read_bytes())
        client=work/'client';(client/'b1-candidate.zip').write_bytes(archive)
        config=json.loads((client/'phase1.json').read_text())
        namespace=dict(kind='OWNED_INTERNAL_DOCKER_ONLY',pins=pins,series=series,bundle_sha256=hashlib.sha256(archive).hexdigest(),
            owned_ips=[config['r1']['ipv4']],ca_hashes=[hashlib.sha256((client/name).read_bytes()).hexdigest() for name in ('ca.pem','wrong-ca.pem')])
        (client/'practical.json').write_text(json.dumps(namespace))
        argv=list(argv);argv[-1]='e858_phase1.practical_fixture'
        return original_run(argv,*args,**kwargs)
    with patch.object(h,'run',side_effect=run):
        return previous.exercise(work,stem,token,network,image,owned,head,series)


def build_without_import_caches(argv,*args,**kwargs):
    # Only a generated disposable Dockerfile, not the accepted old host source.
    if argv[:2]==['docker','build']:
        file=Path(argv[-1])/'Dockerfile'
        raw=file.read_text()
        if 'pip install --no-index /tmp/*.whl' in raw:
            file.write_text(raw.replace('pip install --no-index /tmp/*.whl','pip install --no-index --no-compile /tmp/*.whl'))
    return original_run(argv,*args,**kwargs)


if __name__=='__main__':
    with patch.object(h,'run',side_effect=build_without_import_caches),patch.object(full,'exercise',exercise):
        raise SystemExit(h.main(profile='full94'))
