const bootstrap = window.DBVC_CC_V2_APP || {};

const buildEndpoint = ( path ) => {
	const apiRoot =
		typeof bootstrap.apiRoot === 'string' ? bootstrap.apiRoot : '';
	const normalizedPath = `${ path || '' }`.replace( /^\/+/, '' );
	return `${ apiRoot }${ normalizedPath }`;
};

const parseErrorMessage = async ( response ) => {
	try {
		const payload = await response.json();
		if (
			payload &&
			typeof payload.message === 'string' &&
			payload.message
		) {
			return payload.message;
		}
	} catch ( error ) {}

	return `Request failed with status ${ response.status }`;
};

export const getBootstrap = () => bootstrap;

export async function request( path, options = {} ) {
	const endpoint = buildEndpoint( path );
	const { method = 'GET', data, signal, headers = {} } = options;
	const response = await window.fetch( endpoint, {
		method,
		signal,
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': bootstrap.nonce || '',
			...headers,
		},
		body: data ? JSON.stringify( data ) : undefined,
	} );

	if ( ! response.ok ) {
		throw new Error( await parseErrorMessage( response ) );
	}

	const payload = await response.json();
	return payload && typeof payload === 'object' ? payload : {};
}
