const ACTIVE_RUN_STATUSES = [
	'queued',
	'started',
	'running',
	'processing',
	'active',
];

const STAGE_ORDER = [
	'domain_journey_started',
	'target_object_inventory_built',
	'target_schema_catalog_built',
	'target_schema_sync_completed',
	'url_discovered',
	'url_scope_decided',
	'url_discovery_completed',
	'page_capture_completed',
	'source_normalization_completed',
	'structured_extraction_completed',
	'context_creation_completed',
	'initial_classification_completed',
	'mapping_index_completed',
	'target_transform_completed',
	'recommended_mappings_finalized',
	'pattern_memory_updated',
	'review_presented',
	'review_decision_saved',
	'manual_override_saved',
	'qa_validation_completed',
	'package_validation_completed',
	'package_ready',
	'package_built',
	'stage_rerun_requested',
	'stage_rerun_completed',
];

const getArrayCount = ( value ) =>
	Array.isArray( value ) ? value.length : 0;

export const formatTimestamp = ( value ) => {
	if ( ! value ) {
		return 'Unknown';
	}

	const date = new Date( value );
	if ( Number.isNaN( date.getTime() ) ) {
		return 'Unknown';
	}

	return date.toLocaleString( undefined, {
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
		second: '2-digit',
	} );
};

export const formatDuration = ( elapsedMs ) => {
	if ( typeof elapsedMs !== 'number' || elapsedMs <= 0 ) {
		return '0s';
	}

	const seconds = elapsedMs / 1000;
	return `${ seconds < 10 ? seconds.toFixed( 1 ) : seconds.toFixed( 0 ) }s`;
};

export const humanizeKey = ( key ) =>
	`${ key || '' }`
		.replace( /_/g, ' ' )
		.replace( /\b\w/g, ( letter ) => letter.toUpperCase() )
		.trim() || 'Unknown';

export const getStatusClassName = ( status ) => {
	const normalizedStatus = `${ status || '' }`.trim();

	if (
		normalizedStatus === 'completed' ||
		normalizedStatus === 'completed_with_warnings'
	) {
		return 'is-completed';
	}

	if ( normalizedStatus === 'needs_review' ) {
		return 'is-needs_review';
	}

	if ( normalizedStatus === 'ready_for_import' ) {
		return 'is-ready_for_import';
	}

	if (
		normalizedStatus === 'failed' ||
		normalizedStatus === 'blocked' ||
		normalizedStatus === 'error'
	) {
		return 'is-blocked';
	}

	if ( ACTIVE_RUN_STATUSES.includes( normalizedStatus ) ) {
		return 'is-running';
	}

	return '';
};

export const isNonTerminalStatus = ( status ) =>
	ACTIVE_RUN_STATUSES.includes( `${ status || '' }`.trim() );

export const isActiveOverview = ( latest = {}, stageSummary = {} ) => {
	if ( isNonTerminalStatus( latest.status ) ) {
		return true;
	}

	const stages =
		stageSummary && Array.isArray( stageSummary.stages )
			? stageSummary.stages
			: [];

	return stages.some( ( stage ) => isNonTerminalStatus( stage.status ) );
};

export const sortStages = ( stageSummary = {} ) => {
	const stages =
		stageSummary && Array.isArray( stageSummary.stages )
			? [ ...stageSummary.stages ]
			: [];

	return stages.sort( ( left, right ) => {
		const leftIndex = STAGE_ORDER.indexOf( left.step_key );
		const rightIndex = STAGE_ORDER.indexOf( right.step_key );

		if ( leftIndex === -1 && rightIndex === -1 ) {
			return `${ left.step_key || '' }`.localeCompare(
				`${ right.step_key || '' }`
			);
		}

		if ( leftIndex === -1 ) {
			return 1;
		}

		if ( rightIndex === -1 ) {
			return -1;
		}

		return leftIndex - rightIndex;
	} );
};

export const buildSummaryItems = (
	latest = {},
	inventory = {},
	stageSummary = {}
) => {
	const inventoryStats =
		inventory && typeof inventory.stats === 'object' ? inventory.stats : {};
	const counts =
		latest && typeof latest.counts === 'object' ? latest.counts : {};
	const reviewCount = getArrayCount( latest.urls_needing_review );
	const blockedCount = getArrayCount( latest.urls_blocked );
	const failedCount = getArrayCount( latest.urls_failed );
	const packageReadyCount = getArrayCount( latest.urls_package_ready );
	const packagesBuiltCount = getArrayCount( latest.packages_built );
	let inventoryCount = 0;
	if ( typeof inventoryStats.eligible_count === 'number' ) {
		inventoryCount = inventoryStats.eligible_count;
	} else if ( typeof inventoryStats.url_count === 'number' ) {
		inventoryCount = inventoryStats.url_count;
	}
	const completedSteps =
		stageSummary &&
		stageSummary.stats &&
		typeof stageSummary.stats.completed_steps === 'number'
			? stageSummary.stats.completed_steps
			: 0;

	return [
		{
			key: 'status',
			label: 'Run status',
			value: humanizeKey( latest.status || 'queued' ),
			meta: `Updated ${ formatTimestamp( latest.updated_at ) }`,
			status: latest.status || 'queued',
		},
		{
			key: 'inventory',
			label: 'In-scope URLs',
			value: inventoryCount,
			meta:
				typeof inventoryStats.raw_url_count === 'number'
					? `Raw sitemap URLs ${ inventoryStats.raw_url_count }`
					: 'Waiting on sitemap inventory',
		},
		{
			key: 'captured',
			label: 'Captured',
			value:
				typeof counts.urls_captured === 'number'
					? counts.urls_captured
					: 0,
			meta: `Extracted ${
				typeof counts.urls_extracted === 'number'
					? counts.urls_extracted
					: 0
			}`,
		},
		{
			key: 'review',
			label: 'Needs review',
			value: reviewCount,
			meta: `Blocked ${ blockedCount } • Failed ${ failedCount }`,
		},
		{
			key: 'package',
			label: 'Package ready',
			value: packageReadyCount,
			meta: `Packages built ${ packagesBuiltCount } • Completed steps ${ completedSteps }`,
		},
	];
};

