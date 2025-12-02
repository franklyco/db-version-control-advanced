import { render, useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, Modal, RadioControl, CheckboxControl } from '@wordpress/components';
import './style.css';

const fetchJSON = async (path, { signal } = {}) => {
	const response = await window.fetch(`${DBVC_ADMIN_APP.root}${path}`, {
		headers: {
			'X-WP-Nonce': DBVC_ADMIN_APP.nonce,
		},
		signal,
	});

	if (!response.ok) {
		throw new Error(`Request failed (${response.status})`);
	}

	return response.json();
};

const postJSON = async (path, body) => {
	const response = await window.fetch(`${DBVC_ADMIN_APP.root}${path}`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': DBVC_ADMIN_APP.nonce,
		},
		body: JSON.stringify(body),
	});

	if (!response.ok) {
		const message = await response.text();
		let errorInfo = message;
		try {
			errorInfo = JSON.parse(message);
		} catch (err) {
			// leave as string
		}
		const error = new Error((errorInfo && errorInfo.message) || message || `Request failed (${response.status})`);
		error.status = response.status;
		error.body = errorInfo;
		throw error;
	}

	return response.json();
};

const postFormData = async (path, formData) => {
	const response = await window.fetch(`${DBVC_ADMIN_APP.root}${path}`, {
		method: 'POST',
		headers: {
			'X-WP-Nonce': DBVC_ADMIN_APP.nonce,
		},
		body: formData,
	});

	if (!response.ok) {
		const message = await response.text();
		throw new Error(message || `Request failed (${response.status})`);
	}

	return response.json();
};

const deleteJSON = async (path) => {
	const response = await window.fetch(`${DBVC_ADMIN_APP.root}${path}`, {
		method: 'DELETE',
		headers: {
			'X-WP-Nonce': DBVC_ADMIN_APP.nonce,
		},
	});

	if (!response.ok) {
		const message = await response.text();
		throw new Error(message || `Request failed (${response.status})`);
	}

	return response.json();
};

const formatDate = (value) => {
	if (!value) {
		return '—';
	}
	const date = new Date(value);
	if (Number.isNaN(date.getTime())) {
		return value;
	}
	return date.toLocaleString();
};

const formatDiffValue = (value) => {
	if (value === null || typeof value === 'undefined') {
		return '—';
	}
	if (typeof value === 'boolean') {
		return value ? 'true' : 'false';
	}
	if (typeof value === 'number') {
		return value.toString();
	}
	if (value === '') {
		return '(empty)';
	}
	return value;
};

const toComparableString = (value) => {
	if (value === null || typeof value === 'undefined') {
		return '';
	}
	if (typeof value === 'string') {
		return value;
	}
	if (typeof value === 'number' || typeof value === 'boolean') {
		return String(value);
	}
	try {
		return JSON.stringify(value, null, 2);
	} catch (err) {
		return String(value);
	}
};

const computeHighlightSegments = (oldValue, newValue) => {
	const oldStr = toComparableString(oldValue);
	const newStr = toComparableString(newValue);

	if (oldStr === newStr) {
		return null;
	}

	if (oldStr.length > 5000 || newStr.length > 5000) {
		return null;
	}

	const maxPrefix = Math.min(oldStr.length, newStr.length);
	let start = 0;
	while (start < maxPrefix && oldStr[start] === newStr[start]) {
		start++;
	}

	let endOld = oldStr.length - 1;
	let endNew = newStr.length - 1;
	while (endOld >= start && endNew >= start && oldStr[endOld] === newStr[endNew]) {
		endOld--;
		endNew--;
	}

	return {
		old: {
			before: oldStr.slice(0, start),
			diff: oldStr.slice(start, endOld + 1),
			after: oldStr.slice(endOld + 1),
		},
		new: {
			before: newStr.slice(0, start),
			diff: newStr.slice(start, endNew + 1),
			after: newStr.slice(endNew + 1),
		},
	};
};

const renderHighlightedSegments = (segments) => {
	if (!segments || (!segments.diff && !segments.before && !segments.after)) {
		return null;
	}

	return (
		<>
			{segments.before && <span>{segments.before}</span>}
			{segments.diff && <mark>{segments.diff || '(empty)'}</mark>}
			{segments.after && <span>{segments.after}</span>}
		</>
	);
};

const SECTION_LABELS = {
	meta: 'Meta',
	tax: 'Taxonomies',
	media: 'Media References',
	content: 'Content',
	title: 'Title',
	status: 'Status',
	post_type: 'Post Type',
	post_excerpt: 'Excerpt',
	other: 'Other',
};

const groupDiffChanges = (changes) => {
	const groups = {};

	changes.forEach((change) => {
		const [root] = change.path.split('.');
		const key = root || 'other';
		if (!groups[key]) {
			groups[key] = [];
		}
		groups[key].push(change);
	});

	return Object.entries(groups).map(([key, items]) => {
		const label = SECTION_LABELS[key] || key;
		return { key, label, items };
	});
};

const STATUS_LABELS = {
	all: 'All entities',
	needs_review: 'Needs Review',
	resolved: 'Resolved',
	reused: 'Resolved',
	conflict: 'Conflict',
	needs_download: 'Needs Download',
	missing: 'Missing',
	unknown: 'Unknown',
};

const renderStatusBadge = (status) => {
	const label = STATUS_LABELS[status] || status;
	return <span className={`dbvc-badge dbvc-badge--${status}`}>{label}</span>;
};

const ProposalList = ({ proposals, selectedId, onSelect }) => {
	if (!proposals.length) {
		return <p>No proposals found. Generate an export to get started.</p>;
	}

	return (
		<table className="widefat fixed striped">
			<thead>
				<tr>
					<th>Proposal</th>
					<th>Generated</th>
					<th>Files</th>
					<th>Media</th>
					<th>Decisions</th>
					<th>Resolver reused</th>
					<th>Resolver unresolved</th>
				</tr>
			</thead>
			<tbody>
				{proposals.map((proposal) => {
					const metrics = proposal.resolver?.metrics ?? {};
					const isActive = proposal.id === selectedId;
					const decisionSummary = proposal.decisions ?? {};
					const acceptedCount = decisionSummary.accepted ?? 0;
					const keptCount = decisionSummary.kept ?? 0;
					const reviewedCount = decisionSummary.entities_reviewed ?? 0;
					const hasSelections = (decisionSummary.total ?? 0) > 0;

					return (
						<tr
							key={proposal.id}
							className={isActive ? 'is-active' : undefined}
							onClick={() => onSelect(proposal.id)}
							style={{ cursor: 'pointer' }}
						>
							<td>{proposal.title}</td>
							<td>{formatDate(proposal.generated_at)}</td>
							<td>{proposal.files ?? '—'}</td>
							<td>{proposal.media_items ?? '—'}</td>
							<td>
								{hasSelections ? (
									<div className="dbvc-decisions">
										<span className="dbvc-badge dbvc-badge--accept">{acceptedCount} accept</span>
										<span className="dbvc-badge dbvc-badge--keep">{keptCount} keep</span>
										{reviewedCount > 0 && (
											<span className="dbvc-badge dbvc-badge--reviewed">{reviewedCount} reviewed</span>
										)}
									</div>
								) : (
									'—'
								)}
							</td>
							<td>{metrics.reused ?? '—'}</td>
							<td>{metrics.unresolved ?? '—'}</td>
						</tr>
					);
				})}
			</tbody>
		</table>
	);
};

const ProposalUploader = ({ onUploaded, onError }) => {
	const [isDragging, setIsDragging] = useState(false);
	const [isUploading, setIsUploading] = useState(false);
	const [status, setStatus] = useState('');
	const [error, setError] = useState('');
	const [allowOverwrite, setAllowOverwrite] = useState(false);
	const inputRef = useRef(null);

	const handleFiles = useCallback(
		async (fileList) => {
			const file = fileList && fileList[0];
			if (!file) {
				return;
			}

			const formData = new window.FormData();
			formData.append('file', file);
			formData.append('overwrite', allowOverwrite ? '1' : '0');

			setIsUploading(true);
			setStatus('');
			setError('');

			try {
				const payload = await postFormData('proposals/upload', formData);
				setStatus(`Uploaded ${file.name}`);
				if (typeof onUploaded === 'function') {
					onUploaded(payload.proposal_id, payload);
				}
			} catch (err) {
				const message = err?.message || 'Upload failed.';
				setError(message);
				if (typeof onError === 'function') {
					onError(message);
				}
			} finally {
				setIsUploading(false);
				if (inputRef.current) {
					inputRef.current.value = '';
				}
			}
		},
			[allowOverwrite, onUploaded, onError]
		);

	const handleDrop = (event) => {
		event.preventDefault();
		setIsDragging(false);
		handleFiles(event.dataTransfer?.files);
	};

	const handleDragOver = (event) => {
		event.preventDefault();
		setIsDragging(true);
	};

	const handleDragLeave = (event) => {
		if (event.currentTarget.contains(event.relatedTarget)) {
			return;
		}
		setIsDragging(false);
	};

	return (
		<div className="dbvc-proposal-uploader">
			<div
				className={`dbvc-proposal-uploader__dropzone${isDragging ? ' is-dragging' : ''}${
					isUploading ? ' is-uploading' : ''
				}`}
				onDragOver={handleDragOver}
				onDragLeave={handleDragLeave}
				onDrop={handleDrop}
			>
				<div>
					<strong>Upload proposal bundle</strong>
					<p>Drop a DBVC ZIP export here or select a file to register it.</p>
					<Button
						variant="secondary"
						onClick={() => inputRef.current?.click()}
						disabled={isUploading}
					>
						{isUploading ? 'Uploading…' : 'Select ZIP'}
					</Button>
					<input
						ref={inputRef}
						type="file"
						accept=".zip"
						style={{ display: 'none' }}
						onChange={(event) => handleFiles(event.target.files)}
					/>
					<CheckboxControl
						label="Replace existing proposal when IDs match"
						help="Enable if this upload should refresh an existing proposal folder instead of creating a new one."
						checked={allowOverwrite}
						onChange={(value) => setAllowOverwrite(value)}
						disabled={isUploading}
					/>
				</div>
			</div>
			{status && <p className="dbvc-proposal-uploader__status">{status}</p>}
			{error && (
				<p className="dbvc-proposal-uploader__error" role="alert">
					{error}
				</p>
			)}
		</div>
	);
};

const ResolverSummary = ({ resolver }) => {
	if (!resolver) {
		return <p>Resolver metrics unavailable.</p>;
	}

	const { metrics = {}, conflicts = [] } = resolver;
	return (
		<div className="dbvc-admin-app__resolver">
			<h3>Resolver Summary</h3>
			<ul>
				<li>Detected: {metrics.detected ?? 0}</li>
				<li>Reused: {metrics.reused ?? 0}</li>
				<li>Downloaded: {metrics.downloaded ?? 0}</li>
				<li>Unresolved: {metrics.unresolved ?? 0}</li>
				<li>Conflicts: {conflicts.length}</li>
			</ul>
			{conflicts.length > 0 && (
				<div className="notice notice-warning">
					<p>The resolver reported {conflicts.length} conflict(s). Review before applying.</p>
				</div>
			)}
		</div>
	);
};

