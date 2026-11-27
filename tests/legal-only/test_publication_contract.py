"""Offline Gate S positive/negative tests. This suite cannot verify Render or physical Origin isolation."""
import importlib.util
from pathlib import Path
import os
import shutil
import subprocess
import sys
import tempfile
import json
import uuid
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('legal_gate_s', ROOT / 'scripts/ci/legal_only_static_build.py')
g = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(g)


def fixture():
    return {name: (ROOT / path).read_bytes() for name, path in g.PUBLIC.items()}


def git(repo, *args):
    return subprocess.check_output(['git', *args], cwd=repo, stderr=subprocess.DEVNULL).decode().strip()


class StaticBoundary(unittest.TestCase):
    def test_01_b25_fixed_identity(self):
        g.inventory_identity()
        self.assertEqual(len(g.B_INVENTORY), 25)
        self.assertEqual(g.sha(g.canonical(g.B_INVENTORY)), g.B_INVENTORY_SHA256)

    def test_02_exact_four_sources_and_404_exception(self):
        g.check_payload(fixture())
        self.assertEqual(set(g.PUBLIC) & set(g.B_INVENTORY), {'404.html'})
        self.assertEqual(len(set(g.B_INVENTORY) - set(g.PUBLIC)), 24)
        self.assertNotEqual(g.sha(fixture()['404.html']), g.B_404_SHA256)
        self.assertEqual(g.PUBLIC['404.html'], 'legal-only/public/404.html')
        self.assertNotIn('preparation-local/404.html', g.PUBLIC.values())

    def test_03_extra_b24_asset_denied(self):
        a = fixture(); a['src/app.mjs'] = b'bad'
        with self.assertRaisesRegex(ValueError, 'EXTRA_OR_MISSING_ASSET'): g.check_payload(a)

    def test_04_missing_denied(self):
        a = fixture(); del a['legal/legal.css']
        with self.assertRaisesRegex(ValueError, 'EXTRA_OR_MISSING_ASSET'): g.check_payload(a)

    def test_05_manifest_or_receipt_in_public_denied(self):
        for extra in ('manifest.json', 'receipt.json', '_headers', 'old/build.js'):
            with self.subTest(extra=extra):
                a = fixture(); a[extra] = b'bad'
                with self.assertRaisesRegex(ValueError, 'EXTRA_OR_MISSING_ASSET'): g.check_payload(a)

    def test_06_absolute_traversal_duplicate_and_ambiguous_names(self):
        for p in ('/privacy.html', '../legal/privacy.html', 'legal/../privacy.html',
                  'legal//privacy.html', 'legal/./privacy.html', 'legal\\privacy.html',
                  'legal/%2e%2e/privacy.html', './legal/privacy.html'):
            with self.subTest(path=p):
                with self.assertRaisesRegex(ValueError, 'NONCANONICAL_PATH'): g.canonical_path(p)
        with self.assertRaisesRegex(ValueError, 'DUPLICATE_PATH'):
            g.check_paths(list(g.PUBLIC) + ['404.html'])

    def test_07_b404_reuse_denied(self):
        a = fixture(); a['404.html'] = b'not the original'
        with patch.object(g, 'sha', return_value=g.B_404_SHA256):
            with self.assertRaisesRegex(ValueError, 'B_404_REUSED'):
                g.check_asset('404.html', a['404.html']) if g.SYNTHETIC_MARKER in a['404.html'].decode() else g.check_asset('404.html', fixture()['404.html'])

    def test_08_missing_synthetic_marker_denied(self):
        a = fixture(); a['legal/privacy.html'] = a['legal/privacy.html'].replace(g.SYNTHETIC_MARKER.encode(), b'PUBLIC')
        with self.assertRaisesRegex(ValueError, 'NON_SYNTHETIC_ASSET'): g.check_payload(a)

    def test_09_script_and_form_denied(self):
        a = fixture()
        for tag in ('<script></script>', '<form></form>', '<iframe></iframe>', '<input>', '<object></object>'):
            with self.subTest(tag=tag):
                b = a['legal/privacy.html'].replace(b'</body>', tag.encode() + b'</body>')
                with self.assertRaisesRegex(ValueError, 'UNAPPROVED_HTML_TAG'): g.check_asset('legal/privacy.html', b)

    def test_10_external_and_event_handler_denied(self):
        a = fixture()['legal/privacy.html']
        for replacement in (b'https://example.com', b'//external.example', b'javascript:alert(1)'):
            with self.subTest(replacement=replacement):
                b = a.replace(b'/support', replacement)
                with self.assertRaisesRegex(ValueError, 'UNAPPROVED_HTML_LINK'): g.check_asset('legal/privacy.html', b)
        b = a.replace(b'<main>', b'<main onclick="evil()">')
        with self.assertRaisesRegex(ValueError, 'UNAPPROVED_HTML_ATTRIBUTE'): g.check_asset('legal/privacy.html', b)

    def test_11_external_css_denied(self):
        for resource in ('@import "https://example.com";', 'body{background:url(https://example.com/x)}', 'a{width:expression(alert(1))}'):
            with self.subTest(resource=resource):
                with self.assertRaisesRegex(ValueError, 'UNAPPROVED_CSS_RESOURCE'):
                    g.check_asset('legal/legal.css', fixture()['legal/legal.css'] + resource.encode())

    def test_12_synthetic_route_positive(self):
        for method in ('GET', 'HEAD'):
            for path,expected in (('/privacy','legal/privacy.html'),('/support','legal/support.html'),('/legal/privacy.html','legal/privacy.html'),('/legal/support.html','legal/support.html'),('/legal/legal.css','legal/legal.css'),('/404.html','404.html')):
                self.assertEqual(g.synthetic_route(method,'start.rpf-canovia.com',path),(200,expected))

    def test_13_synthetic_route_unknown_negative(self):
        for path in ('/', '/index.html', '/src/app.mjs', '/api/data', '/login', '/privacy/', '/Privacy', '/privacy%2F', '/support/..', '/legal/../404.html'):
            with self.subTest(path=path):
                self.assertEqual(g.synthetic_route('GET','start.rpf-canovia.com',path)[0],404)
        for query in ('a=1', '?', 'v=1'):
            self.assertEqual(g.synthetic_route('GET','start.rpf-canovia.com','/privacy',query)[0],404)
        self.assertEqual(g.synthetic_route('GET','evil.example','/privacy')[0],421)
        self.assertEqual(g.synthetic_route('POST','start.rpf-canovia.com','/privacy')[0],405)

    def test_14_publish_tree_extras_and_special_files(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)
            for name,data in fixture().items():
                dest=p/name;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data)
            self.assertEqual(set(g.check_regular_tree(p)),set(g.PUBLIC))
            (p/'old.js').write_text('old')
            with self.assertRaisesRegex(ValueError,'EXTRA_OR_MISSING_ASSET'):g.check_regular_tree(p)
            (p/'old.js').unlink()
            (p/'legal/legal.css').unlink()
            with self.assertRaisesRegex(ValueError,'EXTRA_OR_MISSING_ASSET'):g.check_regular_tree(p)

    def test_15_symlink_hardlink_fifo_denied(self):
        for mode in ('symlink','hardlink','fifo'):
            with self.subTest(mode=mode),tempfile.TemporaryDirectory() as d:
                root=Path(d)/'site';root.mkdir();(root/'legal').mkdir()
                for n,v in fixture().items():
                    (root/n).write_bytes(v)
                target=root/'legal/legal.css';target.unlink()
                source=Path(d)/'external.css';source.write_bytes(fixture()['legal/legal.css'])
                try:
                    if mode=='symlink': target.symlink_to(source)
                    elif mode=='hardlink': os.link(source,target)
                    else:
                        if not hasattr(os,'mkfifo'): self.skipTest('no FIFO')
                        os.mkfifo(target)
                except (OSError,NotImplementedError) as exc:self.skipTest(str(exc))
                with self.assertRaisesRegex(ValueError,'LINK_OR_SPECIAL_FILE'):g.check_regular_tree(root)

    def test_16_external_expected_schema_and_security_flags(self):
        with self.assertRaisesRegex(ValueError, 'EXPECTED_SCHEMA_INVALID'):
            g.validate_expected({'run_id': 'RUN1234'})
        with self.assertRaisesRegex(ValueError, 'INDEPENDENT_EXPECTED_REQUIRED'):
            g.build(ROOT, '0'*40, '0'*40, g.B_INVENTORY_SHA256, 'RUN1234',
                    '/tmp/not-an-output', 'synthetic')


