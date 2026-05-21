import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import BookingsPage from './BookingsPage';
import ServicesPage from './ServicesPage';
import GooglePage from './GooglePage';
import SyncLogPage from './SyncLogPage';
import TemplatesPage from './TemplatesPage';
import Logo from './Logo';

export default function App() {
	const initial = window.location.hash.replace( '#/', '' ) || 'bookings';
	const version =
		( window.SlashBooking && window.SlashBooking.version ) || '';

	return (
		<div className="sb-admin">
			<header className="sb-app-header">
				<div className="sb-app-header__brand">
					<div className="sb-app-header__logo">
						<Logo size={ 40 } />
					</div>
					<div>
						<h1 className="sb-app-header__title">SlashBooking</h1>
						<p className="sb-app-header__subtitle">
							{ __(
								'Réservations en ligne, synchronisées avec Google Calendar',
								'slashbooking'
							) }
						</p>
					</div>
				</div>
				{ version && (
					<span className="sb-app-header__version">v{ version }</span>
				) }
			</header>

			<TabPanel
				className="sb-tabs"
				tabs={ [
					{ name: 'bookings',  title: __( 'Réservations', 'slashbooking' ) },
					{ name: 'services',  title: __( 'Services', 'slashbooking' ) },
					{ name: 'google',    title: __( 'Google', 'slashbooking' ) },
					{ name: 'templates', title: __( 'Templates', 'slashbooking' ) },
					{ name: 'log',       title: __( 'Journal', 'slashbooking' ) },
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
