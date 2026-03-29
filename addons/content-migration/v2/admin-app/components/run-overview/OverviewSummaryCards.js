import {
	buildSummaryItems,
	getStatusClassName,
} from '../../workspaces/run-overview/overviewTransforms';

export default function OverviewSummaryCards( {
	inventory,
	latest,
	stageSummary,
} ) {
	const items = buildSummaryItems( latest, inventory, stageSummary );

	return (
		<div
			className="dbvc-cc-v2-grid dbvc-cc-v2-grid--overview-summary"
			data-testid="dbvc-cc-v2-overview-summary"
		>
			{ items.map( ( item ) => (
				<article
					key={ item.key }
					className="dbvc-cc-v2-placeholder-card dbvc-cc-v2-overview-summary-card"
					data-testid={ `dbvc-cc-v2-overview-summary-${ item.key }` }
				>
					<div className="dbvc-cc-v2-overview-summary-card__header">
						<p>{ item.label }</p>
						{ item.status ? (
							<span
								className={ `dbvc-cc-v2-status-pill ${ getStatusClassName(
									item.status
								) }` }
							>
								{ item.value }
							</span>
						) : null }
					</div>
					{ item.status ? null : <strong>{ item.value }</strong> }
					<p>{ item.meta }</p>
				</article>
			) ) }
		</div>
	);
}
