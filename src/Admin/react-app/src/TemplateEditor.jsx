import { useEffect, useState, useRef } from '@wordpress/element';
import {
	Card, CardBody, CardHeader,
	Button, TextControl, TextareaControl,
	Notice, Spinner, SelectControl, Flex, FlexItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import CodeMirror from '@uiw/react-codemirror';
import { html as htmlLang } from '@codemirror/lang-html';
import {
	fetchMailTemplate, saveMailTemplate, restoreMailTemplate,
	previewMailTemplate, sendTestMailTemplate, listTags,
} from './api';

export default function TemplateEditor( { eventKey, onClose } ) {
	const [ template, setTemplate ] = useState( null );
	const [ subject, setSubject ] = useState( '' );
	const [ htmlBody, setHtmlBody ] = useState( '' );
	const [ textBody, setTextBody ] = useState( '' );
	const [ enabled, setEnabled ] = useState( true );
	const [ tagGroups, setTagGroups ] = useState( [] );
	const [ selectedTag, setSelectedTag ] = useState( '' );
	const [ preview, setPreview ] = useState( { subject: '', html: '' } );
	const [ isDirty, setIsDirty ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState( null );
	const [ error, setError ] = useState( null );

	const codeMirrorRef = useRef( null );

	const load = async () => {
		setLoading( true );
		setError( null );
		try {
			const [ tpl, tags ] = await Promise.all( [
				fetchMailTemplate( eventKey ),
				listTags(),
			] );
			setTemplate( tpl );
			setSubject( tpl.subject );
			setHtmlBody( tpl.html_body );
			setTextBody( tpl.text_body ?? '' );
			setEnabled( !! tpl.enabled );
			setTagGroups( tags.groups );
			setIsDirty( false );
			await refreshPreview( tpl.subject, tpl.html_body );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setLoading( false );
		}
	};

	const refreshPreview = async ( sub, body ) => {
		try {
			const p = await previewMailTemplate( eventKey, {
				subject: sub,
				htmlBody: body,
			} );
			setPreview( p );
		} catch ( e ) {
			// Silent on preview errors — non-blocking.
		}
	};

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ eventKey ] );

	useEffect( () => {
		if ( ! template ) {
			return;
		}
		const id = setTimeout( () => {
			refreshPreview( subject, htmlBody );
		}, 400 );
		return () => clearTimeout( id );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ subject, htmlBody ] );

	const onSubjectChange = ( v ) => {
		setSubject( v );
		setIsDirty( true );
	};
	const onHtmlChange = ( v ) => {
		setHtmlBody( v );
		setIsDirty( true );
	};
	const onTextChange = ( v ) => {
		setTextBody( v );
		setIsDirty( true );
	};

	const insertTag = ( tagName ) => {
		if ( ! tagName ) return;
		const insertion = `{{${ tagName }}}`;
		setHtmlBody( ( current ) => current + insertion );
		setIsDirty( true );
		setSelectedTag( '' );
	};

	const save = async () => {
		setSaving( true );
		setMessage( null );
		setError( null );
		try {
			await saveMailTemplate( eventKey, {
				subject,
				htmlBody,
				textBody: textBody.trim() === '' ? null : textBody,
				enabled,
			} );
			setIsDirty( false );
			setMessage( __( 'Template enregistré.', 'slashbooking' ) );
			await load();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setSaving( false );
		}
	};

	const restore = async () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Restaurer le template par défaut ?', 'slashbooking' ) ) ) {
			return;
		}
		try {
			await restoreMailTemplate( eventKey );
			setMessage( __( 'Template par défaut restauré.', 'slashbooking' ) );
			await load();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		}
	};

	const sendTest = async () => {
		try {
			const r = await sendTestMailTemplate( eventKey, { subject, htmlBody } );
			setMessage(
				r.sent
					? __( 'E-mail de test envoyé à : ', 'slashbooking' ) + r.to
					: __( 'Échec de l\'envoi du test.', 'slashbooking' )
			);
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		}
	};

	const close = () => {
		if ( isDirty ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Modifications non sauvegardées, quitter ?', 'slashbooking' ) ) ) {
				return;
			}
		}
		onClose();
	};

	if ( loading ) {
		return (
			<Card>
				<CardBody>
					<Spinner />
				</CardBody>
			</Card>
		);
	}

	const tagOptions = [ { label: __( '— Insérer un tag —', 'slashbooking' ), value: '' } ];
	tagGroups.forEach( ( g ) => {
		g.tags.forEach( ( t ) => {
			tagOptions.push( {
				label: `${ g.label } · {{${ t.name }}} — ${ t.description }`,
				value: t.name,
			} );
		} );
	} );

	return (
		<Card>
			<CardHeader>
				<Flex>
					<FlexItem>
						<h2 style={ { margin: 0 } }>
							{ __( 'Édition : ', 'slashbooking' ) }
							<code>{ eventKey }</code>
							{ template?.is_custom && (
								<span className="sb-badge tb-badge-custom" style={ { marginLeft: 8 } }>
									{ __( 'Personnalisé', 'slashbooking' ) }
								</span>
							) }
						</h2>
					</FlexItem>
					<FlexItem>
						<Button variant="tertiary" onClick={ close }>
							← { __( 'Retour à la liste', 'slashbooking' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardHeader>
			<CardBody>
				{ message && (
					<Notice status="success" onRemove={ () => setMessage( null ) }>
						{ message }
					</Notice>
				) }
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }

				<div className="sb-template-split">
					<div className="sb-template-edit">
						<TextControl
							label={ __( 'Sujet de l\'e-mail', 'slashbooking' ) }
							value={ subject }
							onChange={ onSubjectChange }
						/>

						<div className="sb-tag-picker">
							<SelectControl
								label={ __( 'Insérer un tag dans le corps HTML', 'slashbooking' ) }
								options={ tagOptions }
								value={ selectedTag }
								onChange={ ( v ) => {
									setSelectedTag( v );
									insertTag( v );
								} }
							/>
						</div>

						<label className="sb-cm-label">
							{ __( 'Corps HTML', 'slashbooking' ) }
						</label>
						<div className="sb-codemirror-wrap" ref={ codeMirrorRef }>
							<CodeMirror
								value={ htmlBody }
								height="380px"
								extensions={ [ htmlLang() ] }
								onChange={ onHtmlChange }
							/>
						</div>

						<TextareaControl
							label={ __( 'Version texte (laisser vide pour génération auto)', 'slashbooking' ) }
							value={ textBody }
							onChange={ onTextChange }
							rows={ 5 }
						/>

						<Flex gap={ 2 } className="sb-template-actions">
							<FlexItem>
								<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ ! isDirty || saving }>
									{ __( 'Enregistrer', 'slashbooking' ) }
								</Button>
							</FlexItem>
							<FlexItem>
								<Button variant="secondary" onClick={ sendTest }>
									{ __( 'Envoyer un test', 'slashbooking' ) }
								</Button>
							</FlexItem>
							<FlexItem>
								<Button variant="tertiary" isDestructive onClick={ restore } disabled={ ! template?.is_custom }>
									{ __( 'Restaurer le défaut', 'slashbooking' ) }
								</Button>
							</FlexItem>
						</Flex>
					</div>

					<div className="sb-template-preview">
						<h4>{ __( 'Aperçu live', 'slashbooking' ) }</h4>
						<div className="sb-preview-subject">
							<strong>{ __( 'Sujet rendu : ', 'slashbooking' ) }</strong>
							{ preview.subject }
						</div>
						<iframe
							className="sb-preview-iframe"
							title={ __( 'Aperçu du template', 'slashbooking' ) }
							srcDoc={ preview.html }
							sandbox="allow-same-origin"
						/>
					</div>
				</div>
			</CardBody>
		</Card>
	);
}
