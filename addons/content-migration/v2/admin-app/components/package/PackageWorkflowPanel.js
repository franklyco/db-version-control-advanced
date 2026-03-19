const STAGES = [
	{
		key: 'build',
		label: 'Build',
		fallback:
			'Package build metadata will appear after the first package is created.',
	},
	{
		key: 'latestDryRun',
		label: 'Dry-run',
		fallback: 'No dry-run preview has been recorded for this package yet.',
	},
	{
		key: 'latestPreflight',
		label: 'Preflight',
		fallback:
			'No preflight approval has been requested for this package yet.',
	},
	{
		key: 'latestExecute',
		label: 'Execute',
		fallback: 'No import execution has been recorded for this package yet.',
	},
];

const renderMetrics = ( stageKey, summary = {} ) => {
	if ( stageKey === 'build' ) {
		return (
			<div className="dbvc-cc-v2-signal-stack">
				<span>Records: { summary.recordCount ?? 0 }</span>
				<span>Included pages: { summary.includedPageCount ?? 0 }</span>
				<span>Warnings: { summary.warningCount ?? 0 }</span>
			</div>
		);
	}

	if ( stageKey === 'latestDryRun' ) {
		return (
			<div className="dbvc-cc-v2-signal-stack">
				<span>Included pages: { summary.includedPages ?? 0 }</span>
				<span>Blocking issues: { summary.blockingIssues ?? 0 }</span>
				<span>Write barriers: { summary.writeBarriers ?? 0 }</span>
			</div>
		);
	}

	if ( stageKey === 'latestPreflight' ) {
		return (
			<div className="dbvc-cc-v2-signal-stack">
				<span>Approved pages: { summary.approvedPages ?? 0 }</span>
				<span>Guard failures: { summary.guardFailures ?? 0 }</span>
				<span>Write barriers: { summary.writeBarriers ?? 0 }</span>
			</div>
		);
	}

	return (
		<div className="dbvc-cc-v2-signal-stack">
			<span>Import runs: { summary.importRuns ?? 0 }</span>
			<span>Partial pages: { summary.partialPages ?? 0 }</span>
			<span>Deferred media: { summary.deferredMediaCount ?? 0 }</span>
		</div>
	);
};

export default function PackageWorkflowPanel( { workflowState } ) {
	return (
		<section
			className="dbvc-cc-v2-grid dbvc-cc-v2-grid--package-detail"
			data-testid="dbvc-cc-v2-package-workflow"
		>
			{ STAGES.map( ( stage ) => {
				const snapshot =
					workflowState && typeof workflowState === 'object'
						? workflowState[ stage.key ]
						: null;

				return (
					<article
						key={ stage.key }
						className="dbvc-cc-v2-placeholder-card"
					>
						<p className="dbvc-cc-v2-eyebrow">{ stage.label }</p>
						<h3>{ snapshot?.status || 'not_started' }</h3>
						<p>{ snapshot?.generatedAt || stage.fallback }</p>
						{ snapshot?.readinessStatus ? (
							<p>Readiness: { snapshot.readinessStatus }</p>
						) : null }
						{ snapshot?.approvalEligible ||
						snapshot?.approvalValid ||
						snapshot?.executeReady ? (
							<div className="dbvc-cc-v2-signal-stack">
								<span>
									Eligible:{ ' ' }
									{ snapshot.approvalEligible ? 'yes' : 'no' }
								</span>
								<span>
									Valid:{ ' ' }
									{ snapshot.approvalValid ? 'yes' : 'no' }
								</span>
								<span>
									Execute ready:{ ' ' }
									{ snapshot.executeReady ? 'yes' : 'no' }
								</span>
							</div>
						) : null }
						{ renderMetrics( stage.key, snapshot?.summary ) }
						<p className="dbvc-cc-v2-table__meta">
							Issues: { snapshot?.issueCount ?? 0 } / Warnings:{ ' ' }
							{ snapshot?.warningCount ?? 0 }
						</p>
					</article>
				);
			} ) }
		</section>
	);
}
