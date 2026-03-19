export default function PackageHistoryTable( {
	items,
	selectedPackageId,
	onSelectPackage,
} ) {
	return (
		<div
			className="dbvc-cc-v2-table-wrap"
			data-testid="dbvc-cc-v2-package-history-table"
		>
			<table className="dbvc-cc-v2-table">
				<thead>
					<tr>
						<th>Package</th>
						<th>Status</th>
						<th>Counts</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					{ items.length ? (
						items.map( ( item ) => (
							<tr key={ item.package_id }>
								<td>
									<strong>{ item.package_id }</strong>
									<p className="dbvc-cc-v2-table__meta">
										Built { item.built_at || 'unknown' }
									</p>
								</td>
								<td>
									<span
										className={ `dbvc-cc-v2-status-pill is-${ item.readiness_status }` }
									>
										{ item.readiness_status || 'unknown' }
									</span>
								</td>
								<td>
									<div className="dbvc-cc-v2-signal-stack">
										<span>
											Records: { item.record_count ?? 0 }
										</span>
										<span>
											Included pages:{ ' ' }
											{ item.included_page_count ?? 0 }
										</span>
										<span>
											Warnings:{ ' ' }
											{ item.warning_count ?? 0 }
										</span>
										{ item.workflowSummary
											?.executeStatus ? (
											<span>
												Execute:{ ' ' }
												{
													item.workflowSummary
														.executeStatus
												}
											</span>
										) : null }
										{ item.workflowSummary
											?.importRunCount ? (
											<span>
												Import runs:{ ' ' }
												{
													item.workflowSummary
														.importRunCount
												}
											</span>
										) : null }
									</div>
								</td>
								<td>
									<button
										type="button"
										className="button button-secondary"
										data-testid={ `dbvc-cc-v2-package-select-${ item.package_id }` }
										disabled={
											selectedPackageId ===
											item.package_id
										}
										onClick={ () =>
											onSelectPackage( item.package_id )
										}
									>
										{ selectedPackageId === item.package_id
											? 'Selected'
											: 'View package' }
									</button>
								</td>
							</tr>
						) )
					) : (
						<tr>
							<td colSpan="4">
								No package builds have been generated yet.
							</td>
						</tr>
					) }
				</tbody>
			</table>
		</div>
	);
}
