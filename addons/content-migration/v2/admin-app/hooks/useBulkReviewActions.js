import { useEffect, useMemo, useState } from '@wordpress/element';

import { request } from '../api/client';
import {
	buildBulkReviewPayload,
	buildBulkTargetFamilyOptions,
	BulkReviewActionKeys,
	isBulkEligibleItem,
} from '../components/exceptions/bulkReviewHelpers';

const isSelectionEqual = ( left, right ) => {
	if ( left.length !== right.length ) {
		return false;
	}

	return left.every( ( value, index ) => value === right[ index ] );
};

export default function useBulkReviewActions(
	runId,
	items,
	onMutationComplete
) {
	const [ selectedPageIds, setSelectedPageIds ] = useState( [] );
	const [ selectedFamily, setSelectedFamily ] = useState( '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ statusTone, setStatusTone ] = useState( 'info' );
	const [ statusMessage, setStatusMessage ] = useState( '' );

	const visibleItems = useMemo(
		() => ( Array.isArray( items ) ? items : [] ),
		[ items ]
	);
	const itemIndex = useMemo(
		() =>
			new Map(
				visibleItems
					.filter( ( item ) => item?.pageId )
					.map( ( item ) => [ item.pageId, item ] )
			),
		[ visibleItems ]
	);
	const eligibleItems = useMemo(
		() => visibleItems.filter( isBulkEligibleItem ),
		[ visibleItems ]
	);
	const eligiblePageIds = useMemo(
		() => eligibleItems.map( ( item ) => item.pageId ),
		[ eligibleItems ]
	);
	const selectedItems = useMemo(
		() =>
			selectedPageIds
				.map( ( pageId ) => itemIndex.get( pageId ) )
				.filter( Boolean ),
		[ itemIndex, selectedPageIds ]
	);
	const selectedEligibleItems = useMemo(
		() => selectedItems.filter( isBulkEligibleItem ),
		[ selectedItems ]
	);
	const selectedPageIdSet = useMemo(
		() => new Set( selectedPageIds ),
		[ selectedPageIds ]
	);
	const eligibleVisiblePageIdSet = useMemo(
		() => new Set( eligiblePageIds ),
		[ eligiblePageIds ]
	);
	const targetFamilyOptions = useMemo(
		() => buildBulkTargetFamilyOptions( eligibleItems ),
		[ eligibleItems ]
	);
	const eligibleSelectedCount = selectedEligibleItems.length;
	const allEligibleSelected =
		eligiblePageIds.length > 0 &&
		eligiblePageIds.every( ( pageId ) => selectedPageIdSet.has( pageId ) );
	const hasPartialEligibleSelection =
		eligibleSelectedCount > 0 && ! allEligibleSelected;

	useEffect( () => {
		setSelectedFamily( '' );
		setSelectedPageIds( ( currentSelection ) => {
			const nextSelection = currentSelection.filter( ( pageId ) =>
				itemIndex.has( pageId )
			);

			return isSelectionEqual( currentSelection, nextSelection )
				? currentSelection
				: nextSelection;
		} );
	}, [ itemIndex, runId ] );

	const clearSelection = () => {
		setSelectedFamily( '' );
		setSelectedPageIds( [] );
	};

	const togglePage = ( pageId ) => {
		if ( isBusy || ! eligibleVisiblePageIdSet.has( pageId ) ) {
			return;
		}

		setSelectedPageIds( ( currentSelection ) =>
			currentSelection.includes( pageId )
				? currentSelection.filter(
						( currentPageId ) => currentPageId !== pageId
				  )
				: [ ...currentSelection, pageId ]
		);
	};

	const toggleAllEligible = () => {
		if ( isBusy ) {
			return;
		}

		setSelectedFamily( '' );
		setSelectedPageIds( allEligibleSelected ? [] : eligiblePageIds );
	};

	const selectEligibleVisible = () => {
		if ( isBusy ) {
			return;
		}

		setSelectedFamily( '' );
		setSelectedPageIds( eligiblePageIds );
	};

	const selectTargetFamily = ( family ) => {
		if ( isBusy ) {
			return;
		}

		const nextFamily = `${ family || '' }`.trim();
		setSelectedFamily( nextFamily );

		if ( ! nextFamily ) {
			return;
		}

		setSelectedPageIds(
			eligibleItems
				.filter(
					( item ) =>
						`${ item?.targetObject?.family || '' }` === nextFamily
				)
				.map( ( item ) => item.pageId )
		);
	};

	const applyBulkAction = async ( actionKey ) => {
		if (
			! runId ||
			isBusy ||
			! Object.values( BulkReviewActionKeys ).includes( actionKey )
		) {
			return;
		}

		const targetItems = selectedEligibleItems;
		if ( ! targetItems.length ) {
			setStatusTone( 'error' );
			setStatusMessage(
				'Select at least one low-risk row before applying a bulk review action.'
			);
			return;
		}

		setIsBusy( true );
		setStatusTone( 'info' );
		setStatusMessage(
			`Preparing ${ actionKey } for ${ targetItems.length } selected row${
				targetItems.length === 1 ? '' : 's'
			}.`
		);

		const succeeded = [];
		const failed = [];

		for ( let index = 0; index < targetItems.length; index++ ) {
			const item = targetItems[ index ];
			const targetLabel =
				item?.path || item?.pageId || `row ${ index + 1 }`;

			setStatusMessage(
				`Applying ${ actionKey } to ${ index + 1 } of ${
					targetItems.length
				}: ${ targetLabel }`
			);

			try {
				const detail = await request(
					`runs/${ runId }/urls/${ item.pageId }`
				);
				const payload = buildBulkReviewPayload( detail, actionKey );

				await request(
					`runs/${ runId }/urls/${ item.pageId }/decision`,
					{
						method: 'POST',
						data: payload,
					}
				);

				succeeded.push( item.pageId );
			} catch ( error ) {
				failed.push( {
					pageId: item?.pageId || '',
					message:
						error instanceof Error
							? error.message
							: 'Could not save bulk review decisions.',
				} );
			}
		}

		if (
			succeeded.length > 0 &&
			typeof onMutationComplete === 'function'
		) {
			onMutationComplete();
		}

		setSelectedPageIds(
			failed.length
				? failed.map( ( failure ) => failure.pageId ).filter( Boolean )
				: []
		);
		setSelectedFamily( '' );
		setIsBusy( false );

		if ( failed.length > 0 ) {
			setStatusTone( 'error' );
			setStatusMessage(
				`Applied ${ actionKey } to ${ succeeded.length } of ${
					targetItems.length
				} selected rows. ${ failed.length } failed${
					failed[ 0 ]?.message ? `: ${ failed[ 0 ].message }` : '.'
				}`
			);
			return;
		}

		setStatusTone( 'success' );
		setStatusMessage(
			`Applied ${ actionKey } to ${ succeeded.length } selected row${
				succeeded.length === 1 ? '' : 's'
			}.`
		);
	};

	return {
		allEligibleSelected,
		applyBulkAction,
		clearSelection,
		eligibleSelectedCount,
		eligibleVisibleCount: eligibleItems.length,
		hasPartialEligibleSelection,
		isBusy,
		selectedCount: selectedPageIds.length,
		selectedFamily,
		selectedPageIdSet,
		statusMessage,
		statusTone,
		targetFamilyOptions,
		toggleAllEligible,
		togglePage,
		selectEligibleVisible,
		selectTargetFamily,
	};
}
