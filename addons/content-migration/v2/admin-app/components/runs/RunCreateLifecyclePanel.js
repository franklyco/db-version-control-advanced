const formatDuration = ( elapsedMs ) => {
	if ( typeof elapsedMs !== 'number' || elapsedMs <= 0 ) {
		return '0.0s';
	}

	const seconds = elapsedMs / 1000;
	return `${ seconds < 10 ? seconds.toFixed( 1 ) : seconds.toFixed( 0 ) }s`;
};

const formatTimestamp = ( value ) => {
	if ( ! value ) {
		return '';
	}

	const date = new Date( value );
	if ( Number.isNaN( date.getTime() ) ) {
		return '';
	}

	return date.toLocaleString( undefined, {
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
		second: '2-digit',
	} );
};

const humanizeKey = ( key ) =>
	`${ key || '' }`
		.replace( /_/g, ' ' )
		.replace( /\b\w/g, ( letter ) => letter.toUpperCase() )
		.trim();

const buildLifecycleHeading = ( mode ) => {
	if ( mode === 'replay' ) {
		return 'Observe run replay without leaving the workspace';
	}

	return 'Observe run creation without leaving the workspace';
};

const buildStatusCopy = ( status, mode ) => {
	const actionLabel =
		mode === 'replay'
			? 'Replaying schema sync, sitemap inventory, capture, and pipeline startup with stored run settings.'
			: 'Submitting schema sync, sitemap inventory, capture, and pipeline startup in one V2 admin request.';
	if ( status === 'submitting' ) {
		return actionLabel;
	}

	if ( status === 'success' ) {
		return mode === 'replay'
			? 'The replay request finished and the new run is ready for review or handoff to the overview workspace.'
			: 'The run request finished and the created run is ready for review or handoff to the overview workspace.';
	}

	if ( status === 'error' ) {
		return mode === 'replay'
			? 'The replay request failed before the new run could be confirmed. Review the stored inputs and error details below.'
			: 'The run request failed before the new run could be confirmed. Review the attempted inputs and error details below.';
	}

	return '';
};

const buildStatusLabel = ( status ) => {
	if ( status === 'submitting' ) {
		return 'In progress';
	}

	if ( status === 'success' ) {
		return 'Completed';
	}

	if ( status === 'error' ) {
		return 'Failed';
	}

	return 'Idle';
};

const buildStatsEntries = ( stats ) => {
	if ( ! stats || typeof stats !== 'object' || Array.isArray( stats ) ) {
		return [];
	}

	return Object.entries( stats )
		.filter(
			( [ , value ] ) =>
				typeof value === 'number' && Number.isFinite( value )
		)
		.slice( 0, 4 )
		.map( ( [ key, value ] ) => ( {
			key,
			label: humanizeKey( key ),
			value,
		} ) );
};

const buildStageEntries = ( stageSummary ) => {
	if (
		! stageSummary ||
		typeof stageSummary !== 'object' ||
		Array.isArray( stageSummary ) ||
		! Array.isArray( stageSummary.stages )
	) {
		return [];
	}

	return stageSummary.stages.slice( 0, 4 );
};

const getPanelStatusClassName = ( status ) => {
	if ( status === 'completed' || status === 'completed_with_warnings' ) {
		return 'is-completed';
	}

	if ( status === 'needs_review' ) {
		return 'is-needs_review';
	}

	if ( status === 'ready_for_import' ) {
		return 'is-ready_for_import';
	}

	if ( status === 'submitting' ) {
		return 'is-running';
	}

	if ( status === 'success' ) {
		return 'is-success';
	}

	if ( status === 'error' || status === 'failed' || status === 'blocked' ) {
		return 'is-blocked';
	}

	return '';
};

