import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, Drawer, ErrorNotice, FormGrid, Inline, Modal, PageHeader, ResponsiveTable, Select, Stack, Textarea, TextField, useToast } from '../core3/ui';
import { initials, useApp } from './shared';

const BLANK = { name: '', tagline: '', website: '', industry: '', size: '', founded: '', headquarters: '', email: '', linkedin: '', twitter: '', description: '', logo_id: 0, logo: '', author: 0 };

/**
 * Opens the WordPress media library for one image.
 *
 * @param {Function} onPick ( { id, url } ) => void.
 */
export function pickImage( onPick ) {
	if ( ! window.wp || ! window.wp.media ) {
		return;
	}
	const frame = window.wp.media( { title: __( 'Company logo', 'dev3-ai-job-board' ), library: { type: 'image' }, multiple: false } );
	frame.on( 'select', () => {
		const a = frame.state().get( 'selection' ).first().toJSON();
		onPick( { id: a.id, url: ( a.sizes && a.sizes.thumbnail && a.sizes.thumbnail.url ) || a.url } );
	} );
	frame.open();
}

/**
 * Companies table + editor drawer.
 *
 * @param {Object} props        Props.
 * @param {number} props.openId Company to open.
 * @return {Element} Screen.
 */
export default function Companies( { openId = 0 } ) {
	const { meta, reloadMeta } = useApp();
	const toast = useToast();
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ edit, setEdit ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ confirm, setConfirm ] = useState( null );

	const load = () =>
		request( 'admin/companies' )
			.then( ( r ) => {
				setRows( r );
				if ( openId ) {
					const c = r.find( ( x ) => x.id === openId );
					if ( c ) {
						setEdit( { ...BLANK, ...c, description: c.description.replace( /<[^>]+>/g, '' ) } );
					}
				}
			} )
			.catch( setError );
	useEffect( () => {
		load();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const save = () => {
		setSaving( true );
		request( edit.id ? `admin/companies/${ edit.id }` : 'admin/companies', { method: 'POST', data: edit } )
			.then( () => {
				toast.success( __( 'Company saved.', 'dev3-ai-job-board' ) );
				setEdit( null );
				load();
				reloadMeta();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSaving( false ) );
	};

	const remove = () =>
		request( `admin/companies/${ confirm.id }`, { method: 'DELETE' } )
			.then( () => {
				toast.success( __( 'Company moved to the trash.', 'dev3-ai-job-board' ) );
				setConfirm( null );
				load();
				reloadMeta();
			} )
			.catch( ( e ) => toast.error( e ) );

	const set = ( patch ) => setEdit( { ...edit, ...patch } );

	return (
		<>
			<PageHeader
				title={ __( 'Companies', 'dev3-ai-job-board' ) }
				description={ __( 'Company profiles appear on every job and get their own page with all open jobs.', 'dev3-ai-job-board' ) }
				actions={
					<Button variant="primary" icon="plus" onClick={ () => setEdit( { ...BLANK } ) }>
						{ __( 'Add company', 'dev3-ai-job-board' ) }
					</Button>
				}
			/>
			<ErrorNotice error={ error } />
			<Card flush>
				<ResponsiveTable
					loading={ ! rows && ! error }
					rows={ rows || [] }
					pageSize={ 20 }
					onRowClick={ ( c ) => setEdit( { ...BLANK, ...c, description: c.description.replace( /<[^>]+>/g, '' ) } ) }
					columns={ [
						{
							key: 'name',
							label: __( 'Company', 'dev3-ai-job-board' ),
							primary: true,
							sortable: true,
							render: ( c ) => (
								<span className="dev3jb-cell-title">
									{ c.logo ? <img src={ c.logo } alt="" /> : <span className="dev3jb-cell-ph" aria-hidden="true">{ initials( c.name ) }</span> }
									<span>
										<strong>{ c.name }</strong>
										<span className="dev3jb-cell-sub">{ c.tagline }</span>
									</span>
								</span>
							),
						},
						{ key: 'industry', label: __( 'Industry', 'dev3-ai-job-board' ), hideOnMobile: true, render: ( c ) => c.industry || '—' },
						{ key: 'jobs', label: __( 'Open jobs', 'dev3-ai-job-board' ), align: 'end', sortable: true },
					] }
					rowActions={ ( c ) => (
						// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
						<span className="dev3jb-rowactions" onClick={ ( e ) => e.stopPropagation() }>
							{ c.url && <Button size="sm" variant="ghost" icon="external" href={ c.url } target="_blank" label={ __( 'View', 'dev3-ai-job-board' ) } /> }
							<Button size="sm" variant="ghost" icon="trash" onClick={ () => setConfirm( c ) } label={ __( 'Delete', 'dev3-ai-job-board' ) } />
						</span>
					) }
					empty={ { icon: 'box', title: __( 'No companies yet', 'dev3-ai-job-board' ), description: __( 'Employers add their company when they post a job, or you can add one here.', 'dev3-ai-job-board' ) } }
				/>
			</Card>
			<Drawer
				open={ !! edit }
				onClose={ () => setEdit( null ) }
				size="lg"
				title={ edit && edit.id ? edit.name : __( 'New company', 'dev3-ai-job-board' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setEdit( null ) }>
							{ __( 'Cancel', 'dev3-ai-job-board' ) }
						</Button>
						<Button variant="primary" onClick={ save } loading={ saving } disabled={ ! edit || ! edit.name }>
							{ __( 'Save company', 'dev3-ai-job-board' ) }
						</Button>
					</>
				}
			>
				{ edit && (
					<Stack gap={ 14 }>
						<div className="dev3jb-logo-edit">
							{ edit.logo ? <img src={ edit.logo } alt="" /> : <span className="dev3jb-cell-ph dev3jb-cell-ph--lg" aria-hidden="true">{ initials( edit.name ) }</span> }
							<Inline gap={ 6 }>
								<Button size="sm" onClick={ () => pickImage( ( img ) => set( { logo_id: img.id, logo: img.url } ) ) }>
									{ edit.logo ? __( 'Change logo', 'dev3-ai-job-board' ) : __( 'Choose logo', 'dev3-ai-job-board' ) }
								</Button>
								{ edit.logo && (
									<Button size="sm" variant="ghost" onClick={ () => set( { logo_id: 0, logo: '' } ) }>
										{ __( 'Remove', 'dev3-ai-job-board' ) }
									</Button>
								) }
							</Inline>
						</div>
						<TextField label={ __( 'Company name', 'dev3-ai-job-board' ) } value={ edit.name } onChange={ ( v ) => set( { name: v } ) } required />
						<TextField label={ __( 'Tagline', 'dev3-ai-job-board' ) } value={ edit.tagline } onChange={ ( v ) => set( { tagline: v } ) } maxLength={ 160 } />
						<FormGrid>
							<TextField type="url" label={ __( 'Website', 'dev3-ai-job-board' ) } value={ edit.website } onChange={ ( v ) => set( { website: v } ) } />
							<TextField type="email" label={ __( 'Contact email', 'dev3-ai-job-board' ) } value={ edit.email } onChange={ ( v ) => set( { email: v } ) } />
							<TextField label={ __( 'Industry', 'dev3-ai-job-board' ) } value={ edit.industry } onChange={ ( v ) => set( { industry: v } ) } />
							<Select label={ __( 'Size', 'dev3-ai-job-board' ) } value={ edit.size } onChange={ ( v ) => set( { size: v } ) } options={ meta.sizes.map( ( s ) => ( { value: s, label: s || __( 'Not specified', 'dev3-ai-job-board' ) } ) ) } />
							<TextField label={ __( 'Founded', 'dev3-ai-job-board' ) } value={ edit.founded } onChange={ ( v ) => set( { founded: v.replace( /\D/g, '' ).slice( 0, 4 ) } ) } inputMode="numeric" />
							<TextField label={ __( 'Headquarters', 'dev3-ai-job-board' ) } value={ edit.headquarters } onChange={ ( v ) => set( { headquarters: v } ) } />
							<TextField type="url" label="LinkedIn" value={ edit.linkedin } onChange={ ( v ) => set( { linkedin: v } ) } />
							<TextField type="url" label="X / Twitter" value={ edit.twitter } onChange={ ( v ) => set( { twitter: v } ) } />
						</FormGrid>
						<Select label={ __( 'Owner (employer account)', 'dev3-ai-job-board' ) } value={ String( edit.author || '' ) } onChange={ ( v ) => set( { author: Number( v ) || 0 } ) } options={ [ { value: '', label: __( 'Me', 'dev3-ai-job-board' ) }, ...meta.employers.map( ( u ) => ( { value: String( u.value ), label: u.label } ) ) ] } />
						<Textarea label={ __( 'About the company', 'dev3-ai-job-board' ) } value={ edit.description } onChange={ ( v ) => set( { description: v } ) } rows={ 6 } />
					</Stack>
				) }
			</Drawer>
			<Modal
				open={ !! confirm }
				onClose={ () => setConfirm( null ) }
				size="sm"
				/* translators: %s: company */
				title={ confirm ? sprintf( __( 'Delete %s?', 'dev3-ai-job-board' ), confirm.name ) : '' }
				description={ __( 'The company moves to the trash. Its jobs stay, without a company.', 'dev3-ai-job-board' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( null ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-job-board' ) }
						</Button>
						<Button variant="danger" onClick={ remove }>
							{ __( 'Delete', 'dev3-ai-job-board' ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
