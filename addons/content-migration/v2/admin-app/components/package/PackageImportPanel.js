import { useState } from '@wordpress/element';

import PackageActionConfirmDialog from './PackageActionConfirmDialog';
import { buildPackageNextActions } from './packageNextActions';

const renderIssueList = ( items = [], fallback, issueCount = 0 ) => {
	if ( ! Array.isArray( items ) || ! items.length ) {
		if ( issueCount > 0 ) {
			return (
				<p>
					{ issueCount } issues were recorded in the latest snapshot.
					Run the action again in this session to load the detailed
					issue list.
				</p>
			);
		}

		return <p>{ fallback }</p>;
	}

	return (
		<ul className="dbvc-cc-v2-inspector-list">
			{ items.slice( 0, 5 ).map( ( item, index ) => (
				<li key={ `${ item.code || 'issue' }-${ index }` }>
					<strong>{ item.code || 'issue' }</strong>
					<p className="dbvc-cc-v2-table__meta">
						{ item.message || 'No detail was provided.' }
					</p>
				</li>
			) ) }
		</ul>
	);
};

const buildBlockerSummary = ( summary = {} ) => {
	const fragments = [];

	if ( summary.blockedPages ) {
		fragments.push( `${ summary.blockedPages } blocked pages` );
	}
	if ( summary.writeBarriers ) {
		fragments.push( `${ summary.writeBarriers } write barriers` );
	}
	if ( summary.guardFailures ) {
		fragments.push( `${ summary.guardFailures } guard failures` );
	}

	return fragments.join( ', ' );
};

const buildPreflightDisabledReason = ( {
	hasPackage,
	isPreflightLoading,
	isExecuteLoading,
} ) => {
	if ( ! hasPackage ) {
		return 'Build or select a package before requesting preflight approval.';
	}

	if ( isPreflightLoading ) {
		return 'Preflight approval is already running.';
	}

	if ( isExecuteLoading ) {
		return 'Wait for the current package execute request to finish.';
	}

	return '';
};

const buildExecuteDisabledReason = ( {
	hasPackage,
	isPreflightLoading,
	isExecuteLoading,
	preflight,
	persistedPreflight,
	preflightSummary,
} ) => {
	if ( ! hasPackage ) {
		return 'Build or select a package before executing import.';
	}

	if ( isPreflightLoading ) {
		return 'Wait for preflight approval to finish before executing import.';
	}

	if ( isExecuteLoading ) {
		return 'A package import is already running.';
	}

	if ( ! preflight ) {
		if ( persistedPreflight ) {
			return 'Request fresh preflight approval in this session before execute. Persisted snapshots do not retain approval tokens.';
		}

		return 'Request preflight approval before executing the selected package.';
	}

	if ( ! preflight.approvalEligible ) {
		const blockerSummary = buildBlockerSummary( preflightSummary );

		return blockerSummary
			? `Preflight is not approval-eligible yet: ${ blockerSummary }.`
			: 'Preflight is not approval-eligible yet.';
	}

	if ( ! preflight.approvalValid || ! preflight.executeReady ) {
		const blockerSummary = buildBlockerSummary( preflightSummary );

		return blockerSummary
			? `Resolve the remaining preflight blockers: ${ blockerSummary }.`
			: 'Resolve the remaining preflight blockers.';
	}

	return '';
};

