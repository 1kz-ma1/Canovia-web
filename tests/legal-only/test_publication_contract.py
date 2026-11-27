"""Offline Gate S positive/negative tests. This suite cannot verify Render or physical Origin isolation."""
import importlib.util
from pathlib import Path
import os
import shutil
import subprocess
import sys
import tempfile
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

    def test_16_receipt_wrong_run_and_status_rejected(self):
        manifest={'run_id':'RUN1234','synthetic_only':True,'not_deployable':True}
        receipt={'run_id':'RUN1234','status':'SYNTHETIC_PASS_NOT_DEPLOYABLE','synthetic_only':True,
                 'not_deployable':True,'manifest_sha256':g.sha(g.canonical(manifest))}
        g.verify_receipt(manifest,receipt,'RUN1234')
        with self.assertRaisesRegex(ValueError,'RUN_ID_MISMATCH'):g.verify_receipt(manifest,receipt,'OLD1234')
        with self.assertRaisesRegex(ValueError,'SYNTHETIC_RECEIPT_REMOVED'):g.verify_receipt(manifest,{**receipt,'not_deployable':False},'RUN1234')
        with self.assertRaisesRegex(ValueError,'RECEIPT_MISMATCH'):g.verify_receipt(manifest,{**receipt,'manifest_sha256':'bad'},'RUN1234')


class IsolatedBuild(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='canovia-gate-s-')
        cls.root=Path(cls.temp.name)
        cls.repo=cls.root/'synthetic-source';cls.repo.mkdir()
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

    def run_build(self,name='candidate',**kwargs):
        args=dict(repo=self.repo,source_sha=self.head,source_tree=self.tree,
                  inventory_sha=g.B_INVENTORY_SHA256,run_id='SYNTH-0001',out_root=self.root/name,mode='synthetic')
        args.update(kwargs)
        return g.build(**args)

    def test_17_fresh_synthetic_build(self):
        manifest,receipt=self.run_build('successful')
        root=self.root/'successful';publish=root/'.render-legal-only'
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
        self.assertFalse((self.root/'public').exists())

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
        self.assertFalse((self.root/'crash/evidence/receipt.json').exists())
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
                with self.assertRaisesRegex(ValueError,'RUN_ID_INVALID'):self.run_build('runid',run_id=bad)

    def test_25_evidence_is_bound_to_exact_run(self):
        m,r=self.run_build('evidence',run_id='GATES20261127')
        g.verify_receipt(m,r,'GATES20261127')
        with self.assertRaisesRegex(ValueError,'RUN_ID_MISMATCH'):g.verify_receipt(m,r,'DIFFERENT0001')


if __name__=='__main__': unittest.main(verbosity=2)
