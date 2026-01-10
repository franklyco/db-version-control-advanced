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

const POST_FIELD_KEYS = new Set([
    'post_title',
    'post_name',
    'post_status',
    'post_type',
    'post_excerpt',
    'post_parent',
    'post_author',
    'post_date',
    'post_date_gmt',
    'post_modified',
    'post_modified_gmt',
    'post_password',
    'post_mime_type',
    'post_content_filtered',
    'menu_order',
    'comment_status',
    'ping_status',
    'guid',
    'comment_count',
]);

const SECTION_LABELS = {
    meta: 'Meta',
    tax: 'Taxonomies',
    media: 'Media References',
    content: 'Content',
    post_fields: 'Post Fields',
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
        const sectionKey = POST_FIELD_KEYS.has(root) ? 'post_fields' : root;
        const key = sectionKey || 'other';
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

const NEW_ENTITY_DECISION_PATH = '__dbvc_new_entity__';
const STATUS_LABELS = {
    all: 'All entities',
    needs_review: 'Needs Review',
    needs_review_media: 'Needs Review (Media)',
    resolved: 'Resolved',
    reused: 'Resolved',
    conflict: 'Conflict',
    needs_download: 'Needs Download',
    missing: 'Missing',
    unknown: 'Unknown',
    new_entities: 'New posts',
    with_decisions: 'With selections',
};

const renderStatusBadge = (status) => {
    const label = STATUS_LABELS[status] || status;
    return <span className={`dbvc-badge dbvc-badge--${status}`}>{label}</span>;
};

const ENTITY_COLUMN_DEFS = [
    {
        id: 'title',
        label: 'Title',
        defaultVisible: true,
        lockVisible: true,
        renderCell: (entity) => entity.post_title || entity.vf_object_uid,
    },
    {
        id: 'post_name',
        label: 'Slug',
        defaultVisible: true,
        renderCell: (entity) => (
            <span className="dbvc-text-break">{entity.post_name || '—'}</span>
        ),
    },
    {
        id: 'post_type',
        label: 'Type',
        defaultVisible: true,
        renderCell: (entity) => entity.post_type || '—',
    },
    {
        id: 'post_status',
        label: 'Status',
        defaultVisible: true,
        renderCell: (entity) => entity.post_status || '—',
    },
    {
        id: 'post_modified',
        label: 'Last modified',
        defaultVisible: true,
        renderCell: (entity) => (entity.post_modified ? formatDate(entity.post_modified) : '—'),
    },
    {
        id: 'content_hash',
        label: 'Content hash',
        defaultVisible: false,
        renderCell: (entity) => (
            <span className="dbvc-text-break">{entity.content_hash ?? '—'}</span>
        ),
    },
    {
        id: 'diff',
        label: 'Diff',
        defaultVisible: true,
        renderCell: (entity, helpers) => (
            <>
                {renderStatusBadge(helpers.diffState.needs_review ? 'needs_review' : 'resolved')}
                {helpers.hashMissing && (
                    <span className="dbvc-badge dbvc-badge--missing" style={{ marginLeft: '0.25rem' }}>
                        Hash missing
                    </span>
                )}
            </>
        ),
    },
    {
        id: 'media_refs',
        label: 'Media refs',
        defaultVisible: true,
        renderCell: (entity) =>
            (entity.media_refs?.meta?.length ?? 0) + (entity.media_refs?.content?.length ?? 0),
    },
    {
        id: 'resolver',
        label: 'Resolver',
        defaultVisible: true,
        renderCell: (entity, helpers) => {
            const isNew = helpers.isNewEntity;
            const decision = helpers.newDecision || '';
            let newLabel = 'New post';
            if (decision === 'accept_new') {
                newLabel = 'New post accepted';
            } else if (decision === 'decline_new') {
                newLabel = 'New post declined';
            }
            return (
                <>
                    {renderStatusBadge(helpers.mediaStatus)}
                    {isNew && (
                        <span className="dbvc-badge dbvc-badge--new" style={{ marginLeft: '0.25rem' }}>
                            {newLabel}
                        </span>
                    )}
                </>
            );
        },
    },
    {
        id: 'unresolved_media',
        label: 'Unresolved media',
        defaultVisible: true,
        renderCell: (entity, helpers) => helpers.summary.unresolved ?? 0,
    },
    {
        id: 'meta_diff_count',
        label: 'Unresolved meta',
        defaultVisible: true,
        renderCell: (entity) => entity.meta_diff_count ?? 0,
    },
    {
        id: 'conflicts',
        label: 'Conflicts',
        defaultVisible: true,
        renderCell: (entity, helpers) => helpers.summary.conflicts ?? 0,
    },
    {
        id: 'decisions',
        label: 'Decisions',
        defaultVisible: true,
        renderCell: (entity, helpers) =>
            helpers.entityHasSelections ? (
                <div className="dbvc-decisions">
                    {helpers.entityAccepted > 0 && (
                        <span className="dbvc-badge dbvc-badge--accept">{helpers.entityAccepted} accept</span>
                    )}
                    {helpers.entityKept > 0 && (
                        <span className="dbvc-badge dbvc-badge--keep">{helpers.entityKept} keep</span>
                    )}
                </div>
            ) : (
                '—'
            ),
    },
];

const ProposalList = ({ proposals, selectedId, onSelect }) => {
    if (!proposals.length) {
        return <p>No proposals found. Generate an export to get started.</p>;
    }

    return (
        <>
        <p className="dbvc-entities-tip">
            New posts that were previously accepted can be auto-restored when reopening a proposal. Enable “Auto-mark accepted new posts on reopen” under Configure → Import to keep their media/meta selections intact.
        </p>
        <table className="widefat fixed striped dbvc-proposal-table">
            <thead>
                <tr>
                    <th>Proposal</th>
                    <th>Generated</th>
                    <th>Files</th>
                    <th>Media</th>
                    <th>Status</th>
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

                    const status = proposal.status === 'closed' ? 'Closed' : 'Open';
                    return (
                        <tr
                            key={proposal.id}
                            className={`${isActive ? 'is-active' : ''} ${proposal.status === 'closed' ? 'is-archived' : ''}`}
                            onClick={() => onSelect(proposal.id)}
                            style={{ cursor: 'pointer' }}
                        >
                            <td>{proposal.title}</td>
                            <td>{formatDate(proposal.generated_at)}</td>
                            <td>{proposal.files ?? '—'}</td>
                            <td>{proposal.media_items ?? '—'}</td>
                            <td>
                                <span className={`dbvc-status-badge dbvc-status-badge--${proposal.status ?? 'draft'}`}>
                                    {status}
                                </span>
                            </td>
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
        </>
    );
};

const ProposalUploader = ({ onUploaded, onError }) => {
    const inputRef = useRef(null);
 Etc.
