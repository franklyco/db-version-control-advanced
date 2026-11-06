#!/usr/bin/env python3
"""Offline structural preflight for a DBVC AI submission package.

This helper is deliberately narrower than DBVC's runtime validator. It verifies
the current sample fingerprint, canonical package layout, required entity
fields, common unsafe metadata, and manifest counts. It never contacts
WordPress, uploads a package, or imports content.
"""

from __future__ import annotations

import argparse
import json
import sys
import zipfile
from pathlib import Path
from typing import Any


MANIFEST_NAME = "dbvc-ai-manifest.json"
SAMPLE_PACKAGE_TYPE = "dbvc_ai_sample_package"
SUBMISSION_PACKAGE_TYPE = "dbvc_ai_submission_package"
SCHEMA_VERSION = 1
OPERATIONS = {"create_only", "update_only", "create_or_update"}
POST_BLOCKED_META = {"dbvc_post_history", "_dbvc_import_hash"}
TERM_BLOCKED_META = {"dbvc_term_history"}
ALLOWED_ROOTS = {MANIFEST_NAME, "entities", "docs", "reports"}


class PackageReader:
    """Read a package directory or ZIP using root-relative POSIX paths."""

    def __init__(self, path: Path) -> None:
        self.path = path
        self.is_zip = path.is_file() and path.suffix.lower() == ".zip"
        if not self.is_zip and not path.is_dir():
            raise ValueError(f"Package path is neither a directory nor a .zip file: {path}")
        if self.is_zip:
            try:
                with zipfile.ZipFile(path) as archive:
                    archive.infolist()
            except (OSError, zipfile.BadZipFile) as error:
                raise ValueError(f"Unable to read ZIP archive {path}: {error}") from error

    def paths(self) -> list[str]:
        if self.is_zip:
            with zipfile.ZipFile(self.path) as archive:
                return [entry.filename for entry in archive.infolist() if not entry.is_dir()]
        return sorted(
            candidate.relative_to(self.path).as_posix()
            for candidate in self.path.rglob("*")
            if candidate.is_file()
        )

    def read_bytes(self, relative_path: str) -> bytes:
        if self.is_zip:
            with zipfile.ZipFile(self.path) as archive:
                return archive.read(relative_path)
        return (self.path / relative_path).read_bytes()


def add_issue(
    issues: list[dict[str, str]],
    severity: str,
    code: str,
    path: str,
    message: str,
) -> None:
    issues.append(
        {
            "severity": severity,
            "code": code,
            "path": path,
            "message": message,
        }
    )


def is_ignorable_path(path: str) -> bool:
    return path == ".DS_Store" or path.startswith("__MACOSX/") or path.endswith("/.DS_Store")


def normalize_paths(
    reader: PackageReader,
    label: str,
    issues: list[dict[str, str]],
) -> dict[str, str]:
    """Return normalized path -> archive/source path, recording unsafe entries."""

    normalized_paths: dict[str, str] = {}
    for raw_path in reader.paths():
        path = raw_path.replace("\\", "/")
        if (
            raw_path != path
            or not path
            or path.startswith("/")
            or path.startswith("../")
            or "/../" in path
            or "/./" in path
            or "//" in path
        ):
            add_issue(
                issues,
                "error",
                "unsafe_path",
                raw_path,
                f"{label} contains an unsafe or non-canonical path.",
            )
            continue
        if path in normalized_paths:
            add_issue(
                issues,
                "error",
                "duplicate_path",
                path,
                f"{label} contains duplicate file paths.",
            )
            continue
        normalized_paths[path] = raw_path
    return normalized_paths


def reject_non_finite(value: str) -> None:
    raise ValueError(f"Non-finite JSON value: {value}")


def read_json_object(
    reader: PackageReader,
    raw_path: str,
    display_path: str,
    issues: list[dict[str, str]],
    code_prefix: str,
) -> dict[str, Any] | None:
    try:
        source = reader.read_bytes(raw_path).decode("utf-8")
        value = json.loads(source, parse_constant=reject_non_finite)
    except (OSError, UnicodeDecodeError, json.JSONDecodeError, ValueError) as error:
        add_issue(
            issues,
            "error",
            f"{code_prefix}_json_invalid",
            display_path,
            f"JSON could not be parsed: {error}",
        )
        return None
    if not isinstance(value, dict):
        add_issue(
            issues,
            "error",
            f"{code_prefix}_json_object_required",
            display_path,
            "JSON must contain an object at its root.",
        )
        return None
    return value


