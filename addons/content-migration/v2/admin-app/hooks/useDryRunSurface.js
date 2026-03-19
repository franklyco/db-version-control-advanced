import { useEffect, useState } from '@wordpress/element';

import { request } from '../api/client';

const INITIAL_STATE = {
	data: null,
	isLoading: false,
	error: '',
};

const buildPath = ( runId, packageId ) => {
	const params = new URLSearchParams();
	if ( packageId ) {
		params.set( 'packageId', packageId );
	}

	const queryString = params.toString();
	return queryString
		? `runs/${ runId }/dry-run?${ queryString }`
		: `runs/${ runId }/dry-run`;
};

export default function useDryRunSurface( runId, packageId, requestToken = 0 ) {
	const [ state, setState ] = useState( INITIAL_STATE );

	useEffect( () => {
		if ( ! runId || ! packageId || requestToken <= 0 ) {
			setState( INITIAL_STATE );
			return undefined;
		}

		let isMounted = true;
		const controller = new AbortController();

		setState( ( currentState ) => ( {
			...currentState,
			isLoading: true,
			error: '',
		} ) );

		request( buildPath( runId, packageId ), {
			signal: controller.signal,
		} )
			.then( ( payload ) => {
				if ( ! isMounted ) {
					return;
				}

				setState( {
					data:
						payload && typeof payload === 'object' ? payload : null,
					isLoading: false,
					error: '',
				} );
			} )
			.catch( ( error ) => {
				if ( ! isMounted || controller.signal.aborted ) {
					return;
				}

				setState( {
					data: null,
					isLoading: false,
					error:
						error instanceof Error
							? error.message
							: 'Could not load the V2 dry-run surface.',
				} );
			} );

		return () => {
			isMounted = false;
			controller.abort();
		};
	}, [ runId, packageId, requestToken ] );

	return state;
}
