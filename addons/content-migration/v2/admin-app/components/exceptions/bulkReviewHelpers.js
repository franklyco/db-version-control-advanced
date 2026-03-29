const BULK_REVIEW_APPROVE = 'approve';
const BULK_REVIEW_UNRESOLVED = 'unresolved';

const formatLabel = ( value ) =>
	`${ value || '' }`
		.replaceAll( '_', ' ' )
		.replaceAll( '-', ' ' )
		.replace( /\b\w/g, ( character ) => character.toUpperCase() );

const getRecommendationIds = ( items ) =>
	Array.isArray( items )
		? items
				.map( ( item ) => item?.recommendation_id || '' )
				.filter( Boolean )
		: [];

const getRecommendedTargetObject = ( detail ) =>
	detail?.summary?.recommendedTargetObject ||
	detail?.recommendations?.recommendedTargetObject ||
	{};

const getRecommendedResolutionMode = ( detail ) => {
	const recommendedTarget = getRecommendedTargetObject( detail );

	return (
		recommendedTarget?.resolution_mode ||
		recommendedTarget?.resolutionMode ||
		detail?.summary?.resolutionMode ||
		'update_existing'
	);
};

export const BulkReviewActionKeys = {
	approve: BULK_REVIEW_APPROVE,
	unresolved: BULK_REVIEW_UNRESOLVED,
};

export function formatTargetFamilyLabel( family ) {
	if ( ! family ) {
		return 'Unknown family';
	}

	return formatLabel( family );
}

export function getBulkEligibilityReason( item ) {
	if ( ! item ) {
		return 'No review data available';
	}

	if ( item.status === 'blocked' ) {
		return 'Blocked rows need single-item review';
	}

	if ( item.conflictCount > 0 || item.hasConflicts ) {
		return 'Resolve conflicts before using bulk actions';
	}

	if ( item.unresolvedCount > 0 || item.hasUnresolved ) {
		return 'Resolve unresolved mappings before using bulk actions';
	}

	if ( item.stale ) {
		return 'Refresh stale decisions before using bulk actions';
	}

	if ( item.manualOverrideCount > 0 ) {
		return 'Manual overrides require single-item review';
	}

	if (
		Number( item.recommendationCount || 0 ) +
			Number( item.mediaRecommendationCount || 0 ) <
		1
	) {
		return 'No recommendation payload is available for bulk review';
	}

	return '';
}

export function isBulkEligibleItem( item ) {
	return getBulkEligibilityReason( item ) === '';
}

export function buildBulkTargetFamilyOptions( items = [] ) {
	const families = new Map();

	items.forEach( ( item ) => {
		if ( ! isBulkEligibleItem( item ) ) {
			return;
		}

		const family = `${ item?.targetObject?.family || '' }`.trim();
		if ( ! family ) {
			return;
		}

		const existing = families.get( family ) || {
			value: family,
			label: formatTargetFamilyLabel( family ),
			count: 0,
		};

		existing.count += 1;
		families.set( family, existing );
	} );

	return Array.from( families.values() ).sort( ( left, right ) => {
		if ( right.count !== left.count ) {
			return right.count - left.count;
		}

		return left.label.localeCompare( right.label );
	} );
}

export function buildBulkReviewPayload( detail, actionKey ) {
	const fieldRecommendationIds = getRecommendationIds(
		detail?.recommendations?.fieldRecommendations
	);
	const mediaRecommendationIds = getRecommendationIds(
		detail?.recommendations?.mediaRecommendations
	);
	const recommendedTarget = getRecommendedTargetObject( detail );
	const targetObjectKey =
		recommendedTarget?.target_object_key ||
		recommendedTarget?.targetObjectKey ||
		'';

	const targetFamily =
		recommendedTarget?.target_family ||
		recommendedTarget?.targetFamily ||
		'';
	const basePayload = {
		reviewerNote:
			actionKey === BULK_REVIEW_UNRESOLVED
				? 'Bulk queue action: keep selected recommendations unresolved.'
				: 'Bulk queue action: approve selected recommended mappings.',
		targetObject: {
			decisionMode: 'accept_recommended',
			selectedTargetFamily: targetFamily,
			selectedTargetObjectKey: targetObjectKey,
			selectedResolutionMode: getRecommendedResolutionMode( detail ),
		},
		rejectedRecommendationIds: [],
		mappingOverrides: [],
		ignoredMediaIds: [],
		mediaOverrides: [],
	};

	if ( actionKey === BULK_REVIEW_UNRESOLVED ) {
		return {
			...basePayload,
			approvedRecommendationIds: [],
			unresolvedRecommendationIds: fieldRecommendationIds,
			approvedMediaIds: [],
			conflictingMediaIds: mediaRecommendationIds,
		};
	}

	return {
		...basePayload,
		approvedRecommendationIds: fieldRecommendationIds,
		unresolvedRecommendationIds: [],
		approvedMediaIds: mediaRecommendationIds,
		conflictingMediaIds: [],
	};
}
