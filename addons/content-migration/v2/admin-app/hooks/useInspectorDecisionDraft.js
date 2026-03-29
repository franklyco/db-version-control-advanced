import { useEffect, useMemo, useState } from '@wordpress/element';

const DECISION_APPROVE = 'approve';
const DECISION_REJECT = 'reject';
const DECISION_OVERRIDE = 'override';
const DECISION_UNRESOLVED = 'unresolved';

const buildSelectionSet = ( items = [] ) => {
	const selections = new Set();

	if ( ! Array.isArray( items ) ) {
		return selections;
	}

	items.forEach( ( item ) => {
		if ( item?.recommendation_id ) {
			selections.add( item.recommendation_id );
		}
	} );

	return selections;
};

const buildOverrideMap = ( items = [] ) => {
	const overrides = new Map();

	if ( ! Array.isArray( items ) ) {
		return overrides;
	}

	items.forEach( ( item ) => {
		if (
			item?.recommendation_id &&
			! overrides.has( item.recommendation_id )
		) {
			overrides.set( item.recommendation_id, item );
		}
	} );

	return overrides;
};

const buildConflictIndex = ( conflicts = [] ) => {
	const index = new Map();

	if ( ! Array.isArray( conflicts ) ) {
		return index;
	}

	conflicts.forEach( ( conflict ) => {
		if ( ! Array.isArray( conflict?.recommendation_ids ) ) {
			return;
		}

		conflict.recommendation_ids.forEach( ( recommendationId ) => {
			if ( recommendationId && ! index.has( recommendationId ) ) {
				index.set( recommendationId, conflict );
			}
		} );
	} );

	return index;
};

const resolveSavedDecisionState = (
	recommendationId,
	approved,
	rejected,
	unresolved,
	overrides
) => {
	if ( overrides.has( recommendationId ) ) {
		return DECISION_OVERRIDE;
	}

	if ( approved.has( recommendationId ) ) {
		return DECISION_APPROVE;
	}

	if ( rejected.has( recommendationId ) ) {
		return DECISION_REJECT;
	}

	if ( unresolved.has( recommendationId ) ) {
		return DECISION_UNRESOLVED;
	}

	return '';
};

const buildDecisionItems = ( recommendations, decisions, conflicts, kind ) => {
	if ( ! Array.isArray( recommendations ) ) {
		return [];
	}

	const approved = buildSelectionSet( decisions?.approved );
	const overrides = buildOverrideMap( decisions?.overrides );
	const rejected =
		kind === 'media'
			? buildSelectionSet( decisions?.ignored )
			: buildSelectionSet( decisions?.rejected );
	const unresolved =
		kind === 'media'
			? buildSelectionSet( decisions?.conflicts )
			: buildSelectionSet( decisions?.unresolved );
	const conflictIndex = buildConflictIndex( conflicts );

	return recommendations
		.filter( ( recommendation ) => recommendation?.recommendation_id )
		.map( ( recommendation ) => {
			const recommendationId = recommendation.recommendation_id;
			const savedState = resolveSavedDecisionState(
				recommendationId,
				approved,
				rejected,
				unresolved,
				overrides
			);
			const conflict = conflictIndex.get( recommendationId ) || null;
			const override = overrides.get( recommendationId ) || null;

			return {
				recommendationId,
				kind,
				recommendation,
				state:
					savedState ||
					( conflict ? DECISION_UNRESOLVED : DECISION_APPROVE ),
				savedState,
				overrideTarget: override?.override_target || '',
				isConflicted: Boolean( conflict ),
				conflictReason: conflict?.reason || '',
				conflictTargetRef: conflict?.target_ref || '',
			};
		} );
};

const buildInitialDraft = ( detail ) => {
	const recommendations = detail?.recommendations || {};
	const mappingDecisions = detail?.decisions?.mapping || {};
	const mediaDecisions = detail?.decisions?.media || {};
	const recommendedTargetObject =
		detail?.summary?.recommendedTargetObject || {};
	const currentTargetObject = detail?.summary?.currentTargetObject || {};

	return {
		reviewerNote:
			detail?.decisions?.mapping?.reviewer_meta?.reviewer_note || '',
		selectedTargetObjectKey:
			currentTargetObject.targetObjectKey ||
			recommendedTargetObject.target_object_key ||
			'',
		selectedResolutionMode:
			currentTargetObject.resolutionMode ||
			recommendedTargetObject.resolution_mode ||
			'',
		fieldDecisions: buildDecisionItems(
			recommendations.fieldRecommendations,
			mappingDecisions,
			recommendations.conflicts,
			'field'
		),
		mediaDecisions: buildDecisionItems(
			recommendations.mediaRecommendations,
			mediaDecisions,
			recommendations.conflicts,
			'media'
		),
	};
};