const EntityList = ({ entities, loading, selectedEntityId, onSelect }) => {
	if (loading) {
		return <p>Loading entities…</p>;
	}
	if (!entities.length) {
		return <p>No entities found for this proposal.</p>;
	}
	const virtualizationEnabled = entities.length > 200;
	const rowHeight = 52;
	const containerRef = useRef(null);
	const [viewportHeight, setViewportHeight] = useState(0);
	const [scrollOffset, setScrollOffset] = useState(0);

	useEffect(() => {
		if (!virtualizationEnabled) {
			setViewportHeight(0);
			setScrollOffset(0);
			return undefined;
		}
		const node = containerRef.current;
		if (!node) {
			return undefined;
		}
		const updateHeight = () => {
			setViewportHeight(node.clientHeight || 0);
		};
		updateHeight();
		let resizeObserver = null;
		if (typeof ResizeObserver !== 'undefined') {
			resizeObserver = new ResizeObserver(updateHeight);
			resizeObserver.observe(node);
		} else {
			window.addEventListener('resize', updateHeight);
		}
		return () => {
			if (resizeObserver) {
				resizeObserver.disconnect();
			} else {
				window.removeEventListener('resize', updateHeight);
			}
		};
	}, [virtualizationEnabled]);

	const buffer = 5;
	const totalRows = entities.length;
	const totalHeight = totalRows * rowHeight;
	const visibleRowCount =
		virtualizationEnabled && viewportHeight
			? Math.ceil(viewportHeight / rowHeight) + buffer
			: totalRows;
	const startIndex = virtualizationEnabled ? Math.max(0, Math.floor(scrollOffset / rowHeight) - buffer) : 0;
	const endIndex = virtualizationEnabled ? Math.min(totalRows, startIndex + visibleRowCount) : totalRows;
	const visibleEntities = virtualizationEnabled ? entities.slice(startIndex, endIndex) : entities;
	const paddingTop = virtualizationEnabled ? startIndex * rowHeight : 0;
	const paddingBottom = virtualizationEnabled ? Math.max(0, totalHeight - paddingTop - visibleEntities.length * rowHeight) : 0;

	const handleScroll = virtualizationEnabled
		? (event) => {
				setScrollOffset(event.currentTarget.scrollTop);
		  }
		: undefined;

	return (
		<div
			className={`dbvc-entity-table${virtualizationEnabled ? ' is-virtualized' : ''}`}
			ref={containerRef}
			onScroll={handleScroll}
		>
			<table className="widefat striped">
				<thead>
					<tr>
						<th>Title</th>
						<th>Type</th>
						<th>Status</th>
						<th>Content hash</th>
						<th>Media refs</th>
						<th>Resolver</th>
						<th>Unresolved</th>
						<th>Conflicts</th>
						<th>Decisions</th>
					</tr>
				</thead>
				<tbody>
					{virtualizationEnabled && paddingTop > 0 && (
						<tr className="dbvc-entity-spacer" aria-hidden="true" style={{ height: `${paddingTop}px` }}>
							<td colSpan={9} />
						</tr>
					)}
					{visibleEntities.map((entity) => {
						const isActive = entity.vf_object_uid === selectedEntityId;
						const summary = entity.resolver?.summary ?? {};
						const status = entity.resolver?.status ?? 'unknown';
					const decisionSummary = entity.decision_summary ?? {};
					const entityAccepted = decisionSummary.accepted ?? 0;
					const entityKept = decisionSummary.kept ?? 0;
					const entityHasSelections = (decisionSummary.total ?? 0) > 0;

					const rowClass = [
						`resolver-${status}`,
						isActive ? 'is-active' : '',
					]
						.filter(Boolean)
						.join(' ');

					const handleRowClick = (event) => {
						event.preventDefault();
						event.stopPropagation();
						onSelect(entity.vf_object_uid);
					};
						return (
							<tr
								key={entity.vf_object_uid}
								className={rowClass}
								onClick={handleRowClick}
								onKeyDown={(event) => {
								if (event.key === 'Enter' || event.key === ' ') {
									handleRowClick(event);
								}
							}}
							style={{ cursor: 'pointer' }}
							role="button"
							tabIndex={0}
						>
							<td>{entity.post_title || entity.vf_object_uid}</td>
							<td>{entity.post_type}</td>
							<td>{entity.post_status || '—'}</td>
							<td style={{ wordBreak: 'break-all' }}>{entity.content_hash ?? '—'}</td>
							<td>
								{(entity.media_refs?.meta?.length ?? 0) + (entity.media_refs?.content?.length ?? 0)}
							</td>
							<td>{renderStatusBadge(status)}</td>
							<td>{summary.unresolved ?? 0}</td>
							<td>{summary.conflicts ?? 0}</td>
							<td>
								{entityHasSelections ? (
									<div className="dbvc-decisions">
										{entityAccepted > 0 && (
											<span className="dbvc-badge dbvc-badge--accept">{entityAccepted} accept</span>
										)}
										{entityKept > 0 && (
											<span className="dbvc-badge dbvc-badge--keep">{entityKept} keep</span>
										)}
									</div>
								) : (
									'—'
								)}
							</td>
						</tr>
						);
					})}
					{virtualizationEnabled && paddingBottom > 0 && (
						<tr className="dbvc-entity-spacer" aria-hidden="true" style={{ height: `${paddingBottom}px` }}>
							<td colSpan={9} />
						</tr>
					)}
				</tbody>
			</table>
		</div>
	);
};

