import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, ColorField, FormGrid, JobProgress, Notice, NumberField, PageHeader, Select, Stack, TextField, Toggle, useToast } from '../core3/ui';
import { useApp } from './shared';

const KEYS = [
	'page_jobs',
	'page_submit',
	'page_employer',
	'page_candidate',
	'page_companies',
	'per_page',
	'currency',
	'accent_color',
	'job_duration',
	'expiry_notice_days',
	'schema_enabled',
	'submission_moderation',
	'edit_moderation',
	'allow_registration',
	'guest_apply',
	'resume_required',
	'max_upload_mb',
	'alerts_enabled',
	'email_admin_new_job',
	'email_admin_payment',
	'email_employer_application',
	'email_employer_job_status',
	'email_candidate_received',
	'email_candidate_stage',
	'ai_writer',
	'writer_tone',
	'ai_inclusive',
	'ai_parse',
	'ai_match',
	'ai_match_on_apply',
	'ai_recommend',
	'match_for_employers',
	'hide_names_in_pipeline',
	'delete_on_uninstall',
];

/**
 * Plugin settings.
 *
 * @return {Element} Settings.
 */
export default function SettingsTab() {
	const { settings, save, meta } = useApp();
	const toast = useToast();
	const [ values, setValues ] = useState( () => KEYS.reduce( ( o, k ) => ( { ...o, [ k ]: settings[ k ] } ), {} ) );
	const [ saving, setSaving ] = useState( false );
	const [ index, setIndex ] = useState( null );
	const set = ( patch ) => setValues( { ...values, ...patch } );
	const flag = ( key, label, help ) => <Toggle label={ label } help={ help } checked={ !! values[ key ] } onChange={ ( v ) => set( { [ key ]: v } ) } />;
	const pages = [ { value: '0', label: __( '— Not set —', 'dev3-ai-job-board' ) }, ...meta.pages.map( ( p ) => ( { value: String( p.value ), label: p.label } ) ) ];
	const pageSelect = ( key, label ) => <Select label={ label } value={ String( values[ key ] || 0 ) } onChange={ ( v ) => set( { [ key ]: Number( v ) } ) } options={ pages } />;

	const submit = () => {
		setSaving( true );
		save( values )
			.then( () => toast.success( __( 'Settings saved.', 'dev3-ai-job-board' ) ) )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSaving( false ) );
	};
	const startIndex = () =>
		request( 'admin/index', { method: 'POST' } )
			.then( ( r ) => setIndex( r.job ) )
			.catch( ( e ) => toast.error( e ) );

	const footer = (
		<Button variant="primary" onClick={ submit } loading={ saving }>
			{ __( 'Save settings', 'dev3-ai-job-board' ) }
		</Button>
	);

	return (
		<>
			<PageHeader title={ __( 'Settings', 'dev3-ai-job-board' ) } description={ __( 'Pages, submissions, applications, emails and DEV3 AI features.', 'dev3-ai-job-board' ) } actions={ footer } />
			<Stack gap={ 16 }>
				<Card title={ __( 'Pages', 'dev3-ai-job-board' ) } description={ __( 'Created on activation. Each page holds the matching block or shortcode.', 'dev3-ai-job-board' ) }>
					<FormGrid>
						{ pageSelect( 'page_jobs', __( 'Job board', 'dev3-ai-job-board' ) ) }
						{ pageSelect( 'page_submit', __( 'Post a job', 'dev3-ai-job-board' ) ) }
						{ pageSelect( 'page_employer', __( 'Employer dashboard', 'dev3-ai-job-board' ) ) }
						{ pageSelect( 'page_candidate', __( 'Candidate dashboard', 'dev3-ai-job-board' ) ) }
						{ pageSelect( 'page_companies', __( 'Companies', 'dev3-ai-job-board' ) ) }
					</FormGrid>
				</Card>
				<Card title={ __( 'Job board', 'dev3-ai-job-board' ) }>
					<Stack gap={ 14 }>
						<FormGrid>
							<NumberField label={ __( 'Jobs per page', 'dev3-ai-job-board' ) } value={ values.per_page } onChange={ ( v ) => set( { per_page: v } ) } min={ 1 } max={ 50 } />
							<TextField label={ __( 'Default currency', 'dev3-ai-job-board' ) } value={ values.currency } onChange={ ( v ) => set( { currency: v.toUpperCase().slice( 0, 3 ) } ) } maxLength={ 3 } />
							<NumberField label={ __( 'Default job duration (days)', 'dev3-ai-job-board' ) } value={ values.job_duration } onChange={ ( v ) => set( { job_duration: v } ) } min={ 1 } max={ 365 } help={ __( 'Used when a job has no package.', 'dev3-ai-job-board' ) } />
							<NumberField label={ __( 'Warn employers before expiry (days)', 'dev3-ai-job-board' ) } value={ values.expiry_notice_days } onChange={ ( v ) => set( { expiry_notice_days: v } ) } min={ 0 } max={ 60 } />
							<ColorField label={ __( 'Accent colour', 'dev3-ai-job-board' ) } value={ values.accent_color } onChange={ ( v ) => set( { accent_color: v } ) } />
						</FormGrid>
						{ flag( 'schema_enabled', __( 'Google for Jobs markup (JobPosting)', 'dev3-ai-job-board' ), __( 'Adds structured data to every live job page.', 'dev3-ai-job-board' ) ) }
						{ flag( 'alerts_enabled', __( 'Job alerts', 'dev3-ai-job-board' ), __( 'Visitors can save a search and get new jobs by email.', 'dev3-ai-job-board' ) ) }
					</Stack>
				</Card>
				<Card title={ __( 'Employers & submissions', 'dev3-ai-job-board' ) }>
					<Stack gap={ 14 }>
						<Select
							label={ __( 'New jobs', 'dev3-ai-job-board' ) }
							value={ values.submission_moderation }
							onChange={ ( v ) => set( { submission_moderation: v } ) }
							options={ [
								{ value: 'pending', label: __( 'Wait for my approval', 'dev3-ai-job-board' ) },
								{ value: 'publish', label: __( 'Go live right away', 'dev3-ai-job-board' ) },
							] }
						/>
						{ flag( 'edit_moderation', __( 'Re-review live jobs after the employer edits them', 'dev3-ai-job-board' ) ) }
						{ flag( 'allow_registration', __( 'Let visitors create employer and candidate accounts', 'dev3-ai-job-board' ) ) }
					</Stack>
				</Card>
				<Card title={ __( 'Applications', 'dev3-ai-job-board' ) }>
					<Stack gap={ 14 }>
						{ flag( 'guest_apply', __( 'Allow applying without an account', 'dev3-ai-job-board' ), __( 'Guests enter name, email and an optional resume. Rate limited.', 'dev3-ai-job-board' ) ) }
						{ flag( 'resume_required', __( 'Require a resume file', 'dev3-ai-job-board' ) ) }
						<NumberField label={ __( 'Maximum file size (MB)', 'dev3-ai-job-board' ) } value={ values.max_upload_mb } onChange={ ( v ) => set( { max_upload_mb: v } ) } min={ 1 } max={ 20 } help={ __( 'Resumes are stored in a private folder and only open for the candidate, the employer and you.', 'dev3-ai-job-board' ) } />
					</Stack>
				</Card>
				<Card title={ __( 'Emails', 'dev3-ai-job-board' ) }>
					<div className="dev3jb-togglegrid">
						{ flag( 'email_admin_new_job', __( 'Admin: job waiting for review', 'dev3-ai-job-board' ) ) }
						{ flag( 'email_admin_payment', __( 'Admin: new payment', 'dev3-ai-job-board' ) ) }
						{ flag( 'email_employer_application', __( 'Employer: new application', 'dev3-ai-job-board' ) ) }
						{ flag( 'email_employer_job_status', __( 'Employer: job live, expiring, expired', 'dev3-ai-job-board' ) ) }
						{ flag( 'email_candidate_received', __( 'Candidate: application received', 'dev3-ai-job-board' ) ) }
						{ flag( 'email_candidate_stage', __( 'Candidate: stage updates (when the employer ticks "Email the candidate")', 'dev3-ai-job-board' ) ) }
					</div>
				</Card>
				<Card tone="ai" title={ __( 'DEV3 AI features', 'dev3-ai-job-board' ) } description={ __( 'Every feature has a non-AI fallback, so the board keeps working with AI off.', 'dev3-ai-job-board' ) }>
					<Stack gap={ 14 }>
						{ ! meta.ai.key_set && <Notice tone="ai">{ __( 'Add a Google Gemini API key in API Config to turn these on.', 'dev3-ai-job-board' ) }</Notice> }
						<div className="dev3jb-togglegrid">
							{ flag( 'ai_writer', __( 'Job description writer', 'dev3-ai-job-board' ) ) }
							{ flag( 'ai_inclusive', __( 'Inclusive-language review', 'dev3-ai-job-board' ) ) }
							{ flag( 'ai_parse', __( 'Resume parsing for candidates', 'dev3-ai-job-board' ) ) }
							{ flag( 'ai_match', __( 'Match explanations', 'dev3-ai-job-board' ) ) }
							{ flag( 'ai_match_on_apply', __( 'Score new applications automatically', 'dev3-ai-job-board' ) ) }
							{ flag( 'ai_recommend', __( 'Semantic job recommendations', 'dev3-ai-job-board' ) ) }
						</div>
						<Select
							label={ __( 'Default writing tone', 'dev3-ai-job-board' ) }
							value={ values.writer_tone }
							onChange={ ( v ) => set( { writer_tone: v } ) }
							options={ [
								{ value: 'professional', label: __( 'Professional', 'dev3-ai-job-board' ) },
								{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-job-board' ) },
								{ value: 'startup', label: __( 'Startup', 'dev3-ai-job-board' ) },
								{ value: 'formal', label: __( 'Formal', 'dev3-ai-job-board' ) },
							] }
						/>
						<div className="dev3jb-indexrow">
							<p>
								{ __( 'Semantic index:', 'dev3-ai-job-board' ) } <strong>{ meta.ai.indexed.job }</strong> { __( 'jobs', 'dev3-ai-job-board' ) }, <strong>{ meta.ai.indexed.resume }</strong> { __( 'profiles', 'dev3-ai-job-board' ) }
							</p>
							<Button size="sm" onClick={ startIndex } disabled={ ! meta.ai.key_set }>
								{ __( 'Update index', 'dev3-ai-job-board' ) }
							</Button>
						</div>
						{ index && <JobProgress jobId={ index } title={ __( 'Indexing jobs and profiles', 'dev3-ai-job-board' ) } /> }
					</Stack>
				</Card>
				<Card title={ __( 'Fairness', 'dev3-ai-job-board' ) } description={ __( 'Match scores support decisions; people make them.', 'dev3-ai-job-board' ) }>
					<Stack gap={ 14 }>
						<Notice tone="info" title={ __( 'What matching never uses', 'dev3-ai-job-board' ) }>
							{ __( 'Name, email, phone, photo, age or date of birth, gender, marital or family status, nationality, ethnicity, religion, disability, health, sexual orientation, union membership, political views and street address are removed before scoring and never sent to AI. Scores never move, hide or reject a candidate.', 'dev3-ai-job-board' ) }
						</Notice>
						{ flag( 'match_for_employers', __( 'Show match scores to employers', 'dev3-ai-job-board' ), __( 'When off, only site managers see scores.', 'dev3-ai-job-board' ) ) }
						{ flag( 'hide_names_in_pipeline', __( 'Blind screening: hide candidate names on employer pipeline cards', 'dev3-ai-job-board' ), __( 'Employers see "Candidate #123" without contact details until they move the application to Interview.', 'dev3-ai-job-board' ) ) }
					</Stack>
				</Card>
				<Card title={ __( 'Data', 'dev3-ai-job-board' ) } footer={ footer }>
					{ flag( 'delete_on_uninstall', __( 'Delete all job board data when the plugin is deleted', 'dev3-ai-job-board' ), __( 'Jobs, companies, profiles, applications, resume files, payments and settings.', 'dev3-ai-job-board' ) ) }
				</Card>
			</Stack>
		</>
	);
}
