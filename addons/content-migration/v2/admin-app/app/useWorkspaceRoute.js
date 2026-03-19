import { useEffect, useState } from '@wordpress/element';

const WORKSPACE_VIEWS = [
	'runs',
	'overview',
	'exceptions',
	'readiness',
	'package',
];
const DEFAULT_PANEL = 'inspector';
const DEFAULT_PANEL_TAB = 'summary';

const normalizeText = ( value ) =>
	typeof value === 'string' ? value.trim() : '';

const normalizeView = ( view ) =>
	WORKSPACE_VIEWS.includes( view ) ? view : 'runs';

const normalizeRoute = ( route = {}, defaultRunId = '' ) => {
	const view = normalizeView( route.view );
	const runId =
		normalizeText( route.runId ) || ( view === 'runs' ? '' : defaultRunId );
	const pageId = normalizeText( route.pageId );

	return {
		view,
		runId,
		pageId,
		panel:
			pageId !== '' ? normalizeText( route.panel ) || DEFAULT_PANEL : '',
		panelTab: normalizeText( route.panelTab ) || DEFAULT_PANEL_TAB,
		filter: normalizeText( route.filter ),
		status: normalizeText( route.status ),
		q: normalizeText( route.q ),
		sort: normalizeText( route.sort ),
		packageId: normalizeText( route.packageId ),
	};
};

const syncRouteToUrl = ( route ) => {
	if (
		typeof window === 'undefined' ||
		! window.location ||
		! window.history
	) {
		return;
	}

	const params = new URLSearchParams( window.location.search );
	const routeEntries = {
		view: route.view,
		runId: route.runId,
		pageId: route.pageId,
		panel: route.panel,
		panelTab: route.panelTab,
		filter: route.filter,
		status: route.status,
		q: route.q,
		sort: route.sort,
		packageId: route.packageId,
	};

	Object.entries( routeEntries ).forEach( ( [ key, value ] ) => {
		if ( value ) {
			params.set( key, value );
			return;
		}

		params.delete( key );
	} );

	const queryString = params.toString();
	const nextUrl = queryString
		? `${ window.location.pathname }?${ queryString }`
		: window.location.pathname;
	const currentUrl = `${ window.location.pathname }${ window.location.search }`;

	if ( nextUrl !== currentUrl ) {
		window.history.replaceState( {}, '', nextUrl );
	}
};

export default function useWorkspaceRoute( initialRoute, defaultRunId ) {
	const [ route, setRoute ] = useState( () =>
		normalizeRoute( initialRoute, defaultRunId )
	);

	useEffect( () => {
		syncRouteToUrl( route );
	}, [ route ] );

	const navigate = ( nextRoute ) => {
		setRoute( ( currentRoute ) => {
			const draft =
				typeof nextRoute === 'function'
					? nextRoute( currentRoute )
					: nextRoute;
			return normalizeRoute(
				{ ...currentRoute, ...draft },
				defaultRunId
			);
		} );
	};

	const selectView = ( view ) => {
		navigate( ( currentRoute ) => ( {
			view,
			runId:
				view === 'runs'
					? currentRoute.runId
					: currentRoute.runId || defaultRunId,
			pageId: '',
			panel: '',
			panelTab: DEFAULT_PANEL_TAB,
		} ) );
	};

	const selectRun = ( runId, view = 'overview' ) => {
		navigate( {
			view,
			runId: normalizeText( runId ) || defaultRunId,
			pageId: '',
			panel: '',
			panelTab: DEFAULT_PANEL_TAB,
		} );
	};

	const openDrawer = ( pageId, panelTab = DEFAULT_PANEL_TAB ) => {
		navigate( {
			pageId,
			panel: DEFAULT_PANEL,
			panelTab,
		} );
	};

	const closeDrawer = () => {
		navigate( {
			pageId: '',
			panel: '',
			panelTab: DEFAULT_PANEL_TAB,
		} );
	};

	return {
		route,
		selectRun,
		selectView,
		updateRoute: navigate,
		openDrawer,
		closeDrawer,
		isDrawerOpen: route.panel === DEFAULT_PANEL && route.pageId !== '',
	};
}
