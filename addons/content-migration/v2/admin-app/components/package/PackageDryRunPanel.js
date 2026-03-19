export default function PackageDryRunPanel( { dryRunSurface } ) {
	if ( ! dryRunSurface ) {
		return (
			<div
				className="dbvc-cc-v2-placeholder-card"
				data-testid="dbvc-cc-v2-package-dry-run"
			>
				<h3>Dry-run preview</h3>
				<p>
					Run the package-first dry-run to validate the selected
					package against the downstream import planner.
				</p>
			</div>
		);
	}

	const summary = dryRunSurface.summary || {};
	const pageExecutions = Array.isArray( dryRunSurface.pageExecutions )
		? dryRunSurface.pageExecutions
		: [];
	const issues = Array.isArray( dryRunSurface.issues )
		? dryRunSurface.issues
		: [];

	return (
		<section
			className="dbvc-cc-v2-grid dbvc-cc-v2-grid--package-detail"
			data-testid="dbvc-cc-v2-package-dry-run"
		>
			<article className="dbvc-cc-v2-placeholder-card">
				<p className="dbvc-cc-v2-eyebrow">Dry-run status</p>
				<h3>{ dryRunSurface.status || 'unknown' }</h3>
				<p>
					Package readiness:{ ' ' }
					{ dryRunSurface.readinessStatus || 'n/a' }
				</p>
				<p>Included pages: { summary.includedPages ?? 0 }</p>
				<p>Completed pages: { summary.completedPages ?? 0 }</p>
			</article>

			<article className="dbvc-cc-v2-placeholder-card">
				<p className="dbvc-cc-v2-eyebrow">Simulation totals</p>
				<p>Operations: { summary.simulatedOperations ?? 0 }</p>
				<p>Dependency edges: { summary.dependencyEdges ?? 0 }</p>
				<p>Blocking issues: { summary.blockingIssues ?? 0 }</p>
				<p>Write barriers: { summary.writeBarriers ?? 0 }</p>
			</article>

			<article className="dbvc-cc-v2-placeholder-card">
				<p className="dbvc-cc-v2-eyebrow">Bridge issues</p>
				{ issues.length ? (
					<ul className="dbvc-cc-v2-inspector-list">
						{ issues.slice( 0, 5 ).map( ( issue, index ) => (
							<li key={ `${ issue.code || 'issue' }-${ index }` }>
								<strong>{ issue.code || 'issue' }</strong>
								<p className="dbvc-cc-v2-table__meta">
									{ issue.message ||
										'Dry-run issue reported.' }
								</p>
							</li>
						) ) }
					</ul>
				) : (
					<p>No dry-run blockers were reported by the bridge.</p>
				) }
			</article>

			<article className="dbvc-cc-v2-placeholder-card dbvc-cc-v2-placeholder-card--full">
				<p className="dbvc-cc-v2-eyebrow">Per-page preview</p>
				<table
					className="widefat striped dbvc-cc-v2-table"
					data-testid="dbvc-cc-v2-package-dry-run-table"
				>
					<thead>
						<tr>
							<th>Page</th>
							<th>Status</th>
							<th>Ops</th>
							<th>Issues</th>
						</tr>
					</thead>
					<tbody>
						{ pageExecutions.length ? (
							pageExecutions.map( ( pageExecution ) => (
								<tr
									key={
										pageExecution.pageId ||
										pageExecution.path
									}
								>
									<td>
										<strong>
											{ pageExecution.path ||
												'Unknown path' }
										</strong>
										<p className="dbvc-cc-v2-table__meta">
											{ pageExecution.pageId ||
												'no-page-id' }
										</p>
									</td>
									<td>
										{ pageExecution.status || 'unknown' }
									</td>
									<td>
										{ pageExecution.operationCounts
											?.totalSimulated ?? 0 }
									</td>
									<td>
										{ pageExecution.blockingIssueCount ??
											0 }
										{ pageExecution.operationCounts
											?.writeBarrierCount
											? ` / ${ pageExecution.operationCounts.writeBarrierCount } barriers`
											: '' }
									</td>
								</tr>
							) )
						) : (
							<tr>
								<td colSpan="4">
									No page simulations were returned for this
									package.
								</td>
							</tr>
						) }
					</tbody>
				</table>
			</article>
		</section>
	);
}
