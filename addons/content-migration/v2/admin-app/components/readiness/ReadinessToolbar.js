import { startTransition } from '@wordpress/element';

import { READINESS_FILTERS } from './readinessActions';

export default function ReadinessToolbar( { route, counts, onRouteChange } ) {
	const activeFilter = READINESS_FILTERS.some(
		( filter ) => filter.key === route.filter
	)
		? route.filter
		: 'all';

	return (
		<div
			className="dbvc-cc-v2-toolbar"
			data-testid="dbvc-cc-v2-readiness-toolbar"
		>
			<p className="dbvc-cc-v2-toolbar__summary">
				Readiness rows can now jump straight into the review queue, a QA
				blocker audit, or the package workspace from the blocker that is
				currently holding them back.
			</p>

			<div className="dbvc-cc-v2-toolbar__chips">
				{ READINESS_FILTERS.map( ( filter ) => (
					<button
						key={ filter.key }
						type="button"
						className={ `dbvc-cc-v2-filter-chip${
							activeFilter === filter.key ? ' is-active' : ''
						}` }
						data-testid={ `dbvc-cc-v2-readiness-filter-${ filter.key }` }
						onClick={ () => {
							if ( typeof onRouteChange !== 'function' ) {
								return;
							}

							startTransition( () =>
								onRouteChange( {
									filter: filter.key,
								} )
							);
						} }
					>
						{ filter.label }
						<span>{ counts?.[ filter.countKey ] ?? 0 }</span>
					</button>
				) ) }
			</div>
		</div>
	);
}
