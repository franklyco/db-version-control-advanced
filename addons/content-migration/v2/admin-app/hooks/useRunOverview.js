import {
	startTransition,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';

import { request } from '../api/client';
import { isActiveOverview } from '../workspaces/run-overview/overviewTransforms';

const AUTO_REFRESH_INTERVAL_MS = 10000;
const STALE_AFTER_MS = AUTO_REFRESH_INTERVAL_MS * 2;

const EMPTY_OVERVIEW = {
	runId: '',
	domain: '',
	inventory: {},
	latest: {},
	recentActivity: [],
	stageSummary: {},
};

export default function useRunOverview( runId, refreshToken = 0 ) {
	const [ refreshIndex, setRefreshIndex ] = useState( 0 );
	const [ now, setNow ] = useState( () => Date.now() );
	const [ state, setState ] = useState( {
		overview: EMPTY_OVERVIEW,
		isLoading: false,
		isRefreshing: false,
		error: '',
		lastLoadedAt: 0,
	} );

	const latest = useMemo(
		() =>
			state.overview && typeof state.overview.latest === 'object'
				? state.overview.latest
				: {},
		[ state.overview ]
	);
	const stageSummary = useMemo(
		() =>
			state.overview && typeof state.overview.stageSummary === 'object'
				? state.overview.stageSummary
				: {},
		[ state.overview ]
	);
	const isActive = useMemo(
		() => isActiveOverview( latest, stageSummary ),
		[ latest, stageSummary ]
	);

	useEffect( () => {
		const intervalId = window.setInterval( () => {
			setNow( Date.now() );
		}, 1000 );

		return () => {
			window.clearInterval( intervalId );
		};
	}, [] );

	useEffect( () => {
		if ( ! runId ) {
			setState( {
				overview: EMPTY_OVERVIEW,
				isLoading: false,
				isRefreshing: false,
				error: '',
				lastLoadedAt: 0,
			} );
			return undefined;
		}

		let isMounted = true;
		const controller = new AbortController();

		setState( ( currentState ) => ( {
			...currentState,
			isLoading: currentState.lastLoadedAt === 0,
			isRefreshing: currentState.lastLoadedAt > 0,
			error: '',
		} ) );

		request( `runs/${ runId }/overview`, {
			signal: controller.signal,
		} )
			.then( ( payload ) => {
				if ( ! isMounted ) {
					return;
				}

				setState( {
					overview:
						payload && typeof payload === 'object'
							? payload
							: EMPTY_OVERVIEW,
					isLoading: false,
					isRefreshing: false,
					error: '',
					lastLoadedAt: Date.now(),
				} );
			} )
			.catch( ( error ) => {
				if ( ! isMounted || controller.signal.aborted ) {
					return;
				}

				setState( ( currentState ) => ( {
					...currentState,
					isLoading: false,
					isRefreshing: false,
					error:
						error instanceof Error
							? error.message
							: 'Could not load the V2 run overview.',
				} ) );
			} );

		return () => {
			isMounted = false;
			controller.abort();
		};
	}, [ refreshIndex, refreshToken, runId ] );

	useEffect( () => {
		if ( ! runId || ! isActive ) {
			return undefined;
		}

		const intervalId = window.setInterval( () => {
			startTransition( () => {
				setRefreshIndex( ( currentValue ) => currentValue + 1 );
			} );
		}, AUTO_REFRESH_INTERVAL_MS );

		return () => {
			window.clearInterval( intervalId );
		};
	}, [ isActive, runId ] );

	const refresh = () => {
		startTransition( () => {
			setRefreshIndex( ( currentValue ) => currentValue + 1 );
		} );
	};

	const ageMs =
		state.lastLoadedAt > 0 ? Math.max( 0, now - state.lastLoadedAt ) : 0;

	return {
		...state,
		ageMs,
		isActive,
		isStale:
			runId !== '' &&
			state.lastLoadedAt > 0 &&
			isActive &&
			ageMs >= STALE_AFTER_MS &&
			! state.isLoading &&
			! state.isRefreshing,
		refresh,
	};
}
