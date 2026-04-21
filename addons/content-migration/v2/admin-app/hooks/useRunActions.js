import { useState } from '@wordpress/element';

import { request } from '../api/client';

const DEFAULT_STATE = {
	isSubmitting: false,
	runId: '',
	actionType: '',
	stage: '',
	label: '',
	error: '',
	progress: null,
	lastCompletedAction: null,
	lastRecoveryAction: null,
};

export default function useRunActions() {
	const [ state, setState ] = useState( DEFAULT_STATE );

	const clearStatus = ( options = {} ) => {
		const preserveRecovery = !! options.preserveRecovery;

		setState( ( currentState ) => ( {
			...currentState,
			error: '',
			lastCompletedAction: null,
			lastRecoveryAction: preserveRecovery
				? currentState.lastRecoveryAction
				: null,
		} ) );
	};

	const setRunVisibility = async ( runId, hidden ) => {
		const normalizedRunId =
			typeof runId === 'string' ? runId.trim() : `${ runId || '' }`;
		if ( ! normalizedRunId ) {
			return null;
		}

		setState( ( currentState ) => ( {
			...currentState,
			isSubmitting: true,
			runId: normalizedRunId,
			actionType: hidden ? 'hide' : 'restore',
			stage: '',
			label: hidden ? 'Hiding run' : 'Restoring run',
			error: '',
			progress: null,
			lastCompletedAction: null,
		} ) );

		try {
			const payload = await request(
				`runs/${ normalizedRunId }/visibility`,
				{
					method: 'POST',
					data: {
						hidden,
					},
				}
			);

			setState( ( currentState ) => ( {
				...currentState,
				isSubmitting: false,
				runId: normalizedRunId,
				actionType: hidden ? 'hide' : 'restore',
				stage: '',
				label: hidden ? 'Hiding run' : 'Restoring run',
				error: '',
				progress: null,
				lastCompletedAction: {
					type: hidden ? 'hide' : 'restore',
					runId: normalizedRunId,
					hidden: !! payload.hidden,
				},
			} ) );

			return payload;
		} catch ( error ) {
			setState( ( currentState ) => ( {
				...currentState,
				isSubmitting: false,
				runId: normalizedRunId,
				actionType: hidden ? 'hide' : 'restore',
				stage: '',
				label: hidden ? 'Hiding run' : 'Restoring run',
				error:
					error instanceof Error
						? error.message
						: 'Could not update run visibility.',
				progress: null,
				lastCompletedAction: null,
			} ) );

			return null;
		}
	};

	const rerunStageGroup = async ( runId, candidate ) => {
		const normalizedRunId =
			typeof runId === 'string' ? runId.trim() : `${ runId || '' }`;
		const stage =
			candidate && typeof candidate.stage === 'string'
				? candidate.stage
				: '';
		const label =
			candidate && typeof candidate.label === 'string'
				? candidate.label
				: 'Rerun stage';
		const pageIds =
			candidate &&
			Array.isArray( candidate.pageIds ) &&
			candidate.pageIds.length
				? candidate.pageIds.filter(
						( pageId ) =>
							typeof pageId === 'string' && pageId.trim() !== ''
				  )
				: [];

		if ( ! normalizedRunId || ! stage || ! pageIds.length ) {
			return null;
		}

		setState( ( currentState ) => ( {
			...currentState,
			isSubmitting: true,
			runId: normalizedRunId,
			actionType: 'rerun',
			stage,
			label,
			error: '',
			progress: {
				current: 0,
				total: pageIds.length,
			},
			lastCompletedAction: null,
		} ) );

		let completedCount = 0;
		let failedCount = 0;
		const failures = [];

		for ( let index = 0; index < pageIds.length; index += 1 ) {
			const pageId = pageIds[ index ];

			try {
				await request(
					`runs/${ normalizedRunId }/urls/${ pageId }/rerun`,
					{
						method: 'POST',
						data: {
							stage,
						},
					}
				);
				completedCount += 1;
			} catch ( error ) {
				failedCount += 1;
				failures.push(
					error instanceof Error
						? error.message
						: `Could not rerun ${ pageId }.`
				);
			}

			setState( ( currentState ) => ( {
				...currentState,
				progress: {
					current: index + 1,
					total: pageIds.length,
				},
			} ) );
		}

		setState( ( currentState ) => ( {
			...currentState,
			isSubmitting: false,
			runId: normalizedRunId,
			actionType: 'rerun',
			stage,
			label,
			error: failures.length ? failures[ 0 ] : '',
			progress: {
				current: pageIds.length,
				total: pageIds.length,
			},
			lastCompletedAction: {
				type: 'rerun',
				runId: normalizedRunId,
				stage,
				label,
				completedCount,
				failedCount,
				totalCount: pageIds.length,
			},
			lastRecoveryAction: {
				type: 'rerun',
				runId: normalizedRunId,
				stage,
				label,
				completedCount,
				failedCount,
				totalCount: pageIds.length,
			},
		} ) );

		return {
			completedCount,
			failedCount,
			totalCount: pageIds.length,
		};
	};

	return {
		clearStatus,
		error: state.error,
		isSubmitting: state.isSubmitting,
		label: state.label,
		lastCompletedAction: state.lastCompletedAction,
		lastRecoveryAction: state.lastRecoveryAction,
		progress: state.progress,
		rerunStageGroup,
		runId: state.runId,
		setRunVisibility,
		stage: state.stage,
		type: state.actionType,
	};
}
