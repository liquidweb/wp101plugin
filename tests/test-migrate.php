<?php
/**
 * Tests for WP101 migrations.
 *
 * @package WP101
 */

namespace WP101\Tests;

use PHPUnit\Framework\Error\Warning;
use WP_Error;
use WP101\Admin as Admin;
use WP101\Migrate as Migrate;

/**
 * Tests for migrating from older versions of the WP101 plugin.
 *
 * @group Migration
 */
class MigrateTest extends TestCase {

	const LEGACY_API_KEY = 'abcdefghijkl';

	const CURRENT_API_KEY = 'abcdefghijklmnopqrstuvwxyz123456';

	/**
	 * @after
	 */
	public function remove_actions() {
		remove_all_actions( 'admin_notices' );
	}

	/**
	 * If the wp101_api_key option has a legacy key, attempt to exchange it.
	 */
	public function test_maybe_migrate() {
		$api = $this->mock_api();
		$api->shouldReceive( 'exchange_api_key' )
			->once()
			->andReturn( [
				'apiKey' => self::CURRENT_API_KEY,
			] );
		$this->set_api_key( self::LEGACY_API_KEY );

		// Populate some other legacy options.
		update_option( 'wp101_custom_topics', [
			'custom-topic' => [
				'title'   => 'Custom Topic',
				'content' => 'Some video embed',
			],
		] );
		update_option( 'wp101_hidden_topics', [ 1, 2, 3 ] );

		Migrate\maybe_migrate();

		$this->assertEquals( self::CURRENT_API_KEY, get_option( 'wp101_api_key' ) );
		$this->assertEquals( 10, has_action( 'admin_notices', 'WP101\Migrate\render_migration_success_notice' ) );
		$this->assertFalse(
			get_option( 'wp101_custom_topics', false ),
			'Expected the wp101_custom_topics option to be deleted.'
		);
		$this->assertFalse(
			get_option( 'wp101_hidden_topics', false ),
			'Expected the wp101_hidden_topics option to be deleted.'
		);
	}

	/**
	 * The maybe_migrate() function should return early if there's nothing to migrate.
	 */
	public function test_maybe_migrate_returns_early_no_key_is_present() {
		delete_option( 'wp101_api_key' );

		$api = $this->mock_api();
		$api->shouldReceive( 'exchange_api_key' )->never();

		Migrate\maybe_migrate();

		$this->assertFalse( has_action( 'admin_notices' ) );
	}

	/**
	 * If an API key already matches the expected pattern, don't attempt to exchange it.
	 */
	public function test_maybe_migrate_returns_early_if_keys_do_not_require_migration() {
		$api = $this->mock_api();
		$api->shouldReceive( 'exchange_api_key' )->never();
		$this->set_api_key( self::CURRENT_API_KEY );

		Migrate\maybe_migrate();

		$this->assertFalse( has_action( 'admin_notices' ) );
	}

	/**
	 * If we receive a WP_Error while exchanging the key, ensure we handle it properly.
	 */
	public function test_maybe_migrate_handles_wp_errors() {
		$api = $this->mock_api();
		$api->shouldReceive( 'exchange_api_key' )
			->once()
			->andReturn( new WP_Error( 'some error' ) );
		$this->set_api_key( self::LEGACY_API_KEY );

		$this->expectException( Warning::class );

		Migrate\maybe_migrate();

		$this->assertEquals( self::LEGACY_API_KEY, get_option( 'wp101_api_key' ) );
		$this->assertTrue( has_action( 'admin_notices', 'WP101\Migrate\render_migration_failure_notice' ) );
	}

	public function test_api_key_needs_migration() {
		$this->assertTrue( Migrate\api_key_needs_migration( self::LEGACY_API_KEY ) );
		$this->assertFalse( Migrate\api_key_needs_migration( self::CURRENT_API_KEY ) );
		$this->assertFalse( Migrate\api_key_needs_migration( '') );
	}

	public function test_render_migration_success_notice() {
		ob_start();
		Migrate\render_migration_success_notice();
		$output = ob_get_clean();

		$this->assertContainsSelector( '#wp101-api-key-upgraded', $output );
	}

	public function test_render_migration_failure_notice() {
		ob_start();
		Migrate\render_migration_failure_notice();
		$output = ob_get_clean();

		$this->assertContainsSelector( '#wp101-api-key-upgrade-failed', $output );
	}

	/**
	 * Scenario: An existing subscriber has a (valid) legacy API key saved in wp-config.php.
	 *
	 * They should be given instructions on how to update the key, including the new value.
	 *
	 * @requires extension runkit
	 *
	 * @link https://github.com/liquidweb/wp101plugin/issues/34
	 */
	public function test_old_key_requires_migration() {
		define( 'WP101_API_KEY', self::LEGACY_API_KEY );

		$api = $this->mock_api();
		$api->shouldReceive( 'exchange_api_key' )
			->once()
			->andReturn( [
				'apiKey' => self::CURRENT_API_KEY,
			] );

		Migrate\maybe_migrate();

		$this->assertEquals( self::CURRENT_API_KEY, get_option( 'wp101_api_key' ) );
		$this->assertEquals( 10, has_action( 'admin_notices', 'WP101\Migrate\render_constant_upgrade_notice' ) );
	}

	/**
	 * Scenario: An existing subscriber has a (invalid) legacy API key saved in wp-config.php.
	 *
	 * They should be given instructions on how to remove the key.
	 *
	 * @requires extension runkit
	 *
	 * @link https://github.com/liquidweb/wp101plugin/issues/34
	 */
	public function test_invalid_key_needs_removed() {
		define( 'WP101_API_KEY', 'xxx' );

		$api = $this->mock_api();
		$api->shouldReceive( 'exchange_api_key' )
			->once()
			->andReturn( new WP_Error( 'wp101-api', 'Message from the WP_Error object' ) );

		$this->expectException( Warning::class );

		Migrate\maybe_migrate();

		$this->assertContainsSelector( '#wp101-api-key-constant-remove-notice', $output );
		$this->assertContains( "'WP101_API_KEY', '" . self::LEGACY_API_KEY . "'", $output );
	}
}
