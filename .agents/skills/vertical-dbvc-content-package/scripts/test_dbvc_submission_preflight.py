#!/usr/bin/env python3
"""Focused regression tests for the portable DBVC package preflight helper."""

from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
import zipfile
from pathlib import Path


SCRIPT_PATH = Path(__file__).with_name("dbvc_submission_preflight.py")
SPEC = importlib.util.spec_from_file_location("dbvc_submission_preflight", SCRIPT_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"Could not import {SCRIPT_PATH}")
PREFLIGHT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PREFLIGHT)


class SubmissionPreflightTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.TemporaryDirectory()
        self.root = Path(self.temp_dir.name)
        self.sample = self.root / "sample"
        self.package = self.root / "package"
        self.sample.mkdir()
        self.package.mkdir()
        self.write_json(
            self.sample / "dbvc-ai-manifest.json",
            {
                "package_type": "dbvc_ai_sample_package",
                "package_schema_version": 1,
                "site_fingerprint": "sample-fingerprint",
            },
        )
        self.write_valid_submission()

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    def write_json(self, path: Path, value: dict) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(value, indent=2) + "\n", encoding="utf-8")

    def write_valid_submission(self) -> None:
        self.write_json(
            self.package / "dbvc-ai-manifest.json",
            {
                "package_type": "dbvc_ai_submission_package",
                "package_schema_version": 1,
                "source_sample_package": {
                    "site_fingerprint": "sample-fingerprint",
                    "package_schema_version": 1,
                },
                "intended_operation": "create_only",
                "counts": {"post_entities": 1, "term_entities": 0},
            },
        )
        self.write_json(
            self.package / "entities" / "posts" / "post" / "test-post.json",
            {
                "ID": 0,
                "post_type": "post",
                "post_title": "Test Post",
                "post_name": "test-post",
                "post_status": "draft",
                "post_content": "<p>Test content.</p>",
            },
        )

    def read_manifest(self) -> dict:
        return json.loads(
            (self.package / "dbvc-ai-manifest.json").read_text(encoding="utf-8")
        )

    def test_valid_package_can_be_preflighted_and_zipped(self) -> None:
        report = PREFLIGHT.preflight(self.sample, self.package)

        self.assertEqual("valid", report["status"])
        self.assertEqual({"post_entities": 1, "term_entities": 0}, report["entity_counts"])

        output = self.root / "out" / "submission.zip"
        PREFLIGHT.write_clean_zip(self.package, output)
        with zipfile.ZipFile(output) as archive:
            self.assertEqual(
                {
                    "dbvc-ai-manifest.json",
                    "entities/posts/post/test-post.json",
                },
                set(archive.namelist()),
            )

    def test_fingerprint_mismatch_blocks_delivery(self) -> None:
        manifest = self.read_manifest()
        manifest["source_sample_package"]["site_fingerprint"] = "wrong-site"
        self.write_json(self.package / "dbvc-ai-manifest.json", manifest)

        report = PREFLIGHT.preflight(self.sample, self.package)

        self.assertEqual("blocked", report["status"])
        self.assertIn(
            "site_fingerprint_mismatch",
            {issue["code"] for issue in report["issues"]},
        )

    def test_zip_writer_refuses_to_overwrite_without_explicit_permission(self) -> None:
        output = self.root / "existing.zip"
        output.write_bytes(b"keep this file")

        with self.assertRaisesRegex(ValueError, "Refusing to overwrite"):
            PREFLIGHT.write_clean_zip(self.package, output)

        self.assertEqual(b"keep this file", output.read_bytes())

    def test_count_mismatch_requires_correction_before_final_zip(self) -> None:
        manifest = self.read_manifest()
        manifest["counts"]["post_entities"] = 0
        self.write_json(self.package / "dbvc-ai-manifest.json", manifest)

        report = PREFLIGHT.preflight(self.sample, self.package)

        self.assertEqual("valid_with_warnings", report["status"])
        self.assertIn(
            "entity_count_mismatch",
            {issue["code"] for issue in report["issues"]},
        )

    def test_acf_underscore_reference_meta_is_rejected(self) -> None:
        entity_path = self.package / "entities" / "posts" / "post" / "test-post.json"
        entity = json.loads(entity_path.read_text(encoding="utf-8"))
        entity["meta"] = {"_headline": "field_123"}
        self.write_json(entity_path, entity)

        report = PREFLIGHT.preflight(self.sample, self.package)

        self.assertEqual("blocked", report["status"])
        self.assertIn(
            "acf_reference_meta_not_allowed",
            {issue["code"] for issue in report["issues"]},
        )


if __name__ == "__main__":
    unittest.main()
