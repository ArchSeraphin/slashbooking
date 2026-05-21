import { useEffect, useState } from '@wordpress/element';
import { Card, CardBody, CardHeader, Notice, Spinner, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { listServices } from './api';
import ServiceEditor from './ServiceEditor';

function formatDuration( min ) {
	if ( min < 60 ) return `${ min } min`;
	const h = Math.floor( min / 60 );
	const m = min % 60;
	return m === 0 ? `${ h } h` : `${ h } h ${ String( m ).padStart( 2, '0' ) }`;
}

const DAY_LABELS = [ 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim' ];

function daysSummary( weekly ) {
	const open = [];
	for ( let d = 1; d <= 7; d++ ) {
		const ranges = weekly[ String( d ) ] || weekly[ d ] || [];
		if ( ranges.length > 0 ) open.push( DAY_LABELS[ d - 1 ] );
	}
	return open.length === 0 ? __( 'Aucun jour', 'slashbooking' ) : open.join( ' · ' );
}

export default function ServicesPage() {
	const [ items, setItems ]     = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ]     = useState( null );
	const [ selected, setSelected ] = useState( null );

	const reload = async () => {
		setLoading( true );
		setError( null );
		try {
			const data = await listServices();
			setItems( data.services );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => { reload(); }, [] );

	if ( selected ) {
		return (
			<ServiceEditor
				slug={ selected }
				onClose={ () => { setSelected( null ); reload(); } }
			/>
		);
	}

	return (
		<Card>
			<CardHeader>
				<h2 style={ { margin: 0, fontSize: 16, fontWeight: 600 } }>
					{ __( 'Services & horaires', 'slashbooking' ) }
				</h2>
			</CardHeader>
			<CardBody>
				{ loading && <Spinner /> }
				{ error && (
					<Notice status="error" isDismissible={ false }>{ error }</Notice>
				) }
				{ items && items.length === 0 && (
					<p>{ __( 'Aucun service.', 'slashbooking' ) }</p>
				) }
				{ items && items.length > 0 && (
					<table className="sb-table">
						<thead>
							<tr>
								<th>{ __( 'Service', 'slashbooking' ) }</th>
								<th>{ __( 'Durée', 'slashbooking' ) }</th>
								<th>{ __( 'Buffer', 'slashbooking' ) }</th>
								<th>{ __( 'Jours ouverts', 'slashbooking' ) }</th>
								<th>{ __( 'Statut', 'slashbooking' ) }</th>
								<th style={ { textAlign: 'right' } }></th>
							</tr>
						</thead>
						<tbody>
							{ items.map( ( s ) => (
								<tr key={ s.slug }>
									<td>
										<div className="sb-table__customer">{ s.name }</div>
										<div className="sb-table__customer-meta">
											<code>{ s.slug }</code>
										</div>
									</td>
									<td className="sb-table__time">
										{ formatDuration( s.duration_min ) }
									</td>
									<td className="sb-table__time">
										{ s.buffer_after_min > 0
											? `+${ s.buffer_after_min } min`
											: '—' }
									</td>
									<td>{ daysSummary( s.weekly_hours ) }</td>
									<td>
										<span className={ `sb-status sb-status--${ s.active ? 'confirmed' : 'cancelled' }` }>
											{ s.active
												? __( 'Actif', 'slashbooking' )
												: __( 'Désactivé', 'slashbooking' ) }
										</span>
									</td>
									<td style={ { textAlign: 'right' } }>
										<Button variant="secondary" size="small" onClick={ () => setSelected( s.slug ) }>
											{ __( 'Modifier', 'slashbooking' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</CardBody>
		</Card>
	);
}
