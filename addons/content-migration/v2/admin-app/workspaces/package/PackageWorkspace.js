import { useEffect, useRef, useState } from '@wordpress/element';

import { request } from '../../api/client';
import PackageDetailPanel from '../../components/package/PackageDetailPanel';
import PackageDryRunPanel from '../../components/package/PackageDryRunPanel';
import PackageHistoryTable from '../../components/package/PackageHistoryTable';
import PackageImportHistoryPanel from '../../components/package/PackageImportHistoryPanel';
import useDryRunSurface from '../../hooks/useDryRunSurface';
import PackageImportPanel from '../../components/package/PackageImportPanel';
import useImportExecutionBridge from '../../hooks/useImportExecutionBridge';
import usePackageSurface from '../../hooks/usePackageSurface';
import PackageWorkflowPanel from '../../components/package/PackageWorkflowPanel';

export default function PackageWorkspace( {
	onMutationComplete,
	onRouteChange,
	refreshToken,
	route,
} ) {
	const [ isBuilding, setIsBuilding ] = useState( false );
	const [ buildError, setBuildError ] = useState( '' );
	const [ dryRunRequestToken, setDryRunRequestToken ] = useState( 0 );
	const lastRecordedDryRunAt = useRef( '' );
	const {
		readinessStatus,
		readiness,
		history,
		selectedPackageId,
		selectedPackage,
		isLoading,
		error,
	} = usePackageSurface( route.runId, route.packageId, refreshToken );
	const activePackageId = selectedPackageId || route.packageId || '';
	const {
		data: dryRunSurface,
		isLoading: isDryRunLoading,
		error: dryRunError,
	} = useDryRunSurface( route.runId, activePackageId, dryRunRequestToken );
	const {
		preflight,
		execution,
		isPreflightLoading,
		isExecuteLoading,
		error: importError,
		requestPreflight,
		executeImport,
	} = useImportExecutionBridge( route.runId, activePackageId );

	useEffect( () => {
		setDryRunRequestToken( 0 );
	}, [ route.runId, activePackageId ] );

	useEffect( () => {
		if (
			! dryRunSurface?.generatedAt ||
			dryRunSurface.generatedAt === lastRecordedDryRunAt.current
		) {
			return;
		}

		lastRecordedDryRunAt.current = dryRunSurface.generatedAt;
		onMutationComplete();
	}, [ dryRunSurface?.generatedAt, onMutationComplete ] );

	const handleBuild = async () => {
		if ( ! route.runId || isBuilding ) {
			return;
		}

		setIsBuilding( true );
		setBuildError( '' );

		try {
			const payload = await request( `runs/${ route.runId }/package`, {
				method: 'POST',
			} );
			setDryRunRequestToken( 0 );
			if ( payload.selectedPackageId ) {
				onRouteChange( { packageId: payload.selectedPackageId } );
			}
			onMutationComplete();
		} catch ( mutationError ) {
			setBuildError(
				mutationError instanceof Error
					? mutationError.message
					: 'Could not build the V2 package.'
			);
		} finally {
			setIsBuilding( false );
		}
	};

	const handlePreviewDryRun = () => {
		if ( ! route.runId || ! activePackageId ) {
			return;
		}

		setDryRunRequestToken( ( currentValue ) => currentValue + 1 );
	};

	const handleRequestPreflight = async () => {
		const payload = await requestPreflight();
		if ( payload ) {
			onMutationComplete();
		}
	};

	const handleExecuteImport = async () => {
		const payload = await executeImport();
		if ( payload ) {
			onMutationComplete();
		}
	};

	return (
		<section
			className="dbvc-cc-v2-workspace"
			data-testid="dbvc-cc-v2-workspace-package"
		>
			<div className="dbvc-cc-v2-workspace__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Package Workspace</p>
					<h2>{ route.runId || 'journey-demo' }</h2>
				</div>
				<div className="dbvc-cc-v2-actions">
					<button
						type="button"
						className="button"
						data-testid="dbvc-cc-v2-package-dry-run-trigger"
						disabled={
							! route.runId ||
							! activePackageId ||
							isDryRunLoading
						}
						onClick={ handlePreviewDryRun }
					>
						{ isDryRunLoading
							? 'Running dry-run…'
							: 'Preview dry-run' }
					</button>
					<button
						type="button"
						className="button button-primary"
						data-testid="dbvc-cc-v2-package-build"
						disabled={ ! route.runId || isBuilding }
						onClick={ handleBuild }
					>
						{ isBuilding ? 'Building package…' : 'Build package' }
					</button>
				</div>
			</div>

			{ ! route.runId ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<h3>Select a run</h3>
					<p>
						The package workspace needs a V2 run before it can
						inspect or build packages.
					</p>
				</div>
			) : null }

			{ route.runId && isLoading ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<p>Loading package history and readiness.</p>
				</div>
			) : null }

			{ route.runId &&
			( error || buildError || dryRunError || importError ) ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<p>{ buildError || dryRunError || importError || error }</p>
				</div>
			) : null }

			{ route.runId && ! isLoading && ! error ? (
				<>
					<div className="dbvc-cc-v2-grid">
						<article className="dbvc-cc-v2-placeholder-card">
							<p className="dbvc-cc-v2-eyebrow">
								Current readiness
							</p>
							<strong>{ readinessStatus || 'unknown' }</strong>
						</article>
						<article className="dbvc-cc-v2-placeholder-card">
							<p className="dbvc-cc-v2-eyebrow">Build history</p>
							<strong>{ history.length }</strong>
						</article>
						<article className="dbvc-cc-v2-placeholder-card">
							<p className="dbvc-cc-v2-eyebrow">Ready pages</p>
							<strong>
								{ readiness?.summary?.readyPages ?? 0 }
							</strong>
						</article>
					</div>

					<article className="dbvc-cc-v2-placeholder-card">
						<h3>Package history</h3>
						<PackageHistoryTable
							items={ history }
							selectedPackageId={ selectedPackageId }
							onSelectPackage={ ( packageId ) =>
								onRouteChange( { packageId } )
							}
						/>
					</article>

					<PackageDetailPanel packageDetail={ selectedPackage } />

					<PackageWorkflowPanel
						workflowState={ selectedPackage?.workflowState }
					/>

					<PackageDryRunPanel dryRunSurface={ dryRunSurface } />

					<PackageImportPanel
						preflight={ preflight }
						execution={ execution }
						error={ importError }
						isPreflightLoading={ isPreflightLoading }
						isExecuteLoading={ isExecuteLoading }
						onRequestPreflight={ handleRequestPreflight }
						onExecuteImport={ handleExecuteImport }
						hasPackage={ !! activePackageId }
					/>

					<PackageImportHistoryPanel
						items={ selectedPackage?.importHistory || [] }
					/>
				</>
			) : null }
		</section>
	);
}
