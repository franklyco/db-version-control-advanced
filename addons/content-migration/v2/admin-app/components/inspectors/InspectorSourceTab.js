const renderList = ( items, keyName ) =>
	Array.isArray( items ) && items.length ? (
		<ul className="dbvc-cc-v2-inspector-list">
			{ items.map( ( item, index ) => (
				<li key={ item?.[ keyName ] || item?.href || index }>
					{ typeof item === 'string' ? item : JSON.stringify( item ) }
				</li>
			) ) }
		</ul>
	) : (
		<p className="dbvc-cc-v2-table__meta">No source evidence available.</p>
	);

export default function InspectorSourceTab( { detail } ) {
	const source = detail?.evidence?.source || {};

	return (
		<div
			className="dbvc-cc-v2-inspector-tab"
			data-testid="dbvc-cc-v2-inspector-source"
		>
			<div className="dbvc-cc-v2-placeholder-card">
				<h3>{ source.title || detail?.path || 'Source evidence' }</h3>
				<p>
					{ source.description || 'No source description captured.' }
				</p>
			</div>
			<div className="dbvc-cc-v2-inspector-grid">
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Headings</h3>
					{ renderList( source.headings ) }
				</article>
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Text blocks</h3>
					{ renderList( source.textBlocks ) }
				</article>
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Links</h3>
					{ renderList( source.links, 'href' ) }
				</article>
			</div>
		</div>
	);
}
