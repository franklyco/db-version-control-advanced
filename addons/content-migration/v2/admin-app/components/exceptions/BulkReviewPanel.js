import { BulkReviewActionKeys } from './bulkReviewHelpers';

export default function BulkReviewPanel( { bulkReview, onBulkAction } ) {
	const {
		allEligibleSelected,
		clearSelection,
		eligibleSelectedCount,
		eligibleVisibleCount,
		hasPartialEligibleSelection,
		isBusy,
		selectedCount,
		selectedFamily,
		selectEligibleVisible,
		selectTargetFamily,
		statusMessage,
		statusTone,
		targetFamilyOptions,
		toggleAllEligible,
	} = bulkReview;

	return (
		<section
			className="dbvc-cc-v2-bulk-panel"
			data-testid="dbvc-cc-v2-bulk-review-panel"
		>
			<div className="dbvc-cc-v2-bulk-panel__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Bulk review</p>
					<h3>Low-risk queue helpers</h3>
					<p className="dbvc-cc-v2-toolbar__summary">
						Bulk actions stay conservative. They only apply to
						conflict-free, non-stale rows without manual overrides,
						and each selected URL still saves through the normal V2
						decision endpoint.
					</p>
				</div>
				<div className="dbvc-cc-v2-bulk-panel__summary">
					<strong data-testid="dbvc-cc-v2-bulk-selection-summary">
						{ selectedCount } selected
					</strong>
					<span>{ eligibleSelectedCount } low-risk</span>
					<span>{ eligibleVisibleCount } eligible in view</span>
				</div>
			</div>

			<div className="dbvc-cc-v2-bulk-panel__controls">
				<div className="dbvc-cc-v2-rerun-row">
					<button
						type="button"
						className="button button-secondary"
						disabled={ isBusy || eligibleVisibleCount < 1 }
						data-testid="dbvc-cc-v2-bulk-select-all"
						aria-pressed={
							allEligibleSelected && ! hasPartialEligibleSelection
						}
						onClick={ toggleAllEligible }
					>
						{ allEligibleSelected && ! hasPartialEligibleSelection
							? 'Clear eligible selection'
							: 'Select all eligible' }
					</button>
					<button
						type="button"
						className="button button-secondary"
						disabled={ isBusy || eligibleVisibleCount < 1 }
						data-testid="dbvc-cc-v2-bulk-select-eligible"
						onClick={ selectEligibleVisible }
					>
						Select low-risk visible
					</button>
					<button
						type="button"
						className="button button-link"
						disabled={ isBusy || selectedCount < 1 }
						data-testid="dbvc-cc-v2-bulk-clear-selection"
						onClick={ clearSelection }
					>
						Clear selection
					</button>
				</div>

				<div className="dbvc-cc-v2-bulk-panel__family">
					<label htmlFor="dbvc-cc-v2-bulk-family-select">
						<span>Target family helper</span>
					</label>
					<div className="dbvc-cc-v2-rerun-row">
						<select
							id="dbvc-cc-v2-bulk-family-select"
							value={ selectedFamily || '' }
							disabled={
								isBusy || targetFamilyOptions.length < 1
							}
							data-testid="dbvc-cc-v2-bulk-family-select"
							onChange={ ( event ) =>
								selectTargetFamily( event.target.value )
							}
						>
							<option value="">Select a target family</option>
							{ targetFamilyOptions.map( ( option ) => (
								<option
									key={ option.value }
									value={ option.value }
								>
									{ option.label } ({ option.count })
								</option>
							) ) }
						</select>
						<button
							type="button"
							className="button button-secondary"
							disabled={ isBusy || ! selectedFamily }
							data-testid="dbvc-cc-v2-bulk-select-family"
							onClick={ () =>
								selectTargetFamily( selectedFamily )
							}
						>
							Select family rows
						</button>
					</div>
				</div>

				<div className="dbvc-cc-v2-rerun-row">
					<button
						type="button"
						className="button button-primary"
						disabled={ isBusy || eligibleSelectedCount < 1 }
						data-testid="dbvc-cc-v2-bulk-approve"
						onClick={ () =>
							onBulkAction( BulkReviewActionKeys.approve )
						}
					>
						Approve selected
					</button>
					<button
						type="button"
						className="button button-secondary"
						disabled={ isBusy || eligibleSelectedCount < 1 }
						data-testid="dbvc-cc-v2-bulk-unresolved"
						onClick={ () =>
							onBulkAction( BulkReviewActionKeys.unresolved )
						}
					>
						Leave selected unresolved
					</button>
				</div>
			</div>

			{ statusMessage ? (
				<p
					className={ `dbvc-cc-v2-bulk-panel__status dbvc-cc-v2-bulk-panel__status--${ statusTone }` }
					data-testid="dbvc-cc-v2-bulk-status"
				>
					{ statusMessage }
				</p>
			) : null }
		</section>
	);
}
