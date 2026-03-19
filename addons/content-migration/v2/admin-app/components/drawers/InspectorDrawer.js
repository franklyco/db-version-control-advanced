import { useEffect, useState } from '@wordpress/element';

import { request } from '../../api/client';
import useInspectorDetail from '../../hooks/useInspectorDetail';
import InspectorActionPanel from '../inspectors/InspectorActionPanel';
import InspectorAuditTab from '../inspectors/InspectorAuditTab';
import InspectorMappingTab from '../inspectors/InspectorMappingTab';
import InspectorSourceTab from '../inspectors/InspectorSourceTab';
import InspectorSummaryTab from '../inspectors/InspectorSummaryTab';

const PANEL_TABS = [ 'summary', 'source', 'mapping', 'audit' ];

export default function InspectorDrawer( {
	isOpen,
	onMutationComplete,
	route,
	onClose,
	onSelectTab,
	refreshToken,
} ) {
	const { detail, isLoading, error } = useInspectorDetail(
		route.runId,
		route.pageId,
		isOpen,
		refreshToken
	);
	const [ isBusy, setIsBusy ] = useState( false );
	const [ statusMessage, setStatusMessage ] = useState( '' );

	useEffect( () => {
		if ( ! isOpen ) {
			return undefined;
		}

		const handleKeyDown = ( event ) => {
			if ( event.key === 'Escape' ) {
				onClose();
			}
		};

		window.addEventListener( 'keydown', handleKeyDown );
		return () => window.removeEventListener( 'keydown', handleKeyDown );
	}, [ isOpen, onClose ] );

	const handleSave = async ( payload ) => {
		if ( ! route.runId || ! route.pageId ) {
			return;
		}

		setIsBusy( true );
		setStatusMessage( '' );

		try {
			await request(
				`runs/${ route.runId }/urls/${ route.pageId }/decision`,
				{
					method: 'POST',
					data: payload,
				}
			);
			setStatusMessage( 'Decision artifacts saved.' );
			onMutationComplete();
		} catch ( saveError ) {
			setStatusMessage(
				saveError instanceof Error
					? saveError.message
					: 'Could not save decision artifacts.'
			);
		} finally {
			setIsBusy( false );
		}
	};

	const handleRerun = async ( stage ) => {
		if ( ! route.runId || ! route.pageId ) {
			return;
		}

		setIsBusy( true );
		setStatusMessage( '' );

		try {
			await request(
				`runs/${ route.runId }/urls/${ route.pageId }/rerun`,
				{
					method: 'POST',
					data: {
						stage,
					},
				}
			);
			setStatusMessage( `Rerun queued for ${ stage }.` );
			onMutationComplete();
		} catch ( rerunError ) {
			setStatusMessage(
				rerunError instanceof Error
					? rerunError.message
					: 'Could not rerun the selected stage.'
			);
		} finally {
			setIsBusy( false );
		}
	};

	const renderTab = () => {
		if ( ! detail ) {
			return null;
		}

		if ( route.panelTab === 'source' ) {
			return <InspectorSourceTab detail={ detail } />;
		}

		if ( route.panelTab === 'mapping' ) {
			return <InspectorMappingTab detail={ detail } />;
		}

		if ( route.panelTab === 'audit' ) {
			return <InspectorAuditTab detail={ detail } />;
		}

		return <InspectorSummaryTab detail={ detail } />;
	};

	return (
		<aside
			className={ `dbvc-cc-v2-drawer${ isOpen ? ' is-open' : '' }` }
			data-testid="dbvc-cc-v2-inspector-drawer"
			data-open={ isOpen ? 'true' : 'false' }
			aria-hidden={ ! isOpen }
			hidden={ ! isOpen }
		>
			<div className="dbvc-cc-v2-drawer__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">URL Inspector</p>
					<h2 className="dbvc-cc-v2-drawer__title">
						{ detail?.path || route.pageId || 'No page selected' }
					</h2>
				</div>
				<button
					type="button"
					className="dbvc-cc-v2-drawer__close"
					data-testid="dbvc-cc-v2-drawer-close"
					onClick={ onClose }
				>
					Close
				</button>
			</div>

			<div
				className="dbvc-cc-v2-drawer__tabs"
				role="tablist"
				aria-label="Inspector tabs"
			>
				{ PANEL_TABS.map( ( panelTab ) => {
					const isActive = route.panelTab === panelTab;

					return (
						<button
							key={ panelTab }
							type="button"
							role="tab"
							className={ `dbvc-cc-v2-drawer__tab${
								isActive ? ' is-active' : ''
							}` }
							data-testid={ `dbvc-cc-v2-drawer-tab-${ panelTab }` }
							aria-selected={ isActive }
							onClick={ () => onSelectTab( panelTab ) }
						>
							{ panelTab }
						</button>
					);
				} ) }
			</div>

			<div className="dbvc-cc-v2-drawer__body">
				{ isLoading ? (
					<div className="dbvc-cc-v2-placeholder-card">
						<p>
							Loading URL evidence, recommendations, and
							decisions.
						</p>
					</div>
				) : null }
				{ error ? (
					<div className="dbvc-cc-v2-placeholder-card">
						<p>{ error }</p>
					</div>
				) : null }
				{ ! isLoading && ! error && detail ? renderTab() : null }
				{ detail ? (
					<InspectorActionPanel
						detail={ detail }
						isBusy={ isBusy }
						statusMessage={ statusMessage }
						onRerun={ handleRerun }
						onSave={ handleSave }
					/>
				) : null }
			</div>
		</aside>
	);
}