def scalar_nonempty_string(value: Any) -> bool:
    return isinstance(value, str) and bool(value.strip())


def json_number(value: Any) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def positive_number(value: Any) -> bool:
    return json_number(value) and value > 0


def zero_number(value: Any) -> bool:
    return json_number(value) and value == 0


def validate_sample_manifest(
    manifest: dict[str, Any] | None,
    issues: list[dict[str, str]],
) -> str:
    if manifest is None:
        return ""
    if manifest.get("package_type") != SAMPLE_PACKAGE_TYPE:
        add_issue(
            issues,
            "error",
            "sample_package_type_invalid",
            MANIFEST_NAME,
            "The supplied sample is not a DBVC AI sample package.",
        )
    if manifest.get("package_schema_version") != SCHEMA_VERSION:
        add_issue(
            issues,
            "error",
            "sample_schema_version_invalid",
            MANIFEST_NAME,
            f"The sample package schema version must be {SCHEMA_VERSION}.",
        )
    fingerprint = manifest.get("site_fingerprint")
    if not scalar_nonempty_string(fingerprint):
        add_issue(
            issues,
            "error",
            "sample_fingerprint_missing",
            MANIFEST_NAME,
            "The sample package is missing a non-empty root site_fingerprint.",
        )
        return ""
    return fingerprint.strip()


def validate_submission_manifest(
    manifest: dict[str, Any] | None,
    sample_fingerprint: str,
    issues: list[dict[str, str]],
) -> tuple[str, dict[str, int]]:
    if manifest is None:
        return "", {}

    if manifest.get("package_type") != SUBMISSION_PACKAGE_TYPE:
        add_issue(
            issues,
            "error",
            "submission_package_type_invalid",
            MANIFEST_NAME,
            "package_type must be dbvc_ai_submission_package.",
        )
    if manifest.get("package_schema_version") != SCHEMA_VERSION:
        add_issue(
            issues,
            "error",
            "submission_schema_version_invalid",
            MANIFEST_NAME,
            f"package_schema_version must be {SCHEMA_VERSION}.",
        )

    source_sample = manifest.get("source_sample_package")
    if not isinstance(source_sample, dict):
        add_issue(
            issues,
            "error",
            "source_sample_missing",
            f"{MANIFEST_NAME}#source_sample_package",
            "source_sample_package must be an object.",
        )
        source_sample = {}

    package_fingerprint = source_sample.get("site_fingerprint")
    if not scalar_nonempty_string(package_fingerprint):
        add_issue(
            issues,
            "error",
            "submission_fingerprint_missing",
            f"{MANIFEST_NAME}#source_sample_package.site_fingerprint",
            "The submission package is missing the source sample site fingerprint.",
        )
    elif sample_fingerprint and package_fingerprint.strip() != sample_fingerprint:
        add_issue(
            issues,
            "error",
            "site_fingerprint_mismatch",
            f"{MANIFEST_NAME}#source_sample_package.site_fingerprint",
            "The submission package fingerprint does not match the supplied sample package.",
        )

    if source_sample.get("package_schema_version") != SCHEMA_VERSION:
        add_issue(
            issues,
            "error",
            "source_sample_schema_version_invalid",
            f"{MANIFEST_NAME}#source_sample_package.package_schema_version",
            f"source_sample_package.package_schema_version must be {SCHEMA_VERSION}.",
        )

    operation = manifest.get("intended_operation")
    if not isinstance(operation, str) or operation not in OPERATIONS:
        add_issue(
            issues,
            "error",
            "intended_operation_invalid",
            f"{MANIFEST_NAME}#intended_operation",
            "intended_operation must be create_only, update_only, or create_or_update.",
        )
        operation = ""

    counts = manifest.get("counts")
    declared_counts: dict[str, int] = {}
    if not isinstance(counts, dict):
        add_issue(
            issues,
            "error",
            "counts_invalid",
            f"{MANIFEST_NAME}#counts",
            "counts must be an object with post_entities and term_entities.",
        )
    else:
        for count_key in ("post_entities", "term_entities"):
            value = counts.get(count_key)
            if not isinstance(value, int) or isinstance(value, bool) or value < 0:
                add_issue(
                    issues,
                    "error",
                    "count_invalid",
                    f"{MANIFEST_NAME}#counts.{count_key}",
                    f"{count_key} must be a non-negative integer.",
                )
            else:
                declared_counts[count_key] = value

    return operation, declared_counts


