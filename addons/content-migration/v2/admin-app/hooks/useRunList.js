import { useEffect, useState } from '@wordpress/element';

import { request } from '../api/client';

export default function useRunList( refreshToken = 0 ) {
	const [ state, setState ] = useState( {
		items: [],
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

		request( 'runs', {
			signal: controller.signal,
		} )
			.then( ( payload ) => {
				if ( ! isMounted ) {
					return;
				}

				setState( {
					items: Array.isArray( payload.items ) ? payload.items : [],
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
	}, [ refreshToken ] );

	return state;
}
