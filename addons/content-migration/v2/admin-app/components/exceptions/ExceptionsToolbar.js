import { startTransition } from '@wordpress/element';

const FILTERS = [
	{ key: 'all', label: 'All queue', countKey: 'all' },
	{ key: 'conflicts', label: 'Conflicts', countKey: 'conflicts' },
	{ key: 'unresolved', label: 'Unresolved', countKey: 'unresolved' },
	{ key: 'blocked', label: 'Blocked' },
	{ key: 'stale', label: 'Stale', countKey: 'stale' },
	{ key: 'overridden', label: 'Manual overrides', countKey: 'overridden' },
	{
		key: 'ready-after-review',
		label: 'Ready after review',
		countKey: 'readyAfterReview',
	},
];

const STATUSES = [
	{ key: '', label: 'Any state' },
	{ key: 'needs_review', label: 'Needs review' },
	{ key: 'blocked', label: 'Blocked' },
	{ key: 'completed', label: 'Completed' },
];

const getCount = ( counts, countKey ) =>
	typeof counts?.[ countKey ] === 'number' ? counts[ countKey ] : null;

export default function ExceptionsToolbar( { route, counts, onRouteChange } ) {
	return (
		<div
			className="dbvc-cc-v2-toolbar"
			data-testid="dbvc-cc-v2-exceptions-toolbar"
		>
			<p className="dbvc-cc-v2-toolbar__summary">
				Conflict and unresolved rows stay at the front of the queue so
				operators can open the right review surface directly.
			</p>
			<div className="dbvc-cc-v2-toolbar__chips">
				{ FILTERS.map( ( filter ) => (
					<button
						key={ filter.key }
						type="button"
						className={ `dbvc-cc-v2-filter-chip${
							( route.filter || 'all' ) === filter.key
								? ' is-active'
								: ''
						}` }
						data-testid={ `dbvc-cc-v2-filter-${ filter.key }` }
						onClick={ () =>
							startTransition( () =>
								onRouteChange( {
									filter: filter.key,
								} )
							)
						}
					>
						{ filter.label }
						{ getCount( counts, filter.countKey || filter.key ) !==
						null ? (
							<span>
								{ counts[ filter.countKey || filter.key ] }
							</span>
						) : null }
					</button>
				) ) }
			</div>

			<div className="dbvc-cc-v2-toolbar__controls">
				<div className="dbvc-cc-v2-toolbar__field">
					<label htmlFor="dbvc-cc-v2-status-filter">
						<span>Status</span>
					</label>
					<select
						id="dbvc-cc-v2-status-filter"
						value={ route.status || '' }
						data-testid="dbvc-cc-v2-status-filter"
						onChange={ ( event ) =>
							onRouteChange( {
								status: event.target.value,
							} )
						}
					>
						{ STATUSES.map( ( option ) => (
							<option
								key={ option.key || 'all' }
								value={ option.key }
							>
								{ option.label }
							</option>
						) ) }
					</select>
				</div>

				<div className="dbvc-cc-v2-toolbar__field dbvc-cc-v2-toolbar__field--search">
					<label htmlFor="dbvc-cc-v2-exceptions-search">
						<span>Search</span>
					</label>
					<input
						id="dbvc-cc-v2-exceptions-search"
						type="search"
						value={ route.q || '' }
						placeholder="Filter path, page ID, or target"
						data-testid="dbvc-cc-v2-exceptions-search"
						onChange={ ( event ) =>
							startTransition( () =>
								onRouteChange( {
									q: event.target.value,
								} )
							)
						}
					/>
				</div>
			</div>
		</div>
	);
}
