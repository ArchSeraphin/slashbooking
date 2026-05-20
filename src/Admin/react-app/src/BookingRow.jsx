import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

function fmt( iso, tz ) {
	try {
		return new Intl.DateTimeFormat( 'fr-FR', {
			dateStyle: 'medium', timeStyle: 'short', timeZone: tz,
		} ).format( new Date( iso ) );
	} catch ( e ) {
		return iso;
	}
}

const STATUS_LABELS = {
	pending:   __( 'En attente', 'trinity-booking' ),
	confirmed: __( 'Confirmé',   'trinity-booking' ),
	rejected:  __( 'Refusé',     'trinity-booking' ),
	cancelled: __( 'Annulé',     'trinity-booking' ),
	completed: __( 'Passé',      'trinity-booking' ),
};

export default function BookingRow( { booking, onAct } ) {
	const s = booking.status;
	return (
		<tr>
			<td className="tb-table__time">{ fmt( booking.starts_at_utc, booking.timezone ) }</td>
			<td>#{ booking.service_id }</td>
			<td>
				<div className="tb-table__customer">{ booking.customer_name }</div>
				<div className="tb-table__customer-meta">
					{ booking.customer_email } · { booking.customer_phone }
				</div>
			</td>
			<td>
				<span className={ `tb-status tb-status--${ s }` }>
					{ STATUS_LABELS[ s ] || s }
				</span>
			</td>
			<td>
				<div className="tb-table__actions">
					{ s === 'pending' && (
						<>
							<Button variant="primary" size="small" onClick={ () => onAct( booking.id, 'confirm' ) }>
								{ __( 'Confirmer', 'trinity-booking' ) }
							</Button>
							<Button variant="secondary" size="small" onClick={ () => onAct( booking.id, 'reject' ) }>
								{ __( 'Refuser', 'trinity-booking' ) }
							</Button>
						</>
					) }
					{ ( s === 'pending' || s === 'confirmed' ) && (
						<Button isDestructive variant="tertiary" size="small" onClick={ () => onAct( booking.id, 'cancel' ) }>
							{ __( 'Annuler', 'trinity-booking' ) }
						</Button>
					) }
				</div>
			</td>
		</tr>
	);
}
