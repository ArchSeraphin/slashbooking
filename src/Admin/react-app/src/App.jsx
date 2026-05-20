import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import BookingsPage from './BookingsPage';
import ServicesPage from './ServicesPage';
import GooglePage from './GooglePage';
import SyncLogPage from './SyncLogPage';
import TemplatesPage from './TemplatesPage';

export default function App() {
	const initial = window.location.hash.replace( '#/', '' ) || 'bookings';
	const version =
		( window.TrinityBooking && window.TrinityBooking.version ) || '';

	return (
		<div className="tb-admin">
			<header className="tb-app-header">
				<div className="tb-app-header__brand">
					<div className="tb-app-header__logo" aria-hidden="true">
						TB
					</div>
					<div>
						<h1 className="tb-app-header__title">Trinity Booking</h1>
						<p className="tb-app-header__subtitle">
							{ __(
								'Prise de RDV photovoltaïque & bornes IRVE',
								'trinity-booking'
							) }
						</p>
					</div>
				</div>
				{ version && (
					<span className="tb-app-header__version">v{ version }</span>
				) }
			</header>

			<TabPanel
				className="tb-tabs"
				tabs={ [
					{ name: 'bookings',  title: __( 'Réservations', 'trinity-booking' ) },
					{ name: 'services',  title: __( 'Services', 'trinity-booking' ) },
					{ name: 'google',    title: __( 'Google', 'trinity-booking' ) },
					{ name: 'templates', title: __( 'Templates', 'trinity-booking' ) },
					{ name: 'log',       title: __( 'Journal', 'trinity-booking' ) },
				] }
				initialTabName={ initial }
				onSelect={ ( name ) => {
					window.history.replaceState( null, '', `#/${ name }` );
				} }
			>
				{ ( tab ) => {
					if ( tab.name === 'services' )  return <ServicesPage />;
					if ( tab.name === 'google' )    return <GooglePage />;
					if ( tab.name === 'templates' ) return <TemplatesPage />;
					if ( tab.name === 'log' )       return <SyncLogPage />;
					return <BookingsPage />;
				} }
			</TabPanel>
		</div>
	);
}
