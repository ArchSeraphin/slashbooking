import { useEffect, useState } from '@wordpress/element';
import { Card, CardBody, CardHeader, Notice, Spinner, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { listMailTemplates } from './api';
import TemplateEditor from './TemplateEditor';

const EVENT_LABELS = {
	'booking.pending.client'   : 'Demande reçue (client)',
	'booking.pending.admin'    : 'Nouvelle demande (admin)',
	'booking.confirmed.client' : 'RDV confirmé (client)',
	'booking.rejected.client'  : 'RDV refusé (client)',
	'booking.cancelled.client' : 'Annulation prise en compte (client)',
	'booking.reminder.client'  : 'Rappel J-1 (client)',
};

export default function TemplatesPage() {
	const [ items, setItems ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( null );

	const reload = async () => {
		setLoading( true );
		setError( null );
		try {
			const data = await listMailTemplates();
			setItems( data.templates );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		reload();
	}, [] );

	if ( selected ) {
		return (
			<TemplateEditor
				eventKey={ selected }
				onClose={ () => {
					setSelected( null );
					reload();
				} }
			/>
		);
	}

	return (
		<div className="tb-templates-page">
			<Card>
				<CardHeader>
					<h2>{ __( 'Templates e-mail', 'trinity-booking' ) }</h2>
				</CardHeader>
				<CardBody>
					{ loading && <Spinner /> }
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					{ items && (
						<table className="widefat striped tb-templates-table">
							<thead>
								<tr>
									<th>{ __( 'Évènement', 'trinity-booking' ) }</th>
									<th>{ __( 'Sujet', 'trinity-booking' ) }</th>
									<th>{ __( 'État', 'trinity-booking' ) }</th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( t ) => (
									<tr key={ t.event_key }>
										<td>
											<strong>
												{ EVENT_LABELS[ t.event_key ] || t.event_key }
											</strong>
											<br />
											<code style={ { fontSize: '11px', color: '#666' } }>
												{ t.event_key }
											</code>
										</td>
										<td>{ t.subject }</td>
										<td>
											{ t.is_custom ? (
												<span className="tb-badge tb-badge-custom">
													{ __( 'Personnalisé', 'trinity-booking' ) }
												</span>
											) : (
												<span className="tb-badge tb-badge-default">
													{ __( 'Défaut', 'trinity-booking' ) }
												</span>
											) }
										</td>
										<td>
											<Button
												variant="secondary"
												onClick={ () => setSelected( t.event_key ) }
											>
												{ __( 'Modifier', 'trinity-booking' ) }
											</Button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
