<?php
/**
 * General settings and the admin page (Settings → Gov ID Login).
 *
 * The page has a "General" tab plus one tab per provider. Provider settings live
 * in the provider classes (see Govid_Provider::fields()).
 *
 * @package govid-login
 */

defined( 'ABSPATH' ) || exit;

class Govid_Settings {

	const OPTION = 'govid_settings';
	const PAGE   = 'govid-login';
	const GROUP  = 'govid_general';

	/** Capabilities that must never be granted to auto-registered users. */
	const DANGEROUS_CAPS = array(
		'manage_options',
		'manage_network',
		'edit_users',
		'create_users',
		'promote_users',
		'delete_users',
		'activate_plugins',
		'install_plugins',
		'edit_plugins',
		'edit_theme_options',
		'unfiltered_html',
	);

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GOVID_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function defaults() {
		return array(
			'allow_registration' => 1,
			'default_role'       => 'subscriber',
			'sync_profile'       => 1,
			'hide_password_form' => 0,
		);
	}

	public static function all() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/** @return mixed */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Roles that may be assigned to users registered through a provider.
	 *
	 * @return array slug => translated name
	 */
	public static function safe_roles() {
		$roles = array();

		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = isset( $role['capabilities'] ) ? (array) $role['capabilities'] : array();
			foreach ( self::DANGEROUS_CAPS as $cap ) {
				if ( ! empty( $caps[ $cap ] ) ) {
					continue 2;
				}
			}
			$roles[ $slug ] = translate_user_role( $role['name'] );
		}

