import {
	getTargetContext,
	getTargetFieldName,
	getTargetLabel,
	getTargetMachineRef,
	getTargetTypeLabel,
} from './targetPresentation';
import { InspectorDecisionStates } from '../../hooks/useInspectorDecisionDraft';

const DECISION_OPTIONS = [
	{
		key: InspectorDecisionStates.approve,
		label: 'Approve',
	},
	{
		key: InspectorDecisionStates.reject,
		label: 'Reject',
	},
	{
		key: InspectorDecisionStates.override,
		label: 'Override',
	},
	{
		key: InspectorDecisionStates.unresolved,
		label: 'Unresolved',
	},
];

const formatDecisionSummary = ( item ) => {
	if ( item.state === InspectorDecisionStates.override ) {
		if ( item.overrideTarget.trim() ) {
			return `Override to ${ item.overrideTarget.trim() }.`;
		}

		return 'Override selected. Enter a target ref before saving.';
	}

	if ( item.state === InspectorDecisionStates.reject ) {
		return item.kind === 'media'
			? 'Reject this media mapping and omit it from the approved media set.'
			: 'Reject this mapping from the approved field set.';
	}

	if ( item.state === InspectorDecisionStates.unresolved ) {
		return item.kind === 'media'
			? 'Leave this media mapping unresolved so it remains blocked for later review.'
			: 'Leave this mapping unresolved so it remains blocked for later review.';
	}

	return 'Approve the current recommendation for the next package build.';
};

const formatReasonCode = ( reasonCode ) => {
	if ( ! reasonCode ) {
		return '';
	}

	return reasonCode
		.replaceAll( '_', ' ' )
		.replace( /\b\w/g, ( character ) => character.toUpperCase() );
};

const renderTargetMeta = ( recommendation ) => {
	const context = getTargetContext( recommendation );
	const typeLabel = getTargetTypeLabel( recommendation );

	if ( context ) {
		return (
			<p className="dbvc-cc-v2-target-presentation__context">
				{ context }
				{ typeLabel ? ` · ${ typeLabel }` : '' }
			</p>
		);
	}

	if ( typeLabel ) {
		return (
			<p className="dbvc-cc-v2-target-presentation__context">
				{ typeLabel }
			</p>
		);
	}

	return null;
};

const renderSelectionEvidence = ( recommendation ) => {
	const selection = recommendation?.selection || {};
	if ( ! selection || ! Array.isArray( selection.alternatives ) ) {
		return null;
	}

	const reasonCodes = Array.isArray( selection.reason_codes )
		? selection.reason_codes.filter( Boolean )
		: [];
	const alternatives = selection.alternatives.slice( 0, 3 );
	const marginText =
		typeof selection.margin_to_next === 'number'
			? `${ Math.round( selection.margin_to_next * 100 ) }% margin`
			: '';
	const candidateCount =
		typeof selection.candidate_count === 'number'
			? `${ selection.candidate_count } candidates`
			: '';

	return (
		<div className="dbvc-cc-v2-decision-card__section dbvc-cc-v2-decision-card__section--full">
			<p className="dbvc-cc-v2-eyebrow">Selection evidence</p>
			<p>
				{ selection.status === 'ambiguous'
					? 'Selection stayed ambiguous and defaults to unresolved until a reviewer confirms it.'
					: 'Selection was resolved deterministically from the bounded candidate pool.' }
			</p>
			{ reasonCodes.length ? (
				<p className="dbvc-cc-v2-table__meta">
					Signals:{ ' ' }
					{ reasonCodes.map( formatReasonCode ).join( ' · ' ) }
				</p>
			) : null }
			{ marginText || candidateCount ? (
				<p className="dbvc-cc-v2-table__meta">
					{ [ marginText, candidateCount ]
						.filter( Boolean )
						.join( ' · ' ) }
				</p>
			) : null }
			{ alternatives.length > 1 ? (
				<ul className="dbvc-cc-v2-compact-list">
					{ alternatives.map( ( alternative ) => {
						const adjustedConfidence =
							typeof alternative.adjusted_confidence === 'number'
								? `${ Math.round(
										alternative.adjusted_confidence * 100
								  ) }%`
								: '';
						const rawConfidence =
							typeof alternative.confidence === 'number'
								? `${ Math.round(
										alternative.confidence * 100
								  ) }% raw`
								: '';

						return (
							<li
								key={ `${ alternative.target_ref }-${
									alternative.selected_rank || 'rank'
								}` }
							>
								<strong>
									{ getTargetLabel( alternative ) }
								</strong>{ ' ' }
								{ [ adjustedConfidence, rawConfidence ]
									.filter( Boolean )
									.join( ' · ' ) }
							</li>
						);
					} ) }
				</ul>
			) : null }
		</div>
	);
};