const EntityDetailPanel = ({
	entityDetail,
	resolverInfo,
	resolverDecisionSummary = null,
	decisions = {},
	onDecisionChange,
	onBulkDecision,
	onResetDecisions,
	onCaptureSnapshot,
	onResolverDecision,
	onResolverDecisionReset,
	onApplyResolverDecisionToSimilar,
	savingPaths = {},
	bulkSaving = false,
	resolverSaving = {},
	resolverError = null,
	decisionError,
	loading,
	error,
	onClose,
	filterMode,
	onFilterModeChange,
	isOpen = true,
	resettingDecisions = false,
	snapshotCapturing = false,
}) => {
	const headingId = useMemo(() => {
		if (entityDetail?.item?.vf_object_uid) {
			return `dbvc-entity-detail-${entityDetail.item.vf_object_uid}`;
		}
		if (entityDetail?.vf_object_uid) {
			return `dbvc-entity-detail-${entityDetail.vf_object_uid}`;
		}
		return 'dbvc-entity-detail';
	}, [entityDetail]);
	const closeButtonRef = useRef(null);
	const lastFocusedRef = useRef(null);

	useEffect(() => {
		if (!onClose || !isOpen) {
			return undefined;
		}
		lastFocusedRef.current =
			document.activeElement instanceof HTMLElement ? document.activeElement : null;
		const closeButton = closeButtonRef.current;
		if (closeButton) {
			closeButton.focus();
		}
		const handleKeyDown = (event) => {
			if (event.key === 'Escape') {
				event.preventDefault();
				onClose();
			}
		};
		document.addEventListener('keydown', handleKeyDown);
		return () => {
			document.removeEventListener('keydown', handleKeyDown);
			if (
				lastFocusedRef.current &&
				typeof lastFocusedRef.current.focus === 'function'
			) {
				lastFocusedRef.current.focus();
			}
		};
	}, [isOpen, onClose, headingId]);

	const renderPanelShell = (children) => {
		const header = (
			<div className="dbvc-entity-detail__header">
				<h3 id={headingId}>{item?.post_title || 'Entity detail'}</h3>
				{item && (
					<div className="dbvc-entity-detail__meta">
						<div>
							<strong>Slug:</strong> {item.post_name || '—'}
						</div>
						<div>
							<strong>File:</strong> {item.path || '—'}
						</div>
					</div>
				)}
				{onClose && (
					<button
						type="button"
						className="dbvc-entity-detail__close"
						onClick={onClose}
						aria-label="Close entity detail"
						ref={onClose ? closeButtonRef : undefined}
					>
						Close
					</button>
				)}
			</div>
		);

		const body = (
			<div className="dbvc-admin-app__entity-detail">
				{header}
				{children}
			</div>
		);

		if (!onClose) {
			return body;
		}

		return (
			<div className="dbvc-entity-detail-modal" role="presentation">
				<div className="dbvc-entity-detail-modal__overlay" onClick={onClose} aria-hidden="true"></div>
				<div
					className="dbvc-entity-detail-modal__panel"
					role="dialog"
					aria-modal="true"
					aria-labelledby={headingId}
				>
					{body}
				</div>
			</div>
		);
	};

	const { item, current, proposed = {}, diff } = entityDetail ?? {};
	const decisionSummary = entityDetail?.decision_summary ?? {};
	const totalSelections = decisionSummary.total ?? 0;
	const selectionsLabel =
		totalSelections > 0
			? `${decisionSummary.accepted ?? 0} accepted · ${decisionSummary.kept ?? 0} kept`
			: 'No selections captured yet.';
	const changes = diff?.changes ?? [];
	const filteredChanges = useMemo(() => {
		if (filterMode !== 'conflicts') {
			return changes;
		}
		return changes.filter((change) => {
			const path = change.path || '';
			const decision = decisions?.[path] ?? 'keep';
			return decision !== 'accept';
		});
	}, [changes, filterMode, decisions]);
	const groupedChanges = useMemo(() => groupDiffChanges(filteredChanges), [filteredChanges]);
	const attachments = resolverInfo?.attachments ?? [];
	const [resolverSearch, setResolverSearch] = useState('');
	const filteredAttachments = useMemo(() => {
		if (!resolverSearch) {
			return attachments;
		}
		const term = resolverSearch.toLowerCase();
		return attachments.filter((attachment) => {
			const descriptor = attachment.descriptor || {};
			const haystack = [
				attachment.original_id,
				attachment.reason,
				attachment.status,
				attachment.decision?.note,
				descriptor.asset_uid,
				descriptor.bundle_path,
				descriptor.path,
			]
				.filter(Boolean)
				.join(' ')
				.toLowerCase();
			return haystack.includes(term);
		});
	}, [attachments, resolverSearch]);

	const filterActive = filterMode === 'conflicts';
	const filteredCount = filteredChanges.length;
	const totalCount = changes.length;
	const [showAllSections, setShowAllSections] = useState(false);
	const [activeSection, setActiveSection] = useState('');
	const [bulkResolverFilterType, setBulkResolverFilterType] = useState('reason');
	const [bulkResolverFilterValue, setBulkResolverFilterValue] = useState('');
	const [bulkResolverAction, setBulkResolverAction] = useState('');
	const [bulkResolverTargetId, setBulkResolverTargetId] = useState('');
	const [bulkResolverNote, setBulkResolverNote] = useState('');
	const [bulkResolverPersistGlobal, setBulkResolverPersistGlobal] = useState(false);
	const [bulkResolverSubmitting, setBulkResolverSubmitting] = useState(false);
	const [bulkResolverError, setBulkResolverError] = useState('');
	useEffect(() => {
		if (!showAllSections) {
			setActiveSection(groupedChanges[0]?.key || '');
		}
	}, [groupedChanges, showAllSections]);

	const visibleGroups = useMemo(() => {
		if (showAllSections || !activeSection) {
			return groupedChanges;
		}
		return groupedChanges.filter((section) => section.key === activeSection);
	}, [groupedChanges, showAllSections, activeSection]);

	const bulkSourceItems = useMemo(() => {
		if (showAllSections) {
			return filteredChanges;
		}
		const match = groupedChanges.find((section) => section.key === activeSection);
		return match ? match.items : [];
	}, [showAllSections, filteredChanges, groupedChanges, activeSection]);

	const bulkPaths = useMemo(
		() => bulkSourceItems.map((change) => change.path).filter(Boolean),
		[bulkSourceItems]
	);

	const resolverBulkOptions = useMemo(() => {
		const reasons = new Set();
		const assetUids = new Set();
		const bundlePaths = new Set();

		attachments.forEach((attachment) => {
			if (attachment.reason) {
				reasons.add(attachment.reason);
			}
			const descriptor = attachment.descriptor || {};
			if (descriptor.asset_uid) {
				assetUids.add(descriptor.asset_uid);
			}
			const manifestPath = descriptor.bundle_path || descriptor.path || '';
			if (manifestPath) {
				bundlePaths.add(manifestPath);
			}
		});

		return {
			reason: Array.from(reasons),
			asset_uid: Array.from(assetUids),
			bundle_path: Array.from(bundlePaths),
		};
	}, [attachments]);

	useEffect(() => {
		const values = resolverBulkOptions[bulkResolverFilterType] || [];
		if (!values.length) {
			setBulkResolverFilterValue('');
			return;
		}
		if (!values.includes(bulkResolverFilterValue)) {
			setBulkResolverFilterValue(values[0] || '');
		}
	}, [bulkResolverFilterType, bulkResolverFilterValue, resolverBulkOptions]);

	const matchingResolverAttachments = useMemo(() => {
		if (!bulkResolverFilterValue && (resolverBulkOptions[bulkResolverFilterType] || []).length) {
			return [];
		}
		return attachments.filter((attachment) => {
			const descriptor = attachment.descriptor || {};
			switch (bulkResolverFilterType) {
				case 'asset_uid':
					return (descriptor.asset_uid || '') === bulkResolverFilterValue;
				case 'bundle_path':
					return (descriptor.bundle_path || descriptor.path || '') === bulkResolverFilterValue;
				case 'reason':
				default:
					return (attachment.reason || '') === bulkResolverFilterValue;
			}
		});
	}, [attachments, bulkResolverFilterType, bulkResolverFilterValue, resolverBulkOptions]);

	const bulkResolverMatchCount = matchingResolverAttachments.length;
	const bulkResolverNeedsTarget = bulkResolverAction === 'reuse' || bulkResolverAction === 'map';

	const handleResolverBulkSubmit = async () => {
		if (!bulkResolverAction) {
			setBulkResolverError('Select an action to apply.');
			return;
		}
		if (bulkResolverNeedsTarget && !bulkResolverTargetId) {
			setBulkResolverError('Enter a target attachment ID for this action.');
			return;
		}
		if (!bulkResolverMatchCount) {
			setBulkResolverError('No matching conflicts were found for the selected filter.');
			return;
		}
		if (!onResolverDecision) {
			return;
		}
		if (
			!window.confirm(
				`Apply this decision to ${bulkResolverMatchCount} conflict(s) matched by ${
					bulkResolverFilterType === 'reason'
						? 'reason'
						: bulkResolverFilterType === 'asset_uid'
						? 'asset UID'
						: 'manifest path'
				 }?`
			)
		) {
			return;
		}

		setBulkResolverSubmitting(true);
		setBulkResolverError('');
		const payload = {
			action: bulkResolverAction,
			target_id: bulkResolverNeedsTarget ? Number(bulkResolverTargetId) : null,
			note: bulkResolverNote,
			persist_global: bulkResolverPersistGlobal,
		};

		try {
			for (const attachment of matchingResolverAttachments) {
				if (!attachment.original_id) {
					continue;
				}
				await onResolverDecision(attachment.original_id, payload);
			}
			setBulkResolverNote('');
			setBulkResolverTargetId('');
		} catch (err) {
			setBulkResolverError(err?.message || 'Bulk apply failed.');
		} finally {
			setBulkResolverSubmitting(false);
		}
	};

	if (onClose && !isOpen) {
		return null;
	}

	if (loading) {
		return renderPanelShell(<p>Loading entity detail…</p>);
	}
	if (error) {
		return renderPanelShell(
			<div className="notice notice-error">
				<p>Failed to load entity detail: {error}</p>
			</div>
		);
	}
	if (!entityDetail) {
		return renderPanelShell(<p>Select an entity to view details.</p>);
	}

	return renderPanelShell(
		<>
			<div className="dbvc-entity-toolbar">
				<div className="dbvc-entity-toolbar__meta">
					<div className="dbvc-entity-toolbar__title">{item?.post_title || 'Entity detail'}</div>
					<div className="dbvc-entity-toolbar__sub">
						<span>Slug: {item?.post_name || '—'}</span>
						<span>File: {item?.path || '—'}</span>
					</div>
				</div>
				<div className="dbvc-diff-filter">
					<span>View:</span>
					<button
						type="button"
						className={filterActive ? 'is-active' : ''}
						onClick={() => onFilterModeChange && onFilterModeChange('conflicts')}
					>
						Conflicts & Resolver
					</button>
					<button
						type="button"
						className={!filterActive ? 'is-active' : ''}
						onClick={() => onFilterModeChange && onFilterModeChange('full')}
					>
						Full Overview
					</button>
				</div>
				<p className="dbvc-entity-toolbar__count">
					{filteredCount} field(s) shown
					{filterActive ? ` (from ${totalCount} total changes)` : ''}.
				</p>
				{bulkPaths.length > 0 && (
					<div className="dbvc-bulk-actions">
						<button
							type="button"
							className="button button-secondary"
							onClick={() => {
								if (onBulkDecision && window.confirm(`Accept ${bulkPaths.length} field(s)?`)) {
									onBulkDecision('accept', bulkPaths);
								}
							}}
							disabled={bulkSaving}
						>
							{bulkSaving ? 'Accepting…' : 'Accept All Visible'}
						</button>
						<button
							type="button"
							className="button"
							onClick={() => {
								if (onBulkDecision && window.confirm(`Keep current values for ${bulkPaths.length} field(s)?`)) {
									onBulkDecision('keep', bulkPaths);
								}
							}}
							disabled={bulkSaving}
						>
							{bulkSaving ? 'Keeping…' : 'Keep All Visible'}
						</button>
					</div>
				)}
				<p className="dbvc-decision-summary">Selections: {selectionsLabel}</p>
			</div>

		<div className="dbvc-entity-detail__actions">
			<Button
				variant="secondary"
				onClick={onCaptureSnapshot}
				isBusy={snapshotCapturing}
				disabled={snapshotCapturing || !onCaptureSnapshot}
			>
				{snapshotCapturing ? 'Capturing snapshot…' : 'Capture current snapshot'}
			</Button>
			<p className="description">Creates a fresh “current” snapshot from the live site for this entity.</p>
		</div>

		{decisionError && (
			<div className="notice notice-error">
				<p>{decisionError}</p>
			</div>
		)}
		{typeof onResetDecisions === 'function' && totalSelections > 0 && (
			<div className="dbvc-entity-detail__actions">
				<Button
					variant="tertiary"
					onClick={onResetDecisions}
					isBusy={resettingDecisions}
					disabled={resettingDecisions}
				>
					{resettingDecisions ? 'Clearing…' : 'Clear all decisions'}
				</Button>
				<p className="description">Removes every Accept/Keep choice for this entity.</p>
			</div>
		)}

		{groupedChanges.length > 0 && (
			<div className="dbvc-section-nav">
					<span>{showAllSections ? 'Sections:' : 'Jump to:'}</span>
					{groupedChanges.map((section) => (
						<button
							type="button"
							key={section.key}
							className={!showAllSections && activeSection === section.key ? 'is-active' : ''}
							onClick={() => {
								if (showAllSections) {
									const target = document.getElementById(`dbvc-diff-${section.key}`);
									if (target) {
										target.scrollIntoView({ behavior: 'smooth', block: 'start' });
									}
								} else {
									setActiveSection(section.key);
								}
							}}
						>
							{section.label}
						</button>
					))}
					<button
						type="button"
						className={showAllSections ? 'is-active' : ''}
						onClick={() => setShowAllSections((prev) => !prev)}
					>
						{showAllSections ? 'Focus Section' : 'Show All'}
					</button>
				</div>
			)}

			{visibleGroups.length > 0 ? (
				visibleGroups.map((section) => (
					<DiffSection
						key={section.key}
						section={section}
						decisions={decisions}
						onDecisionChange={onDecisionChange}
						savingPaths={savingPaths}
					/>
				))
			) : (
				<p>
					{filterActive && totalCount > 0
						? 'No resolver or media conflicts detected for this entity. Switch to Full Overview to inspect all differences.'
						: 'No differences detected.'}
				</p>
			)}

			{attachments.length > 0 && (
				<div className="dbvc-admin-app__resolver-attachments">
					<h4>Media Resolver</h4>
					<div className="dbvc-panel-search">
						<label>
							Search:
							&nbsp;
							<input
								type="search"
								value={resolverSearch}
								onChange={(event) => setResolverSearch(event.target.value)}
								placeholder="Reason, asset UID, note…"
							/>
						</label>
					</div>
					{resolverDecisionSummary && (
						<p className="dbvc-resolver-summary">
							Saved decisions — reuse: {resolverDecisionSummary.reuse ?? 0}, map: {resolverDecisionSummary.map ?? 0}, download:{' '}
							{resolverDecisionSummary.download ?? 0}, skip: {resolverDecisionSummary.skip ?? 0}
						</p>
					)}
					{resolverError && (
						<div className="notice notice-error">
							<p>{resolverError}</p>
						</div>
					)}
					{filteredAttachments.length > 0 ? (
						<table className="widefat">
							<thead>
								<tr>
									<th>Original ID</th>
									<th>Status</th>
									<th>Preview</th>
									<th>Target</th>
									<th>Reason</th>
									<th>Decision</th>
									<th style={{ width: '160px' }}>Actions</th>
								</tr>
							</thead>
							<tbody>
								{filteredAttachments.map((attachment) => (
									<ResolverDecisionRow
										key={attachment.original_id}
										attachment={attachment}
										saving={!!resolverSaving[attachment.original_id]}
										onSave={onResolverDecision}
										onClear={onResolverDecisionReset}
										onApplyToSimilar={onApplyResolverDecisionToSimilar}
									/>
								))}
							</tbody>
						</table>
					) : (
						<p>No resolver conflicts match your search.</p>
					)}
						<div className="dbvc-resolver-bulk">
							<h5>Bulk Apply Resolver Decision</h5>
							<p className="description">Match a subset of conflicts and apply a single decision without editing rows individually.</p>
							<div className="dbvc-resolver-bulk__filters">
								<label>
									Match by
									<select value={bulkResolverFilterType} onChange={(event) => setBulkResolverFilterType(event.target.value)}>
										<option value="reason">Reason</option>
										<option value="asset_uid">Asset UID</option>
										<option value="bundle_path">Manifest Path</option>
									</select>
								</label>
								<label>
									Value
									<select
										value={bulkResolverFilterValue}
										onChange={(event) => setBulkResolverFilterValue(event.target.value)}
										disabled={(resolverBulkOptions[bulkResolverFilterType] || []).length === 0}
									>
										{(resolverBulkOptions[bulkResolverFilterType] || []).length === 0 && <option value="">No values detected</option>}
										{(resolverBulkOptions[bulkResolverFilterType] || []).map((value) => (
											<option key={value || 'blank'} value={value}>
												{value || '— Not provided —'}
											</option>
										))}
									</select>
								</label>
								<span className="dbvc-resolver-bulk__count">Matches: {bulkResolverMatchCount}</span>
							</div>
							<div className="dbvc-resolver-controls dbvc-resolver-bulk__controls">
								<select value={bulkResolverAction} onChange={(event) => setBulkResolverAction(event.target.value)}>
									<option value="">Select action…</option>
									<option value="reuse">Reuse existing</option>
									<option value="download">Download new</option>
									<option value="map">Map to attachment ID</option>
									<option value="skip">Skip</option>
								</select>
								{bulkResolverNeedsTarget && (
									<input
										type="number"
										min="1"
										placeholder="Attachment ID"
										value={bulkResolverTargetId}
										onChange={(event) => setBulkResolverTargetId(event.target.value)}
									/>
								)}
								<textarea
									value={bulkResolverNote}
									onChange={(event) => setBulkResolverNote(event.target.value)}
									placeholder="Optional note"
									rows={2}
								/>
								<label>
									<input
										type="checkbox"
										checked={bulkResolverPersistGlobal}
										onChange={(event) => setBulkResolverPersistGlobal(event.target.checked)}
									/>{' '}
									Remember as global rule
								</label>
							</div>
							{bulkResolverError && (
								<div className="notice notice-error">
									<p>{bulkResolverError}</p>
								</div>
							)}
							<button
								type="button"
								className="button button-secondary"
								onClick={handleResolverBulkSubmit}
								disabled={bulkResolverSubmitting}
							>
								{bulkResolverSubmitting ? 'Applying…' : 'Apply to Matches'}
							</button>
						</div>
					</div>
				)}

		<div className="dbvc-admin-app__entity-columns">
			<div>
				<h4>Raw Current</h4>
				<pre>{JSON.stringify(current, null, 2)}</pre>
			</div>
			<div>
				<h4>Raw Proposed (Bundle)</h4>
				<pre>{JSON.stringify(proposed, null, 2)}</pre>
			</div>
		</div>
		</>
	);
};

