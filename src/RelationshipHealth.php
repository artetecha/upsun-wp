<?php
/**
 * Live health probes for the environment's service relationships.
 *
 * Config problems (missing relationship, wrong credentials) surface at
 * boot; this answers the other question — is the service actually well? —
 * with per-scheme probes: a ping + server info for MySQL/MariaDB, INFO
 * memory/hit-rate/evictions for Redis, an HTTP status (with cluster-status
 * sniffing for Elasticsearch-alikes) for http(s) services. Unknown schemes
 * are skipped, never guessed. Surfaced by `wp upsun relationships
 * --health` and the shared check registry (Site Health, dashboard,
 * doctor).
 */

namespace Upsun;

final class RelationshipHealth {

	/**
	 * Probe every relationship instance.
	 *
	 * @return array<int, array{relationship: string, scheme: string, host: string, status: string, detail: string}>
	 *               status: pass | warn | fail | skip.
	 */
	public static function probe_all(): array {
		$rows = array();

		foreach ( Environment::relationships() as $name => $instances ) {
			if ( ! is_array( $instances ) ) {
				continue;
			}

			foreach ( $instances as $instance ) {
				if ( ! is_array( $instance ) ) {
					continue;
				}

				$result = self::probe( $instance );
				$host   = (string) ( $instance['host'] ?? '' );
				$port   = (string) ( $instance['port'] ?? '' );

				$rows[] = array(
					'relationship' => (string) $name,
					'scheme'       => (string) ( $instance['scheme'] ?? '' ),
					'host'         => '' !== $port ? "{$host}:{$port}" : $host,
					'status'       => $result['status'],
					'detail'       => $result['detail'],
				);
			}
		}

		return $rows;
	}

	/**
	 * @return array{status: string, detail: string}
	 */
	public static function probe( array $instance ): array {
		switch ( (string) ( $instance['scheme'] ?? '' ) ) {
			case 'mysql':
			case 'mariadb':
				return self::probe_mysql( $instance );
			case 'redis':
				return self::probe_redis( $instance );
			case 'http':
			case 'https':
				return self::probe_http( $instance );
			default:
				return array(
					'status' => 'skip',
					'detail' => sprintf( 'no probe for scheme "%s"', (string) ( $instance['scheme'] ?? '' ) ),
				);
		}
	}

	/**
	 * The shared health check: probes everything, fails when any service
	 * does. Skipped schemes never affect the verdict.
	 *
	 * @return array{status: string, message: string}
	 */
	public static function check(): array {
		$rows = self::probe_all();

		if ( array() === $rows ) {
			return array(
				'status'  => 'pass',
				'message' => __( 'No relationships found.', 'upsun-mu-plugin' ),
			);
		}

		$status  = 'pass';
		$parts   = array();
		$skipped = 0;

		foreach ( $rows as $row ) {
			if ( 'skip' === $row['status'] ) {
				$skipped++;
				continue;
			}

			$parts[] = sprintf( '%s (%s): %s', $row['relationship'], $row['scheme'], $row['detail'] );

			if ( 'fail' === $row['status'] ) {
				$status = 'fail';
			} elseif ( 'warn' === $row['status'] && 'fail' !== $status ) {
				$status = 'warn';
			}
		}

		$message = implode( '; ', $parts );

		if ( $skipped > 0 ) {
			$message .= sprintf( ' (%d unprobed scheme(s) skipped)', $skipped );
		}

		return array(
			'status'  => $status,
			'message' => $message,
		);
	}

	/* ---------------------------------------------------------------------
	 * Probes. Thin wrappers around connections; the formatting/verdict
	 * helpers below are pure and unit-tested.
	 * ------------------------------------------------------------------- */

	private static function probe_mysql( array $instance ): array {
		global $wpdb;

		$host = (string) ( $instance['host'] ?? '' );
		$port = (int) ( $instance['port'] ?? 3306 );

		// The relationship WordPress itself runs on: reuse the live handle.
		if ( isset( $wpdb ) && is_object( $wpdb ) && defined( 'DB_HOST' )
			&& in_array( DB_HOST, array( $host, "{$host}:{$port}" ), true ) ) {
			if ( method_exists( $wpdb, 'check_connection' ) && ! $wpdb->check_connection( false ) ) {
				return array(
					'status' => 'fail',
					'detail' => 'wpdb connection lost and could not reconnect',
				);
			}

			$server = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';

			return array(
				'status' => 'pass',
				'detail' => 'connected (wpdb' . ( '' !== $server ? ', ' . $server : '' ) . ')',
			);
		}

		if ( ! class_exists( '\mysqli' ) ) {
			return array(
				'status' => 'warn',
				'detail' => 'mysqli not available for probing',
			);
		}

		mysqli_report( MYSQLI_REPORT_OFF );
		$mysqli = mysqli_init();
		$mysqli->options( MYSQLI_OPT_CONNECT_TIMEOUT, 2 );

		if ( ! @$mysqli->real_connect(
			$host,
			(string) ( $instance['username'] ?? '' ),
			(string) ( $instance['password'] ?? '' ),
			(string) ( $instance['path'] ?? '' ),
			$port
		) ) {
			return array(
				'status' => 'fail',
				'detail' => 'connect failed: ' . $mysqli->connect_error,
			);
		}

		$server = $mysqli->server_info;
		$mysqli->close();

		return array(
			'status' => 'pass',
			'detail' => "connected ({$server})",
		);
	}