def recognize_entity_path(path: str) -> tuple[str, str, str] | None:
    parts = path.split("/")
    if len(parts) != 4 or parts[0] != "entities" or not parts[3].endswith(".json"):
        return None
    slug = parts[3][:-5]
    if not parts[1] or not parts[2] or not slug:
        return None
    if parts[1] == "posts":
        return "post", parts[2], slug
    if parts[1] == "terms":
        return "term", parts[2], slug
    return None


def validate_meta(
    payload: dict[str, Any],
    entity_path: str,
    blocked_keys: set[str],
    issues: list[dict[str, str]],
) -> None:
    if "meta" not in payload:
        return
    meta = payload["meta"]
    if not isinstance(meta, dict):
        add_issue(
            issues,
            "error",
            "meta_invalid",
            f"{entity_path}#meta",
            "meta must be an object when present.",
        )
        return
    for key in meta:
        if key in blocked_keys:
            add_issue(
                issues,
                "error",
                "meta_key_blocked",
                f"{entity_path}#meta.{key}",
                f"The DBVC-blocked meta key {key} must not be authored.",
            )
        if isinstance(key, str) and key.startswith("_"):
            add_issue(
                issues,
                "error",
                "acf_reference_meta_not_allowed",
                f"{entity_path}#meta.{key}",
                "Do not author ACF underscore reference meta in an AI submission package.",
            )


def validate_post_entity(
    payload: dict[str, Any],
    object_key: str,
    file_slug: str,
    entity_path: str,
    operation: str,
    issues: list[dict[str, str]],
) -> None:
    required = ("ID", "post_type", "post_title", "post_name")
    for key in required:
        if key not in payload:
            add_issue(
                issues,
                "error",
                "field_required",
                f"{entity_path}#{key}",
                f"The required post field {key} is missing.",
            )

    if payload.get("post_type") != object_key:
        add_issue(
            issues,
            "error",
            "post_type_path_mismatch",
            f"{entity_path}#post_type",
            "post_type must exactly match the post type in the entity path.",
        )
    if not scalar_nonempty_string(payload.get("post_title")):
        add_issue(
            issues,
            "error",
            "post_title_invalid",
            f"{entity_path}#post_title",
            "post_title must be a non-empty string.",
        )
    if not scalar_nonempty_string(payload.get("post_name")):
        add_issue(
            issues,
            "error",
            "post_name_invalid",
            f"{entity_path}#post_name",
            "post_name must be a non-empty string.",
        )
    elif payload["post_name"] != file_slug:
        add_issue(
            issues,
            "warning",
            "post_slug_mismatch",
            f"{entity_path}#post_name",
            "post_name should exactly match the entity filename slug.",
        )

    identifier = payload.get("ID")
    if not json_number(identifier):
        add_issue(
            issues,
            "error",
            "post_id_invalid",
            f"{entity_path}#ID",
            "ID must be a JSON number.",
        )
    if operation == "create_only" and not zero_number(identifier):
        add_issue(
            issues,
            "error",
            "create_post_id_must_be_zero",
            f"{entity_path}#ID",
            "A net-new post, page, or CPT entity must use ID: 0.",
        )

    uid = payload.get("vf_object_uid")
    if "vf_object_uid" in payload and not scalar_nonempty_string(uid):
        add_issue(
            issues,
            "error",
            "post_uid_invalid",
            f"{entity_path}#vf_object_uid",
            "vf_object_uid must be a non-empty string when present.",
        )
    if operation == "create_only" and "vf_object_uid" in payload:
        add_issue(
            issues,
            "error",
            "create_post_uid_not_allowed",
            f"{entity_path}#vf_object_uid",
            "Net-new entities must not include vf_object_uid.",
        )
    if operation == "update_only" and not (scalar_nonempty_string(uid) or positive_number(identifier)):
        add_issue(
            issues,
            "error",
            "update_post_identity_missing",
            entity_path,
            "An update_only post entity needs a known vf_object_uid or positive destination ID.",
        )

    validate_meta(payload, entity_path, POST_BLOCKED_META, issues)
    if "tax_input" in payload and not isinstance(payload["tax_input"], dict):
        add_issue(
            issues,
            "error",
            "tax_input_invalid",
            f"{entity_path}#tax_input",
            "tax_input must be an object when present.",
        )


