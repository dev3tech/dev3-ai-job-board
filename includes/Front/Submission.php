<?php
/**
 * Front-end job submission: details (with the writing assistant) → preview → package → checkout.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Front;

use Dev3\AiJobBoard\Ai\Describer;
use Dev3\AiJobBoard\Billing\Checkout;
use Dev3\AiJobBoard\Data\Companies;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Packages;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Data\UserPackages;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static form + handlers.
 */
class Submission {

	const LOGO_MAX = 2097152;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_dev3jb_submit_job', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_dev3jb_choose_package', array( __CLASS__, 'handle_package' ) );
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'redirect_hosts' ) );
	}

	/**
	 * Lets wp_safe_redirect() send employers to Stripe Checkout.
	 *
	 * @param array $hosts Hosts.
	 * @return array
	 */
	public static function redirect_hosts( $hosts ) {
		$hosts[] = 'checkout.stripe.com';
		return $hosts;
	}

	/**
	 * Submit page URL for a step.
	 *
	 * @param string $step   form|preview|package.
	 * @param int    $job_id Job.
	 * @param array  $extra  Extra args.
	 * @return string
	 */
	public static function url( $step, $job_id = 0, array $extra = array() ) {
		$args = array_merge( array( 'step' => $step ), $job_id ? array( 'job' => (int) $job_id ) : array(), $extra );
		return add_query_arg( $args, Settings::page_url( 'submit' ) );
	}

	/**
	 * Renders the current step.
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! is_user_logged_in() ) {
			return Account::gate( 'employer', __( 'Post a job', 'dev3-ai-job-board' ), __( 'Log in or create a free employer account to post jobs and manage applications.', 'dev3-ai-job-board' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$step   = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'form';
		$job_id = isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0;
		$relist = ! empty( $_GET['relist'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$user = get_current_user_id();
		if ( $job_id && ! Listings::owner_can( $job_id, $user ) ) {
			return '<div class="dev3jb dev3jb-notice dev3jb-notice--error">' . esc_html__( 'You cannot edit this job.', 'dev3-ai-job-board' ) . '</div>';
		}
		$steps = array(
			'form'    => __( 'Job details', 'dev3-ai-job-board' ),
			'preview' => __( 'Preview', 'dev3-ai-job-board' ),
			'package' => __( 'Package', 'dev3-ai-job-board' ),
		);
		if ( ! isset( $steps[ $step ] ) || ( 'form' !== $step && ! $job_id ) ) {
			$step = 'form';
		}
		$html  = '<div class="dev3jb dev3jb-submit">';
		$html .= '<ol class="dev3jb-steps" aria-label="' . esc_attr__( 'Steps', 'dev3-ai-job-board' ) . '">';
		$i     = 0;
		foreach ( $steps as $key => $label ) {
			++$i;
			$state = $key === $step ? ' aria-current="step" class="is-current"' : ( array_search( $key, array_keys( $steps ), true ) < array_search( $step, array_keys( $steps ), true ) ? ' class="is-done"' : '' );
			$html .= '<li' . $state . '><span class="dev3jb-steps__n">' . (int) $i . '</span><span>' . esc_html( $label ) . '</span></li>';
		}
		$html .= '</ol>' . Forms::notices( array() );
		if ( 'preview' === $step ) {
			$html .= self::preview( $job_id );
		} elseif ( 'package' === $step ) {
			$html .= self::package_step( $job_id, $relist );
		} else {
			$html .= self::form( $job_id );
		}
		return $html . '</div>';
	}

	/**
	 * Details form.
	 *
	 * @param int $job_id Job (0 = new).
	 * @return string
	 */
	public static function form( $job_id ) {
		$user      = get_current_user_id();
		$job       = $job_id ? Listings::get( $job_id ) : null;
		$companies = Companies::for_user( $user );
		$v         = static function ( $key, $fallback = '' ) use ( $job ) {
			return $job && isset( $job[ $key ] ) && null !== $job[ $key ] ? $job[ $key ] : $fallback;
		};
		$first     = static function ( $key ) use ( $job ) {
			return $job && ! empty( $job[ $key ] ) ? $job[ $key ][0]['id'] : 0;
		};
		$ai        = Describer::ai_available( 'frontend' );
		ob_start();
		?>
		<form class="dev3jb-form dev3jb-jobform" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dev3jb-jobform="<?php echo esc_attr( (string) wp_json_encode( array( 'ai' => $ai ) ) ); ?>" novalidate>
			<?php echo Forms::guard_fields( 'submit_job' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<input type="hidden" name="job_id" value="<?php echo (int) $job_id; ?>">
			<input type="hidden" name="return" value="<?php echo esc_url( self::url( 'form', $job_id ) ); ?>">
			<input type="hidden" name="job[excerpt]" value="<?php echo esc_attr( (string) $v( 'excerpt' ) ); ?>" data-dev3jb-excerpt>

			<fieldset class="dev3jb-section">
				<legend class="dev3jb-h"><?php esc_html_e( 'Company', 'dev3-ai-job-board' ); ?></legend>
				<?php if ( $companies ) : ?>
					<div class="dev3jb-field">
						<label for="dev3jb-company"><?php esc_html_e( 'Hiring company', 'dev3-ai-job-board' ); ?></label>
						<select id="dev3jb-company" name="company_id" data-dev3jb-company>
							<?php foreach ( $companies as $c ) : ?>
								<option value="<?php echo (int) $c['id']; ?>" <?php selected( (int) $v( 'company_id', $companies[0]['id'] ), $c['id'] ); ?>><?php echo esc_html( $c['name'] ); ?></option>
							<?php endforeach; ?>
							<option value="new"><?php esc_html_e( '+ Add a new company', 'dev3-ai-job-board' ); ?></option>
						</select>
					</div>
				<?php else : ?>
					<input type="hidden" name="company_id" value="new">
				<?php endif; ?>
				<div class="dev3jb-newcompany" data-dev3jb-newcompany <?php echo $companies ? 'hidden' : ''; ?>>
					<div class="dev3jb-grid2">
						<div class="dev3jb-field"><label for="dev3jb-c-name"><?php esc_html_e( 'Company name', 'dev3-ai-job-board' ); ?> <span aria-hidden="true">*</span></label><input type="text" id="dev3jb-c-name" name="company[name]" maxlength="120" autocomplete="organization"></div>
						<div class="dev3jb-field"><label for="dev3jb-c-web"><?php esc_html_e( 'Website', 'dev3-ai-job-board' ); ?></label><input type="url" id="dev3jb-c-web" name="company[website]" placeholder="https://" inputmode="url"></div>
					</div>
					<div class="dev3jb-field"><label for="dev3jb-c-tag"><?php esc_html_e( 'Tagline', 'dev3-ai-job-board' ); ?></label><input type="text" id="dev3jb-c-tag" name="company[tagline]" maxlength="160" placeholder="<?php esc_attr_e( 'What does the company do, in one line?', 'dev3-ai-job-board' ); ?>"></div>
					<div class="dev3jb-field"><label for="dev3jb-c-desc"><?php esc_html_e( 'About the company', 'dev3-ai-job-board' ); ?></label><textarea id="dev3jb-c-desc" name="company[description]" rows="3"></textarea></div>
					<div class="dev3jb-field"><label for="dev3jb-c-logo"><?php esc_html_e( 'Logo (optional)', 'dev3-ai-job-board' ); ?></label><input class="dev3jb-file" type="file" id="dev3jb-c-logo" name="company_logo" accept="image/png,image/jpeg,image/webp"><span class="dev3jb-help"><?php esc_html_e( 'Square PNG, JPG or WebP, up to 2 MB.', 'dev3-ai-job-board' ); ?></span></div>
				</div>
			</fieldset>

			<fieldset class="dev3jb-section">
				<legend class="dev3jb-h"><?php esc_html_e( 'The job', 'dev3-ai-job-board' ); ?></legend>
				<div class="dev3jb-field"><label for="dev3jb-title"><?php esc_html_e( 'Job title', 'dev3-ai-job-board' ); ?> <span aria-hidden="true">*</span></label><input type="text" id="dev3jb-title" name="job[title]" required maxlength="160" value="<?php echo esc_attr( (string) $v( 'title' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Senior Product Designer', 'dev3-ai-job-board' ); ?>"></div>
				<div class="dev3jb-grid3">
					<div class="dev3jb-field"><label for="dev3jb-cat"><?php esc_html_e( 'Category', 'dev3-ai-job-board' ); ?></label><?php echo self::term_select_ids( 'job[categories]', Post_Types::CATEGORY, $first( 'categories' ), 'dev3jb-cat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<div class="dev3jb-field"><label for="dev3jb-type"><?php esc_html_e( 'Job type', 'dev3-ai-job-board' ); ?></label><?php echo self::term_select_ids( 'job[types]', Post_Types::TYPE, $first( 'types' ), 'dev3jb-type' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<div class="dev3jb-field"><label for="dev3jb-exp"><?php esc_html_e( 'Experience level', 'dev3-ai-job-board' ); ?></label><select id="dev3jb-exp" name="job[experience]" data-dev3jb-experience><option value=""><?php esc_html_e( 'Not specified', 'dev3-ai-job-board' ); ?></option>
					<?php
					foreach ( Listings::experience_labels() as $k => $label ) :
						?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $v( 'experience' ), $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div>
				</div>
				<fieldset class="dev3jb-field">
					<legend><?php esc_html_e( 'Workplace', 'dev3-ai-job-board' ); ?></legend>
					<div class="dev3jb-pills">
						<?php foreach ( Listings::workplace_labels() as $k => $label ) : ?>
							<label class="dev3jb-pill"><input type="radio" name="job[workplace]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $v( 'workplace', 'onsite' ), $k ); ?>><span><?php echo esc_html( $label ); ?></span></label>
						<?php endforeach; ?>
					</div>
				</fieldset>
				<div class="dev3jb-grid2">
					<div class="dev3jb-field"><label for="dev3jb-loc"><?php esc_html_e( 'Location', 'dev3-ai-job-board' ); ?></label><?php echo self::term_select_ids( 'job[locations]', Post_Types::LOCATION, $first( 'locations' ), 'dev3jb-loc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<div class="dev3jb-field"><label for="dev3jb-region"><?php esc_html_e( 'Remote region or office address', 'dev3-ai-job-board' ); ?></label><input type="text" id="dev3jb-region" name="job[remote_region]" maxlength="160" value="<?php echo esc_attr( (string) $v( 'remote_region' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Europe time zones', 'dev3-ai-job-board' ); ?>"></div>
				</div>
				<fieldset class="dev3jb-field">
					<legend><?php esc_html_e( 'Salary', 'dev3-ai-job-board' ); ?></legend>
					<div class="dev3jb-salary">
						<label class="dev3jb-sr" for="dev3jb-smin"><?php esc_html_e( 'Minimum', 'dev3-ai-job-board' ); ?></label><input type="number" min="0" step="any" id="dev3jb-smin" name="job[salary_min]" placeholder="<?php esc_attr_e( 'From', 'dev3-ai-job-board' ); ?>" value="<?php echo esc_attr( (string) $v( 'salary_min' ) ); ?>">
						<label class="dev3jb-sr" for="dev3jb-smax"><?php esc_html_e( 'Maximum', 'dev3-ai-job-board' ); ?></label><input type="number" min="0" step="any" id="dev3jb-smax" name="job[salary_max]" placeholder="<?php esc_attr_e( 'To', 'dev3-ai-job-board' ); ?>" value="<?php echo esc_attr( (string) $v( 'salary_max' ) ); ?>">
						<label class="dev3jb-sr" for="dev3jb-scur"><?php esc_html_e( 'Currency', 'dev3-ai-job-board' ); ?></label><input type="text" id="dev3jb-scur" name="job[salary_currency]" maxlength="3" class="dev3jb-cur" value="<?php echo esc_attr( (string) $v( 'salary_currency', Settings::get( 'currency', 'USD' ) ) ); ?>">
						<label class="dev3jb-sr" for="dev3jb-sper"><?php esc_html_e( 'Period', 'dev3-ai-job-board' ); ?></label><select id="dev3jb-sper" name="job[salary_period]">
						<?php
						foreach ( Listings::period_labels() as $k => $label ) :
							?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $v( 'salary_period', 'year' ), $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
					</div>
					<label class="dev3jb-check"><input type="checkbox" name="job[salary_hidden]" value="1" <?php checked( (bool) $v( 'salary_hidden', false ) ); ?>> <span><?php esc_html_e( 'Do not show the salary publicly (jobs with a salary get more applicants)', 'dev3-ai-job-board' ); ?></span></label>
				</fieldset>
				<div class="dev3jb-field"><label for="dev3jb-skills"><?php esc_html_e( 'Skills', 'dev3-ai-job-board' ); ?></label><input type="text" id="dev3jb-skills" name="job[skills]" value="<?php echo esc_attr( $job ? implode( ', ', wp_list_pluck( $job['skills'], 'name' ) ) : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Figma, User research, Prototyping', 'dev3-ai-job-board' ); ?>" data-dev3jb-skills><span class="dev3jb-help"><?php esc_html_e( 'Separate skills with commas. They power search and candidate matching.', 'dev3-ai-job-board' ); ?></span></div>
			</fieldset>

			<fieldset class="dev3jb-section">
				<legend class="dev3jb-h"><?php esc_html_e( 'Description', 'dev3-ai-job-board' ); ?></legend>
				<div class="dev3jb-assist" data-dev3jb-assist data-working="<?php echo esc_attr( $ai ? __( 'Writing your job ad… this can take up to 30 seconds.', 'dev3-ai-job-board' ) : __( 'Building the outline…', 'dev3-ai-job-board' ) ); ?>" data-use="<?php esc_attr_e( 'Use this description', 'dev3-ai-job-board' ); ?>" data-discard="<?php esc_attr_e( 'Discard', 'dev3-ai-job-board' ); ?>" data-used="<?php esc_attr_e( 'Description added. Review and edit it below.', 'dev3-ai-job-board' ); ?>" data-clean="<?php esc_attr_e( 'No exclusionary wording found.', 'dev3-ai-job-board' ); ?>" data-replace="<?php esc_attr_e( 'Replace', 'dev3-ai-job-board' ); ?>">
					<div class="dev3jb-assist__head"><?php echo Templates::icon( 'sparkles' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><div><strong><?php echo esc_html( $ai ? __( 'Writing assistant', 'dev3-ai-job-board' ) : __( 'Quick outline', 'dev3-ai-job-board' ) ); ?></strong><span class="dev3jb-muted"><?php echo esc_html( $ai ? __( 'Write a few notes and get a clear, inclusive job ad to review.', 'dev3-ai-job-board' ) : __( 'Write notes as lines starting with "-" and we turn them into a structured job ad.', 'dev3-ai-job-board' ) ); ?></span></div></div>
					<div class="dev3jb-field"><label for="dev3jb-notes"><?php esc_html_e( 'Notes about the role', 'dev3-ai-job-board' ); ?></label><textarea id="dev3jb-notes" rows="4" data-dev3jb-notes placeholder="<?php esc_attr_e( "What the person will do, must-have skills, team, benefits…\n- Design the onboarding flow\n- 3+ years in product design\n- Remote budget, 30 days holiday", 'dev3-ai-job-board' ); ?>"></textarea></div>
					<div class="dev3jb-row">
						<?php if ( $ai ) : ?>
							<label class="dev3jb-sr" for="dev3jb-tone"><?php esc_html_e( 'Tone', 'dev3-ai-job-board' ); ?></label>
							<select id="dev3jb-tone" class="dev3jb-select-sm" data-dev3jb-tone>
								<option value="professional"><?php esc_html_e( 'Professional', 'dev3-ai-job-board' ); ?></option>
								<option value="friendly"><?php esc_html_e( 'Friendly', 'dev3-ai-job-board' ); ?></option>
								<option value="startup"><?php esc_html_e( 'Startup', 'dev3-ai-job-board' ); ?></option>
								<option value="formal"><?php esc_html_e( 'Formal', 'dev3-ai-job-board' ); ?></option>
							</select>
						<?php endif; ?>
						<button type="button" class="dev3jb-btn dev3jb-btn--primary dev3jb-btn--sm" data-dev3jb-draft><?php echo Templates::icon( 'sparkles', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $ai ? __( 'Draft it for me', 'dev3-ai-job-board' ) : __( 'Build outline', 'dev3-ai-job-board' ) ); ?></button>
						<button type="button" class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" data-dev3jb-inclusive><?php esc_html_e( 'Check wording', 'dev3-ai-job-board' ); ?></button>
					</div>
					<p class="dev3jb-form__status" role="status" aria-live="polite" data-dev3jb-assist-status></p>
					<div class="dev3jb-draft" data-dev3jb-draft-out tabindex="-1" hidden></div>
				</div>
				<?php
				$content = (string) $v( 'content' );
				if ( function_exists( 'wp_editor' ) ) {
					echo '<div class="dev3jb-field dev3jb-editor"><label for="dev3jb_job_content">' . esc_html__( 'Job description', 'dev3-ai-job-board' ) . ' <span aria-hidden="true">*</span></label>';
					wp_editor(
						$content,
						'dev3jb_job_content',
						array(
							'textarea_name' => 'job[content]',
							'media_buttons' => false,
							'textarea_rows' => 12,
							'teeny'         => true,
							'quicktags'     => array( 'buttons' => 'strong,em,ul,ol,li,link' ),
							'tinymce'       => array(
								'toolbar1'      => 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo',
								'block_formats' => 'Paragraph=p;Heading=h3',
							),
						)
					);
					echo '</div>';
				}
				?>
			</fieldset>

			<fieldset class="dev3jb-section">
				<legend class="dev3jb-h"><?php esc_html_e( 'How to apply', 'dev3-ai-job-board' ); ?></legend>
				<div class="dev3jb-pills" data-dev3jb-applymethod>
					<label class="dev3jb-pill"><input type="radio" name="job[apply_method]" value="internal" <?php checked( $v( 'apply_method', 'internal' ), 'internal' ); ?>><span><?php esc_html_e( 'On this site', 'dev3-ai-job-board' ); ?></span></label>
					<label class="dev3jb-pill"><input type="radio" name="job[apply_method]" value="url" <?php checked( $v( 'apply_method', 'internal' ), 'url' ); ?>><span><?php esc_html_e( 'On our website', 'dev3-ai-job-board' ); ?></span></label>
					<label class="dev3jb-pill"><input type="radio" name="job[apply_method]" value="email" <?php checked( $v( 'apply_method', 'internal' ), 'email' ); ?>><span><?php esc_html_e( 'By email', 'dev3-ai-job-board' ); ?></span></label>
				</div>
				<div class="dev3jb-grid2">
					<div class="dev3jb-field" data-dev3jb-apply-url><label for="dev3jb-aurl"><?php esc_html_e( 'Application link', 'dev3-ai-job-board' ); ?></label><input type="url" id="dev3jb-aurl" name="job[apply_url]" value="<?php echo esc_attr( (string) $v( 'apply_url' ) ); ?>" placeholder="https://"></div>
					<div class="dev3jb-field" data-dev3jb-apply-email><label for="dev3jb-aemail"><?php esc_html_e( 'Email for applications', 'dev3-ai-job-board' ); ?></label><input type="email" id="dev3jb-aemail" name="job[apply_email]" value="<?php echo esc_attr( (string) $v( 'apply_email' ) ); ?>" placeholder="<?php esc_attr_e( 'Optional for on-site applications: we notify this address', 'dev3-ai-job-board' ); ?>"></div>
				</div>
				<div class="dev3jb-field dev3jb-field--narrow"><label for="dev3jb-deadline"><?php esc_html_e( 'Application deadline (optional)', 'dev3-ai-job-board' ); ?></label><input type="date" id="dev3jb-deadline" name="job[deadline]" value="<?php echo esc_attr( (string) $v( 'deadline' ) ); ?>"></div>
			</fieldset>

			<div class="dev3jb-form__foot">
				<button type="submit" class="dev3jb-btn dev3jb-btn--primary dev3jb-btn--lg"><?php echo esc_html( $job && 'publish' === $job['status'] ? __( 'Save changes', 'dev3-ai-job-board' ) : __( 'Preview job', 'dev3-ai-job-board' ) ); ?></button>
				<a class="dev3jb-btn dev3jb-btn--ghost" href="<?php echo esc_url( Settings::page_url( 'employer' ) ); ?>"><?php esc_html_e( 'Cancel', 'dev3-ai-job-board' ); ?></a>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Term select by id (hierarchical).
	 *
	 * @param string $name     Field name.
	 * @param string $taxonomy Taxonomy.
	 * @param int    $current  Current id.
	 * @param string $id       Element id.
	 * @return string
	 */
	public static function term_select_ids( $name, $taxonomy, $current, $id ) {
		$html = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '"><option value="">' . esc_html__( 'Choose…', 'dev3-ai-job-board' ) . '</option>';
		$by   = array();
		foreach ( Post_Types::terms( $taxonomy ) as $t ) {
			$by[ $t['parent'] ][] = $t;
		}
		$walk = static function ( $parent_id, $depth ) use ( &$walk, $by, $current, &$html ) {
			foreach ( isset( $by[ $parent_id ] ) ? $by[ $parent_id ] : array() as $t ) {
				$html .= '<option value="' . (int) $t['id'] . '"' . selected( (int) $current, $t['id'], false ) . '>' . esc_html( str_repeat( '— ', $depth ) . $t['name'] ) . '</option>';
				$walk( $t['id'], $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $html . '</select>';
	}

	/**
	 * Preview step.
	 *
	 * @param int $job_id Job.
	 * @return string
	 */
	public static function preview( $job_id ) {
		$job = Listings::get( $job_id );
		if ( ! $job ) {
			return '';
		}
		$next = in_array( $job['status'], array( 'publish', 'pending' ), true ) && $job['user_package'] ? Settings::page_url( 'employer' ) : self::url( 'package', $job_id );
		$bar  = '<div class="dev3jb-previewbar"><p><strong>' . esc_html__( 'This is how candidates will see your job.', 'dev3-ai-job-board' ) . '</strong></p><div class="dev3jb-row"><a class="dev3jb-btn dev3jb-btn--ghost" href="' . esc_url( self::url( 'form', $job_id ) ) . '">' . Templates::icon( 'edit', 16 ) . esc_html__( 'Edit', 'dev3-ai-job-board' ) . '</a><a class="dev3jb-btn dev3jb-btn--primary" href="' . esc_url( $next ) . '">' . esc_html__( 'Continue', 'dev3-ai-job-board' ) . Templates::icon( 'arrow', 16 ) . '</a></div></div>';
		return $bar . '<div class="dev3jb-previewframe">' . Templates::single_job( $job ) . '</div>' . $bar;
	}

	/**
	 * Package step.
	 *
	 * @param int  $job_id Job.
	 * @param bool $relist Relisting an expired job.
	 * @return string
	 */
	public static function package_step( $job_id, $relist = false ) {
		$job = Listings::get( $job_id );
		if ( ! $job ) {
			return '';
		}
		if ( ! $relist && $job['user_package'] && in_array( $job['status'], array( 'publish', 'pending' ), true ) ) {
			return '<div class="dev3jb-notice dev3jb-notice--ok">' . esc_html__( 'This job already has a package.', 'dev3-ai-job-board' ) . ' <a class="dev3jb-link" href="' . esc_url( Settings::page_url( 'employer' ) ) . '">' . esc_html__( 'Go to your dashboard', 'dev3-ai-job-board' ) . '</a></div>';
		}
		$credits  = UserPackages::available( get_current_user_id() );
		$packages = Packages::active();
		$html     = '<form class="dev3jb-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . Forms::guard_fields( 'choose_package' );
		$html    .= '<input type="hidden" name="job_id" value="' . (int) $job_id . '"><input type="hidden" name="relist" value="' . ( $relist ? 1 : 0 ) . '"><input type="hidden" name="return" value="' . esc_url( self::url( 'package', $job_id, $relist ? array( 'relist' => 1 ) : array() ) ) . '">';
		/* translators: %s: job title */
		$html .= '<h2 class="dev3jb-h">' . esc_html( sprintf( $relist ? __( 'Relist "%s"', 'dev3-ai-job-board' ) : __( 'Choose how to publish "%s"', 'dev3-ai-job-board' ), $job['title'] ) ) . '</h2>';
		$first = true;
		if ( $credits ) {
			$html .= '<fieldset class="dev3jb-section"><legend class="dev3jb-h">' . esc_html__( 'Use a package you already have', 'dev3-ai-job-board' ) . '</legend><div class="dev3jb-packages">';
			foreach ( $credits as $c ) {
				$html .= '<label class="dev3jb-package"><input type="radio" name="choice" value="credit:' . (int) $c['id'] . '" ' . checked( $first, true, false ) . '><span class="dev3jb-package__body"><span class="dev3jb-package__name">' . esc_html( $c['name'] ) . '</span>';
				/* translators: 1: remaining posts, 2: days */
				$html .= '<span class="dev3jb-package__meta">' . esc_html( sprintf( _n( '%1$d job post left · %2$d days', '%1$d job posts left · %2$d days', $c['remaining'], 'dev3-ai-job-board' ), $c['remaining'], $c['duration_days'] ) ) . ( $c['featured'] ? ' · ' . esc_html__( 'Featured', 'dev3-ai-job-board' ) : '' ) . '</span></span></label>';
				$first = false;
			}
			$html .= '</div></fieldset>';
		}
		if ( $packages ) {
			$html .= '<fieldset class="dev3jb-section"><legend class="dev3jb-h">' . esc_html( $credits ? __( 'Or buy a new package', 'dev3-ai-job-board' ) : __( 'Choose a package', 'dev3-ai-job-board' ) ) . '</legend><div class="dev3jb-packages">';
			foreach ( $packages as $p ) {
				$html .= '<label class="dev3jb-package' . ( $p['highlight'] ? ' is-highlight' : '' ) . '"><input type="radio" name="choice" value="buy:' . esc_attr( $p['id'] ) . '" ' . checked( $first, true, false ) . '><span class="dev3jb-package__body">';
				if ( $p['highlight'] ) {
					$html .= '<span class="dev3jb-tag dev3jb-tag--featured">' . esc_html__( 'Most popular', 'dev3-ai-job-board' ) . '</span>';
				}
				$html .= '<span class="dev3jb-package__name">' . esc_html( $p['name'] ) . '</span><span class="dev3jb-package__price">' . esc_html( Packages::is_paid( $p ) ? html_entity_decode( Listings::money( $p['price'] ), ENT_QUOTES, 'UTF-8' ) : __( 'Free', 'dev3-ai-job-board' ) ) . '</span>';
				/* translators: 1: job posts, 2: days */
				$html .= '<span class="dev3jb-package__meta">' . esc_html( sprintf( _n( '%1$d job post · %2$d days', '%1$d job posts · %2$d days each', $p['job_limit'], 'dev3-ai-job-board' ), $p['job_limit'], $p['duration_days'] ) ) . '</span>';
				if ( $p['featured'] ) {
					$html .= '<span class="dev3jb-package__meta">' . Templates::icon( 'star', 14 ) . ' ' . esc_html__( 'Featured at the top of search', 'dev3-ai-job-board' ) . '</span>';
				}
				if ( '' !== $p['description'] ) {
					$html .= '<span class="dev3jb-muted">' . esc_html( $p['description'] ) . '</span>';
				}
				$html .= '</span></label>';
				$first = false;
			}
			$html .= '</div></fieldset>';
		}
		if ( ! $credits && ! $packages ) {
			return $html . '<p class="dev3jb-notice dev3jb-notice--error">' . esc_html__( 'No packages are available. Please contact the site owner.', 'dev3-ai-job-board' ) . '</p></form>';
		}
		$gateway = Checkout::gateway();
		$labels  = array(
			'stripe'      => __( 'Paid packages are paid securely by card on the next page.', 'dev3-ai-job-board' ),
			'woocommerce' => __( 'Paid packages go to checkout on the next page.', 'dev3-ai-job-board' ),
			'manual'      => __( 'For paid packages we email you payment instructions. Your job goes live once the payment is confirmed.', 'dev3-ai-job-board' ),
		);
		$html   .= '<p class="dev3jb-muted">' . esc_html( $labels[ $gateway ] ) . '</p>';
		return $html . '<div class="dev3jb-form__foot"><button type="submit" class="dev3jb-btn dev3jb-btn--primary dev3jb-btn--lg">' . esc_html( $relist ? __( 'Relist job', 'dev3-ai-job-board' ) : __( 'Submit job', 'dev3-ai-job-board' ) ) . '</button><a class="dev3jb-btn dev3jb-btn--ghost" href="' . esc_url( self::url( 'preview', $job_id ) ) . '">' . esc_html__( 'Back', 'dev3-ai-job-board' ) . '</a></div></form>';
	}

	/**
	 * Uploads a company logo (image only) for a user without upload_files.
	 *
	 * @param array $file $_FILES entry.
	 * @return int|\WP_Error Attachment id (0 when none).
	 */
	public static function upload_logo( array $file ) {
		if ( empty( $file['name'] ) || ( isset( $file['error'] ) && UPLOAD_ERR_NO_FILE === (int) $file['error'] ) ) {
			return 0;
		}
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'dev3jb_logo', __( 'The logo upload failed. Try again.', 'dev3-ai-job-board' ) );
		}
		if ( (int) $file['size'] > self::LOGO_MAX ) {
			return new \WP_Error( 'dev3jb_logo', __( 'The logo must be smaller than 2 MB.', 'dev3-ai-job-board' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$mimes  = array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
		);
		$result = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'dev3jb_logo', (string) $result['error'] );
		}
		if ( ! wp_getimagesize( $result['file'] ) ) {
			wp_delete_file( $result['file'] );
			return new \WP_Error( 'dev3jb_logo', __( 'Upload a PNG, JPG or WebP image.', 'dev3-ai-job-board' ) );
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $result['type'],
				'post_title'     => sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
				'post_author'    => get_current_user_id(),
			),
			$result['file']
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $result['file'] ) );
		return (int) $id;
	}

	/**
	 * Details form handler.
	 */
	public static function handle() {
		$back  = Forms::return_url( Settings::page_url( 'submit' ) );
		$guard = Forms::check_guard( 'submit_job', 20, 2 );
		if ( is_wp_error( $guard ) ) {
			Forms::back( $back, $guard );
		}
		$user = get_current_user_id();
		if ( ! $user ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_login', __( 'Log in to post a job.', 'dev3-ai-job-board' ) ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_guard().
		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		if ( $job_id && ! Listings::owner_can( $job_id, $user ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_forbidden', __( 'You cannot edit this job.', 'dev3-ai-job-board' ) ) );
		}
		$raw = isset( $_POST['job'] ) && is_array( $_POST['job'] ) ? wp_unslash( $_POST['job'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Listings::sanitize() sanitizes every key.
		$cid = isset( $_POST['company_id'] ) ? sanitize_text_field( wp_unslash( $_POST['company_id'] ) ) : 'new';
		if ( 'new' === $cid ) {
			$craw    = isset( $_POST['company'] ) && is_array( $_POST['company'] ) ? wp_unslash( $_POST['company'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Companies::sanitize() sanitizes every key.
			$company = Companies::sanitize( array_intersect_key( (array) $craw, array_flip( array( 'name', 'website', 'tagline', 'description' ) ) ) );
			if ( empty( $company['name'] ) ) {
				Forms::back( $back, new \WP_Error( 'dev3jb_company_name', __( 'Add the company name.', 'dev3-ai-job-board' ) ) );
			}
			$logo = isset( $_FILES['company_logo'] ) ? self::upload_logo( map_deep( wp_unslash( $_FILES['company_logo'] ), 'sanitize_text_field' ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_wp_error( $logo ) ) {
				Forms::back( $back, $logo );
			}
			if ( $logo ) {
				$company['logo_id'] = $logo;
			}
			$cid = Companies::save( $company, 0, $user, 'publish' );
			if ( is_wp_error( $cid ) ) {
				Forms::back( $back, $cid );
			}
		} elseif ( ! Companies::owner_can( (int) $cid, $user ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_company', __( 'Choose one of your companies.', 'dev3-ai-job-board' ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$raw['company_id'] = (int) $cid;
		if ( ! isset( $raw['salary_hidden'] ) ) {
			$raw['salary_hidden'] = 0;
		}
		foreach ( array( 'categories', 'types', 'locations' ) as $k ) {
			$raw[ $k ] = isset( $raw[ $k ] ) && '' !== $raw[ $k ] ? array( (int) $raw[ $k ] ) : array();
		}
		$data = Listings::sanitize( $raw );
		unset( $data['featured'], $data['filled'], $data['expires'] );
		if ( empty( $data['content'] ) || '' === trim( wp_strip_all_tags( $data['content'] ) ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_content', __( 'Add a job description (the writing assistant can help).', 'dev3-ai-job-board' ) ) );
		}
		$current = $job_id ? get_post_status( $job_id ) : '';
		$status  = '';
		if ( 'publish' === $current && Settings::get( 'edit_moderation', false ) && ! current_user_can( 'edit_others_posts' ) ) {
			$status = 'pending';
		}
		$id = Listings::save( $data, $job_id, $user, $status );
		if ( is_wp_error( $id ) ) {
			Forms::back( $back, $id );
		}
		$wp_user = get_userdata( $user );
		if ( $wp_user && ! Post_Types::is_employer( $user ) ) {
			$wp_user->add_role( Post_Types::ROLE_EMPLOYER );
		}
		if ( 'publish' === $current ) {
			Forms::back( Checkout::dashboard_url(), 'pending' === $status ? 'updated_review' : 'updated' );
		}
		wp_safe_redirect( self::url( 'preview', $id ) );
		exit;
	}

	/**
	 * Package step handler.
	 */
	public static function handle_package() {
		$back  = Forms::return_url( Settings::page_url( 'submit' ) );
		$guard = Forms::check_guard( 'choose_package', 20, 0 );
		if ( is_wp_error( $guard ) ) {
			Forms::back( $back, $guard );
		}
		$user = get_current_user_id();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_guard().
		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$relist = ! empty( $_POST['relist'] );
		$choice = isset( $_POST['choice'] ) ? sanitize_text_field( wp_unslash( $_POST['choice'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $user || ! Listings::owner_can( $job_id, $user ) ) {
			Forms::back( $back, new \WP_Error( 'dev3jb_forbidden', __( 'You cannot publish this job.', 'dev3-ai-job-board' ) ) );
		}
		list( $kind, $value ) = array_pad( explode( ':', $choice, 2 ), 2, '' );
		if ( 'credit' === $kind ) {
			$package = UserPackages::get( (int) $value );
			if ( ! $package || (int) $package['user_id'] !== $user ) {
				Forms::back( $back, new \WP_Error( 'dev3jb_package', __( 'Choose a package.', 'dev3-ai-job-board' ) ) );
			}
			$res = $relist ? Listings::relist( $job_id, $package ) : Checkout::use_credit( $job_id, $package['id'], $user );
			if ( is_wp_error( $res ) ) {
				Forms::back( $back, $res );
			}
			$status = is_array( $res ) ? $res['status'] : $res;
			Forms::back( Checkout::dashboard_url(), 'publish' === $status ? 'published' : 'submitted' );
		}
		if ( 'buy' === $kind ) {
			if ( $relist ) {
				update_post_meta( $job_id, '_dev3jb_filled', 0 );
			}
			$res = Checkout::start( $user, $value, $job_id );
			if ( is_wp_error( $res ) ) {
				Forms::back( $back, $res );
			}
			wp_safe_redirect( $res['redirect'] );
			exit;
		}
		Forms::back( $back, new \WP_Error( 'dev3jb_package', __( 'Choose a package.', 'dev3-ai-job-board' ) ) );
	}
}
