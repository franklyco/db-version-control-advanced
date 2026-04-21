export default function ReadinessIssuesList( {
	title,
	items,
	testId,
	onItemAction,
} ) {
	return (
		<article className="dbvc-cc-v2-placeholder-card" data-testid={ testId }>
			<h3>{ title }</h3>
			{ items.length ? (
				<ul className="dbvc-cc-v2-inspector-list">
					{ items.map( ( item, index ) => (
						<li key={ `${ item.code || 'issue' }-${ index }` }>
							<strong>{ item.code || 'issue' }</strong>
							<p className="dbvc-cc-v2-table__meta">
								{ item.message || 'No message available.' }
							</p>
							{ item.path ? (
								<p className="dbvc-cc-v2-table__meta">
									{ item.path }
								</p>
							) : null }
							{ item.action ? (
								<div className="dbvc-cc-v2-actions">
									<button
										type="button"
										className="button button-secondary"
										data-testid={ `dbvc-cc-v2-readiness-issue-action-${
											item.pageId || index
										}` }
										onClick={ () => {
											if (
												typeof onItemAction ===
												'function'
											) {
												onItemAction(
													item,
													item.action
												);
											}
										} }
									>
										{ item.action.label }
									</button>
								</div>
							) : null }
						</li>
					) ) }
				</ul>
			) : (
				<p>No items in this group.</p>
			) }
		</article>
	);
}
