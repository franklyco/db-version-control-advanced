const STATUS_LABELS = {
	ready_for_import: 'Ready for import',
	needs_review: 'Needs review',
	blocked: 'Blocked',
};

export default function ReadinessSummaryCards( {
	readinessStatus,
	summary,
	benchmarkSummary,
	schemaFingerprint,
} ) {
	const benchmarkStatus = benchmarkSummary?.status || '';
	const cards = [
		{
			label: 'Readiness',
			value: STATUS_LABELS[ readinessStatus ] || 'Unknown',
		},
		{
			label: 'Eligible pages',
			value: summary.eligiblePages ?? 0,
		},
		{
			label: 'Ready pages',
			value: summary.readyPages ?? 0,
		},
		{
			label: 'Benchmark gate',
			value: STATUS_LABELS[ benchmarkStatus ] || 'Unknown',
		},
		{
			label: 'High-risk pages',
			value: benchmarkSummary?.highRiskPageCount ?? 0,
		},
		{
			label: 'Schema fingerprint',
			value: schemaFingerprint || 'Not captured',
		},
	];

	return (
		<section
			className="dbvc-cc-v2-grid"
			data-testid="dbvc-cc-v2-readiness-summary"
		>
			{ cards.map( ( card ) => (
				<article
					key={ card.label }
					className="dbvc-cc-v2-placeholder-card"
				>
					<p className="dbvc-cc-v2-eyebrow">{ card.label }</p>
					<strong>{ card.value }</strong>
				</article>
			) ) }
		</section>
	);
}