const buildLatestRecommendationDraft = ( detail ) => {
	const recommendations = detail?.recommendations || {};
	const recommendedTargetObject =
		detail?.summary?.recommendedTargetObject || {};
	const resolutionMode =
		recommendedTargetObject.resolution_mode ||
		recommendedTargetObject.resolutionMode ||
		detail?.summary?.resolutionMode ||
		'update_existing';

	return {
		reviewerNote:
			detail?.decisions?.mapping?.reviewer_meta?.reviewer_note || '',
		selectedTargetObjectKey:
			recommendedTargetObject.target_object_key ||
			recommendedTargetObject.targetObjectKey ||
			'',
		selectedResolutionMode: resolutionMode,
		fieldDecisions: buildDecisionItems(
			recommendations.fieldRecommendations,
			{},
			recommendations.conflicts,
			'field'
		),
		mediaDecisions: buildDecisionItems(
			recommendations.mediaRecommendations,
			{},
			recommendations.conflicts,
			'media'
		),
	};
};

const serializeDecisionItems = ( items ) =>
	Array.isArray( items )
		? items.map( ( item ) => ( {
				recommendationId: item?.recommendationId || '',
				state: item?.state || '',
				overrideTarget: `${ item?.overrideTarget || '' }`.trim(),
		  } ) )
		: [];

const serializeDraft = ( draft ) =>
	JSON.stringify( {
		reviewerNote: `${ draft?.reviewerNote || '' }`.trim(),
		selectedTargetObjectKey: draft?.selectedTargetObjectKey || '',
		selectedResolutionMode: draft?.selectedResolutionMode || '',
		fieldDecisions: serializeDecisionItems( draft?.fieldDecisions ),
		mediaDecisions: serializeDecisionItems( draft?.mediaDecisions ),
	} );

const updateDecisionItems = ( items, recommendationId, updater ) =>
	items.map( ( item ) =>
		item.recommendationId === recommendationId ? updater( item ) : item
	);

const buildValidationErrors = ( items ) => {
	const errors = [];

	items.forEach( ( item ) => {
		if (
			item.state === DECISION_OVERRIDE &&
			! item.overrideTarget.trim()
		) {
			errors.push(
				`Add an override target for ${
					item.recommendation?.recommendation_id ||
					'the selected recommendation'
				}.`
			);
		}
	} );

	return errors;
};

export const InspectorDecisionStates = {
	approve: DECISION_APPROVE,
	reject: DECISION_REJECT,
	override: DECISION_OVERRIDE,
	unresolved: DECISION_UNRESOLVED,
};

