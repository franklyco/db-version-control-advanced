export default function ReadinessIssuesList( { title, items, testId } ) {
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
						</li>
					) ) }
				</ul>
			) : (
				<p>No items in this group.</p>
			) }
		</article>
	);
}
