<?php
/**
 * WP Rocket writes its page cache to wp-content/cache/wp-rocket and its
 * per-site config to wp-content/wp-rocket-config — both mountable. It also
 * installs the advanced-cache.php drop-in in the wp-content root, which
 * CANNOT be a mount (it would shadow deployed code): that residual gap is
 * surfaced as a note and belongs in the build step, like an object-cache
 * drop-in.
 */

namespace Upsun\Integrations;

use Upsun\Integration;

class WpRocket implements Integration {

	public function label(): string {
		return 'WP Rocket';
	}

	public function is_active(): bool {
		return defined( 'WP_ROCKET_VERSION' );
	}

	public function register(): void {
		add_filter( 'upsun_writable_path_requirements', array( $this, 'add_requirements' ), 5 );
	}

	public function add_requirements( array $requirements ): array {
		$requirements['wp-rocket'] = array(
			'label'  => 'WP Rocket',
			'active' => array( $this, 'is_active' ),
			'paths'  => array( 'cache', 'wp-rocket-config' ),
			'note'   => __( 'Note: WP Rocket also writes the advanced-cache.php drop-in to the wp-content root, which cannot be a mount — copy it in at build time (Composer post-install), like an object-cache drop-in.', 'upsun-mu-plugin' ),
		);

		return $requirements;
	}
}
