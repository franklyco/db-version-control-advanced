export default function InspectorSummaryTab( { detail } ) {
	const summary = detail?.summary || {};
	const counts = summary.counts || {};
	const currentTarget = summary.currentTargetObject || {};

	return (
		<div
			className="dbvc-cc-v2-inspector-tab"
			data-testid="dbvc-cc-v2-inspector-summary"
		>
			<div className="dbvc-cc-v2-inspector-grid">
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Review state</h3>
					<p>Status: { summary.reviewStatus || 'needs_review' }</p>
					<p>Decision: { summary.decisionStatus || 'pending' }</p>
					<p>Resolution: { summary.resolutionMode || 'pending' }</p>
				</article>
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Target object</h3>
					<p>
						{ currentTarget.label ||
							currentTarget.targetObjectKey ||
							'Unassigned' }
					</p>
					<p>Family: { currentTarget.targetFamily || 'unknown' }</p>
					<p>
						Decision mode:{ ' ' }
						{ currentTarget.decisionMode || 'accept_recommended' }
					</p>
				</article>
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Exception counts</h3>
					<p>{ counts.recommendations || 0 } field recommendations</p>
					<p>
						{ counts.mediaRecommendations || 0 } media
						recommendations
					</p>
					<p>
						{ counts.unresolved || 0 } unresolved /{ ' ' }
						{ counts.conflicts || 0 } conflicts
					</p>
				</article>
			</div>
		</div>
	);
}
