import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import { AppContext } from './shared';
import Dashboard from './Dashboard';
import Jobs from './Jobs';
import JobEditor from './JobEditor';
import Applications from './Applications';
import Candidates from './Candidates';
import Companies from './Companies';
import Taxonomies from './Taxonomies';
import Packages from './Packages';
import Alerts from './Alerts';
import SettingsTab from './SettingsTab';
import './admin.css';

const NAV = [
	{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-job-board' ), icon: 'dashboard' },
	{ id: 'jobs', label: __( 'Jobs', 'dev3-ai-job-board' ), icon: 'list' },
	{ id: 'applications', label: __( 'Applications', 'dev3-ai-job-board' ), icon: 'columns' },
	{ id: 'candidates', label: __( 'Candidates', 'dev3-ai-job-board' ), icon: 'users' },
	{ id: 'companies', label: __( 'Companies', 'dev3-ai-job-board' ), icon: 'box' },
	{ id: 'taxonomies', label: __( 'Categories & types', 'dev3-ai-job-board' ), icon: 'copy' },
	{ id: 'packages', label: __( 'Packages & payments', 'dev3-ai-job-board' ), icon: 'file' },
	{ id: 'alerts', label: __( 'Job alerts', 'dev3-ai-job-board' ), icon: 'inbox' },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-job-board' ), icon: 'settings' },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-job-board' ), icon: 'key' },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-job-board' ), icon: 'chart' },
];

export default function App() {
	const [ route, navigate ] = useHashRoute(
		NAV.map( ( n ) => n.id ),
		'dashboard'
	);
	const [ settings, setSettings ] = useState( null );
	const [ meta, setMeta ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const [ badges, setBadges ] = useState( {} );

	const reloadMeta = () => request( 'admin/meta' ).then( setMeta );
	const load = () => {
		setError( null );
		Promise.all( [ request( 'settings' ), request( 'admin/meta' ) ] )
			.then( ( [ s, m ] ) => {
				setSettings( s );
				setMeta( m );
			} )
			.catch( setError );
	};
	useEffect( load, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			reloadMeta();
			return next;
		} );

	const onUsage = () => setMeter( ( n ) => n + 1 );
	const ctx = useMemo( () => ( { meta, settings, reloadMeta, save, onUsage, navigate, setBadges } ), [ meta, settings ] ); // eslint-disable-line react-hooks/exhaustive-deps
	const nav = NAV.map( ( n ) => ( badges[ n.id ] ? { ...n, badge: badges[ n.id ] } : n ) );
	const p0 = route.params[ 0 ] || '';

	const screens = {
		dashboard: () => <Dashboard />,
		jobs: () => ( /^(\d+|new)$/.test( p0 ) ? <JobEditor key={ p0 } id={ p0 } /> : <Jobs initialStatus={ p0 } /> ),
		applications: () => <Applications initialJob={ /^\d+$/.test( p0 ) ? Number( p0 ) : 0 } initialApp={ Number( route.params[ 1 ] ) || 0 } />,
		candidates: () => <Candidates />,
		companies: () => <Companies openId={ /^\d+$/.test( p0 ) ? Number( p0 ) : 0 } />,
		taxonomies: () => <Taxonomies tab={ p0 || 'categories' } />,
		packages: () => <Packages />,
		alerts: () => <Alerts />,
		settings: () => <SettingsTab />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-job-board' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-job-board' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-job-board' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-job-board' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppContext.Provider value={ ctx }>
			<AppShell
				name={ config.name || 'DEV3 AI Job Board & Resume Matcher for WordPress' }
				version={ config.version }
				icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
				nav={ nav }
				active={ route.id }
				narrowNav="select"
				actions={ <UsageMeter compact refreshKey={ meter } /> }
				footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-job-board' ) }
			>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				{ ( ! settings || ! meta ) && ! error && <SkeletonCard lines={ 5 } /> }
				{ settings && meta && screens[ route.id ]() }
			</AppShell>
		</AppContext.Provider>
	);
}
