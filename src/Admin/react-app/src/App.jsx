import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import BookingsPage from './BookingsPage';
import GooglePage from './GooglePage';
import SyncLogPage from './SyncLogPage';

export default function App() {
	const initial = window.location.hash.replace( '#/', '' ) || 'bookings';
	return (
		<div className="tb-admin">
			<TabPanel
				className="tb-tabs"
				tabs={ [
					{
						name: 'bookings',
						title: __( 'Réservations', 'trinity-booking' ),
					},
					{
						name: 'google',
						title: __( 'Google', 'trinity-booking' ),
					},
					{ name: 'log', title: __( 'Journal', 'trinity-booking' ) },
				] }
				initialTabName={ initial }
				onSelect={ ( name ) => {
					window.history.replaceState( null, '', `#/${ name }` );
				} }
			>
				{ ( tab ) => {
					if ( tab.name === 'google' ) {
						return <GooglePage />;
					}
					if ( tab.name === 'log' ) {
						return <SyncLogPage />;
					}
					return <BookingsPage />;
				} }
			</TabPanel>
		</div>
	);
}
