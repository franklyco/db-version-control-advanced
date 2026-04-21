import { useEffect, useState } from '@wordpress/element';

import { request } from '../api/client';

const getElapsedMs = ( requestStartedAt ) => {
	if ( ! requestStartedAt ) {
		return 0;
	}

	return Math.max( 0, Date.now() - requestStartedAt );
};

const buildRequestSummary = ( payload = {}, options = {} ) => ( {
	mode:
		typeof options.mode === 'string' && options.mode
			? options.mode
			: 'create',
	sourceRunId:
		typeof options.sourceRunId === 'string' ? options.sourceRunId : '',
	domain: typeof payload.domain === 'string' ? payload.domain : '',
	sitemapUrl:
		typeof payload.sitemapUrl === 'string' ? payload.sitemapUrl : '',
	maxUrls:
		typeof payload.maxUrls === 'number' && payload.maxUrls > 0
			? payload.maxUrls
			: 0,
	forceRebuild: !! payload.forceRebuild,
	overrideCount:
		payload.crawlOverrides &&
		typeof payload.crawlOverrides === 'object' &&
		! Array.isArray( payload.crawlOverrides )
			? Object.values( payload.crawlOverrides ).filter(
					( value ) =>
						value !== '' &&
						value !== null &&
						typeof value !== 'undefined'
			  ).length
			: 0,
} );

export default function useRunCreation() {
	const [ state, setState ] = useState( {
		isSubmitting: false,
		status: 'idle',
		error: '',
		requestStartedAt: 0,
		requestFinishedAt: 0,
		elapsedMs: 0,
		lastRequest: null,
		lastCreatedRun: null,
	} );

	useEffect( () => {
		if ( ! state.isSubmitting || ! state.requestStartedAt ) {
			return undefined;
		}

		const intervalId = window.setInterval( () => {
			setState( ( currentState ) => {
				if (
					! currentState.isSubmitting ||
					! currentState.requestStartedAt
				) {
					return currentState;
				}

				return {
					...currentState,
					elapsedMs: getElapsedMs( currentState.requestStartedAt ),
				};
			} );
		}, 250 );

		return () => {
			window.clearInterval( intervalId );
		};
	}, [ state.isSubmitting, state.requestStartedAt ] );

	const clearError = () => {
		setState( ( currentState ) => ( {
			...currentState,
			error: '',
		} ) );
	};

	const createRun = async ( payload, options = {} ) => {
		const requestStartedAt = Date.now();
		const lastRequest = buildRequestSummary( payload, options );

		setState( {
			isSubmitting: true,
			status: 'submitting',
			error: '',
			requestStartedAt,
			requestFinishedAt: 0,
			elapsedMs: 0,
			lastRequest,
			lastCreatedRun: null,
		} );

		try {
			const created = await request( 'runs', {
				method: 'POST',
				data: payload,
			} );
			const requestFinishedAt = Date.now();

			setState( {
				isSubmitting: false,
				status: 'success',
				error: '',
				requestStartedAt,
				requestFinishedAt,
				elapsedMs: requestFinishedAt - requestStartedAt,
				lastRequest,
				lastCreatedRun: created,
			} );

			return created;
		} catch ( error ) {
			const requestFinishedAt = Date.now();

			setState( {
				isSubmitting: false,
				status: 'error',
				error:
					error instanceof Error
						? error.message
						: 'Could not start the V2 run.',
				requestStartedAt,
				requestFinishedAt,
				elapsedMs: requestFinishedAt - requestStartedAt,
				lastRequest,
				lastCreatedRun: null,
			} );

			return null;
		}
	};

	return {
		createRun,
		clearError,
		elapsedMs: state.elapsedMs,
		error: state.error,
		isSubmitting: state.isSubmitting,
		lastCreatedRun: state.lastCreatedRun,
		lastRequest: state.lastRequest,
		requestFinishedAt: state.requestFinishedAt,
		requestStartedAt: state.requestStartedAt,
		status: state.status,
	};
}
