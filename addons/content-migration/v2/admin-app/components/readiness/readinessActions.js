export const READINESS_FILTERS = [
	{ key: 'all', label: 'All readiness', countKey: 'all' },
	{ key: 'review', label: 'Review work', countKey: 'review' },
	{ key: 'qa', label: 'QA blockers', countKey: 'qa' },
	{ key: 'package', label: 'Package blockers', countKey: 'package' },
	{ key: 'ready', label: 'Ready', countKey: 'ready' },
];

const REVIEW_CODES = new Set( [
	'target_conflicts',
	'unresolved_items',
	'field_context_ambiguous_recommendations',
	'stale_decisions',
	'missing_target_object',
	'blocked_resolution',
	'manual_review_pending',
	'manual_overrides_present',
] );

const QA_CODES = new Set( [
	'missing_page_context',
	'missing_mapping_recommendations',
	'missing_target_transform',
	'field_context_provider_missing',
	'field_context_provider_degraded',
	'field_context_provider_warnings',
	'schema_fingerprint_missing',
	'rerun_history_present',
] );

const PACKAGE_CODES = new Set( [ 'empty_package_record' ] );
PACKAGE_CODES.add( 'benchmark_release_gate_blocked' );
PACKAGE_CODES.add( 'benchmark_release_gate_warning' );

const PRIMARY_CODE_ORDER = [
	'target_conflicts',
	'unresolved_items',
	'field_context_ambiguous_recommendations',
	'stale_decisions',
	'manual_review_pending',
	'manual_overrides_present',
	'missing_target_object',
	'blocked_resolution',
	'benchmark_release_gate_blocked',
	'benchmark_release_gate_warning',
	'empty_package_record',
	'field_context_provider_missing',
	'field_context_provider_degraded',
	'field_context_provider_warnings',
	'missing_mapping_recommendations',
	'missing_target_transform',
	'missing_page_context',
	'rerun_history_present',
	'schema_fingerprint_missing',
];

const normalizeCode = ( value ) =>
	typeof value === 'string' ? value.trim() : '';

const getIssueCodes = ( items ) =>
	Array.isArray( items )
		? items.map( ( item ) => normalizeCode( item?.code ) ).filter( Boolean )
		: [];

const getPageCodes = ( item ) => [
	...getIssueCodes( item?.blockingIssues ),
	...getIssueCodes( item?.warnings ),
];

const makeExceptionsAction = ( label, filter, panelTab ) => ( {
	label,
	target: 'exceptions',
	filter,
	panelTab,
} );

const makeReadinessAction = ( label, panelTab = 'audit' ) => ( {
	label,
	target: 'readiness',
	panelTab,
} );

const makePackageAction = ( label ) => ( {
	label,
	target: 'package',
} );

export const resolveReadinessIssueAction = ( item = {} ) => {
	switch ( normalizeCode( item.code ) ) {
		case 'target_conflicts':
			return makeExceptionsAction(
				'Resolve conflicts',
				'conflicts',
				'conflicts'
			);
		case 'unresolved_items':
			return makeExceptionsAction(
				'Review unresolved',
				'unresolved',
				'mapping'
			);
		case 'field_context_ambiguous_recommendations':
			return makeExceptionsAction(
				'Review ambiguous mappings',
				'review',
				'mapping'
			);
		case 'stale_decisions':
			return makeExceptionsAction( 'Review stale', 'stale', 'mapping' );
		case 'manual_review_pending':
			return makeExceptionsAction( 'Review mapping', 'all', 'mapping' );
		case 'manual_overrides_present':
			return makeExceptionsAction(
				'Review overrides',
				'overridden',
				'mapping'
			);
		case 'missing_target_object':
			return makeExceptionsAction( 'Select target', 'all', 'mapping' );
		case 'blocked_resolution':
			return makeExceptionsAction(
				'Review resolution',
				'blocked',
				'mapping'
			);
		case 'benchmark_release_gate_blocked':
		case 'benchmark_release_gate_warning':
			return makeReadinessAction( 'Inspect benchmark gate' );
		case 'empty_package_record':
			return makeReadinessAction( 'Inspect package blocker' );
		case 'missing_mapping_recommendations':
		case 'missing_target_transform':
		case 'missing_page_context':
			return makeReadinessAction( 'Inspect QA blocker' );
		case 'field_context_provider_missing':
		case 'field_context_provider_degraded':
		case 'field_context_provider_warnings':
			return makeReadinessAction( 'Inspect Field Context audit' );
		case 'rerun_history_present':
			return makeReadinessAction( 'Inspect rerun history' );
		case 'schema_fingerprint_missing':
			return makeReadinessAction( 'Inspect schema warning' );
		default:
			return item.pageId ? makeReadinessAction( 'Inspect QA' ) : null;
	}
};

export const resolveReadinessPageFlags = ( item = {} ) => {
	const codes = getPageCodes( item );

	return {
		review:
			item.readinessStatus === 'needs_review' ||
			codes.some( ( code ) => REVIEW_CODES.has( code ) ),
		qa: codes.some( ( code ) => QA_CODES.has( code ) ),
		package: codes.some( ( code ) => PACKAGE_CODES.has( code ) ),
		ready: item.readinessStatus === 'ready_for_import',
	};
};

export const resolveReadinessPageAction = ( item = {} ) => {
	const codes = getPageCodes( item );

	for ( const code of PRIMARY_CODE_ORDER ) {
		if ( codes.includes( code ) ) {
			return resolveReadinessIssueAction( {
				...item,
				code,
			} );
		}
	}

	if ( item.readinessStatus === 'ready_for_import' ) {
		return makePackageAction( 'Open package workspace' );
	}

	return makeReadinessAction( 'Inspect QA' );
};

export const matchesReadinessFilter = ( item, filter = 'all' ) => {
	if ( ! filter || filter === 'all' ) {
		return true;
	}

	const flags = resolveReadinessPageFlags( item );

	if ( filter === 'review' ) {
		return flags.review;
	}

	if ( filter === 'qa' ) {
		return flags.qa;
	}

	if ( filter === 'package' ) {
		return flags.package;
	}

	if ( filter === 'ready' ) {
		return flags.ready;
	}

	return true;
};

export const buildReadinessFilterCounts = ( items ) =>
	( Array.isArray( items ) ? items : [] ).reduce(
		( counts, item ) => {
			const flags = resolveReadinessPageFlags( item );

			counts.all += 1;
			if ( flags.review ) {
				counts.review += 1;
			}
			if ( flags.qa ) {
				counts.qa += 1;
			}
			if ( flags.package ) {
				counts.package += 1;
			}
			if ( flags.ready ) {
				counts.ready += 1;
			}

			return counts;
		},
		{
			all: 0,
			review: 0,
			qa: 0,
			package: 0,
			ready: 0,
		}
	);
