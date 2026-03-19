import { useEffect, useState } from '@wordpress/element';

import { request } from '../api/client';

const INITIAL_STATE = {
	readinessStatus: '',
	readiness: null,
	history: [],
	selectedPackageId: '',
	selectedPackage: null,
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
		? `runs/${ runId }/package?${ queryString }`
		: `runs/${ runId }/package`;
};

export default function usePackageSurface(
	runId,
	packageId,
	refreshToken = 0
) {
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

		request( buildPath( runId, packageId ), {
			signal: controller.signal,
		} )
			.then( ( payload ) => {
				if ( ! isMounted ) {
					return;
				}

				setState( {
					readinessStatus: payload.readinessStatus || '',
					readiness:
						payload.readiness &&
						typeof payload.readiness === 'object'
							? payload.readiness
							: null,
					history: Array.isArray( payload.history )
						? payload.history
						: [],
					selectedPackageId: payload.selectedPackageId || '',
					selectedPackage:
						payload.selectedPackage &&
						typeof payload.selectedPackage === 'object'
							? payload.selectedPackage
							: null,
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
							: 'Could not load V2 package state.',
				} );
			} );

		return () => {
			isMounted = false;
			controller.abort();
		};
	}, [ runId, packageId, refreshToken ] );

	return state;
}
