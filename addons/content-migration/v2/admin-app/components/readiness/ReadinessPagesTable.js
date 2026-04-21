const STATUS_LABELS = {
	ready_for_import: 'Ready',
	needs_review: 'Needs review',
	blocked: 'Blocked',
};

export default function ReadinessPagesTable( {
	items,
	onItemAction,
	onOpenDrawer,
} ) {
	return (
		<div
			className="dbvc-cc-v2-table-wrap"
			data-testid="dbvc-cc-v2-readiness-pages-table"
		>
			<table className="dbvc-cc-v2-table">
				<thead>
					<tr>
						<th>URL</th>
						<th>Status</th>
						<th>Signals</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					{ items.length ? (
						items.map( ( item ) => (
							<tr key={ item.pageId }>
								<td>
									<strong>
										{ item.path || item.pageId }
									</strong>
									<p className="dbvc-cc-v2-table__meta">
										{ item.source_url || item.sourceUrl }
									</p>
								</td>
								<td>
									<span
										className={ `dbvc-cc-v2-status-pill is-${ item.readinessStatus }` }
									>
										{ STATUS_LABELS[
											item.readinessStatus
										] || item.readinessStatus }
									</span>
								</td>
								<td>
									<div className="dbvc-cc-v2-signal-stack">
										<span>
											Quality: { item.qualityScore ?? 0 }
										</span>
										<span>
											Blockers:{ ' ' }
											{ item.blockingIssues?.length ?? 0 }
										</span>
										<span>
											Warnings:{ ' ' }
											{ item.warnings?.length ?? 0 }
										</span>
									</div>
								</td>
								<td>
									<div className="dbvc-cc-v2-actions dbvc-cc-v2-actions--stack">
										{ item.primaryAction ? (
											<button
												type="button"
												className="button button-secondary"
												data-testid={ `dbvc-cc-v2-readiness-primary-${ item.pageId }` }
												onClick={ () => {
													if (
														typeof onItemAction ===
														'function'
													) {
														onItemAction(
															item,
															item.primaryAction
														);
													}
												} }
											>
												{ item.primaryAction.label }
											</button>
										) : null }
										{ item.primaryAction?.target !==
										'readiness' ? (
											<button
												type="button"
												className="button button-link"
												data-testid={ `dbvc-cc-v2-readiness-audit-${ item.pageId }` }
												onClick={ () => {
													if (
														typeof onOpenDrawer ===
														'function'
													) {
														onOpenDrawer(
															item.pageId,
															'audit'
														);
													}
												} }
											>
												Inspect QA
											</button>
										) : null }
									</div>
								</td>
							</tr>
						) )
					) : (
						<tr>
							<td colSpan="4">No readiness rows available.</td>
						</tr>
					) }
				</tbody>
			</table>
		</div>
	);
}
