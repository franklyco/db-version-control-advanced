const renderRecommendation = ( recommendation ) => (
	<li key={ recommendation.recommendation_id }>
		<strong>{ recommendation.target_ref }</strong>
		<p className="dbvc-cc-v2-table__meta">
			{ recommendation.source_evidence || 'No source evidence' }
		</p>
	</li>
);

export default function InspectorMappingTab( { detail } ) {
	const recommendations = detail?.recommendations || {};

	return (
		<div
			className="dbvc-cc-v2-inspector-tab"
			data-testid="dbvc-cc-v2-inspector-mapping"
		>
			<div className="dbvc-cc-v2-inspector-grid">
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Field recommendations</h3>
					<ul className="dbvc-cc-v2-inspector-list">
						{ Array.isArray(
							recommendations.fieldRecommendations
						) && recommendations.fieldRecommendations.length
							? recommendations.fieldRecommendations.map(
									renderRecommendation
							  )
							: 'No field recommendations.' }
					</ul>
				</article>
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Media recommendations</h3>
					<ul className="dbvc-cc-v2-inspector-list">
						{ Array.isArray(
							recommendations.mediaRecommendations
						) && recommendations.mediaRecommendations.length
							? recommendations.mediaRecommendations.map(
									renderRecommendation
							  )
							: 'No media recommendations.' }
					</ul>
				</article>
			</div>
		</div>
	);
}
