import { buildPackageNextActions } from './packageNextActions';

export default function PackageNextActionsPanel( { packageDetail, onAction } ) {
	const actions = buildPackageNextActions( packageDetail );

	if ( ! actions.length ) {
		return null;
	}

	return (
		<article
			className="dbvc-cc-v2-placeholder-card"
			data-testid="dbvc-cc-v2-package-next-actions"
		>
			<div className="dbvc-cc-v2-overview-section__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Next actions</p>
					<h3>Resolve package blockers faster</h3>
				</div>
				<p>
					Use the same route-aware shortcuts as readiness instead of
					hunting through package QA manually.
				</p>
			</div>

			<div className="dbvc-cc-v2-grid dbvc-cc-v2-grid--overview-actions">
				{ actions.map( ( item ) => (
					<div
						key={ item.key }
						className="dbvc-cc-v2-overview-action-card"
						data-priority={ item.priority }
					>
						<div>
							<strong>{ item.title }</strong>
							<p>{ item.description }</p>
						</div>
						<button
							type="button"
							className={ `button ${
								item.priority === 'primary'
									? 'button-primary'
									: 'button-secondary'
							}` }
							data-testid={ `dbvc-cc-v2-package-next-action-${ item.key }` }
							onClick={ () => onAction( item ) }
						>
							{ item.action?.label || 'Open' }
						</button>
					</div>
				) ) }
			</div>
		</article>
	);
}
