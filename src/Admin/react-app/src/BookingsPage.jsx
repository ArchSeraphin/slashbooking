import { useEffect, useState, useCallback, useMemo } from '@wordpress/element';
import { SelectControl, Spinner, Button, Notice, Card, CardBody } from '@wordpress/components';
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

	// KPI counts derived from a separate unfiltered fetch (first page is enough for the small volumes Trinity handles).
	const [ kpi, setKpi ] = useState( { total: 0, pending: 0, confirmed: 0, upcoming: 0 } );

	const computeKpis = ( rows ) => {
		const now = new Date();
		let pending = 0, confirmed = 0, upcoming = 0;
		rows.forEach( ( b ) => {
			if ( b.status === 'pending' ) pending++;
			if ( b.status === 'confirmed' ) confirmed++;
			if ( b.status === 'confirmed' && new Date( b.starts_at_utc ) >= now ) upcoming++;
		} );
		return { pending, confirmed, upcoming };
	};

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

			// Refresh KPI from an unfiltered probe (lightweight: first 100 rows).
			const probe = await listBookings( { page: 1, per_page: 100 } );
			const counts = computeKpis( probe.items );
			setKpi( { total: probe.total, ...counts } );
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

	const kpiCards = useMemo( () => ( [
		{
			label: __( 'Total RDV', 'trinity-booking' ),
			value: kpi.total,
			variant: 'primary',
			hint:  __( 'Toutes périodes', 'trinity-booking' ),
		},
		{
			label: __( 'À valider', 'trinity-booking' ),
			value: kpi.pending,
			variant: 'warning',
			hint:  __( 'En attente de confirmation', 'trinity-booking' ),
		},
		{
			label: __( 'Confirmés', 'trinity-booking' ),
			value: kpi.confirmed,
			variant: 'accent',
			hint:  __( 'Validés par l\'admin', 'trinity-booking' ),
		},
		{
			label: __( 'À venir', 'trinity-booking' ),
			value: kpi.upcoming,
			variant: 'primary',
			hint:  __( 'Confirmés futurs', 'trinity-booking' ),
		},
	] ), [ kpi ] );

	return (
		<section className="tb-bookings">
			<div className="tb-kpi-grid">
				{ kpiCards.map( ( c ) => (
					<div key={ c.label } className={ `tb-kpi tb-kpi--${ c.variant }` }>
						<p className="tb-kpi__label">{ c.label }</p>
						<p className="tb-kpi__value">{ c.value }</p>
						<p className="tb-kpi__hint">{ c.hint }</p>
					</div>
				) ) }
			</div>

			<Card>
				<CardBody>
					<div className="tb-bookings__toolbar">
						<SelectControl
							label={ __( 'Filtrer par statut', 'trinity-booking' ) }
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

					{ busy ? (
						<div style={ { padding: 32, textAlign: 'center' } }><Spinner /></div>
					) : items.length === 0 ? (
						<div className="tb-empty">
							<svg className="tb-empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
								<rect x="3" y="4" width="18" height="18" rx="2"/>
								<path d="M16 2v4M8 2v4M3 10h18"/>
							</svg>
							<p className="tb-empty__title">{ __( 'Aucune réservation', 'trinity-booking' ) }</p>
							<p className="tb-empty__hint">
								{ __( 'Les RDV apparaîtront ici dès la première prise via le formulaire public.', 'trinity-booking' ) }
							</p>
						</div>
					) : (
						<table className="tb-table">
							<thead>
								<tr>
									<th>{ __( 'Date',    'trinity-booking' ) }</th>
									<th>{ __( 'Service', 'trinity-booking' ) }</th>
									<th>{ __( 'Client',  'trinity-booking' ) }</th>
									<th>{ __( 'Statut',  'trinity-booking' ) }</th>
									<th style={ { textAlign: 'right' } }>{ __( 'Actions', 'trinity-booking' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( b ) => (
									<BookingRow key={ b.id } booking={ b } onAct={ onAct } />
								) ) }
							</tbody>
						</table>
					) }

					{ items.length > 0 && (
						<div className="tb-bookings__pager">
							<Button disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
								{ __( '← Précédent', 'trinity-booking' ) }
							</Button>
							<span>
								{ __( 'Page', 'trinity-booking' ) } { page } / { lastPage }
							</span>
							<Button disabled={ page >= lastPage } onClick={ () => setPage( page + 1 ) }>
								{ __( 'Suivant →', 'trinity-booking' ) }
							</Button>
						</div>
					) }
				</CardBody>
			</Card>
		</section>
	);
}
