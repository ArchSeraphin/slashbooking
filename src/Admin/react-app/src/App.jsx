import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import BookingsPage from './BookingsPage';

export default function App() {
	return (
		<div className="tb-admin">
			<Notice status="info" isDismissible={ false }>
				{ __( 'Trinity Booking — dashboard V1', 'trinity-booking' ) }
			</Notice>
			<BookingsPage />
		</div>
	);
}
