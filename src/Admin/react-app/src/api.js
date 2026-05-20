import apiFetch from '@wordpress/api-fetch';

export function setupApi() {
	if ( window.TrinityBooking?.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( window.TrinityBooking.nonce ) );
	}
	if ( window.TrinityBooking?.restUrl ) {
		apiFetch.use( apiFetch.createRootURLMiddleware( window.TrinityBooking.restUrl + '/' ) );
	}
}

export async function listBookings( params = {} ) {
	const qs = new URLSearchParams( params ).toString();
	return apiFetch( { path: 'admin/bookings' + ( qs ? '?' + qs : '' ) } );
}

export async function actBooking( id, action ) {
	return apiFetch( {
		path: `admin/bookings/${ id }/${ action }`,
		method: 'POST',
	} );
}
