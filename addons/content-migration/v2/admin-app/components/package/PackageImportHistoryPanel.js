export default function PackageImportHistoryPanel( { items = [] } ) {
	return (
		<article
			className="dbvc-cc-v2-placeholder-card dbvc-cc-v2-placeholder-card--full"
			data-testid="dbvc-cc-v2-package-import-history"
		>
			<p className="dbvc-cc-v2-eyebrow">Import history</p>
			<table className="widefat striped dbvc-cc-v2-table">
				<thead>
					<tr>
						<th>Executed</th>
						<th>Status</th>
						<th>Import runs</th>
						<th>Rollback</th>
						<th>Issues</th>
					</tr>
				</thead>
				<tbody>
					{ items.length ? (
						items.map( ( item, index ) => (
							<tr
								key={ `${
									item.generatedAt || 'import'
								}-${ index }` }
								data-testid={ `dbvc-cc-v2-package-import-history-row-${ index }` }
							>
								<td>
									<strong>
										{ item.generatedAt || 'unknown' }
									</strong>
									<p className="dbvc-cc-v2-table__meta">
										Pages:{ ' ' }
										{ item.summary?.includedPages ?? 0 }
									</p>
								</td>
								<td>
									<span
										className={ `dbvc-cc-v2-status-pill is-${
											item.status || 'unknown'
										}` }
									>
										{ item.status || 'unknown' }
									</span>
									<p className="dbvc-cc-v2-table__meta">
										Completed:{ ' ' }
										{ item.summary?.completedPages ?? 0 } /
										Partial:{ ' ' }
										{ item.summary?.partialPages ?? 0 }
									</p>
								</td>
								<td>
									<div className="dbvc-cc-v2-signal-stack">
										<span>
											Runs:{ ' ' }
											{ item.summary?.importRuns ?? 0 }
										</span>
										{ Array.isArray( item.importRuns ) &&
										item.importRuns.length ? (
											item.importRuns
												.slice( 0, 3 )
												.map( ( run ) => (
													<span
														key={
															run.runId ||
															run.runUuid
														}
													>
														#{ run.runId }{ ' ' }
														{ run.status }
													</span>
												) )
										) : (
											<span>
												No persisted import runs
											</span>
										) }
									</div>
								</td>
								<td>
									<div className="dbvc-cc-v2-signal-stack">
										<span>
											Available:{ ' ' }
											{ item.summary
												?.rollbackAvailableRuns ?? 0 }
										</span>
										{ Array.isArray( item.importRuns ) &&
										item.importRuns.length ? (
											item.importRuns
												.slice( 0, 2 )
												.map( ( run ) => (
													<span
														key={ `${ run.runId }-rollback` }
													>
														#{ run.runId }{ ' ' }
														{ run.rollbackStatus ||
															'not_started' }
													</span>
												) )
										) : (
											<span>n/a</span>
										) }
									</div>
								</td>
								<td>
									<div className="dbvc-cc-v2-signal-stack">
										<span>
											Issues: { item.issueCount ?? 0 }
										</span>
										<span>
											Warnings: { item.warningCount ?? 0 }
										</span>
										<span>
											Deferred media:{ ' ' }
											{ item.summary
												?.deferredMediaCount ?? 0 }
										</span>
									</div>
								</td>
							</tr>
						) )
					) : (
						<tr>
							<td colSpan="5">
								No package-linked import executions have been
								recorded yet.
							</td>
						</tr>
					) }
				</tbody>
			</table>
		</article>
	);
}
