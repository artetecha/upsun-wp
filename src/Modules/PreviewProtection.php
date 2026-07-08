<?php
/**
 * Keep non-production environments out of search engines.
 *
 * Preview environments hold a clone of the production database, so writing
 * the blog_public option would be misleading (and could sync back in a
 * merge). Headers and robots meta are emitted at request time instead.
 */

namespace Upsun\Modules;

use Upsun\Environment;
use Upsun\Module;

class PreviewProtection implements Module {

	public function should_load(): bool {
		return ! Environment::is_production();
	}

	public function register(): void {
		add_filter( 'wp_robots', array( $this, 'add_noindex' ), 99 );
		add_action( 'send_headers', array( $this, 'send_robots_header' ) );
	}

	public function add_noindex( array $robots ): array {
		if ( ! $this->enabled() ) {
			return $robots;
		}

		$robots['noindex']  = true;
		$robots['nofollow'] = true;

		return $robots;
	}

	public function send_robots_header(): void {
		if ( ! $this->enabled() || headers_sent() ) {
			return;
		}

		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	private function enabled(): bool {
		/**
		 * Filters whether non-production environments send noindex. Disable
		 * for a real staging domain that should be indexable.
		 *
		 * @param bool $noindex Default true.
		 */
		return (bool) apply_filters( 'upsun_preview_noindex', true );
	}
}
