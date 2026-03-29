import { resolveReadinessIssueAction } from '../readiness/readinessActions';

const toArray = ( value ) => ( Array.isArray( value ) ? value : [] );

const resolveScopeLabel = ( item = {} ) =>
	item.path ||
	item.sourceUrl ||
	item.source_url ||
	item.pageId ||
	item.page_id ||
	'';

const buildActionDescription = ( item = {}, fallback ) => {
	const scopeLabel = resolveScopeLabel( item );
	const fragments = [ item.message || fallback ];

	if ( scopeLabel ) {
		fragments.push( scopeLabel );
	}

	return fragments.filter( Boolean ).join( ' ' );
};

export const buildPackageNextActions = ( packageDetail = {} ) => {
	const packageId =
		packageDetail?.packageId ||
		packageDetail?.summary?.package_id ||
		packageDetail?.manifest?.package_id ||
		'';
	const blockingIssues = toArray( packageDetail?.qaReport?.blocking_issues );
	const warningItems = toArray( packageDetail?.qaReport?.warnings );
	const actions = [];
	const primaryBlocker = blockingIssues[ 0 ];
	const primaryWarning = warningItems[ 0 ];

	if ( primaryBlocker ) {
		const blockerAction = resolveReadinessIssueAction( primaryBlocker ) || {
			label: 'Inspect blocker',
			target: 'readiness',
			panelTab: 'audit',
		};

		actions.push( {
			key: 'primary-blocker',
			title: 'Resolve primary blocker',
			description: buildActionDescription(
				primaryBlocker,
				'This package still has a blocking issue.'
			),
			priority: 'primary',
			action: {
				...blockerAction,
				packageId,
			},
			item: primaryBlocker,
		} );
	}

	if ( primaryWarning ) {
		const warningAction = resolveReadinessIssueAction( primaryWarning ) || {
			label: 'Inspect warning',
			target: 'readiness',
			panelTab: 'audit',
		};

		actions.push( {
			key: 'primary-warning',
			title: 'Review first warning',
			description: buildActionDescription(
				primaryWarning,
				'This package still has a warning worth checking before import.'
			),
			priority: primaryBlocker ? 'secondary' : 'primary',
			action: {
				...warningAction,
				packageId,
			},
			item: primaryWarning,
		} );
	}

	return actions;
};
