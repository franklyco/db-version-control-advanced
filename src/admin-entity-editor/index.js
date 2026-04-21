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
	if (!response.ok) {
		const text = await response.text();
		let parsed = text;
		try {
			parsed = JSON.parse(text);
		} catch (e) {}
		const error = new Error(parsed?.message || text || `Request failed (${response.status})`);
		error.body = parsed;
		error.status = response.status;
		throw error;
	}
	return response.json();
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
	}, []);

	const closeRawIntakeModal = useCallback(() => {
		setRawIntakeOpen(false);
		setRawIntakePreviewBusy(false);
		setRawIntakeCommitBusy(false);
		setRawIntakeError('');
		setRawIntakePreview(null);
	}, []);

	const previewRawIntake = useCallback(async () => {
		setRawIntakePreviewBusy(true);
		setRawIntakeError('');
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

	const commitRawIntake = useCallback(async () => {
		setRawIntakeCommitBusy(true);
		setRawIntakeError('');
		try {
			const data = await apiPost('entity-editor/raw-intake/commit', {
				content: rawIntakeDraft,
				mode: rawIntakeMode,
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
		} finally {
			setRawIntakeCommitBusy(false);
		}
	}, [closeRawIntakeModal, loadEntityIndex, rawIntakeDraft, rawIntakeMode, rawIntakeOpenAfterSuccess, rawIntakePreview]);

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
			if (key === 'mtime') return Number(item?.mtime || 0);
			return (item?.[key] || '').toString().toLowerCase();
		};
		list.sort((a, b) => {
			const left = getValue(a, entitySort.key);
			const right = getValue(b, entitySort.key);
			if (left === right) return 0;
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
	const busyAny = entitySaveBusy || entityImportBusy || entityReplaceBusy || entityDeleteBusy;
	const entityBulkActionNeedsSelection = entityBulkAction === 'download_selected'
		|| entityBulkAction === 'remove_selected'
		|| entityBulkAction === 'clear_selection';
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

		if (entityBulkAction === 'remove_selected') {
			await removeSelectedEntityFiles();
			setEntityBulkAction('');
		}
	}, [entityBulkAction, removeSelectedEntityFiles, selectedEntityPaths.length, selectedFilteredCount, filteredEntityPaths.length, allFilteredSelected]);

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
	const rawIntakeCanCommit = !!rawIntakePreview && !!rawIntakeAvailable?.[rawIntakeMode] && rawIntakeBlocking.length === 0;

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
				<p className="description">Showing {sortedEntityIndex.length} indexed entities from sync (posts + terms only).</p>
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
								<th>Matched WP</th>
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
											<a href={item.matched_wp?.edit_url || '#'}>{item.matched_wp?.kind || 'wp'} #{item.matched_wp?.id}</a>
										) : '—'}
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
										onChange={(e) => setRawIntakeMode(e.target.value)}
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
									onChange={(e) => setRawIntakeDraft(e.target.value)}
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
											{rawIntakePreview?.file_collision?.exists && (
												<p>
													File collision: {rawIntakePreview?.file_collision?.relative_path || 'existing sync file'}
													{rawIntakePreview?.file_collision?.compatible_with_match ? ' (compatible with matched entity)' : ''}
												</p>
											)}
										</div>
										{rawIntakeWarnings.length > 0 && (
											<div className="notice notice-warning">
												<p>Warnings:</p>
												<ul style={{ marginLeft: '18px' }}>
													{rawIntakeWarnings.map((warning, index) => (
														<li key={`${warning?.code || 'warning'}-${index}`}>{warning?.message || 'Warning'}</li>
													))}
												</ul>
											</div>
										)}
										{rawIntakeBlocking.length > 0 && (
											<div className="notice notice-error">
												<p>This payload is blocked for the selected mode:</p>
												<ul style={{ marginLeft: '18px' }}>
													{rawIntakeBlocking.map((item, index) => (
														<li key={`${item?.code || 'blocked'}-${index}`}>{item?.message || 'Blocked'}</li>
													))}
												</ul>
											</div>
										)}
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
