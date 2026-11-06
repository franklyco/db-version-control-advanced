#!/usr/bin/env python3
"""Regression checks for the tracked skills-only ChatGPT distribution.

The plugin intentionally shares its DBVC data-contract references with the
local companion, while withholding executable preflight and ZIP delivery.
"""

from __future__ import annotations

import json
import unittest
from pathlib import Path


LOCAL_SKILL_ROOT = Path(__file__).resolve().parents[1]
REPOSITORY_ROOT = LOCAL_SKILL_ROOT.parents[2]
PLUGIN_ROOT = REPOSITORY_ROOT / "distribution" / "chatgpt" / "vertical-dbvc-content"
PLUGIN_SKILL_ROOT = PLUGIN_ROOT / "skills" / "vertical-dbvc-content-package"


class ChatGptPluginContractTest(unittest.TestCase):
    def test_manifest_declares_a_skills_only_plugin(self) -> None:
        manifest = json.loads(
            (PLUGIN_ROOT / ".codex-plugin" / "plugin.json").read_text(encoding="utf-8")
        )

        self.assertEqual("vertical-dbvc-content", manifest["name"])
        self.assertEqual("0.1.0", manifest["version"])
        self.assertEqual("./skills/", manifest["skills"])
        self.assertNotIn("mcpServers", manifest)
        self.assertNotIn("apps", manifest)
        self.assertIn("never creates a ZIP", manifest["interface"]["longDescription"])

    def test_shared_contract_references_stay_identical(self) -> None:
        local_references = LOCAL_SKILL_ROOT / "references"
        plugin_references = PLUGIN_SKILL_ROOT / "references"

        for filename in (
            "input-brief.md",
            "dbvc-submission-contract.md",
            "field-context-routing.md",
        ):
            with self.subTest(filename=filename):
                self.assertEqual(
                    (local_references / filename).read_bytes(),
                    (plugin_references / filename).read_bytes(),
                )

    def test_skill_has_a_clear_no_zip_no_upload_boundary(self) -> None:
        skill_text = (PLUGIN_SKILL_ROOT / "SKILL.md").read_text(encoding="utf-8")
        boundary_text = (PLUGIN_SKILL_ROOT / "references" / "web-mobile-boundary.md").read_text(
            encoding="utf-8"
        )

        for required_text in (
            "JSON draft for local preflight",
            "Do not create a ZIP",
            "Never upload, import, apply, publish",
            "original current DBVC sample package",
        ):
            with self.subTest(required_text=required_text):
                self.assertIn(required_text, f"{skill_text}\n{boundary_text}")

        self.assertNotIn("dbvc_submission_preflight.py", skill_text)
        self.assertFalse((PLUGIN_ROOT / ".mcp.json").exists())
        self.assertFalse((PLUGIN_ROOT / "scripts").exists())

    def test_release_readme_marks_install_and_hosting_as_separate_gates(self) -> None:
        readme = (PLUGIN_ROOT / "README.md").read_text(encoding="utf-8")

        self.assertIn("not installed, published, connected to DBVC", readme)
        self.assertIn("marketplace/install decision", readme)
        self.assertIn("hosting, authentication, privacy, retention, and access controls", readme)


if __name__ == "__main__":
    unittest.main()
