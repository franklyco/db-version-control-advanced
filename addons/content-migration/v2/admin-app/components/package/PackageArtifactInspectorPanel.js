const toArray = ( value ) => ( Array.isArray( value ) ? value : [] );

const renderIssueList = ( items, emptyMessage ) => {
	const normalizedItems = toArray( items );
	if ( ! normalizedItems.length ) {
		return <p>{ emptyMessage }</p>;
	}

	return (
		<ul className="dbvc-cc-v2-inspector-list">
			{ normalizedItems.slice( 0, 10 ).map( ( item, index ) => (
				<li key={ `${ item.code || 'issue' }-${ index }` }>
					<strong>{ item.code || 'issue' }</strong>
					<p className="dbvc-cc-v2-table__meta">
						{ item.message || 'No detail was provided.' }
					</p>
				</li>
			) ) }
		</ul>
	);
};

const renderRecordsTable = ( records ) => {
	const rows = toArray( records );
	if ( ! rows.length ) {
		return (
			<p>
				No package records were generated for this build yet. Resolve
				blockers or review items before execute.
			</p>
		);
	}

	return (
		<div className="dbvc-cc-v2-table-wrap">
			<table className="dbvc-cc-v2-table">
				<thead>
					<tr>
						<th>Page</th>
						<th>Target</th>
						<th>Action</th>
						<th>Counts</th>
					</tr>
				</thead>
				<tbody>
					{ rows.slice( 0, 10 ).map( ( record ) => (
						<tr
							key={ `${
								record.page_id || record.path || 'record'
							}-${ record.target_entity_key || 'target' }` }
						>
							<td>
								<strong>
									{ record.page_id || 'Unknown page' }
								</strong>
								<p className="dbvc-cc-v2-table__meta">
									{ record.path ||
										record.source_url ||
										'No path available.' }
								</p>
							</td>
							<td>
								{ record.target_entity_key ||
									'Unresolved target' }
							</td>
							<td>{ record.target_action || 'unknown' }</td>
							<td>
								<div className="dbvc-cc-v2-signal-stack">
									<span>
										Fields:{ ' ' }
										{
											toArray( record.field_values )
												.length
										}
									</span>
									<span>
										Media:{ ' ' }
										{ toArray( record.media_refs ).length }
									</span>
								</div>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
};

const renderMediaTable = ( mediaItems ) => {
	const rows = toArray( mediaItems );
	if ( ! rows.length ) {
		return <p>No media items are queued in this package.</p>;
	}

	return (
		<div className="dbvc-cc-v2-table-wrap">
			<table className="dbvc-cc-v2-table">
				<thead>
					<tr>
						<th>Page</th>
						<th>Target ref</th>
						<th>Media kind</th>
						<th>Source</th>
					</tr>
				</thead>
				<tbody>
					{ rows.slice( 0, 10 ).map( ( item ) => (
						<tr
							key={ `${ item.page_id || 'page' }-${
								item.target_ref || 'target'
							}-${ item.source_url || 'source' }` }
						>
							<td>
								<strong>
									{ item.page_id || 'Unknown page' }
								</strong>
								<p className="dbvc-cc-v2-table__meta">
									{ item.path || 'No path available.' }
								</p>
							</td>
							<td>{ item.target_ref || 'Unknown target' }</td>
							<td>{ item.media_kind || 'unknown' }</td>
							<td className="dbvc-cc-v2-table__cell-break">
								{ item.source_url || 'No source URL recorded.' }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
};

const renderManifestPreview = ( manifest ) => {
	const stats = manifest?.stats || {};
	const includedPages = toArray( manifest?.included_pages );
	const includedObjectTypes = toArray( manifest?.included_object_types );

	return (
		<>
			<div className="dbvc-cc-v2-grid dbvc-cc-v2-grid--package-detail">
				<div className="dbvc-cc-v2-signal-stack">
					<span>Build sequence: { stats.build_seq ?? 0 }</span>
					<span>
						Eligible pages: { stats.eligible_page_count ?? 0 }
					</span>
					<span>
						Included pages: { stats.included_page_count ?? 0 }
					</span>
					<span>Records: { stats.record_count ?? 0 }</span>
					<span>Media items: { stats.media_item_count ?? 0 }</span>
				</div>
				<div className="dbvc-cc-v2-signal-stack">
					<span>
						Readiness: { stats.readiness_status || 'unknown' }
					</span>
					<span>
						Schema fingerprint:{ ' ' }
						{ manifest?.target_schema_fingerprint || 'Unavailable' }
					</span>
					<span>
						Generated: { manifest?.generated_at || 'Unknown' }
					</span>
				</div>
			</div>
			<div className="dbvc-cc-v2-grid dbvc-cc-v2-grid--package-detail">
				<div>
					<h4>Included object types</h4>
					{ includedObjectTypes.length ? (
						<ul className="dbvc-cc-v2-inspector-list">
							{ includedObjectTypes
								.slice( 0, 10 )
								.map( ( item ) => (
									<li key={ item }>{ item }</li>
								) ) }
						</ul>
					) : (
						<p>No object types were included in this build.</p>
					) }
				</div>
				<div>
					<h4>Included pages</h4>
					{ includedPages.length ? (
						<ul className="dbvc-cc-v2-inspector-list">
							{ includedPages.slice( 0, 10 ).map( ( item ) => (
								<li key={ item }>{ item }</li>
							) ) }
						</ul>
					) : (
						<p>No pages were eligible for this package yet.</p>
					) }
				</div>
			</div>
		</>
	);
};

const renderSummaryPreview = ( summary ) => (
	<div className="dbvc-cc-v2-grid dbvc-cc-v2-grid--package-detail">
		<div className="dbvc-cc-v2-signal-stack">
			<span>Readiness: { summary?.readiness_status || 'unknown' }</span>
			<span>Build sequence: { summary?.build_seq ?? 0 }</span>
			<span>Records: { summary?.record_count ?? 0 }</span>
			<span>Included pages: { summary?.included_page_count ?? 0 }</span>
			<span>Media items: { summary?.media_item_count ?? 0 }</span>
		</div>
		<div className="dbvc-cc-v2-signal-stack">
			<span>Exceptions: { summary?.exception_count ?? 0 }</span>
			<span>Auto accepted: { summary?.auto_accepted_count ?? 0 }</span>
			<span>
				Manual overrides: { summary?.manual_override_count ?? 0 }
			</span>
			<span>Blocking issues: { summary?.blocking_issue_count ?? 0 }</span>
			<span>Warnings: { summary?.warning_count ?? 0 }</span>
			<span>
				Benchmark gate: { summary?.benchmark_status || 'unknown' }
			</span>
			<span>
				Benchmark high-risk pages:{ ' ' }
				{ summary?.benchmark_high_risk_page_count ?? 0 }
			</span>
		</div>
	</div>
);

const renderBenchmarkSummary = ( benchmarkSummary = {} ) => (
	<div className="dbvc-cc-v2-signal-stack">
		<span>Gate status: { benchmarkSummary?.status || 'unknown' }</span>
		<span>
			High-risk pages: { benchmarkSummary?.highRiskPageCount ?? 0 }
		</span>
		<span>
			Unresolved items: { benchmarkSummary?.totals?.unresolvedCount ?? 0 }
		</span>
		<span>
			Ambiguous reviewed:{ ' ' }
			{ benchmarkSummary?.totals?.ambiguousReviewedCount ?? 0 }
		</span>
		<span>
			Manual overrides:{ ' ' }
			{ benchmarkSummary?.totals?.manualOverrideCount ?? 0 }
		</span>
		<span>Reruns: { benchmarkSummary?.totals?.rerunCount ?? 0 }</span>
	</div>
);

export default function PackageArtifactInspectorPanel( {
	artifactKey,
	artifactActions,
	packageDetail,
} ) {
	if ( ! artifactKey || ! packageDetail ) {
		return null;
	}

	const action = artifactActions?.[ artifactKey ];
	if ( ! action || ! action.canInspect ) {
		return null;
	}

	const manifest = packageDetail.manifest || {};
	const summary = packageDetail.summary || {};
	const qaReport = packageDetail.qaReport || {};
	const recordPayload = packageDetail.records || {};
	const mediaManifest = packageDetail.mediaManifest || {};
	let body = null;

	switch ( artifactKey ) {
		case 'manifest':
			body = renderManifestPreview( manifest );
			break;
		case 'summary':
			body = renderSummaryPreview( summary );
			break;
		case 'qa':
			body = (
				<div className="dbvc-cc-v2-grid dbvc-cc-v2-grid--package-detail">
					<div>
						<h4>Blocking issues</h4>
						{ renderIssueList(
							qaReport?.blocking_issues,
							'No blocking issues were recorded for this package.'
						) }
					</div>
					<div>
						<h4>Warnings</h4>
						{ renderIssueList(
							qaReport?.warnings,
							'No warnings were recorded for this package.'
						) }
					</div>
					<div>
						<h4>Benchmark gate</h4>
						{ renderBenchmarkSummary(
							qaReport?.benchmark_summary
						) }
					</div>
				</div>
			);
			break;
		case 'records':
			body = renderRecordsTable( recordPayload?.records );
			break;
		case 'media':
			body = renderMediaTable( mediaManifest?.media_items );
			break;
		default:
			return null;
	}

	return (
		<article
			className="dbvc-cc-v2-placeholder-card dbvc-cc-v2-placeholder-card--full"
			data-testid={ `dbvc-cc-v2-package-artifact-panel-${ artifactKey }` }
		>
			<p className="dbvc-cc-v2-eyebrow">Artifact drill-in</p>
			<h3>{ action.label || artifactKey }</h3>
			<p>{ action.description || '' }</p>
			{ body }
		</article>
	);
}
