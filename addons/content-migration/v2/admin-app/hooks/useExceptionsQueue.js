import { useDeferredValue, useEffect, useState } from '@wordpress/element';

import { request } from '../api/client';

const buildQueryString = ( route ) => {
	const params = new URLSearchParams();
	[ 'filter', 'status', 'sort' ].forEach( ( key ) => {
		if ( route[ key ] ) {
			params.set( key, route[ key ] );
		}
	} );
	if ( route.q ) {
		params.set( 'q', route.q );
	}

	const query = params.toString();
	return query ? `?${ query }` : '';
};

export default function useExceptionsQueue( runId, route, refreshToken = 0 ) {
	const deferredQuery = useDeferredValue( route.q || '' );
	const [ state, setState ] = useState( {
		items: [],
		counts: {},
		isLoading: false,
		error: '',
	} );

	useEffect( () => {
		if ( ! runId ) {
			setState( {
				items: [],
				counts: {},
				isLoading: false,
				error: '',
			} );
			return undefined;
		}

		let isMounted = true;
		const controller = new AbortController();
		const nextRoute = {
			filter: route.filter || '',
			status: route.status || '',
			sort: route.sort || '',
			q: deferredQuery,
		};

		setState( ( currentState ) => ( {
			...currentState,
			isLoading: true,
			error: '',
		} ) );

		request(
			`runs/${ runId }/exceptions${ buildQueryString( nextRoute ) }`,
			{
				signal: controller.signal,
			}
		)
			.then( ( payload ) => {
				if ( ! isMounted ) {
					return;
				}

				setState( {
					items: Array.isArray( payload.items ) ? payload.items : [],
					counts:
						payload && typeof payload.counts === 'object'
							? payload.counts
							: {},
					isLoading: false,
					error: '',
				} );
			} )
			.catch( ( error ) => {
				if ( ! isMounted || controller.signal.aborted ) {
					return;
				}

				setState( {
					items: [],
					counts: {},
					isLoading: false,
					error:
						error instanceof Error
							? error.message
							: 'Could not load the Phase 6 exception queue.',
				} );
			} );

		return () => {
			isMounted = false;
			controller.abort();
		};
	}, [
		deferredQuery,
		refreshToken,
		route.filter,
		route.sort,
		route.status,
		runId,
	] );

	return state;
}
