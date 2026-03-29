import {
	formatDuration,
	formatTimestamp,
	getStatusClassName,
	humanizeKey,
	sortStages,
} from '../../workspaces/run-overview/overviewTransforms';

export default function OverviewStageCards( { stageSummary } ) {
	const stages = sortStages( stageSummary );

	if ( ! stages.length ) {
		return (
			<article className="dbvc-cc-v2-placeholder-card">
				<h3>Stage progress</h3>
				<p>No stage activity has been materialized for this run yet.</p>
			</article>
		);
	}

	return (
		<article
			className="dbvc-cc-v2-placeholder-card"
			data-testid="dbvc-cc-v2-overview-stage-list"
		>
			<div className="dbvc-cc-v2-overview-section__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Run progress</p>
					<h3>Stage status</h3>
				</div>
				<p>Updated { formatTimestamp( stageSummary.updated_at ) }</p>
			</div>

			<ul className="dbvc-cc-v2-overview-stage-list">
				{ stages.map( ( stage ) => (
					<li
						key={ stage.step_key }
						className="dbvc-cc-v2-overview-stage-card"
						data-testid={ `dbvc-cc-v2-overview-stage-${ stage.step_key }` }
					>
						<div className="dbvc-cc-v2-overview-stage-card__header">
							<div>
								<strong>
									{ stage.step_name ||
										humanizeKey( stage.step_key ) }
								</strong>
								<p>
									{ stage.latest_message ||
										'No stage message recorded yet.' }
								</p>
							</div>
							<span
								className={ `dbvc-cc-v2-status-pill ${ getStatusClassName(
									stage.status
								) }` }
							>
								{ humanizeKey( stage.status || 'queued' ) }
							</span>
						</div>
						<div className="dbvc-cc-v2-overview-stage-card__meta">
							<span>
								Events { Number( stage.event_count ) || 0 }
							</span>
							<span>
								Duration{ ' ' }
								{ formatDuration(
									Number( stage.last_duration_ms ) || 0
								) }
							</span>
							<span>
								Last finish{ ' ' }
								{ formatTimestamp( stage.last_finished_at ) }
							</span>
						</div>
					</li>
				) ) }
			</ul>
		</article>
	);
}
