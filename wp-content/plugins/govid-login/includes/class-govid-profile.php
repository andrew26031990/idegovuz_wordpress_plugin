<?php
/**
 * Identity section on the user profile screen.
 *
 * @package govid-login
 */

defined( 'ABSPATH' ) || exit;

class Govid_Profile {

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save' ) );
	}

	/**
	 * @param WP_User $user User being edited (not necessarily the current user).
	 */
	public static function render( $user ) {
		$phone         = get_user_meta( $user->ID, Govid_Users::META_PHONE, true );
		$middle_name   = get_user_meta( $user->ID, Govid_Users::META_MIDDLE_NAME, true );
		$pin           = Govid_Users::get_pin( $user->ID );
		$verified      = (int) get_user_meta( $user->ID, Govid_Users::META_VERIFIED, true );
		$last_provider = Govid_Providers::get( (string) get_user_meta( $user->ID, Govid_Users::META_LAST_PROVIDER, true ) );
		$last_login    = (int) get_user_meta( $user->ID, Govid_Users::META_LAST_LOGIN, true );
		$is_admin      = current_user_can( 'edit_users' );
		?>
		<h2><?php esc_html_e( 'Госидентификация (OneID / MyID)', 'govid-login' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php if ( $middle_name ) : ?>
				<tr>
					<th><?php esc_html_e( 'Отчество', 'govid-login' ); ?></th>
					<td><?php echo esc_html( $middle_name ); ?></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><label for="govid_phone"><?php esc_html_e( 'Номер телефона', 'govid-login' ); ?></label></th>
				<td>
					<input type="tel" id="govid_phone" name="govid_phone" class="regular-text" value="<?php echo esc_attr( $phone ); ?>" />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Привязка', 'govid-login' ); ?></th>
				<td>
					<?php if ( $pin ) : ?>
						<?php
						printf(
							/* translators: %s: PINFL */
							esc_html__( 'ПИНФЛ: %s', 'govid-login' ),
							'<code>' . esc_html( $pin ) . '</code>'
						);
						if ( $verified ) {
							echo ' — ' . esc_html__( 'личность подтверждена', 'govid-login' );
						}
						?>
						<?php if ( $last_provider && $last_login ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: 1: provider name, 2: date */
									esc_html__( 'Последний вход: %1$s, %2$s', 'govid-login' ),
									esc_html( $last_provider->label() ),
									esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_login ) )
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( $is_admin ) : ?>
							<p>
								<label>
									<input type="checkbox" name="govid_unlink" value="1" />
									<?php esc_html_e( 'Отвязать ПИНФЛ от этой учётной записи', 'govid-login' ); ?>
								</label>
							</p>
						<?php endif; ?>
					<?php else : ?>
						<?php esc_html_e( 'Не привязан', 'govid-login' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<?php
			if ( $is_admin ) {
				self::render_extra_fields( $user->ID );
			}
			?>
		</table>
		<?php
	}

	/**
	 * Read-only list of values saved through the providers' "extra fields" settings.
	 */
	private static function render_extra_fields( $user_id ) {
		foreach ( Govid_Providers::all() as $provider ) {
			foreach ( $provider->extra_field_map() as $path => $meta_key ) {
				$value = get_user_meta( $user_id, $meta_key, true );
				if ( '' === $value || null === $value ) {
					continue;
				}
				?>
				<tr>
					<th><?php echo esc_html( $provider->label() . ': ' . $path ); ?></th>
					<td>
						<?php if ( is_array( $value ) ) : ?>
							<pre style="margin:0;white-space:pre-wrap"><?php echo esc_html( wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
						<?php else : ?>
							<?php echo esc_html( $value ); ?>
						<?php endif; ?>
						<p class="description"><code><?php echo esc_html( $meta_key ); ?></code></p>
					</td>
				</tr>
				<?php
			}
		}
	}

	/**
	 * Nonce is verified by core (user-edit.php / profile.php) before these hooks fire.
	 *
	 * @param int $user_id User being saved.
	 */
	public static function save( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_POST['govid_phone'] ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST['govid_phone'] ) );
			update_user_meta( $user_id, Govid_Users::META_PHONE, preg_replace( '/[^0-9+\-\s()]/', '', $phone ) );
		}

		if ( ! empty( $_POST['govid_unlink'] ) && current_user_can( 'edit_users' ) ) {
			Govid_Users::unlink( $user_id );
		}
		// phpcs:enable
	}
}
