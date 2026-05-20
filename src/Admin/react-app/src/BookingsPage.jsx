import { useEffect, useState, useCallback } from '@wordpress/element';
import { SelectControl, Spinner, Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { listBookings, actBooking, setupApi } from './api';
import BookingRow from './BookingRow';

setupApi();

const STATUSES = [
	{ value: '',          label: __( 'Tous statuts', 'trinity-booking' ) },
	{ value: 'pending',   label: __( 'En attente',   'trinity-booking' ) },
	{ value: 'confirmed', label: __( 'Confirmés',    'trinity-booking' ) },
	{ value: 'rejected',  label: __( 'Refusés',      'trinity-booking' ) },
	{ value: 'cancelled', label: __( 'Annulés',      'trinity-booking' ) },
];

const PER_PAGE = 20;

export default function BookingsPage() {
	const [ items, setItems ]   = useState( [] );
	const [ total, setTotal ]   = useState( 0 );
	const [ page, setPage ]     = useState( 1 );
	const [ status, setStatus ] = useState( '' );
	const [ busy, setBusy ]     = useState( false );
	const [ error, setError ]   = useState( null );

	const load = useCallback( async () => {
		setBusy( true );
		setError( null );
		try {
			const res = await listBookings( {
				page,
				per_page: PER_PAGE,
				...( status ? { status } : {} ),
			} );
			setItems( res.items );
			setTotal( res.total );
		} catch ( e ) {
			setError( e.message || String( e ) );
		} finally {
			setBusy( false );
		}
	}, [ page, status ] );

	useEffect( () => { load(); }, [ load ] );

	const onAct = async ( id, action ) => {
		try {
			await actBooking( id, action );
			await load();
		} catch ( e ) {
			setError( e.message || String( e ) );
		}
	};

	const lastPage = Math.max( 1, Math.ceil( total / PER_PAGE ) );

	return (
		<section className="tb-bookings">
			<div className="tb-bookings__toolbar">
				<SelectControl
					label={ __( 'Statut', 'trinity-booking' ) }
					value={ status }
					options={ STATUSES }
					onChange={ ( v ) => { setPage( 1 ); setStatus( v ); } }
				/>
			</div>

			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ busy ? <Spinner /> : (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Date',    'trinity-booking' ) }</th>
							<th>{ __( 'Service', 'trinity-booking' ) }</th>
							<th>{ __( 'Client',  'trinity-booking' ) }</th>
							<th>{ __( 'Statut',  'trinity-booking' ) }</th>
							<th>{ __( 'Actions', 'trinity-booking' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ items.length === 0 && (
							<tr><td colSpan={ 5 }>{ __( 'Aucun RDV.', 'trinity-booking' ) }</td></tr>
						) }
						{ items.map( ( b ) => (
							<BookingRow key={ b.id } booking={ b } onAct={ onAct } />
						) ) }
					</tbody>
				</table>
			) }

			<div className="tb-bookings__pager">
				<Button disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
					{ __( 'Précédent', 'trinity-booking' ) }
				</Button>
				<span>
					{ __( 'Page', 'trinity-booking' ) } { page } / { lastPage }
				</span>
				<Button disabled={ page >= lastPage } onClick={ () => setPage( page + 1 ) }>
					{ __( 'Suivant', 'trinity-booking' ) }
				</Button>
			</div>
		</section>
	);
}