def validate_term_entity(
    payload: dict[str, Any],
    object_key: str,
    file_slug: str,
    entity_path: str,
    operation: str,
    issues: list[dict[str, str]],
) -> None:
    required = ("term_id", "taxonomy", "name", "slug")
    for key in required:
        if key not in payload:
            add_issue(
                issues,
                "error",
                "field_required",
                f"{entity_path}#{key}",
                f"The required term field {key} is missing.",
            )

    if payload.get("taxonomy") != object_key:
        add_issue(
            issues,
            "error",
            "taxonomy_path_mismatch",
            f"{entity_path}#taxonomy",
            "taxonomy must exactly match the taxonomy in the entity path.",
        )
    if not scalar_nonempty_string(payload.get("name")):
        add_issue(
            issues,
            "error",
            "term_name_invalid",
            f"{entity_path}#name",
            "name must be a non-empty string.",
        )
    if not scalar_nonempty_string(payload.get("slug")):
        add_issue(
            issues,
            "error",
            "term_slug_invalid",
            f"{entity_path}#slug",
            "slug must be a non-empty string.",
        )
    elif payload["slug"] != file_slug:
        add_issue(
            issues,
            "warning",
            "term_slug_mismatch",
            f"{entity_path}#slug",
            "slug should exactly match the entity filename slug.",
        )

    identifier = payload.get("term_id")
    if not json_number(identifier):
        add_issue(
            issues,
            "error",
            "term_id_invalid",
            f"{entity_path}#term_id",
            "term_id must be a JSON number.",
        )
    if operation == "create_only" and not zero_number(identifier):
        add_issue(
            issues,
            "error",
            "create_term_id_must_be_zero",
            f"{entity_path}#term_id",
            "A net-new term must use term_id: 0.",
        )

    uid = payload.get("vf_object_uid")
    if "vf_object_uid" in payload and not scalar_nonempty_string(uid):
        add_issue(
            issues,
            "error",
            "term_uid_invalid",
            f"{entity_path}#vf_object_uid",
            "vf_object_uid must be a non-empty string when present.",
        )
    if operation == "create_only" and "vf_object_uid" in payload:
        add_issue(
            issues,
            "error",
            "create_term_uid_not_allowed",
            f"{entity_path}#vf_object_uid",
            "Net-new terms must not include vf_object_uid.",
        )
    if operation == "update_only" and not (scalar_nonempty_string(uid) or positive_number(identifier)):
        add_issue(
            issues,
            "error",
            "update_term_identity_missing",
            entity_path,
            "An update_only term needs a known vf_object_uid or positive destination ID.",
        )

    if "parent_uid" in payload:
        add_issue(
            issues,
            "error",
            "term_parent_uid_blocked",
            f"{entity_path}#parent_uid",
            "parent_uid is blocked in DBVC AI submission packages.",
        )
    validate_meta(payload, entity_path, TERM_BLOCKED_META, issues)


def derive_status(issues: list[dict[str, str]]) -> str:
    if any(issue["severity"] == "error" for issue in issues):
        return "blocked"
    if any(issue["severity"] == "warning" for issue in issues):
        return "valid_with_warnings"
    return "valid"


