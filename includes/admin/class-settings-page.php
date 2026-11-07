<?php
/**
 * Settings → Traduzioni: languages, root pages, general options.
 *
 * All writes go through admin-post.php with a nonce and the manage_options capability.
 *
 * @package EPT
 */

namespace EPT\Admin;

use EPT\Language_Rules;
use EPT\Languages;
use EPT\Root_Pages;
use EPT\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page.
 */
final class Settings_Page {

	public const SLUG = 'ept-settings';
	public const CAP  = 'manage_options';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_ept_add_language', array( self::class, 'handle_add_language' ) );
		add_action( 'admin_post_ept_save_languages', array( self::class, 'handle_save_languages' ) );
		add_action( 'admin_post_ept_delete_language', array( self::class, 'handle_delete_language' ) );
		add_action( 'admin_post_ept_install_language_pack', array( self::class, 'handle_install_language_pack' ) );
		add_action( 'admin_post_ept_save_general', array( self::class, 'handle_save_general' ) );
		add_action( 'admin_post_ept_save_deepl', array( self::class, 'handle_save_deepl' ) );
		add_action( 'admin_post_ept_save_identity', array( self::class, 'handle_save_identity' ) );
		add_action( 'admin_post_ept_save_complianz', array( self::class, 'handle_save_complianz' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EPT_FILE ), array( self::class, 'plugin_links' ) );
	}

	/**
	 * Menu entry.
	 */
	public static function menu(): void {
		add_options_page(
			__( 'Traduzioni', 'ept' ),
			__( 'Traduzioni', 'ept' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * "Impostazioni" link in the plugins list.
	 *
	 * @param array<string,string> $links Links.
	 * @return array<string,string>
	 */
	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Impostazioni', 'ept' ) . '</a>' );
		return $links;
	}

	/**
	 * Page URL.
	 */
	public static function url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Non hai i permessi per accedere a questa pagina.', 'ept' ) );
		}
		$default   = Languages::default_language();
		$languages = Languages::all();
		$locales   = Languages::available_locales();
		$general   = Settings::all();
		?>
		<div class="wrap ept-settings">
			<h1><?php esc_html_e( 'Traduzioni', 'ept' ); ?></h1>

			<?php if ( '' === (string) get_option( 'permalink_structure' ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'I permalink del sito sono disattivati (Impostazioni → Permalink: "Semplice"). Le traduzioni funzionano, ma gli indirizzi sono del tipo ?page_id=123 invece di /en/nome-pagina/. Per avere gli indirizzi per lingua scegli, per esempio, "Nome articolo" in Impostazioni → Permalink.', 'ept' ); ?>
					<a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>"><?php esc_html_e( 'Apri le impostazioni dei permalink', 'ept' ); ?></a>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Lingua predefinita', 'ept' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: language name, 2: code, 3: locale. */
					esc_html__( '%1$s (codice %2$s, locale %3$s). Le pagine in questa lingua restano sugli URL attuali, senza prefisso.', 'ept' ),
					'<strong>' . esc_html( $default['name'] ) . '</strong>',
					'<code>' . esc_html( $default['code'] ) . '</code>',
					'<code>' . esc_html( $default['locale'] ) . '</code>'
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Lingue aggiuntive', 'ept' ); ?></h2>
			<?php if ( ! $languages ) : ?>
				<p><?php esc_html_e( 'Nessuna lingua aggiuntiva configurata.', 'ept' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ept_save_languages">
					<?php wp_nonce_field( 'ept_save_languages' ); ?>
					<table class="widefat striped ept-languages">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Nome nativo', 'ept' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Codice', 'ept' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Locale', 'ept' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Prefisso URL', 'ept' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Attiva', 'ept' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Pubblica', 'ept' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Ordine', 'ept' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Pagina radice', 'ept' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Pacchetto lingua WordPress', 'ept' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Azioni', 'ept' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $languages as $code => $lang ) : ?>
							<?php $field = 'languages[' . $code . ']'; ?>
							<tr>
								<td><input type="text" class="regular-text" style="width:12em" name="<?php echo esc_attr( $field ); ?>[name]" value="<?php echo esc_attr( $lang['name'] ); ?>" aria-label="<?php esc_attr_e( 'Nome nativo', 'ept' ); ?>"></td>
								<td><code><?php echo esc_html( $code ); ?></code></td>
								<td><code><?php echo esc_html( $lang['locale'] ); ?></code></td>
								<td>/<input type="text" style="width:6em" name="<?php echo esc_attr( $field ); ?>[prefix]" value="<?php echo esc_attr( $lang['prefix'] ); ?>" aria-label="<?php esc_attr_e( 'Prefisso URL', 'ept' ); ?>">/</td>
								<td><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[active]" value="1" <?php checked( ! empty( $lang['active'] ) ); ?> aria-label="<?php esc_attr_e( 'Attiva', 'ept' ); ?>"></td>
								<td><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[public]" value="1" <?php checked( ! empty( $lang['public'] ) ); ?> aria-label="<?php esc_attr_e( 'Pubblica', 'ept' ); ?>"></td>
								<td><input type="number" style="width:5em" name="<?php echo esc_attr( $field ); ?>[order]" value="<?php echo esc_attr( (string) (int) $lang['order'] ); ?>" aria-label="<?php esc_attr_e( 'Ordine', 'ept' ); ?>"></td>
								<td><?php self::render_root_page_cell( $lang ); ?></td>
								<td><?php self::render_language_pack_cell( (string) $lang['locale'] ); ?></td>
								<td>
									<?php if ( empty( $lang['active'] ) ) : ?>
										<a class="button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ept_delete_language&code=' . rawurlencode( $code ) ), 'ept_delete_language_' . $code ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Rimuovere questa lingua dalla configurazione? Le pagine non vengono eliminate.', 'ept' ) ); ?>');"><?php esc_html_e( 'Rimuovi', 'ept' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description">
						<?php esc_html_e( '"Attiva": la lingua si può tradurre e ha la sua pagina radice. "Pubblica": le pagine sono visibili ai visitatori, nel selettore, negli hreflang e nella sitemap. Una lingua attiva ma non pubblica è visibile solo agli utenti connessi con permesso di modifica.', 'ept' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'Cambiare il prefisso di una lingua attiva cambia lo slug della sua pagina radice, e quindi l\'URL di tutte le pagine di quella lingua.', 'ept' ); ?>
					</p>
					<?php submit_button( __( 'Salva lingue', 'ept' ) ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Aggiungi lingua', 'ept' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ept_add_language">
				<?php wp_nonce_field( 'ept_add_language' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ept-locale"><?php esc_html_e( 'Lingua (locale WordPress)', 'ept' ); ?></label></th>
						<td>
							<select id="ept-locale" name="locale" required>
								<option value=""><?php esc_html_e( '— Scegli —', 'ept' ); ?></option>
								<?php foreach ( $locales as $locale => $native ) : ?>
									<?php
									if ( $locale === $default['locale'] ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $locale ); ?>"><?php echo esc_html( $native . ' — ' . $locale ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Per l\'inglese scegli en_GB (britannico) o en_US (americano). Le lingue scritte da destra a sinistra non sono disponibili.', 'ept' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ept-code"><?php esc_html_e( 'Codice', 'ept' ); ?></label></th>
						<td><input id="ept-code" type="text" name="code" style="width:8em" pattern="[a-z]{2,3}(-[a-z0-9]{2,8})?">
							<p class="description"><?php esc_html_e( 'Facoltativo: se vuoto si ricava dal locale (en_GB → en).', 'ept' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ept-name"><?php esc_html_e( 'Nome nativo', 'ept' ); ?></label></th>
						<td><input id="ept-name" type="text" name="name" class="regular-text">
							<p class="description"><?php esc_html_e( 'Facoltativo: se vuoto si usa il nome fornito da WordPress (es. "English (UK)"). Consigliato un nome breve, es. "English".', 'ept' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ept-prefix"><?php esc_html_e( 'Prefisso URL', 'ept' ); ?></label></th>
						<td>/<input id="ept-prefix" type="text" name="prefix" style="width:8em">/
							<p class="description"><?php esc_html_e( 'Facoltativo: se vuoto è uguale al codice.', 'ept' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Opzioni', 'ept' ); ?></th>
						<td>
							<label><input type="checkbox" name="active" value="1" checked> <?php esc_html_e( 'Attiva subito (crea la pagina radice in bozza)', 'ept' ); ?></label><br>
							<label><input type="checkbox" name="language_pack" value="1"> <?php esc_html_e( 'Scarica il pacchetto lingua di WordPress (testi di tema e plugin tradotti)', 'ept' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Aggiungi lingua', 'ept' ), 'secondary' ); ?>
			</form>

			<h2><?php esc_html_e( 'Proposte automatiche (DeepL)', 'ept' ); ?></h2>
			<?php self::render_deepl(); ?>

			<?php self::render_identity(); ?>

			<?php self::render_complianz(); ?>

			<h2><?php esc_html_e( 'Generali', 'ept' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ept_save_general">
				<?php wp_nonce_field( 'ept_save_general' ); ?>
				<p>
					<label for="ept-not-found"><strong><?php esc_html_e( 'Pagina 404', 'ept' ); ?></strong></label><br>
					<select id="ept-not-found" name="not_found_page">
						<option value="0"><?php esc_html_e( '— Pagina 404 del tema —', 'ept' ); ?></option>
						<?php foreach ( self::not_found_candidates() as $candidate ) : ?>
							<option value="<?php echo esc_attr( (string) $candidate->ID ); ?>" <?php selected( (int) ( $general['not_found_page'] ?? 0 ), $candidate->ID ); ?>><?php echo esc_html( '' !== $candidate->post_title ? $candidate->post_title : '#' . $candidate->ID ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'Facoltativo: una pagina Elementor italiana mostrata al posto della pagina "non trovata" del tema (il codice resta 404). Traducila come le altre: in ogni lingua viene mostrata la sua versione; se manca, si usa la 404 del tema.', 'ept' ); ?></span>
				</p>
				<label>
					<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $general['delete_data_on_uninstall'] ) ); ?>>
					<?php esc_html_e( 'Alla disinstallazione elimina i dati del plugin (impostazioni, collegamenti tra traduzioni, memoria traduzioni). Le pagine tradotte non vengono mai eliminate.', 'ept' ); ?>
				</label>
				<?php submit_button( __( 'Salva', 'ept' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * DeepL section (optional): switch, key (never shown in full), usage counter.
	 */
	private static function render_deepl(): void {
		$key     = (string) Settings::get( 'deepl_key' );
		$enabled = ! empty( Settings::get( 'deepl_enabled' ) );
		$usage   = \EPT\Proposals::usage_summary();
		$api     = $enabled && '' !== $key ? \EPT\Proposals::api_usage() : null;
		?>
		<p><?php esc_html_e( 'Facoltativo. Senza DeepL la schermata di traduzione funziona lo stesso: a mano, con la memoria traduzioni e con i pulsanti "Copia testi da tradurre" / "Incolla traduzioni" (Google Traduttore o altro traduttore gratuito sul web, senza account).', 'ept' ); ?></p>
		<p class="description"><?php esc_html_e( 'Con una chiave DeepL API i campi arrivano già compilati da proposte da rivedere. Dal 2026 i nuovi iscritti trovano il piano gratuito "Developer" (1 milione di caratteri una tantum) o piani a pagamento: verifica le condizioni su deepl.com prima di iscriverti.', 'ept' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ept_save_deepl">
			<?php wp_nonce_field( 'ept_save_deepl' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Proposte DeepL', 'ept' ); ?></th>
					<td><label><input type="checkbox" name="deepl_enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Attiva le proposte automatiche con DeepL', 'ept' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="ept-deepl-key"><?php esc_html_e( 'Chiave API DeepL', 'ept' ); ?></label></th>
					<td>
						<input id="ept-deepl-key" type="password" name="deepl_key" class="regular-text" autocomplete="off" value="" placeholder="<?php echo esc_attr( '' !== $key ? \EPT\Deepl::mask( $key ) : __( 'Incolla qui la chiave', 'ept' ) ); ?>">
						<p class="description"><?php esc_html_e( 'La chiave non viene mai mostrata per intero. Lascia il campo vuoto per mantenere quella attuale. L\'indirizzo del servizio (piano gratuito o a pagamento) viene riconosciuto alla verifica.', 'ept' ); ?></p>
						<?php if ( '' !== $key ) : ?>
							<label><input type="checkbox" name="deepl_remove" value="1"> <?php esc_html_e( 'Rimuovi la chiave', 'ept' ); ?></label>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $enabled || $usage['site_month'] > 0 ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Consumo', 'ept' ); ?></th>
					<td>
						<?php
						/* translators: %s: number of characters. */
						echo esc_html( sprintf( __( 'Caratteri inviati da questo sito nel mese: %s.', 'ept' ), number_format_i18n( $usage['site_month'] ) ) );
						if ( is_array( $api ) ) {
							echo ' ';
							/* translators: 1: characters used, 2: limit. */
							echo esc_html( sprintf( __( 'Account DeepL: %1$s su %2$s caratteri.', 'ept' ), number_format_i18n( $api['count'] ), number_format_i18n( $api['limit'] ) ) );
						} elseif ( is_wp_error( $api ) ) {
							echo ' <span style="color:#8a2424">' . esc_html( $api->get_error_message() ) . '</span>';
						}
						if ( '' !== $usage['warning'] ) {
							echo '<p style="color:#8a2424">' . esc_html( $usage['warning'] ) . '</p>';
						}
						?>
					</td>
				</tr>
				<?php endif; ?>
			</table>
			<?php submit_button( __( 'Salva', 'ept' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Save the DeepL switch and key; verify the key and detect its API address.
	 */
	public static function handle_save_deepl(): void {
		self::guard( 'ept_save_deepl' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$enabled = ! empty( $_POST['deepl_enabled'] );
		$remove  = ! empty( $_POST['deepl_remove'] );
		$key     = isset( $_POST['deepl_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['deepl_key'] ) ) ) : '';
		// phpcs:enable
		delete_transient( \EPT\Proposals::USAGE_CACHE );
		if ( $remove ) {
			Settings::update(
				array(
					'deepl_key'      => '',
					'deepl_endpoint' => '',
					'deepl_enabled'  => false,
				)
			);
			Notices::add( 'success', __( 'Chiave DeepL rimossa: proposte DeepL disattivate.', 'ept' ) );
			self::back();
		}
		$values = array( 'deepl_enabled' => $enabled );
		if ( '' !== $key ) {
			$values['deepl_key']      = $key;
			$values['deepl_endpoint'] = '';
		}
		Settings::update( $values );

		$current = (string) Settings::get( 'deepl_key' );
		if ( ! $enabled ) {
			Notices::add( 'success', __( 'Impostazioni salvate. Proposte DeepL disattivate.', 'ept' ) );
			self::back();
		}
		if ( '' === $current ) {
			Notices::add( 'warning', __( 'Proposte DeepL attivate, ma manca la chiave: finché non la inserisci non vengono usate.', 'ept' ) );
			self::back();
		}
		$found = \EPT\Deepl::detect( $current );
		if ( is_wp_error( $found ) ) {
			Notices::add( 'error', $found->get_error_message() );
		} else {
			Settings::update( array( 'deepl_endpoint' => $found['base'] ) );
			set_transient( \EPT\Proposals::USAGE_CACHE, $found['usage'], 10 * MINUTE_IN_SECONDS );
			Notices::add( 'success', __( 'Chiave DeepL valida: proposte attive.', 'ept' ) );
		}
		self::back();
	}

	/**
	 * Root page cell: title, status and edit link.
	 *
	 * @param array<string,mixed> $lang Language.
	 */
	private static function render_root_page_cell( array $lang ): void {
		$page = $lang['root_page_id'] ? get_post( (int) $lang['root_page_id'] ) : null;
		if ( ! $page ) {
			echo '<em>' . esc_html__( 'Non ancora creata (si crea attivando la lingua)', 'ept' ) . '</em>';
			return;
		}
		$status = get_post_status_object( $page->post_status );
		printf(
			'<a href="%1$s">/%2$s/</a> <span class="description">(%3$s)</span>',
			esc_url( (string) get_edit_post_link( $page->ID ) ),
			esc_html( $page->post_name ),
			esc_html( $status ? $status->label : $page->post_status )
		);
	}

	/**
	 * Language pack cell.
	 *
	 * @param string $locale Locale.
	 */
	private static function render_language_pack_cell( string $locale ): void {
		if ( Languages::is_language_pack_installed( $locale ) ) {
			echo esc_html__( 'Installato', 'ept' );
			return;
		}
		printf(
			'<a class="button button-small" href="%1$s">%2$s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ept_install_language_pack&locale=' . rawurlencode( $locale ) ), 'ept_install_language_pack_' . $locale ) ),
			esc_html__( 'Scarica', 'ept' )
		);
	}

	/**
	 * Common permission + nonce check.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Non hai i permessi per eseguire questa operazione.', 'ept' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirect back to the settings page.
	 */
	private static function back(): void {
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Add a language.
	 */
	public static function handle_add_language(): void {
		self::guard( 'ept_add_language' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$locale = sanitize_text_field( wp_unslash( $_POST['locale'] ?? '' ) );
		$code   = sanitize_key( wp_unslash( $_POST['code'] ?? '' ) );
		$name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$prefix = sanitize_title( wp_unslash( $_POST['prefix'] ?? '' ) );
		$active = ! empty( $_POST['active'] );
		$pack   = ! empty( $_POST['language_pack'] );
		// phpcs:enable

		$input = array(
			'locale' => $locale,
			'code'   => '' !== $code ? $code : Language_Rules::code_from_locale( $locale ),
			'name'   => '' !== $name ? $name : Languages::native_name( $locale ),
			'prefix' => $prefix,
			'active' => false,
			'public' => false,
			'order'  => count( Languages::all() ) + 1,
		);

		list( $entry, $errors ) = Language_Rules::normalize( $input, Languages::all(), Languages::default_code() );
		if ( Languages::default_language()['locale'] === $locale ) {
			$errors[] = 'code_is_default';
		}
		if ( $errors ) {
			foreach ( array_unique( $errors ) as $error ) {
				Notices::add( 'error', Languages::error_message( $error ) );
			}
			self::back();
		}

		Languages::put( $entry );
		Notices::add(
			'success',
			/* translators: %s: language name. */
			sprintf( __( 'Lingua %s aggiunta.', 'ept' ), $entry['name'] )
		);

		if ( $active ) {
			self::activate_language( $entry );
		}
		if ( $pack ) {
			self::install_language_pack( $entry['locale'] );
		}
		self::back();
	}

	/**
	 * Save the languages table.
	 */
	public static function handle_save_languages(): void {
		self::guard( 'ept_save_languages' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); each field is sanitised below.
		$rows = isset( $_POST['languages'] ) && is_array( $_POST['languages'] ) ? wp_unslash( $_POST['languages'] ) : array();

		foreach ( Languages::all() as $code => $old ) {
			$row = isset( $rows[ $code ] ) && is_array( $rows[ $code ] ) ? $rows[ $code ] : array();

			$input = array_merge(
				$old,
				array(
					'name'   => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
					'prefix' => sanitize_title( (string) ( $row['prefix'] ?? '' ) ),
					'active' => ! empty( $row['active'] ),
					'public' => ! empty( $row['public'] ),
					'order'  => (int) ( $row['order'] ?? 0 ),
				)
			);

			$others = Languages::all();
			unset( $others[ $code ] );
			list( $entry, $errors ) = Language_Rules::normalize( $input, $others, Languages::default_code() );
			if ( $errors ) {
				foreach ( array_unique( $errors ) as $error ) {
					/* translators: 1: language name, 2: error message. */
					Notices::add( 'error', sprintf( __( '%1$s: %2$s', 'ept' ), $old['name'], Languages::error_message( $error ) ) );
				}
				continue;
			}

			$was_active     = ! empty( $old['active'] );
			$prefix_changed = $entry['prefix'] !== $old['prefix'];

			if ( $entry['active'] && ( ! $was_active || $prefix_changed ) ) {
				// Restores the previous entry by itself if the root page cannot be set up.
				self::activate_language( $entry );
			} else {
				Languages::put( $entry );
			}
		}

		Notices::add( 'success', __( 'Lingue salvate.', 'ept' ) );
		self::back();
	}

	/**
	 * Activate a language (or apply a new prefix) and make sure its root page exists.
	 * On failure the previous configuration is kept.
	 *
	 * @param array<string,mixed> $entry Desired entry, with active = true.
	 */
	private static function activate_language( array $entry ): bool {
		$previous        = Languages::get( (string) $entry['code'] );
		$entry['active'] = true;
		Languages::put( $entry );

		$result = Root_Pages::ensure( (string) $entry['code'] );
		if ( is_wp_error( $result ) ) {
			if ( null !== $previous ) {
				$previous['root_page_id'] = Languages::get( (string) $entry['code'] )['root_page_id'];
				Languages::put( $previous );
			}
			Notices::add(
				'error',
				/* translators: 1: language name, 2: error message. */
				sprintf( __( 'Impossibile attivare %1$s: %2$s', 'ept' ), $entry['name'], $result->get_error_message() )
			);
			return false;
		}
		return true;
	}

	/**
	 * Remove an inactive language from the configuration.
	 */
	public static function handle_delete_language(): void {
		$code = sanitize_key( wp_unslash( $_GET['code'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in guard().
		self::guard( 'ept_delete_language_' . $code );

		$lang = Languages::get( $code );
		if ( null === $lang ) {
			self::back();
		}
		if ( ! empty( $lang['active'] ) ) {
			Notices::add( 'error', __( 'Disattiva la lingua prima di rimuoverla.', 'ept' ) );
			self::back();
		}
		Languages::remove( $code );
		Notices::add(
			'success',
			/* translators: %s: language name. */
			sprintf( __( 'Lingua %s rimossa dalla configurazione. Le sue pagine, compresa la pagina radice, non sono state eliminate.', 'ept' ), $lang['name'] )
		);
		self::back();
	}

	/**
	 * Download the WordPress language pack of a configured locale.
	 */
	public static function handle_install_language_pack(): void {
		$locale = sanitize_text_field( wp_unslash( $_GET['locale'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in guard().
		self::guard( 'ept_install_language_pack_' . $locale );
		$known = array_column( Languages::all(), 'locale' );
		if ( in_array( $locale, $known, true ) ) {
			self::install_language_pack( $locale );
		}
		self::back();
	}

	/**
	 * Install a language pack and report the outcome.
	 *
	 * @param string $locale Locale.
	 */
	private static function install_language_pack( string $locale ): void {
		if ( Languages::is_language_pack_installed( $locale ) ) {
			return;
		}
		if ( ! current_user_can( 'install_languages' ) ) {
			Notices::add( 'error', __( 'Non hai il permesso di installare pacchetti lingua.', 'ept' ) );
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			Notices::add( 'error', __( 'WordPress non può scrivere i file della lingua (accesso al filesystem non disponibile).', 'ept' ) );
			return;
		}
		$result = wp_download_language_pack( $locale );
		if ( $result ) {
			/* translators: %s: locale. */
			Notices::add( 'success', sprintf( __( 'Pacchetto lingua %s installato.', 'ept' ), $locale ) );
		} else {
			/* translators: %s: locale. */
			Notices::add( 'error', sprintf( __( 'Download del pacchetto lingua %s non riuscito.', 'ept' ), $locale ) );
		}
	}

	/**
	 * Save general settings.
	 */
	public static function handle_save_general(): void {
		self::guard( 'ept_save_general' );
		Settings::update(
			array(
				'delete_data_on_uninstall' => ! empty( $_POST['delete_data_on_uninstall'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
				'not_found_page'           => self::valid_not_found( isset( $_POST['not_found_page'] ) ? absint( $_POST['not_found_page'] ) : 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
			)
		);
		Notices::add( 'success', __( 'Impostazioni salvate.', 'ept' ) );
		self::back();
	}

	/**
	 * Italian Elementor pages usable as 404 page.
	 *
	 * @return list<\WP_Post>
	 */
	private static function not_found_candidates(): array {
		$pages = get_pages(
			array(
				'post_status' => 'publish,draft,private',
				'sort_column' => 'post_title',
			)
		);
		return array_values(
			array_filter(
				is_array( $pages ) ? $pages : array(),
				static fn( $page ): bool => \EPT\Translations::is_source( (int) $page->ID )
					&& null === \EPT\Root_Pages::root_language( (int) $page->ID )
					&& 'builder' === get_post_meta( (int) $page->ID, '_elementor_edit_mode', true )
			)
		);
	}

	/**
	 * Validated 404 page ID (0 when not a candidate).
	 *
	 * @param int $id Page ID.
	 */
	private static function valid_not_found( int $id ): int {
		foreach ( self::not_found_candidates() as $candidate ) {
			if ( (int) $candidate->ID === $id ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Site name and tagline per language.
	 */
	private static function render_identity(): void {
		$languages = Languages::all();
		if ( ! $languages ) {
			return;
		}
		$values = \EPT\Site_Identity::all();
		$name   = (string) get_option( 'blogname' );
		$motto  = (string) get_option( 'blogdescription' );
		?>
		<h2><?php esc_html_e( 'Nome e motto del sito per lingua', 'ept' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Compaiono nel titolo della scheda del browser, nei risultati di Google, nell\'Open Graph e nei widget UAE "Site Title" e "Site Tagline". Il nome di solito resta quello dell\'azienda; il motto si traduce. Se un campo è vuoto si usa quello italiano.', 'ept' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ept_save_identity">
			<?php wp_nonce_field( 'ept_save_identity' ); ?>
			<table class="widefat striped" style="max-width:900px">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Lingua', 'ept' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Nome del sito', 'ept' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Motto', 'ept' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $languages as $code => $lang ) : ?>
						<?php $code = (string) $code; ?>
						<tr>
							<th scope="row"><?php echo esc_html( (string) $lang['name'] ); ?></th>
							<td><input type="text" class="regular-text" aria-label="<?php /* translators: %s: language name. */ echo esc_attr( sprintf( __( 'Nome del sito in %s', 'ept' ), $lang['name'] ) ); ?>" name="identity[<?php echo esc_attr( $code ); ?>][name]" value="<?php echo esc_attr( $values[ $code ]['name'] ?? '' ); ?>" placeholder="<?php echo esc_attr( $name ); ?>"></td>
							<td><input type="text" class="regular-text" aria-label="<?php /* translators: %s: language name. */ echo esc_attr( sprintf( __( 'Motto in %s', 'ept' ), $lang['name'] ) ); ?>" name="identity[<?php echo esc_attr( $code ); ?>][tagline]" value="<?php echo esc_attr( $values[ $code ]['tagline'] ?? '' ); ?>" placeholder="<?php echo esc_attr( $motto ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Salva nome e motto', 'ept' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Save site name and tagline per language.
	 */
	public static function handle_save_identity(): void {
		self::guard( 'ept_save_identity' );
		$posted = isset( $_POST['identity'] ) && is_array( $_POST['identity'] ) ? wp_unslash( $_POST['identity'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); sanitised in Site_Identity::save().
		\EPT\Site_Identity::save( (array) $posted );
		Notices::add( 'success', __( 'Nome e motto salvati.', 'ept' ) );
		self::back();
	}

	/**
	 * English texts of the Complianz cookie banner (only when Complianz is active).
	 */
	private static function render_complianz(): void {
		if ( ! \EPT\Complianz::active() ) {
			return;
		}
		$strings = \EPT\Complianz::strings();
		$english = \EPT\Complianz::translations();
		?>
		<h2><?php esc_html_e( 'Banner dei cookie (Complianz)', 'ept' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Il banner ha due versioni: italiano sulle pagine italiane, inglese su tutte le altre lingue. Scrivi qui i testi inglesi; quelli vuoti restano in italiano. Le etichette fisse di Complianz (es. "Accetta") seguono già la lingua della pagina, e i link alla cookie policy portano alla sua traduzione.', 'ept' ); ?></p>
		<?php if ( ! $strings ) : ?>
			<p><?php esc_html_e( 'Nessun testo del banner ancora rilevato: apri una pagina italiana del sito (con il banner visibile), poi torna qui.', 'ept' ); ?></p>
			<?php
			return;
		endif;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ept_save_complianz">
			<?php wp_nonce_field( 'ept_save_complianz' ); ?>
			<table class="widefat striped" style="max-width:1100px">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Testo italiano', 'ept' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Testo inglese', 'ept' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $strings as $name => $text ) : ?>
						<tr>
							<td style="width:50%"><div><?php echo wp_kses_post( $text ); ?></div><code style="font-size:11px"><?php echo esc_html( (string) $name ); ?></code></td>
							<td><textarea class="large-text" rows="<?php echo esc_attr( (string) min( 6, max( 1, (int) ceil( strlen( $text ) / 70 ) ) ) ); ?>" aria-label="<?php /* translators: %s: string name. */ echo esc_attr( sprintf( __( 'Testo inglese di %s', 'ept' ), $name ) ); ?>" name="complianz[<?php echo esc_attr( (string) $name ); ?>]"><?php echo esc_textarea( $english[ $name ] ?? '' ); ?></textarea></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Salva testi del banner', 'ept' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Save the English texts of the Complianz banner.
	 */
	public static function handle_save_complianz(): void {
		self::guard( 'ept_save_complianz' );
		$posted = isset( $_POST['complianz'] ) && is_array( $_POST['complianz'] ) ? wp_unslash( $_POST['complianz'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); sanitised in Complianz::save().
		\EPT\Complianz::save( (array) $posted );
		Notices::add( 'success', __( 'Testi del banner salvati.', 'ept' ) );
		self::back();
	}
}