class IsolatedBuild(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='canovia-gate-s-')
        cls.root=Path(cls.temp.name)
        cls.repo=cls.root/'synthetic-source';cls.repo.mkdir()
        cls.isolation_root=cls.root/'private-isolation';cls.isolation_root.mkdir(mode=0o700)
        for name in g.INPUTS:
            dest=cls.repo/name;dest.parent.mkdir(parents=True,exist_ok=True)
            shutil.copyfile(ROOT/name,dest)
        git(cls.repo,'init','-q')
        git(cls.repo,'add','--',*g.INPUTS)
        subprocess.check_call(['git','-c','user.email=synthetic@localhost','-c','user.name=Gate S Synthetic','commit','-qm','synthetic only'],cwd=cls.repo)
        cls.head=git(cls.repo,'rev-parse','HEAD')
        cls.tree=git(cls.repo,'rev-parse','HEAD^{tree}')

    @classmethod
    def tearDownClass(cls):cls.temp.cleanup()

    def expected(self, name, run_id='SYNTH-0001', attempt_id=None, **overrides):
        attempt_id = attempt_id or uuid.uuid4().hex
        x = dict(kind='CANOVIA_GATE_S_EXTERNAL_EXPECTED_V1',
                 source_sha=self.head, source_tree=self.tree,
                 inputs_sha256={k:g.sha((self.repo/k).read_bytes()) for k in g.INPUTS},
                 files_sha256=dict(g.FIXED_PUBLIC_SHA256), files_bytes=dict(g.FIXED_PUBLIC_BYTES),
                 run_id=run_id, attempt_id=attempt_id,
                 isolation_root=str(self.isolation_root), out_root=str(self.isolation_root/name),
                 b25_inventory_sha256=g.B_INVENTORY_SHA256,
                 synthetic_only=True, not_deployable=True, release_approved=False)
        x.update(overrides)
        return x

    def run_build(self,name='candidate',**kwargs):
        expected = kwargs.pop('expected', None)
        args=dict(repo=self.repo, source_sha=self.head, source_tree=self.tree,
                  inventory_sha=g.B_INVENTORY_SHA256,run_id='SYNTH-0001',
                  out_root=self.isolation_root/name, mode='synthetic',
                  isolation_root=str(self.isolation_root))
        args.update(kwargs)
        expected = expected if expected is not None else self.expected(name, run_id=args['run_id'])
        args['expected'] = expected
        args['attempt_id'] = kwargs.get('attempt_id', expected['attempt_id'])
        return g.build(**args)

    def assert_verified(self, name, manifest, receipt, expected, exit_code=0, exited=True):
        observed = dict(attempt_id=expected['attempt_id'], run_id=expected['run_id'],
                        source_sha=expected['source_sha'], source_tree=expected['source_tree'],
                        out_root=expected['out_root'], exit_code=exit_code, process_exited=exited)
        return g.verify_receipt(manifest, receipt, expected, exit_code, exited,
                                observed_attempt=observed, repo=self.repo)

    def test_17_fresh_synthetic_build(self):
        manifest,receipt=self.run_build('successful')
        root=self.isolation_root/'successful';publish=root/'.render-legal-only'
        self.assertEqual(set(g.check_regular_tree(publish)),set(g.PUBLIC))
        self.assertEqual(len(manifest['files_sha256']),4)
        self.assertEqual(manifest['source_sha'],self.head)
        self.assertEqual(manifest['source_tree'],self.tree)
        self.assertEqual(manifest['b25_inventory_sha256'],g.B_INVENTORY_SHA256)
        self.assertTrue(manifest['synthetic_only'] and manifest['not_deployable'])
        self.assertTrue(receipt['synthetic_only'] and receipt['not_deployable'])
        self.assertEqual(receipt['render_deploy'],'NOT_RUN')
        self.assertFalse((publish/'manifest.json').exists())
        self.assertFalse((publish/'receipt.json').exists())
        self.assertEqual(g.sha((root/'evidence/manifest.json').read_bytes()),receipt['manifest_sha256'])
        self.assertEqual(set(p.relative_to(publish).as_posix() for p in publish.rglob('*') if p.is_file()),set(g.PUBLIC))

    def test_18_public_mode_denied(self):
        with self.assertRaisesRegex(ValueError,'PUBLICATION_MODE_FORBIDDEN'):self.run_build('public',mode='public')
        self.assertFalse((self.isolation_root/'public').exists())

    def test_19_wrong_source_and_b25_hash_denied(self):
        for overrides in ({'source_sha':'0'*40},{'source_tree':'0'*40},{'inventory_sha':'0'*64}):
            with self.subTest(overrides=overrides):
                with self.assertRaises(ValueError):self.run_build('wrong'+str(len(list(self.root.iterdir()))),**overrides)

    def test_20_old_output_never_reused(self):
        self.run_build('first')
        with self.assertRaisesRegex(ValueError,'NEW_ISOLATED_OUTPUT_REQUIRED'):self.run_build('first')

    def test_21_failure_has_no_success_receipt(self):
        with patch.object(g,'check_regular_tree',side_effect=ValueError('INJECTED_AFTER_WRITE')):
            with self.assertRaisesRegex(ValueError,'INJECTED_AFTER_WRITE'):self.run_build('crash')
        self.assertFalse((self.isolation_root/'crash/evidence/receipt.json').exists())
        with self.assertRaisesRegex(ValueError,'NEW_ISOLATED_OUTPUT_REQUIRED'):self.run_build('crash')

    def test_22_source_copy_mutation_denied(self):
        target=self.repo/g.PUBLIC['legal/privacy.html']
        old=target.read_bytes()
        try:
            target.write_bytes(old+b'bad')
            with self.assertRaisesRegex(ValueError,'SOURCE_NOT_CLEAN'):self.run_build('dirty')
        finally:target.write_bytes(old)

    def test_23_source_symlink_denied(self):
        target=self.repo/g.PUBLIC['legal/legal.css'];external=self.root/'symlink-src.css'
        external.write_bytes(target.read_bytes());target.unlink()
        try:
            target.symlink_to(external)
            with self.assertRaises(ValueError):self.run_build('src-link')
        finally:target.unlink();target.write_bytes(external.read_bytes())

    def test_24_invalid_run_id_denied(self):
        for bad in ('../pass','', 'a', 'space here'):
            with self.subTest(bad=bad):
                with self.assertRaisesRegex(ValueError,'(RUN_ID_INVALID|EXPECTED_RUN_INVALID)'):self.run_build('runid',run_id=bad)

    def test_25_evidence_is_bound_to_exact_run(self):
        m,r=self.run_build('evidence',run_id='GATES20261127')
        expected=dict(self._last_expected) if hasattr(self, '_last_expected') else self.expected('evidence',run_id='GATES20261127')
        # Existing test retains run-ID check; caller must supply the *same* attempt.
        expected.update(run_id='GATES20261127', attempt_id=m['attempt_id'])
        self.assert_verified('evidence',m,r,expected)
        with self.assertRaisesRegex(ValueError,'CURRENT_ATTEMPT_SOURCE_OR_LOCATION_MISMATCH'):
            self.assert_verified('evidence',m,r,{**expected,'run_id':'DIFFERENT0001'})

    def test_26_receipt_source_sha_tamper(self):
        e=self.expected('p101-sha');m,r=self.run_build('p101-sha', expected=e)
        with self.assertRaisesRegex(ValueError,'CURRENT_ATTEMPT_SOURCE_OR_LOCATION_MISMATCH'):
            self.assert_verified('p101-sha',m,{**r,'source_sha':'f'*40},e)

    def test_27_manifest_receipt_tree_mismatch(self):
        e=self.expected('p101-tree');m,r=self.run_build('p101-tree',expected=e)
        with self.assertRaisesRegex(ValueError,'CURRENT_ATTEMPT_SOURCE_OR_LOCATION_MISMATCH'):
            self.assert_verified('p101-tree',{**m,'source_tree':'0'*40},r,e)
        with self.assertRaisesRegex(ValueError,'CURRENT_ATTEMPT_SOURCE_OR_LOCATION_MISMATCH'):
            self.assert_verified('p101-tree',m,{**r,'source_tree':'0'*40},e)

    def test_28_input_hash_tamper(self):
        e=self.expected('p101-input');m,r=self.run_build('p101-input',expected=e)
        modified=dict(m['inputs_sha256']);modified[g.INPUTS[0]]='1'*64
        with self.assertRaisesRegex(ValueError,'EVIDENCE_EXPECTED_HASH_MISMATCH'):
            self.assert_verified('p101-input',{**m,'inputs_sha256':modified},r,e)
        wrong=dict(e['inputs_sha256']);wrong[g.INPUTS[1]]='0'*64
        with self.assertRaisesRegex(ValueError,'INDEPENDENT_INPUT_HASH_MISMATCH'):
            self.run_build('p101-reject-hash',expected={**self.expected('p101-reject-hash'),'inputs_sha256':wrong})

    def test_29_output_extra_missing_and_byte_tamper(self):
        for name,mutate in (('p101-extra','extra'),('p101-missing','missing'),('p101-byte','byte')):
            with self.subTest(mutate=mutate):
                e=self.expected(name);m,r=self.run_build(name,expected=e)
                site=self.isolation_root/name/'.render-legal-only'
                if mutate=='extra':(site/'old.js').write_text('bad')
                if mutate=='missing':(site/'404.html').unlink()
                if mutate=='byte':(site/'legal/legal.css').write_bytes(fixture()['legal/legal.css']+b'x')
                with self.assertRaises(ValueError):self.assert_verified(name,m,r,e)

    def test_30_safety_flags_missing_or_changed(self):
        e=self.expected('p101-flags');m,r=self.run_build('p101-flags',expected=e)
        for key,value in (('synthetic_only',False),('not_deployable',False),('release_approved',True)):
            with self.subTest(key=key):
                with self.assertRaises(ValueError):self.assert_verified('p101-flags',{**m,key:value},r,e)
                with self.assertRaises(ValueError):self.assert_verified('p101-flags',m,{**r,key:value},e)
        with self.assertRaisesRegex(ValueError,'EVIDENCE_SCHEMA_MISMATCH'):
            m2=dict(m);del m2['synthetic_only'];self.assert_verified('p101-flags',m2,r,e)

    def test_31_same_run_id_old_attempt_after_b_failure(self):
        a=self.expected('p102-a',run_id='SAME-RUN-1234')
        old_m,old_r=self.run_build('p102-a',run_id='SAME-RUN-1234',expected=a)
        self.assert_verified('p102-a',old_m,old_r,a)
        b=self.expected('p102-b',run_id='SAME-RUN-1234')
        with patch.object(g,'check_regular_tree',side_effect=ValueError('SIMULATED_B_FAILURE')):
            with self.assertRaisesRegex(ValueError,'SIMULATED_B_FAILURE'):
                self.run_build('p102-b',run_id='SAME-RUN-1234',expected=b)
        with self.assertRaises(ValueError):self.assert_verified('p102-b',old_m,old_r,b,exit_code=2)
        with self.assertRaisesRegex(ValueError,'CURRENT_ATTEMPT_SOURCE_OR_LOCATION_MISMATCH'):
            self.assert_verified('p102-b',old_m,old_r,b)
        self.assertFalse((self.isolation_root/'p102-b/evidence/receipt.json').exists())

    def test_32_old_output_region_not_current(self):
        a=self.expected('p102-old');m,r=self.run_build('p102-old',expected=a)
        b=self.expected('p102-new',run_id=a['run_id'])
        with self.assertRaisesRegex(ValueError,'CURRENT_ATTEMPT_SOURCE_OR_LOCATION_MISMATCH'):
            self.assert_verified('p102-new',m,r,b)

    def test_33_exit_result_mandatory_and_external(self):
        e=self.expected('p102-exit');m,r=self.run_build('p102-exit',expected=e)
        for result,ended in ((None,None),(1,True),(2,True),(0,False),(False,True)):
            with self.subTest(result=result,ended=ended):
                with self.assertRaisesRegex(ValueError,'CURRENT_ATTEMPT_NOT_SUCCESSFULLY_EXITED'):
                    self.assert_verified('p102-exit',m,r,e,exit_code=result,exited=ended)

    def test_34_symlink_parent_into_repository_denied(self):
        link=self.root/'isolation-symlink'
        link.symlink_to(self.repo,target_is_directory=True)
        e=self.expected('p103-symlink',isolation_root=str(link),out_root=str(link/'p103-symlink'))
        with self.assertRaisesRegex(ValueError,'ISOLATION_ANCESTOR_NOT_DIRECTORY'):
            self.run_build('p103-symlink',expected=e,isolation_root=str(link),out_root=link/'p103-symlink')
        self.assertFalse((self.repo/'p103-symlink').exists())

    def test_35_outside_and_dotdot_path_denied(self):
        name='p103-outside'
        for out in (str(self.root/'outside'), str(self.isolation_root/'..'/'outside'), str(self.repo/'bad')):
            with self.subTest(out=out):
                e=self.expected(name,out_root=out)
                with self.assertRaises(ValueError):self.run_build(name,expected=e,out_root=out)
        self.assertFalse((self.repo/'bad').exists())

    def test_36_css_escape_denied(self):
        original=fixture()['legal/legal.css']
        attacks=(br'body{background: u\72l(https://evil.example/)}',br'@im\70ort "https://evil.example/";')
        for attack in attacks:
            with self.subTest(attack=attack):
                with self.assertRaisesRegex(ValueError,'UNAPPROVED_CSS_RESOURCE'):
                    g.check_asset('legal/legal.css',original+attack)

    def test_37_image_set_string_url_denied(self):
        raw=fixture()['legal/legal.css']+b'body{background:image-set("https://evil.example/p.png" 1x)}'
        with self.assertRaisesRegex(ValueError,'UNAPPROVED_CSS_RESOURCE'):
            g.check_asset('legal/legal.css',raw)

    def test_38_wrong_output_inode_denied(self):
        e=self.expected('p103-identity');m,r=self.run_build('p103-identity',expected=e)
        old=self.isolation_root/'p103-identity'
        shifted=self.isolation_root/'p103-identity-old'
        old.rename(shifted)
        shutil.copytree(shifted,old)
        with self.assertRaisesRegex(ValueError,'CURRENT_OUTPUT_IDENTITY_MISMATCH'):
            self.assert_verified('p103-identity',m,r,e)

    def test_39_expected_file_and_real_subprocess_exit(self):
        e=self.expected('p101-cli')
        external=self.root/'reviewer-pinned-expected.json'
        external.write_bytes(g.canonical(e))
        args=[sys.executable,str(self.repo/'scripts/ci/legal_only_static_build.py'),
              '--mode','synthetic','--source-sha',e['source_sha'],
              '--source-tree',e['source_tree'],'--b-inventory-sha256',g.B_INVENTORY_SHA256,
              '--run-id',e['run_id'],'--attempt-id',e['attempt_id'],
              '--isolation-root',e['isolation_root'],'--out-root',e['out_root'],
              '--expected-file',str(external)]
        completed=subprocess.run(args,cwd=self.repo,capture_output=True,text=True,timeout=20)
        self.assertEqual(completed.returncode,0,completed.stderr)
        site=self.isolation_root/'p101-cli'/'evidence'
        m=json.loads((site/'manifest.json').read_bytes());r=json.loads((site/'receipt.json').read_bytes())
        self.assert_verified('p101-cli',m,r,e,exit_code=completed.returncode,exited=True)
        self.assertIn('CANDIDATE_REQUIRES_INDEPENDENT',completed.stdout)
        self.assertFalse((self.isolation_root/'p101-cli/.render-legal-only/manifest.json').exists())

    def test_41_no_supervisor_observation_is_not_success(self):
        e=self.expected('p102-observation');m,r=self.run_build('p102-observation',expected=e)
        with self.assertRaisesRegex(ValueError,'EXTERNAL_EXECUTION_OBSERVATION_REQUIRED'):
            g.verify_receipt(m,r,e,0,True,repo=self.repo)
        wrong=dict(attempt_id='f'*32,run_id=e['run_id'],source_sha=e['source_sha'],
                   source_tree=e['source_tree'],out_root=e['out_root'],exit_code=0,process_exited=True)
        with self.assertRaisesRegex(ValueError,'EXTERNAL_ATTEMPT_IDENTITY_MISMATCH'):
            g.verify_receipt(m,r,e,0,True,observed_attempt=wrong,repo=self.repo)

    def test_42_forged_in_memory_receipt_not_equal_to_disk(self):
        e=self.expected('p101-disk');m,r=self.run_build('p101-disk',expected=e)
        # A presentation-only rewrite is not evidence even if a matching hash
        # has been placed into the supplied in-memory manifest/receipt.
        fake={**m,'kind':'FORGED'}
        rehashed={**r,'manifest_sha256':g.sha(g.canonical(fake))}
        with self.assertRaisesRegex(ValueError,'UNAPPROVED_EVIDENCE_KIND'):
            self.assert_verified('p101-disk',fake,rehashed,e)

    def test_40_duplicate_or_untrusted_external_expectation_denied(self):
        e=self.expected('p101-bad-expectation')
        with self.assertRaisesRegex(ValueError,'EXPECTED_GATE_INVALID'):
            self.run_build('p101-bad-expectation',expected={**e,'release_approved':True})
        with self.assertRaisesRegex(ValueError,'EXPECTED_HASH_SET_INVALID'):
            self.run_build('p101-bad-expectation',expected={**e,'inputs_sha256':{'a':'b'}})


if __name__=='__main__': unittest.main(verbosity=2)
