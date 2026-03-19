import { useEffect, useState } from '@wordpress/element';

import { request } from '../api/client';

const INITIAL_STATE = {
	readinessStatus: '',
	summary: {},
	blockingIssues: [],
	warnings: [],
	pageReports: [],
	schemaFingerprint: '',
	isLoading: false,
	error: '',
};

export default function useRunReadiness( runId, refreshToken = 0 ) {
	const [ state, setState ] = useState( INITIAL_STATE );

	useEffect( () => {
		if ( ! runId ) {
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

		request( `runs/${ runId }/readiness`, {
			signal: controller.signal,
		} )
			.then( ( payload ) => {
				if ( ! isMounted ) {
					return;
				}

				setState( {
					readinessStatus: payload.readinessStatus || '',
					summary:
						payload.summary && typeof payload.summary === 'object'
							? payload.summary
							: {},
					blockingIssues: Array.isArray( payload.blockingIssues )
						? payload.blockingIssues
						: [],
					warnings: Array.isArray( payload.warnings )
						? payload.warnings
						: [],
					pageReports: Array.isArray( payload.pageReports )
						? payload.pageReports
						: [],
					schemaFingerprint: payload.schemaFingerprint || '',
					isLoading: false,
					error: '',
				} );
			} )
			.catch( ( error ) => {
				if ( ! isMounted || controller.signal.aborted ) {
					return;
				}

				setState( {
					...INITIAL_STATE,
					isLoading: false,
					error:
						error instanceof Error
							? error.message
							: 'Could not load V2 readiness.',
				} );
			} );

		return () => {
			isMounted = false;
			controller.abort();
		};
	}, [ runId, refreshToken ] );

	return state;
}
