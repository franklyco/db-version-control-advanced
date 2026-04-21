import RecommendationDecisionCard from './RecommendationDecisionCard';

const renderWarningList = ( warnings ) =>
	Array.isArray( warnings ) && warnings.length ? (
		<ul className="dbvc-cc-v2-inspector-list dbvc-cc-v2-inspector-list--compact">
			{ warnings.map( ( warning, index ) => (
				<li key={ `field-context-provider-warning-${ index }` }>
					{ warning?.message || warning?.code || '' }
				</li>
			) ) }
		</ul>
	) : (
		<p className="dbvc-cc-v2-table__meta">No provider warnings.</p>
	);

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
	detail,
	fieldDecisions,
	mediaDecisions,
} ) {
	const provider =
		detail?.evidence?.mapping?.fieldContextProvider ||
		detail?.evidence?.audit?.fieldContextProvider ||
		{};
	const hasProvider =
		provider && typeof provider === 'object'
			? Object.keys( provider ).length > 0
			: false;

	return (
		<div
			className="dbvc-cc-v2-inspector-tab"
			data-testid="dbvc-cc-v2-inspector-mapping"
		>
			{ hasProvider ? (
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Field Context provider</h3>
					<p>
						Status:{ ' ' }
						<strong>{ provider.status || 'unknown' }</strong>
					</p>
					<p>
						Provider: { provider.provider || 'unavailable' } ·
						Transport: { provider.transport || 'n/a' }
					</p>
					<p>
						Catalog: { provider.catalog_status || 'unknown' } ·
						Resolver: { provider.resolver_status || 'unknown' }
					</p>
					<p>
						Contract/schema: { provider.contract_version || 0 } /{ ' ' }
						{ provider.schema_version || 0 }
					</p>
					<p>
						Source hash: { provider.source_hash || 'unavailable' }
					</p>
					{ renderWarningList( provider.warnings ) }
				</article>
			) : null }
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
