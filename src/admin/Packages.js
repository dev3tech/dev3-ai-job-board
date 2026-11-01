import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Checkbox, ErrorNotice, FormGrid, Inline, Notice, NumberField, PageHeader, ResponsiveTable, Select, Stack, Stat, StatGrid, Textarea, TextField, Toggle, useToast } from '../core3/ui';
import { formatDate, money, useApp } from './shared';

const NEW_PACKAGE = { id: '', name: '', description: '', price: 0, job_limit: 1, duration_days: 30, featured: false, highlight: false, active: true };

/**
 * Packages, gateways, payments and manual credits.
 *
 * @return {Element} Screen.
 */
export default function Packages() {
	const { meta, settings, save, reloadMeta } = useApp();
	const toast = useToast();
	const [ list, setList ] = useState( meta.packages );
	const [ saving, setSaving ] = useState( false );
	const [ pay, setPay ] = useState( null );
	const [ cfg, setCfg ] = useState( null );
	const [ keys, setKeys ] = useState( { stripe_secret: '', stripe_webhook: '' } );
	const [ gateway, setGateway ] = useState( settings.payment_gateway );
	const [ manual, setManual ] = useState( settings.manual_instructions || '' );
	const [ grant, setGrant ] = useState( { user_id: '', package_id: meta.packages.length ? meta.packages[ 0 ].id : '' } );
	const [ error, setError ] = useState( null );

	const loadPayments = () => request( 'admin/payments' ).then( setPay ).catch( setError );
	useEffect( () => {
		loadPayments();
		request( 'admin/payment-config' ).then( setCfg ).catch( setError );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const setPkg = ( i, patch ) => setList( list.map( ( p, j ) => ( j === i ? { ...p, ...patch } : p ) ) );
	const savePackages = () => {
		setSaving( true );
		request( 'admin/packages', { method: 'POST', data: { packages: list } } )
			.then( ( saved ) => {
				setList( saved );
				reloadMeta();
				toast.success( __( 'Packages saved.', 'dev3-ai-job-board' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSaving( false ) );
	};
	const saveGateway = () =>
		Promise.all( [ save( { payment_gateway: gateway, manual_instructions: manual } ), request( 'admin/payment-config', { method: 'POST', data: keys } ).then( setCfg ) ] )
			.then( () => {
				setKeys( { stripe_secret: '', stripe_webhook: '' } );
				toast.success( __( 'Payment settings saved.', 'dev3-ai-job-board' ) );
			} )
			.catch( ( e ) => toast.error( e ) );
	const markPaid = ( p ) =>
		request( `admin/payments/${ p.id }/paid`, { method: 'POST' } )
			.then( () => {
				/* translators: %d: payment id */
				toast.success( sprintf( __( 'Payment #%d marked as paid. The package is active.', 'dev3-ai-job-board' ), p.id ) );
				loadPayments();
			} )
			.catch( ( e ) => toast.error( e ) );
	const doGrant = () =>
		request( 'admin/packages/grant', { method: 'POST', data: grant } )
			.then( () => toast.success( __( 'Credits added to the employer account.', 'dev3-ai-job-board' ) ) )
			.catch( ( e ) => toast.error( e ) );

	return (
		<>
			<PageHeader title={ __( 'Packages & payments', 'dev3-ai-job-board' ) } description={ __( 'Sell job posts: one-off posts, featured upgrades or bundles. Free packages work too.', 'dev3-ai-job-board' ) } />
			<ErrorNotice error={ error } />
			<StatGrid>
				<Stat label={ __( 'Revenue (30 days)', 'dev3-ai-job-board' ) } value={ pay ? money( pay.revenue_30, meta.currency ) : '' } loading={ ! pay } icon="chart" tone="success" />
				<Stat label={ __( 'Pending payments', 'dev3-ai-job-board' ) } value={ pay ? pay.items.filter( ( p ) => p.status === 'pending' ).length : '' } loading={ ! pay } icon="clock" />
				<Stat label={ __( 'Active gateway', 'dev3-ai-job-board' ) } value={ pay ? { manual: __( 'Offline', 'dev3-ai-job-board' ), stripe: 'Stripe', woocommerce: 'WooCommerce' }[ pay.gateway ] : '' } loading={ ! pay } icon="key" />
			</StatGrid>
			<Card
				title={ __( 'Packages', 'dev3-ai-job-board' ) }
				actions={
					<Button size="sm" icon="plus" onClick={ () => setList( [ ...list, { ...NEW_PACKAGE } ] ) }>
						{ __( 'Add package', 'dev3-ai-job-board' ) }
					</Button>
				}
				footer={
					<Button variant="primary" onClick={ savePackages } loading={ saving }>
						{ __( 'Save packages', 'dev3-ai-job-board' ) }
					</Button>
				}
			>
				<div className="dev3jb-pkggrid">
					{ list.map( ( p, i ) => (
						<div key={ p.id || `new${ i }` } className={ `dev3jb-pkgcard${ p.highlight ? ' is-hl' : '' }${ p.active ? '' : ' is-off' }` }>
							<Stack gap={ 10 }>
								<Inline align="between">
									<strong>{ p.name || __( 'New package', 'dev3-ai-job-board' ) }</strong>
									{ ! p.active && <Badge>{ __( 'Hidden', 'dev3-ai-job-board' ) }</Badge> }
								</Inline>
								<TextField label={ __( 'Name', 'dev3-ai-job-board' ) } value={ p.name } onChange={ ( v ) => setPkg( i, { name: v } ) } />
								<TextField label={ __( 'Short description', 'dev3-ai-job-board' ) } value={ p.description } onChange={ ( v ) => setPkg( i, { description: v } ) } maxLength={ 200 } />
								<FormGrid>
									<NumberField label={ sprintf( /* translators: %s: currency */ __( 'Price (%s)', 'dev3-ai-job-board' ), meta.currency ) } value={ p.price } onChange={ ( v ) => setPkg( i, { price: v === '' ? 0 : v } ) } min={ 0 } step={ 1 } help={ __( '0 = free', 'dev3-ai-job-board' ) } />
									<NumberField label={ __( 'Job posts', 'dev3-ai-job-board' ) } value={ p.job_limit } onChange={ ( v ) => setPkg( i, { job_limit: v || 1 } ) } min={ 1 } />
									<NumberField label={ __( 'Days live', 'dev3-ai-job-board' ) } value={ p.duration_days } onChange={ ( v ) => setPkg( i, { duration_days: v || 30 } ) } min={ 1 } max={ 365 } />
								</FormGrid>
								<Checkbox label={ __( 'Featured (pinned to the top)', 'dev3-ai-job-board' ) } checked={ p.featured } onChange={ ( v ) => setPkg( i, { featured: v } ) } />
								<Checkbox label={ __( 'Highlight as "Most popular"', 'dev3-ai-job-board' ) } checked={ p.highlight } onChange={ ( v ) => setPkg( i, { highlight: v } ) } />
								<Toggle label={ __( 'Available to employers', 'dev3-ai-job-board' ) } checked={ p.active } onChange={ ( v ) => setPkg( i, { active: v } ) } />
								<Button size="sm" variant="ghost" icon="trash" onClick={ () => setList( list.filter( ( _, j ) => j !== i ) ) }>
									{ __( 'Remove', 'dev3-ai-job-board' ) }
								</Button>
							</Stack>
						</div>
					) ) }
				</div>
			</Card>
			<Card
				title={ __( 'Payment gateway', 'dev3-ai-job-board' ) }
				footer={
					<Button variant="primary" onClick={ saveGateway }>
						{ __( 'Save payment settings', 'dev3-ai-job-board' ) }
					</Button>
				}
			>
				<Stack gap={ 14 }>
					<Select
						label={ __( 'Take payments with', 'dev3-ai-job-board' ) }
						value={ gateway }
						onChange={ setGateway }
						options={ [
							{ value: 'manual', label: __( 'Offline / bank transfer (you mark payments paid)', 'dev3-ai-job-board' ) },
							{ value: 'stripe', label: __( 'Stripe Checkout (cards, Apple Pay, Google Pay)', 'dev3-ai-job-board' ) },
							{ value: 'woocommerce', label: __( 'WooCommerce checkout', 'dev3-ai-job-board' ) },
						] }
					/>
					{ gateway === 'manual' && <Textarea label={ __( 'Payment instructions emailed to employers', 'dev3-ai-job-board' ) } value={ manual } onChange={ setManual } rows={ 3 } /> }
					{ gateway === 'stripe' && cfg && (
						<>
							{ cfg.stripe_secret_set && <Notice tone={ cfg.stripe_test_mode ? 'warning' : 'success' }>{ cfg.stripe_test_mode ? __( 'Stripe is in test mode.', 'dev3-ai-job-board' ) : __( 'Stripe is live.', 'dev3-ai-job-board' ) }</Notice> }
							<FormGrid>
								<TextField type="password" label={ __( 'Stripe secret key', 'dev3-ai-job-board' ) } value={ keys.stripe_secret } onChange={ ( v ) => setKeys( { ...keys, stripe_secret: v } ) } placeholder={ cfg.stripe_secret || 'sk_live_…' } autoComplete="off" />
								<TextField type="password" label={ __( 'Webhook signing secret', 'dev3-ai-job-board' ) } value={ keys.stripe_webhook } onChange={ ( v ) => setKeys( { ...keys, stripe_webhook: v } ) } placeholder={ cfg.stripe_webhook || 'whsec_…' } autoComplete="off" />
							</FormGrid>
							<p className="dev3jb-help">
								{ __( 'Webhook URL (event: checkout.session.completed):', 'dev3-ai-job-board' ) } <code>{ cfg.webhook_url }</code>
							</p>
						</>
					) }
					{ gateway === 'woocommerce' && cfg && ! cfg.woocommerce && <Notice tone="warning">{ __( 'WooCommerce is not active. Employers will get offline payment instructions until it is.', 'dev3-ai-job-board' ) }</Notice> }
					{ gateway === 'woocommerce' && cfg && cfg.woocommerce && <Notice tone="info">{ __( 'Each package becomes a hidden virtual product. A paid order (processing or completed) activates the package. Works with the block checkout and HPOS.', 'dev3-ai-job-board' ) }</Notice> }
				</Stack>
			</Card>
			<Card title={ __( 'Payments', 'dev3-ai-job-board' ) } flush>
				<ResponsiveTable
					loading={ ! pay }
					rows={ pay ? pay.items : [] }
					pageSize={ 20 }
					columns={ [
						{ key: 'id', label: '#', width: 60, render: ( p ) => `#${ p.id }` },
						{ key: 'user_name', label: __( 'Employer', 'dev3-ai-job-board' ), primary: true, render: ( p ) => <><strong>{ p.user_name }</strong><br /><span className="dev3jb-muted">{ p.package_name }{ p.job_title ? ` · ${ p.job_title }` : '' }</span></> },
						{ key: 'amount', label: __( 'Amount', 'dev3-ai-job-board' ), align: 'end', render: ( p ) => money( p.amount, p.currency ) },
						{ key: 'gateway', label: __( 'Gateway', 'dev3-ai-job-board' ), hideOnMobile: true },
						{ key: 'status', label: __( 'Status', 'dev3-ai-job-board' ), render: ( p ) => <Badge tone={ p.status === 'paid' ? 'success' : p.status === 'pending' ? 'warning' : 'neutral' }>{ p.status }</Badge> },
						{ key: 'created_at', label: __( 'Date', 'dev3-ai-job-board' ), hideOnMobile: true, render: ( p ) => formatDate( p.created_at ) },
					] }
					rowActions={ ( p ) =>
						p.status === 'pending' ? (
							<Button size="sm" onClick={ () => markPaid( p ) }>
								{ __( 'Mark paid', 'dev3-ai-job-board' ) }
							</Button>
						) : null
					}
					empty={ { icon: 'file', title: __( 'No payments yet', 'dev3-ai-job-board' ) } }
				/>
			</Card>
			<Card title={ __( 'Give an employer free job posts', 'dev3-ai-job-board' ) } description={ __( 'Useful for partners, refunds or trials.', 'dev3-ai-job-board' ) }>
				<Inline gap={ 12 }>
					<Select label={ __( 'Employer', 'dev3-ai-job-board' ) } value={ grant.user_id } onChange={ ( v ) => setGrant( { ...grant, user_id: v } ) } options={ [ { value: '', label: __( 'Choose…', 'dev3-ai-job-board' ) }, ...meta.employers.map( ( u ) => ( { value: String( u.value ), label: u.label } ) ) ] } />
					<Select label={ __( 'Package', 'dev3-ai-job-board' ) } value={ grant.package_id } onChange={ ( v ) => setGrant( { ...grant, package_id: v } ) } options={ meta.packages.map( ( p ) => ( { value: p.id, label: p.name } ) ) } />
					<Button onClick={ doGrant } disabled={ ! grant.user_id }>
						{ __( 'Add credits', 'dev3-ai-job-board' ) }
					</Button>
				</Inline>
			</Card>
		</>
	);
}