def preflight(sample_path: Path, package_path: Path) -> dict[str, Any]:
    """Run the portable structural checks and return a JSON-serializable report."""

    issues: list[dict[str, str]] = []
    actual_counts = {"post_entities": 0, "term_entities": 0}
    try:
        sample = PackageReader(sample_path)
    except ValueError as error:
        add_issue(issues, "error", "sample_unreadable", str(sample_path), str(error))
        sample = None
    try:
        submission = PackageReader(package_path)
    except ValueError as error:
        add_issue(issues, "error", "submission_unreadable", str(package_path), str(error))
        submission = None

    sample_manifest: dict[str, Any] | None = None
    submission_manifest: dict[str, Any] | None = None
    operation = ""
    declared_counts: dict[str, int] = {}

    if sample is not None:
        sample_paths = normalize_paths(sample, "Sample package", issues)
        sample_manifest_path = sample_paths.get(MANIFEST_NAME)
        if sample_manifest_path is None:
            add_issue(
                issues,
                "error",
                "sample_manifest_missing",
                MANIFEST_NAME,
                "The sample package must contain a root dbvc-ai-manifest.json.",
            )
        else:
            sample_manifest = read_json_object(
                sample,
                sample_manifest_path,
                MANIFEST_NAME,
                issues,
                "sample_manifest",
            )
    sample_fingerprint = validate_sample_manifest(sample_manifest, issues)

    if submission is not None:
        submission_paths = normalize_paths(submission, "Submission package", issues)
        submission_manifest_path = submission_paths.get(MANIFEST_NAME)
        if submission_manifest_path is None:
            add_issue(
                issues,
                "error",
                "submission_manifest_missing",
                MANIFEST_NAME,
                "The submission package must contain a root dbvc-ai-manifest.json.",
            )
        else:
            submission_manifest = read_json_object(
                submission,
                submission_manifest_path,
                MANIFEST_NAME,
                issues,
                "submission_manifest",
            )
        operation, declared_counts = validate_submission_manifest(
            submission_manifest,
            sample_fingerprint,
            issues,
        )

        for path in submission_paths:
            if is_ignorable_path(path):
                continue
            root = path.split("/", 1)[0]
            if root not in ALLOWED_ROOTS:
                add_issue(
                    issues,
                    "warning",
                    "root_path_unexpected",
                    path,
                    "The submission package contains an unexpected root-level path.",
                )

        entity_paths = [
            path
            for path in submission_paths
            if path.startswith("entities/") and not is_ignorable_path(path)
        ]
        if not entity_paths:
            add_issue(
                issues,
                "error",
                "entities_missing",
                "entities",
                "The submission package must contain at least one entity JSON file.",
            )

        logical_entities: set[tuple[str, str, str]] = set()
        for path in entity_paths:
            if not path.endswith(".json"):
                add_issue(
                    issues,
                    "error",
                    "entity_extension_invalid",
                    path,
                    "Files under entities must use the .json extension.",
                )
                continue

            recognized = recognize_entity_path(path)
            if recognized is None:
                add_issue(
                    issues,
                    "warning",
                    "entity_path_unrecognized",
                    path,
                    "The entity JSON path does not use DBVC's canonical layout.",
                )
                continue

            entity_kind, object_key, file_slug = recognized
            logical_key = (entity_kind, object_key, file_slug)
            if logical_key in logical_entities:
                add_issue(
                    issues,
                    "error",
                    "entity_duplicate",
                    path,
                    "The package contains duplicate logical entity paths.",
                )
            logical_entities.add(logical_key)

            payload = read_json_object(
                submission,
                submission_paths[path],
                path,
                issues,
                "entity",
            )
            if payload is None:
                continue
            if entity_kind == "post":
                actual_counts["post_entities"] += 1
                validate_post_entity(
                    payload,
                    object_key,
                    file_slug,
                    path,
                    operation,
                    issues,
                )
            else:
                actual_counts["term_entities"] += 1
                validate_term_entity(
                    payload,
                    object_key,
                    file_slug,
                    path,
                    operation,
                    issues,
                )

    for count_key, actual_count in actual_counts.items():
        if count_key in declared_counts and declared_counts[count_key] != actual_count:
            add_issue(
                issues,
                "warning",
                "entity_count_mismatch",
                f"{MANIFEST_NAME}#counts.{count_key}",
                f"Declared {count_key} does not match the {actual_count} recognized entity file(s).",
            )

    status = derive_status(issues)
    return {
        "tool": "dbvc_submission_preflight",
        "status": status,
        "sample": {"path": str(sample_path)},
        "submission": {"path": str(package_path)},
        "entity_counts": actual_counts,
        "summary": {
            "errors": sum(issue["severity"] == "error" for issue in issues),
            "warnings": sum(issue["severity"] == "warning" for issue in issues),
        },
        "issues": issues,
    }


