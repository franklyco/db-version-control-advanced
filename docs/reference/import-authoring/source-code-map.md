# Source Code Map

Status: Current
Last verified: 2026-06-22
Source of truth: runtime classes listed below.
Read when: maintaining DBVC AI package docs or changing package behavior.
Minimum context: `maintenance-contract.md`.

## Fast Path

If docs and runtime disagree, runtime wins. Update docs in the same pass that changes runtime behavior.

## Current Contract

| Contract Area | Runtime Source |
|---|---|
| AI package settings | `includes/Dbvc/AiPackage/Settings.php` |
| sample package storage | `includes/Dbvc/AiPackage/Storage.php` |
| sample package build flow | `includes/Dbvc/AiPackage/SamplePackageBuilder.php` |
| compact schema output | `includes/Dbvc/AiPackage/CompactSchemaBuilder.php` |
| root package docs | `includes/Dbvc/AiPackage/PackageDocBuilder.php` |
| sample templates | `includes/Dbvc/AiPackage/TemplateBuilder.php` |
| ACF discovery | `includes/Dbvc/AiPackage/AcfDiscoveryService.php` |
| schema discovery | `includes/Dbvc/AiPackage/SchemaDiscoveryService.php` |
| observed shapes | `includes/Dbvc/AiPackage/ObservedShapeService.php` |
| validation rules artifact | `includes/Dbvc/AiPackage/RulesService.php` |
| site fingerprint | `includes/Dbvc/AiPackage/SiteFingerprintService.php` |
| submission package detection | `includes/Dbvc/AiPackage/SubmissionPackageDetector.php` |
| submission validation | `includes/Dbvc/AiPackage/SubmissionPackageValidator.php` |
| submission translation | `includes/Dbvc/AiPackage/SubmissionPackageTranslator.php` |
| submission import | `includes/Dbvc/AiPackage/SubmissionPackageImporter.php` |
| import report formatting | `includes/Dbvc/AiPackage/ImportReportFormatter.php` |
| validation report formatting | `includes/Dbvc/AiPackage/ValidationReportFormatter.php` |
| issue grouping | `includes/Dbvc/AiPackage/IssueService.php` |

## Authoring Rules

Use this file for maintainer orientation only. It is not part of the minimum context for an AI agent that is only creating content.

## Nuance

The proposed implementation docs may contain useful history, but current behavior should be verified against the runtime classes above before promoting guidance into this reference folder.

Vertical Field Context and Object Type Context runtime behavior for Content Migration V2 is documented in module-local docs under `addons/content-migration/docs/`. Only package-facing minimal context belongs in `vertical-context.md`.

## Examples

No examples. This is a maintainer lookup map.

## Maintenance Notes

Update this file when AI package classes move, split, merge, or change ownership.