const App = () => {
	const [proposals, setProposals] = useState([]);
	const [selectedId, setSelectedId] = useState(null);
	const [resolver, setResolver] = useState(null);
	const [entities, setEntities] = useState([]);
	const [entityFilter, setEntityFilter] = useState('needs_review');
	const [entitySearch, setEntitySearch] = useState('');
	const [selectedEntityId, setSelectedEntityId] = useState(null);
	const [entityDetail, setEntityDetail] = useState(null);
	const [isEntityDetailOpen, setIsEntityDetailOpen] = useState(false);
	const [entityDecisions, setEntityDecisions] = useState({});
	const [decisionSaving, setDecisionSaving] = useState({});
	const [decisionError, setDecisionError] = useState(null);
	const [loadingProposals, setLoadingProposals] = useState(true);
	const [hasLoadedProposals, setHasLoadedProposals] = useState(false);
	const [loadingEntities, setLoadingEntities] = useState(false);
	const [loadingResolver, setLoadingResolver] = useState(false);
	const [loadingEntityDetail, setLoadingEntityDetail] = useState(false);
	const [errorProposals, setErrorProposals] = useState(null);
	const [errorEntities, setErrorEntities] = useState(null);
	const [errorResolver, setErrorResolver] = useState(null);
	const [errorEntityDetail, setErrorEntityDetail] = useState(null);
	const [applying, setApplying] = useState(false);
	const [applyResult, setApplyResult] = useState(null);
	const [applyError, setApplyError] = useState(null);
	const [isApplyModalOpen, setIsApplyModalOpen] = useState(false);
	const [applyMode, setApplyMode] = useState('full');
	const [applyIgnoreHash, setApplyIgnoreHash] = useState(false);
	const [diffFilterMode, setDiffFilterMode] = useState('conflicts');
	const [toasts, setToasts] = useState([]);
	const [applyHistory, setApplyHistory] = useState([]);
	const [resolverSaving, setResolverSaving] = useState({});
	const [resolverError, setResolverError] = useState(null);
	const [resettingDecisions, setResettingDecisions] = useState(false);
	const [snapshotCapturing, setSnapshotCapturing] = useState(false);
	const [captureAllSnapshotsLoading, setCaptureAllSnapshotsLoading] = useState(false);
	const [clearingAll, setClearingAll] = useState(false);
	const reloadProposals = useCallback(
		async (options = {}) => {
			const { signal, focusProposalId } = options;
			setLoadingProposals(true);
			setErrorProposals(null);
			try {
				const payload = await fetchJSON('proposals', signal ? { signal } : undefined);
				const items = payload.items ?? [];
				setProposals(items);
				setSelectedId((current) => {
					if (focusProposalId && items.some((item) => item.id === focusProposalId)) {
						return focusProposalId;
					}
					if (current && items.some((item) => item.id === current)) {
						return current;
					}
					return items[0]?.id ?? null;
				});
			} catch (err) {
				if (err.name === 'AbortError') {
					return;
				}
				setErrorProposals(err.message);
			} finally {
				setLoadingProposals(false);
				setHasLoadedProposals(true);
			}
		},
		[]
	);

	const stopPropagation = useCallback((event) => {
		event.stopPropagation();
	}, []);

	const preventOuterSubmit = useCallback((event) => {
		event.preventDefault();
		event.stopPropagation();
	}, []);

	const resolverAttachments = useMemo(() => {
		if (entityDetail?.resolver?.attachments?.length) {
			return entityDetail.resolver.attachments;
		}
		return resolver?.attachments || [];
	}, [resolver, entityDetail]);
	const [bulkSaving, setBulkSaving] = useState(false);

	useEffect(() => {
		setApplyResult(null);
		setApplyError(null);
		setApplying(false);
		setIsApplyModalOpen(false);
		setApplyMode('full');
		setApplyIgnoreHash(false);
		setToasts([]);
	}, [selectedId]);

	useEffect(() => {
		if (applyMode !== 'partial') {
			setApplyIgnoreHash(false);
		}
	}, [applyMode]);

	useEffect(() => {
		if (toasts.length === 0) {
			return;
		}

		const timer = setTimeout(() => {
			setToasts((prev) => prev.slice(1));
		}, 6000);

		return () => clearTimeout(timer);
	}, [toasts]);

	useEffect(() => {
		const controller = new AbortController();
		reloadProposals({ signal: controller.signal });
		return () => controller.abort();
	}, [reloadProposals]);

	const loadEntities = useCallback(async (proposalId, filter, signal) => {
		if (!proposalId) {
			setEntities([]);
			return [];
		}
		setLoadingEntities(true);
		setErrorEntities(null);
		try {
			const query = filter && filter !== 'all' ? `?status=${encodeURIComponent(filter)}` : '';
			const payload = await fetchJSON(`proposals/${encodeURIComponent(proposalId)}/entities${query}`, { signal });
			const items = payload.items ?? [];
			setEntities(items);
			if (payload.decision_summary) {
				setProposals((prev) =>
					prev.map((proposal) =>
						proposal.id === proposalId
							? { ...proposal, decisions: payload.decision_summary }
							: proposal
					)
				);
			}
			if (payload.resolver_decisions) {
				setProposals((prev) =>
					prev.map((proposal) =>
						proposal.id === proposalId
							? { ...proposal, resolver_decisions: payload.resolver_decisions }
							: proposal
					)
				);
			}
			return items;
		} catch (err) {
			if (err.name !== 'AbortError') {
				setErrorEntities(err.message);
			}
			return [];
		} finally {
			setLoadingEntities(false);
		}
	}, []);

	const loadResolver = useCallback(async (proposalId, signal) => {
		if (!proposalId) {
			setResolver(null);
			return;
		}
		setLoadingResolver(true);
		setErrorResolver(null);
		try {
			const payload = await fetchJSON(`proposals/${encodeURIComponent(proposalId)}/resolver`, { signal });
			setResolver(payload);
		} catch (err) {
			if (err.name !== 'AbortError') {
				setErrorResolver(err.message);
			}
		} finally {
			setLoadingResolver(false);
		}
	}, []);

	useEffect(() => {
		if (!selectedId) {
			return;
		}

		const controller = new AbortController();
		setSelectedEntityId(null);
		setEntityDetail(null);
		loadEntities(selectedId, entityFilter, controller.signal).then((items) => {
			if (items.length) {
				setSelectedEntityId(items[0].vf_object_uid);
				setIsEntityDetailOpen(true);
			} else {
				setSelectedEntityId(null);
				setIsEntityDetailOpen(false);
			}
		});
		return () => controller.abort();
	}, [selectedId, entityFilter, loadEntities]);

	useEffect(() => {
		if (!selectedId) {
			return;
		}

		const controller = new AbortController();
		loadResolver(selectedId, controller.signal);
		return () => controller.abort();
	}, [selectedId, loadResolver]);

	useEffect(() => {
		if (!selectedId || !selectedEntityId) {
			setEntityDetail(null);
			setEntityDecisions({});
			setDecisionSaving({});
			return;
		}

		const controller = new AbortController();
		const load = async () => {
			setLoadingEntityDetail(true);
			setErrorEntityDetail(null);
			setDecisionError(null);
			setDecisionSaving({});
			try {
				const payload = await fetchJSON(
					`proposals/${encodeURIComponent(selectedId)}/entities/${encodeURIComponent(selectedEntityId)}`,
					{ signal: controller.signal }
				);
				setEntityDetail(payload);
				setEntityDecisions(payload.decisions ?? {});
			} catch (err) {
				if (err.name !== 'AbortError') {
					setErrorEntityDetail(err.message);
				}
			} finally {
				setLoadingEntityDetail(false);
			}
		};
		load();

		return () => controller.abort();
	}, [selectedId, selectedEntityId]);

	const updateAttachmentsDecision = useCallback(
		(originalId, decision) => {
			const originalNumeric = Number(originalId);
			const patch = (attachments = []) =>
				attachments.map((attachment) => {
					const attachmentId =
						typeof attachment.original_id !== 'undefined'
							? attachment.original_id
							: attachment.descriptor?.original_id;
					if (attachmentId === originalNumeric) {
						if (decision) {
							return { ...attachment, decision };
						}
						const { decision: _omit, ...rest } = attachment;
						return rest;
					}
					return attachment;
				});

			setResolver((prev) =>
				prev
					? {
							...prev,
							attachments: patch(prev.attachments || []),
					  }
					: prev
			);

			setEntityDetail((prev) =>
				prev
					? {
							...prev,
							resolver: {
								...(prev.resolver || {}),
								attachments: patch(prev.resolver?.attachments || []),
							},
					  }
					: prev
			);

			setEntities((prev) =>
				prev.map((entity) =>
					entity.vf_object_uid === selectedEntityId
						? {
								...entity,
								resolver: {
									...(entity.resolver || {}),
									attachments: patch(entity.resolver?.attachments || []),
								},
						  }
						: entity
				)
			);
		},
		[selectedEntityId]
	);

	const filteredEntities = useMemo(() => {
		if (!entitySearch) {
			return entities;
		}
		const needle = entitySearch.toLowerCase();
		return entities.filter((entity) => {
			const haystack = [
				entity.post_title,
				entity.post_type,
				entity.post_status,
				entity.path,
			]
				.filter(Boolean)
				.join(' ')
				.toLowerCase();
			return haystack.includes(needle);
		});
	}, [entities, entitySearch]);

	const selectedEntity = useMemo(
		() => entities.find((entity) => entity.vf_object_uid === selectedEntityId),
		[entities, selectedEntityId]
	);

	const selectedProposal = useMemo(
		() => proposals.find((proposal) => proposal.id === selectedId),
		[proposals, selectedId]
	);

	useEffect(() => {
		if (!filteredEntities.length) {
			setSelectedEntityId(null);
			setIsEntityDetailOpen(false);
			return;
		}
		if (!filteredEntities.find((entity) => entity.vf_object_uid === selectedEntityId)) {
			setSelectedEntityId(filteredEntities[0].vf_object_uid);
			setIsEntityDetailOpen(true);
		}
	}, [filteredEntities, selectedEntityId]);

	useEffect(() => {
		if (entities.length && !selectedEntityId) {
			setSelectedEntityId(entities[0].vf_object_uid);
			setIsEntityDetailOpen(true);
		}
	}, [entities, selectedEntityId]);

	useEffect(() => {
		setDiffFilterMode('conflicts');
	}, [selectedEntityId]);

	const handleSelectEntity = useCallback((entityId) => {
		if (!entityId) {
			setSelectedEntityId(null);
			setIsEntityDetailOpen(false);
			return;
		}
		setSelectedEntityId((prev) => (prev === entityId ? prev : entityId));
		setIsEntityDetailOpen(true);
	}, []);

	const handleCloseEntityDetail = useCallback(() => {
		setIsEntityDetailOpen(false);
	}, []);

	const handleFilterChange = (event) => {
		setEntityFilter(event.target.value);
	};

	const handleSearchChange = (event) => {
		setEntitySearch(event.target.value);
	};

	const handleOpenApplyModal = useCallback(() => {
		if (!selectedId) {
			return;
		}

		setApplyError(null);
		setApplyResult(null);
		setApplyMode('full');
		setApplyIgnoreHash(false);
		setIsApplyModalOpen(true);
	}, [selectedId]);

	const handleCancelApply = useCallback(() => {
		if (applying) {
			return;
		}
		setIsApplyModalOpen(false);
	}, [applying]);

	const dismissToast = useCallback((id) => {
		setToasts((prev) => prev.filter((toast) => toast.id !== id));
	}, []);

	const handleConfirmApply = useCallback(async () => {
		if (!selectedId) {
			return;
		}

		setIsApplyModalOpen(false);
		setApplyError(null);
		setApplyResult(null);
		setApplying(true);

		try {
			const payload = await postJSON(
				`proposals/${encodeURIComponent(selectedId)}/apply`,
				{ mode: applyMode, ignore_missing_hash: applyIgnoreHash }
			);

			setApplyResult(payload);

			if (payload.decisions) {
				setProposals((prev) =>
					prev.map((proposal) =>
						proposal.id === selectedId
							? { ...proposal, decisions: payload.decisions }
							: proposal
					)
				);
			}
			if (payload.resolver_decisions) {
				setProposals((prev) =>
					prev.map((proposal) =>
						proposal.id === selectedId
							? { ...proposal, resolver_decisions: payload.resolver_decisions }
							: proposal
					)
				);
			}

			const importedCount = payload?.result?.imported ?? 0;
			const skippedCount = payload?.result?.skipped ?? 0;
			const errorsList = Array.isArray(payload?.result?.errors) ? payload.result.errors : [];
			const timestamp = new Date().toISOString();
			const toastId = Date.now();
			const detailParts = [];
			if (errorsList.length > 0) {
				detailParts.push(errorsList[0]);
			}
			if (payload.decisions_cleared && payload.auto_clear_enabled) {
				detailParts.push('Reviewer decisions cleared.');
			}
			if (payload.ignore_missing_hash) {
				detailParts.push('Hash check override enabled.');
			}
			if (payload.resolver_decisions) {
				detailParts.push(
					`Resolver decisions — reuse: ${payload.resolver_decisions.reuse ?? 0}, map: ${
						payload.resolver_decisions.map ?? 0
					}, download: ${payload.resolver_decisions.download ?? 0}, skip: ${payload.resolver_decisions.skip ?? 0}`
				);
			}

			setToasts((prev) => [
				...prev,
				{
					id: toastId,
					severity: errorsList.length ? 'warning' : 'success',
					title: `Applied ${selectedProposal?.title || selectedId}`,
					message: `Imported ${importedCount} · Skipped ${skippedCount}`,
					detail: detailParts.join(' '),
					timestamp,
				},
			]);

			setApplyHistory((prev) => {
				const entry = {
					id: toastId,
					timestamp,
					proposalId: selectedId,
					proposalTitle: selectedProposal?.title || selectedId,
					mode: payload.mode ?? applyMode,
					imported: importedCount,
					skipped: skippedCount,
					errors: errorsList,
					decisionsCleared: Boolean(payload.decisions_cleared && payload.auto_clear_enabled),
					ignoreMissingHash: Boolean(payload.ignore_missing_hash),
					resolverDecisions: payload.resolver_decisions ?? null,
				};
				return [entry, ...prev].slice(0, 10);
			});

			setSelectedEntityId(null);
			setIsEntityDetailOpen(false);
			setEntityDetail(null);
			setEntityDecisions({});
			setDecisionSaving({});

			const items = await loadEntities(selectedId, entityFilter);
			if (items.length) {
				setSelectedEntityId(items[0].vf_object_uid);
				setIsEntityDetailOpen(true);
			}

			await loadResolver(selectedId);
		} catch (err) {
			const errorMessage = err?.message || 'Apply request failed.';
			setApplyError(errorMessage);

			const timestamp = new Date().toISOString();
			const toastId = Date.now();
			const detailParts = [errorMessage];
			if (applyIgnoreHash) {
				detailParts.push('Hash check override requested.');
			}

			setToasts((prev) => [
				...prev,
				{
					id: toastId,
					severity: 'warning',
					title: `Apply failed (${selectedProposal?.title || selectedId})`,
					message: errorMessage,
					detail: detailParts.join(' '),
					timestamp,
				},
			]);

			setApplyHistory((prev) => {
				const entry = {
					id: toastId,
					timestamp,
					proposalId: selectedId,
					proposalTitle: selectedProposal?.title || selectedId,
					mode: applyMode,
					imported: 0,
					skipped: 0,
					errors: [errorMessage],
					decisionsCleared: false,
					ignoreMissingHash: applyIgnoreHash,
				};
				return [entry, ...prev].slice(0, 10);
			});
		} finally {
			setApplying(false);
		}
	}, [selectedId, applyMode, loadEntities, entityFilter, loadResolver, selectedProposal, applyIgnoreHash]);

	const handleDecisionChange = useCallback(
		async (path, action) => {
			if (!selectedId || !selectedEntityId) {
				return;
			}

			setDecisionError(null);
			setDecisionSaving((prev) => ({ ...prev, [path]: true }));

			try {
				const payload = await postJSON(
					`proposals/${encodeURIComponent(selectedId)}/entities/${encodeURIComponent(selectedEntityId)}/selections`,
					{ path, action }
				);

				const nextDecisions = payload.decisions ?? {};
				const nextSummary = payload.summary ?? null;

				setEntityDecisions(nextDecisions);
				setEntityDetail((prev) =>
					prev
						? {
								...prev,
								decisions: nextDecisions,
								decision_summary: nextSummary ?? prev.decision_summary,
						  }
						: prev
				);
				setEntities((prev) =>
					prev.map((entity) =>
						entity.vf_object_uid === selectedEntityId
							? {
									...entity,
									decision_summary: nextSummary ?? entity.decision_summary,
							  }
							: entity
					)
				);
				if (payload.proposal_summary) {
					setProposals((prev) =>
						prev.map((proposal) =>
							proposal.id === selectedId
								? { ...proposal, decisions: payload.proposal_summary }
								: proposal
						)
					);
				}
			} catch (err) {
				setDecisionError(err.message);
			} finally {
				setDecisionSaving((prev) => {
					const next = { ...prev };
					delete next[path];
					return next;
				});
			}
		},
		[selectedId, selectedEntityId]
	);

	const saveResolverDecision = useCallback(
		async (originalId, payload) => {
			if (!selectedId) {
				return;
			}

			setResolverError(null);
			setResolverSaving((prev) => ({ ...prev, [originalId]: true }));

			try {
				const response = await postJSON(
					`proposals/${encodeURIComponent(selectedId)}/resolver/${encodeURIComponent(originalId)}`,
					payload
				);
				updateAttachmentsDecision(originalId, response.decision ?? null);
				return response;
			} catch (err) {
				setResolverError(err.message);
				throw err;
			} finally {
				setResolverSaving((prev) => {
					const next = { ...prev };
					delete next[originalId];
					return next;
				});
			}
		},
		[selectedId, updateAttachmentsDecision]
	);

	const handleResolverDecision = useCallback(
		async (originalId, payload) => {
			await saveResolverDecision(originalId, payload);
		},
		[saveResolverDecision]
	);

	const handleResolverDecisionReset = useCallback(
		async (originalId, scope = 'proposal') => {
			if (!selectedId) {
				return;
			}

			setResolverError(null);
			setResolverSaving((prev) => ({ ...prev, [originalId]: true }));

			try {
				const response = await deleteJSON(
					`proposals/${encodeURIComponent(selectedId)}/resolver/${encodeURIComponent(originalId)}?scope=${encodeURIComponent(scope)}`
				);
				updateAttachmentsDecision(originalId, response.decision ?? null);
			} catch (err) {
				setResolverError(err.message);
			} finally {
				setResolverSaving((prev) => {
					const next = { ...prev };
					delete next[originalId];
					return next;
				});
			}
		},
		[selectedId, updateAttachmentsDecision]
	);

	const handleResolverDecisionApplySimilar = useCallback(
		async (attachment, payload) => {
			const reason = attachment.reason || '';
			if (!reason || !resolverAttachments.length) {
				return;
			}

			const targets = resolverAttachments.filter((candidate) => {
				if (candidate.original_id === attachment.original_id) {
					return false;
				}
				if ((candidate.reason || '') !== reason) {
					return false;
				}
				return true;
			});

			if (!targets.length) {
				window.alert('No similar conflicts found to apply this decision to.');
				return;
			}

			if (
				!window.confirm(
					`Apply this ${payload.action} decision to ${targets.length} other conflict(s) with reason "${reason}"?`
				)
			) {
				return;
			}

			try {
				await Promise.all(
					targets.map((candidate) => saveResolverDecision(candidate.original_id, payload))
				);
			} catch (err) {
				// errors handled in saveResolverDecision
			}
		},
		[resolverAttachments, saveResolverDecision]
	);

	const handleResetEntityDecisions = useCallback(async () => {
		if (!selectedId || !selectedEntityId) {
			return;
		}

		if (!window.confirm('Clear all Accept/Keep choices for this entity?')) {
			return;
		}

		setDecisionError(null);
		setResettingDecisions(true);
		setDecisionSaving({});
		try {
			const payload = await postJSON(
				`proposals/${encodeURIComponent(selectedId)}/entities/${encodeURIComponent(selectedEntityId)}/selections`,
				{ action: 'clear_all' }
			);

			const nextDecisions = payload.decisions ?? {};
			const nextSummary = payload.summary ?? null;

			setEntityDecisions(nextDecisions);
			setEntityDetail((prev) =>
				prev
					? {
						...prev,
						decisions: nextDecisions,
						decision_summary: nextSummary ?? prev.decision_summary,
				  }
					: prev
			);
			setEntities((prev) =>
				prev.map((entity) =>
					entity.vf_object_uid === selectedEntityId
						? {
							...entity,
							decision_summary: nextSummary ?? entity.decision_summary,
					  }
						: entity
				)
			);
			if (payload.proposal_summary) {
				setProposals((prev) =>
					prev.map((proposal) =>
						proposal.id === selectedId ? { ...proposal, decisions: payload.proposal_summary } : proposal
					)
				);
			}
		} catch (err) {
			setDecisionError(err.message);
		} finally {
			setResettingDecisions(false);
		}
	}, [selectedId, selectedEntityId]);

	const handleCaptureSnapshot = useCallback(async () => {
		if (!selectedId || !selectedEntityId) {
			return;
		}

		setSnapshotCapturing(true);
		setDecisionError(null);
		try {
			const payload = await postJSON(
				`proposals/${encodeURIComponent(selectedId)}/entities/${encodeURIComponent(selectedEntityId)}/snapshot`,
				{}
			);
			const snapshot = payload.snapshot ?? null;
			if (snapshot) {
				setEntityDetail((prev) =>
					prev
						? {
							...prev,
							current: snapshot,
							current_source: 'snapshot',
					  }
						: prev
				);
			}

			const refreshed = await fetchJSON(
				`proposals/${encodeURIComponent(selectedId)}/entities/${encodeURIComponent(selectedEntityId)}`
			);
			setEntityDetail(refreshed);
			setEntityDecisions(refreshed.decisions ?? {});
			setEntities((prev) =>
				prev.map((entity) =>
					entity.vf_object_uid === selectedEntityId
						? {
							...entity,
							decision_summary: refreshed.decision_summary ?? entity.decision_summary,
					  }
						: entity
				)
			);
		} catch (err) {
			setDecisionError(err.message);
		} finally {
			setSnapshotCapturing(false);
		}
	}, [selectedId, selectedEntityId]);

	const handleCaptureProposalSnapshot = useCallback(async () => {
		if (!selectedId) {
			return;
		}

		setCaptureAllSnapshotsLoading(true);
		setDecisionError(null);
		try {
			const unresolvedPayload = await fetchJSON(
				`proposals/${encodeURIComponent(selectedId)}/entities?status=needs_review`
			);
			const unresolvedItems = Array.isArray(unresolvedPayload.items) ? unresolvedPayload.items : [];
			const entityIds = unresolvedItems
				.map((item) => parseInt(item.vf_object_uid, 10))
				.filter((id) => Number.isFinite(id) && id > 0);

			const body = entityIds.length ? { entity_ids: entityIds } : {};
			const payload = await postJSON(
				`proposals/${encodeURIComponent(selectedId)}/snapshot`,
				body
			);

			const captured = payload.captured ?? 0;
			const targets = payload.targets ?? (entityIds.length || (unresolvedItems.length || 0));
			const timestamp = new Date().toISOString();
			setToasts((prev) => [
				...prev,
				{
					id: Date.now(),
					severity: 'info',
					title: 'Snapshots captured',
					message: `Captured ${captured}/${targets || captured} snapshot(s) for proposal ${selectedId}.`,
					timestamp,
				},
			]);

			await loadEntities(selectedId, entityFilter);
			await loadResolver(selectedId);

			if (selectedEntityId) {
				const refreshed = await fetchJSON(
					`proposals/${encodeURIComponent(selectedId)}/entities/${encodeURIComponent(selectedEntityId)}`
				);
				setEntityDetail(refreshed);
				setEntityDecisions(refreshed.decisions ?? {});
			}
		} catch (err) {
			setDecisionError(err.message);
		} finally {
			setCaptureAllSnapshotsLoading(false);
		}
	}, [selectedId, selectedEntityId, entityFilter, loadEntities, loadResolver]);

	const handleClearAllProposals = useCallback(async () => {
		if (!window.confirm('This will delete all stored backups, snapshots, and reviewer decisions. Continue?')) {
			return;
		}

		setClearingAll(true);
		setDecisionError(null);
		try {
			await deleteJSON('maintenance/clear-proposals');
			await reloadProposals();
			setSelectedId(null);
			setEntities([]);
			setEntityDetail(null);
			setResolver(null);
			setEntityDecisions({});
			setDecisionSaving({});
			setToasts((prev) => [
				...prev,
				{
					id: Date.now(),
					severity: 'info',
					title: 'Backups cleared',
					message: 'All proposal backups, snapshots, and decisions have been removed.',
					timestamp: new Date().toISOString(),
				},
			]);
		} catch (err) {
			setDecisionError(err.message);
		} finally {
			setClearingAll(false);
		}
	}, [reloadProposals]);

	const handleBulkDecision = useCallback(
		async (action, paths) => {
			if (!selectedId || !selectedEntityId || !Array.isArray(paths) || paths.length === 0) {
				return;
			}

			const normalizedPaths = paths.filter((path) => typeof path === 'string' && path.length > 0);
			if (!normalizedPaths.length) {
				return;
			}

			const applyPayload = (payload) => {
				const nextDecisions = payload.decisions ?? {};
				const nextSummary = payload.summary ?? null;

				setEntityDecisions(nextDecisions);
				setEntityDetail((prev) =>
					prev
						? {
							...prev,
							decisions: nextDecisions,
							decision_summary: nextSummary ?? prev.decision_summary,
					  }
						: prev
				);
				setEntities((prev) =>
					prev.map((entity) =>
						entity.vf_object_uid === selectedEntityId
							? {
								...entity,
								decision_summary: nextSummary ?? entity.decision_summary,
					  }
							: entity
					)
				);
				if (payload.proposal_summary) {
					setProposals((prev) =>
						prev.map((proposal) =>
							proposal.id === selectedId
								? { ...proposal, decisions: payload.proposal_summary }
								: proposal
						)
					);
				}
			};

			const runSequentialFallback = async () => {
				for (const path of normalizedPaths) {
					const payload = await postJSON(
						`proposals/${encodeURIComponent(selectedId)}/entities/${encodeURIComponent(selectedEntityId)}/selections`,
						{ path, action }
					);
					applyPayload(payload);
					setDecisionSaving((prev) => {
						const next = { ...prev };
						delete next[path];
						return next;
					});
				}
			};

			setDecisionError(null);
			setBulkSaving(true);
			setDecisionSaving((prev) => {
				const next = { ...prev };
				normalizedPaths.forEach((path) => {
					next[path] = true;
				});
				return next;
			});

			try {
				try {
					const payload = await postJSON(
						`proposals/${encodeURIComponent(selectedId)}/entities/${encodeURIComponent(selectedEntityId)}/selections/bulk`,
						{ action, paths: normalizedPaths }
					);
					applyPayload(payload);
					setDecisionSaving({});
				} catch (err) {
					if (err.status === 404) {
						await runSequentialFallback();
					} else {
						throw err;
					}
				}
			} catch (err) {
				setDecisionError(err.message);
			} finally {
				setBulkSaving(false);
				setDecisionSaving({});
			}
		},
		[selectedId, selectedEntityId]
	);

	const proposalDecisionSummary = selectedProposal?.decisions ?? null;
	const proposalDecisionsTotal = proposalDecisionSummary?.total ?? 0;
	const proposalDecisionsAccepted = proposalDecisionSummary?.accepted ?? 0;
	const proposalDecisionsKept = proposalDecisionSummary?.kept ?? 0;
	const proposalDecisionsReviewed = proposalDecisionSummary?.entities_reviewed ?? 0;
	const resolverDecisionSummary = selectedProposal?.resolver_decisions ?? null;
	const resolverDecisionsReuse = resolverDecisionSummary?.reuse ?? 0;
	const resolverDecisionsMap = resolverDecisionSummary?.map ?? 0;
	const resolverDecisionsDownload = resolverDecisionSummary?.download ?? 0;
	const resolverDecisionsSkip = resolverDecisionSummary?.skip ?? 0;
	const unresolvedCount = resolver?.metrics?.unresolved ?? selectedProposal?.resolver?.metrics?.unresolved ?? 0;
	const applyResultErrors = Array.isArray(applyResult?.result?.errors) ? applyResult.result.errors : [];
	const applyNoticeClass = applyResultErrors.length ? 'notice notice-warning' : 'notice notice-success';
	const applyImported = applyResult?.result?.imported ?? 0;
	const applySkipped = applyResult?.result?.skipped ?? 0;
	const applyResolverMetrics = applyResult?.result?.media_resolver?.metrics ?? {};
	const applyResolverUnresolved = applyResolverMetrics?.unresolved ?? 0;

	const handleUploadComplete = useCallback(
		(proposalId) => {
			reloadProposals({ focusProposalId: proposalId });
			const timestamp = new Date().toISOString();
			const toastId = Date.now();
			setToasts((prev) => [
				...prev,
				{
					id: toastId,
					severity: 'success',
					title: 'Proposal uploaded',
					message: `Bundle registered as ${proposalId}`,
					timestamp,
				},
			]);
		},
		[reloadProposals]
	);

	const handleUploadError = useCallback((message) => {
		const timestamp = new Date().toISOString();
		const toastId = Date.now();
		setToasts((prev) => [
			...prev,
			{
				id: toastId,
				severity: 'error',
				title: 'Upload failed',
				message,
				timestamp,
			},
		]);
	}, []);

	if (!hasLoadedProposals && loadingProposals) {
		return <p>Loading proposals…</p>;
	}

	if (!hasLoadedProposals && errorProposals) {
		return <p className="dbvc-admin-app-error">Error loading proposals: {errorProposals}</p>;
	}

		return (
			<div
				className="dbvc-admin-app"
				onClick={stopPropagation}
				onMouseDown={stopPropagation}
				onSubmit={preventOuterSubmit}
			>
			{toasts.length > 0 && (
				<div className="dbvc-toasts">
					{toasts.map((toast) => (
						<div key={toast.id} className={`dbvc-toast dbvc-toast--${toast.severity}`}>
							<div className="dbvc-toast__content">
								<strong>{toast.title}</strong>
								<span>{toast.message}</span>
								{toast.detail && <small>{toast.detail}</small>}
								<small className="dbvc-toast__time">{formatDate(toast.timestamp)}</small>
							</div>
							<button
								type="button"
								className="dbvc-toast__dismiss"
								onClick={() => dismissToast(toast.id)}
								aria-label="Dismiss notification"
							>
								×
							</button>
						</div>
					))}
				</div>
			)}
		<div className="dbvc-admin-app__header">
			<h1>DBVC Proposals</h1>
			<Button
				variant="secondary"
				onClick={() => reloadProposals()}
				disabled={loadingProposals && hasLoadedProposals}
			>
				{loadingProposals && hasLoadedProposals ? 'Refreshing…' : 'Refresh list'}
			</Button>
			<Button
				variant="tertiary"
				onClick={handleClearAllProposals}
				disabled={clearingAll}
				isBusy={clearingAll}
			>
				{clearingAll ? 'Clearing…' : 'Clear all backups'}
			</Button>
		</div>

		<ProposalUploader onUploaded={handleUploadComplete} onError={handleUploadError} />

		{errorProposals && (
			<div className="notice notice-error">
				<p>Failed to load proposals: {errorProposals}</p>
			</div>
		)}

		<ProposalList proposals={proposals} selectedId={selectedId} onSelect={setSelectedId} />
		<ResolverRulesPanel />

			{selectedProposal && (
				<section className="dbvc-admin-app__detail">
					<h2>{selectedProposal.title}</h2>
					<p>
						Generated: {formatDate(selectedProposal.generated_at)} · Files:{' '}
						{selectedProposal.files ?? '—'} · Media: {selectedProposal.media_items ?? '—'}
					</p>

					{errorResolver && (
						<div className="notice notice-error">
							<p>Resolver error: {errorResolver}</p>
						</div>
					)}
						{loadingResolver ? <p>Loading resolver metrics…</p> : <ResolverSummary resolver={resolver} />}

						<div className="dbvc-admin-app__actions">
							<button
								type="button"
								className="button button-primary"
								onClick={handleOpenApplyModal}
								disabled={applying}
							>
								{applying ? 'Applying…' : 'Apply Proposal'}
							</button>
							{proposalDecisionsTotal > 0 && (
								<div className="dbvc-decisions">
									<span className="dbvc-badge dbvc-badge--accept">{proposalDecisionsAccepted} accept</span>
									<span className="dbvc-badge dbvc-badge--keep">{proposalDecisionsKept} keep</span>
									<span className="dbvc-badge dbvc-badge--reviewed">{proposalDecisionsReviewed} reviewed</span>
							</div>
						)}
					</div>

						{applyError && (
							<div className="notice notice-error">
								<p>Apply failed: {applyError}</p>
							</div>
						)}

						{applyResult && (
							<div className={applyNoticeClass}>
								<p>
									Applied proposal "{selectedProposal?.title || selectedId}". Mode: {applyResult.mode ?? 'full'} · Imported {applyImported} · Skipped{' '}
									{applySkipped}
									{applyResult.decisions_cleared && applyResult.auto_clear_enabled
										? ' · Reviewer decisions were cleared.'
										: ''}
									{applyResolverUnresolved > 0 && ` · ${applyResolverUnresolved} resolver conflict(s) remain.`}
								</p>
								{applyResult.resolver_decisions && (
									<p className="dbvc-resolver-summary">
										Resolver decisions applied — reuse: {applyResult.resolver_decisions.reuse ?? 0}, map:{' '}
										{applyResult.resolver_decisions.map ?? 0}, download: {applyResult.resolver_decisions.download ?? 0}, skip:{' '}
										{applyResult.resolver_decisions.skip ?? 0}
									</p>
								)}
								{applyResultErrors.length > 0 && (
									<ul>
										{applyResultErrors.map((message, index) => (
											<li key={index}>{message}</li>
										))}
									</ul>
								)}
							</div>
						)}
						{resolverDecisionSummary && (
							<div className="dbvc-resolver-summary">
								<span>
									Resolver decisions — reuse: {resolverDecisionsReuse}, map: {resolverDecisionsMap}, download:{' '}
									{resolverDecisionsDownload}, skip: {resolverDecisionsSkip}
								</span>
							</div>
						)}

					{applyHistory.length > 0 && (
						<div className="dbvc-apply-history">
							<h3>Recent Apply Runs</h3>
							<ul>
								{applyHistory.map((entry) => (
									<li key={entry.id}>
										<strong>{formatDate(entry.timestamp)}</strong> — Mode {entry.mode} · Imported {entry.imported} · Skipped {entry.skipped}
										{entry.errors.length ? ` · ${entry.errors.length} error(s)` : ''}
										{entry.decisionsCleared ? ' · Selections cleared' : ''}
										{entry.ignoreMissingHash ? ' · Hash override' : ''}
										{entry.resolverDecisions
											? ` · Resolver decisions (reuse ${entry.resolverDecisions.reuse ?? 0}, map ${entry.resolverDecisions.map ?? 0}, download ${entry.resolverDecisions.download ?? 0}, skip ${entry.resolverDecisions.skip ?? 0})`
											: ''}
									</li>
								))}
							</ul>
						</div>
					)}

						{isApplyModalOpen && (
							<Modal
								title={`Apply proposal "${selectedProposal?.title || selectedId}"`}
								onRequestClose={handleCancelApply}
								isDismissible={!applying}
								shouldCloseOnClickOutside={!applying}
							>
								<div className="dbvc-apply-modal__summary">
									<p>
										This will run the import pipeline for the selected proposal using the chosen mode.
									</p>
									{proposalDecisionsTotal > 0 ? (
										<>
											<p>Reviewer selections captured:</p>
											<ul>
												<li>{proposalDecisionsAccepted} field(s) marked Accept</li>
												<li>{proposalDecisionsKept} field(s) kept as-is</li>
												<li>{proposalDecisionsReviewed} entity row(s) touched</li>
											</ul>
											<p className="description">
												Only fields marked Accept will overwrite live data. Other fields remain unchanged.
											</p>
										</>
									) : (
										<p className="dbvc-apply-modal__warning">
											No reviewer selections have been recorded. Applying will use the manifest values unchanged.
										</p>
									)}
								</div>

								<RadioControl
									label="Import mode"
									selected={applyMode}
									options={[
										{ label: 'Full import (copy entire proposal)', value: 'full' },
										{ label: 'Partial import (skip unchanged items when hashes match)', value: 'partial' },
									]}
									onChange={(value) => setApplyMode(value)}
								/>
								{applyMode === 'partial' && (
									<CheckboxControl
										label="Ignore missing import hash validation"
										checked={applyIgnoreHash}
										onChange={(value) => setApplyIgnoreHash(value)}
										help="Use only for legacy backups that predate import hash support. Recommended to resolve hash mismatches when possible."
									/>
								)}
								<p className="description">
									Selections will be cleared automatically after a successful apply if the auto-clear setting is enabled in Configure → Import.
								</p>

								<div className="dbvc-apply-modal__footer">
									<Button variant="secondary" onClick={handleCancelApply} disabled={applying}>
										Cancel
									</Button>
									<Button
										variant="primary"
										onClick={handleConfirmApply}
										isBusy={applying}
										disabled={applying}
									>
										Apply Proposal
									</Button>
								</div>
							</Modal>
						)}

			<h3>Entities</h3>
		<div className="dbvc-admin-app__filters">
			<label>
				Show:&nbsp;
				<select value={entityFilter} onChange={handleFilterChange}>
								<option value="all">{STATUS_LABELS.all}</option>
								<option value="needs_review">{STATUS_LABELS.needs_review}</option>
								<option value="resolved">{STATUS_LABELS.resolved}</option>
							</select>
						</label>
						<label>
							&nbsp;Search:&nbsp;
							<input
								type="search"
								value={entitySearch}
								onChange={handleSearchChange}
								placeholder="Title, type, path…"
							/>
			</label>
		</div>
		{selectedProposal && (
			<div className="dbvc-entity-actions">
				<Button
					variant="secondary"
					onClick={handleCaptureProposalSnapshot}
					disabled={captureAllSnapshotsLoading}
					isBusy={captureAllSnapshotsLoading}
				>
					{captureAllSnapshotsLoading ? 'Capturing snapshots…' : 'Capture Full Snapshot'}
				</Button>
				<p className="description">
					Captures current-state JSON for {unresolvedCount > 0 ? `${unresolvedCount} unresolved` : 'all'} entity(ies) so the diff shows live vs. proposed.
				</p>
			</div>
		)}
					{errorEntities && (
						<div className="notice notice-error">
							<p>Failed to load entities: {errorEntities}</p>
						</div>
					)}
					<EntityList
						entities={filteredEntities}
						loading={loadingEntities}
						selectedEntityId={selectedEntityId}
						onSelect={handleSelectEntity}
					/>

			<EntityDetailPanel
				entityDetail={entityDetail}
				resolverInfo={selectedEntity?.resolver}
				resolverDecisionSummary={selectedProposal?.resolver_decisions}
				decisions={entityDecisions}
				onDecisionChange={handleDecisionChange}
				onBulkDecision={handleBulkDecision}
				onResetDecisions={handleResetEntityDecisions}
				onCaptureSnapshot={handleCaptureSnapshot}
				onResolverDecision={handleResolverDecision}
				onResolverDecisionReset={handleResolverDecisionReset}
				onApplyResolverDecisionToSimilar={handleResolverDecisionApplySimilar}
				savingPaths={decisionSaving}
				bulkSaving={bulkSaving}
				resolverSaving={resolverSaving}
				resolverError={resolverError}
				decisionError={decisionError}
				loading={loadingEntityDetail}
				error={errorEntityDetail}
				filterMode={diffFilterMode}
				onFilterModeChange={setDiffFilterMode}
				isOpen={isEntityDetailOpen}
				onClose={handleCloseEntityDetail}
				resettingDecisions={resettingDecisions}
				snapshotCapturing={snapshotCapturing}
			/>
				</section>
			)}
		</div>
	);
};