export default function useInspectorDecisionDraft( detail ) {
	const initialDraft = useMemo(
		() => buildInitialDraft( detail ),
		[ detail ]
	);
	const latestRecommendationDraft = useMemo(
		() => buildLatestRecommendationDraft( detail ),
		[ detail ]
	);
	const [ draft, setDraft ] = useState( () => initialDraft );
	const [ savedDraft, setSavedDraft ] = useState( () => initialDraft );

	useEffect( () => {
		setDraft( initialDraft );
		setSavedDraft( initialDraft );
	}, [ initialDraft ] );

	const candidateTargetObjects =
		detail?.recommendations?.candidateTargetObjects || [];
	const recommendedTargetObject =
		detail?.summary?.recommendedTargetObject || {};
	const selectedTargetObject =
		candidateTargetObjects.find(
			( candidate ) =>
				candidate.target_object_key === draft.selectedTargetObjectKey
		) ||
		candidateTargetObjects[ 0 ] ||
		recommendedTargetObject;
	const isTargetOverride =
		Boolean( draft.selectedTargetObjectKey ) &&
		draft.selectedTargetObjectKey !==
			recommendedTargetObject.target_object_key;
	const validationErrors = [
		...buildValidationErrors( draft.fieldDecisions ),
		...buildValidationErrors( draft.mediaDecisions ),
	];
	const hasUnsavedChanges =
		serializeDraft( draft ) !== serializeDraft( savedDraft );
	const canResetToLatestRecommendations =
		serializeDraft( draft ) !== serializeDraft( latestRecommendationDraft );

	return {
		reviewerNote: draft.reviewerNote,
		selectedTargetObjectKey: draft.selectedTargetObjectKey,
		selectedResolutionMode: draft.selectedResolutionMode,
		fieldDecisions: draft.fieldDecisions,
		mediaDecisions: draft.mediaDecisions,
		candidateTargetObjects,
		selectedTargetObject,
		recommendedTargetObject,
		isTargetOverride,
		isStale: Boolean( detail?.summary?.stale ),
		hasUnsavedChanges,
		canResetToLatestRecommendations,
		validationErrors,
		canSave: validationErrors.length === 0,
		commitSavedDraft: () => setSavedDraft( draft ),
		restoreSavedDraft: () => setDraft( savedDraft ),
		resetToLatestRecommendations: () =>
			setDraft( latestRecommendationDraft ),
		setReviewerNote: ( reviewerNote ) =>
			setDraft( ( currentDraft ) => ( {
				...currentDraft,
				reviewerNote,
			} ) ),
		setSelectedTargetObjectKey: ( selectedTargetObjectKey ) =>
			setDraft( ( currentDraft ) => ( {
				...currentDraft,
				selectedTargetObjectKey,
			} ) ),
		setSelectedResolutionMode: ( selectedResolutionMode ) =>
			setDraft( ( currentDraft ) => ( {
				...currentDraft,
				selectedResolutionMode,
			} ) ),
		setDecisionState: ( kind, recommendationId, state ) =>
			setDraft( ( currentDraft ) => ( {
				...currentDraft,
				[ kind === 'media' ? 'mediaDecisions' : 'fieldDecisions' ]:
					updateDecisionItems(
						kind === 'media'
							? currentDraft.mediaDecisions
							: currentDraft.fieldDecisions,
						recommendationId,
						( item ) => ( {
							...item,
							state,
							overrideTarget:
								state === DECISION_OVERRIDE
									? item.overrideTarget
									: '',
						} )
					),
			} ) ),
		setOverrideTarget: ( kind, recommendationId, overrideTarget ) =>
			setDraft( ( currentDraft ) => ( {
				...currentDraft,
				[ kind === 'media' ? 'mediaDecisions' : 'fieldDecisions' ]:
					updateDecisionItems(
						kind === 'media'
							? currentDraft.mediaDecisions
							: currentDraft.fieldDecisions,
						recommendationId,
						( item ) => ( {
							...item,
							overrideTarget,
						} )
					),
			} ) ),
		buildSavePayload: () => ( {
			reviewerNote: draft.reviewerNote,
			targetObject: {
				decisionMode: isTargetOverride
					? 'override'
					: 'accept_recommended',
				selectedTargetFamily:
					selectedTargetObject?.target_family ||
					recommendedTargetObject.target_family ||
					'',
				selectedTargetObjectKey: draft.selectedTargetObjectKey,
				selectedResolutionMode: draft.selectedResolutionMode,
			},
			approvedRecommendationIds: draft.fieldDecisions
				.filter( ( item ) => item.state === DECISION_APPROVE )
				.map( ( item ) => item.recommendationId ),
			rejectedRecommendationIds: draft.fieldDecisions
				.filter( ( item ) => item.state === DECISION_REJECT )
				.map( ( item ) => item.recommendationId ),
			unresolvedRecommendationIds: draft.fieldDecisions
				.filter( ( item ) => item.state === DECISION_UNRESOLVED )
				.map( ( item ) => item.recommendationId ),
			mappingOverrides: draft.fieldDecisions
				.filter(
					( item ) =>
						item.state === DECISION_OVERRIDE &&
						item.overrideTarget.trim()
				)
				.map( ( item ) => ( {
					recommendationId: item.recommendationId,
					overrideScope: item.recommendation?.target_ref?.startsWith(
						'taxonomy:'
					)
						? 'taxonomy'
						: 'field',
					overrideTarget: item.overrideTarget.trim(),
				} ) ),
			approvedMediaIds: draft.mediaDecisions
				.filter( ( item ) => item.state === DECISION_APPROVE )
				.map( ( item ) => item.recommendationId ),
			ignoredMediaIds: draft.mediaDecisions
				.filter( ( item ) => item.state === DECISION_REJECT )
				.map( ( item ) => item.recommendationId ),
			conflictingMediaIds: draft.mediaDecisions
				.filter( ( item ) => item.state === DECISION_UNRESOLVED )
				.map( ( item ) => item.recommendationId ),
			mediaOverrides: draft.mediaDecisions
				.filter(
					( item ) =>
						item.state === DECISION_OVERRIDE &&
						item.overrideTarget.trim()
				)
				.map( ( item ) => ( {
					recommendationId: item.recommendationId,
					overrideTarget: item.overrideTarget.trim(),
				} ) ),
		} ),
	};
}
