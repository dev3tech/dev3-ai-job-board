import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, Inline, Modal, PageHeader, ResponsiveTable, Select, Tabs, TextField, useToast } from '../core3/ui';
import { termOptions, useApp } from './shared';

const TABS = [
	{ id: 'categories', label: __( 'Categories', 'dev3-ai-job-board' ), hierarchical: true },
	{ id: 'types', label: __( 'Job types', 'dev3-ai-job-board' ), hierarchical: false },
	{ id: 'locations', label: __( 'Locations', 'dev3-ai-job-board' ), hierarchical: true },
	{ id: 'skills', label: __( 'Skills', 'dev3-ai-job-board' ), hierarchical: false },
];

const SCHEMA = [ 'FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER' ].map( ( v ) => ( { value: v, label: v.replace( '_', ' ' ).toLowerCase().replace( /^\w/, ( c ) => c.toUpperCase() ) } ) );

/**
 * Categories, job types, locations and skills.
 *
 * @param {Object} props     Props.
 * @param {string} props.tab Tab.
 * @return {Element} Screen.
 */
export default function Taxonomies( { tab } ) {
	const { reloadMeta, navigate } = useApp();
	const toast = useToast();
	const conf = TABS.find( ( t ) => t.id === tab ) || TABS[ 0 ];
	const [ terms, setTerms ] = useState( null );
	const [ name, setName ] = useState( '' );
	const [ parent, setParent ] = useState( '' );
	const [ schema, setSchema ] = useState( 'FULL_TIME' );
	const [ edit, setEdit ] = useState( null );

	useEffect( () => {
		setTerms( null );
		request( `admin/terms/${ conf.id }` ).then( setTerms ).catch( ( e ) => toast.error( e ) );
	}, [ conf.id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const done = ( list ) => {
		setTerms( list );
		reloadMeta();
	};
	const add = ( e ) => {
		e.preventDefault();
		request( `admin/terms/${ conf.id }`, { method: 'POST', data: { name, parent: Number( parent ) || 0, schema } } )
			.then( ( list ) => {
				done( list );
				setName( '' );
				/* translators: %s: term */
				toast.success( sprintf( __( '"%s" added.', 'dev3-ai-job-board' ), name ) );
			} )
			.catch( ( err ) => toast.error( err ) );
	};
	const update = () =>
		request( `admin/terms/${ conf.id }/${ edit.id }`, { method: 'POST', data: { name: edit.name, parent: Number( edit.parent ) || 0, schema: edit.schema } } )
			.then( ( list ) => {
				done( list );
				setEdit( null );
			} )
			.catch( ( err ) => toast.error( err ) );
	const remove = ( t ) =>
		request( `admin/terms/${ conf.id }/${ t.id }`, { method: 'DELETE' } )
			.then( done )
			.catch( ( err ) => toast.error( err ) );

	const ordered = terms ? termOptions( terms ).map( ( o ) => ( { ...terms.find( ( t ) => String( t.id ) === o.value ), label: o.label } ) ) : [];

	return (
		<>
			<PageHeader title={ __( 'Categories & types', 'dev3-ai-job-board' ) } description={ __( 'The vocabularies employers pick from and candidates filter by.', 'dev3-ai-job-board' ) } />
			<div className="dev3jb-tabscroll">
				<Tabs label={ __( 'Lists', 'dev3-ai-job-board' ) } tabs={ TABS } value={ conf.id } onChange={ ( v ) => navigate( `/taxonomies/${ v }` ) } />
			</div>
			<Card title={ sprintf( /* translators: %s: list */ __( 'Add to %s', 'dev3-ai-job-board' ), conf.label.toLowerCase() ) }>
				<form onSubmit={ add }>
					<Inline gap={ 12 }>
						<TextField label={ __( 'Name', 'dev3-ai-job-board' ) } value={ name } onChange={ setName } required />
						{ conf.hierarchical && <Select label={ __( 'Parent', 'dev3-ai-job-board' ) } value={ parent } onChange={ setParent } options={ termOptions( terms || [], __( 'None (top level)', 'dev3-ai-job-board' ) ) } /> }
						{ conf.id === 'types' && <Select label={ __( 'Google for Jobs type', 'dev3-ai-job-board' ) } value={ schema } onChange={ setSchema } options={ SCHEMA } /> }
						<Button type="submit" variant="primary" icon="plus" disabled={ ! name.trim() }>
							{ __( 'Add', 'dev3-ai-job-board' ) }
						</Button>
					</Inline>
				</form>
			</Card>
			<Card flush>
				<ResponsiveTable
					loading={ ! terms }
					rows={ ordered }
					pageSize={ 30 }
					columns={ [
						{ key: 'label', label: __( 'Name', 'dev3-ai-job-board' ), primary: true },
						...( conf.id === 'types' ? [ { key: 'schema', label: __( 'Schema type', 'dev3-ai-job-board' ), render: ( t ) => t.schema || '—' } ] : [] ),
						{ key: 'slug', label: __( 'Slug', 'dev3-ai-job-board' ), hideOnMobile: true },
						{ key: 'count', label: __( 'Jobs', 'dev3-ai-job-board' ), align: 'end' },
					] }
					rowActions={ ( t ) => (
						<>
							<Button size="sm" variant="ghost" icon="edit" label={ __( 'Edit', 'dev3-ai-job-board' ) } onClick={ () => setEdit( { ...t } ) } />
							<Button size="sm" variant="ghost" icon="trash" label={ __( 'Delete', 'dev3-ai-job-board' ) } onClick={ () => remove( t ) } />
						</>
					) }
					empty={ { icon: 'list', title: __( 'Nothing here yet', 'dev3-ai-job-board' ) } }
				/>
			</Card>
			<Modal
				open={ !! edit }
				onClose={ () => setEdit( null ) }
				size="sm"
				title={ __( 'Edit', 'dev3-ai-job-board' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setEdit( null ) }>
							{ __( 'Cancel', 'dev3-ai-job-board' ) }
						</Button>
						<Button variant="primary" onClick={ update }>
							{ __( 'Save', 'dev3-ai-job-board' ) }
						</Button>
					</>
				}
			>
				{ edit && (
					<>
						<TextField label={ __( 'Name', 'dev3-ai-job-board' ) } value={ edit.name } onChange={ ( v ) => setEdit( { ...edit, name: v } ) } data-autofocus />
						{ conf.hierarchical && <Select label={ __( 'Parent', 'dev3-ai-job-board' ) } value={ String( edit.parent || '' ) } onChange={ ( v ) => setEdit( { ...edit, parent: v } ) } options={ termOptions( ( terms || [] ).filter( ( t ) => t.id !== edit.id ), __( 'None (top level)', 'dev3-ai-job-board' ) ) } /> }
						{ conf.id === 'types' && <Select label={ __( 'Google for Jobs type', 'dev3-ai-job-board' ) } value={ edit.schema || 'OTHER' } onChange={ ( v ) => setEdit( { ...edit, schema: v } ) } options={ SCHEMA } /> }
					</>
				) }
			</Modal>
		</>
	);
}
