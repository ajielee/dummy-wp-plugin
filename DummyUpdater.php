<?php

class DummyUpdater
{
	/**
	 * @var string
	 */
	private $file;

	/**
	 * @var string
	 */
	private $plugin;

	/**
	 * @var string
	 */
	private $basename;

	/**
	 * @var false
	 */
	private $active;

	/**
	 * @var string
	 */
	private $username;

	/**
	 * @var string
	 */
	private $repository;

	/**
	 * @var string
	 */
	private $authorizeToken;

	/**
	 * @var string
	 */
	private $githubResponse;

	/**
	 * DummyUpdate constructor.
	 *
	 * @param $file
	 */
	public function __construct($file)
	{
		$this->file = $file;
		add_action('admin_init', array($this, 'setPluginProperties'));
		return $this;
	}

	public function setPluginProperties()
	{
		$this->plugin = get_plugin_data($this->file);
		$this->basename = plugin_basename($this->file);
		$this->active = is_plugin_active($this->basename);
	}

	/**
	 * @param string $username
	 */
	public function setUsername($username)
	{
		$this->username = $username;
	}

	/**
	 * @param string $repository
	 */
	public function setRepository($repository)
	{
		$this->repository = $repository;
	}

	/**
	 * @param string $token
	 */
	public function authorize($token)
	{
		$this->authorizeToken = $token;
	}

	private function getRepositoryInfo()
	{
		// Do we have a response?
		if (! is_null($this->githubResponse)) {
			return;
		}

		// Build URI
		$requestUri = sprintf(
			'https://api.github.com/repos/%s/%s/releases',
			$this->username,
			$this->repository
		);

		// Is there an access token?
		if ($this->authorizeToken) {
			// Append it
			$requestUri = add_query_arg('access_token', $this->authorizeToken, $requestUri);
		}

		// Get JSON and parse it
		$response = json_decode(wp_remote_retrieve_body(wp_remote_get($requestUri)), true);

		// If it is an array
		if (is_array($response)) {
			// Get the first item
			$response = current($response);
		}

		// Is there an access token?
		if ($this->authorizeToken) {
			$response['zipball_url'] = add_query_arg('access_token', $this->authorizeToken,
				// Update our zip url with token
				$response['zipball_url']);
		}

		// Set it to our property
		$this->githubResponse = $response;
	}

	public function initialize()
	{
		add_filter('pre_set_site_transient_update_plugins', [$this, 'modifyTransient'], 10, 1);
		add_filter('plugins_api', [$this, 'pluginPopup'], 10, 3);
		add_filter('upgrader_post_install', [$this, 'afterInstall'], 10, 3);
	}

	/**
	 * @param $transient
	 *
	 * @return mixed
	 */
	public function modifyTransient($transient)
	{
		// Check if transient has a checked property
		if (! property_exists($transient, 'checked')) {
			return $transient;
		}

		// Did Wordpress check for updates?
		if ($checked = $transient->checked) {
			return $transient;
		}

		// Get the repo info
		$this->getRepositoryInfo();

		// Check if we're out of date
		$outOfDate = version_compare($this->githubResponse['tag_name'], $checked[$this->basename], 'gt');
		if ($outOfDate) {
			// Get the ZIP
			$newFiles = $this->githubResponse['zipball_url'];
			// Create valid slug
			$slug = current(explode('/', $this->basename));
			// setup our plugin info
			$plugin = [
				'url'         => $this->plugin['PluginURI'],
				'slug'        => $slug,
				'package'     => $newFiles,
				'new_version' => $this->githubResponse['tag_name']
			];
			// Return it in response
			$transient->response[$this->basename] = (object) $plugin;
		}

		// Return filtered transient
		return $transient;
	}

	/**
	 * @param $result
	 * @param $action
	 * @param $args
	 *
	 * @return object
	 */
	public function pluginPopup($result, $action, $args)
	{
		// If there is no slug
		if (empty($args->slug)) {
			return $result;
		}

		// If it's not our slug
		if ($args->slug !== current(explode('/', $this->basename))) {
			return $result;
		}

		// Get our repo info
		$this->getRepositoryInfo();

		// Set it to an array
		$plugin = [
			'name'              => $this->plugin['Name'],
			'slug'              => $this->basename,
			'requires'          => '3.3',
			'tested'            => '4.4.1',
			'rating'            => '100.0',
			'num_ratings'       => '10823',
			'downloaded'        => '14249',
			'added'             => '2016-01-05',
			'version'           => $this->githubResponse['tag_name'],
			'author'            => $this->plugin['AuthorName'],
			'author_profile'    => $this->plugin['AuthorURI'],
			'last_updated'      => $this->githubResponse['published_at'],
			'homepage'          => $this->plugin['PluginURI'],
			'short_description' => $this->plugin['Description'],
			'sections'          => array(
				'Description' => $this->plugin['Description'],
				'Updates'     => $this->githubResponse['body'],
			),
			'download_link'     => $this->githubResponse['zipball_url']
		];

		// Return the data
		return (object) $plugin;
	}

	public function afterInstall($response, $hook_extra, $result)
	{
		// Get global FS object
		global $wp_filesystem;

		$installDirectory = plugin_dir_path($this->file);
		// Move files to the plugin dir
		$wp_filesystem->move($result['destination'], $installDirectory);
		// Set the destination for the rest of the stack
		$result['destination'] = $installDirectory;

		// If it was active
		if ($this->active) {
			// Reactivate
			activate_plugin($this->basename);
		}

		return $result;
	}
}
