<?php
/**
 * A feature that wires itself into WordPress.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Anything that has to hook into WordPress and is not a settings section, a
 * channel, a REST controller or an admin screen is a module: the editor panel,
 * the Site Health tests, the page markup, the posts-list column, the privacy
 * tools, the cache purge, a translation-plugin integration.
 *
 * To add one: create `src/Modules/<Name>Module.php` implementing this interface.
 * It is found by folder and registered once, after the channels, while the
 * plugin starts. `register()` only adds hooks; it must not read the database,
 * call out, or do work. Check `is_admin()`, a setting or a plugin's presence
 * inside the hooked callbacks or at the top of `register()` and return early
 * when the module does not apply.
 */
interface ModuleInterface {

	/**
	 * Adds the module's hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void;
}
