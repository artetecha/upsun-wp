<?php
/**
 * Wordfence writes its WAF configuration and logs to wp-content/wflogs.
 * Advisory only: the fix on Upsun is a mount, and the writable-paths check
 * prints the exact YAML when it is missing.
 */

namespace Upsun\Integrations;

use Upsun\Integration;

class Wordfence implements Integration {

	public function label(): string {
		return 'Wordfence';
	}

	public function is_active(): bool {
		return class_exists( 'wordfence' );
	}

	public function register(): void {
		add_filter( 'upsun_writable_path_requirements', array( $this, 'add_requirements' ), 5 );
	}

	public function add_requirements( array $requirements ): array {
		$requirements['wordfence'] = array(
			'label'  => 'Wordfence',
			'active' => array( $this, 'is_active' ),
			'paths'  => array( 'wflogs' ),
		);

		return $requirements;
	}
}
