import {
	getFieldContextCompact,
	getFieldContextProviderName,
	getFieldContextProviderStatus,
	getFieldContextValueShapeLabel,
	getFieldContextWarnings,
	getFieldContextWritable,
	getTargetContext,
	getTargetFieldName,
	getTargetLabel,
	getTargetMachineRef,
	getTargetTypeLabel,
	isFieldContextCloneProjection,
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

const formatFieldContextWarning = ( warning ) => {
	if ( typeof warning === 'string' ) {
		return warning;
	}

	if ( warning && typeof warning === 'object' ) {
		return warning.message || warning.code || '';
	}

	return '';
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
	const fieldContext = getFieldContextCompact( recommendation );
	const fieldContextStatus = getFieldContextProviderStatus( recommendation );
	const fieldContextProvider = getFieldContextProviderName( recommendation );
	const fieldContextWarnings = getFieldContextWarnings( recommendation );
	const fieldContextWritable = getFieldContextWritable( recommendation );
	const fieldContextCloneProjection =
		isFieldContextCloneProjection( recommendation );
	const fieldContextValueShape =
		getFieldContextValueShapeLabel( recommendation );
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
					{ confidence ? (
						<span className="dbvc-cc-v2-chip dbvc-cc-v2-chip--muted">
							{ confidence }
						</span>
					) : null }
					{ fieldContextStatus ? (
						<span className="dbvc-cc-v2-chip dbvc-cc-v2-chip--muted">
							Field Context: { fieldContextStatus }
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
				{ fieldContext ? (
					<div className="dbvc-cc-v2-decision-card__section">
						<p className="dbvc-cc-v2-eyebrow">Field Context</p>
						<p>
							Provider:{ ' ' }
							<strong>
								{ fieldContextProvider || 'unavailable' }
							</strong>
							{ fieldContextStatus
								? ` (${ fieldContextStatus })`
								: '' }
						</p>
						{ fieldContext.field_purpose ? (
							<p>Field purpose: { fieldContext.field_purpose }</p>
						) : null }
						{ fieldContext.group_purpose ? (
							<p>Group purpose: { fieldContext.group_purpose }</p>
						) : null }
						{ fieldContextValueShape ? (
							<p>Value shape: { fieldContextValueShape }</p>
						) : null }
						{ typeof fieldContextWritable === 'boolean' ? (
							<p>
								Writable:{ ' ' }
								{ fieldContextWritable ? 'yes' : 'no' }
							</p>
						) : null }
						<p>
							Clone projection:{ ' ' }
							{ fieldContextCloneProjection ? 'yes' : 'no' }
						</p>
						{ fieldContextWarnings.length ? (
							<ul className="dbvc-cc-v2-inspector-list dbvc-cc-v2-inspector-list--compact">
								{ fieldContextWarnings.map(
									( warning, index ) => (
										<li
											key={ `${ recommendationId }-field-context-warning-${ index }` }
										>
											{ formatFieldContextWarning(
												warning
											) }
										</li>
									)
								) }
							</ul>
						) : (
							<p className="dbvc-cc-v2-table__meta">
								No Field Context warnings.
							</p>
						) }
					</div>
				) : null }
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