export const buildNextActions = ( latest = {} ) => {
	const reviewCount = getArrayCount( latest.urls_needing_review );
	const blockedCount = getArrayCount( latest.urls_blocked );
	const packageReadyCount = getArrayCount( latest.urls_package_ready );
	const status = `${ latest.status || '' }`.trim();

	return [
		{
			key: 'exceptions',
			view: 'exceptions',
			title:
				reviewCount > 0 || blockedCount > 0
					? 'Review flagged URLs'
					: 'Inspect the exception queue',
			description:
				reviewCount > 0 || blockedCount > 0
					? `${ reviewCount } need review and ${ blockedCount } are blocked.`
					: 'Open the exception workspace to confirm whether anything still needs manual attention.',
			priority:
				reviewCount > 0 || blockedCount > 0 ? 'primary' : 'secondary',
		},
		{
			key: 'readiness',
			view: 'readiness',
			title:
				status === 'blocked' || status === 'needs_review'
					? 'Check readiness blockers'
					: 'Inspect readiness signals',
			description:
				status === 'blocked' || status === 'needs_review'
					? 'Review blockers, warnings, and per-URL QA before moving deeper into package work.'
					: 'Use the readiness workspace to inspect QA state and schema freshness.',
			priority:
				status === 'blocked' || status === 'needs_review'
					? 'primary'
					: 'secondary',
		},
		{
			key: 'package',
			view: 'package',
			title:
				packageReadyCount > 0 || status === 'ready_for_import'
					? 'Open package workflow'
					: 'Inspect package readiness',
			description:
				packageReadyCount > 0 || status === 'ready_for_import'
					? 'Review build, dry-run, preflight, and execute state for the current run package.'
					: 'Open the package workspace to see whether this run is approaching package-ready status.',
			priority:
				packageReadyCount > 0 || status === 'ready_for_import'
					? 'primary'
					: 'secondary',
		},
	];
};

export const buildRecentActivityItems = ( recentActivity = [] ) => {
	if ( ! Array.isArray( recentActivity ) ) {
		return [];
	}

	return recentActivity
		.filter( ( item ) => item && typeof item === 'object' )
		.map( ( item ) => {
			const warningCount = getArrayCount( item.warningCodes );
			const outputCount =
				item.artifactCounts &&
				typeof item.artifactCounts.output === 'number'
					? item.artifactCounts.output
					: getArrayCount( item.outputArtifacts );
			const inputCount =
				item.artifactCounts &&
				typeof item.artifactCounts.input === 'number'
					? item.artifactCounts.input
					: getArrayCount( item.inputArtifacts );
			let scopeLabel = 'Run-level activity';

			if ( item.path ) {
				scopeLabel = item.path;
			} else if ( item.packageId ) {
				scopeLabel = `Package ${ item.packageId }`;
			} else if ( item.pageId ) {
				scopeLabel = `Page ${ item.pageId }`;
			}

			const metaParts = [];

			if ( inputCount > 0 || outputCount > 0 ) {
				metaParts.push(
					`Artifacts ${ inputCount } in / ${ outputCount } out`
				);
			}

			if ( warningCount > 0 ) {
				metaParts.push( `Warnings ${ warningCount }` );
			}

			if ( item.errorCode ) {
				metaParts.push( `Error ${ humanizeKey( item.errorCode ) }` );
			}

			if ( typeof item.durationMs === 'number' && item.durationMs > 0 ) {
				metaParts.push(
					`Duration ${ formatDuration( item.durationMs ) }`
				);
			}

			return {
				activityId: item.activityId || '',
				stepKey: item.stepKey || '',
				stepName: item.stepName || humanizeKey( item.stepKey || '' ),
				status: item.status || 'queued',
				statusClassName: getStatusClassName( item.status || 'queued' ),
				summary: item.message || 'No activity message recorded yet.',
				scopeLabel,
				finishedAtLabel: formatTimestamp( item.finishedAt ),
				startedAtLabel: formatTimestamp( item.startedAt ),
				meta:
					metaParts.join( ' • ' ) ||
					'No additional evidence recorded.',
			};
		} );
};