const mount = () => {
	const root = document.getElementById('dbvc-admin-app-root');
	if (!root) {
		return;
	}

	render(<App />, root);
};

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount);
} else {
	mount();
}
const DiffSection = ({ section, decisions, onDecisionChange, savingPaths }) => {
	const [expanded, setExpanded] = useState(true);

	return (
		<div className="dbvc-admin-app__diff-section" id={`dbvc-diff-${section.key}`}>
			<button
				type="button"
				className="dbvc-admin-app__diff-toggle"
				onClick={() => setExpanded((prev) => !prev)}
				aria-expanded={expanded}
			>
				<h4>{section.label}</h4>
				<span>{expanded ? 'Collapse' : 'Expand'}</span>
			</button>
			{expanded ? (
				<table className="widefat dbvc-admin-app__diff-table">
					<thead>
						<tr>
							<th style={{ width: '25%' }}>Field</th>
							<th>Current</th>
							<th>Proposed</th>
							<th style={{ width: '20%' }}>Decision</th>
						</tr>
					</thead>
					<tbody>
		{section.items.map((change) => {
			const highlight = computeHighlightSegments(change.from, change.to);
			const decision = decisions?.[change.path] ?? '';
			const isSaving = !!savingPaths[change.path];
			return (
								<tr key={change.path}>
						<td>
							<div className="dbvc-field-label">
								{change.label || change.path}
								{change.path && (
									<div className="dbvc-field-label__key">{change.path}</div>
								)}
							</div>
						</td>
									<td>
										{highlight && highlight.old.diff
											? renderHighlightedSegments(highlight.old)
											: formatDiffValue(change.from)}
									</td>
									<td>
										{highlight && highlight.new.diff
											? renderHighlightedSegments(highlight.new)
											: formatDiffValue(change.to)}
									</td>
				<td>
					<div className="dbvc-decision-controls">
						<label>
							<input
								type="radio"
								name={`decision-${change.path}`}
								value="keep"
								checked={decision === 'keep'}
								disabled={isSaving}
								onChange={() => onDecisionChange && onDecisionChange(change.path, 'keep')}
							/>
							Keep
						</label>
						<label>
							<input
								type="radio"
								name={`decision-${change.path}`}
								value="accept"
								checked={decision === 'accept'}
								disabled={isSaving}
								onChange={() => onDecisionChange && onDecisionChange(change.path, 'accept')}
							/>
							Accept
						</label>
					<Button
						variant="link"
						className="dbvc-decision-clear"
						onClick={() => onDecisionChange && onDecisionChange(change.path, 'clear')}
						disabled={isSaving || !decision}
					>
						Clear decision
					</Button>
					{!decision && <span className="dbvc-decision-status">Not reviewed</span>}
						{isSaving && <span className="saving">Saving…</span>}
					</div>
				</td>
			</tr>
			);
		})}
					</tbody>
				</table>
			) : (
				<div className="dbvc-admin-app__no-diff">Collapsed ({section.items.length} changes).</div>
			)}
		</div>
	);
};