def write_clean_zip(
    package_directory: Path,
    output_path: Path,
    overwrite: bool = False,
) -> None:
    """Write a deterministic ZIP with the submission directory contents at root."""

    if not package_directory.is_dir():
        raise ValueError("--zip-output requires --package to be a staging directory.")
    root = package_directory.resolve()
    output = output_path.resolve()
    try:
        output.relative_to(root)
    except ValueError:
        pass
    else:
        raise ValueError("The ZIP output path must not be inside the package staging directory.")
    if output_path.exists() and not overwrite:
        raise ValueError(
            "Refusing to overwrite an existing ZIP. Choose a new path or pass --overwrite."
        )

    files: list[tuple[str, Path]] = []
    for candidate in sorted(package_directory.rglob("*")):
        if not candidate.is_file():
            continue
        if candidate.is_symlink():
            raise ValueError(f"Submission package must not contain symlinked files: {candidate}")
        relative_path = candidate.relative_to(package_directory).as_posix()
        if is_ignorable_path(relative_path):
            continue
        files.append((relative_path, candidate))

    output_path.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output_path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for relative_path, source_path in files:
            entry = zipfile.ZipInfo(relative_path, date_time=(1980, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, source_path.read_bytes())


def write_report(
    report: dict[str, Any],
    report_path: Path,
    overwrite: bool = False,
) -> None:
    if report_path.exists() and not overwrite:
        raise ValueError(
            "Refusing to overwrite an existing report. Choose a new path or pass --overwrite."
        )
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(
        json.dumps(report, indent=2, sort_keys=True, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )


def print_report(report: dict[str, Any]) -> None:
    summary = report["summary"]
    print(
        f"{report['status']}: {summary['errors']} error(s), "
        f"{summary['warnings']} warning(s)"
    )
    for issue in report["issues"]:
        print(
            f"{issue['severity'].upper()} {issue['code']} "
            f"[{issue['path']}]: {issue['message']}"
        )


def refresh_report_status(report: dict[str, Any]) -> None:
    report["status"] = derive_status(report["issues"])
    report["summary"]["errors"] = sum(
        issue["severity"] == "error" for issue in report["issues"]
    )
    report["summary"]["warnings"] = sum(
        issue["severity"] == "warning" for issue in report["issues"]
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Preflight a DBVC AI submission package against a current sample package."
    )
    parser.add_argument(
        "--sample",
        required=True,
        type=Path,
        help="Current DBVC AI sample package directory or ZIP.",
    )
    parser.add_argument(
        "--package",
        required=True,
        type=Path,
        help="Submission staging directory or ZIP to validate.",
    )
    parser.add_argument(
        "--report",
        type=Path,
        help="Optional JSON path for the offline preflight report.",
    )
    parser.add_argument(
        "--zip-output",
        type=Path,
        help="Write a deterministic final ZIP only when preflight is cleanly valid.",
    )
    parser.add_argument(
        "--overwrite",
        action="store_true",
        help="Explicitly allow replacement of an existing report or final ZIP.",
    )
    args = parser.parse_args(argv)

    report = preflight(args.sample, args.package)
    report_destination_available = True
    if args.report is not None and args.report.exists() and not args.overwrite:
        add_issue(
            report["issues"],
            "error",
            "report_not_written",
            str(args.report),
            "Refusing to overwrite an existing report without --overwrite.",
        )
        refresh_report_status(report)
        report_destination_available = False

    if args.zip_output is not None:
        if report["status"] != "valid":
            add_issue(
                report["issues"],
                "error",
                "zip_not_written",
                str(args.zip_output),
                "The final ZIP is written only after a clean valid preflight report.",
            )
            refresh_report_status(report)
        else:
            try:
                write_clean_zip(args.package, args.zip_output, args.overwrite)
                report["zip_output"] = str(args.zip_output)
            except (OSError, ValueError, zipfile.BadZipFile) as error:
                add_issue(
                    report["issues"],
                    "error",
                    "zip_write_failed",
                    str(args.zip_output),
                    f"Unable to write the final ZIP: {error}",
                )
                refresh_report_status(report)

    if args.report is not None and report_destination_available:
        try:
            write_report(report, args.report, args.overwrite)
        except (OSError, ValueError) as error:
            add_issue(
                report["issues"],
                "error",
                "report_write_failed",
                str(args.report),
                f"Unable to write the preflight report: {error}",
            )
            refresh_report_status(report)
    print_report(report)
    if report["status"] == "blocked":
        return 2
    if report["status"] == "valid_with_warnings":
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
