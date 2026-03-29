import { useMemo, useState } from '@wordpress/element';

import { getBootstrap } from '../../api/client';
import RunActionStatusPanel from '../../components/runs/RunActionStatusPanel';
import RunCard from '../../components/runs/RunCard';
import RunCreateForm from '../../components/runs/RunCreateForm';
import RunCreateLifecyclePanel from '../../components/runs/RunCreateLifecyclePanel';
import useRunActions from '../../hooks/useRunActions';
import useRunCreation from '../../hooks/useRunCreation';
import useRunList from '../../hooks/useRunList';
import { buildRunCreatePayloadFromProfile } from './runCreateFields';

export default function RunsWorkspace( {
	onMutationComplete,
	onOpenDrawer,
	onSelectRun,
	refreshToken,
	selectedRunId,
} ) {
	const [ includeHidden, setIncludeHidden ] = useState( false );
	const [ hasKnownHiddenRuns, setHasKnownHiddenRuns ] = useState( false );
	const [ prefillProfile, setPrefillProfile ] = useState( null );
	const [ prefillToken, setPrefillToken ] = useState( 0 );
	const [ replaySourceRunId, setReplaySourceRunId ] = useState( '' );
	const { items, meta, isLoading, error } = useRunList(
		refreshToken,
		includeHidden
	);
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
	const {
		clearStatus: clearRunActionStatus,
		error: runActionError,
		isSubmitting: isRunActionSubmitting,
		label: runActionLabel,
		lastCompletedAction,
		lastRecoveryAction,
		progress: runActionProgress,
		rerunStageGroup,
		runId: runActionRunId,
		setRunVisibility,
	} = useRunActions();
	const bootstrap = getBootstrap();
	const hiddenCount = Number( meta?.hiddenCount || 0 );
	const effectiveHiddenCount = Math.max(
		hiddenCount,
		hasKnownHiddenRuns ? 1 : 0
	);
	const runCreateBootstrap = useMemo(
		() => bootstrap.runCreate || {},
		[ bootstrap.runCreate ]
	);

	const handleRunCreate = async ( payload ) => {
		setReplaySourceRunId( '' );
		const created = await createRun( payload, { mode: 'create' } );
		if ( ! created || ! created.runId ) {
			return;
		}

		if ( typeof onMutationComplete === 'function' ) {
			onMutationComplete();
		}

		onSelectRun( created.runId, 'runs' );
		return created;
	};

	const handleDuplicateRun = ( run ) => {
		if ( ! run || ! run.runProfile ) {
			return;
		}

		setPrefillProfile( run.runProfile );
		setPrefillToken( ( currentToken ) => currentToken + 1 );
		clearRunActionStatus( { preserveRecovery: true } );
	};

	const handleReplayRun = async ( run ) => {
		if ( ! run || ! run.runId || ! run.runProfile ) {
			return;
		}

		const payload = buildRunCreatePayloadFromProfile( run.runProfile );
		if ( ! payload.sitemapUrl ) {
			return;
		}

		setReplaySourceRunId( run.runId );
		clearRunActionStatus();

		const created = await createRun( payload, {
			mode: 'replay',
			sourceRunId: run.runId,
		} );

		setReplaySourceRunId( '' );

		if ( ! created || ! created.runId ) {
			return;
		}

		if ( typeof onMutationComplete === 'function' ) {
			onMutationComplete();
		}

		onSelectRun( created.runId, 'runs' );
	};

	const handleToggleHidden = async ( run, hidden ) => {
		if ( ! run || ! run.runId ) {
			return;
		}

		const result = await setRunVisibility( run.runId, hidden );
		if ( ! result ) {
			return;
		}

		if ( typeof onMutationComplete === 'function' ) {
			onMutationComplete();
		}

		setHasKnownHiddenRuns( !! result.hidden );
	};

	const handleOpenRunOverview = ( runId ) => {
		if ( ! runId ) {
			return;
		}

		onSelectRun( runId, 'overview' );
	};

	const handleOpenRunExceptions = ( runId ) => {
		if ( ! runId ) {
			return;
		}

		onSelectRun( runId, 'exceptions' );
	};

	const handleRerunStageGroup = async ( run, candidate ) => {
		if ( ! run || ! run.runId ) {
			return;
		}

		const result = await rerunStageGroup( run.runId, candidate );
		if ( ! result ) {
			return;
		}

		if ( typeof onMutationComplete === 'function' ) {
			onMutationComplete();
		}
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

			{ effectiveHiddenCount > 0 || includeHidden ? (
				<div className="dbvc-cc-v2-run-filters">
					<button
						type="button"
						className="button button-secondary"
						data-testid="dbvc-cc-v2-run-show-hidden-toggle"
						onClick={ () =>
							setIncludeHidden(
								( currentState ) => ! currentState
							)
						}
					>
						{ includeHidden
							? 'Hide hidden runs'
							: `Show hidden runs (${ effectiveHiddenCount })` }
					</button>
				</div>
			) : null }

			<RunCreateForm
				error={ createError }
				isSubmitting={ isSubmitting }
				onClearError={ clearError }
				prefillProfile={ prefillProfile }
				prefillToken={ prefillToken }
				onSubmit={ handleRunCreate }
				runCreateBootstrap={ runCreateBootstrap }
			/>

			<RunCreateLifecyclePanel
				elapsedMs={ elapsedMs }
				error={ createError }
				lastCreatedRun={ lastCreatedRun }
				lastRequest={ lastRequest }
				onOpenOverview={ handleOpenRunOverview }
				onOpenSourceRun={ handleOpenRunOverview }
				requestFinishedAt={ requestFinishedAt }
				requestStartedAt={ requestStartedAt }
				status={ status }
			/>

			<RunActionStatusPanel
				error={ runActionError }
				isSubmitting={ isRunActionSubmitting }
				label={ runActionLabel }
				lastCompletedAction={ lastCompletedAction }
				lastRecoveryAction={ lastRecoveryAction }
				onOpenExceptions={ handleOpenRunExceptions }
				onOpenOverview={ handleOpenRunOverview }
				progress={ runActionProgress }
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
							<RunCard
								key={ run.runId }
								isActionBusy={
									isRunActionSubmitting &&
									runActionRunId === run.runId
								}
								isCreateBusy={
									isSubmitting &&
									replaySourceRunId === run.runId
								}
								isSelected={ selectedRunId === run.runId }
								onDuplicateRun={ handleDuplicateRun }
								onOpenOverview={ ( runId ) =>
									onSelectRun( runId, 'overview' )
								}
								onReplayRun={ handleReplayRun }
								onRerunStageGroup={ handleRerunStageGroup }
								onToggleHidden={ handleToggleHidden }
								run={ run }
							/>
						) )
					) : (
						<div className="dbvc-cc-v2-placeholder-card">
							<p>
								{ includeHidden
									? 'No V2 runs match the current hidden-run view.'
									: 'No V2 runs exist yet. Use the run-start surface above to create the first crawl-backed V2 run.' }
							</p>
						</div>
					) }
				</div>
			) : null }
		</section>
	);
}