const ResolverDecisionRow = ({ attachment, saving, onSave, onClear, onApplyToSimilar }) => {
	const originalId = attachment.original_id;
	const [action, setAction] = useState(attachment.decision?.action || '');
	const [targetId, setTargetId] = useState(
		attachment.decision?.target_id ? String(attachment.decision.target_id) : ''
	);
	const [note, setNote] = useState(attachment.decision?.note || '');
	const [persistGlobal, setPersistGlobal] = useState(attachment.decision?.scope === 'global');

	useEffect(() => {
		setAction(attachment.decision?.action || '');
		setTargetId(attachment.decision?.target_id ? String(attachment.decision.target_id) : '');
		setNote(attachment.decision?.note || '');
		setPersistGlobal(attachment.decision?.scope === 'global');
	}, [attachment.decision]);

	const needsTarget = action === 'reuse' || action === 'map';
	const parsedTarget = parseInt(targetId, 10);
	const canSave = action && (!needsTarget || parsedTarget > 0);

	return (
		<tr>
			<td>{originalId}</td>
			<td>{renderStatusBadge(attachment.status || 'unknown')}</td>
			<td>
				<div className="dbvc-resolver-preview">
					<div className="dbvc-resolver-preview__item">
						<span className="dbvc-resolver-preview__label">Proposed</span>
						{attachment.preview?.proposed ? (
							<img
								src={attachment.preview.proposed}
								alt=""
								className="dbvc-resolver-preview__image"
								loading="lazy"
							/>
						) : (
							<span className="dbvc-resolver-preview__placeholder">—</span>
						)}
					</div>
					<div className="dbvc-resolver-preview__item">
						<span className="dbvc-resolver-preview__label">Current</span>
						{attachment.preview?.local ? (
							<img
								src={attachment.preview.local}
								alt=""
								className="dbvc-resolver-preview__image"
								loading="lazy"
							/>
						) : (
							<span className="dbvc-resolver-preview__placeholder">—</span>
						)}
					</div>
				</div>
			</td>
			<td>{attachment.target_id ?? '—'}</td>
			<td>{attachment.reason ?? '—'}</td>
			<td>
				<div className="dbvc-resolver-controls">
					<select value={action} onChange={(event) => setAction(event.target.value)}>
						<option value="">Select…</option>
						<option value="reuse">Reuse existing</option>
						<option value="download">Download new</option>
						<option value="map">Map to attachment ID</option>
						<option value="skip">Skip</option>
					</select>
					{needsTarget && (
						<input
							type="number"
							min="1"
							placeholder="Attachment ID"
							value={targetId}
							onChange={(event) => setTargetId(event.target.value)}
						/>
					)}
					<textarea
						value={note}
						onChange={(event) => setNote(event.target.value)}
						placeholder="Optional note"
						rows={2}
					/>
					<label>
						<input
							type="checkbox"
							checked={persistGlobal}
							onChange={(event) => setPersistGlobal(event.target.checked)}
						/>{' '}
						Remember for future proposals
					</label>
				</div>
			</td>
			<td>
				<button
					type="button"
					className="button button-primary"
					disabled={!canSave || saving}
					onClick={() =>
						onSave &&
						onSave(originalId, {
							action,
							target_id: needsTarget ? parsedTarget : null,
							note,
							persist_global: persistGlobal,
						})
					}
				>
					{saving ? 'Saving…' : 'Save'}
				</button>
				{attachment.decision && (
					<button
						type="button"
						className="button-link-delete"
						onClick={() =>
							onClear && onClear(originalId, attachment.decision.scope === 'global' ? 'global' : 'proposal')
						}
						disabled={saving}
					>
						Clear
					</button>
				)}
				{attachment.decision && attachment.reason && onApplyToSimilar && (
					<button
						type="button"
						className="button-link"
						onClick={() =>
							onApplyToSimilar(attachment, {
								action: attachment.decision?.action,
								target_id: attachment.decision?.target_id ?? null,
								note: attachment.decision?.note ?? '',
								persist_global: attachment.decision?.scope === 'global',
							})
						}
						disabled={saving}
					>
						Apply to similar
					</button>
				)}
			</td>
		</tr>
	);
};

