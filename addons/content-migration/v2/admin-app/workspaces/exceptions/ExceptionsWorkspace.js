import { useEffect } from '@wordpress/element';

import BulkReviewPanel from '../../components/exceptions/BulkReviewPanel';
import ExceptionsTable from '../../components/exceptions/ExceptionsTable';
import ExceptionsToolbar from '../../components/exceptions/ExceptionsToolbar';
import useBulkReviewActions from '../../hooks/useBulkReviewActions';
import useExceptionsQueue from '../../hooks/useExceptionsQueue';

export default function ExceptionsWorkspace( {
	onMutationComplete,
	onQueueItemsChange,
	refreshToken,
	route,
	onOpenDrawer,
	onRouteChange,
} ) {
	const { items, counts, isLoading, error } = useExceptionsQueue(
		route.runId,
		route,
		refreshToken
	);
	const bulkReview = useBulkReviewActions(
		route.runId,
		items,
		onMutationComplete
	);

	useEffect( () => {
		if ( typeof onQueueItemsChange !== 'function' ) {
			return undefined;
		}

		onQueueItemsChange( {
			runId: route.runId || '',
			items,
			filter: route.filter || '',
			status: route.status || '',
			q: route.q || '',
			sort: route.sort || '',
		} );
	}, [
		items,
		onQueueItemsChange,
		route.filter,
		route.q,
		route.runId,
		route.sort,
		route.status,
	] );

	return (
		<section
			className="dbvc-cc-v2-workspace"
			data-testid="dbvc-cc-v2-workspace-exceptions"
		>
			<div className="dbvc-cc-v2-workspace__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Exceptions Workspace</p>
					<h2>{ route.runId || 'journey-demo' }</h2>
				</div>
			</div>

			{ route.runId ? (
				<>
					<ExceptionsToolbar
						route={ route }
						counts={ counts }
						onRouteChange={ onRouteChange }
					/>
					<BulkReviewPanel
						bulkReview={ bulkReview }
						onBulkAction={ bulkReview.applyBulkAction }
					/>
					<ExceptionsTable
						bulkReview={ bulkReview }
						items={ items }
						isLoading={ isLoading }
						error={ error }
						onOpenDrawer={ onOpenDrawer }
					/>
				</>
			) : (
				<div className="dbvc-cc-v2-placeholder-card">
					<h3>Select a run</h3>
					<p>
						The Phase 6 queue needs a V2 run context before it can
						load exception rows.
					</p>
				</div>
			) }
		</section>
	);
}
