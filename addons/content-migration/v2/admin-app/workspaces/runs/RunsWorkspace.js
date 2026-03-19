import useRunList from '../../hooks/useRunList';

export default function RunsWorkspace( {
	onOpenDrawer,
	onSelectRun,
	refreshToken,
} ) {
	const { items, isLoading, error } = useRunList( refreshToken );

	return (
		<section
			className="dbvc-cc-v2-workspace"
			data-testid="dbvc-cc-v2-workspace-runs"
		>
			<div className="dbvc-cc-v2-workspace__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Runs Workspace</p>
					<h2>Recent run shells</h2>
				</div>
				<button
					type="button"
					className="button button-secondary"
					data-testid="dbvc-cc-v2-runs-drawer-toggle"
					onClick={ () => onOpenDrawer( 'seed-home' ) }
				>
					Inspect sample URL
				</button>
			</div>

			{ isLoading ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<p>Loading active V2 runs.</p>
				</div>
			) : null }

			{ error ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<p>{ error }</p>
				</div>
			) : null }

			{ ! isLoading && ! error ? (
				<div className="dbvc-cc-v2-grid dbvc-cc-v2-grid--runs">
					{ items.length ? (
						items.map( ( run ) => (
							<article
								key={ run.runId }
								className="dbvc-cc-v2-placeholder-card"
							>
								<p className="dbvc-cc-v2-chip">
									{ run.status }
								</p>
								<h3>{ run.runId }</h3>
								<p>Domain: { run.domain }</p>
								<p>Updated: { run.updatedAt || 'unknown' }</p>
								<button
									type="button"
									className="button button-primary"
									data-testid={ `dbvc-cc-v2-open-run-${ run.runId }` }
									onClick={ () =>
										onSelectRun( run.runId, 'overview' )
									}
								>
									Open overview
								</button>
							</article>
						) )
					) : (
						<div className="dbvc-cc-v2-placeholder-card">
							<p>
								No V2 runs exist yet. Start a run from the REST
								API or current controls, then return here.
							</p>
						</div>
					) }
				</div>
			) : null }
		</section>
	);
}
