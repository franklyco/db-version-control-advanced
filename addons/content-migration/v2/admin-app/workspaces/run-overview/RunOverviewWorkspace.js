import OverviewNextActions from '../../components/run-overview/OverviewNextActions';
import OverviewRecentActivity from '../../components/run-overview/OverviewRecentActivity';
import OverviewStageCards from '../../components/run-overview/OverviewStageCards';
import OverviewSummaryCards from '../../components/run-overview/OverviewSummaryCards';
import useRunOverview from '../../hooks/useRunOverview';
import { formatTimestamp, humanizeKey } from './overviewTransforms';

const buildRefreshCopy = ( {
	ageMs,
	isActive,
	isRefreshing,
	isStale,
	lastLoadedAt,
} ) => {
	if ( isRefreshing ) {
		return 'Refreshing selected run state.';
	}

	if ( ! lastLoadedAt ) {
		return 'Waiting for the first overview payload.';
	}

	if ( isStale ) {
		return `Overview data is older than ${ Math.round(
			ageMs / 1000
		) }s and may be stale.`;
	}

	if ( isActive ) {
		return `Auto-refresh every 10s. Last update ${ formatTimestamp(
			lastLoadedAt
		) }.`;
	}

	return `Last updated ${ formatTimestamp( lastLoadedAt ) }.`;
};

export default function RunOverviewWorkspace( {
	onNavigateToView,
	onOpenDrawer,
	refreshToken,
	route,
} ) {
	const {
		ageMs,
		error,
		isActive,
		isLoading,
		isRefreshing,
		isStale,
		lastLoadedAt,
		overview,
		refresh,
	} = useRunOverview( route.runId, refreshToken );
	const latest =
		overview && typeof overview.latest === 'object' ? overview.latest : {};
	const inventory =
		overview && typeof overview.inventory === 'object'
			? overview.inventory
			: {};
	const stageSummary =
		overview && typeof overview.stageSummary === 'object'
			? overview.stageSummary
			: {};
	const recentActivity =
		overview && Array.isArray( overview.recentActivity )
			? overview.recentActivity
			: [];

	return (
		<section
			className="dbvc-cc-v2-workspace"
			data-testid="dbvc-cc-v2-workspace-overview"
		>
			<div className="dbvc-cc-v2-workspace__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Run Overview</p>
					<h2>{ route.runId || 'journey-demo' }</h2>
					<p className="dbvc-cc-v2-overview__refresh-copy">
						{ buildRefreshCopy( {
							ageMs,
							isActive,
							isRefreshing,
							isStale,
							lastLoadedAt,
						} ) }
					</p>
				</div>

				<div className="dbvc-cc-v2-header__actions">
					<span
						className="dbvc-cc-v2-chip dbvc-cc-v2-chip--muted"
						data-testid="dbvc-cc-v2-overview-run-status"
					>
						{ humanizeKey( latest.status || 'queued' ) }
					</span>
					<button
						type="button"
						className="button button-secondary"
						data-testid="dbvc-cc-v2-overview-refresh"
						disabled={ isLoading || isRefreshing }
						onClick={ refresh }
					>
						{ isRefreshing ? 'Refreshing...' : 'Refresh overview' }
					</button>
					<button
						type="button"
						className="button button-secondary"
						onClick={ () =>
							onOpenDrawer( 'overview-home', 'summary' )
						}
					>
						Open inspector
					</button>
				</div>
			</div>

			{ ! route.runId ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<h3>Select a run</h3>
					<p>
						The overview workspace needs a V2 run before it can show
						pipeline state.
					</p>
				</div>
			) : null }

			{ route.runId && isLoading ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<p>Loading the selected-run overview.</p>
				</div>
			) : null }

			{ route.runId && error ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<p>{ error }</p>
				</div>
			) : null }

			{ route.runId && ! isLoading && ! error ? (
				<>
					<OverviewSummaryCards
						inventory={ inventory }
						latest={ latest }
						stageSummary={ stageSummary }
					/>

					<div
						className="dbvc-cc-v2-overview__meta"
						data-testid="dbvc-cc-v2-overview-refresh-status"
					>
						<p>
							<strong>Started:</strong>{ ' ' }
							{ formatTimestamp( latest.started_at ) }
						</p>
						<p>
							<strong>Last update:</strong>{ ' ' }
							{ formatTimestamp( latest.updated_at ) }
						</p>
						<p>
							<strong>Refresh mode:</strong>{ ' ' }
							{ isActive
								? 'Auto-refresh every 10s'
								: 'Manual refresh only' }
						</p>
					</div>

					<OverviewStageCards stageSummary={ stageSummary } />

					<OverviewRecentActivity recentActivity={ recentActivity } />

					<OverviewNextActions
						latest={ latest }
						onNavigate={ onNavigateToView }
					/>
				</>
			) : null }
		</section>
	);
}
