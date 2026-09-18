import json
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


@unittest.skipUnless(shutil.which('git'), 'git unavailable')
class InventoryTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='dbvc-dropin-inventory-')
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.repo = self.base / 'repo'
        self.repo.mkdir()
        subprocess.run(['git', 'init', '-q', str(self.repo)], check=True)
        (self.repo / 'db-version-control.php').write_text('<?php // synthetic fixture\n')

    def run_inventory(self, output, repo=None):
        return subprocess.run([sys.executable, str(ROOT / 'scripts/inspect_dbvc.py'),
                               '--repo', str(repo or self.repo), '--output', str(output)],
                              text=True, capture_output=True)

    def test_new_external_report_and_repo_unchanged(self):
        before = subprocess.check_output(['git', '-C', str(self.repo), 'status', '--porcelain'])
        output = self.base / 'report.json'
        result = self.run_inventory(output)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(json.loads(output.read_text())['runtime_verified'])
        after = subprocess.check_output(['git', '-C', str(self.repo), 'status', '--porcelain'])
        self.assertEqual(before, after)

    def test_output_inside_repo_rejected(self):
        output = self.repo / 'report.json'
        self.assertNotEqual(self.run_inventory(output).returncode, 0)
        self.assertFalse(output.exists())

    def test_output_inside_parent_worktree_rejected(self):
        plugin = self.repo / 'plugins/dbvc'
        plugin.mkdir(parents=True)
        (plugin / 'db-version-control.php').write_text('<?php // nested fixture\n')
        output = self.repo / 'report.json'
        self.assertNotEqual(self.run_inventory(output, plugin).returncode, 0)
        self.assertFalse(output.exists())

    def test_existing_output_never_overwritten(self):
        output = self.base / 'report.json'
        output.write_text('preserve')
        self.assertNotEqual(self.run_inventory(output).returncode, 0)
        self.assertEqual(output.read_text(), 'preserve')

    def test_non_dbvc_root_rejected(self):
        self.assertNotEqual(self.run_inventory(self.base / 'report.json', self.base).returncode, 0)
