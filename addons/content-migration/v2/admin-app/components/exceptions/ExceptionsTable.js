import { useEffect, useRef } from '@wordpress/element';

import {
	getBulkEligibilityReason,
	isBulkEligibleItem,
} from './bulkReviewHelpers';

function renderReasonLabel( reasonCode ) {
	return `${ reasonCode || '' }`.replaceAll( '_', ' ' );
}

function getQueueState( item ) {
	return item.queueState || item.status || 'needs_review';
}

function getQueueStateLabel( item ) {
	return (
		item.queueStateLabel ||
		`${ getQueueState( item ) }`.replaceAll( '_', ' ' )
	);
}

function EligibleSelectionCheckbox( {
	checked,
	disabled,
	indeterminate,
	label,
	onChange,
	testId,
} ) {
	const inputRef = useRef( null );

	useEffect( () => {
		if ( inputRef.current ) {
			inputRef.current.indeterminate = indeterminate;
		}
	}, [ indeterminate ] );

	return (
		<input
			ref={ inputRef }
			type="checkbox"
			checked={ checked }
			disabled={ disabled }
			aria-label={ label }
			data-testid={ testId }
			onChange={ onChange }
		/>
	);
}

export default function ExceptionsTable( {
	bulkReview,
	items,
	isLoading,
	error,
	onOpenDrawer,
} ) {
	if ( isLoading ) {
		return (
			<div
				className="dbvc-cc-v2-placeholder-card"
				data-testid="dbvc-cc-v2-exceptions-loading"
			>
				<p>Loading the exception queue from V2 decision artifacts.</p>
			</div>
		);
	}

	if ( error ) {
		return (
			<div
				className="dbvc-cc-v2-placeholder-card"
				data-testid="dbvc-cc-v2-exceptions-error"
			>
				<p>{ error }</p>
			</div>
		);
	}

	if ( ! items.length ) {
		return (
			<div
				className="dbvc-cc-v2-placeholder-card"
				data-testid="dbvc-cc-v2-exceptions-empty"
			>
				<p>No exception rows match the current filters.</p>
			</div>
		);
	}

	return (
		<div
			className="dbvc-cc-v2-table-wrap"
			data-testid="dbvc-cc-v2-exceptions-table"
		>
			<table className="dbvc-cc-v2-table">
				<thead>
					<tr>
						<th scope="col">
							<EligibleSelectionCheckbox
								checked={ bulkReview.allEligibleSelected }
								disabled={
									bulkReview.isBusy ||
									bulkReview.eligibleVisibleCount < 1
								}
								indeterminate={
									bulkReview.hasPartialEligibleSelection
								}
								label="Select all eligible exception rows"
								testId="dbvc-cc-v2-exception-select-all-visible"
								onChange={ bulkReview.toggleAllEligible }
							/>
						</th>
						<th scope="col">URL</th>
						<th scope="col">Queue state</th>
						<th scope="col">Target</th>
						<th scope="col">Signals</th>
						<th scope="col">Actions</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( item ) => (
						<tr
							key={ item.pageId }
							data-testid={ `dbvc-cc-v2-exception-row-${ item.pageId }` }
						>
							<td className="dbvc-cc-v2-table__select">
								{ isBulkEligibleItem( item ) ? (
									<EligibleSelectionCheckbox
										checked={ bulkReview.selectedPageIdSet.has(
											item.pageId
										) }
										disabled={ bulkReview.isBusy }
										indeterminate={ false }
										label={ `Select ${
											item.path || item.pageId
										} for bulk review` }
										testId={ `dbvc-cc-v2-exception-select-${ item.pageId }` }
										onChange={ () =>
											bulkReview.togglePage( item.pageId )
										}
									/>
								) : (
									<span className="dbvc-cc-v2-table__meta">
										{ getBulkEligibilityReason( item ) }
									</span>
								) }
							</td>
							<td>
								<strong>{ item.path || item.pageId }</strong>
								<p className="dbvc-cc-v2-table__meta">
									{ item.sourceUrl }
								</p>
							</td>
							<td>
								<span
									className={ `dbvc-cc-v2-status-pill is-${ getQueueState(
										item
									) }` }
								>
									{ getQueueStateLabel( item ) }
								</span>
								<p className="dbvc-cc-v2-table__meta">
									Review{ ' ' }
									{ item.reviewStatus.replaceAll( '_', ' ' ) }{ ' ' }
									/ Decision{ ' ' }
									{ item.decisionStatus.replaceAll(
										'_',
										' '
									) }
								</p>
							</td>
							<td>
								<p className="dbvc-cc-v2-table__meta">
									{ item.targetObject?.label ||
										item.targetObject?.key ||
										'Unknown target' }
								</p>
								<p className="dbvc-cc-v2-table__meta">
									Resolution{ ' ' }
									{ item.resolutionMode || 'pending' }
								</p>
							</td>
							<td>
								<div className="dbvc-cc-v2-signal-stack">
									<span>
										{ item.recommendationCount } field /{ ' ' }
										{ item.mediaRecommendationCount } media
									</span>
									<span>
										{ item.unresolvedCount } unresolved /{ ' ' }
										{ item.conflictCount } conflicts
									</span>
									{ item.manualOverrideCount ? (
										<span>
											{ item.manualOverrideCount } manual
											overrides
										</span>
									) : null }
									{ item.stale ? (
										<span>
											Recommendation drift detected
										</span>
									) : null }
									{ Array.isArray( item.reasonCodes ) &&
									item.reasonCodes.length ? (
										<span>
											Primary signal:{ ' ' }
											{ renderReasonLabel(
												item.reasonCodes[ 0 ]
											) }
										</span>
									) : null }
								</div>
							</td>
							<td>
								<div className="dbvc-cc-v2-table__actions">
									<button
										type="button"
										className="button button-secondary"
										data-testid={ `dbvc-cc-v2-open-exception-${ item.pageId }` }
										onClick={ () =>
											onOpenDrawer(
												item.pageId,
												item.quickAction?.panelTab ||
													'mapping'
											)
										}
									>
										{ item.quickAction?.label ||
											'Review recommendations' }
									</button>
									<button
										type="button"
										className="button button-link"
										data-testid={ `dbvc-cc-v2-open-exception-summary-${ item.pageId }` }
										onClick={ () =>
											onOpenDrawer(
												item.pageId,
												'summary'
											)
										}
									>
										Open summary
									</button>
								</div>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
