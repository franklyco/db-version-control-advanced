import { buildNextActions } from '../../workspaces/run-overview/overviewTransforms';

export default function OverviewNextActions( { latest, onNavigate } ) {
	const actions = buildNextActions( latest );

	return (
		<article
			className="dbvc-cc-v2-placeholder-card"
			data-testid="dbvc-cc-v2-overview-next-actions"
		>
			<div className="dbvc-cc-v2-overview-section__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Next actions</p>
					<h3>Where to go next</h3>
				</div>
				<p>
					Phase 16 keeps overview read-oriented while adding direct
					blocker shortcuts.
				</p>
			</div>

			<div className="dbvc-cc-v2-grid dbvc-cc-v2-grid--overview-actions">
				{ actions.map( ( action ) => (
					<div
						key={ action.key }
						className="dbvc-cc-v2-overview-action-card"
						data-priority={ action.priority }
					>
						<div>
							<strong>{ action.title }</strong>
							<p>{ action.description }</p>
						</div>
						<button
							type="button"
							className={ `button ${
								action.priority === 'primary'
									? 'button-primary'
									: 'button-secondary'
							}` }
							data-testid={ `dbvc-cc-v2-overview-next-action-${ action.key }` }
							onClick={ () => onNavigate( action ) }
						>
							{ action.buttonLabel || 'Open' }
						</button>
					</div>
				) ) }
			</div>
		</article>
	);
}
