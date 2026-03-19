const renderReasonLabel = ( reasonCode ) =>
	`${ reasonCode || '' }`.replaceAll( '_', ' ' );

export default function ExceptionsTable( {
	items,
	isLoading,
	error,
	onOpenDrawer,
} ) {
	if ( isLoading ) {
		return (
			<div
				className="dbvc-cc-v2-placeholder-card"
				data-testid="dbvc-cc-v2-exceptions-loading"
			>
				<p>Loading the exception queue from V2 decision artifacts.</p>
			</div>
		);
	}

	if ( error ) {
		return (
			<div
				className="dbvc-cc-v2-placeholder-card"
				data-testid="dbvc-cc-v2-exceptions-error"
			>
				<p>{ error }</p>
			</div>
		);
	}

	if ( ! items.length ) {
		return (
			<div
				className="dbvc-cc-v2-placeholder-card"
				data-testid="dbvc-cc-v2-exceptions-empty"
			>
				<p>No exception rows match the current filters.</p>
			</div>
		);
	}

	return (
		<div
			className="dbvc-cc-v2-table-wrap"
			data-testid="dbvc-cc-v2-exceptions-table"
		>
			<table className="dbvc-cc-v2-table">
				<thead>
					<tr>
						<th scope="col">URL</th>
						<th scope="col">Status</th>
						<th scope="col">Target</th>
						<th scope="col">Signals</th>
						<th scope="col">Actions</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( item ) => (
						<tr
							key={ item.pageId }
							data-testid={ `dbvc-cc-v2-exception-row-${ item.pageId }` }
						>
							<td>
								<strong>{ item.path || item.pageId }</strong>
								<p className="dbvc-cc-v2-table__meta">
									{ item.sourceUrl }
								</p>
							</td>
							<td>
								<span
									className={ `dbvc-cc-v2-status-pill is-${ item.status }` }
								>
									{ item.status.replaceAll( '_', ' ' ) }
								</span>
								<p className="dbvc-cc-v2-table__meta">
									Review{ ' ' }
									{ item.reviewStatus.replaceAll( '_', ' ' ) }
								</p>
							</td>
							<td>
								<p className="dbvc-cc-v2-table__meta">
									{ item.targetObject?.label ||
										item.targetObject?.key ||
										'Unknown target' }
								</p>
								<p className="dbvc-cc-v2-table__meta">
									Resolution{ ' ' }
									{ item.resolutionMode || 'pending' }
								</p>
							</td>
							<td>
								<div className="dbvc-cc-v2-signal-stack">
									<span>
										{ item.recommendationCount } field /{ ' ' }
										{ item.mediaRecommendationCount } media
									</span>
									<span>
										{ item.unresolvedCount } unresolved /{ ' ' }
										{ item.conflictCount } conflicts
									</span>
									{ item.manualOverrideCount ? (
										<span>
											{ item.manualOverrideCount } manual
											overrides
										</span>
									) : null }
									{ Array.isArray( item.reasonCodes ) &&
									item.reasonCodes.length ? (
										<span>
											{ renderReasonLabel(
												item.reasonCodes[ 0 ]
											) }
										</span>
									) : null }
								</div>
							</td>
							<td>
								<button
									type="button"
									className="button button-secondary"
									data-testid={ `dbvc-cc-v2-open-exception-${ item.pageId }` }
									onClick={ () =>
										onOpenDrawer( item.pageId, 'summary' )
									}
								>
									Open inspector
								</button>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