	private static function probe_redis( array $instance ): array {
		if ( ! class_exists( '\Redis' ) ) {
			return array(
				'status' => 'warn',
				'detail' => 'phpredis not available for probing',
			);
		}

		try {
			$redis = new \Redis();

			if ( ! @$redis->connect( (string) ( $instance['host'] ?? '' ), (int) ( $instance['port'] ?? 6379 ), 1.5 ) ) {
				return array(
					'status' => 'fail',
					'detail' => 'connect failed',
				);
			}

			if ( ! empty( $instance['password'] ) ) {
				$redis->auth( (string) $instance['password'] );
			}

			$info = $redis->info();
			$redis->close();
		} catch ( \Throwable $exception ) {
			return array(
				'status' => 'fail',
				'detail' => 'connect failed: ' . $exception->getMessage(),
			);
		}

		return array(
			'status' => 'pass',
			'detail' => self::redis_detail( is_array( $info ) ? $info : array() ),
		);
	}

	private static function probe_http( array $instance ): array {
		$url = sprintf(
			'%s://%s:%s/',
			(string) ( $instance['scheme'] ?? 'http' ),
			(string) ( $instance['host'] ?? '' ),
			(string) ( $instance['port'] ?? 80 )
		);

		$response = wp_remote_get( $url, array( 'timeout' => 2, 'sslverify' => false ) );

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'fail',
				'detail' => 'request failed: ' . $response->get_error_message(),
			);
		}

		return self::http_verdict(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response )
		);
	}

	/* ---------------------------------------------------------------------
	 * Pure helpers (unit-tested).
	 * ------------------------------------------------------------------- */

	/**
	 * Human summary of a Redis INFO payload: memory against maxmemory,
	 * keyspace hit rate, evictions (the "is my cache sized right?" trio).
	 */
	public static function redis_detail( array $info ): string {
		$used = (int) ( $info['used_memory'] ?? 0 );
		$max  = (int) ( $info['maxmemory'] ?? 0 );

		$memory = self::human_bytes( $used ) . ( $max > 0 ? '/' . self::human_bytes( $max ) : ' (no maxmemory limit)' );

		$hits   = (int) ( $info['keyspace_hits'] ?? 0 );
		$misses = (int) ( $info['keyspace_misses'] ?? 0 );
		$rate   = ( $hits + $misses ) > 0
			? sprintf( '%.1f%%', 100 * $hits / ( $hits + $misses ) )
			: 'n/a';

		return sprintf(
			'memory %s; hit rate %s; evicted %d',
			$memory,
			$rate,
			(int) ( $info['evicted_keys'] ?? 0 )
		);
	}

	/**
	 * Verdict for an http(s) service response; recognizes the cluster
	 * status Elasticsearch/OpenSearch report on their root/health bodies.
	 *
	 * @return array{status: string, detail: string}
	 */
	public static function http_verdict( int $code, string $body ): array {
		$cluster = '';

		if ( preg_match( '/"status"\s*:\s*"(green|yellow|red)"/', $body, $matches ) ) {
			$cluster = $matches[1];
		}

		if ( $code >= 200 && $code < 300 ) {
			if ( 'red' === $cluster ) {
				return array( 'status' => 'fail', 'detail' => "HTTP {$code}, cluster red" );
			}

			if ( 'yellow' === $cluster ) {
				return array( 'status' => 'warn', 'detail' => "HTTP {$code}, cluster yellow" );
			}

			return array(
				'status' => 'pass',
				'detail' => "HTTP {$code}" . ( 'green' === $cluster ? ', cluster green' : '' ),
			);
		}

		return array(
			'status' => 'warn',
			'detail' => "HTTP {$code}",
		);
	}

	public static function human_bytes( int $bytes ): string {
		$units = array( 'B', 'K', 'M', 'G', 'T' );
		$value = (float) max( 0, $bytes );
		$unit  = 0;

		while ( $value >= 1024 && $unit < count( $units ) - 1 ) {
			$value /= 1024;
			$unit++;
		}

		return ( $value >= 10 || 0 === $unit ? sprintf( '%d', $value ) : sprintf( '%.1f', $value ) ) . $units[ $unit ];
	}
}
