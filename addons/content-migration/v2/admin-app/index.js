import { createRoot, render } from '@wordpress/element';

import ContentCollectorV2AppShell from './app/ContentCollectorV2AppShell';

const bootstrap = window.DBVC_CC_V2_APP || {};
const rootElement = document.getElementById( 'dbvc-cc-v2-root' );

if ( rootElement ) {
	const app = <ContentCollectorV2AppShell bootstrap={ bootstrap } />;

	if ( typeof createRoot === 'function' ) {
		createRoot( rootElement ).render( app );
	} else {
		render( app, rootElement );
	}
}
