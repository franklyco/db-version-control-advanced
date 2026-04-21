import { useEffect, useState } from '@wordpress/element';

import { request } from '../api/client';

export default function useRunList( refreshToken = 0, includeHidden = false ) {
	const [ state, setState ] = useState( {
		items: [],
		meta: {},
		isLoading: true,
		error: '',
	} );

	useEffect( () => {
		let isMounted = true;
		const controller = new AbortController();

		setState( ( currentState ) => ( {
			...currentState,
			isLoading: true,
			error: '',
		} ) );

		request( includeHidden ? 'runs?includeHidden=1' : 'runs', {
			signal: controller.signal,
		} )
			.then( ( payload ) => {
				if ( ! isMounted ) {
					return;
				}

				setState( {
					items: Array.isArray( payload.items ) ? payload.items : [],
					meta:
						payload.meta && typeof payload.meta === 'object'
							? payload.meta
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
					meta: {},
					isLoading: false,
					error:
						error instanceof Error
							? error.message
							: 'Could not load V2 runs.',
				} );
			} );

		return () => {
			isMounted = false;
			controller.abort();
		};
	}, [ includeHidden, refreshToken ] );

	return state;
}
