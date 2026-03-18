# Phase 1 Foundation Status

## Implemented
- Settings service: `addons/content-migration/settings/dbvc-cc-settings-service.php`
- Artifact manager: `addons/content-migration/collector/dbvc-cc-artifact-manager.php`
- Schema snapshot service: `addons/content-migration/schema-snapshot/dbvc-cc-schema-snapshot-service.php`

## Contracts Used
- `DBVC_CC_Contracts::OPTION_SETTINGS`
- `DBVC_CC_Contracts::STORAGE_DEFAULT_PATH`
- `DBVC_CC_Contracts::STORAGE_INDEX_FILE`
- `DBVC_CC_Contracts::STORAGE_REDIRECT_MAP_FILE`
- `DBVC_CC_Contracts::STORAGE_EVENTS_LOG_PATH`
- `DBVC_CC_Contracts::ACTION_RUN_SCHEMA_SNAPSHOT`

## Snapshot Output
- Snapshot directory: `uploads/{storage_path}/_schema/`
- Snapshot artifact: `dbvc_cc_schema_snapshot.json`

## Triggering Snapshot Generation
- Auto-generate once when missing on admin init.
- Manual trigger action hook:
  - `do_action( DBVC_CC_Contracts::ACTION_RUN_SCHEMA_SNAPSHOT );`
