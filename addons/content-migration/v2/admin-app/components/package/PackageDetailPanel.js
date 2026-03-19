export default function PackageDetailPanel( { packageDetail } ) {
	if ( ! packageDetail ) {
		return (
			<div
				className="dbvc-cc-v2-placeholder-card"
				data-testid="dbvc-cc-v2-package-detail"
			>
				<h3>Select a package build</h3>
				<p>Package details will appear after the first build.</p>
			</div>
		);
	}

	const summary = packageDetail.summary || {};
	const manifest = packageDetail.manifest || {};
	const qaReport = packageDetail.qaReport || {};
	const artifactRefs = packageDetail.artifactRefs || {};

	return (
		<section
			className="dbvc-cc-v2-grid dbvc-cc-v2-grid--package-detail"
			data-testid="dbvc-cc-v2-package-detail"
		>
			<article className="dbvc-cc-v2-placeholder-card">
				<p className="dbvc-cc-v2-eyebrow">Summary</p>
				<h3>{ packageDetail.packageId }</h3>
				<p>Readiness: { summary.readiness_status || 'unknown' }</p>
				<p>Records: { summary.record_count ?? 0 }</p>
				<p>Media items: { summary.media_item_count ?? 0 }</p>
			</article>

			<article className="dbvc-cc-v2-placeholder-card">
				<p className="dbvc-cc-v2-eyebrow">Manifest</p>
				<p>
					Included pages:{ ' ' }
					{ Array.isArray( manifest.included_pages )
						? manifest.included_pages.length
						: 0 }
				</p>
				<p>
					Object types:{ ' ' }
					{ Array.isArray( manifest.included_object_types )
						? manifest.included_object_types.join( ', ' )
						: 'None' }
				</p>
			</article>

			<article className="dbvc-cc-v2-placeholder-card">
				<p className="dbvc-cc-v2-eyebrow">QA</p>
				<p>Quality score: { qaReport.quality_score ?? 0 }</p>
				<p>
					Blockers:{ ' ' }
					{ Array.isArray( qaReport.blocking_issues )
						? qaReport.blocking_issues.length
						: 0 }
				</p>
				<p>
					Warnings:{ ' ' }
					{ Array.isArray( qaReport.warnings )
						? qaReport.warnings.length
						: 0 }
				</p>
			</article>

			<article className="dbvc-cc-v2-placeholder-card">
				<p className="dbvc-cc-v2-eyebrow">Artifacts</p>
				<ul className="dbvc-cc-v2-inspector-list">
					{ Object.entries( artifactRefs ).map(
						( [ key, value ] ) => (
							<li key={ key }>
								<strong>{ key }</strong>
								<p className="dbvc-cc-v2-table__meta">
									{ value }
								</p>
							</li>
						)
					) }
				</ul>
			</article>
		</section>
	);
}