const renderFieldContextEvidence = ( recommendation ) => {
	const compact = recommendation?.field_context_compact || {};
	if ( ! compact || typeof compact !== 'object' ) {
		return null;
	}

	const warnings = Array.isArray( compact.warnings )
		? compact.warnings.filter( Boolean )
		: [];
	const branchPath = Array.isArray( compact.branch_label_path )
		? compact.branch_label_path.filter( Boolean ).join( ' > ' )
		: '';
	const providerBits = [
		compact.provider_status ? `Provider ${ compact.provider_status }` : '',
		compact.schema_version ? `Schema ${ compact.schema_version }` : '',
		compact.contract_version
			? `Contract ${ compact.contract_version }`
			: '',
	]
		.filter( Boolean )
		.join( ' · ' );
	let writableLabel = '';
	if ( typeof compact.writable === 'boolean' ) {
		writableLabel = compact.writable ? 'Writable' : 'Read-only';
	}
	const shapeBits = [
		compact.field_type || '',
		compact.value_shape || compact.content_type || '',
		writableLabel,
		compact.clone_projected ? 'Clone projected' : '',
	]
		.filter( Boolean )
		.join( ' · ' );

	if (
		! providerBits &&
		! branchPath &&
		! compact.field_purpose &&
		! compact.group_purpose &&
		! shapeBits &&
		! warnings.length
	) {
		return null;
	}

	return (
		<div className="dbvc-cc-v2-decision-card__section dbvc-cc-v2-decision-card__section--full">
			<p className="dbvc-cc-v2-eyebrow">Field Context</p>
			{ providerBits ? (
				<p className="dbvc-cc-v2-table__meta">{ providerBits }</p>
			) : null }
			{ branchPath ? (
				<p className="dbvc-cc-v2-table__meta">Branch: { branchPath }</p>
			) : null }
			{ compact.field_purpose ? <p>{ compact.field_purpose }</p> : null }
			{ compact.group_purpose ? (
				<p className="dbvc-cc-v2-table__meta">
					Group purpose: { compact.group_purpose }
				</p>
			) : null }
			{ shapeBits ? (
				<p className="dbvc-cc-v2-table__meta">{ shapeBits }</p>
			) : null }
			{ warnings.length ? (
				<ul className="dbvc-cc-v2-compact-list">
					{ warnings.map( ( warning ) => (
						<li key={ warning }>{ formatReasonCode( warning ) }</li>
					) ) }
				</ul>
			) : null }
		</div>
	);
};

