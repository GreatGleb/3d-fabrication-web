"""Offline integration tests: no model calls, project builds, or network."""
import importlib.util
import fcntl
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().with_name('plan_workflow.py')
spec = importlib.util.spec_from_file_location('workflow', SCRIPT)
w = importlib.util.module_from_spec(spec)
spec.loader.exec_module(w)

FAKE = '''#!/usr/bin/env python3
import json, pathlib, re, sys
prompt = sys.stdin.read()
output = pathlib.Path(sys.argv[sys.argv.index('-o') + 1])
schema = json.loads(pathlib.Path(sys.argv[sys.argv.index('--output-schema') + 1]).read_text())
if 'approved' in schema['properties']:
    marker = pathlib.Path(__file__).with_name('reject-once')
    reject = marker.exists()
    if reject: marker.unlink()
    result = {'approved': not reject, 'findings': ['Correct evidence'] if reject else []}
else:
    layer = int(re.search(r'слой (\\d+)/30', prompt).group(1))
    result = {'status':'ready', 'summary':f'Layer {layer}', 'handoff':'Next layer',
              'difficulties':['Known limitation'], 'evidence':['README.md'], 'checks':['Read source'],
              'issues_add':[], 'issues_resolve':[],
              'updates':[{'layer':layer, 'summary':f'Layer {layer}', 'markdown':'Verified plan. ' * 20}]}
output.write_text(json.dumps(result))
print(json.dumps({'type':'turn.completed','usage':{'input_tokens':100,'cached_input_tokens':20,'output_tokens':10}}))
'''


class WorkflowTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.out = self.base / 'run'
        self.fake = self.base / 'codex-fake'
        self.fake.write_text(FAKE)
        self.fake.chmod(0o755)

    def tearDown(self):
        self.temp.cleanup()

    def run_cli(self, command='run', *extra, ok=True):
        result = subprocess.run([sys.executable, str(SCRIPT), command, '--output', str(self.out),
                                 '--codex', str(self.fake), *extra], capture_output=True, text=True)
        self.assertEqual(result.returncode == 0, ok, result.stdout + result.stderr)
        return result

    def test_full_cycle_and_resume_without_duplicate_sessions(self):
        self.run_cli('run', '--until', '2')
        self.assertEqual(w.read(self.out/'state.json')['completed'], 2)
        self.run_cli()
        state = w.read(self.out/'state.json')
        self.assertEqual(state['completed'], 30)
        self.assertEqual(len(state['sections']), 30)
        self.assertEqual(len(list((self.out/'sessions').iterdir())), 60)
        self.run_cli()
        self.assertEqual(len(list((self.out/'sessions').iterdir())), 60)
        self.assertEqual(w.token_usage(self.out)['total_tokens'], 6600)

    def test_rejection_and_feedback_across_restart(self):
        (self.base/'reject-once').touch()
        self.run_cli('run', '--until', '1', '--attempts', '1', ok=False)
        self.assertEqual(w.read(self.out/'state.json')['completed'], 0)
        self.run_cli('run', '--until', '1')
        prompts = [p.read_text() for p in (self.out/'sessions').glob('*/prompt.txt')]
        self.assertTrue(any('Correct evidence' in text for text in prompts))
        self.assertEqual(w.read(self.out/'state.json')['completed'], 1)

    def test_source_drift_stops_resume(self):
        source = self.base/'requirements.txt'
        source.write_text('first')
        self.run_cli('run', '--source', str(source), '--until', '1')
        source.write_text('changed')
        self.run_cli('run', '--until', '2', ok=False)
        self.assertEqual(w.read(self.out/'state.json')['completed'], 1)

    def test_token_threshold_does_not_accept_unreviewed_result(self):
        self.run_cli('run', '--max-total-tokens', '100', '--until', '1', ok=False)
        self.assertEqual(w.read(self.out/'state.json')['completed'], 0)
        self.assertEqual(len(list((self.out/'sessions').iterdir())), 1)

    def test_validation_and_issue_history(self):
        result = dict(status='ready', summary='Summary', handoff='', difficulties=[],
                      evidence=['README.md'], checks=['Read'], issues_add=[{'id':'Q-1','text':'Unknown'}],
                      issues_resolve=[], updates=[dict(layer=1, summary='First', markdown='Detail '*30)])
        state = dict(sections={}, issues={}, history=[], completed=0)
        w.validate_result(result, 1, state)
        updated = w.apply_result(state, result, 1)
        self.assertEqual(state['completed'], 0)
        self.assertIn('Q-1', updated['issues'])
        result['updates'][0]['layer'] = 2
        with self.assertRaises(ValueError):
            w.validate_result(result, 1, state)
        result['updates'][0]['layer'] = 1
        result['issues_resolve'] = [{'id':'missing', 'reason':'guess'}]
        with self.assertRaises(ValueError):
            w.validate_result(result, 1, state)

    def test_superseded_cycle_is_not_rendered_or_run(self):
        self.run_cli('run', '--until', '1')
        state = w.read(self.out/'state.json')
        state['superseded_by'] = 'docs/planning/edition-2/README.md'
        w.write(self.out/'state.json', state)
        (self.out/'PLAN.md').write_text('pointer')
        for command in ('run', 'preview'):
            result = self.run_cli(command, ok=False)
            self.assertIn('superseded', result.stderr)
        self.assertEqual((self.out/'PLAN.md').read_text(), 'pointer')
        self.run_cli('status')

    def test_preview_no_model_calls(self):
        result = self.run_cli('preview')
        self.assertIn('слой 1/30', result.stdout)
        self.assertFalse((self.out/'sessions').exists())

    def test_detect_extension_without_path_entry(self):
        executable = self.base / '.vscode/extensions/openai.chatgpt-test/bin/linux-x86_64/codex'
        executable.parent.mkdir(parents=True)
        executable.write_text('#!/bin/sh\nexit 0\n')
        executable.chmod(0o755)
        with patch.object(w.shutil, 'which', return_value=None), patch.object(w.Path, 'home', return_value=self.base):
            self.assertEqual(w.resolve_codex(), str(executable))
            with self.assertRaises(RuntimeError):
                w.resolve_codex('/missing/explicit-codex')

    def test_explicit_executable_has_priority(self):
        self.assertEqual(w.resolve_codex(str(self.fake)), str(self.fake))

    def test_timeout_preserves_checkpoint(self):
        self.fake.write_text('#!/usr/bin/env python3\nimport time\ntime.sleep(30)\n')
        self.run_cli('run', '--until', '1', '--timeout', '1', ok=False)
        self.assertEqual(w.read(self.out/'state.json')['completed'], 0)

    def test_nonzero_cli_does_not_accept_candidate(self):
        self.fake.write_text(FAKE + '\nsys.exit(7)\n')
        self.run_cli('run', '--until', '1', ok=False)
        self.assertEqual(w.read(self.out/'state.json')['completed'], 0)

    def test_concurrent_run_is_rejected(self):
        self.out.mkdir()
        with (self.out/'.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.run_cli('run', '--until', '1', ok=False)
            self.assertIn('Another workflow', result.stderr)

    def test_economy_reviews_at_checkpoint_and_final(self):
        self.run_cli('run', '--review-every', '5')
        self.assertEqual(len(list((self.out/'sessions').iterdir())), 36)


if __name__ == '__main__':
    unittest.main()