		return $roles;
	}

	/**
	 * Role for new users, re-validated on every use.
	 */
	public static function default_role() {
		$safe = self::safe_roles();
		$role = (string) self::get( 'default_role' );

		if ( isset( $safe[ $role ] ) ) {
			return $role;
		}

		$site_default = (string) get_option( 'default_role' );
		return isset( $safe[ $site_default ] ) ? $site_default : '';
	}

	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		foreach ( Govid_Providers::all() as $provider ) {
			register_setting(
				$provider->option_name(),
				$provider->option_name(),
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $provider, 'sanitize_settings' ),
					'default'           => $provider->defaults(),
				)
			);
		}
	}

	/**
	 * @param mixed $input Raw form input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		foreach ( array( 'allow_registration', 'sync_profile', 'hide_password_form' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$role                = isset( $input['default_role'] ) ? sanitize_key( $input['default_role'] ) : '';
		$out['default_role'] = array_key_exists( $role, self::safe_roles() ) ? $role : 'subscriber';

		return $out;
	}

	public static function add_page() {
		add_options_page(
			__( 'Вход через госсервисы', 'govid-login' ),
			__( 'Gov ID Login', 'govid-login' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Настройки', 'govid-login' ) . '</a>' );
		return $links;
	}

	public static function page_url( $tab = '' ) {
		$args = array( 'page' => self::PAGE );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	// ------------------------------------------------------------------
	// Page
	// ------------------------------------------------------------------

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$providers = Govid_Providers::all();
		// phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( 'general' !== $tab && ! isset( $providers[ $tab ] ) ) {
			$tab = 'general';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Вход через госсервисы', 'govid-login' ); ?></h1>

			<?php if ( '' === (string) get_option( 'permalink_structure' ) ) : ?>
				<div class="notice notice-error"><p>
					<?php
					printf(
						/* translators: %s: link to permalink settings */
						esc_html__( 'Для работы адресов возврата нужны «красивые» постоянные ссылки. Включите их в разделе %s.', 'govid-login' ),
						'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Настройки → Постоянные ссылки', 'govid-login' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( self::page_url() ); ?>" class="nav-tab <?php echo 'general' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Общие', 'govid-login' ); ?></a>
				<?php foreach ( $providers as $id => $provider ) : ?>
					<a href="<?php echo esc_url( self::page_url( $id ) ); ?>" class="nav-tab <?php echo $id === $tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $provider->label() ); ?>
						<span class="govid-status" style="color:<?php echo $provider->is_active() ? '#00a32a' : '#a7aaad'; ?>">&#9679;</span>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'general' === $tab ) {
				self::render_general_tab( $providers );
			} else {
				self::render_provider_tab( $providers[ $tab ] );
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param Govid_Provider[] $providers Providers.
	 */
	private static function render_general_tab( array $providers ) {
		$options = self::all();
		?>
		<h2><?php esc_html_e( 'Способы входа', 'govid-login' ); ?></h2>
		<table class="widefat striped" style="max-width:720px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Сервис', 'govid-login' ); ?></th>
					<th><?php esc_html_e( 'Статус', 'govid-login' ); ?></th>
					<th><?php esc_html_e( 'Порядок', 'govid-login' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $providers as $id => $provider ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( self::page_url( $id ) ); ?>"><?php echo esc_html( $provider->label() ); ?></a></td>
						<td>
							<?php
							if ( $provider->is_active() ) {
								esc_html_e( 'Включён', 'govid-login' );
							} elseif ( $provider->get( 'enabled' ) ) {
								esc_html_e( 'Включён, но не настроен', 'govid-login' );
							} else {
								esc_html_e( 'Выключен', 'govid-login' );
							}
							?>
						</td>
						<td><?php echo (int) $provider->priority(); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="options.php">
			<?php settings_fields( self::GROUP ); ?>

			<h2><?php esc_html_e( 'Пользователи', 'govid-login' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				self::render_field_row(
					self::OPTION,
					'allow_registration',
					array(
						'type'  => 'checkbox',
						'label' => __( 'Регистрация', 'govid-login' ),
						'text'  => __( 'Создавать учётную запись при первом входе', 'govid-login' ),
					),
					$options['allow_registration']
				);
				self::render_field_row(
					self::OPTION,
					'default_role',
					array(
						'type'        => 'select',
						'label'       => __( 'Роль новых пользователей', 'govid-login' ),
						'options'     => self::safe_roles(),
						'description' => __( 'Роли с административными правами недоступны.', 'govid-login' ),
					),
					$options['default_role']
				);
				self::render_field_row(
					self::OPTION,
					'sync_profile',
					array(
						'type'  => 'checkbox',
						'label' => __( 'Синхронизация профиля', 'govid-login' ),
						'text'  => __( 'Обновлять ФИО, телефон и дополнительные поля при каждом входе (иначе — только при регистрации)', 'govid-login' ),
					),
					$options['sync_profile']
				);
				?>
			</table>

			<h2><?php esc_html_e( 'Страница входа', 'govid-login' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				self::render_field_row(
					self::OPTION,
					'hide_password_form',
					array(
						'type'  => 'checkbox',
						'label' => __( 'Форма входа', 'govid-login' ),
						'text'  => __( 'Скрыть вход по логину и паролю (останется ссылка «Войти с логином и паролем»)', 'govid-login' ),
					),
					$options['hide_password_form']
				);
				?>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Кнопки на сайте', 'govid-login' ); ?></h2>
		<p><?php esc_html_e( 'Кнопки включённых сервисов автоматически добавляются на страницу wp-login.php. Чтобы вывести их в другом месте, используйте шорткод:', 'govid-login' ); ?></p>
		<p><code>[govid_login]</code> &nbsp; <code>[govid_login providers="myid" redirect_to="/account/"]</code></p>
		<?php
	}

	private static function render_provider_tab( Govid_Provider $provider ) {
		$values = $provider->settings();
		$intro  = $provider->settings_intro();
		?>
		<?php if ( $intro ) : ?>
			<p style="max-width:860px"><?php echo wp_kses_post( $intro ); ?></p>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Redirect URI', 'govid-login' ); ?></th>
				<td>
					<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $provider->redirect_uri() ); ?>" onfocus="this.select();" />
					<p class="description"><?php esc_html_e( 'Сообщите этот адрес оператору сервиса при подключении.', 'govid-login' ); ?></p>
				</td>
			</tr>
		</table>

		<form method="post" action="options.php">
			<?php settings_fields( $provider->option_name() ); ?>
			<table class="form-table" role="presentation">
				<?php
				foreach ( $provider->fields() as $key => $field ) {
					$value = $values[ $key ];
					if ( $provider->is_constant( $key ) ) {
						$value                = $provider->get( $key );
						$field['locked']      = true;
						$field['description'] = sprintf(
							/* translators: %s: constant name */
							__( 'Задано в wp-config.php константой %s.', 'govid-login' ),
							$provider->constant_name( $key )
						);
					}
					self::render_field_row( $provider->option_name(), $key, $field, $value );

					if ( 'extra_fields' === $key ) {
						self::render_extra_fields_preview( $provider );
					}
				}
				?>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function render_extra_fields_preview( Govid_Provider $provider ) {
		$map = $provider->extra_field_map();
		if ( ! $map ) {
			return;
		}
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Будут сохраняться', 'govid-login' ); ?></th>
			<td>
				<?php foreach ( $map as $path => $meta_key ) : ?>
					<code><?php echo esc_html( $path ); ?></code> &rarr; <code><?php echo esc_html( $meta_key ); ?></code><br />
				<?php endforeach; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders one form-table row for a field definition (see Govid_Provider::fields()).
	 */
	public static function render_field_row( $option, $key, array $field, $value ) {
		$id          = 'govid_' . md5( $option ) . '_' . $key;
		$name        = $option . '[' . $key . ']';
		$type        = isset( $field['type'] ) ? $field['type'] : 'text';
		$locked      = ! empty( $field['locked'] );
		$description = isset( $field['description'] ) ? $field['description'] : '';
		?>
		<tr>
			<th scope="row">
				<?php if ( 'checkbox' === $type ) : ?>
					<?php echo esc_html( $field['label'] ); ?>
				<?php else : ?>
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
				<?php endif; ?>
			</th>
			<td>
				<?php
				switch ( $type ) {
					case 'checkbox':
						?>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (int) $value, 1 ); ?> />
							<?php echo esc_html( isset( $field['text'] ) ? $field['text'] : '' ); ?>
						</label>
						<?php
						break;

					case 'secret':
						?>
						<input type="password" id="<?php echo esc_attr( $id ); ?>" class="regular-text" autocomplete="new-password"
							<?php if ( $locked ) : ?>
								value="********" disabled
							<?php else : ?>
								name="<?php echo esc_attr( $name ); ?>" value=""
								placeholder="<?php echo $value ? esc_attr__( 'Сохранён. Оставьте пустым, чтобы не менять.', 'govid-login' ) : ''; ?>"
							<?php endif; ?>
						/>
						<?php
						if ( ! $locked && '' === $description ) {
							$description = __( 'Надёжнее задать секрет в wp-config.php константой.', 'govid-login' );
						}
						break;

					case 'number':
						?>
						<input type="number" id="<?php echo esc_attr( $id ); ?>" class="small-text" name="<?php echo esc_attr( $name ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
							<?php echo isset( $field['min'] ) ? 'min="' . esc_attr( $field['min'] ) . '"' : ''; ?>
							<?php echo isset( $field['max'] ) ? 'max="' . esc_attr( $field['max'] ) . '"' : ''; ?> />
						<?php
						break;

					case 'select':
						?>
						<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
							<?php foreach ( $field['options'] as $option_value => $option_label ) : ?>
								<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $value, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php
						break;

					case 'textarea':
						?>
						<textarea id="<?php echo esc_attr( $id ); ?>" class="large-text code" rows="3" name="<?php echo esc_attr( $name ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
						<?php
						break;

					default:
						?>
						<input type="text" id="<?php echo esc_attr( $id ); ?>" class="regular-text"
							<?php if ( $locked ) : ?>
								value="<?php echo esc_attr( $value ); ?>" disabled
							<?php else : ?>
								name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"
							<?php endif; ?>
						/>
						<?php
				}

				if ( '' !== $description ) {
					echo '<p class="description">' . esc_html( $description ) . '</p>';
				}
				?>
			</td>
		</tr>
		<?php
	}
}
