<?php

use PHPUnit\Framework\TestCase;
use Upsun\RelationshipHealth;

final class RelationshipHealthTest extends TestCase {

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	protected function tearDown(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	private function fake_relationships( array $relationships ): void {
		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		putenv( 'PLATFORM_ENVIRONMENT=main' );
		putenv( 'PLATFORM_RELATIONSHIPS=' . base64_encode( json_encode( $relationships ) ) );
		\Upsun\Environment::reset();
	}

	public function test_unknown_schemes_are_skipped_not_guessed(): void {
		$result = RelationshipHealth::probe( array( 'scheme' => 'solr-custom' ) );

		$this->assertSame( 'skip', $result['status'] );
		$this->assertStringContainsString( 'solr-custom', $result['detail'] );
	}

	public function test_redis_detail_summarizes_memory_and_hit_rate(): void {
		$detail = RelationshipHealth::redis_detail(
			array(
				'used_memory'     => 12 * 1024 * 1024,
				'maxmemory'       => 512 * 1024 * 1024,
				'keyspace_hits'   => 970,
				'keyspace_misses' => 30,
				'evicted_keys'    => 4,
			)
		);

		$this->assertSame( 'memory 12M/512M; hit rate 97.0%; evicted 4', $detail );
	}

	public function test_redis_detail_handles_no_traffic_and_no_limit(): void {
		$detail = RelationshipHealth::redis_detail( array( 'used_memory' => 2048 ) );

		$this->assertStringContainsString( 'no maxmemory limit', $detail );
		$this->assertStringContainsString( 'hit rate n/a', $detail );
	}

	public function test_http_verdict_recognizes_cluster_status(): void {
		$this->assertSame( 'pass', RelationshipHealth::http_verdict( 200, '{"status":"green"}' )['status'] );
		$this->assertSame( 'warn', RelationshipHealth::http_verdict( 200, '{"status":"yellow"}' )['status'] );
		$this->assertSame( 'fail', RelationshipHealth::http_verdict( 200, '{"status":"red"}' )['status'] );
		$this->assertSame( 'pass', RelationshipHealth::http_verdict( 200, 'plain body' )['status'] );
		$this->assertSame( 'warn', RelationshipHealth::http_verdict( 503, '' )['status'] );
	}

	public function test_human_bytes(): void {
		$this->assertSame( '512B', RelationshipHealth::human_bytes( 512 ) );
		$this->assertSame( '1.5K', RelationshipHealth::human_bytes( 1536 ) );
		$this->assertSame( '12M', RelationshipHealth::human_bytes( 12 * 1024 * 1024 ) );
		$this->assertSame( '2.0G', RelationshipHealth::human_bytes( 2 * 1024 * 1024 * 1024 ) );
	}

	public function test_check_passes_with_no_relationships(): void {
		$this->fake_relationships( array() );

		$result = RelationshipHealth::check();

		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'No relationships', $result['message'] );
	}

	public function test_check_skips_unprobed_schemes_without_affecting_the_verdict(): void {
		$this->fake_relationships(
			array(
				'search' => array( array( 'scheme' => 'unknown-thing', 'host' => 'search.internal', 'port' => 9200 ) ),
			)
		);

		$result = RelationshipHealth::check();

		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( '1 unprobed scheme(s) skipped', $result['message'] );
	}

	public function test_check_joins_the_shared_registry(): void {
		$checks = \Upsun\Modules\SiteHealth::checks();

		$this->assertArrayHasKey( 'relationships', $checks );
		$this->assertArrayHasKey( 'disk_usage', $checks );
	}
}
