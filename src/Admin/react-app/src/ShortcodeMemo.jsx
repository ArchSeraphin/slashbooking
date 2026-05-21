import { useEffect, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { listServices } from './api';

export default function ShortcodeMemo() {
	const [ services, setServices ] = useState( null );
	const [ copied, setCopied ]     = useState( '' );

	useEffect( () => {
		listServices()
			.then( ( res ) => setServices( Array.isArray( res ) ? res : ( res?.services ?? [] ) ) )
			.catch( () => setServices( [] ) );
	}, [] );

	const copy = async ( code ) => {
		try {
			await navigator.clipboard.writeText( code );
			setCopied( code );
			setTimeout( () => setCopied( '' ), 1500 );
		} catch ( e ) {
			// Clipboard access denied — silent fail, user can copy manually.
		}
	};

	const active = ( services ?? [] ).filter( ( s ) => s.active !== false );

	const lines = [];
	if ( active.length > 1 ) {
		lines.push( {
			code:  '[slashbooking]',
			label: __( 'Sélecteur de projet (tous services actifs)', 'slashbooking' ),
		} );
	}
	active.forEach( ( s ) => {
		lines.push( {
			code:  `[slashbooking service="${ s.slug }"]`,
			label: s.name + ( s.duration_minutes ? ` · ${ s.duration_minutes } min` : '' ),
		} );
	} );

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Shortcodes — à coller dans tes pages WordPress', 'slashbooking' ) }</h2>
			</CardHeader>
			<CardBody>
				{ services === null ? (
					<Spinner />
				) : lines.length === 0 ? (
					<p>
						{ __(
							'Aucun service actif. Ajoute un service dans l\'onglet Services pour générer un shortcode.',
							'slashbooking'
						) }
					</p>
				) : (
					<table className="sb-shortcode-memo">
						<tbody>
							{ lines.map( ( { code, label } ) => (
								<tr key={ code }>
									<td className="sb-shortcode-memo__code">
										<code>{ code }</code>
									</td>
									<td className="sb-shortcode-memo__label">{ label }</td>
									<td className="sb-shortcode-memo__action">
										<Button
											variant="tertiary"
											onClick={ () => copy( code ) }
										>
											{ copied === code
												? __( '✓ Copié', 'slashbooking' )
												: __( 'Copier', 'slashbooking' ) }
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
