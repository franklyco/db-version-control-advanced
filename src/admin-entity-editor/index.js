import { createRoot, useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, Modal, Spinner } from '@wordpress/components';

const apiGet = async (path, { signal } = {}) => {
	const response = await window.fetch(`${DBVC_ENTITY_EDITOR_APP.root}${path}`, {
		headers: {
			'X-WP-Nonce': DBVC_ENTITY_EDITOR_APP.nonce,
		},
		signal,
	});
	if (!response.ok) {
		const text = await response.text();
		throw new Error(text || `Request failed (${response.status})`);
	}
	return response.json();
};

const apiPost = async (path, body) => {
	const response = await window.fetch(`${DBVC_ENTITY_EDITOR_APP.root}${path}`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': DBVC_ENTITY_EDITOR_APP.nonce,
		},
		body: JSON.stringify(body),
	});
	const text = await response.text();
	let parsed = null;
	if (text) {
		try {
			parsed = JSON.parse(text);
		} catch (e) {
			const message = response.ok
				? 'The server returned a non-JSON response. Check the site PHP error log for a fatal error.'
				: `Request failed (${response.status}) and returned a non-JSON response. Check the site PHP error log.`;
			const error = new Error(message);
			error.body = {
				message,
				data: {
					raw: text.slice(0, 1000),
				},
			};
			error.status = response.status;
			throw error;
		}
	}
	if (!response.ok) {
		const error = new Error(parsed?.message || text || `Request failed (${response.status})`);
		error.body = parsed;
		error.status = response.status;
		throw error;
	}
	return parsed || {};
};

const formatDate = (value) => {
	if (!value) return '—';
	const date = new Date(value);
	return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
};

const getEntityDownloadUrl = (relativePath) => {
	if (!relativePath || !DBVC_ENTITY_EDITOR_APP?.download_url) return '';
	const params = new URLSearchParams({
		action: 'dbvc_entity_editor_download',
		path: relativePath,
		_wpnonce: DBVC_ENTITY_EDITOR_APP.download_nonce || '',
	});
	return `${DBVC_ENTITY_EDITOR_APP.download_url}?${params.toString()}`;
};

const collectSearchMatches = (sourceValue, queryValue) => {
	const source = (sourceValue || '').toLowerCase();
	const query = (queryValue || '').trim().toLowerCase();
	if (!query) return [];
	const matches = [];
	let offset = 0;
	while (offset < source.length) {
		const index = source.indexOf(query, offset);
		if (index === -1) break;
		matches.push(index);
		offset = index + Math.max(1, query.length);
	}
	return matches;
};

const formatTransferReferenceWarning = (warning) => {
	const source = warning?.source_post_title || warning?.source_path || 'Selected entity';
	const field = warning?.field_label || warning?.meta_key || 'Unknown field';
	const target = warning?.referenced_post_title || warning?.referenced_post_name || `Post #${warning?.referenced_post_id || '?'}`;
	return `${source}: ${field} references ${target}, but that post is not included in this packet.`;
};

const getDuplicateMatchBasisLabel = (basis) => {
	if (basis === 'payload_entity_id') return 'Matched by local DB ID';
	if (basis === 'matched_wp_id') return 'Matched by current WP ID';
	if (basis === 'vf_object_uid') return 'Matched by vf_object_uid';
	return 'Duplicate file group';
};

const getRawIntakeActionLabel = (action) => {
	if (action === 'update_matched') return 'update matched entity';
	if (action === 'stage') return 'stage JSON only';
	if (action === 'blocked') return 'blocked';
	return 'create new entity';
};

const getSyncImportActionLabel = (action) => {
	if (action === 'create') return 'create new entity';
	if (action === 'created') return 'created entity';
	if (action === 'update_matched') return 'update matched entity';
	if (action === 'updated') return 'updated entity';
	if (action === 'blocked') return 'blocked';
	return 'preview';
};

const getBricksAdvisoryNoticeClass = (advisory) => (
	advisory?.severity === 'warning' ? 'notice-warning' : 'notice-info'
);

const formatSyncImportDetailValue = (value) => {
	if (Array.isArray(value)) return value.length ? value.join(', ') : 'empty';
	if (value === true) return 'true';
	if (value === false) return 'false';
	if (value === null || value === undefined || value === '') return 'empty';
	return String(value);
};

const ImportWarningNotes = ({ warnings = [] }) => {
	if (!Array.isArray(warnings) || warnings.length === 0) return null;
	return (
		<div className="notice notice-warning" style={{ margin: '8px 0 0' }}>
			<p><strong>Notes</strong></p>
			<ul style={{ marginLeft: '18px' }}>
				{warnings.map((warning, index) => (
					<li key={`${warning?.code || 'warning'}-${index}`}>{warning?.message || 'Warning'}</li>
				))}
			</ul>
		</div>
	);
};

const ImportBlockerPanel = ({
	blocking = [],
	blockerDetails = [],
	settingsLinks = [],
	settingRemediations = [],
	advancedOverrides = [],
	canonicalRelativePath = '',
	onOpenCanonical,
	onApplyAction,
	buildBusyKey,
	busyKey = '',
	disabled = false,
}) => {
	if (!Array.isArray(blocking) || blocking.length === 0) return null;

	const details = Array.isArray(blockerDetails) ? blockerDetails : [];
	const links = Array.isArray(settingsLinks) ? settingsLinks : [];
	const remediations = Array.isArray(settingRemediations) ? settingRemediations : [];
	const overrides = Array.isArray(advancedOverrides) ? advancedOverrides : [];
	const hasActions = links.length > 0 || remediations.length > 0 || overrides.length > 0 || !!canonicalRelativePath;

	return (
		<div className="notice notice-warning" style={{ margin: '8px 0 0' }}>
			<p><strong>Blockers and guidance</strong></p>
			{details.length > 0 ? (
				<ul style={{ marginLeft: '18px' }}>
					{details.map((detail, index) => (
						<li key={`${detail?.code || 'blocker-detail'}-${index}`}>
							{detail?.category ? `${detail.category}: ` : ''}
							{detail?.message || detail?.code || 'Import blocked'}
							{detail?.option ? ` · Setting: ${detail.option}` : ''}
							{Object.prototype.hasOwnProperty.call(detail || {}, 'current_value') ? ` · Current: ${formatSyncImportDetailValue(detail.current_value)}` : ''}
							{detail?.post_type ? ` · Post type: ${detail.post_type}` : ''}
							{detail?.taxonomy ? ` · Taxonomy: ${detail.taxonomy}` : ''}
							{detail?.canonical_relative_path ? ` · Canonical: ${detail.canonical_relative_path}` : ''}
							{detail?.relative_path ? ` · Sync file: ${detail.relative_path}` : ''}
							{detail?.match?.id ? ` · Matched entity: ${detail.match.kind || 'entity'} #${detail.match.id}` : ''}
						</li>
					))}
				</ul>
			) : (
				<ul style={{ marginLeft: '18px' }}>
					{blocking.map((blocker, index) => (
						<li key={`${blocker?.code || 'blocked'}-${index}`}>{blocker?.message || 'Blocked'}</li>
					))}
				</ul>
			)}
			{hasActions && (
				<div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px', marginTop: '8px' }}>
					{links.map((link) => (
						<a key={link?.id || link?.url} href={link?.url || '#'} className="button button-secondary">
							{link?.label || 'Open settings'}
						</a>
					))}
					{canonicalRelativePath && (
						<Button
							variant="secondary"
							onClick={() => onOpenCanonical?.(canonicalRelativePath)}
							disabled={disabled || !onOpenCanonical}
						>
							Open canonical JSON
						</Button>
					)}
					{remediations.map((remediation) => {
						const actionBusyKey = buildBusyKey ? buildBusyKey(remediation) : remediation?.id || '';
						return (
							<Button
								key={remediation?.id || remediation?.label}
								variant="secondary"
								onClick={() => onApplyAction?.(remediation)}
								disabled={disabled || !onApplyAction}
								isBusy={busyKey === actionBusyKey}
							>
								{remediation?.label || 'Apply setting fix'}
							</Button>
						);
					})}
					{overrides.map((override) => {
						const actionBusyKey = buildBusyKey ? buildBusyKey(override) : override?.id || '';
						return (
							<Button
								key={override?.id || override?.label}
								variant={override?.id === 'archive_stale_duplicate' ? 'secondary' : 'primary'}
								onClick={() => onApplyAction?.(override)}
								disabled={disabled || !onApplyAction}
								isBusy={busyKey === actionBusyKey}
							>
								{override?.label || 'Apply'}
							</Button>
						);
					})}
				</div>
			)}
		</div>
	);
};

const collectImportBlockerMessages = (items = [], limit = 4) => {
	const messages = [];
	(Array.isArray(items) ? items : []).forEach((item) => {
		const blocking = Array.isArray(item?.blocking) ? item.blocking : [];
		blocking.forEach((blocker) => {
			const message = blocker?.message || blocker?.code || '';
			if (message && !messages.includes(message)) {
				messages.push(message);
			}
		});
	});
	return messages.slice(0, limit);
};

const getSyncImportItemPath = (item) => item?.relative_path || item?.source_relative_path || '';

const isSyncImportMatchedUpdateEligible = (item) => (
	!item?.updated
	&& !!item?.matched_update?.eligible
	&& !!item?.available_actions?.update_matched
);

const isRawIntakeMatchedUpdateEligible = (preview, mode) => (
	mode === 'create_or_update_matched'
	&& preview?.detected_action === 'update_matched'
	&& !!preview?.matched_update?.eligible
	&& !!preview?.available_actions?.create_or_update_matched
);

const getEntityImportStatusRank = (item) => (item?.matched_wp?.id ? 1 : 0);

