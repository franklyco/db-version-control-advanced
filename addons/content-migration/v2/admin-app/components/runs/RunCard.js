const renderRerunButtonLabel = ( candidate ) => {
	const count = Number( candidate?.count || 0 );
	if ( count > 0 ) {
		return `${ candidate.label } (${ count })`;
	}

	return candidate?.label || 'Rerun stage';
};

export default function RunCard( {
	isCreateBusy,
	isActionBusy,
	isSelected,
	onDuplicateRun,
	onOpenOverview,
	onReplayRun,
	onRerunStageGroup,
	onToggleHidden,
	run,
} ) {
	const rerunCandidates = Array.isArray( run?.actionSummary?.rerunCandidates )
		? run.actionSummary.rerunCandidates
		: [];
	const runProfile =
		run && typeof run.runProfile === 'object' ? run.runProfile : {};
	const canDuplicate =
		typeof runProfile.sitemapUrl === 'string' && runProfile.sitemapUrl;
	const isBusy = isActionBusy || isCreateBusy;

	return (
		<article
			className={ `dbvc-cc-v2-placeholder-card dbvc-cc-v2-run-card${
				isSelected ? ' dbvc-cc-v2-placeholder-card--active' : ''
			}` }
			data-testid={ `dbvc-cc-v2-run-card-${ run.runId }` }
		>
			<div className="dbvc-cc-v2-run-card__header">
				<p className="dbvc-cc-v2-chip">{ run.status }</p>
				<div className="dbvc-cc-v2-run-card__chips">
					{ run.hidden ? (
						<p className="dbvc-cc-v2-chip dbvc-cc-v2-chip--muted">
							Hidden
						</p>
					) : null }
					{ isSelected ? (
						<p className="dbvc-cc-v2-chip dbvc-cc-v2-chip--muted">
							Selected
						</p>
					) : null }
				</div>
			</div>

			<h3>{ run.runId }</h3>
			<p>Domain: { run.domain }</p>
			<p>Updated: { run.updatedAt || 'unknown' }</p>
			{ canDuplicate ? (
				<p className="dbvc-cc-v2-run-card__meta">
					Sitemap: { runProfile.sitemapUrl }
				</p>
			) : null }
			{ Number( runProfile.maxUrls || 0 ) > 0 ? (
				<p className="dbvc-cc-v2-run-card__meta">
					Max URLs: { runProfile.maxUrls }
				</p>
			) : null }
			{ ! canDuplicate ? (
				<p className="dbvc-cc-v2-run-card__hint">
					Stored request settings are unavailable for this older run,
					so replay and duplicate helpers stay disabled.
				</p>
			) : null }
			{ rerunCandidates.length ? (
				<div className="dbvc-cc-v2-run-card__reruns">
					<p className="dbvc-cc-v2-run-card__label">Rerun helpers</p>
					<div className="dbvc-cc-v2-run-card__rerun-list">
						{ rerunCandidates.map( ( candidate ) => (
							<button
								key={ `${ run.runId }-${ candidate.stage }` }
								type="button"
								className="button button-secondary"
								disabled={ isBusy }
								data-testid={ `dbvc-cc-v2-run-rerun-${ candidate.stage }-${ run.runId }` }
								onClick={ () =>
									onRerunStageGroup( run, candidate )
								}
							>
								{ renderRerunButtonLabel( candidate ) }
							</button>
						) ) }
					</div>
				</div>
			) : null }

			<div className="dbvc-cc-v2-run-card__actions">
				<button
					type="button"
					className="button button-primary"
					data-testid={ `dbvc-cc-v2-open-run-${ run.runId }` }
					onClick={ () => onOpenOverview( run.runId ) }
				>
					Open overview
				</button>
				<button
					type="button"
					className="button button-secondary"
					disabled={ ! canDuplicate || isBusy }
					data-testid={ `dbvc-cc-v2-run-replay-${ run.runId }` }
					onClick={ () => onReplayRun( run ) }
				>
					Replay run
				</button>
				<button
					type="button"
					className="button button-secondary"
					disabled={ ! canDuplicate || isBusy }
					data-testid={ `dbvc-cc-v2-run-duplicate-${ run.runId }` }
					onClick={ () => onDuplicateRun( run ) }
				>
					Duplicate settings
				</button>
				<button
					type="button"
					className="button button-secondary"
					disabled={ isBusy }
					data-testid={ `dbvc-cc-v2-run-visibility-${ run.runId }` }
					onClick={ () => onToggleHidden( run, ! run.hidden ) }
				>
					{ run.hidden ? 'Restore run' : 'Hide run' }
				</button>
			</div>
		</article>
	);
}
