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
	pending:   __( 'En attente', 'slashbooking' ),
	confirmed: __( 'Confirmé',   'slashbooking' ),
	rejected:  __( 'Refusé',     'slashbooking' ),
	cancelled: __( 'Annulé',     'slashbooking' ),
	completed: __( 'Passé',      'slashbooking' ),
};

export default function BookingRow( { booking, onAct } ) {
	const s = booking.status;
	return (
		<tr>
			<td className="sb-table__time">{ fmt( booking.starts_at_utc, booking.timezone ) }</td>
			<td>#{ booking.service_id }</td>
			<td>
				<div className="sb-table__customer">{ booking.customer_name }</div>
				<div className="sb-table__customer-meta">
					{ booking.customer_email } · { booking.customer_phone }
				</div>
			</td>
			<td>
				<span className={ `sb-status sb-status--${ s }` }>
					{ STATUS_LABELS[ s ] || s }
				</span>
			</td>
			<td>
				<div className="sb-table__actions">
					{ s === 'pending' && (
						<>
							<Button variant="primary" size="small" onClick={ () => onAct( booking.id, 'confirm' ) }>
								{ __( 'Confirmer', 'slashbooking' ) }
							</Button>
							<Button variant="secondary" size="small" onClick={ () => onAct( booking.id, 'reject' ) }>
								{ __( 'Refuser', 'slashbooking' ) }
							</Button>
						</>
					) }
					{ ( s === 'pending' || s === 'confirmed' ) && (
						<Button isDestructive variant="tertiary" size="small" onClick={ () => onAct( booking.id, 'cancel' ) }>
							{ __( 'Annuler', 'slashbooking' ) }
						</Button>
					) }
				</div>
			</td>
		</tr>
	);
}
