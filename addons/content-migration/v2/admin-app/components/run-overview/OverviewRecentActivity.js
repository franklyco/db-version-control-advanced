import {
	buildRecentActivityItems,
	humanizeKey,
} from '../../workspaces/run-overview/overviewTransforms';

export default function OverviewRecentActivity( { recentActivity } ) {
	const items = buildRecentActivityItems( recentActivity );

	if ( ! items.length ) {
		return (
			<article
				className="dbvc-cc-v2-placeholder-card"
				data-testid="dbvc-cc-v2-overview-activity"
			>
				<h3>Recent activity</h3>
				<p>
					No recent run activity has been recorded for this run yet.
				</p>
			</article>
		);
	}

	return (
		<article
			className="dbvc-cc-v2-placeholder-card"
			data-testid="dbvc-cc-v2-overview-activity"
		>
			<div className="dbvc-cc-v2-overview-section__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Operator evidence</p>
					<h3>Recent activity</h3>
				</div>
				<p>Read-only timeline from the journey log</p>
			</div>

			<ol
				className="dbvc-cc-v2-overview-activity-list"
				data-testid="dbvc-cc-v2-overview-activity-list"
			>
				{ items.map( ( item ) => (
					<li
						key={ item.activityId || item.stepKey }
						className="dbvc-cc-v2-overview-activity-item"
						data-testid={ `dbvc-cc-v2-overview-activity-item-${ item.activityId }` }
					>
						<div className="dbvc-cc-v2-overview-activity-item__header">
							<div>
								<strong>{ item.stepName }</strong>
								<p>{ item.summary }</p>
							</div>
							<span
								className={ `dbvc-cc-v2-status-pill ${ item.statusClassName }` }
							>
								{ humanizeKey( item.status ) }
							</span>
						</div>
						<div className="dbvc-cc-v2-overview-activity-item__meta">
							<span>
								<strong>Scope:</strong> { item.scopeLabel }
							</span>
							<span>
								<strong>Finished:</strong>{ ' ' }
								{ item.finishedAtLabel }
							</span>
							<span>
								<strong>Started:</strong>{ ' ' }
								{ item.startedAtLabel }
							</span>
						</div>
						<p className="dbvc-cc-v2-overview-activity-item__evidence">
							{ item.meta }
						</p>
					</li>
				) ) }
			</ol>
		</article>
	);
}