export default function RunCreateLifecyclePanel( {
	elapsedMs,
	error,
	lastCreatedRun,
	lastRequest,
	onOpenSourceRun,
	onOpenOverview,
	requestFinishedAt,
	requestStartedAt,
	status,
} ) {
	if ( status === 'idle' && ! lastRequest && ! lastCreatedRun && ! error ) {
		return null;
	}

	const statsEntries = buildStatsEntries(
		lastCreatedRun && lastCreatedRun.stats
	);
	const stageEntries = buildStageEntries(
		lastCreatedRun && lastCreatedRun.stageSummary
	);
	const requestMode =
		lastRequest && typeof lastRequest.mode === 'string'
			? lastRequest.mode
			: 'create';
	const statusLabel = buildStatusLabel( status );
	const statusCopy = buildStatusCopy( status, requestMode );
	const runId =
		lastCreatedRun && typeof lastCreatedRun.runId === 'string'
			? lastCreatedRun.runId
			: '';
	const sourceRunId =
		lastRequest && typeof lastRequest.sourceRunId === 'string'
			? lastRequest.sourceRunId
			: '';
	let domain = '';
	if ( lastCreatedRun && typeof lastCreatedRun.domain === 'string' ) {
		domain = lastCreatedRun.domain;
	} else if ( lastRequest && typeof lastRequest.domain === 'string' ) {
		domain = lastRequest.domain;
	}
	const successHeadline =
		requestMode === 'replay'
			? `Created replay run ${ runId }`
			: `Created run ${ runId }`;
	let successCopy = 'The run is ready for deeper review.';

	if ( requestMode === 'replay' ) {
		const replaySourceRunId =
			lastRequest && typeof lastRequest.sourceRunId === 'string'
				? lastRequest.sourceRunId
				: 'the selected run';
		const replayDomainSuffix = domain ? ` for ${ domain }` : '';

		successCopy = `Stored settings from ${ replaySourceRunId } were replayed${ replayDomainSuffix }.`;
	} else if ( domain ) {
		successCopy = `The run is now linked to ${ domain } and ready for deeper review.`;
	}

	return (
		<section
			className="dbvc-cc-v2-placeholder-card dbvc-cc-v2-run-create-lifecycle"
			data-status={ status }
			data-testid="dbvc-cc-v2-run-create-lifecycle"
		>
			<div className="dbvc-cc-v2-run-create-lifecycle__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Run request status</p>
					<h3>{ buildLifecycleHeading( requestMode ) }</h3>
					<p>{ statusCopy }</p>
				</div>
				<span
					className={ `dbvc-cc-v2-status-pill ${ getPanelStatusClassName(
						status
					) }` }
					data-testid="dbvc-cc-v2-run-create-lifecycle-status"
				>
					{ statusLabel }
				</span>
			</div>

			<div className="dbvc-cc-v2-run-create-lifecycle__meta">
				{ requestStartedAt ? (
					<p>
						<strong>Started:</strong>{ ' ' }
						{ formatTimestamp( requestStartedAt ) || 'just now' }
					</p>
				) : null }
				{ requestFinishedAt ? (
					<p>
						<strong>Finished:</strong>{ ' ' }
						{ formatTimestamp( requestFinishedAt ) || 'just now' }
					</p>
				) : null }
				<p data-testid="dbvc-cc-v2-run-create-lifecycle-timer">
					<strong>Elapsed:</strong> { formatDuration( elapsedMs ) }
				</p>
			</div>

			{ lastRequest ? (
				<div
					className="dbvc-cc-v2-run-create-lifecycle__request"
					data-testid="dbvc-cc-v2-run-create-lifecycle-request"
				>
					<div>
						<span>Request mode</span>
						<strong>
							{ requestMode === 'replay'
								? 'Replay stored settings'
								: 'Start new run' }
						</strong>
					</div>
					<div>
						<span>Source domain</span>
						<strong>
							{ lastRequest.domain || 'derived from sitemap' }
						</strong>
					</div>
					<div>
						<span>Sitemap URL</span>
						<strong>
							{ lastRequest.sitemapUrl || 'not provided' }
						</strong>
					</div>
					<div>
						<span>Max URLs</span>
						<strong>
							{ lastRequest.maxUrls > 0
								? lastRequest.maxUrls
								: 'No cap' }
						</strong>
					</div>
					<div>
						<span>Force rebuild</span>
						<strong>
							{ lastRequest.forceRebuild ? 'Yes' : 'No' }
						</strong>
					</div>
					<div>
						<span>Advanced overrides</span>
						<strong>{ lastRequest.overrideCount }</strong>
					</div>
					{ lastRequest.sourceRunId ? (
						<div>
							<span>Source run</span>
							<strong>{ lastRequest.sourceRunId }</strong>
						</div>
					) : null }
				</div>
			) : null }

			{ status === 'error' && error ? (
				<div
					className="dbvc-cc-v2-run-create-lifecycle__alert dbvc-cc-v2-run-create-lifecycle__alert--error"
					data-testid="dbvc-cc-v2-run-create-lifecycle-error"
				>
					<strong>Request failed</strong>
					<p>{ error }</p>
				</div>
			) : null }

			{ status === 'success' && runId ? (
				<div
					className="dbvc-cc-v2-run-create-lifecycle__alert"
					data-run-id={ runId }
					data-testid="dbvc-cc-v2-run-create-lifecycle-success"
				>
					<div>
						<strong>{ successHeadline }</strong>
						<p>{ successCopy }</p>
					</div>
					<div className="dbvc-cc-v2-run-create-lifecycle__actions">
						{ requestMode === 'replay' &&
						sourceRunId &&
						typeof onOpenSourceRun === 'function' ? (
							<button
								type="button"
								className="button button-secondary"
								data-testid="dbvc-cc-v2-run-create-open-source-run"
								onClick={ () => onOpenSourceRun( sourceRunId ) }
							>
								Open source run
							</button>
						) : null }
						{ typeof onOpenOverview === 'function' ? (
							<button
								type="button"
								className="button button-primary"
								data-testid="dbvc-cc-v2-run-create-open-overview"
								onClick={ () => onOpenOverview( runId ) }
							>
								Open overview
							</button>
						) : null }
					</div>
				</div>
			) : null }

			{ status === 'success' && statsEntries.length ? (
				<div className="dbvc-cc-v2-run-create-lifecycle__stats">
					{ statsEntries.map( ( entry ) => (
						<div key={ entry.key }>
							<span>{ entry.label }</span>
							<strong>{ entry.value }</strong>
						</div>
					) ) }
				</div>
			) : null }

			{ status === 'success' && stageEntries.length ? (
				<div className="dbvc-cc-v2-run-create-lifecycle__stages">
					<div className="dbvc-cc-v2-run-create-lifecycle__stages-copy">
						<h4>Stage snapshot</h4>
						<p>
							This is the latest materialized stage summary
							returned by the run-create response.
						</p>
					</div>

					<ul className="dbvc-cc-v2-run-create-lifecycle__stage-list">
						{ stageEntries.map( ( stage ) => (
							<li
								key={ stage.step_key }
								className="dbvc-cc-v2-run-create-lifecycle__stage"
								data-testid={ `dbvc-cc-v2-run-create-stage-${ stage.step_key }` }
							>
								<div>
									<strong>
										{ stage.step_name ||
											humanizeKey( stage.step_key ) }
									</strong>
									<p>
										{ stage.latest_message ||
											'No message recorded.' }
									</p>
								</div>
								<div className="dbvc-cc-v2-run-create-lifecycle__stage-meta">
									<span
										className={ `dbvc-cc-v2-status-pill ${ getPanelStatusClassName(
											stage.status
										) }` }
									>
										{ humanizeKey( stage.status ) ||
											'Queued' }
									</span>
									<span>
										Events:{ ' ' }
										{ Number( stage.event_count ) || 0 }
									</span>
									<span>
										Duration:{ ' ' }
										{ formatDuration(
											Number( stage.last_duration_ms ) ||
												0
										) }
									</span>
								</div>
							</li>
						) ) }
					</ul>
				</div>
			) : null }
		</section>
	);
}