const parseResolverRulesCsv = (text) => {
	const rows = text
		.replace(/\r\n/g, '\n')
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line.length > 0)
		.map((line) => splitCsvLine(line));

	if (!rows.length) {
		return [];
	}

	let headers = rows[0].map((cell) => cell.trim().toLowerCase());
	let dataRows = rows.slice(1);
	if (!headers.includes('original_id')) {
		headers = ['original_id', 'action', 'target_id', 'note'];
		dataRows = rows;
	}

	const allowed = new Set(['original_id', 'action', 'target_id', 'note']);
	const sanitizedHeaders = headers.map((header, index) => (allowed.has(header) ? header : `col_${index}`));

	return dataRows
		.map((cells) => {
			const record = {};
			sanitizedHeaders.forEach((header, index) => {
				record[header] = cells[index] ?? '';
			});
			return record;
		})
		.map((record) => {
			const originalId = parseInt(record.original_id, 10);
			const targetId = parseInt(record.target_id, 10);
			return {
				original_id: Number.isFinite(originalId) ? originalId : null,
				action: (record.action || '').toLowerCase(),
				target_id: Number.isFinite(targetId) ? targetId : null,
				note: record.note || '',
			};
		})
		.filter((record) => record.original_id && record.action);
};

const splitCsvLine = (line) => {
	const cells = [];
	let current = '';
	let inQuotes = false;

	for (let i = 0; i < line.length; i++) {
		const char = line[i];
		if (char === '"') {
			if (inQuotes && line[i + 1] === '"') {
				current += '"';
				i++;
			} else {
				inQuotes = !inQuotes;
			}
		} else if (char === ',' && !inQuotes) {
			cells.push(current);
			current = '';
		} else {
			current += char;
		}
	}
	cells.push(current);
	return cells;
};
const ResolverRulesPanel = () => {
	const [rules, setRules] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [refreshKey, setRefreshKey] = useState(0);
	const [selected, setSelected] = useState({});
	const [selectAll, setSelectAll] = useState(false);
	const [ruleFormVisible, setRuleFormVisible] = useState(false);
	const [editingRule, setEditingRule] = useState(null);
	const [ruleForm, setRuleForm] = useState({ original_id: '', action: '', target_id: '', note: '' });
	const [ruleFormError, setRuleFormError] = useState('');
	const [ruleFormSaving, setRuleFormSaving] = useState(false);
	const [importing, setImporting] = useState(false);
	const [importResult, setImportResult] = useState({ imported: 0, errors: [] });
	const fileInputRef = useRef(null);
	const [ruleSearch, setRuleSearch] = useState('');
	const lastTargetIdRef = useRef('');
	const filteredRules = useMemo(() => {
		if (!ruleSearch) {
			return rules;
		}
		const term = ruleSearch.toLowerCase();
			return rules.filter((rule) => {
				return [rule.original_id, rule.action, rule.note, rule.target_id, rule.saved_at]
					.filter(Boolean)
					.map((value) => String(value).toLowerCase())
					.some((value) => value.includes(term));
		});
	}, [ruleSearch, rules]);

	const hasRules = rules.length > 0;
	const hasFilteredRules = filteredRules.length > 0;
	const hasSelected = Object.values(selected).some(Boolean);

	const duplicateOriginalId =
		!editingRule &&
		ruleForm.original_id &&
		rules.some((rule) => String(rule.original_id) === String(ruleForm.original_id));
	const duplicateTargetId =
		(ruleForm.action === 'reuse' || ruleForm.action === 'map') &&
		ruleForm.target_id &&
		rules.some(
			(rule) =>
				String(rule.original_id) !== String(ruleForm.original_id) &&
				rule.target_id &&
				Number(rule.target_id) === Number(ruleForm.target_id)
		);

	useEffect(() => {
		if (!lastTargetIdRef.current) {
			const seeded = rules.find((rule) => rule.target_id);
			if (seeded) {
				lastTargetIdRef.current = String(seeded.target_id);
			}
		}
	}, [rules]);

	useEffect(() => {
		let isMounted = true;
		const load = async () => {
			setLoading(true);
			setError(null);
			try {
				const payload = await fetchJSON('resolver-rules');
				if (isMounted) {
					setRules(payload.rules ?? []);
				}
			} catch (err) {
				if (isMounted) {
					setError(err.message);
				}
			} finally {
				if (isMounted) {
					setLoading(false);
				}
			}
		};
		load();
		return () => {
			isMounted = false;
		};
	}, [refreshKey]);

	const handleDelete = async (originalId) => {
		if (!window.confirm(`Delete global rule for original ID ${originalId}?`)) {
			return;
		}
		try {
			await deleteJSON(`resolver-rules/${encodeURIComponent(originalId)}`);
			setRefreshKey((key) => key + 1);
		} catch (err) {
			window.alert(err.message);
		}
	};

	const handleBulkDelete = async () => {
		const ids = Object.entries(selected)
			.filter(([, checked]) => checked)
			.map(([originalId]) => originalId);
		if (!ids.length) {
			window.alert('Select at least one rule to delete.');
		 return;
		}
		if (!window.confirm(`Delete ${ids.length} selected rule(s)?`)) {
			return;
		}
		try {
			await postJSON('resolver-rules/bulk-delete', { original_ids: ids });
			setSelected({});
			setSelectAll(false);
			setRefreshKey((key) => key + 1);
		} catch (err) {
			window.alert(err.message);
		}
	};

	const toggleSelectAll = () => {
		const next = !selectAll;
		setSelectAll(next);
		if (next) {
			const map = {};
			rules.forEach((rule) => {
				map[rule.original_id] = true;
			});
			setSelected(map);
		} else {
			setSelected({});
		}
	};

	const toggleSelect = (originalId) => {
		setSelected((prev) => ({
			...prev,
			[originalId]: !prev[originalId],
		}));
	};

	const openRuleForm = (rule = null) => {
		if (rule) {
			setEditingRule(rule);
			setRuleForm({
				original_id: String(rule.original_id ?? ''),
				action: rule.action ?? '',
				target_id: rule.target_id ? String(rule.target_id) : '',
				note: rule.note ?? '',
			});
		} else {
			setEditingRule(null);
			setRuleForm({
				original_id: '',
				action: '',
				target_id: lastTargetIdRef.current || '',
				note: '',
			});
		}
		setRuleFormError('');
		setRuleFormVisible(true);
	};

	const closeRuleForm = () => {
		setRuleFormVisible(false);
		setRuleFormError('');
		setEditingRule(null);
	};

	const handleRuleFormChange = (field, value) => {
		setRuleForm((prev) => {
			const next = { ...prev, [field]: value };
			if (field === 'action') {
				const needsTarget = value === 'reuse' || value === 'map';
				if (!needsTarget) {
					next.target_id = '';
				} else if (!next.target_id && lastTargetIdRef.current) {
					next.target_id = lastTargetIdRef.current;
				}
			}
			return next;
		});
	};

	const handleRuleFormSubmit = async (event) => {
		event.preventDefault();
		setRuleFormError('');
		const originalId = parseInt(ruleForm.original_id, 10);
		if (!Number.isFinite(originalId) || originalId <= 0) {
			setRuleFormError('Enter a valid original media ID.');
			return;
		}
		if (!editingRule && duplicateOriginalId) {
			setRuleFormError('A rule already exists for that original media ID. Choose Edit instead.');
			return;
		}
		if (!ruleForm.action) {
			setRuleFormError('Select an action for this rule.');
			return;
		}
		const needsTarget = ruleForm.action === 'reuse' || ruleForm.action === 'map';
		const targetId = parseInt(ruleForm.target_id, 10);
		if (needsTarget && (!Number.isFinite(targetId) || targetId <= 0)) {
			setRuleFormError('Enter a target attachment ID for reuse/map actions.');
			return;
		}
		if (needsTarget && duplicateTargetId) {
			setRuleFormError('This attachment ID is already mapped to another rule.');
			return;
		}

		setRuleFormSaving(true);
		try {
			await postJSON('resolver-rules', {
				original_id: originalId,
				action: ruleForm.action,
				target_id: needsTarget ? targetId : null,
				note: ruleForm.note,
			});
			setRefreshKey((key) => key + 1);
			if (needsTarget && Number.isFinite(targetId) && targetId > 0) {
				lastTargetIdRef.current = String(targetId);
			}
			closeRuleForm();
		} catch (err) {
			setRuleFormError(err?.message || 'Failed to save rule.');
		} finally {
			setRuleFormSaving(false);
		}
	};

	const handleImportClick = () => {
		if (fileInputRef.current) {
			fileInputRef.current.click();
		}
	};

	const handleImportFile = async (event) => {
		const file = event.target.files && event.target.files[0];
		if (!file) {
			return;
		}
		await runCsvImport(file);
		event.target.value = '';
	};

	const runCsvImport = async (file) => {
		setImporting(true);
		setImportResult({ imported: 0, errors: [] });
		try {
			const text = await file.text();
			const parsed = parseResolverRulesCsv(text);
			if (!parsed.length) {
				setImportResult({ imported: 0, errors: ['The CSV file does not contain any valid rows.'] });
				return;
			}
			const response = await postJSON('resolver-rules/import', { rules: parsed });
			const importedCount = response.imported ? response.imported.length : 0;
			setImportResult({ imported: importedCount, errors: response.errors ?? [] });
			setRefreshKey((key) => key + 1);
		} catch (err) {
			setImportResult({ imported: 0, errors: [err?.message || 'Import failed.'] });
		} finally {
			setImporting(false);
		}
	};


	return (
		<section className="dbvc-resolver-rules">
			<h2>Global Resolver Rules</h2>
			<p className="description">
				Decisions saved with “remember for future proposals” appear here. They apply automatically whenever a matching original media ID is detected in
				any proposal.
			</p>
			{loading && <p>Loading resolver rules…</p>}
			{error && (
				<div className="notice notice-error">
					<p>{error}</p>
				</div>
			)}
			{!loading && !hasRules && <p>No global rules saved yet.</p>}
			{hasRules && (
				<div className="dbvc-panel-search">
					<label>
						Search:&nbsp;
						<input
							type="search"
							value={ruleSearch}
							onChange={(event) => setRuleSearch(event.target.value)}
							placeholder="ID, action, note…"
						/>
					</label>
				</div>
			)}
			{!loading && hasRules && !hasFilteredRules && <p>No rules match “{ruleSearch}”.</p>}
			{!loading && hasFilteredRules && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>
								<input type="checkbox" checked={selectAll} onChange={toggleSelectAll} />
							</th>
							<th>Original ID</th>
							<th>Action</th>
							<th>Target</th>
							<th>Note</th>
							<th>Saved</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{filteredRules.map((rule) => (
							<tr key={rule.original_id}>
									<td>
										<input
											type="checkbox"
											checked={!!selected[rule.original_id]}
											onChange={() => toggleSelect(rule.original_id)}
										/>
									</td>
									<td>{rule.original_id}</td>
									<td>{rule.action}</td>
									<td>{rule.target_id ?? '—'}</td>
									<td>{rule.note ?? '—'}</td>
									<td>{formatDate(rule.saved_at)}</td>
									<td className="dbvc-resolver-rules__actions">
										<button type="button" className="button-link" onClick={() => openRuleForm(rule)}>
											Edit
										</button>
										<button type="button" className="button-link-delete" onClick={() => handleDelete(rule.original_id)}>
											Delete
										</button>
									</td>
								</tr>
							))}
						</tbody>
					</table>
				)}
				<div className="dbvc-resolver-rules__bulk">
					<button type="button" className="button button-primary" onClick={() => openRuleForm(null)}>
						Add Rule
					</button>
					<button type="button" className="button" onClick={handleImportClick} disabled={importing}>
						{importing ? 'Importing…' : 'Import CSV'}
					</button>
					<button type="button" className="button button-secondary" onClick={handleBulkDelete} disabled={!Object.values(selected).some(Boolean)}>
						Delete Selected
					</button>
					<button
						type="button"
						className="button button-link"
						onClick={() => {
							const csv = ['original_id,action,target_id,note,saved_at']
								.concat(
									rules.map((rule) =>
										[
											rule.original_id,
											rule.action,
											rule.target_id ?? '',
											(rule.note || '').replace(/\"/g, '\"\"'),
											rule.saved_at,
										].join(',')
									)
								)
								.join('\n');
							const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
							const url = URL.createObjectURL(blob);
							const anchor = document.createElement('a');
							anchor.href = url;
							anchor.download = 'dbvc-resolver-rules.csv';
							document.body.appendChild(anchor);
							anchor.click();
							document.body.removeChild(anchor);
							URL.revokeObjectURL(url);
						}}
					>
						Export CSV
					</button>
					<input
						ref={fileInputRef}
						type="file"
						accept=".csv,text/csv"
						style={{ display: 'none' }}
						onChange={handleImportFile}
					/>
				</div>
				{ruleFormVisible && (
					<form className="dbvc-resolver-rule-form" onSubmit={handleRuleFormSubmit}>
						<h3>{editingRule ? 'Edit global rule' : 'Add global rule'}</h3>
						<div className="dbvc-resolver-controls">
							<input
								type="number"
								min="1"
								placeholder="Original ID"
								value={ruleForm.original_id}
								onChange={(event) => handleRuleFormChange('original_id', event.target.value)}
								readOnly={!!editingRule}
							/>
							{duplicateOriginalId && (
								<span className="dbvc-field-hint is-warning">Rule already exists for this media ID.</span>
							)}
							<select value={ruleForm.action} onChange={(event) => handleRuleFormChange('action', event.target.value)}>
								<option value="">Select action…</option>
								<option value="reuse">Reuse existing</option>
								<option value="download">Download new</option>
								<option value="map">Map to attachment ID</option>
								<option value="skip">Skip</option>
							</select>
							{(ruleForm.action === 'reuse' || ruleForm.action === 'map') && (
								<input
									type="number"
									min="1"
									placeholder="Target attachment ID"
									value={ruleForm.target_id}
									onChange={(event) => handleRuleFormChange('target_id', event.target.value)}
								/>
							)}
							{duplicateTargetId && (
								<span className="dbvc-field-hint is-warning">This attachment ID is already used by another rule.</span>
							)}
							<textarea
								value={ruleForm.note}
								onChange={(event) => handleRuleFormChange('note', event.target.value)}
								placeholder="Optional note"
								rows={2}
							/>
						</div>
						{ruleFormError && (
							<div className="notice notice-error">
								<p>{ruleFormError}</p>
							</div>
						)}
						<div className="dbvc-resolver-rule-form__actions">
							<button type="button" className="button button-link" onClick={closeRuleForm}>
								Cancel
							</button>
							<button type="submit" className="button button-primary" disabled={ruleFormSaving}>
								{ruleFormSaving ? 'Saving…' : editingRule ? 'Update Rule' : 'Save Rule'}
							</button>
						</div>
					</form>
				)}
				{(importResult.imported > 0 || importResult.errors.length > 0) && (
					<div className="dbvc-resolver-import">
						{importResult.imported > 0 && (
							<div className="notice notice-success">
								<p>{importResult.imported} rule(s) imported successfully.</p>
							</div>
						)}
						{importResult.errors.length > 0 && (
							<div className="notice notice-warning">
								<p>Import completed with warnings:</p>
								<ul>
									{importResult.errors.map((message, index) => (
										<li key={index}>{message}</li>
									))}
								</ul>
							</div>
						)}
					</div>
				)}
		</section>
	);
};
