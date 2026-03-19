const WORKSPACE_LABELS = {
	runs: 'Runs',
	overview: 'Overview',
	exceptions: 'Exceptions',
	readiness: 'Readiness',
	package: 'Package',
};

export default function WorkspaceNav( { currentView, views, onSelectView } ) {
	return (
		<nav
			className="dbvc-cc-v2-nav"
			aria-label="Content Collector workspaces"
			data-testid="dbvc-cc-v2-nav"
		>
			{ views.map( ( view ) => {
				const isActive = currentView === view;

				return (
					<button
						key={ view }
						type="button"
						className={ `dbvc-cc-v2-nav__button${
							isActive ? ' is-active' : ''
						}` }
						data-testid={ `dbvc-cc-v2-nav-${ view }` }
						aria-pressed={ isActive }
						onClick={ () => onSelectView( view ) }
					>
						{ WORKSPACE_LABELS[ view ] || view }
					</button>
				);
			} ) }
		</nav>
	);
}
