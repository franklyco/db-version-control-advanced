import ReadinessIssuesList from '../../components/readiness/ReadinessIssuesList';
import ReadinessPagesTable from '../../components/readiness/ReadinessPagesTable';
import ReadinessSummaryCards from '../../components/readiness/ReadinessSummaryCards';
import ReadinessToolbar from '../../components/readiness/ReadinessToolbar';
import {
	READINESS_FILTERS,
	buildReadinessFilterCounts,
	matchesReadinessFilter,
	resolveReadinessIssueAction,
	resolveReadinessPageAction,
} from '../../components/readiness/readinessActions';
import { buildOperatorActionRoute } from '../../app/operatorActionRoutes';
import useRunReadiness from '../../hooks/useRunReadiness';

export default function ReadinessWorkspace( {
	refreshToken,
	route,
	onOpenDrawer,
	onRouteChange,
} ) {
	const {
		readinessStatus,
		summary,
		blockingIssues,
		warnings,
		pageReports,
		schemaFingerprint,
		isLoading,
		error,
	} = useRunReadiness( route.runId, refreshToken );

	const handleItemAction = ( item, action ) => {
		if ( ! action ) {
			return;
		}

		const nextRoute = buildOperatorActionRoute( route, item, action );
		if ( typeof onRouteChange === 'function' && nextRoute ) {
			onRouteChange( nextRoute );
			return;
		}

		if (
			action.target === 'readiness' &&
			typeof onOpenDrawer === 'function' &&
			item?.pageId
		) {
			onOpenDrawer( item.pageId, action.panelTab || 'audit' );
		}
	};

	const blockingItems = blockingIssues.map( ( item ) => ( {
		...item,
		action: resolveReadinessIssueAction( item ),
	} ) );
	const warningItems = warnings.map( ( item ) => ( {
		...item,
		action: resolveReadinessIssueAction( item ),
	} ) );
	const pageItems = pageReports.map( ( item ) => ( {
		...item,
		primaryAction: resolveReadinessPageAction( item ),
	} ) );
	const pageCounts = buildReadinessFilterCounts( pageItems );
	const activeFilter = READINESS_FILTERS.some(
		( filter ) => filter.key === route.filter
	)
		? route.filter
		: 'all';
	const filteredPageItems = pageItems.filter( ( item ) =>
		matchesReadinessFilter( item, activeFilter )
	);

	return (
		<section
			className="dbvc-cc-v2-workspace"
			data-testid="dbvc-cc-v2-workspace-readiness"
		>
			<div className="dbvc-cc-v2-workspace__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Readiness Workspace</p>
					<h2>{ route.runId || 'journey-demo' }</h2>
				</div>
			</div>

			{ ! route.runId ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<h3>Select a run</h3>
					<p>
						The readiness workspace needs a V2 run before it can
						load QA summaries.
					</p>
				</div>
			) : null }

			{ route.runId && isLoading ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<p>Loading readiness and QA reports.</p>
				</div>
			) : null }

			{ route.runId && error ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<p>{ error }</p>
				</div>
			) : null }

			{ route.runId && ! isLoading && ! error ? (
				<>
					<ReadinessSummaryCards
						readinessStatus={ readinessStatus }
						summary={ summary }
						schemaFingerprint={ schemaFingerprint }
					/>

					<ReadinessToolbar
						route={ route }
						counts={ pageCounts }
						onRouteChange={ onRouteChange }
					/>

					<div className="dbvc-cc-v2-grid dbvc-cc-v2-grid--readiness-lists">
						<ReadinessIssuesList
							title="Blocking issues"
							items={ blockingItems }
							testId="dbvc-cc-v2-readiness-blockers"
							onItemAction={ handleItemAction }
						/>
						<ReadinessIssuesList
							title="Warnings"
							items={ warningItems }
							testId="dbvc-cc-v2-readiness-warnings"
							onItemAction={ handleItemAction }
						/>
					</div>

					<article className="dbvc-cc-v2-placeholder-card">
						<h3>Per-URL QA reports</h3>
						<ReadinessPagesTable
							items={ filteredPageItems }
							onItemAction={ handleItemAction }
							onOpenDrawer={ onOpenDrawer }
						/>
					</article>
				</>
			) : null }
		</section>
	);
}
