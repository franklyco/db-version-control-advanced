import RecommendationDecisionCard from './RecommendationDecisionCard';
import {
	getTargetContext,
	getTargetFieldName,
	getTargetLabel,
	getTargetMachineRef,
	getTargetTypeLabel,
} from './targetPresentation';

const formatReasonCode = ( reasonCode ) =>
	`${ reasonCode || '' }`
		.replaceAll( '_', ' ' )
		.replace( /\b\w/g, ( character ) => character.toUpperCase() );

const formatConfidence = ( value ) =>
	typeof value === 'number' ? `${ Math.round( value * 100 ) }%` : '';

const buildRelatedDecisionGroups = (
	conflict,
	fieldDecisions = [],
	mediaDecisions = []
) => {
	const ids = Array.isArray( conflict?.recommendation_ids )
		? new Set( conflict.recommendation_ids )
		: new Set();

	return {
		fieldItems: fieldDecisions.filter( ( item ) =>
			ids.has( item.recommendationId )
		),
		mediaItems: mediaDecisions.filter( ( item ) =>
			ids.has( item.recommendationId )
		),
	};
};

const renderEditableDecisions = (
	items,
	kind,
	setDecisionState,
	setOverrideTarget
) => {
	if ( ! items.length ) {
		return (
			<p className="dbvc-cc-v2-table__meta">
				No { kind } recommendations are attached to this conflict.
			</p>
		);
	}

	return (
		<div className="dbvc-cc-v2-decision-card-list">
			{ items.map( ( item ) => (
				<RecommendationDecisionCard
					key={ item.recommendationId }
					item={ item }
					mode="edit"
					onOverrideTargetChange={ ( recommendationId, value ) =>
						setOverrideTarget( kind, recommendationId, value )
					}
					onStateChange={ ( recommendationId, state ) =>
						setDecisionState( kind, recommendationId, state )
					}
				/>
			) ) }
		</div>
	);
};

export default function InspectorConflictsTab( { detail, decisionDraft } ) {
	const conflicts = Array.isArray( detail?.recommendations?.conflicts )
		? detail.recommendations.conflicts
		: [];
	const review = detail?.recommendations?.review || {};
	const summary = detail?.summary || {};
	const currentTarget = summary.currentTargetObject || {};
	const {
		fieldDecisions,
		mediaDecisions,
		setDecisionState,
		setOverrideTarget,
	} = decisionDraft;

	return (
		<div
			className="dbvc-cc-v2-inspector-tab"
			data-testid="dbvc-cc-v2-inspector-conflicts"
		>
			<div className="dbvc-cc-v2-inspector-grid">
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Conflict summary</h3>
					<p>
						{ conflicts.length } conflict groups need a final target
						decision.
					</p>
					<p>
						Current target object:{ ' ' }
						<strong>
							{ currentTarget.label ||
								currentTarget.targetObjectKey ||
								'Unassigned' }
						</strong>
					</p>
					<p>
						Resolution mode:{ ' ' }
						{ summary.resolutionMode || 'needs review' }
					</p>
				</article>

				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Why this is blocked</h3>
					<p>
						{ summary.resolutionReason ||
							'Multiple recommendations currently point at the same non-repeatable target, so one or more decisions must change before packaging can proceed.' }
					</p>
					<p>
						Review reasons:{ ' ' }
						{ Array.isArray( review.reason_codes ) &&
						review.reason_codes.length
							? review.reason_codes
									.map( formatReasonCode )
									.join( ', ' )
							: 'Needs manual review' }
					</p>
				</article>

				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Operator guidance</h3>
					<p>
						Primary confidence:{ ' ' }
						{ formatConfidence( review.primary_confidence ) ||
							'Not available' }
					</p>
					<p>
						Choose one recommendation to keep on the conflicted
						target, then reject, override, or leave the remaining
						items unresolved.
					</p>
					{ summary.stale ? (
						<p className="dbvc-cc-v2-table__meta">
							Saved decisions are stale. Reset to latest
							recommendations before finalizing the conflict.
						</p>
					) : null }
				</article>
			</div>

			{ conflicts.length ? (
				<div className="dbvc-cc-v2-conflict-list">
					{ conflicts.map( ( conflict, index ) => {
						const { fieldItems, mediaItems } =
							buildRelatedDecisionGroups(
								conflict,
								fieldDecisions,
								mediaDecisions
							);
						const fieldName = getTargetFieldName( conflict );
						const machineRef = getTargetMachineRef( conflict );
						const targetType = getTargetTypeLabel( conflict );
						const targetContext = getTargetContext( conflict );

						return (
							<article
								key={ `${
									conflict.target_ref || 'conflict'
								}-${ index }` }
								className="dbvc-cc-v2-conflict-card dbvc-cc-v2-placeholder-card"
								data-testid={ `dbvc-cc-v2-conflict-card-${ index }` }
							>
								<div className="dbvc-cc-v2-conflict-card__header">
									<div className="dbvc-cc-v2-target-presentation">
										<strong>
											{ getTargetLabel( conflict ) }
										</strong>
										{ targetContext || targetType ? (
											<p className="dbvc-cc-v2-target-presentation__context">
												{ targetContext }
												{ targetContext && targetType
													? ` · ${ targetType }`
													: targetType }
											</p>
										) : null }
										{ fieldName ? (
											<p className="dbvc-cc-v2-target-presentation__field-name">
												Name: <span>{ fieldName }</span>
											</p>
										) : null }
										{ machineRef ? (
											<p className="dbvc-cc-v2-target-presentation__machine">
												Ref: <span>{ machineRef }</span>
											</p>
										) : null }
									</div>
									<p className="dbvc-cc-v2-table__meta">
										{ formatReasonCode( conflict.reason ) ||
											'Conflict requires review' }
									</p>
								</div>

								<div className="dbvc-cc-v2-conflict-card__columns">
									<div>
										<p className="dbvc-cc-v2-eyebrow">
											Field and taxonomy
										</p>
										{ renderEditableDecisions(
											fieldItems,
											'field',
											setDecisionState,
											setOverrideTarget
										) }
									</div>
									<div>
										<p className="dbvc-cc-v2-eyebrow">
											Media
										</p>
										{ renderEditableDecisions(
											mediaItems,
											'media',
											setDecisionState,
											setOverrideTarget
										) }
									</div>
								</div>
							</article>
						);
					} ) }
				</div>
			) : (
				<div className="dbvc-cc-v2-placeholder-card">
					<h3>No conflicts are active for this URL</h3>
					<p>
						Use the mapping tab to review unresolved or overridden
						recommendations instead.
					</p>
				</div>
			) }
		</div>
	);
}
