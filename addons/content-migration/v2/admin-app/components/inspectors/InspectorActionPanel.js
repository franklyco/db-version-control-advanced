import { useEffect, useState } from '@wordpress/element';

const buildOverrideState = ( recommendations, existingOverrides = [] ) => {
	const overrideIndex = new Map();
	if ( Array.isArray( existingOverrides ) ) {
		existingOverrides.forEach( ( override ) => {
			if ( override?.recommendation_id ) {
				overrideIndex.set(
					override.recommendation_id,
					override.override_target || ''
				);
			}
		} );
	}

	return Array.isArray( recommendations )
		? recommendations.map( ( recommendation ) => ( {
				recommendationId: recommendation.recommendation_id,
				targetRef: recommendation.target_ref,
				overrideTarget:
					overrideIndex.get( recommendation.recommendation_id ) || '',
		  } ) )
		: [];
};

export default function InspectorActionPanel( {
	detail,
	isBusy,
	statusMessage,
	onSave,
	onRerun,
} ) {
	const candidateTargetObjects =
		detail?.recommendations?.candidateTargetObjects || [];
	const recommendedTargetObject =
		detail?.summary?.recommendedTargetObject || {};
	const [ reviewerNote, setReviewerNote ] = useState( '' );
	const [ selectedTargetObjectKey, setSelectedTargetObjectKey ] =
		useState( '' );
	const [ selectedResolutionMode, setSelectedResolutionMode ] =
		useState( '' );
	const [ fieldOverrides, setFieldOverrides ] = useState( [] );
	const [ mediaOverrides, setMediaOverrides ] = useState( [] );

	useEffect( () => {
		const nextRecommendedTargetObject =
			detail?.summary?.recommendedTargetObject || {};
		const nextCurrentTargetObject =
			detail?.summary?.currentTargetObject || {};

		setReviewerNote(
			detail?.decisions?.mapping?.reviewer_meta?.reviewer_note || ''
		);
		setSelectedTargetObjectKey(
			nextCurrentTargetObject.targetObjectKey ||
				nextRecommendedTargetObject.target_object_key ||
				''
		);
		setSelectedResolutionMode(
			nextCurrentTargetObject.resolutionMode ||
				nextRecommendedTargetObject.resolution_mode ||
				''
		);
		setFieldOverrides(
			buildOverrideState(
				detail?.recommendations?.fieldRecommendations,
				detail?.decisions?.mapping?.overrides
			)
		);
		setMediaOverrides(
			buildOverrideState(
				detail?.recommendations?.mediaRecommendations,
				detail?.decisions?.media?.overrides
			)
		);
	}, [ detail ] );

	const selectedTargetObject =
		candidateTargetObjects.find(
			( candidate ) =>
				candidate.target_object_key === selectedTargetObjectKey
		) ||
		candidateTargetObjects[ 0 ] ||
		recommendedTargetObject;
	const isTargetOverride =
		selectedTargetObjectKey &&
		selectedTargetObjectKey !== recommendedTargetObject.target_object_key;

	return (
		<div
			className="dbvc-cc-v2-action-panel"
			data-testid="dbvc-cc-v2-inspector-actions"
		>
			<div className="dbvc-cc-v2-action-panel__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Review Actions</p>
					<h3>Target overrides, field overrides, and reruns</h3>
				</div>
				{ statusMessage ? (
					<p className="dbvc-cc-v2-action-panel__notice">
						{ statusMessage }
					</p>
				) : null }
			</div>

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
					<h4>Field and taxonomy overrides</h4>
					{ fieldOverrides.map( ( override, index ) => (
						<div
							key={ override.recommendationId }
							className="dbvc-cc-v2-toolbar__field"
						>
							<label
								htmlFor={ `dbvc-cc-v2-field-override-${ override.recommendationId }` }
							>
								<span>{ override.targetRef }</span>
							</label>
							<input
								id={ `dbvc-cc-v2-field-override-${ override.recommendationId }` }
								type="text"
								value={ override.overrideTarget }
								placeholder="Override target ref"
								data-testid={ `dbvc-cc-v2-field-override-${ override.recommendationId }` }
								onChange={ ( event ) =>
									setFieldOverrides( ( currentState ) =>
										currentState.map(
											(
												currentOverride,
												currentIndex
											) =>
												currentIndex === index
													? {
															...currentOverride,
															overrideTarget:
																event.target
																	.value,
													  }
													: currentOverride
										)
									)
								}
							/>
						</div>
					) ) }
				</div>

				<div className="dbvc-cc-v2-placeholder-card">
					<h4>Media overrides</h4>
					{ mediaOverrides.map( ( override, index ) => (
						<div
							key={ override.recommendationId }
							className="dbvc-cc-v2-toolbar__field"
						>
							<label
								htmlFor={ `dbvc-cc-v2-media-override-${ override.recommendationId }` }
							>
								<span>{ override.targetRef }</span>
							</label>
							<input
								id={ `dbvc-cc-v2-media-override-${ override.recommendationId }` }
								type="text"
								value={ override.overrideTarget }
								placeholder="Override media target ref"
								data-testid={ `dbvc-cc-v2-media-override-${ override.recommendationId }` }
								onChange={ ( event ) =>
									setMediaOverrides( ( currentState ) =>
										currentState.map(
											(
												currentOverride,
												currentIndex
											) =>
												currentIndex === index
													? {
															...currentOverride,
															overrideTarget:
																event.target
																	.value,
													  }
													: currentOverride
										)
									)
								}
							/>
						</div>
					) ) }
				</div>
			</div>

			<div className="dbvc-cc-v2-action-panel__footer">
				<div className="dbvc-cc-v2-rerun-row">
					{ Array.isArray( detail?.actions?.rerunStages ) &&
						detail.actions.rerunStages.map( ( stage ) => (
							<button
								key={ stage }
								type="button"
								className="button button-secondary"
								disabled={ isBusy }
								data-testid={ `dbvc-cc-v2-rerun-${ stage }` }
								onClick={ () => onRerun( stage ) }
							>
								Rerun { stage }
							</button>
						) ) }
				</div>

				<button
					type="button"
					className="button button-primary"
					disabled={ isBusy }
					data-testid="dbvc-cc-v2-save-decision"
					onClick={ () =>
						onSave( {
							reviewerNote,
							targetObject: {
								decisionMode: isTargetOverride
									? 'override'
									: 'accept_recommended',
								selectedTargetFamily:
									selectedTargetObject?.target_family ||
									recommendedTargetObject.target_family ||
									'',
								selectedTargetObjectKey,
								selectedResolutionMode,
							},
							approvedRecommendationIds: (
								detail?.recommendations?.fieldRecommendations ||
								[]
							).map(
								( recommendation ) =>
									recommendation.recommendation_id
							),
							approvedMediaIds: (
								detail?.recommendations?.mediaRecommendations ||
								[]
							).map(
								( recommendation ) =>
									recommendation.recommendation_id
							),
							mappingOverrides: fieldOverrides
								.filter(
									( override ) => override.overrideTarget
								)
								.map( ( override ) => ( {
									recommendationId: override.recommendationId,
									overrideScope:
										override.targetRef.startsWith(
											'taxonomy:'
										)
											? 'taxonomy'
											: 'field',
									overrideTarget: override.overrideTarget,
								} ) ),
							mediaOverrides: mediaOverrides
								.filter(
									( override ) => override.overrideTarget
								)
								.map( ( override ) => ( {
									recommendationId: override.recommendationId,
									overrideTarget: override.overrideTarget,
								} ) ),
						} )
					}
				>
					Save decision
				</button>
			</div>
		</div>
	);
}
