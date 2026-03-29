import { getBootstrap } from '../../api/client';
import RunCreateForm from '../../components/runs/RunCreateForm';
import RunCreateLifecyclePanel from '../../components/runs/RunCreateLifecyclePanel';
import useRunCreation from '../../hooks/useRunCreation';
import useRunList from '../../hooks/useRunList';

export default function RunsWorkspace( {
	onMutationComplete,
	onOpenDrawer,
	onSelectRun,
	refreshToken,
	selectedRunId,
} ) {
	const { items, isLoading, error } = useRunList( refreshToken );
	const {
		createRun,
		clearError,
		elapsedMs,
		error: createError,
		isSubmitting,
		lastCreatedRun,
		lastRequest,
		requestFinishedAt,
		requestStartedAt,
		status,
	} = useRunCreation();
	const bootstrap = getBootstrap();

	const handleRunCreate = async ( payload ) => {
		const created = await createRun( payload );
		if ( ! created || ! created.runId ) {
			return;
		}

		if ( typeof onMutationComplete === 'function' ) {
			onMutationComplete();
		}

		onSelectRun( created.runId, 'runs' );
		return created;
	};

	return (
		<section
			className="dbvc-cc-v2-workspace"
			data-testid="dbvc-cc-v2-workspace-runs"
		>
			<div className="dbvc-cc-v2-workspace__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Runs Workspace</p>
					<h2>Start or reopen V2 runs</h2>
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

			<RunCreateForm
				error={ createError }
				isSubmitting={ isSubmitting }
				onClearError={ clearError }
				onSubmit={ handleRunCreate }
				runCreateBootstrap={ bootstrap.runCreate }
			/>

			<RunCreateLifecyclePanel
				elapsedMs={ elapsedMs }
				error={ createError }
				lastCreatedRun={ lastCreatedRun }
				lastRequest={ lastRequest }
				onOpenOverview={ ( runId ) => onSelectRun( runId, 'overview' ) }
				requestFinishedAt={ requestFinishedAt }
				requestStartedAt={ requestStartedAt }
				status={ status }
			/>

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
								className={ `dbvc-cc-v2-placeholder-card${
									selectedRunId === run.runId
										? ' dbvc-cc-v2-placeholder-card--active'
										: ''
								}` }
								data-testid={ `dbvc-cc-v2-run-card-${ run.runId }` }
							>
								<div className="dbvc-cc-v2-run-card__header">
									<p className="dbvc-cc-v2-chip">
										{ run.status }
									</p>
									{ selectedRunId === run.runId ? (
										<p className="dbvc-cc-v2-chip dbvc-cc-v2-chip--muted">
											Selected
										</p>
									) : null }
								</div>
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
								No V2 runs exist yet. Use the run-start surface
								above to create the first crawl-backed V2 run.
							</p>
						</div>
					) }
				</div>
			) : null }
		</section>
	);
}
