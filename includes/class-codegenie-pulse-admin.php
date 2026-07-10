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

	/**
	 * @param Codegenie_Pulse_Options      $options      Options.
	 * @param Codegenie_Pulse_Reporter     $reporter     Reporter.
	 * @param Codegenie_Pulse_Secret_Store $secret_store Secret store.
	 */
	public function __construct( Codegenie_Pulse_Options $options, Codegenie_Pulse_Reporter $reporter, Codegenie_Pulse_Secret_Store $secret_store ) {
		$this->options      = $options;
		$this->reporter     = $reporter;
		$this->secret_store = $secret_store;
	}

	/**
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_post_codegenie_pulse_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_codegenie_pulse_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_codegenie_pulse_disconnect', array( $this, 'handle_disconnect' ) );
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
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Codegenie Pulse Connector', 'codegenie-pulse-connector' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $this->secret_store->is_available() ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'OpenSSL ontbreekt. Activeer de PHP OpenSSL-extensie voordat je een DSN opslaat.', 'codegenie-pulse-connector' ); ?></p></div>
			<?php elseif ( $this->options->has_stored_dsn() && ! $this->options->has_readable_dsn() ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'De opgeslagen DSN kan niet meer worden ontsleuteld. Dit kan gebeuren nadat WordPress salts zijn gewijzigd. Plak de DSN opnieuw.', 'codegenie-pulse-connector' ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width: 100%;">
				<h2><?php echo esc_html__( 'Verbindingsstatus', 'codegenie-pulse-connector' ); ?></h2>
				<p><strong><?php echo esc_html( $status['label'] ); ?></strong></p>
				<p><?php echo esc_html( $status['description'] ); ?></p>
				<p><code><?php echo esc_html( $this->options->masked_dsn() ); ?></code></p>
				<?php if ( ! empty( $state['last_success_at'] ) ) : ?>
					<p><?php echo esc_html( sprintf( __( 'Laatste succesvolle levering: %s', 'codegenie-pulse-connector' ), $this->format_datetime( $state['last_success_at'] ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $state['last_failure_at'] ) && ! empty( $state['last_failure_message'] ) ) : ?>
					<p><?php echo esc_html( sprintf( __( 'Laatste probleem op %1$s: %2$s', 'codegenie-pulse-connector' ), $this->format_datetime( $state['last_failure_at'] ), $state['last_failure_message'] ) ); ?></p>
				<?php endif; ?>
			</div>

			<div class="card" style="max-width: 100%;">
				<h2><?php echo esc_html__( 'Koppelen in vier stappen', 'codegenie-pulse-connector' ); ?></h2>
				<ol>
					<li><?php echo esc_html__( 'Voeg je website toe in Codegenie Pulse en kopieer het websiteverificatietoken.', 'codegenie-pulse-connector' ); ?></li>
					<li><?php echo esc_html__( 'Plak het token hieronder, sla op en klik daarna in Codegenie Pulse op Verificatie controleren.', 'codegenie-pulse-connector' ); ?></li>
					<li><?php echo esc_html__( 'Open in Codegenie Pulse de foutinstellingen, maak een WordPress-productiebron aan en kopieer de volledige DSN.', 'codegenie-pulse-connector' ); ?></li>
					<li><?php echo esc_html__( 'Plak de DSN hieronder, sla op en verstuur een verbindingstest.', 'codegenie-pulse-connector' ); ?></li>
				</ol>
			</div>

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
						<th scope="row"><?php echo esc_html__( 'Automatische rapportage', 'codegenie-pulse-connector' ); ?></th>
						<td>
							<label><input type="checkbox" name="automatic_error_reporting" value="1" <?php checked( ! empty( $settings['automatic_error_reporting'] ) ); ?>> <?php echo esc_html__( 'Fatale PHP-fouten automatisch rapporteren', 'codegenie-pulse-connector' ); ?></label><br>
							<label><input type="checkbox" name="capture_mail_failures" value="1" <?php checked( ! empty( $settings['capture_mail_failures'] ) ); ?>> <?php echo esc_html__( 'Mislukte WordPress e-mails rapporteren zonder ontvangers of berichtinhoud', 'codegenie-pulse-connector' ); ?></label><br>
							<label><input type="checkbox" name="capture_rest_errors" value="1" <?php checked( ! empty( $settings['capture_rest_errors'] ) ); ?>> <?php echo esc_html__( 'REST API serverfouten met status 5xx rapporteren', 'codegenie-pulse-connector' ); ?></label>
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

			<?php if ( $this->options->has_readable_dsn() ) : ?>
				<hr>
				<h2><?php echo esc_html__( 'Verbinding beheren', 'codegenie-pulse-connector' ); ?></h2>
				<p><?php echo esc_html__( 'Een test verstuurt één info-event en telt daarom als één verwerkt event binnen je Pulse-plan.', 'codegenie-pulse-connector' ); ?></p>
				<div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="codegenie_pulse_test">
						<?php wp_nonce_field( 'codegenie_pulse_test' ); ?>
						<?php submit_button( __( 'Verbinding testen', 'codegenie-pulse-connector' ), 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'De Codegenie Pulse DSN verwijderen?', 'codegenie-pulse-connector' ) ); ?>');">
						<input type="hidden" name="action" value="codegenie_pulse_disconnect">
						<?php wp_nonce_field( 'codegenie_pulse_disconnect' ); ?>
						<?php submit_button( __( 'DSN verwijderen', 'codegenie-pulse-connector' ), 'delete', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width: 100%;">
				<h2><?php echo esc_html__( 'Privacy en veiligheid', 'codegenie-pulse-connector' ); ?></h2>
				<p><?php echo esc_html__( 'De connector verstuurt geen cookies, autorisatieheaders, formulierdata, request bodies, e-mailadressen of WordPress-gebruikers. URL-querystrings en bekende geheimen worden lokaal verwijderd. De connector volgt geen bezoekers en stuurt niets zolang er geen DSN is ingesteld.', 'codegenie-pulse-connector' ); ?></p>
			</div>
		</div>
		<?php
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
				'automatic_error_reporting' => isset( $_POST['automatic_error_reporting'] ),
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
		$this->set_notice( 'success', __( 'De Codegenie Pulse DSN is verwijderd.', 'codegenie-pulse-connector' ) );
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
					'value'   => $this->options->masked_dsn(),
					'private' => true,
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
		$good   = 'connected' === $status['code'];

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
	 * @return string
	 */
	private function settings_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @return void
	 */
	private function redirect_to_settings() {
		wp_safe_redirect( $this->settings_url() );
		exit;
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
}