export default function PackageImportPanel( {
	preflight,
	execution,
	error,
	isPreflightLoading,
	isExecuteLoading,
	onRequestPreflight,
	onExecuteImport,
	hasPackage,
	workflowState,
	latestImport,
	packageId,
	onResolvePrimaryBlocker,
	packageDetail,
} ) {
	const [ pendingAction, setPendingAction ] = useState( null );
	const primaryPackageAction = buildPackageNextActions( packageDetail )[ 0 ];
	const persistedPreflight =
		workflowState && typeof workflowState.latestPreflight === 'object'
			? workflowState.latestPreflight
			: null;
	const activePreflight = preflight || persistedPreflight;
	const activeExecution = execution || latestImport || null;
	const preflightSummary =
		activePreflight && typeof activePreflight.summary === 'object'
			? activePreflight.summary
			: {};
	const executionSummary =
		activeExecution && typeof activeExecution.summary === 'object'
			? activeExecution.summary
			: {};
	const preflightDisabledReason = buildPreflightDisabledReason( {
		hasPackage,
		isPreflightLoading,
		isExecuteLoading,
	} );
	const executeDisabledReason = buildExecuteDisabledReason( {
		hasPackage,
		isPreflightLoading,
		isExecuteLoading,
		preflight,
		persistedPreflight,
		preflightSummary,
	} );
	const canRequestPreflight = preflightDisabledReason === '';
	const canExecute = executeDisabledReason === '';
	const latestImportStatus = activeExecution?.status || 'not_started';
	const latestRollbackCount =
		activeExecution?.summary?.rollbackAvailableRuns ?? 0;
	const latestIssueCount = activeExecution?.issueCount ?? 0;
	const latestRollbackStatus = Array.isArray( activeExecution?.importRuns )
		? activeExecution.importRuns[ 0 ]?.rollbackStatus || 'not_started'
		: 'not_started';
	const packageSuffix = packageId ? ` (${ packageId })` : '';

	const handleConfirm = async () => {
		if ( ! pendingAction ) {
			return;
		}

		const actionType = pendingAction.type;
		setPendingAction( null );

		if ( actionType === 'preflight' ) {
			await onRequestPreflight();
			return;
		}

		await onExecuteImport();
	};

	return (
		<>
			<section
				className="dbvc-cc-v2-grid dbvc-cc-v2-grid--package-detail"
				data-testid="dbvc-cc-v2-package-preflight"
			>
				<article className="dbvc-cc-v2-placeholder-card">
					<p className="dbvc-cc-v2-eyebrow">Import bridge</p>
					<h3>{ activePreflight?.status || 'not_started' }</h3>
					<p>
						Approval eligible:{ ' ' }
						{ activePreflight?.approvalEligible ? 'yes' : 'no' }
					</p>
					<p>
						Execute ready:{ ' ' }
						{ activePreflight?.executeReady ? 'yes' : 'no' }
					</p>
					<div className="dbvc-cc-v2-actions">
						<button
							type="button"
							className="button"
							data-testid="dbvc-cc-v2-package-preflight-trigger"
							disabled={ ! canRequestPreflight }
							onClick={ () =>
								setPendingAction( {
									type: 'preflight',
									title: 'Request preflight approval?',
									description: `This will validate the selected package${ packageSuffix } and mint fresh approval tokens for the current browser session before execute.`,
									confirmLabel: 'Request approval',
									loadingLabel: 'Requesting approval…',
									confirmTestId:
										'dbvc-cc-v2-package-preflight-confirm',
								} )
							}
						>
							{ isPreflightLoading
								? 'Requesting approval…'
								: 'Request preflight approval' }
						</button>
						<button
							type="button"
							className="button button-primary"
							data-testid="dbvc-cc-v2-package-execute-trigger"
							disabled={ ! canExecute }
							onClick={ () =>
								setPendingAction( {
									type: 'execute',
									title: 'Execute package import?',
									description: `This will apply the selected package${ packageSuffix } through the shared import executor using the active preflight approval tokens and will record rollback state where available.`,
									confirmLabel: 'Execute import',
									loadingLabel: 'Executing import…',
									confirmTestId:
										'dbvc-cc-v2-package-execute-confirm',
								} )
							}
						>
							{ isExecuteLoading
								? 'Executing import…'
								: 'Execute package import' }
						</button>
					</div>
					{ preflightDisabledReason ? (
						<div className="dbvc-cc-v2-action-panel__notice-card">
							<div>
								<strong>Preflight state</strong>
								<p>{ preflightDisabledReason }</p>
							</div>
						</div>
					) : null }
					{ executeDisabledReason ? (
						<div className="dbvc-cc-v2-action-panel__notice-card dbvc-cc-v2-action-panel__notice-card--warning">
							<div>
								<strong>Execute blocked</strong>
								<p>{ executeDisabledReason }</p>
							</div>
							{ primaryPackageAction?.action &&
							typeof onResolvePrimaryBlocker === 'function' ? (
								<button
									type="button"
									className="button button-secondary"
									data-testid="dbvc-cc-v2-package-resolve-primary-blocker"
									onClick={ () =>
										onResolvePrimaryBlocker(
											primaryPackageAction
										)
									}
								>
									{ primaryPackageAction.action.label }
								</button>
							) : null }
						</div>
					) : null }
					{ activeExecution ? (
						<div className="dbvc-cc-v2-action-panel__notice-card">
							<div>
								<strong>Latest recorded import</strong>
								<p>
									Status: { latestImportStatus }.
									Rollback-available runs:{ ' ' }
									{ latestRollbackCount }. Latest rollback
									status: { latestRollbackStatus }. Issues:{ ' ' }
									{ latestIssueCount }.
								</p>
							</div>
						</div>
					) : null }
					{ error ? <p>{ error }</p> : null }
				</article>

				<article className="dbvc-cc-v2-placeholder-card">
					<p className="dbvc-cc-v2-eyebrow">Preflight summary</p>
					<p>
						Included pages: { preflightSummary.includedPages ?? 0 }
					</p>
					<p>
						Approved pages: { preflightSummary.approvedPages ?? 0 }
					</p>
					<p>Blocked pages: { preflightSummary.blockedPages ?? 0 }</p>
					<p>
						Write barriers: { preflightSummary.writeBarriers ?? 0 }
					</p>
				</article>

				<article className="dbvc-cc-v2-placeholder-card">
					<p className="dbvc-cc-v2-eyebrow">Preflight issues</p>
					{ renderIssueList(
						preflight?.issues,
						'No package-level preflight issues were reported.',
						activePreflight?.issueCount ?? 0
					) }
				</article>

				<article
					className="dbvc-cc-v2-placeholder-card dbvc-cc-v2-placeholder-card--full"
					data-testid="dbvc-cc-v2-package-execution"
				>
					<p className="dbvc-cc-v2-eyebrow">Execution summary</p>
					{ activeExecution ? (
						<>
							<div className="dbvc-cc-v2-grid dbvc-cc-v2-grid--package-detail">
								<div>
									<p>
										Status:{ ' ' }
										{ activeExecution.status || 'unknown' }
									</p>
									<p>
										Completed pages:{ ' ' }
										{ executionSummary.completedPages ?? 0 }
									</p>
									<p>
										Partial pages:{ ' ' }
										{ executionSummary.partialPages ?? 0 }
									</p>
									<p>
										Import runs:{ ' ' }
										{ executionSummary.importRuns ?? 0 }
									</p>
								</div>
								<div>
									<p>
										Entity writes:{ ' ' }
										{ executionSummary.executedEntityWrites ??
											0 }
									</p>
									<p>
										Field writes:{ ' ' }
										{ executionSummary.executedFieldWrites ??
											0 }
									</p>
									<p>
										Media writes:{ ' ' }
										{ executionSummary.executedMediaWrites ??
											0 }
									</p>
									<p>
										Deferred media:{ ' ' }
										{ executionSummary.deferredMediaCount ??
											0 }
									</p>
								</div>
							</div>
							{ renderIssueList(
								execution?.issues,
								'No execution failures were reported.',
								activeExecution.issueCount ?? 0
							) }
						</>
					) : (
						<p>
							Execute the selected package after preflight
							approval to see shared import run and rollback
							output here.
						</p>
					) }
				</article>
			</section>

			<PackageActionConfirmDialog
				action={ pendingAction }
				isBusy={ isPreflightLoading || isExecuteLoading }
				onCancel={ () => setPendingAction( null ) }
				onConfirm={ handleConfirm }
			/>
		</>
	);
}