export default function RecommendationDecisionCard( {
	item,
	mode = 'read',
	onOverrideTargetChange,
	onStateChange,
} ) {
	const recommendation = item?.recommendation || {};
	const recommendationId = item?.recommendationId || 'recommendation';
	const testIdPrefix = `dbvc-cc-v2-${ item.kind }-decision`;
	const fieldName = getTargetFieldName( recommendation );
	const machineRef = getTargetMachineRef( recommendation );
	const confidence =
		typeof recommendation.confidence === 'number'
			? `${ Math.round( recommendation.confidence * 100 ) }% confidence`
			: '';

	return (
		<article
			className="dbvc-cc-v2-decision-card"
			data-kind={ item.kind }
			data-state={ item.state }
			data-testid={ `${ testIdPrefix }-card-${ recommendationId }` }
		>
			<div className="dbvc-cc-v2-decision-card__header">
				<div className="dbvc-cc-v2-target-presentation">
					<strong>{ getTargetLabel( recommendation ) }</strong>
					{ renderTargetMeta( recommendation ) }
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
				<div className="dbvc-cc-v2-decision-card__badges">
					{ item.isConflicted ? (
						<span className="dbvc-cc-v2-chip dbvc-cc-v2-chip--muted">
							Conflict
						</span>
					) : null }
					{ recommendation?.selection?.status === 'ambiguous' ? (
						<span className="dbvc-cc-v2-chip dbvc-cc-v2-chip--warning">
							Ambiguous
						</span>
					) : null }
					{ confidence ? (
						<span className="dbvc-cc-v2-chip dbvc-cc-v2-chip--muted">
							{ confidence }
						</span>
					) : null }
					<span
						className="dbvc-cc-v2-decision-card__state"
						data-state={ item.state }
						data-testid={ `${ testIdPrefix }-state-${ recommendationId }` }
					>
						{
							DECISION_OPTIONS.find(
								( option ) => option.key === item.state
							)?.label
						}
					</span>
				</div>
			</div>

			<div className="dbvc-cc-v2-decision-card__grid">
				<div className="dbvc-cc-v2-decision-card__section">
					<p className="dbvc-cc-v2-eyebrow">Source evidence</p>
					<p>
						{ recommendation.source_evidence ||
							'No source evidence available.' }
					</p>
				</div>
				<div className="dbvc-cc-v2-decision-card__section">
					<p className="dbvc-cc-v2-eyebrow">Recommended target</p>
					<p>
						{ recommendation.target_evidence ||
							machineRef ||
							'No target preview available.' }
					</p>
				</div>
				<div className="dbvc-cc-v2-decision-card__section">
					<p className="dbvc-cc-v2-eyebrow">Final decision</p>
					<p>{ formatDecisionSummary( item ) }</p>
					{ item.isConflicted ? (
						<p className="dbvc-cc-v2-table__meta">
							Conflict reason:{ ' ' }
							{ formatReasonCode( item.conflictReason ) ||
								'Needs review' }
							{ item.conflictTargetRef
								? ` · ${ item.conflictTargetRef }`
								: '' }
						</p>
					) : null }
				</div>
				{ renderFieldContextEvidence( recommendation ) }
				{ renderSelectionEvidence( recommendation ) }
			</div>

			{ mode === 'edit' ? (
				<div
					className="dbvc-cc-v2-decision-card__controls"
					data-testid={ `${ testIdPrefix }-controls-${ recommendationId }` }
				>
					{ DECISION_OPTIONS.map( ( option ) => (
						<button
							key={ option.key }
							type="button"
							className={ `button button-secondary dbvc-cc-v2-decision-card__control${
								item.state === option.key ? ' is-active' : ''
							}` }
							data-testid={ `${ testIdPrefix }-${ option.key }-${ recommendationId }` }
							data-state={ option.key }
							onClick={ () =>
								onStateChange( recommendationId, option.key )
							}
						>
							{ option.label }
						</button>
					) ) }
				</div>
			) : null }

			{ mode === 'edit' &&
			item.state === InspectorDecisionStates.override ? (
				<div className="dbvc-cc-v2-toolbar__field">
					<label
						htmlFor={ `${ testIdPrefix }-override-input-${ recommendationId }` }
					>
						<span>Override target ref</span>
					</label>
					<input
						id={ `${ testIdPrefix }-override-input-${ recommendationId }` }
						type="text"
						value={ item.overrideTarget }
						placeholder="Enter override target ref"
						data-testid={ `${ testIdPrefix }-override-input-${ recommendationId }` }
						onChange={ ( event ) =>
							onOverrideTargetChange(
								recommendationId,
								event.target.value
							)
						}
					/>
				</div>
			) : null }
		</article>
	);
}
