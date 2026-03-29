import RecommendationDecisionCard from './RecommendationDecisionCard';

export default function InspectorActionPanel( { decisionDraft } ) {
	const {
		canResetToLatestRecommendations,
		candidateTargetObjects,
		fieldDecisions,
		hasUnsavedChanges,
		isStale,
		mediaDecisions,
		recommendedTargetObject,
		resetToLatestRecommendations,
		restoreSavedDraft,
		reviewerNote,
		selectedResolutionMode,
		selectedTargetObject,
		selectedTargetObjectKey,
		setDecisionState,
		setOverrideTarget,
		setReviewerNote,
		setSelectedResolutionMode,
		setSelectedTargetObjectKey,
		validationErrors,
	} = decisionDraft;

	return (
		<div
			className="dbvc-cc-v2-action-panel"
			data-testid="dbvc-cc-v2-inspector-actions"
		>
			<div className="dbvc-cc-v2-action-panel__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Review Actions</p>
					<h3>Single-item decisions, target overrides, and reruns</h3>
				</div>
			</div>

			{ isStale ? (
				<div
					className="dbvc-cc-v2-action-panel__notice-card dbvc-cc-v2-action-panel__notice-card--warning"
					data-testid="dbvc-cc-v2-inspector-stale-banner"
				>
					<div>
						<p className="dbvc-cc-v2-eyebrow">Stale decisions</p>
						<p>
							Saved decisions were derived from an older
							recommendation fingerprint. Reset to the latest
							recommendations, review the updated evidence, and
							save again to clear the stale state.
						</p>
					</div>
					<div className="dbvc-cc-v2-toolbar__controls">
						<button
							type="button"
							className="button button-secondary"
							disabled={ ! canResetToLatestRecommendations }
							data-testid="dbvc-cc-v2-inspector-reset-latest"
							onClick={ resetToLatestRecommendations }
						>
							Reset to latest recommendations
						</button>
						<button
							type="button"
							className="button button-secondary"
							disabled={ ! hasUnsavedChanges }
							data-testid="dbvc-cc-v2-inspector-restore-saved"
							onClick={ restoreSavedDraft }
						>
							Restore saved decisions
						</button>
					</div>
				</div>
			) : null }

			{ hasUnsavedChanges ? (
				<div
					className="dbvc-cc-v2-action-panel__notice-card"
					data-testid="dbvc-cc-v2-inspector-unsaved-banner"
				>
					<div>
						<p className="dbvc-cc-v2-eyebrow">Unsaved edits</p>
						<p>
							Local review edits have not been written to the
							decision artifacts yet.
						</p>
					</div>
					<button
						type="button"
						className="button button-secondary"
						data-testid="dbvc-cc-v2-inspector-discard-local"
						onClick={ restoreSavedDraft }
					>
						Discard local edits
					</button>
				</div>
			) : null }

			<div className="dbvc-cc-v2-form-grid">
				<div className="dbvc-cc-v2-toolbar__field">
					<label htmlFor="dbvc-cc-v2-target-object-select">
						<span>Target object</span>
					</label>
					<select
						id="dbvc-cc-v2-target-object-select"
						value={ selectedTargetObjectKey || '' }
						data-testid="dbvc-cc-v2-target-object-select"
						onChange={ ( event ) =>
							setSelectedTargetObjectKey( event.target.value )
						}
					>
						{ candidateTargetObjects.map( ( candidate ) => (
							<option
								key={ candidate.target_object_key }
								value={ candidate.target_object_key }
							>
								{ candidate.label ||
									candidate.presentation?.label ||
									candidate.target_object_key }
							</option>
						) ) }
					</select>
				</div>

				<div className="dbvc-cc-v2-toolbar__field">
					<label htmlFor="dbvc-cc-v2-resolution-mode-select">
						<span>Resolution mode</span>
					</label>
					<select
						id="dbvc-cc-v2-resolution-mode-select"
						value={ selectedResolutionMode || '' }
						data-testid="dbvc-cc-v2-resolution-mode-select"
						onChange={ ( event ) =>
							setSelectedResolutionMode( event.target.value )
						}
					>
						<option value="update_existing">update_existing</option>
						<option value="create_new">create_new</option>
						<option value="blocked_needs_review">
							blocked_needs_review
						</option>
						<option value="skip_out_of_scope">
							skip_out_of_scope
						</option>
					</select>
				</div>
			</div>

			<div className="dbvc-cc-v2-placeholder-card dbvc-cc-v2-placeholder-card--full">
				<h4>Target selection</h4>
				<p>
					Selected target:{ ' ' }
					<strong>
						{ selectedTargetObject?.label ||
							selectedTargetObject?.presentation?.label ||
							selectedTargetObject?.target_object_key ||
							recommendedTargetObject.target_object_key ||
							'Unassigned' }
					</strong>
				</p>
			</div>

			<div className="dbvc-cc-v2-toolbar__field">
				<label htmlFor="dbvc-cc-v2-reviewer-note">
					<span>Reviewer note</span>
				</label>
				<textarea
					id="dbvc-cc-v2-reviewer-note"
					value={ reviewerNote }
					rows="3"
					data-testid="dbvc-cc-v2-reviewer-note"
					onChange={ ( event ) =>
						setReviewerNote( event.target.value )
					}
				/>
			</div>

			<div className="dbvc-cc-v2-action-panel__columns">
				<div className="dbvc-cc-v2-placeholder-card">
					<h4>Field and taxonomy decisions</h4>
					<div
						className="dbvc-cc-v2-decision-card-list"
						data-testid="dbvc-cc-v2-field-decision-list-edit"
					>
						{ fieldDecisions.length ? (
							fieldDecisions.map( ( item ) => (
								<RecommendationDecisionCard
									key={ item.recommendationId }
									item={ item }
									mode="edit"
									onOverrideTargetChange={ (
										recommendationId,
										value
									) =>
										setOverrideTarget(
											'field',
											recommendationId,
											value
										)
									}
									onStateChange={ (
										recommendationId,
										state
									) =>
										setDecisionState(
											'field',
											recommendationId,
											state
										)
									}
								/>
							) )
						) : (
							<p className="dbvc-cc-v2-table__meta">
								No field recommendations.
							</p>
						) }
					</div>
				</div>

				<div className="dbvc-cc-v2-placeholder-card">
					<h4>Media decisions</h4>
					<div
						className="dbvc-cc-v2-decision-card-list"
						data-testid="dbvc-cc-v2-media-decision-list-edit"
					>
						{ mediaDecisions.length ? (
							mediaDecisions.map( ( item ) => (
								<RecommendationDecisionCard
									key={ item.recommendationId }
									item={ item }
									mode="edit"
									onOverrideTargetChange={ (
										recommendationId,
										value
									) =>
										setOverrideTarget(
											'media',
											recommendationId,
											value
										)
									}
									onStateChange={ (
										recommendationId,
										state
									) =>
										setDecisionState(
											'media',
											recommendationId,
											state
										)
									}
								/>
							) )
						) : (
							<p className="dbvc-cc-v2-table__meta">
								No media recommendations.
							</p>
						) }
					</div>
				</div>
			</div>

			{ validationErrors.length ? (
				<div
					className="dbvc-cc-v2-action-panel__validation"
					data-testid="dbvc-cc-v2-inspector-validation"
				>
					{ validationErrors.map( ( message ) => (
						<p key={ message }>{ message }</p>
					) ) }
				</div>
			) : null }
		</div>
	);
}
