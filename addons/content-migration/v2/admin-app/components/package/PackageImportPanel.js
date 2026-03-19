const renderIssueList = ( items = [], fallback ) => {
	if ( ! Array.isArray( items ) || ! items.length ) {
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

export default function PackageImportPanel( {
	preflight,
	execution,
	error,
	isPreflightLoading,
	isExecuteLoading,
	onRequestPreflight,
	onExecuteImport,
	hasPackage,
} ) {
	const preflightSummary =
		preflight && typeof preflight.summary === 'object'
			? preflight.summary
			: {};
	const executionSummary =
		execution && typeof execution.summary === 'object'
			? execution.summary
			: {};
	const canExecute =
		!! preflight &&
		preflight.approvalValid &&
		! isPreflightLoading &&
		! isExecuteLoading;

	return (
		<section
			className="dbvc-cc-v2-grid dbvc-cc-v2-grid--package-detail"
			data-testid="dbvc-cc-v2-package-preflight"
		>
			<article className="dbvc-cc-v2-placeholder-card">
				<p className="dbvc-cc-v2-eyebrow">Import bridge</p>
				<h3>{ preflight?.status || 'not_started' }</h3>
				<p>
					Approval eligible:{ ' ' }
					{ preflight?.approvalEligible ? 'yes' : 'no' }
				</p>
				<p>Execute ready: { preflight?.executeReady ? 'yes' : 'no' }</p>
				<div className="dbvc-cc-v2-actions">
					<button
						type="button"
						className="button"
						data-testid="dbvc-cc-v2-package-preflight-trigger"
						disabled={
							! hasPackage ||
							isPreflightLoading ||
							isExecuteLoading
						}
						onClick={ onRequestPreflight }
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
						onClick={ onExecuteImport }
					>
						{ isExecuteLoading
							? 'Executing import…'
							: 'Execute package import' }
					</button>
				</div>
				{ error ? <p>{ error }</p> : null }
			</article>

			<article className="dbvc-cc-v2-placeholder-card">
				<p className="dbvc-cc-v2-eyebrow">Preflight summary</p>
				<p>Included pages: { preflightSummary.includedPages ?? 0 }</p>
				<p>Approved pages: { preflightSummary.approvedPages ?? 0 }</p>
				<p>Blocked pages: { preflightSummary.blockedPages ?? 0 }</p>
				<p>Write barriers: { preflightSummary.writeBarriers ?? 0 }</p>
			</article>

			<article className="dbvc-cc-v2-placeholder-card">
				<p className="dbvc-cc-v2-eyebrow">Preflight issues</p>
				{ renderIssueList(
					preflight?.issues,
					'No package-level preflight issues were reported.'
				) }
			</article>

			<article
				className="dbvc-cc-v2-placeholder-card dbvc-cc-v2-placeholder-card--full"
				data-testid="dbvc-cc-v2-package-execution"
			>
				<p className="dbvc-cc-v2-eyebrow">Execution summary</p>
				{ execution ? (
					<>
						<div className="dbvc-cc-v2-grid dbvc-cc-v2-grid--package-detail">
							<div>
								<p>Status: { execution.status || 'unknown' }</p>
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
									{ executionSummary.deferredMediaCount ?? 0 }
								</p>
							</div>
						</div>
						{ renderIssueList(
							execution.issues,
							'No execution failures were reported.'
						) }
					</>
				) : (
					<p>
						Execute the selected package after preflight approval to
						see shared import run and rollback output here.
					</p>
				) }
			</article>
		</section>
	);
}
