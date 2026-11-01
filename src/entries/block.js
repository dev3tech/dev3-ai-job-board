import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Disabled, PanelBody, RangeControl, SelectControl, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

const ICON = (
	<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
		<path d="M3 7h18v13H3zM8 7V4h8v3M3 13h18" />
	</svg>
);

const data = () => window.dev3jbBlock || { categories: [], types: [] };

/**
 * Generic edit: inspector controls + live server-side preview.
 *
 * @param {string}   name     Block name.
 * @param {Function} controls ( attributes, setAttributes ) => controls.
 * @return {Function} Edit component.
 */
const edit = ( name, controls ) =>
	function Edit( { attributes, setAttributes } ) {
		const props = useBlockProps();
		return (
			<div { ...props }>
				{ controls && (
					<InspectorControls>
						<PanelBody title={ __( 'Settings', 'dev3-ai-job-board' ) }>{ controls( attributes, setAttributes ) }</PanelBody>
					</InspectorControls>
				) }
				<Disabled>
					<ServerSideRender block={ name } attributes={ attributes } />
				</Disabled>
			</div>
		);
	};

const workplace = [
	{ value: '', label: __( 'Any workplace', 'dev3-ai-job-board' ) },
	{ value: 'onsite', label: __( 'On-site', 'dev3-ai-job-board' ) },
	{ value: 'hybrid', label: __( 'Hybrid', 'dev3-ai-job-board' ) },
	{ value: 'remote', label: __( 'Remote', 'dev3-ai-job-board' ) },
];

const blocks = {
	'dev3jb/jobs': {
		title: __( 'Job board', 'dev3-ai-job-board' ),
		description: __( 'Search box, filters and job results with paging.', 'dev3-ai-job-board' ),
		attributes: { per_page: { type: 'number', default: 10 }, filters: { type: 'string', default: 'yes' }, category: { type: 'string', default: '' }, type: { type: 'string', default: '' }, workplace: { type: 'string', default: '' } },
		controls: ( a, set ) => (
			<>
				<RangeControl label={ __( 'Jobs per page', 'dev3-ai-job-board' ) } value={ a.per_page } min={ 1 } max={ 50 } onChange={ ( v ) => set( { per_page: v } ) } />
				<ToggleControl label={ __( 'Show filters', 'dev3-ai-job-board' ) } checked={ a.filters !== 'no' } onChange={ ( v ) => set( { filters: v ? 'yes' : 'no' } ) } />
				<SelectControl label={ __( 'Only this category', 'dev3-ai-job-board' ) } value={ a.category } options={ data().categories } onChange={ ( v ) => set( { category: v } ) } />
				<SelectControl label={ __( 'Only this job type', 'dev3-ai-job-board' ) } value={ a.type } options={ data().types } onChange={ ( v ) => set( { type: v } ) } />
				<SelectControl label={ __( 'Only this workplace', 'dev3-ai-job-board' ) } value={ a.workplace } options={ workplace } onChange={ ( v ) => set( { workplace: v } ) } />
			</>
		),
	},
	'dev3jb/job-search': {
		title: __( 'Job search box', 'dev3-ai-job-board' ),
		description: __( 'A compact search box that opens the job board.', 'dev3-ai-job-board' ),
		attributes: {},
	},
	'dev3jb/recent-jobs': {
		title: __( 'Recent jobs', 'dev3-ai-job-board' ),
		description: __( 'The newest or featured jobs.', 'dev3-ai-job-board' ),
		attributes: { limit: { type: 'number', default: 5 }, featured: { type: 'string', default: 'no' }, category: { type: 'string', default: '' } },
		controls: ( a, set ) => (
			<>
				<RangeControl label={ __( 'Number of jobs', 'dev3-ai-job-board' ) } value={ a.limit } min={ 1 } max={ 20 } onChange={ ( v ) => set( { limit: v } ) } />
				<ToggleControl label={ __( 'Featured only', 'dev3-ai-job-board' ) } checked={ a.featured === 'yes' } onChange={ ( v ) => set( { featured: v ? 'yes' : 'no' } ) } />
				<SelectControl label={ __( 'Category', 'dev3-ai-job-board' ) } value={ a.category } options={ data().categories } onChange={ ( v ) => set( { category: v } ) } />
			</>
		),
	},
	'dev3jb/companies': {
		title: __( 'Companies', 'dev3-ai-job-board' ),
		description: __( 'A grid of hiring companies.', 'dev3-ai-job-board' ),
		attributes: { limit: { type: 'number', default: 24 } },
		controls: ( a, set ) => <RangeControl label={ __( 'Number of companies', 'dev3-ai-job-board' ) } value={ a.limit } min={ 1 } max={ 100 } onChange={ ( v ) => set( { limit: v } ) } />,
	},
	'dev3jb/submit-job': {
		title: __( 'Post a job form', 'dev3-ai-job-board' ),
		description: __( 'Front-end job submission with packages and checkout.', 'dev3-ai-job-board' ),
		attributes: {},
	},
	'dev3jb/employer-dashboard': {
		title: __( 'Employer dashboard', 'dev3-ai-job-board' ),
		description: __( 'Jobs, applications pipeline, companies and packages.', 'dev3-ai-job-board' ),
		attributes: {},
	},
	'dev3jb/candidate-dashboard': {
		title: __( 'Candidate dashboard', 'dev3-ai-job-board' ),
		description: __( 'Profile and resume, applications, saved jobs, alerts and recommendations.', 'dev3-ai-job-board' ),
		attributes: {},
	},
};

Object.keys( blocks ).forEach( ( name ) => {
	const b = blocks[ name ];
	registerBlockType( name, {
		apiVersion: 2,
		title: b.title,
		description: b.description,
		category: 'widgets',
		icon: ICON,
		keywords: [ 'jobs', 'career', 'hiring' ],
		supports: { align: [ 'wide', 'full' ], html: false },
		attributes: b.attributes,
		edit: edit( name, b.controls ),
		save: () => null,
	} );
} );