const EntityEditorApp = () => {
	const [entityIndex, setEntityIndex] = useState([]);
	const [entityIndexStats, setEntityIndexStats] = useState(null);
	const [entityIndexLoading, setEntityIndexLoading] = useState(false);
	const [entityIndexError, setEntityIndexError] = useState('');
	const [entityIndexErrorItems, setEntityIndexErrorItems] = useState([]);
	const [entityIndexNotice, setEntityIndexNotice] = useState('');

	const [entityKindFilter, setEntityKindFilter] = useState('all');
	const [entitySubtypeFilter, setEntitySubtypeFilter] = useState('all');
	const [entitySearch, setEntitySearch] = useState('');
	const [entityBulkAction, setEntityBulkAction] = useState('');
	const [entitySort, setEntitySort] = useState({ key: 'mtime', direction: 'desc' });
	const [entityPage, setEntityPage] = useState(1);
	const [selectedEntityPaths, setSelectedEntityPaths] = useState([]);

	const [selectedEntityFile, setSelectedEntityFile] = useState('');
	const [entityFileData, setEntityFileData] = useState(null);
	const [entityFileLoading, setEntityFileLoading] = useState(false);
	const [entityFileError, setEntityFileError] = useState('');
	const [entityEditorDraft, setEntityEditorDraft] = useState('');

	const [entityLockToken, setEntityLockToken] = useState('');
	const [entityLockInfo, setEntityLockInfo] = useState(null);
	const [entityLockConflict, setEntityLockConflict] = useState(null);

	const [entitySaveBusy, setEntitySaveBusy] = useState(false);
	const [entityImportBusy, setEntityImportBusy] = useState(false);
	const [entityReplaceBusy, setEntityReplaceBusy] = useState(false);
	const [entityDeleteBusy, setEntityDeleteBusy] = useState(false);
	const [entitySaveNotice, setEntitySaveNotice] = useState('');
	const [entitySaveError, setEntitySaveError] = useState('');
	const [entityEditorSearch, setEntityEditorSearch] = useState('');
	const [entityEditorSearchIndex, setEntityEditorSearchIndex] = useState(-1);

	const [fullReplaceModalOpen, setFullReplaceModalOpen] = useState(false);
	const [fullReplaceConfirmPhrase, setFullReplaceConfirmPhrase] = useState('');
	const [fullReplaceModalError, setFullReplaceModalError] = useState('');
	const [fullReplaceNeedsTakeover, setFullReplaceNeedsTakeover] = useState(false);
	const [transferPreviewOpen, setTransferPreviewOpen] = useState(false);
	const [transferPreviewLoading, setTransferPreviewLoading] = useState(false);
	const [transferPreviewError, setTransferPreviewError] = useState('');
	const [transferPreviewData, setTransferPreviewData] = useState(null);
	const [rawIntakeOpen, setRawIntakeOpen] = useState(false);
	const [rawIntakeDraft, setRawIntakeDraft] = useState('');
	const [rawIntakeMode, setRawIntakeMode] = useState('create_only');
	const [rawIntakeOpenAfterSuccess, setRawIntakeOpenAfterSuccess] = useState(true);
	const [rawIntakePreviewBusy, setRawIntakePreviewBusy] = useState(false);
	const [rawIntakeCommitBusy, setRawIntakeCommitBusy] = useState(false);
	const [rawIntakeError, setRawIntakeError] = useState('');
	const [rawIntakePreview, setRawIntakePreview] = useState(null);
	const [rawIntakeUpdateConfirmation, setRawIntakeUpdateConfirmation] = useState(null);
	const [syncImportOpen, setSyncImportOpen] = useState(false);
	const [syncImportPath, setSyncImportPath] = useState('');
	const [syncImportPaths, setSyncImportPaths] = useState([]);
	const [syncImportPreviewBusy, setSyncImportPreviewBusy] = useState(false);
	const [syncImportCommitBusy, setSyncImportCommitBusy] = useState(false);
	const [syncImportRemediationBusy, setSyncImportRemediationBusy] = useState('');
	const [syncImportError, setSyncImportError] = useState('');
	const [syncImportNotice, setSyncImportNotice] = useState('');
	const [syncImportPreview, setSyncImportPreview] = useState(null);
	const [syncImportUpdateConfirmations, setSyncImportUpdateConfirmations] = useState({});
	const entityEditorTextareaRef = useRef(null);

	const entityPerPage = 20;

	const loadEntityIndex = useCallback(async (force = false) => {
		setEntityIndexLoading(true);
		setEntityIndexError('');
		setEntityIndexErrorItems([]);
		try {
			const data = force
				? await apiPost('entity-editor/index/rebuild', {})
				: await apiGet('entity-editor/index');
			setEntityIndex(Array.isArray(data?.items) ? data.items : []);
			setEntityIndexStats(data?.stats || null);
		} catch (error) {
			setEntityIndexError(error?.message || 'Failed to load entity index');
			setEntityIndexErrorItems([]);
		} finally {
			setEntityIndexLoading(false);
		}
	}, []);

	const loadEntityEditorFile = useCallback(async (path, forceTakeover = false) => {
		if (!path) return;
		setEntityFileLoading(true);
		setEntityFileError('');
		setEntityLockConflict(null);
		try {
			const data = await apiGet(
				`entity-editor/file?path=${encodeURIComponent(path)}${forceTakeover ? '&force_takeover=1' : ''}`
			);
			setEntityFileData(data);
			setEntityEditorDraft(data?.content || '');
			setEntitySaveError('');
			setEntitySaveNotice('');
			setEntityLockToken(data?.lock?.token || '');
			setEntityLockInfo(data?.lock || null);
		} catch (error) {
			setEntityFileData(null);
			setEntityFileError(error?.message || 'Failed to load entity file');
			setEntityLockToken('');
			setEntityLockInfo(null);
			setEntityLockConflict(error?.body?.data?.lock || null);
		} finally {
			setEntityFileLoading(false);
		}
	}, []);

	const normalizedEntityEditorSearch = entityEditorSearch.trim();
	const entityEditorSearchMatches = useMemo(
		() => collectSearchMatches(entityEditorDraft, normalizedEntityEditorSearch),
		[entityEditorDraft, normalizedEntityEditorSearch]
	);

	const jumpToEntityEditorSearchMatch = useCallback((matchIndex, matches, query, { keepInputFocus = true } = {}) => {
		if (!Array.isArray(matches) || !matches.length || !query) return;
		const textarea = entityEditorTextareaRef.current || document.querySelector('textarea.dbvc-entity-editor__textarea');
		if (!textarea) return;
		const activeElement = document.activeElement;
		const clampedIndex = Math.max(0, Math.min(matchIndex, matches.length - 1));
		const start = matches[clampedIndex];
		const end = start + query.length;
		textarea.focus();
		textarea.setSelectionRange(start, end);
		const textBefore = textarea.value.slice(0, start);
		const lineNumber = (textBefore.match(/\n/g) || []).length;
		const lineHeight = Number.parseFloat(window.getComputedStyle(textarea).lineHeight) || 20;
		textarea.scrollTop = Math.max(0, (lineNumber * lineHeight) - (textarea.clientHeight * 0.35));
		if (keepInputFocus && activeElement && activeElement !== textarea && typeof activeElement.focus === 'function') {
			activeElement.focus();
		}
		setEntityEditorSearchIndex(clampedIndex);
	}, []);

	const getEntityEditorSearchCursor = useCallback(() => {
		const textarea = entityEditorTextareaRef.current || document.querySelector('textarea.dbvc-entity-editor__textarea');
		if (!textarea) return 0;
		return Math.max(0, Number(textarea.selectionStart || 0));
	}, []);

	useEffect(() => {
		if (entityIndex.length === 0 && !entityIndexLoading && !entityIndexError) {
			loadEntityIndex(false);
		}
	}, [entityIndex.length, entityIndexLoading, entityIndexError, loadEntityIndex]);

	useEffect(() => {
		if (!selectedEntityFile) {
			setEntityFileData(null);
			setEntityLockToken('');
			setEntityLockInfo(null);
			setEntityLockConflict(null);
			setEntityFileError('');
			setEntityEditorSearch('');
			setEntityEditorSearchIndex(-1);
			return;
		}
		loadEntityEditorFile(selectedEntityFile, false);
	}, [selectedEntityFile, loadEntityEditorFile]);

	useEffect(() => {
		if (!normalizedEntityEditorSearch || !entityEditorSearchMatches.length) {
			setEntityEditorSearchIndex(-1);
			return;
		}
		const cursor = getEntityEditorSearchCursor();
		const selectedMatchIndex = entityEditorSearchMatches.findIndex((position) => position === cursor);
		if (selectedMatchIndex >= 0) {
			setEntityEditorSearchIndex(selectedMatchIndex);
			return;
		}
		const nearestIndex = entityEditorSearchMatches.findIndex((position) => position > cursor);
		setEntityEditorSearchIndex(nearestIndex >= 0 ? nearestIndex : entityEditorSearchMatches.length - 1);
	}, [entityEditorSearchMatches, normalizedEntityEditorSearch, getEntityEditorSearchCursor]);

	useEffect(() => {
		setEntitySubtypeFilter('all');
		setEntityPage(1);
	}, [entityKindFilter]);

	useEffect(() => {
		setRawIntakePreview(null);
		setRawIntakeError('');
	}, [rawIntakeDraft, rawIntakeMode]);

	useEffect(() => {
		const available = new Set(entityIndex.map((item) => item.relative_path).filter(Boolean));
		setSelectedEntityPaths((current) => current.filter((path) => available.has(path)));
	}, [entityIndex]);

	const saveEntityEditorFile = useCallback(async (forceTakeover = false) => {
		if (!selectedEntityFile) return;
		setEntitySaveError('');
		setEntitySaveNotice('');
		setEntityLockConflict(null);
		try {
			JSON.parse(entityEditorDraft || '{}');
		} catch (error) {
			setEntitySaveError(error?.message || 'Invalid JSON');
			return;
		}
		setEntitySaveBusy(true);
		try {
			const data = await apiPost('entity-editor/file/save', {
				path: selectedEntityFile,
				content: entityEditorDraft,
				lock_token: entityLockToken || '',
				force_takeover: !!forceTakeover,
			});
			setEntityFileData((current) => ({ ...current, ...data }));
			setEntityEditorDraft(data?.content || entityEditorDraft);
			setEntitySaveNotice(forceTakeover ? 'Saved JSON and took over lock.' : 'Saved JSON to sync folder.');
			setEntityLockToken(data?.lock?.token || entityLockToken);
			setEntityLockInfo(data?.lock || entityLockInfo);
			setEntityIndex([]);
		} catch (error) {
			setEntitySaveError(error?.message || 'Failed to save JSON');
			setEntityLockConflict(error?.body?.data?.lock || null);
		} finally {
			setEntitySaveBusy(false);
		}
	}, [selectedEntityFile, entityEditorDraft, entityLockToken, entityLockInfo]);

	const partialImportEntityEditorFile = useCallback(async (forceTakeover = false) => {
		if (!selectedEntityFile) return;
		setEntitySaveError('');
		setEntitySaveNotice('');
		setEntityLockConflict(null);
		try {
			JSON.parse(entityEditorDraft || '{}');
		} catch (error) {
			setEntitySaveError(error?.message || 'Invalid JSON');
			return;
		}
		setEntityImportBusy(true);
		try {
			const data = await apiPost('entity-editor/file/import-partial', {
				path: selectedEntityFile,
				content: entityEditorDraft,
				lock_token: entityLockToken || '',
				force_takeover: !!forceTakeover,
			});
			const counts = data?.import_result?.counts || {};
			setEntityFileData((current) => ({ ...current, ...data }));
			setEntityEditorDraft(data?.content || entityEditorDraft);
			setEntityLockToken(data?.lock?.token || entityLockToken);
			setEntityLockInfo(data?.lock || entityLockInfo);
			setEntitySaveNotice(
				`Saved + partial import complete (fields: ${counts.core_fields_updated ?? 0}, meta: ${counts.meta_keys_updated ?? 0}, tax: ${counts.taxonomies_updated ?? 0}).`
			);
			setEntityIndex([]);
		} catch (error) {
			setEntitySaveError(error?.message || 'Failed to run partial import');
			setEntityLockConflict(error?.body?.data?.lock || null);
		} finally {
			setEntityImportBusy(false);
		}
	}, [selectedEntityFile, entityEditorDraft, entityLockToken, entityLockInfo]);

	const fullReplaceEntityEditorFile = useCallback(async (forceTakeover = false, confirmPhrase = '') => {
		if (!selectedEntityFile) return;
		setEntitySaveError('');
		setEntitySaveNotice('');
		setEntityLockConflict(null);
		const phrase = (confirmPhrase || '').trim();
		if (phrase !== 'REPLACE') {
			setEntitySaveError('Full replace requires typing "REPLACE".');
			return;
		}
		try {
			JSON.parse(entityEditorDraft || '{}');
		} catch (error) {
			setEntitySaveError(error?.message || 'Invalid JSON');
			return;
		}
		setEntityReplaceBusy(true);
		try {
			const data = await apiPost('entity-editor/file/import-replace', {
				path: selectedEntityFile,
				content: entityEditorDraft,
				confirm_phrase: phrase,
				lock_token: entityLockToken || '',
				force_takeover: !!forceTakeover,
			});
			const counts = data?.import_result?.counts || {};
			const snapshot = data?.import_result?.snapshot_path || '';
			setEntityFileData((current) => ({ ...current, ...data }));
			setEntityEditorDraft(data?.content || entityEditorDraft);
			setEntityLockToken(data?.lock?.token || entityLockToken);
			setEntityLockInfo(data?.lock || entityLockInfo);
			setEntitySaveNotice(
				`Saved + full replace complete (fields: ${counts.core_fields_updated ?? 0}, meta updated: ${counts.meta_keys_updated ?? 0}, meta deleted: ${counts.meta_keys_deleted ?? 0}, tax: ${counts.taxonomies_updated ?? 0})${snapshot ? `; snapshot: ${snapshot}` : ''}.`
			);
			setEntityIndex([]);
		} catch (error) {
			setEntitySaveError(error?.message || 'Failed to run full replace');
			setEntityLockConflict(error?.body?.data?.lock || null);
		} finally {
			setEntityReplaceBusy(false);
		}
	}, [selectedEntityFile, entityEditorDraft, entityLockToken, entityLockInfo]);

	const openFullReplaceModal = useCallback((needsTakeover = false) => {
		setFullReplaceNeedsTakeover(!!needsTakeover);
		setFullReplaceConfirmPhrase('');
		setFullReplaceModalError('');
		setFullReplaceModalOpen(true);
	}, []);

	const closeFullReplaceModal = useCallback(() => {
		setFullReplaceModalOpen(false);
		setFullReplaceConfirmPhrase('');
		setFullReplaceModalError('');
		setFullReplaceNeedsTakeover(false);
	}, []);

	const closeTransferPreview = useCallback(() => {
		setTransferPreviewOpen(false);
		setTransferPreviewLoading(false);
		setTransferPreviewError('');
		setTransferPreviewData(null);
	}, []);

	const openRawIntakeModal = useCallback(() => {
		setRawIntakeOpen(true);
		setRawIntakeDraft('');
		setRawIntakeMode('create_only');
		setRawIntakeOpenAfterSuccess(true);
		setRawIntakePreview(null);
		setRawIntakeError('');
		setRawIntakeUpdateConfirmation(null);
	}, []);

	const closeRawIntakeModal = useCallback(() => {
		setRawIntakeOpen(false);
		setRawIntakePreviewBusy(false);
		setRawIntakeCommitBusy(false);
		setRawIntakeError('');
		setRawIntakePreview(null);
		setRawIntakeUpdateConfirmation(null);
	}, []);

	const closeSyncImportModal = useCallback(() => {
		setSyncImportOpen(false);
		setSyncImportPath('');
		setSyncImportPaths([]);
		setSyncImportPreviewBusy(false);
		setSyncImportCommitBusy(false);
		setSyncImportRemediationBusy('');
		setSyncImportError('');
		setSyncImportNotice('');
		setSyncImportPreview(null);
		setSyncImportUpdateConfirmations({});
	}, []);

	const previewRawIntake = useCallback(async () => {
		setRawIntakePreviewBusy(true);
		setRawIntakeError('');
		setRawIntakeUpdateConfirmation(null);
		try {
			const data = await apiPost('entity-editor/raw-intake/preview', {
				content: rawIntakeDraft,
				mode: rawIntakeMode,
			});
			setRawIntakePreview(data);
		} catch (error) {
			setRawIntakeError(error?.message || 'Failed to preview raw JSON intake');
			setRawIntakePreview(error?.body?.data?.preview || null);
		} finally {
			setRawIntakePreviewBusy(false);
		}
	}, [rawIntakeDraft, rawIntakeMode]);

	const setRawIntakeMatchedUpdateConfirmed = useCallback((confirmed) => {
		if (!confirmed || !rawIntakePreview) {
			setRawIntakeUpdateConfirmation(null);
			return;
		}

		setRawIntakeUpdateConfirmation({
			confirmed: true,
			preview_hash: rawIntakePreview?.preview_hash || '',
			matched_entity_id: rawIntakePreview?.matched_update?.wp_entity?.id || rawIntakePreview?.match?.id || 0,
		});
	}, [rawIntakePreview]);

	const openSyncImportPreview = useCallback(async (paths) => {
		const normalizedPaths = (Array.isArray(paths) ? paths : [paths])
			.map((path) => (path || '').toString())
			.filter(Boolean);
		if (!normalizedPaths.length) return;
		setSyncImportOpen(true);
		setSyncImportPath(normalizedPaths[0] || '');
		setSyncImportPaths(normalizedPaths);
		setSyncImportPreview(null);
		setSyncImportError('');
		setSyncImportNotice('');
		setSyncImportUpdateConfirmations({});
		setSyncImportPreviewBusy(true);
		try {
			const data = await apiPost('entity-editor/sync-file-import/preview', {
				paths: normalizedPaths,
				mode: 'create_only',
			});
			setSyncImportPreview(data);
		} catch (error) {
			setSyncImportError(error?.message || 'Failed to preview sync-file import');
			setSyncImportPreview(error?.body?.data?.preview || null);
		} finally {
			setSyncImportPreviewBusy(false);
		}
	}, []);

	const remediateSyncImport = useCallback(async (item, remediation) => {
		const path = item?.relative_path || item?.source_relative_path || '';
		const remediationId = remediation?.id || '';
		if (!path || !remediationId) return;

		if (remediation?.requires_confirmation) {
			const message = remediation?.description || 'Apply this import fix?';
			if (!window.confirm(message)) return;
		}

		const busyKey = `${path}:${remediationId}`;
		setSyncImportRemediationBusy(busyKey);
		setSyncImportError('');
		setSyncImportNotice('');
		try {
			const data = await apiPost('entity-editor/sync-file-import/remediate', {
				path,
				mode: 'create_only',
				remediation: remediationId,
				preview_hash: item?.preview_hash || '',
			});
			setSyncImportPreview(data);
			setSyncImportUpdateConfirmations({});
			const previewPaths = Array.isArray(data?.items)
				? data.items.map((previewItem) => previewItem?.relative_path || previewItem?.source_relative_path || '').filter(Boolean)
				: [];
			if (previewPaths.length) {
				setSyncImportPath(previewPaths[0]);
				setSyncImportPaths(previewPaths);
			}
			setSyncImportNotice(data?.remediation_result?.action ? 'Import blocker fix applied. Review the refreshed preview before creating entities.' : '');
			await loadEntityIndex(true);
		} catch (error) {
			setSyncImportError(error?.message || 'Failed to apply import fix');
			setSyncImportPreview(error?.body?.data?.preview || syncImportPreview);
		} finally {
			setSyncImportRemediationBusy('');
		}
	}, [loadEntityIndex, syncImportPreview]);

	const setSyncImportMatchedUpdateConfirmed = useCallback((item, confirmed) => {
		const path = getSyncImportItemPath(item);
		if (!path) return;

		setSyncImportUpdateConfirmations((previous) => {
			const next = { ...previous };
			if (!confirmed) {
				delete next[path];
				return next;
			}

			next[path] = {
				confirmed: true,
				preview_hash: item?.preview_hash || '',
				matched_entity_id: item?.matched_update?.wp_entity?.id || item?.match?.id || 0,
			};
			return next;
		});
	}, []);

	const commitSyncImport = useCallback(async () => {
		const paths = syncImportPaths.length ? syncImportPaths : (syncImportPath ? [syncImportPath] : []);
		if (!paths.length) return;
		setSyncImportCommitBusy(true);
		setSyncImportError('');
		try {
			const data = await apiPost('entity-editor/sync-file-import/commit', {
				paths,
				mode: 'create_only',
			});
			const summary = data?.summary || {};
			setSyncImportPreview(data);
			setSyncImportUpdateConfirmations({});
			setEntityIndexNotice(
				`Sync-file import complete: created ${summary?.created ?? 0}, blocked ${summary?.blocked ?? 0}, skipped ${summary?.skipped ?? 0}, errors ${summary?.errors ?? 0}.`
			);
			setEntityIndexError('');
			setEntityIndexErrorItems([]);
			await loadEntityIndex(true);
		} catch (error) {
			setSyncImportError(error?.message || 'Failed to import sync file');
			setSyncImportPreview(error?.body?.data?.preview || syncImportPreview);
		} finally {
			setSyncImportCommitBusy(false);
		}
	}, [loadEntityIndex, syncImportPath, syncImportPaths, syncImportPreview]);

	const commitSyncImportMatchedUpdates = useCallback(async () => {
		const previewItems = Array.isArray(syncImportPreview?.items) ? syncImportPreview.items : [];
		const updateItems = previewItems.filter(isSyncImportMatchedUpdateEligible);
		const confirmedItems = updateItems.filter((item) => {
			const path = getSyncImportItemPath(item);
			const confirmation = path ? syncImportUpdateConfirmations[path] : null;
			return !!confirmation?.confirmed
				&& confirmation.preview_hash === item?.preview_hash
				&& Number(confirmation.matched_entity_id || 0) === Number(item?.matched_update?.wp_entity?.id || item?.match?.id || 0);
		});
		if (!updateItems.length || confirmedItems.length !== updateItems.length) return;

		const paths = confirmedItems.map(getSyncImportItemPath).filter(Boolean);
		const confirmations = {};
		confirmedItems.forEach((item) => {
			const path = getSyncImportItemPath(item);
			if (!path) return;
			confirmations[path] = {
				confirmed: true,
				preview_hash: item?.preview_hash || '',
				matched_entity_id: item?.matched_update?.wp_entity?.id || item?.match?.id || 0,
			};
		});

		setSyncImportCommitBusy(true);
		setSyncImportError('');
		try {
			const data = await apiPost('entity-editor/sync-file-import/commit', {
				paths,
				mode: 'update_matched',
				confirmations,
			});
			const summary = data?.summary || {};
			setSyncImportPreview(data);
			setSyncImportUpdateConfirmations({});
			setEntityIndexNotice(
				`Sync-file matched update complete: updated ${summary?.updated ?? 0}, blocked ${summary?.blocked ?? 0}, skipped ${summary?.skipped ?? 0}, errors ${summary?.errors ?? 0}.`
			);
			setEntityIndexError('');
			setEntityIndexErrorItems([]);
			await loadEntityIndex(true);
		} catch (error) {
			setSyncImportError(error?.message || 'Failed to update matched entity from sync file');
			setSyncImportPreview(error?.body?.data?.preview || syncImportPreview);
		} finally {
			setSyncImportCommitBusy(false);
		}
	}, [loadEntityIndex, syncImportPreview, syncImportUpdateConfirmations]);

	const commitRawIntake = useCallback(async () => {
		setRawIntakeCommitBusy(true);
		setRawIntakeError('');
		const matchedUpdate = isRawIntakeMatchedUpdateEligible(rawIntakePreview, rawIntakeMode);
		const confirmation = matchedUpdate && rawIntakeUpdateConfirmation?.confirmed
			? {
				confirmed: true,
				preview_hash: rawIntakeUpdateConfirmation.preview_hash || '',
				matched_entity_id: rawIntakeUpdateConfirmation.matched_entity_id || 0,
			}
			: {};
		try {
			const data = await apiPost('entity-editor/raw-intake/commit', {
				content: rawIntakeDraft,
				mode: rawIntakeMode,
				confirmation,
				open_after_success: !!rawIntakeOpenAfterSuccess,
			});

			const actionLabel = getRawIntakeActionLabel(data?.action);
			setEntityIndexNotice(`Raw JSON intake complete: ${actionLabel}.`);
			setEntityIndexError('');
			setEntityIndexErrorItems([]);
			closeRawIntakeModal();
			await loadEntityIndex(true);
			if (rawIntakeOpenAfterSuccess && data?.relative_path) {
				setSelectedEntityFile(data.relative_path);
			}
		} catch (error) {
			setRawIntakeError(error?.message || 'Failed to commit raw JSON intake');
			setRawIntakePreview(error?.body?.data?.preview || rawIntakePreview);
			setRawIntakeUpdateConfirmation(null);
		} finally {
			setRawIntakeCommitBusy(false);
		}
	}, [closeRawIntakeModal, loadEntityIndex, rawIntakeDraft, rawIntakeMode, rawIntakeOpenAfterSuccess, rawIntakePreview, rawIntakeUpdateConfirmation]);

	const confirmFullReplaceModal = useCallback(async () => {
		const phrase = (fullReplaceConfirmPhrase || '').trim();
		if (phrase !== 'REPLACE') {
			setFullReplaceModalError('Type "REPLACE" to continue.');
			return;
		}
		setFullReplaceModalError('');
		setFullReplaceModalOpen(false);
		await fullReplaceEntityEditorFile(fullReplaceNeedsTakeover, phrase);
	}, [fullReplaceConfirmPhrase, fullReplaceNeedsTakeover, fullReplaceEntityEditorFile]);

	const entitySubtypeOptions = useMemo(() => {
		const values = new Set();
		entityIndex.forEach((item) => {
			if ((entityKindFilter === 'all' || item.entity_kind === entityKindFilter) && item.subtype) {
				values.add(item.subtype);
			}
		});
		return Array.from(values).sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));
	}, [entityIndex, entityKindFilter]);

	const filteredEntityIndex = useMemo(() => {
		const query = entitySearch.trim().toLowerCase();
		return entityIndex.filter((item) => {
			if (entityKindFilter !== 'all' && item.entity_kind !== entityKindFilter) return false;
			if (entitySubtypeFilter !== 'all' && item.subtype !== entitySubtypeFilter) return false;
			if (!query) return true;
			const values = [item.title, item.slug, item.uid, item.relative_path, item.subtype, item.entity_kind]
				.map((value) => (value || '').toString().toLowerCase());
			return values.some((value) => value.includes(query));
		});
	}, [entityIndex, entityKindFilter, entitySubtypeFilter, entitySearch]);

	const sortedEntityIndex = useMemo(() => {
		const list = [...filteredEntityIndex];
		const getValue = (item, key) => {
			if (key === 'import_status') return getEntityImportStatusRank(item);
			if (key === 'mtime') return Number(item?.mtime || 0);
			return (item?.[key] || '').toString().toLowerCase();
		};
		list.sort((a, b) => {
			const left = getValue(a, entitySort.key);
			const right = getValue(b, entitySort.key);
			if (left === right) {
				const leftPath = (a?.relative_path || '').toString().toLowerCase();
				const rightPath = (b?.relative_path || '').toString().toLowerCase();
				if (leftPath === rightPath) return 0;
				return leftPath > rightPath ? 1 : -1;
			}
			const direction = left > right ? 1 : -1;
			return entitySort.direction === 'asc' ? direction : direction * -1;
		});
		return list;
	}, [filteredEntityIndex, entitySort]);

	const entityTotalPages = Math.max(1, Math.ceil(sortedEntityIndex.length / entityPerPage));
	const safeEntityPage = Math.min(entityPage, entityTotalPages);
	const selectedEntityPathSet = useMemo(() => new Set(selectedEntityPaths), [selectedEntityPaths]);
	const filteredEntityPaths = useMemo(() => sortedEntityIndex.map((item) => item.relative_path).filter(Boolean), [sortedEntityIndex]);
	const pagedEntityIndex = useMemo(() => {
		const offset = (safeEntityPage - 1) * entityPerPage;
		return sortedEntityIndex.slice(offset, offset + entityPerPage);
	}, [sortedEntityIndex, safeEntityPage]);
	const pagedEntityPaths = useMemo(() => pagedEntityIndex.map((item) => item.relative_path).filter(Boolean), [pagedEntityIndex]);
	const selectedEntityItems = useMemo(
		() => entityIndex.filter((item) => selectedEntityPathSet.has(item.relative_path)),
		[entityIndex, selectedEntityPathSet]
	);
	const selectedCanonicalDuplicateCount = useMemo(
		() => selectedEntityItems.filter((item) => item.is_canonical_duplicate).length,
		[selectedEntityItems]
	);

	const selectedFilteredCount = useMemo(
		() => filteredEntityPaths.reduce((count, path) => (selectedEntityPathSet.has(path) ? count + 1 : count), 0),
		[filteredEntityPaths, selectedEntityPathSet]
	);
	const allFilteredSelected = filteredEntityPaths.length > 0 && selectedFilteredCount === filteredEntityPaths.length;
	const allPagedSelected = pagedEntityPaths.length > 0 && pagedEntityPaths.every((path) => selectedEntityPathSet.has(path));
	const unimportedEntityCount = useMemo(
		() => entityIndex.reduce((count, item) => count + (getEntityImportStatusRank(item) === 0 ? 1 : 0), 0),
		[entityIndex]
	);
	const busyAny = entitySaveBusy || entityImportBusy || entityReplaceBusy || entityDeleteBusy || syncImportPreviewBusy || syncImportCommitBusy || !!syncImportRemediationBusy;
	const entityBulkActionNeedsSelection = entityBulkAction === 'download_selected'
		|| entityBulkAction === 'remove_selected'
		|| entityBulkAction === 'clear_selection'
		|| entityBulkAction === 'preview_import_selected';
	const entityBulkActionDisabled = !entityBulkAction
		|| busyAny
		|| (entityBulkAction === 'select_filtered' && (!filteredEntityPaths.length || allFilteredSelected))
		|| (entityBulkAction === 'deselect_filtered' && !selectedFilteredCount)
		|| (entityBulkActionNeedsSelection && !selectedEntityPaths.length);

	const toggleEntitySort = (key) => {
		setEntityPage(1);
		setEntitySort((current) => (
			current.key === key
				? { key, direction: current.direction === 'asc' ? 'desc' : 'asc' }
				: { key, direction: 'asc' }
		));
	};

	const clearEntityEditorFile = () => {
		setSelectedEntityFile('');
		setEntityFileData(null);
		setEntityEditorDraft('');
		setEntitySaveNotice('');
		setEntitySaveError('');
		setEntityLockToken('');
		setEntityLockInfo(null);
		setEntityLockConflict(null);
		setFullReplaceModalOpen(false);
		setFullReplaceConfirmPhrase('');
		setFullReplaceModalError('');
		setFullReplaceNeedsTakeover(false);
	};

	const toggleEntityRowSelection = (path, checked) => {
		if (!path) return;
		setSelectedEntityPaths((current) => {
			const next = new Set(current);
			if (checked) {
				next.add(path);
			} else {
				next.delete(path);
			}
			return Array.from(next);
		});
	};

	const setFilteredSelection = (checked) => {
		setSelectedEntityPaths((current) => {
			const next = new Set(current);
			filteredEntityPaths.forEach((path) => {
				if (checked) {
					next.add(path);
				} else {
					next.delete(path);
				}
			});
			return Array.from(next);
		});
	};

	const setPagedSelection = (checked) => {
		setSelectedEntityPaths((current) => {
			const next = new Set(current);
			pagedEntityPaths.forEach((path) => {
				if (checked) {
					next.add(path);
				} else {
					next.delete(path);
				}
			});
			return Array.from(next);
		});
	};

	const clearSelection = () => setSelectedEntityPaths([]);

	const removeSelectedEntityFiles = useCallback(async () => {
		if (!selectedEntityPaths.length || entityDeleteBusy) return;

		const selectionCount = selectedEntityPaths.length;
		let confirmation = `Remove ${selectionCount} selected sync JSON file${selectionCount === 1 ? '' : 's'} from the Entity Editor index? This only deletes the file${selectionCount === 1 ? '' : 's'} from the sync folder. It does not delete the WordPress ${selectionCount === 1 ? 'entity' : 'entities'}.`;
		if (selectedCanonicalDuplicateCount > 0) {
			confirmation += ` ${selectedCanonicalDuplicateCount} selected row${selectedCanonicalDuplicateCount === 1 ? ' is' : 's are'} currently marked as the latest valid file.`;
		}

		if (!window.confirm(confirmation)) {
			return;
		}

		setEntityDeleteBusy(true);
		setEntityIndexNotice('');
		setEntityIndexError('');
		setEntityIndexErrorItems([]);

		try {
			const data = await apiPost('entity-editor/files/delete', {
				paths: selectedEntityPaths,
			});

			const deletedPaths = Array.isArray(data?.deleted_paths) ? data.deleted_paths : [];
			const deletedSet = new Set(deletedPaths);
			const deleteErrors = Array.isArray(data?.errors) ? data.errors : [];
			const refreshedIndex = data?.index || {};

			setEntityIndex(Array.isArray(refreshedIndex?.items) ? refreshedIndex.items : []);
			setEntityIndexStats(refreshedIndex?.stats || null);
			setSelectedEntityPaths((current) => current.filter((path) => !deletedSet.has(path)));

			if (selectedEntityFile && deletedSet.has(selectedEntityFile)) {
				clearEntityEditorFile();
			}

			if (deletedPaths.length > 0) {
				const notice = deleteErrors.length > 0
					? `Removed ${deletedPaths.length} sync file${deletedPaths.length === 1 ? '' : 's'}. ${deleteErrors.length} selected file${deleteErrors.length === 1 ? '' : 's'} could not be removed.`
					: `Removed ${deletedPaths.length} sync file${deletedPaths.length === 1 ? '' : 's'}. Backup copies were written to .dbvc_entity_editor_backups.`;
				setEntityIndexNotice(notice);
			}

			if (deleteErrors.length > 0) {
				setEntityIndexError('Some selected files could not be removed.');
				setEntityIndexErrorItems(deleteErrors);
			}
		} catch (error) {
			const errors = Array.isArray(error?.body?.data?.errors) ? error.body.data.errors : [];
			setEntityIndexError(error?.message || 'Failed to remove selected entity files.');
			setEntityIndexErrorItems(errors);
		} finally {
			setEntityDeleteBusy(false);
		}
	}, [selectedEntityPaths, entityDeleteBusy, selectedCanonicalDuplicateCount, selectedEntityFile]);

	const submitBulkDownload = () => {
		if (!selectedEntityPaths.length || !DBVC_ENTITY_EDITOR_APP?.download_url) return;
		const form = document.createElement('form');
		form.method = 'POST';
		form.action = DBVC_ENTITY_EDITOR_APP.download_url;
		form.style.display = 'none';

		const fields = {
			action: 'dbvc_entity_editor_download_bulk',
			_wpnonce: DBVC_ENTITY_EDITOR_APP.download_bulk_nonce || '',
			paths: JSON.stringify(selectedEntityPaths),
		};

		Object.entries(fields).forEach(([name, value]) => {
			const input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			input.value = value;
			form.appendChild(input);
		});

		document.body.appendChild(form);
		form.submit();
		form.remove();
	};

	const submitTransferPacket = () => {
		if (!selectedEntityPaths.length || !DBVC_ENTITY_EDITOR_APP?.download_url) return;
		setTransferPreviewOpen(false);
		const form = document.createElement('form');
		form.method = 'POST';
		form.action = DBVC_ENTITY_EDITOR_APP.download_url;
		form.style.display = 'none';

		const fields = {
			action: 'dbvc_entity_editor_transfer_packet',
			_wpnonce: DBVC_ENTITY_EDITOR_APP.transfer_packet_nonce || '',
			paths: JSON.stringify(selectedEntityPaths),
		};

		Object.entries(fields).forEach(([name, value]) => {
			const input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			input.value = value;
			form.appendChild(input);
		});

		document.body.appendChild(form);
		form.submit();
		form.remove();
	};

	const openTransferPreview = useCallback(async () => {
		if (!selectedEntityPaths.length) return;
		setTransferPreviewOpen(true);
		setTransferPreviewLoading(true);
		setTransferPreviewError('');
		setTransferPreviewData(null);
		try {
			const preview = await apiPost('entity-editor/transfer-preview', {
				paths: selectedEntityPaths,
			});
			setTransferPreviewData(preview || null);
		} catch (error) {
			setTransferPreviewError(error?.message || 'Failed to build transfer preview.');
		} finally {
			setTransferPreviewLoading(false);
		}
	}, [selectedEntityPaths]);

	const runEntityBulkAction = useCallback(async () => {
		if (!entityBulkAction) {
			return;
		}

		if (entityBulkAction === 'select_filtered') {
			setFilteredSelection(true);
			setEntityBulkAction('');
			return;
		}

		if (entityBulkAction === 'deselect_filtered') {
			setFilteredSelection(false);
			setEntityBulkAction('');
			return;
		}

		if (entityBulkAction === 'clear_selection') {
			clearSelection();
			setEntityBulkAction('');
			return;
		}

		if (entityBulkAction === 'download_selected') {
			submitBulkDownload();
			setEntityBulkAction('');
			return;
		}

		if (entityBulkAction === 'preview_import_selected') {
			await openSyncImportPreview(selectedEntityPaths);
			setEntityBulkAction('');
			return;
		}

		if (entityBulkAction === 'remove_selected') {
			await removeSelectedEntityFiles();
			setEntityBulkAction('');
		}
	}, [entityBulkAction, openSyncImportPreview, removeSelectedEntityFiles, selectedEntityPaths, selectedEntityPaths.length, selectedFilteredCount, filteredEntityPaths.length, allFilteredSelected]);

	const canNavigateEntitySearch = !!normalizedEntityEditorSearch && entityEditorSearchMatches.length > 0;
	const transferPreviewSelection = transferPreviewData?.selection?.summary || {};
	const transferPreviewRequirements = transferPreviewData?.requirements || {};
	const transferPreviewNotes = Array.isArray(transferPreviewRequirements?.notes) ? transferPreviewRequirements.notes : [];
	const transferPreviewPostTypes = Array.isArray(transferPreviewRequirements?.post_types) ? transferPreviewRequirements.post_types : [];
	const transferPreviewTaxonomies = Array.isArray(transferPreviewRequirements?.taxonomies) ? transferPreviewRequirements.taxonomies : [];
	const transferPreviewWarnings = Array.isArray(transferPreviewData?.warnings?.unsupported_post_references) ? transferPreviewData.warnings.unsupported_post_references : [];
	const transferPreviewTotals = transferPreviewData?.totals || {};
	const transferPreviewMedia = transferPreviewData?.media || {};
	const rawIntakeWarnings = Array.isArray(rawIntakePreview?.warnings) ? rawIntakePreview.warnings : [];
	const rawIntakeBlocking = Array.isArray(rawIntakePreview?.blocking) ? rawIntakePreview.blocking : [];
	const rawIntakeAvailable = rawIntakePreview?.available_actions || {};
	const rawIntakeMatchedUpdate = isRawIntakeMatchedUpdateEligible(rawIntakePreview, rawIntakeMode);
	const rawIntakeMatchedUpdateEntity = rawIntakePreview?.matched_update?.wp_entity || {};
	const rawIntakeUpdateConfirmed = !!rawIntakeUpdateConfirmation?.confirmed
		&& rawIntakeUpdateConfirmation.preview_hash === rawIntakePreview?.preview_hash
		&& Number(rawIntakeUpdateConfirmation.matched_entity_id || 0) === Number(rawIntakeMatchedUpdateEntity?.id || rawIntakePreview?.match?.id || 0);
	const rawIntakeCanCommitBase = !!rawIntakePreview
		&& !!rawIntakeAvailable?.[rawIntakeMode]
		&& rawIntakeBlocking.length === 0;
	const rawIntakeCanCommit = rawIntakeCanCommitBase && (!rawIntakeMatchedUpdate || rawIntakeUpdateConfirmed);
	const rawIntakeBlockedMessages = collectImportBlockerMessages([{ blocking: rawIntakeBlocking }]);
	const rawIntakeModeBlocked = !!rawIntakePreview && !rawIntakePreviewBusy && !rawIntakeCanCommitBase;
	const syncImportItems = Array.isArray(syncImportPreview?.items) ? syncImportPreview.items : [];
	const syncImportSummary = syncImportPreview?.summary || {};
	const syncImportCreatableCount = syncImportItems.reduce((count, item) => {
		const blocking = Array.isArray(item?.blocking) ? item.blocking : [];
		return count + (!item?.created && !!item?.available_actions?.create_only && blocking.length === 0 ? 1 : 0);
	}, 0);
	const syncImportUpdateableItems = syncImportItems.filter(isSyncImportMatchedUpdateEligible);
	const syncImportUpdateableCount = syncImportUpdateableItems.length;
	const syncImportConfirmedUpdateCount = syncImportUpdateableItems.reduce((count, item) => {
		const path = getSyncImportItemPath(item);
		const confirmation = path ? syncImportUpdateConfirmations[path] : null;
		const matchedId = item?.matched_update?.wp_entity?.id || item?.match?.id || 0;
		const confirmed = !!confirmation?.confirmed
			&& confirmation.preview_hash === item?.preview_hash
			&& Number(confirmation.matched_entity_id || 0) === Number(matchedId || 0);
		return count + (confirmed ? 1 : 0);
	}, 0);
	const syncImportCanCommit = syncImportCreatableCount > 0;
	const syncImportCanUpdateMatched = syncImportUpdateableCount > 0 && syncImportConfirmedUpdateCount === syncImportUpdateableCount;
	const syncImportPreviewEmpty = !!syncImportPreview && !syncImportPreviewBusy && syncImportItems.length === 0;
	const syncImportNoCreatable = !!syncImportPreview && !syncImportPreviewBusy && syncImportItems.length > 0 && syncImportCreatableCount === 0 && syncImportUpdateableCount === 0;
	const syncImportBlockedMessages = collectImportBlockerMessages(syncImportItems);

	const canOfferSyncFileImport = (item) => {
		if (!item?.relative_path || !['post', 'term'].includes(item?.entity_kind)) return false;
		if (item?.matched_wp?.id) return false;
		if (item?.is_duplicate && !item?.is_canonical_duplicate) return false;
		return true;
	};

	const handleEntityEditorSearchChange = (value) => {
		setEntityEditorSearch(value);
		const normalizedQuery = value.trim();
		if (!normalizedQuery) {
			setEntityEditorSearchIndex(-1);
			return;
		}
		const matches = collectSearchMatches(entityEditorDraft, normalizedQuery);
		if (!matches.length) {
			setEntityEditorSearchIndex(-1);
			return;
		}
		const cursor = getEntityEditorSearchCursor();
		const targetIndex = matches.findIndex((position) => position >= cursor);
		jumpToEntityEditorSearchMatch(
			targetIndex >= 0 ? targetIndex : 0,
			matches,
			normalizedQuery
		);
	};

	const jumpToNextEntitySearch = ({ keepInputFocus = true } = {}) => {
		if (!canNavigateEntitySearch) return;
		const cursor = getEntityEditorSearchCursor();
		const nextIndex = entityEditorSearchMatches.findIndex((position) => position > cursor);
		jumpToEntityEditorSearchMatch(
			nextIndex >= 0 ? nextIndex : 0,
			entityEditorSearchMatches,
			normalizedEntityEditorSearch,
			{ keepInputFocus }
		);
	};

	const jumpToPreviousEntitySearch = ({ keepInputFocus = true } = {}) => {
		if (!canNavigateEntitySearch) return;
		const cursor = getEntityEditorSearchCursor();
		let previousIndex = -1;
		for (let index = entityEditorSearchMatches.length - 1; index >= 0; index -= 1) {
			if (entityEditorSearchMatches[index] < cursor) {
				previousIndex = index;
				break;
			}
		}
		jumpToEntityEditorSearchMatch(
			previousIndex >= 0 ? previousIndex : entityEditorSearchMatches.length - 1,
			entityEditorSearchMatches,
			normalizedEntityEditorSearch,
			{ keepInputFocus }
		);
	};

	return (
		<div className="dbvc-admin-app is-route-entity-editor">
			<div className="dbvc-admin-app__header">
				<h1>DBVC Entity Editor</h1>
				<div style={{ display: 'flex', gap: '8px' }}>
					<Button
						variant="secondary"
						onClick={() => loadEntityIndex(false)}
						disabled={entityIndexLoading}
						title="Refresh the entity index from cache"
					>
						{entityIndexLoading ? 'Refreshing…' : 'Refresh index'}
					</Button>
					<Button
						variant="tertiary"
						onClick={() => loadEntityIndex(true)}
						disabled={entityIndexLoading}
						isBusy={entityIndexLoading}
						title="Force a full sync-folder rescan and rebuild the index"
					>
						{entityIndexLoading ? 'Rebuilding…' : 'Rebuild index'}
					</Button>
				</div>
			</div>
			<section className="dbvc-entity-editor-shell">
				<h2>Entity index</h2>
				<p className="description">
					Showing {sortedEntityIndex.length} indexed entities from sync (posts + terms only). Unimported: {unimportedEntityCount}.
				</p>
				{entityIndexError && (
					<div className="notice notice-error">
						<p>{entityIndexError}</p>
						{entityIndexErrorItems.length > 0 && (
							<ul style={{ marginLeft: '18px' }}>
								{entityIndexErrorItems.map((item, index) => (
									<li key={`${item?.path || 'error'}-${item?.code || index}`}>
										<strong>{item?.path || 'Selection'}</strong>: {item?.message || 'Unable to complete this operation.'}
									</li>
								))}
							</ul>
						)}
					</div>
				)}
				{entityIndexNotice && (
					<div className="notice notice-success"><p>{entityIndexNotice}</p></div>
				)}
				<div className="dbvc-entity-editor__toolbar">
					<label className="dbvc-entity-editor__toolbar-field">
						Kind{' '}
						<select value={entityKindFilter} onChange={(e) => { setEntityKindFilter(e.target.value); setEntityPage(1); }}>
							<option value="all">All</option>
							<option value="post">Posts</option>
							<option value="term">Terms</option>
						</select>
					</label>
					<label className="dbvc-entity-editor__toolbar-field">
						Subtype{' '}
						<select value={entitySubtypeFilter} onChange={(e) => { setEntitySubtypeFilter(e.target.value); setEntityPage(1); }}>
							<option value="all">All</option>
							{entitySubtypeOptions.map((option) => <option key={option} value={option}>{option}</option>)}
						</select>
					</label>
					<label className="dbvc-entity-editor__toolbar-field dbvc-entity-editor__toolbar-field--search">
						Search{' '}
						<input
							type="search"
							value={entitySearch}
							onChange={(e) => { setEntitySearch(e.target.value); setEntityPage(1); }}
							placeholder="title, slug, uid, file"
						/>
					</label>
					<div className="dbvc-entity-editor__toolbar-actions">
						<span className="dbvc-entity-editor__toolbar-selection">
							Selected: {selectedEntityPaths.length}
						</span>
						<label className="dbvc-entity-editor__toolbar-field dbvc-entity-editor__toolbar-field--bulk">
							Bulk action{' '}
							<select
								value={entityBulkAction}
								onChange={(e) => setEntityBulkAction(e.target.value)}
							>
								<option value="">Choose action</option>
								<option value="select_filtered">Select filtered ({filteredEntityPaths.length})</option>
								<option value="deselect_filtered">Deselect filtered</option>
								<option value="clear_selection">Clear selection</option>
								<option value="download_selected">Download selected</option>
								<option value="preview_import_selected">Preview import selected</option>
								<option value="remove_selected">Remove selected</option>
							</select>
						</label>
						<Button
							variant="secondary"
							onClick={runEntityBulkAction}
							disabled={entityBulkActionDisabled}
							isBusy={entityDeleteBusy && entityBulkAction === 'remove_selected'}
							title="Apply the selected bulk action"
						>
							Apply
						</Button>
						<Button
							variant="secondary"
							onClick={openRawIntakeModal}
							disabled={entityDeleteBusy}
							title="Paste one raw DBVC entity JSON payload, preview the detected type, and create or stage it"
						>
							New From Raw JSON
						</Button>
						<Button
							variant="primary"
							onClick={openTransferPreview}
							disabled={!selectedEntityPaths.length || entityDeleteBusy}
							title="Preview the packet contents, dependencies, and warnings before download"
						>
							Create transfer packet
						</Button>
						<Button
							variant="tertiary"
							href={DBVC_ENTITY_EDITOR_APP?.proposal_review_url || 'admin.php?page=dbvc-export#proposal-review'}
							title="Open Proposal Review to upload a transfer packet"
						>
							Upload Transfer Packet
						</Button>
					</div>
				</div>

				<div className="dbvc-entity-editor-shell__pane dbvc-entity-editor__table">
					<table className="widefat striped">
						<thead>
							<tr>
								<th>
									<input
										type="checkbox"
										checked={allPagedSelected}
										onChange={(e) => setPagedSelection(e.target.checked)}
										title="Select/deselect all rows on this page"
									/>
								</th>
								<th><button type="button" className="button button-link" onClick={() => toggleEntitySort('entity_kind')}>Kind{entitySort.key === 'entity_kind' ? (entitySort.direction === 'asc' ? ' ↑' : ' ↓') : ''}</button></th>
								<th><button type="button" className="button button-link" onClick={() => toggleEntitySort('import_status')}>Import status{entitySort.key === 'import_status' ? (entitySort.direction === 'asc' ? ' ↑' : ' ↓') : ''}</button></th>
								<th>Actions</th>
								<th><button type="button" className="button button-link" onClick={() => toggleEntitySort('subtype')}>Subtype{entitySort.key === 'subtype' ? (entitySort.direction === 'asc' ? ' ↑' : ' ↓') : ''}</button></th>
								<th><button type="button" className="button button-link" onClick={() => toggleEntitySort('title')}>Title{entitySort.key === 'title' ? (entitySort.direction === 'asc' ? ' ↑' : ' ↓') : ''}</button></th>
								<th><button type="button" className="button button-link" onClick={() => toggleEntitySort('slug')}>Slug{entitySort.key === 'slug' ? (entitySort.direction === 'asc' ? ' ↑' : ' ↓') : ''}</button></th>
								<th><button type="button" className="button button-link" onClick={() => toggleEntitySort('uid')}>UID{entitySort.key === 'uid' ? (entitySort.direction === 'asc' ? ' ↑' : ' ↓') : ''}</button></th>
								<th><button type="button" className="button button-link" onClick={() => toggleEntitySort('mtime')}>Modified{entitySort.key === 'mtime' ? (entitySort.direction === 'asc' ? ' ↑' : ' ↓') : ''}</button></th>
								<th><button type="button" className="button button-link" onClick={() => toggleEntitySort('relative_path')}>File{entitySort.key === 'relative_path' ? (entitySort.direction === 'asc' ? ' ↑' : ' ↓') : ''}</button></th>
							</tr>
						</thead>
						<tbody>
							{pagedEntityIndex.length ? pagedEntityIndex.map((item) => (
								<tr
									key={item.relative_path}
									className={[
										selectedEntityFile === item.relative_path ? 'is-active' : '',
										item.is_canonical_duplicate ? 'dbvc-entity-editor__row--canonical' : '',
										item.is_duplicate && !item.is_canonical_duplicate ? 'dbvc-entity-editor__row--stale' : '',
									].filter(Boolean).join(' ')}
								>
									<td>
										<input
											type="checkbox"
											checked={selectedEntityPathSet.has(item.relative_path)}
											onChange={(e) => toggleEntityRowSelection(item.relative_path, e.target.checked)}
											title="Select this entity row"
										/>
									</td>
									<td>{item.entity_kind || '—'}</td>
									<td>
										{item.matched_wp?.id ? (
											<>
												<span className="dbvc-badge dbvc-badge--accept">Imported</span>
												<div>
													<a href={item.matched_wp?.edit_url || '#'}>{item.matched_wp?.kind || 'wp'} #{item.matched_wp?.id}</a>
												</div>
											</>
										) : (
											<span className="dbvc-badge dbvc-badge--pending">Not imported</span>
										)}
									</td>
									<td>
										<button
											type="button"
											className="button button-small"
											onClick={() => setSelectedEntityFile(item.relative_path)}
											title="Open this JSON file in the editor"
										>
											Edit JSON
										</button>
										{canOfferSyncFileImport(item) && (
											<button
												type="button"
												className="button button-small"
												onClick={() => openSyncImportPreview(item.relative_path)}
												disabled={busyAny}
												title="Preview creating a new live WordPress entity from this sync JSON file"
												style={{ marginLeft: '6px' }}
											>
												Import as New
											</button>
										)}
									</td>
									<td>{item.subtype || '—'}</td>
									<td>
										<div className="dbvc-entity-editor__title-cell">
											<div>{item.title || '—'}</div>
											{item.is_duplicate && item.duplicate_group && (
												<div className="dbvc-entity-editor__title-meta">
													<span className={`dbvc-badge ${item.is_canonical_duplicate ? 'dbvc-badge--accept' : 'dbvc-badge--pending'}`}>
														{item.is_canonical_duplicate ? 'Latest valid row' : 'Older duplicate'}
													</span>
													<span className="dbvc-entity-editor__duplicate-note">
														{getDuplicateMatchBasisLabel(item.duplicate_group.match_basis)}
														{item.duplicate_group?.size ? ` · ${item.duplicate_group.size} file${item.duplicate_group.size === 1 ? '' : 's'}` : ''}
													</span>
												</div>
											)}
										</div>
									</td>
									<td>{item.slug || '—'}</td>
									<td>{item.uid || '—'}</td>
									<td>{item.mtime_gmt ? formatDate(item.mtime_gmt) : '—'}</td>
									<td>
										{item.relative_path ? (
											<a
												href={getEntityDownloadUrl(item.relative_path)}
												download
												title="Download this entity JSON file"
											>
												{item.relative_path}
											</a>
										) : '—'}
									</td>
								</tr>
							)) : (
								<tr><td colSpan={10}>{entityIndexLoading ? 'Loading index…' : 'No matching entities found.'}</td></tr>
							)}
						</tbody>
					</table>
				</div>

				{selectedEntityFile && (
					(Modal ? (
						<Modal
							title={`Editing: ${selectedEntityFile}`}
							onRequestClose={() => {
								if (fullReplaceModalOpen) return;
								clearEntityEditorFile();
							}}
							className="dbvc-entity-editor-modal"
							overlayClassName="dbvc-entity-editor-modal-overlay"
						>
							<div className="dbvc-entity-editor-shell__pane dbvc-entity-editor__editor">
								{entityFileLoading && (
									<p style={{ display: 'flex', alignItems: 'center', gap: '8px' }}><Spinner /> Loading file…</p>
								)}
								{entityFileError && <div className="notice notice-error"><p>{entityFileError}</p></div>}
								{entityLockConflict && (
									<div className="notice notice-warning">
										<p>Current lock owner: {entityLockConflict?.user_display || 'another user'}{entityLockConflict?.expires_at ? ` (expires ${formatDate(entityLockConflict.expires_at)})` : ''}</p>
										<button type="button" className="button" onClick={() => loadEntityEditorFile(selectedEntityFile, true)} title="Take ownership of this file lock so you can edit and save">Take over lock</button>
									</div>
								)}
								{entityFileData && (
									<>
										<p className="description">Kind: {entityFileData.entity_kind || '—'} · Subtype: {entityFileData.subtype || '—'} · UID: {entityFileData.uid || '—'}</p>
										{entityLockInfo && (
											<p className="description">Editor lock: {entityLockInfo?.user_display || 'unknown'}{entityLockInfo?.expires_at ? ` · expires ${formatDate(entityLockInfo.expires_at)}` : ''}</p>
										)}
										<div className="dbvc-entity-editor__actions" style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
											<div className="dbvc-entity-editor__search">
												<input
													type="search"
													value={entityEditorSearch}
													onChange={(e) => handleEntityEditorSearchChange(e.target.value)}
													onKeyDown={(e) => {
														if (e.key === 'Enter') {
															e.preventDefault();
															if (e.shiftKey) {
																jumpToPreviousEntitySearch({ keepInputFocus: true });
																return;
															}
															jumpToNextEntitySearch({ keepInputFocus: true });
														}
													}}
													placeholder="Find in JSON..."
													title="Search within JSON (Enter = next, Shift+Enter = previous)"
												/>
												<button type="button" className="button button-small" onClick={() => jumpToPreviousEntitySearch({ keepInputFocus: false })} disabled={!canNavigateEntitySearch} title="Jump to previous match">
													Prev
												</button>
												<button type="button" className="button button-small" onClick={() => jumpToNextEntitySearch({ keepInputFocus: false })} disabled={!canNavigateEntitySearch} title="Jump to next match">
													Next
												</button>
												<span className="dbvc-entity-editor__search-count">
													{canNavigateEntitySearch
														? `${entityEditorSearchIndex + 1} / ${entityEditorSearchMatches.length}`
														: normalizedEntityEditorSearch ? '0 matches' : 'Find'}
												</span>
											</div>
											<Button variant="primary" onClick={() => saveEntityEditorFile(false)} disabled={busyAny || !entityLockToken} isBusy={entitySaveBusy} title="Save JSON file only (no database changes)">
												{entitySaveBusy ? 'Saving…' : 'Save JSON'}
											</Button>
											<Button variant="secondary" onClick={() => partialImportEntityEditorFile(false)} disabled={busyAny || !entityLockToken} isBusy={entityImportBusy} title="Save JSON and merge only fields/meta present in JSON">
												{entityImportBusy ? 'Importing…' : 'Save + Partial Import'}
											</Button>
											<Button variant="secondary" onClick={() => openFullReplaceModal(false)} disabled={busyAny || !entityLockToken} isBusy={entityReplaceBusy} title="Save JSON and fully replace entity data (destructive)">
												{entityReplaceBusy ? 'Replacing…' : 'Save + Full Replace'}
											</Button>
											<Button variant="tertiary" onClick={clearEntityEditorFile} disabled={busyAny} title="Close editor and return to entity table">
												Close
											</Button>
										</div>
										<textarea
											className="dbvc-entity-editor__textarea"
											ref={entityEditorTextareaRef}
											value={entityEditorDraft}
											onChange={(e) => {
												setEntityEditorDraft(e.target.value);
												setEntitySaveNotice('');
												setEntitySaveError('');
											}}
										/>
										{entitySaveError && (
											<div className="notice notice-error">
												<p>{entitySaveError}</p>
												{entityLockConflict && (
													<>
														<button type="button" className="button" onClick={() => saveEntityEditorFile(true)} disabled={busyAny} title="Take lock ownership and save JSON to sync folder">Take over lock and save</button>
														<button type="button" className="button" onClick={() => openFullReplaceModal(true)} disabled={busyAny} title="Take lock ownership and run destructive full replace">Take over lock and full replace</button>
													</>
												)}
											</div>
										)}
										{entitySaveNotice && <div className="notice notice-success"><p>{entitySaveNotice}</p></div>}
									</>
								)}
							</div>
						</Modal>
					) : (
						<div className="dbvc-entity-editor-shell__pane dbvc-entity-editor__editor" style={{ marginTop: '12px' }}>
							<div className="notice notice-warning"><p>Editor modal is unavailable in this WordPress build.</p></div>
						</div>
					))
				)}

				<div style={{ marginTop: '10px', display: 'flex', gap: '8px', alignItems: 'center' }}>
					<button type="button" className="button" disabled={safeEntityPage <= 1} onClick={() => setEntityPage((current) => Math.max(1, current - 1))} title="Go to previous page of entities">
						Previous
					</button>
					<span>Page {safeEntityPage} of {entityTotalPages}</span>
					<button type="button" className="button" disabled={safeEntityPage >= entityTotalPages} onClick={() => setEntityPage((current) => Math.min(entityTotalPages, current + 1))} title="Go to next page of entities">
						Next
					</button>
					{entityIndexStats && (
						<small>
							{' · '}indexed: {entityIndexStats?.indexed_files ?? 0}, scanned: {entityIndexStats?.scanned_files ?? 0}, excluded: {entityIndexStats?.excluded_files ?? 0}
							{' · '}duplicate groups: {entityIndexStats?.duplicate_groups ?? 0}, duplicate files: {entityIndexStats?.duplicate_files ?? 0}
						</small>
					)}
				</div>

				{fullReplaceModalOpen && (
					(Modal ? (
						<Modal title="Confirm Full Replace" onRequestClose={closeFullReplaceModal}>
							<p>This operation is destructive. Meta keys not present in JSON will be deleted (except protected keys).</p>
							{fullReplaceNeedsTakeover && <p>This action will also take over the editor lock.</p>}
							<p>Type <code>REPLACE</code> to confirm.</p>
							<input
								type="text"
								value={fullReplaceConfirmPhrase}
								onChange={(e) => {
									setFullReplaceConfirmPhrase(e.target.value);
									setFullReplaceModalError('');
								}}
								placeholder="REPLACE"
								style={{ width: '100%' }}
							/>
							{fullReplaceModalError && <p style={{ color: '#b32d2e' }}>{fullReplaceModalError}</p>}
							<div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '12px' }}>
								<Button variant="tertiary" onClick={closeFullReplaceModal} title="Cancel full replace and return to editor">Cancel</Button>
								<Button variant="primary" onClick={confirmFullReplaceModal} disabled={entityReplaceBusy} isBusy={entityReplaceBusy} title="Confirm destructive full replace">
									Confirm Replace
								</Button>
							</div>
						</Modal>
					) : (
						<div className="notice notice-warning">
							<p>Typed confirmation needed: enter "REPLACE" then use Save + Full Replace.</p>
							<button type="button" className="button" onClick={closeFullReplaceModal} title="Close this warning">Close</button>
						</div>
					))
				)}

				{transferPreviewOpen && (
					(Modal ? (
						<Modal title="Transfer Packet Preview" onRequestClose={closeTransferPreview}>
							{transferPreviewLoading && (
								<p style={{ display: 'flex', alignItems: 'center', gap: '8px' }}><Spinner /> Analyzing selection…</p>
							)}
							{transferPreviewError && (
								<div className="notice notice-error">
									<p>{transferPreviewError}</p>
									<div style={{ display: 'flex', gap: '8px', marginTop: '8px' }}>
										<Button variant="secondary" onClick={openTransferPreview}>Retry preview</Button>
										<Button variant="tertiary" onClick={closeTransferPreview}>Close</Button>
									</div>
								</div>
							)}
							{!transferPreviewLoading && !transferPreviewError && transferPreviewData && (
								<>
									<p className="description">
										This preview uses the packet builder’s live dependency analysis. The packet will still be generated fresh when you continue.
									</p>
									<div className="notice notice-info">
										<p>
											Selected: {transferPreviewSelection.requested_paths ?? selectedEntityPaths.length} item(s)
											{' · '}posts: {transferPreviewSelection.selected_posts ?? 0}
											{' · '}terms: {transferPreviewSelection.selected_terms ?? 0}
											{' · '}dependent terms: {transferPreviewSelection.dependency_terms ?? 0}
										</p>
										<p>
											Fallback files: {transferPreviewSelection.fallback_files ?? 0}
											{' · '}duplicates skipped: {transferPreviewSelection.duplicates_skipped ?? 0}
											{' · '}missing dependencies: {transferPreviewSelection.missing_dependencies ?? 0}
										</p>
										<p>
											Packet files: {transferPreviewTotals.files ?? '—'}
											{' · '}media refs: {transferPreviewMedia.items ?? transferPreviewTotals.media_items ?? 0}
											{' · '}bundle: {transferPreviewMedia.will_include_bundle ? 'included' : (transferPreviewMedia.bundle_enabled ? 'enabled but not needed' : 'disabled on source site')}
										</p>
									</div>

									{(transferPreviewPostTypes.length > 0 || transferPreviewTaxonomies.length > 0) && (
										<div className="notice notice-info">
											<p>Destination requirements:</p>
											<ul style={{ marginLeft: '18px' }}>
												{transferPreviewPostTypes.length > 0 && (
													<li>Post types: {transferPreviewPostTypes.join(', ')}</li>
												)}
												{transferPreviewTaxonomies.length > 0 && (
													<li>Taxonomies: {transferPreviewTaxonomies.join(', ')}</li>
												)}
											</ul>
										</div>
									)}

									{transferPreviewWarnings.length > 0 && (
										<div className="notice notice-warning">
											<p>
												Detected likely post-object or relationship references to posts that are not included. These references will not be remapped automatically on import.
											</p>
											<ul style={{ marginLeft: '18px' }}>
												{transferPreviewWarnings.map((warning, index) => (
													<li key={`${warning?.source_path || 'warning'}-${warning?.meta_key || index}-${warning?.referenced_post_id || 0}`}>
														{formatTransferReferenceWarning(warning)}
													</li>
												))}
											</ul>
										</div>
									)}

									{transferPreviewNotes.length > 0 && (
										<div className="notice notice-info">
											<p>Transfer notes:</p>
											<ul style={{ marginLeft: '18px' }}>
												{transferPreviewNotes.map((note, index) => (
													<li key={`${note}-${index}`}>{note}</li>
												))}
											</ul>
										</div>
									)}

									<div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '12px' }}>
										<Button variant="tertiary" onClick={closeTransferPreview}>Cancel</Button>
										<Button variant="primary" onClick={submitTransferPacket}>Download Packet ZIP</Button>
									</div>
								</>
							)}
						</Modal>
					) : (
						<div className="notice notice-warning">
							<p>Transfer preview modal is unavailable in this WordPress build.</p>
							<button type="button" className="button" onClick={closeTransferPreview}>Close</button>
						</div>
					))
				)}

				{syncImportOpen && (
					(Modal ? (
						<Modal title="Import Sync JSON" onRequestClose={closeSyncImportModal} className="dbvc-entity-editor-modal" overlayClassName="dbvc-entity-editor-modal-overlay">
							<div className="dbvc-entity-editor-shell__pane dbvc-entity-editor__editor">
								<p className="description">
									Preview creates unmatched post/CPT or term entities from JSON files that already exist in the DBVC sync folder.
								</p>
								{syncImportPreviewBusy && (
									<p style={{ display: 'flex', alignItems: 'center', gap: '8px' }}><Spinner /> Checking sync file…</p>
								)}
								{syncImportError && (
									<div className="notice notice-error" style={{ marginTop: '12px' }}>
										<p>{syncImportError}</p>
									</div>
								)}
								{syncImportNotice && (
									<div className="notice notice-success" style={{ marginTop: '12px' }}>
										<p>{syncImportNotice}</p>
									</div>
								)}
								{syncImportItems.length > 0 && (
									<div style={{ marginTop: '12px' }}>
										<div className="notice notice-info">
											<p>
												Selected: {syncImportSummary?.requested ?? syncImportItems.length}
												{' · '}Creatable: {syncImportSummary?.creatable ?? syncImportCreatableCount}
												{' · '}Updatable: {syncImportSummary?.updatable ?? syncImportUpdateableCount}
												{' · '}Created: {syncImportSummary?.created ?? 0}
												{' · '}Updated: {syncImportSummary?.updated ?? 0}
												{' · '}Blocked: {syncImportSummary?.blocked ?? 0}
												{' · '}Skipped: {syncImportSummary?.skipped ?? 0}
												{' · '}Errors: {syncImportSummary?.errors ?? 0}
											</p>
										</div>
										{syncImportNoCreatable && (
											<div className="notice notice-warning">
												<p>
													<strong>No entities can be created from this preview.</strong> Create stays disabled until at least one selected JSON is unmatched and passes import preflight.
												</p>
												{syncImportBlockedMessages.length > 0 && (
													<ul style={{ marginLeft: '18px' }}>
														{syncImportBlockedMessages.map((message, index) => (
															<li key={`sync-import-blocked-summary-${index}`}>{message}</li>
														))}
													</ul>
												)}
											</div>
										)}
										<div style={{ display: 'grid', gap: '10px' }}>
											{syncImportItems.map((item, itemIndex) => {
												const itemWarnings = Array.isArray(item?.warnings) ? item.warnings : [];
												const itemBlocking = Array.isArray(item?.blocking) ? item.blocking : [];
												const blockerDetails = Array.isArray(item?.blocker_details) ? item.blocker_details : [];
												const settingsLinks = Array.isArray(item?.settings_links) ? item.settings_links : [];
												const settingRemediations = Array.isArray(item?.setting_remediations) ? item.setting_remediations : [];
												const advancedOverrides = Array.isArray(item?.advanced_overrides) ? item.advanced_overrides : [];
												const bricksAdvisory = item?.bricks_template_advisory?.enabled ? item.bricks_template_advisory : null;
												const bricksAdvisoryMessages = Array.isArray(bricksAdvisory?.messages) ? bricksAdvisory.messages : [];
												const bricksAdvisoryConflicts = Array.isArray(bricksAdvisory?.condition_conflicts) ? bricksAdvisory.condition_conflicts : [];
												const bricksAdvisoryConditions = Array.isArray(bricksAdvisory?.conditions) ? bricksAdvisory.conditions : [];
												const hasBricksAdvisoryWarning = bricksAdvisory?.severity === 'warning';
												const action = item?.created ? 'created' : (item?.action || item?.detected_action);
												const matchedUpdate = isSyncImportMatchedUpdateEligible(item) ? item.matched_update : null;
												const matchedUpdateEntity = matchedUpdate?.wp_entity || {};
												const itemPath = getSyncImportItemPath(item);
												const itemUpdateConfirmation = itemPath ? syncImportUpdateConfirmations[itemPath] : null;
												const itemUpdateConfirmed = !!itemUpdateConfirmation?.confirmed
													&& itemUpdateConfirmation.preview_hash === item?.preview_hash
													&& Number(itemUpdateConfirmation.matched_entity_id || 0) === Number(matchedUpdateEntity?.id || item?.match?.id || 0);
												return (
													<div key={`${item?.relative_path || item?.source_relative_path || 'sync-import'}-${itemIndex}`} className={`notice ${itemBlocking.length ? 'notice-error' : ((itemWarnings.length || hasBricksAdvisoryWarning) ? 'notice-warning' : 'notice-info')}`} style={{ margin: 0 }}>
														<p>
															<strong>{item?.title || item?.relative_path || 'Sync JSON'}</strong>
															{' · '}Subtype: {item?.subtype || '—'}
															{' · '}Action: {getSyncImportActionLabel(action)}
														</p>
														<p>
															Slug: {item?.slug || '—'}
															{' · '}UID: {item?.uid || '—'}
														</p>
														<p>Source sync file: {item?.source_relative_path || item?.relative_path || '—'}</p>
														{item?.source_relative_path && item?.relative_path && item.source_relative_path !== item.relative_path && (
															<p>Canonical sync file: {item.relative_path}</p>
														)}
														{bricksAdvisory && (
															<div className={`notice ${getBricksAdvisoryNoticeClass(bricksAdvisory)}`} style={{ margin: '8px 0 0' }}>
																<p>
																	<strong>Bricks template advisory</strong>
																	{bricksAdvisory?.template_type ? ` · Type: ${bricksAdvisory.template_type}` : ''}
																	{bricksAdvisoryConditions.length ? ` · Conditions: ${bricksAdvisoryConditions.join('; ')}` : ''}
																</p>
																{bricksAdvisoryMessages.length > 0 && (
																	<ul style={{ marginLeft: '18px' }}>
																		{bricksAdvisoryMessages.map((message, index) => (
																			<li key={`bricks-advisory-message-${index}`}>{message}</li>
																		))}
																	</ul>
																)}
																{bricksAdvisoryConflicts.length > 0 && (
																	<ul style={{ marginLeft: '18px' }}>
																		{bricksAdvisoryConflicts.map((conflict, index) => (
																			<li key={`bricks-advisory-conflict-${conflict?.id || index}`}>
																				{conflict?.edit_url ? (
																					<a href={conflict.edit_url}>{conflict?.title || 'Bricks template'} #{conflict?.id || 0}</a>
																				) : (
																					<span>{conflict?.title || 'Bricks template'} #{conflict?.id || 0}</span>
																				)}
																				{conflict?.reason ? ` · ${conflict.reason}` : ''}
																				{conflict?.condition ? ` · ${conflict.condition}` : ''}
																			</li>
																		))}
																	</ul>
																)}
																<p className="description">
																	DBVC will still only create the entity. Review Bricks conditions after import if this template should replace an existing frontend template.
																</p>
															</div>
														)}
														{item?.match?.status === 'matched' && (
															<p>
																Matched live entity:{' '}
																{item?.match?.edit_url ? (
																	<a href={item.match.edit_url}>
																		{item?.match?.kind || 'entity'} #{item?.match?.id || 0}
																	</a>
																) : (
																	<span>{item?.match?.kind || 'entity'} #{item?.match?.id || 0}</span>
																)}
																{' · '}match source: {item?.match?.match_source || 'unknown'}
															</p>
														)}
														{matchedUpdate && (
															<div className="notice notice-warning" style={{ margin: '8px 0 0' }}>
																<p>
																	<strong>Update matched entity</strong>
																	{matchedUpdate?.match_source ? ` · Match source: ${matchedUpdate.match_source}` : ''}
																</p>
																<p>
																	DBVC will apply JSON-present core fields, meta, and taxonomies from this sync file to{' '}
																	{matchedUpdateEntity?.edit_url ? (
																		<a href={matchedUpdateEntity.edit_url}>
																			{matchedUpdateEntity?.label || matchedUpdateEntity?.subtype || 'entity'} #{matchedUpdateEntity?.id || 0}
																		</a>
																	) : (
																		<span>{matchedUpdateEntity?.label || matchedUpdateEntity?.subtype || 'entity'} #{matchedUpdateEntity?.id || 0}</span>
																	)}
																	.
																</p>
																<label style={{ display: 'flex', gap: '8px', alignItems: 'flex-start', marginTop: '8px' }}>
																	<input
																		type="checkbox"
																		checked={itemUpdateConfirmed}
																		onChange={(event) => setSyncImportMatchedUpdateConfirmed(item, event.target.checked)}
																		disabled={syncImportPreviewBusy || syncImportCommitBusy || !!syncImportRemediationBusy}
																	/>
																	<span>{matchedUpdate?.confirmation_label || 'I confirm updating this matched WordPress entity from the selected JSON.'}</span>
																</label>
															</div>
														)}
														{item?.wp_entity?.status === 'matched' && (
															<p>
																{item?.updated ? 'Updated live entity:' : (item?.created ? 'Created live entity:' : 'Live entity:')}{' '}
																{item?.wp_entity?.edit_url ? (
																	<a href={item.wp_entity.edit_url}>
																		{item?.wp_entity?.kind || 'entity'} #{item?.wp_entity?.id || 0}
																	</a>
																) : (
																	<span>{item?.wp_entity?.kind || 'entity'} #{item?.wp_entity?.id || 0}</span>
																)}
															</p>
														)}
														<ImportBlockerPanel
															blocking={itemBlocking}
															blockerDetails={blockerDetails}
															settingsLinks={settingsLinks}
															settingRemediations={settingRemediations}
															advancedOverrides={advancedOverrides}
															canonicalRelativePath={item?.canonical_relative_path || ''}
															onOpenCanonical={(path) => {
																setSelectedEntityFile(path);
																closeSyncImportModal();
															}}
															onApplyAction={(action) => remediateSyncImport(item, action)}
															buildBusyKey={(action) => `${item?.relative_path || item?.source_relative_path || ''}:${action?.id || ''}`}
															busyKey={syncImportRemediationBusy}
															disabled={syncImportPreviewBusy || syncImportCommitBusy || !!syncImportRemediationBusy}
														/>
														<ImportWarningNotes warnings={itemWarnings} />
													</div>
												);
											})}
										</div>
										{syncImportItems.length > 25 && (
											<div className="notice notice-warning">
												<p>Only 25 files can be imported in one request.</p>
											</div>
										)}
									</div>
								)}
								{syncImportPreviewEmpty && (
									<div className="notice notice-warning" style={{ marginTop: '12px' }}>
										<p>
											<strong>No preview items were returned.</strong> The selected path may no longer exist in the sync folder, may not be a supported post/term JSON file, or the server may have returned an incomplete preview.
										</p>
									</div>
								)}
								<div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '12px' }}>
									<Button variant="tertiary" onClick={closeSyncImportModal} disabled={syncImportPreviewBusy || syncImportCommitBusy || !!syncImportRemediationBusy}>
										Close
									</Button>
									<Button variant="secondary" onClick={() => openSyncImportPreview(syncImportPaths.length ? syncImportPaths : syncImportPath)} disabled={(!syncImportPath && !syncImportPaths.length) || syncImportPreviewBusy || syncImportCommitBusy || !!syncImportRemediationBusy} isBusy={syncImportPreviewBusy}>
										Refresh Preview
									</Button>
									<Button variant="primary" onClick={commitSyncImport} disabled={!syncImportCanCommit || syncImportPreviewBusy || syncImportCommitBusy || !!syncImportRemediationBusy} isBusy={syncImportCommitBusy}>
										{syncImportCreatableCount > 0
											? `Create ${syncImportCreatableCount} ${syncImportCreatableCount === 1 ? 'Entity' : 'Entities'}`
											: 'Create Entity'}
									</Button>
									{syncImportUpdateableCount > 0 && (
										<Button variant="primary" onClick={commitSyncImportMatchedUpdates} disabled={!syncImportCanUpdateMatched || syncImportPreviewBusy || syncImportCommitBusy || !!syncImportRemediationBusy} isBusy={syncImportCommitBusy}>
											Update {syncImportUpdateableCount} Matched {syncImportUpdateableCount === 1 ? 'Entity' : 'Entities'}
										</Button>
									)}
								</div>
							</div>
						</Modal>
					) : (
						<div className="notice notice-warning">
							<p>Sync-file import modal is unavailable in this WordPress build.</p>
							<button type="button" className="button" onClick={closeSyncImportModal}>Close</button>
						</div>
					))
				)}

				{rawIntakeOpen && (
					(Modal ? (
						<Modal title="New From Raw JSON" onRequestClose={closeRawIntakeModal} className="dbvc-entity-editor-modal" overlayClassName="dbvc-entity-editor-modal-overlay">
							<div className="dbvc-entity-editor-shell__pane dbvc-entity-editor__editor">
								<p className="description">
									Paste one DBVC post/CPT or term JSON payload. Preview will detect the entity kind, target sync path, and whether DBVC will create, update, stage, or block the action.
								</p>
								<label style={{ display: 'block', marginBottom: '12px' }}>
									Mode
									<select
										value={rawIntakeMode}
										onChange={(e) => {
											setRawIntakeMode(e.target.value);
											setRawIntakeUpdateConfirmation(null);
										}}
										style={{ display: 'block', width: '100%', marginTop: '6px' }}
										disabled={rawIntakePreviewBusy || rawIntakeCommitBusy}
									>
										<option value="create_only">Create only (recommended)</option>
										<option value="create_or_update_matched">Create or Update Matched</option>
										<option value="stage_only">Stage JSON Only</option>
									</select>
								</label>
								<label style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '12px' }}>
									<input
										type="checkbox"
										checked={rawIntakeOpenAfterSuccess}
										onChange={(e) => setRawIntakeOpenAfterSuccess(e.target.checked)}
										disabled={rawIntakePreviewBusy || rawIntakeCommitBusy}
									/>
									<span>Open the resulting sync file in Entity Editor after success</span>
								</label>
								<textarea
									className="dbvc-entity-editor__textarea"
									value={rawIntakeDraft}
									onChange={(e) => {
										setRawIntakeDraft(e.target.value);
										setRawIntakeUpdateConfirmation(null);
									}}
									placeholder='Paste one DBVC entity JSON payload here...'
									style={{ minHeight: '260px' }}
								/>
								{rawIntakeError && (
									<div className="notice notice-error" style={{ marginTop: '12px' }}>
										<p>{rawIntakeError}</p>
									</div>
								)}
								{rawIntakePreview && (
									<div style={{ marginTop: '12px' }}>
										<div className="notice notice-info">
											<p>
												Detected: {rawIntakePreview?.entity_kind || '—'}
												{' · '}Subtype: {rawIntakePreview?.subtype || '—'}
												{' · '}Action: {getRawIntakeActionLabel(rawIntakePreview?.detected_action)}
											</p>
											<p>
												Title/Name: {rawIntakePreview?.title || '—'}
												{' · '}Slug: {rawIntakePreview?.slug || '—'}
												{' · '}UID: {rawIntakePreview?.uid || '—'}
											</p>
											<p>Target sync file: {rawIntakePreview?.target_relative_path || '—'}</p>
											{rawIntakePreview?.match?.status === 'matched' && (
												<p>
													Matched live entity:{' '}
													{rawIntakePreview?.match?.edit_url ? (
														<a href={rawIntakePreview.match.edit_url}>
															{rawIntakePreview?.match?.kind || 'entity'} #{rawIntakePreview?.match?.id || 0}
														</a>
													) : (
														<span>{rawIntakePreview?.match?.kind || 'entity'} #{rawIntakePreview?.match?.id || 0}</span>
													)}
													{' · '}match source: {rawIntakePreview?.match?.match_source || 'unknown'}
												</p>
											)}
											{rawIntakeMatchedUpdate && (
												<div className="notice notice-warning" style={{ margin: '8px 0 0' }}>
													<p>
														<strong>Update matched entity</strong>
														{rawIntakePreview?.matched_update?.match_source ? ` · Match source: ${rawIntakePreview.matched_update.match_source}` : ''}
													</p>
													<p>
														DBVC will apply JSON-present core fields, meta, and taxonomies from this raw JSON payload to{' '}
														{rawIntakeMatchedUpdateEntity?.edit_url ? (
															<a href={rawIntakeMatchedUpdateEntity.edit_url}>
																{rawIntakeMatchedUpdateEntity?.label || rawIntakeMatchedUpdateEntity?.subtype || 'entity'} #{rawIntakeMatchedUpdateEntity?.id || 0}
															</a>
														) : (
															<span>{rawIntakeMatchedUpdateEntity?.label || rawIntakeMatchedUpdateEntity?.subtype || 'entity'} #{rawIntakeMatchedUpdateEntity?.id || 0}</span>
														)}
														.
													</p>
													<label style={{ display: 'flex', gap: '8px', alignItems: 'flex-start', marginTop: '8px' }}>
														<input
															type="checkbox"
															checked={rawIntakeUpdateConfirmed}
															onChange={(event) => setRawIntakeMatchedUpdateConfirmed(event.target.checked)}
															disabled={rawIntakePreviewBusy || rawIntakeCommitBusy}
														/>
														<span>{rawIntakePreview?.matched_update?.confirmation_label || 'I confirm updating this matched WordPress entity from the selected JSON.'}</span>
													</label>
												</div>
											)}
											{rawIntakePreview?.file_collision?.exists && (
												<p>
													File collision: {rawIntakePreview?.file_collision?.relative_path || 'existing sync file'}
													{rawIntakePreview?.file_collision?.compatible_with_match ? ' (compatible with matched entity)' : ''}
												</p>
											)}
										</div>
										{rawIntakeModeBlocked && (
											<div className="notice notice-warning">
												<p>
													<strong>The selected mode cannot be committed for this payload.</strong>
													{rawIntakeMode === 'create_only' && rawIntakeAvailable?.create_or_update_matched
														? ' Switch to Create or Update Matched if you intend to update the matched local entity.'
														: ' Review the blocker guidance below before trying again.'}
												</p>
												{rawIntakeBlockedMessages.length > 0 && (
													<ul style={{ marginLeft: '18px' }}>
														{rawIntakeBlockedMessages.map((message, index) => (
															<li key={`raw-intake-blocked-summary-${index}`}>{message}</li>
														))}
													</ul>
												)}
											</div>
										)}
										<ImportWarningNotes warnings={rawIntakeWarnings} />
										<ImportBlockerPanel
											blocking={rawIntakeBlocking}
											blockerDetails={rawIntakePreview?.blocker_details || []}
											settingsLinks={rawIntakePreview?.settings_links || []}
											settingRemediations={rawIntakePreview?.setting_remediations || []}
											advancedOverrides={rawIntakePreview?.advanced_overrides || []}
											disabled={rawIntakePreviewBusy || rawIntakeCommitBusy}
										/>
									</div>
								)}
								<div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '12px' }}>
									<Button variant="tertiary" onClick={closeRawIntakeModal} disabled={rawIntakePreviewBusy || rawIntakeCommitBusy}>
										Cancel
									</Button>
									<Button variant="secondary" onClick={previewRawIntake} disabled={rawIntakePreviewBusy || rawIntakeCommitBusy} isBusy={rawIntakePreviewBusy}>
										Preview
									</Button>
									<Button variant="primary" onClick={commitRawIntake} disabled={!rawIntakeCanCommit || rawIntakePreviewBusy || rawIntakeCommitBusy} isBusy={rawIntakeCommitBusy}>
										Commit
									</Button>
								</div>
							</div>
						</Modal>
					) : (
						<div className="notice notice-warning">
							<p>Raw JSON intake modal is unavailable in this WordPress build.</p>
							<button type="button" className="button" onClick={closeRawIntakeModal}>Close</button>
						</div>
					))
				)}
			</section>
		</div>
	);
};

const rootNode = document.getElementById('dbvc-entity-editor-root');
if (rootNode) {
	createRoot(rootNode).render(<EntityEditorApp />);
}
