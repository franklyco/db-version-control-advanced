import { useEffect, useState } from '@wordpress/element';

import { request } from '../api/client';

const INITIAL_STATE = {
	preflight: null,
	execution: null,
	isPreflightLoading: false,
	isExecuteLoading: false,
	error: '',
};

export default function useImportExecutionBridge( runId, packageId ) {
	const [ state, setState ] = useState( INITIAL_STATE );

	useEffect( () => {
		setState( INITIAL_STATE );
	}, [ runId, packageId ] );

	const requestPreflight = async () => {
		if ( ! runId || ! packageId ) {
			return null;
		}

		setState( ( currentState ) => ( {
			...currentState,
			isPreflightLoading: true,
			error: '',
			execution: null,
		} ) );

		try {
			const payload = await request(
				`runs/${ runId }/preflight-approve`,
				{
					method: 'POST',
					data: {
						packageId,
						confirmApproval: true,
					},
				}
			);

			setState( ( currentState ) => ( {
				...currentState,
				preflight:
					payload && typeof payload === 'object' ? payload : null,
				isPreflightLoading: false,
				error: '',
			} ) );

			return payload;
		} catch ( error ) {
			setState( ( currentState ) => ( {
				...currentState,
				isPreflightLoading: false,
				error:
					error instanceof Error
						? error.message
						: 'Could not request V2 import preflight approval.',
			} ) );

			return null;
		}
	};

	const executeImport = async () => {
		if ( ! runId || ! packageId ) {
			return null;
		}

		const approvalTokens =
			state.preflight &&
			typeof state.preflight === 'object' &&
			state.preflight.approvalTokens &&
			typeof state.preflight.approvalTokens === 'object'
				? state.preflight.approvalTokens
				: {};

		setState( ( currentState ) => ( {
			...currentState,
			isExecuteLoading: true,
			error: '',
		} ) );

		try {
			const payload = await request( `runs/${ runId }/execute`, {
				method: 'POST',
				data: {
					packageId,
					confirmExecute: true,
					approvalTokens,
				},
			} );

			setState( ( currentState ) => ( {
				...currentState,
				execution:
					payload && typeof payload === 'object' ? payload : null,
				isExecuteLoading: false,
				error: '',
			} ) );

			return payload;
		} catch ( error ) {
			setState( ( currentState ) => ( {
				...currentState,
				isExecuteLoading: false,
				error:
					error instanceof Error
						? error.message
						: 'Could not execute the V2 package import bridge.',
			} ) );

			return null;
		}
	};

	return {
		...state,
		requestPreflight,
		executeImport,
	};
}
