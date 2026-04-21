const normalizeText = ( value ) =>
	typeof value === 'string' ? value.trim() : '';

const resolvePageId = ( item = {}, action = {} ) =>
	normalizeText( action.pageId ) ||
	normalizeText( item.pageId ) ||
	normalizeText( item.page_id );

const resolvePackageId = ( route = {}, item = {}, action = {} ) =>
	normalizeText( action.packageId ) ||
	normalizeText( item.packageId ) ||
	normalizeText( item.package_id ) ||
	normalizeText( route.packageId );

const resolveRunId = ( route = {}, item = {}, action = {} ) =>
	normalizeText( action.runId ) ||
	normalizeText( item.runId ) ||
	normalizeText( item.run_id ) ||
	normalizeText( route.runId );

export const buildOperatorActionRoute = (
	route = {},
	item = {},
	action = {}
) => {
	const target = normalizeText( action.target );
	const runId = resolveRunId( route, item, action );
	const pageId = resolvePageId( item, action );
	const packageId = resolvePackageId( route, item, action );

	if ( target === 'exceptions' ) {
		return {
			view: 'exceptions',
			runId,
			pageId,
			panel: pageId ? 'inspector' : '',
			panelTab: pageId
				? normalizeText( action.panelTab ) || 'summary'
				: 'summary',
			filter: normalizeText( action.filter ) || 'all',
			status: '',
			q: '',
			sort: '',
			packageId,
		};
	}

	if ( target === 'readiness' ) {
		return {
			view: 'readiness',
			runId,
			pageId,
			panel: pageId ? 'inspector' : '',
			panelTab: pageId
				? normalizeText( action.panelTab ) || 'audit'
				: 'summary',
			filter: normalizeText( action.filter ),
			status: '',
			q: '',
			sort: '',
			packageId,
		};
	}

	if ( target === 'package' ) {
		return {
			view: 'package',
			runId,
			pageId: '',
			panel: '',
			panelTab: 'summary',
			packageId,
		};
	}

	return null;
};
