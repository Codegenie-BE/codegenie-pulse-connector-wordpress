<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress-native settings, connection test and Site Health status.
 */
final class Codegenie_Pulse_Admin {
	const PAGE_SLUG = 'codegenie-pulse-connector';

	/** @var Codegenie_Pulse_Options */
	private $options;

	/** @var Codegenie_Pulse_Reporter */
	private $reporter;

	/** @var Codegenie_Pulse_Secret_Store */
	private $secret_store;

	/** @var Codegenie_Pulse_Connection */
	private $connection;

	/**
	 * @param Codegenie_Pulse_Options      $options      Options.
	 * @param Codegenie_Pulse_Reporter     $reporter     Reporter.
	 * @param Codegenie_Pulse_Secret_Store $secret_store Secret store.
	 * @param Codegenie_Pulse_Connection   $connection   Automatic connection flow.
	 */
	public function __construct( Codegenie_Pulse_Options $options, Codegenie_Pulse_Reporter $reporter, Codegenie_Pulse_Secret_Store $secret_store, Codegenie_Pulse_Connection $connection ) {
		$this->options      = $options;
		$this->reporter     = $reporter;
		$this->secret_store = $secret_store;
		$this->connection   = $connection;
	}

	/**
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_post_codegenie_pulse_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_codegenie_pulse_authorize', array( $this, 'handle_authorize' ) );
		add_action( 'admin_post_codegenie_pulse_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_codegenie_pulse_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_init', array( $this, 'harden_authorization_page' ), 1 );
		add_filter( 'plugin_action_links_' . plugin_basename( CODEGENIE_PULSE_CONNECTOR_FILE ), array( $this, 'plugin_action_links' ) );
		add_filter( 'debug_information', array( $this, 'add_debug_information' ) );
		add_filter( 'site_status_tests', array( $this, 'add_site_health_test' ) );
	}

	/**
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Codegenie Pulse', 'codegenie-pulse-connector' ),
			__( 'Codegenie Pulse', 'codegenie-pulse-connector' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * @param string[] $links Plugin links.
	 * @return string[]
	 */
	public function plugin_action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( $this->settings_url() ) . '">' . esc_html__( 'Instellingen', 'codegenie-pulse-connector' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Render the settings and onboarding page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->options->all();
		$state    = $this->options->state();
		$status   = $this->connection_status( $state );
		$notice   = $this->take_notice();
		$authorization = $this->authorization_request();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Codegenie Pulse Connector', 'codegenie-pulse-connector' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_wp_error( $authorization ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $authorization->get_error_message() ); ?></p></div>
			<?php elseif ( is_array( $authorization ) ) : ?>
				<div class="card" style="max-width: 100%; border-left: 4px solid #2271b1;">
					<h2><?php echo esc_html__( 'Codegenie Pulse wil deze website koppelen', 'codegenie-pulse-connector' ); ?></h2>
					<p><?php echo esc_html( sprintf( __( 'Platform: %s', 'codegenie-pulse-connector' ), $authorization['pulse_host'] ) ); ?></p>
					<p><?php echo esc_html( sprintf( __( 'WordPress-site: %s', 'codegenie-pulse-connector' ), home_url( '/' ) ) ); ?></p>
					<p><?php echo esc_html__( 'Na je goedkeuring deelt WordPress de sitenaam en technische WordPress-, plugin- en PHP-versies. Pulse stelt websiteverificatie en de functies van je abonnement automatisch in.', 'codegenie-pulse-connector' ); ?></p>
					<p><strong><?php echo esc_html__( 'Er worden geen bezoekersdata, wachtwoorden, cookies, formulierinhoud of bestaande logbestanden gedeeld.', 'codegenie-pulse-connector' ); ?></strong></p>
					<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:16px;">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="codegenie_pulse_authorize">
							<input type="hidden" name="request_token" value="<?php echo esc_attr( $authorization['request_token'] ); ?>">
							<input type="hidden" name="challenge_id" value="<?php echo esc_attr( $authorization['challenge_id'] ); ?>">
							<input type="hidden" name="pulse_origin" value="<?php echo esc_attr( $authorization['pulse_origin'] ); ?>">
							<?php wp_nonce_field( 'codegenie_pulse_authorize' ); ?>
							<?php submit_button( __( 'Koppeling goedkeuren', 'codegenie-pulse-connector' ), 'primary', 'submit', false ); ?>
						</form>
						<a href="<?php echo esc_url( $this->settings_url() ); ?>" class="button button-secondary"><?php echo esc_html__( 'Annuleren', 'codegenie-pulse-connector' ); ?></a>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( ! $this->secret_store->is_available() ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'OpenSSL ontbreekt. Activeer de PHP OpenSSL-extensie voordat je een DSN opslaat.', 'codegenie-pulse-connector' ); ?></p></div>
			<?php elseif ( $this->options->has_stored_dsn() && ! $this->options->has_readable_dsn() ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'De opgeslagen DSN kan niet meer worden ontsleuteld. Dit kan gebeuren nadat WordPress salts zijn gewijzigd. Plak de DSN opnieuw.', 'codegenie-pulse-connector' ); ?></p></div>
			<?php endif; ?>

			<?php if ( Codegenie_Pulse_Options::CAPTURE_DEBUG === $this->options->capture_mode() && function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type() ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'De capturemodus Debug staat aan op een productieomgeving. Gebruik deze modus alleen tijdelijk omdat notices en deprecated-meldingen extra events kunnen veroorzaken.', 'codegenie-pulse-connector' ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width: 100%;">
				<h2><?php echo esc_html__( 'Verbindingsstatus', 'codegenie-pulse-connector' ); ?></h2>
				<p><strong><?php echo esc_html( $status['label'] ); ?></strong></p>
				<p><?php echo esc_html( $status['description'] ); ?></p>
				<p><code><?php echo esc_html( $this->connection_label() ); ?></code></p>
				<?php if ( ! empty( $state['last_success_at'] ) ) : ?>
					<p><?php echo esc_html( sprintf( __( 'Laatste succesvolle levering: %s', 'codegenie-pulse-connector' ), $this->format_datetime( $state['last_success_at'] ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $state['last_failure_at'] ) && ! empty( $state['last_failure_message'] ) ) : ?>
					<p><?php echo esc_html( sprintf( __( 'Laatste probleem op %1$s: %2$s', 'codegenie-pulse-connector' ), $this->format_datetime( $state['last_failure_at'] ), $state['last_failure_message'] ) ); ?></p>
				<?php endif; ?>
			</div>

			<div class="card" style="max-width: 100%;">
				<h2><?php echo esc_html__( 'Automatisch koppelen vanuit Pulse', 'codegenie-pulse-connector' ); ?></h2>
				<p><?php echo esc_html__( 'Open Codegenie Pulse, kies Website toevoegen en daarna WordPress koppelen. Je keert automatisch terug naar dit scherm om de koppeling goed te keuren. Tokens en DSN-velden worden daarna veilig ingevuld.', 'codegenie-pulse-connector' ); ?></p>
				<?php if ( '' !== (string) $settings['pulse_dashboard_url'] ) : ?>
					<p><a class="button button-secondary" href="<?php echo esc_url( $settings['pulse_dashboard_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Website openen in Codegenie Pulse', 'codegenie-pulse-connector' ); ?></a></p>
				<?php endif; ?>
			</div>

			<h2><?php echo esc_html__( 'Handmatige fallback en geavanceerde instellingen', 'codegenie-pulse-connector' ); ?></h2>
			<p><?php echo esc_html__( 'Gebruik onderstaande velden alleen wanneer de automatische koppeling niet beschikbaar is of wanneer je de capture-instellingen bewust wilt aanpassen.', 'codegenie-pulse-connector' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="codegenie_pulse_save">
				<?php wp_nonce_field( 'codegenie_pulse_save' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="codegenie-pulse-verification-token"><?php echo esc_html__( 'Websiteverificatietoken', 'codegenie-pulse-connector' ); ?></label></th>
						<td>
							<input id="codegenie-pulse-verification-token" name="verification_token" type="text" class="regular-text code" minlength="32" maxlength="128" autocomplete="off" value="<?php echo esc_attr( $settings['verification_token'] ); ?>">
							<p class="description"><?php echo esc_html__( 'De plugin publiceert dit op /.well-known/codegenie-pulse.txt. Dit token is uitsluitend bedoeld voor websiteverificatie.', 'codegenie-pulse-connector' ); ?></p>
							<?php if ( ! empty( $settings['verification_token'] ) ) : ?>
								<p><a href="<?php echo esc_url( home_url( '/.well-known/codegenie-pulse.txt' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Verificatiebestand openen', 'codegenie-pulse-connector' ); ?></a></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="codegenie-pulse-dsn"><?php echo esc_html__( 'Codegenie Pulse DSN', 'codegenie-pulse-connector' ); ?></label></th>
						<td>
							<input id="codegenie-pulse-dsn" name="dsn" type="password" class="large-text code" maxlength="2048" autocomplete="new-password" placeholder="<?php echo esc_attr( $this->options->has_stored_dsn() ? __( 'Leeg laten om de huidige DSN te behouden', 'codegenie-pulse-connector' ) : 'https://pulse.example/api/ingest/errors/{token}' ); ?>">
							<p class="description"><?php echo esc_html__( 'De DSN moet HTTPS gebruiken. De token wordt nooit opnieuw in het dashboard getoond en versleuteld opgeslagen met je WordPress salts.', 'codegenie-pulse-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="codegenie-pulse-capture-mode"><?php echo esc_html__( 'PHP-foutcapture', 'codegenie-pulse-connector' ); ?></label></th>
						<td>
							<select id="codegenie-pulse-capture-mode" name="error_capture_mode">
								<option value="off" <?php selected( Codegenie_Pulse_Options::CAPTURE_OFF, $settings['error_capture_mode'] ); ?>><?php echo esc_html__( 'Uitgeschakeld', 'codegenie-pulse-connector' ); ?></option>
								<option value="production" <?php selected( Codegenie_Pulse_Options::CAPTURE_PRODUCTION, $settings['error_capture_mode'] ); ?>><?php echo esc_html__( 'Productie, aanbevolen', 'codegenie-pulse-connector' ); ?></option>
								<option value="extended" <?php selected( Codegenie_Pulse_Options::CAPTURE_EXTENDED, $settings['error_capture_mode'] ); ?>><?php echo esc_html__( 'Uitgebreid', 'codegenie-pulse-connector' ); ?></option>
								<option value="debug" <?php selected( Codegenie_Pulse_Options::CAPTURE_DEBUG, $settings['error_capture_mode'] ); ?>><?php echo esc_html__( 'Debug, alleen tijdelijk', 'codegenie-pulse-connector' ); ?></option>
							</select>
							<p class="description"><?php echo esc_html__( 'Productie rapporteert fatale fouten en onverwerkte exceptions. Uitgebreid voegt PHP warnings toe. Debug voegt ook notices, strict- en deprecated-meldingen toe, voor zover de PHP error_reporting-instelling ze activeert.', 'codegenie-pulse-connector' ); ?></p>
							<p class="description"><?php echo esc_html__( 'Identieke niet-fatale fouten worden standaard maximaal één keer per minuut verstuurd, met maximaal tien unieke niet-fatale events per request.', 'codegenie-pulse-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'WordPress-signalen', 'codegenie-pulse-connector' ); ?></th>
						<td>
							<label><input type="checkbox" name="capture_mail_failures" value="1" <?php checked( ! empty( $settings['capture_mail_failures'] ) ); ?>> <?php echo esc_html__( 'Mislukte WordPress e-mails rapporteren zonder ontvangers of berichtinhoud', 'codegenie-pulse-connector' ); ?></label><br>
							<label><input type="checkbox" name="capture_rest_errors" value="1" <?php checked( ! empty( $settings['capture_rest_errors'] ) ); ?>> <?php echo esc_html__( 'REST API serverfouten met status 5xx rapporteren', 'codegenie-pulse-connector' ); ?></label>
							<p class="description"><?php echo esc_html__( 'Deze signalen worden alleen automatisch verstuurd wanneer PHP-foutcapture niet is uitgeschakeld.', 'codegenie-pulse-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Deployment tracking', 'codegenie-pulse-connector' ); ?></th>
						<td>
							<label><input type="checkbox" name="deployment_tracking" value="1" <?php checked( ! empty( $settings['deployment_tracking'] ) ); ?>> <?php echo esc_html__( 'WordPress-, plugin- en themawijzigingen als releases registreren', 'codegenie-pulse-connector' ); ?></label>
							<p class="description"><?php echo esc_html__( 'Dit gebruikt automatisch het deployment-endpoint met dezelfde DSN-token. De functie moet in je Pulse-plan actief zijn.', 'codegenie-pulse-connector' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Instellingen opslaan', 'codegenie-pulse-connector' ) ); ?>
			</form>

			<?php if ( $this->options->has_platform_connection() ) : ?>
				<hr>
				<h2><?php echo esc_html__( 'Verbinding beheren', 'codegenie-pulse-connector' ); ?></h2>
				<?php if ( $this->options->has_readable_dsn() ) : ?>
					<p><?php echo esc_html__( 'Een test verstuurt één info-event en telt daarom als één verwerkt event binnen je Pulse-plan.', 'codegenie-pulse-connector' ); ?></p>
				<?php else : ?>
					<p><?php echo esc_html__( 'De website is gekoppeld voor monitoring. Foutmonitoring vereist een plan dat deze functie ondersteunt.', 'codegenie-pulse-connector' ); ?></p>
				<?php endif; ?>
				<div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap;">
					<?php if ( $this->options->has_readable_dsn() ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="codegenie_pulse_test">
							<?php wp_nonce_field( 'codegenie_pulse_test' ); ?>
							<?php submit_button( __( 'Verbinding testen', 'codegenie-pulse-connector' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'De lokale Codegenie Pulse-koppeling verbreken? De website blijft in Pulse staan totdat je die daar verwijdert of archiveert.', 'codegenie-pulse-connector' ) ); ?>');">
						<input type="hidden" name="action" value="codegenie_pulse_disconnect">
						<?php wp_nonce_field( 'codegenie_pulse_disconnect' ); ?>
						<?php submit_button( __( 'Lokale koppeling verbreken', 'codegenie-pulse-connector' ), 'delete', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width: 100%;">
				<h2><?php echo esc_html__( 'Privacy en veiligheid', 'codegenie-pulse-connector' ); ?></h2>
				<p><?php echo esc_html__( 'De connector verstuurt geen cookies, autorisatieheaders, formulierdata, request bodies, e-mailadressen of WordPress-gebruikers. Bij een goedgekeurde koppeling worden alleen site- en technische versies gedeeld. URL-querystrings en bekende geheimen worden lokaal verwijderd. De connector leest geen bestaand debug.log- of PHP error_log-bestand.', 'codegenie-pulse-connector' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Complete a Pulse-started connection after explicit administrator consent.
	 *
	 * @return void
	 */
	public function handle_authorize() {
		$this->authorize_action( 'codegenie_pulse_authorize' );

		if ( ! $this->secret_store->is_available() ) {
			$this->set_notice( 'error', __( 'OpenSSL is vereist voor de veilige WordPress-koppeling.', 'codegenie-pulse-connector' ) );
			$this->redirect_to_settings();

			return;
		}

		$request_token = isset( $_POST['request_token'] ) ? sanitize_text_field( wp_unslash( $_POST['request_token'] ) ) : '';
		$challenge_id  = isset( $_POST['challenge_id'] ) ? sanitize_text_field( wp_unslash( $_POST['challenge_id'] ) ) : '';
		$pulse_origin  = isset( $_POST['pulse_origin'] ) ? esc_url_raw( wp_unslash( $_POST['pulse_origin'] ) ) : '';
		$result        = $this->connection->exchange( $pulse_origin, $request_token, $challenge_id );

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
			$this->redirect_to_settings();

			return;
		}

		$this->set_notice(
			'success',
			isset( $result['message'] ) && is_string( $result['message'] )
				? $result['message']
				: __( 'WordPress is gekoppeld met Codegenie Pulse.', 'codegenie-pulse-connector' )
		);

		if ( ! empty( $result['dashboard_url'] ) && is_string( $result['dashboard_url'] ) ) {
			$this->redirect_to_pulse( $result['dashboard_url'] );

			return;
		}

		$this->redirect_to_settings();
	}

	/**
	 * Prevent one-time authorization values from being cached or sent as a referrer.
	 *
	 * @return void
	 */
	public function harden_authorization_page() {
		if ( ! isset( $_GET['page'], $_GET['codegenie_pulse_authorize'] ) ) {
			return;
		}

		if ( self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * @return void
	 */
	public function handle_save() {
		$this->authorize_action( 'codegenie_pulse_save' );

		$result = $this->options->save(
			array(
				'verification_token'        => isset( $_POST['verification_token'] ) ? sanitize_text_field( wp_unslash( $_POST['verification_token'] ) ) : '',
				'dsn'                       => isset( $_POST['dsn'] ) ? trim( wp_unslash( $_POST['dsn'] ) ) : '',
				'error_capture_mode'         => isset( $_POST['error_capture_mode'] ) ? sanitize_key( wp_unslash( $_POST['error_capture_mode'] ) ) : Codegenie_Pulse_Options::CAPTURE_PRODUCTION,
				'capture_mail_failures'     => isset( $_POST['capture_mail_failures'] ),
				'capture_rest_errors'       => isset( $_POST['capture_rest_errors'] ),
				'deployment_tracking'       => isset( $_POST['deployment_tracking'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
		} else {
			$this->set_notice( 'success', __( 'De Codegenie Pulse-instellingen zijn opgeslagen.', 'codegenie-pulse-connector' ) );
		}

		$this->redirect_to_settings();
	}

	/**
	 * @return void
	 */
	public function handle_test() {
		$this->authorize_action( 'codegenie_pulse_test' );

		$result = $this->reporter->report_message(
			'Codegenie Pulse WordPress connection test',
			'info',
			array(
				'capture_source' => 'manual_connection_test',
				'test_event'     => true,
			),
			true
		);

		$this->set_notice(
			! empty( $result['success'] ) ? 'success' : 'error',
			! empty( $result['success'] )
				? __( 'Verbinding geslaagd. Het testevent staat in Codegenie Pulse.', 'codegenie-pulse-connector' )
				: ( isset( $result['message'] ) ? $result['message'] : __( 'De verbindingstest is mislukt.', 'codegenie-pulse-connector' ) )
		);

		$this->redirect_to_settings();
	}

	/**
	 * @return void
	 */
	public function handle_disconnect() {
		$this->authorize_action( 'codegenie_pulse_disconnect' );
		$this->options->disconnect();
		$this->set_notice( 'success', __( 'De lokale Codegenie Pulse-koppeling is verwijderd. Beheer of archiveer de website afzonderlijk in Pulse.', 'codegenie-pulse-connector' ) );
		$this->redirect_to_settings();
	}

	/**
	 * Add token-safe diagnostic data to Tools > Site Health > Info.
	 *
	 * @param array<string, mixed> $info Site Health info.
	 * @return array<string, mixed>
	 */
	public function add_debug_information( $info ) {
		$status = $this->connection_status( $this->options->state() );

		$info['codegenie_pulse_connector'] = array(
			'label'  => __( 'Codegenie Pulse Connector', 'codegenie-pulse-connector' ),
			'fields' => array(
				'version' => array(
					'label' => __( 'Pluginversie', 'codegenie-pulse-connector' ),
					'value' => CODEGENIE_PULSE_CONNECTOR_VERSION,
				),
				'status'  => array(
					'label' => __( 'Status', 'codegenie-pulse-connector' ),
					'value' => $status['label'],
				),
				'endpoint' => array(
					'label'   => __( 'Endpoint', 'codegenie-pulse-connector' ),
					'value'   => $this->connection_label(),
					'private' => true,
				),
				'capture_mode' => array(
					'label' => __( 'PHP-foutcapture', 'codegenie-pulse-connector' ),
					'value' => $this->capture_mode_label(),
				),
			),
		);

		return $info;
	}

	/**
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function add_site_health_test( $tests ) {
		$tests['direct']['codegenie_pulse_connection'] = array(
			'label' => __( 'Codegenie Pulse-verbinding', 'codegenie-pulse-connector' ),
			'test'  => array( $this, 'site_health_result' ),
		);

		return $tests;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function site_health_result() {
		$status = $this->connection_status( $this->options->state() );
		$good   = in_array( $status['code'], array( 'connected', 'connected_limited' ), true );

		return array(
			'label'       => $status['label'],
			'status'      => $good ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'Codegenie Pulse', 'codegenie-pulse-connector' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $status['description'] ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( $this->settings_url() ) . '">' . esc_html__( 'Connectorinstellingen openen', 'codegenie-pulse-connector' ) . '</a></p>',
			'test'        => 'codegenie_pulse_connection',
		);
	}

	/**
	 * @param array<string, mixed> $state Connection state.
	 * @return array<string, string>
	 */
	private function connection_status( $state ) {
		if ( 'automatic' === (string) $this->options->get( 'connection_method', '' ) && $this->options->has_platform_connection() ) {
			if ( $this->options->has_stored_dsn() && ! $this->options->has_readable_dsn() ) {
				return array(
					'code'        => 'unreadable',
					'label'       => __( 'Opnieuw koppelen vereist', 'codegenie-pulse-connector' ),
					'description' => __( 'De versleutelde DSN kan niet worden gelezen. Start de WordPress-koppeling opnieuw vanuit Pulse.', 'codegenie-pulse-connector' ),
				);
			}

			if ( ! $this->options->has_readable_dsn() ) {
				$plan_label = (string) $this->options->get( 'pulse_plan_label', '' );

				return array(
					'code'        => 'connected_limited',
					'label'       => __( 'Website gekoppeld', 'codegenie-pulse-connector' ),
					'description' => '' !== $plan_label
						? sprintf( __( 'De website is automatisch gekoppeld. Foutmonitoring is niet actief binnen het plan %s.', 'codegenie-pulse-connector' ), $plan_label )
						: __( 'De website is automatisch gekoppeld. Foutmonitoring is niet actief binnen het huidige plan.', 'codegenie-pulse-connector' ),
				);
			}

			return array(
				'code'        => 'connected',
				'label'       => __( 'Automatisch verbonden', 'codegenie-pulse-connector' ),
				'description' => __( 'Websiteverificatie en de beschikbare Pulse-functies zijn automatisch ingesteld.', 'codegenie-pulse-connector' ),
			);
		}

		if ( ! $this->options->has_stored_dsn() ) {
			return array(
				'code'        => 'not_configured',
				'label'       => __( 'Nog niet gekoppeld', 'codegenie-pulse-connector' ),
				'description' => __( 'Plak een DSN uit Codegenie Pulse om foutmonitoring te activeren.', 'codegenie-pulse-connector' ),
			);
		}

		if ( ! $this->options->has_readable_dsn() ) {
			return array(
				'code'        => 'unreadable',
				'label'       => __( 'Opnieuw koppelen vereist', 'codegenie-pulse-connector' ),
				'description' => __( 'De versleutelde DSN kan niet worden gelezen. Plak de DSN opnieuw.', 'codegenie-pulse-connector' ),
			);
		}

		$success_at = ! empty( $state['last_error_success_at'] ) ? strtotime( $state['last_error_success_at'] ) : 0;
		$failure_at = ! empty( $state['last_error_failure_at'] )
			? strtotime( $state['last_error_failure_at'] )
			: 0;

		if ( $success_at > 0 && $success_at >= $failure_at ) {
			return array(
				'code'        => 'connected',
				'label'       => __( 'Verbonden', 'codegenie-pulse-connector' ),
				'description' => __( 'WordPress kan gebeurtenissen naar Codegenie Pulse sturen.', 'codegenie-pulse-connector' ),
			);
		}

		if ( $failure_at > 0 ) {
			return array(
				'code'        => 'failed',
				'label'       => __( 'Aandacht vereist', 'codegenie-pulse-connector' ),
				'description' => ! empty( $state['last_error_failure_message'] ) ? (string) $state['last_error_failure_message'] : __( 'De laatste foutlevering is mislukt.', 'codegenie-pulse-connector' ),
			);
		}

		return array(
			'code'        => 'ready',
			'label'       => __( 'Klaar om te testen', 'codegenie-pulse-connector' ),
			'description' => __( 'De DSN is opgeslagen. Verstuur nu een verbindingstest.', 'codegenie-pulse-connector' ),
		);
	}

	/**
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Je hebt onvoldoende rechten voor deze actie.', 'codegenie-pulse-connector' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( $action );
	}

	/**
	 * Validate the browser-visible, short-lived authorization values.
	 * The site proof itself never appears in this request.
	 *
	 * @return array<string, string>|WP_Error|null
	 */
	private function authorization_request() {
		if ( ! isset( $_GET['codegenie_pulse_authorize'] ) ) {
			return null;
		}

		$request_token = isset( $_GET['request'] ) ? sanitize_text_field( wp_unslash( $_GET['request'] ) ) : '';
		$challenge_id  = isset( $_GET['challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['challenge'] ) ) : '';
		$pulse_origin  = isset( $_GET['pulse_origin'] ) ? esc_url_raw( wp_unslash( $_GET['pulse_origin'] ) ) : '';

		if ( 1 !== preg_match( '/^[A-Za-z0-9]{64}$/', $request_token ) || 1 !== preg_match( '/^[A-Za-z0-9]{48}$/', $challenge_id ) ) {
			return new WP_Error( 'codegenie_pulse_invalid_authorization', __( 'De WordPress-koppelingsaanvraag is ongeldig. Start opnieuw in Codegenie Pulse.', 'codegenie-pulse-connector' ) );
		}

		$normalized_origin = $this->connection->normalize_origin( $pulse_origin );

		if ( is_wp_error( $normalized_origin ) ) {
			return $normalized_origin;
		}

		$parts = wp_parse_url( $normalized_origin );

		return array(
			'request_token' => $request_token,
			'challenge_id'  => $challenge_id,
			'pulse_origin'  => $normalized_origin,
			'pulse_host'    => is_array( $parts ) && isset( $parts['host'] ) ? (string) $parts['host'] : $normalized_origin,
		);
	}

	/**
	 * @return string
	 */
	private function settings_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Redirect only to the already validated Pulse host.
	 *
	 * @param string $url Pulse dashboard URL.
	 * @return void
	 */
	private function redirect_to_pulse( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			$this->redirect_to_settings();

			return;
		}

		$allowed_host = strtolower( (string) $parts['host'] );

		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) use ( $allowed_host ) {
				$hosts[] = $allowed_host;

				return array_values( array_unique( $hosts ) );
			}
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * @return void
	 */
	private function redirect_to_settings() {
		wp_safe_redirect( $this->settings_url() );
		exit;
	}

	/**
	 * @return string
	 */
	private function connection_label() {
		if ( $this->options->has_readable_dsn() || $this->options->has_stored_dsn() ) {
			return $this->options->masked_dsn();
		}

		$pulse_origin = (string) $this->options->get( 'pulse_origin', '' );
		$plan_label   = (string) $this->options->get( 'pulse_plan_label', '' );

		if ( '' !== $pulse_origin ) {
			$host = wp_parse_url( $pulse_origin, PHP_URL_HOST );
			$label = is_string( $host ) && '' !== $host ? $host : $pulse_origin;

			return '' !== $plan_label ? $label . ', ' . $plan_label : $label;
		}

		return $this->options->masked_dsn();
	}

	/**
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 * @return void
	 */
	private function set_notice( $type, $message ) {
		set_transient(
			'codegenie_pulse_notice_' . get_current_user_id(),
			array(
				'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
				'message' => sanitize_text_field( $message ),
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * @return array<string, string>|null
	 */
	private function take_notice() {
		$key    = 'codegenie_pulse_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );

		return is_array( $notice ) ? $notice : null;
	}

	/**
	 * @param string $value ISO datetime.
	 * @return string
	 */
	private function format_datetime( $value ) {
		$timestamp = strtotime( $value );

		return $timestamp ? wp_date( 'd/m/Y H:i', $timestamp ) : '-';
	}

	/**
	 * @return string
	 */
	private function capture_mode_label() {
		switch ( $this->options->capture_mode() ) {
			case Codegenie_Pulse_Options::CAPTURE_OFF:
				return __( 'Uitgeschakeld', 'codegenie-pulse-connector' );
			case Codegenie_Pulse_Options::CAPTURE_EXTENDED:
				return __( 'Uitgebreid', 'codegenie-pulse-connector' );
			case Codegenie_Pulse_Options::CAPTURE_DEBUG:
				return __( 'Debug', 'codegenie-pulse-connector' );
			default:
				return __( 'Productie', 'codegenie-pulse-connector' );
		}
	}
}
