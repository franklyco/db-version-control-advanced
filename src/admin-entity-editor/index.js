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

const EntityEditorApp = () => {
	const [entityIndex, setEntityIndex] = useState([]);
	const [entityIndexStats, setEntityIndexStats] = useState(null);
	const [entityIndexLoading, setEntityIndexLoading] = useState(false);
	const [entityIndexError, setEntityIndexError] = useState('');

	const [entityKindFilter, setEntityKindFilter] = useState('all');
	const [entitySubtypeFilter, setEntitySubtypeFilter] = useState('all');
	const [entitySearch, setEntitySearch] = useState('');
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
	const [entitySaveNotice, setEntitySaveNotice] = useState('');
	const [entitySaveError, setEntitySaveError] = useState('');
	const [entityEditorSearch, setEntityEditorSearch] = useState('');
	const [entityEditorSearchIndex, setEntityEditorSearchIndex] = useState(-1);

	const [fullReplaceModalOpen, setFullReplaceModalOpen] = useState(false);
	const [fullReplaceConfirmPhrase, setFullReplaceConfirmPhrase] = useState('');
	const [fullReplaceModalError, setFullReplaceModalError] = useState('');
	const [fullReplaceNeedsTakeover, setFullReplaceNeedsTakeover] = useState(false);
	const entityEditorTextareaRef = useRef(null);

	const entityPerPage = 20;

	const loadEntityIndex = useCallback(async (force = false) => {
		setEntityIndexLoading(true);
		setEntityIndexError('');
		try {
			const data = force
				? await apiPost('entity-editor/index/rebuild', {})
				: await apiGet('entity-editor/index');
			setEntityIndex(Array.isArray(data?.items) ? data.items : []);
			setEntityIndexStats(data?.stats || null);
		} catch (error) {
			setEntityIndexError(error?.message || 'Failed to load entity index');
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

	const selectedFilteredCount = useMemo(
		() => filteredEntityPaths.reduce((count, path) => (selectedEntityPathSet.has(path) ? count + 1 : count), 0),
		[filteredEntityPaths, selectedEntityPathSet]
	);
	const allFilteredSelected = filteredEntityPaths.length > 0 && selectedFilteredCount === filteredEntityPaths.length;
	const allPagedSelected = pagedEntityPaths.length > 0 && pagedEntityPaths.every((path) => selectedEntityPathSet.has(path));

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

	const busyAny = entitySaveBusy || entityImportBusy || entityReplaceBusy;
	const canNavigateEntitySearch = !!normalizedEntityEditorSearch && entityEditorSearchMatches.length > 0;

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
					<div className="notice notice-error"><p>{entityIndexError}</p></div>
				)}
				<div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' }}>
					<label>
						Kind{' '}
						<select value={entityKindFilter} onChange={(e) => { setEntityKindFilter(e.target.value); setEntityPage(1); }}>
							<option value="all">All</option>
							<option value="post">Posts</option>
							<option value="term">Terms</option>
						</select>
					</label>
					<label>
						Subtype{' '}
						<select value={entitySubtypeFilter} onChange={(e) => { setEntitySubtypeFilter(e.target.value); setEntityPage(1); }}>
							<option value="all">All</option>
							{entitySubtypeOptions.map((option) => <option key={option} value={option}>{option}</option>)}
						</select>
					</label>
					<label>
						Search{' '}
						<input
							type="search"
							value={entitySearch}
							onChange={(e) => { setEntitySearch(e.target.value); setEntityPage(1); }}
							placeholder="title, slug, uid, file"
						/>
					</label>
					<button
						type="button"
						className="button"
						onClick={() => setFilteredSelection(true)}
						disabled={!filteredEntityPaths.length || allFilteredSelected}
						title="Select all rows that match current filters (all pages)"
					>
						Select filtered ({filteredEntityPaths.length})
					</button>
					<button
						type="button"
						className="button"
						onClick={() => setFilteredSelection(false)}
						disabled={!selectedFilteredCount}
						title="Deselect selected rows that match current filters"
					>
						Deselect filtered
					</button>
					<button
						type="button"
						className="button"
						onClick={clearSelection}
						disabled={!selectedEntityPaths.length}
						title="Clear all selected rows"
					>
						Clear selection
					</button>
					<button
						type="button"
						className="button button-primary"
						onClick={submitBulkDownload}
						disabled={!selectedEntityPaths.length}
						title="Download selected entity JSON files as a ZIP archive"
					>
						Download selected ({selectedEntityPaths.length})
					</button>
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
								<tr key={item.relative_path}>
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
									<td>{item.title || '—'}</td>
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
						<small> · indexed: {entityIndexStats?.indexed_files ?? 0}, scanned: {entityIndexStats?.scanned_files ?? 0}, excluded: {entityIndexStats?.excluded_files ?? 0}</small>
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
			</section>
		</div>
	);
};

const rootNode = document.getElementById('dbvc-entity-editor-root');
if (rootNode) {
	createRoot(rootNode).render(<EntityEditorApp />);
}
