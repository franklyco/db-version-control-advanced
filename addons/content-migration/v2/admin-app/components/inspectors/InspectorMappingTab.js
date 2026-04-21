import RecommendationDecisionCard from './RecommendationDecisionCard';

const renderDecisionList = ( items, kind ) => (
	<div
		className="dbvc-cc-v2-decision-card-list"
		data-testid={ `dbvc-cc-v2-${ kind }-decision-list-read` }
	>
		{ Array.isArray( items ) && items.length ? (
			items.map( ( item ) => (
				<RecommendationDecisionCard
					key={ item.recommendationId }
					item={ item }
				/>
			) )
		) : (
			<p className="dbvc-cc-v2-table__meta">No recommendations.</p>
		) }
	</div>
);

export default function InspectorMappingTab( {
	fieldDecisions,
	mediaDecisions,
} ) {
	return (
		<div
			className="dbvc-cc-v2-inspector-tab"
			data-testid="dbvc-cc-v2-inspector-mapping"
		>
			<div className="dbvc-cc-v2-inspector-grid">
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Field recommendations</h3>
					{ renderDecisionList( fieldDecisions, 'field' ) }
				</article>
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Media recommendations</h3>
					{ renderDecisionList( mediaDecisions, 'media' ) }
				</article>
			</div>
		</div>
	);
}
