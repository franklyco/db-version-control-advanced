import { useEffect, useState } from '@wordpress/element';

import { request } from '../api/client';

export default function useInspectorDetail(
	runId,
	pageId,
	isEnabled,
	refreshToken = 0
) {
	const [ state, setState ] = useState( {
		detail: null,
		isLoading: false,
		error: '',
	} );

	useEffect( () => {
		if ( ! isEnabled || ! runId || ! pageId ) {
			setState( {
				detail: null,
				isLoading: false,
				error: '',
			} );
			return undefined;
		}

		let isMounted = true;
		const controller = new AbortController();

		setState( ( currentState ) => ( {
			...currentState,
			isLoading: true,
			error: '',
		} ) );

		request( `runs/${ runId }/urls/${ pageId }`, {
			signal: controller.signal,
		} )
			.then( ( payload ) => {
				if ( ! isMounted ) {
					return;
				}

				setState( {
					detail:
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
					detail: null,
					isLoading: false,
					error:
						error instanceof Error
							? error.message
							: 'Could not load the selected URL inspector payload.',
				} );
			} );

		return () => {
			isMounted = false;
			controller.abort();
		};
	}, [ isEnabled, pageId, refreshToken, runId ] );

	return state;
}
