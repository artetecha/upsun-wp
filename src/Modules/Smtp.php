<?php
/**
 * Route wp_mail() through the Upsun on-platform SMTP relay
 * (PLATFORM_SMTP_HOST, port 25, no auth). A dedicated mailer plugin that has
 * already switched PHPMailer to SMTP wins; disable entirely with the
 * upsun_configure_smtp filter.
 */

namespace Upsun\Modules;

use Upsun\Environment;
use Upsun\Module;

class Smtp implements Module {

	public function should_load(): bool {
		return null !== Environment::smtp_host();
	}

	public function register(): void {
		// Priority 1: run before mailer plugins so they can override us.
		add_action( 'phpmailer_init', array( $this, 'configure' ), 1 );
	}

	/**
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public function configure( $phpmailer ): void {
		/**
		 * Filters whether the plugin wires PHPMailer to the Upsun relay.
		 *
		 * @param bool $configure Default true.
		 */
		if ( ! apply_filters( 'upsun_configure_smtp', true ) ) {
			return;
		}

		$host = Environment::smtp_host();

		if ( null === $host || 'smtp' === $phpmailer->Mailer ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host        = $host;
		$phpmailer->Port        = 25;
		$phpmailer->SMTPAuth    = false;
		$phpmailer->SMTPSecure  = '';
		$phpmailer->SMTPAutoTLS = false;
	}
}
