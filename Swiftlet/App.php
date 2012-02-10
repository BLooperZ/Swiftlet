<?php

namespace Swiftlet;

final class App implements Interfaces\App
{
	private
		$_action     = 'index',
		$_args       = array(),
		$_config     = array(),
		$_controller,
		$_hooks      = array(),
		$_plugins    = array(),
		$_rootPath   = '/',
		$_singletons = array(),
		$_view
		;

	/**
	 * Initialize the application
	 */
	public function __construct()
	{
		set_error_handler(array($this, 'error'), E_ALL | E_STRICT);

		spl_autoload_register(array($this, 'autoload'));

		// Determine the client-side path to root
		if ( !empty($_SERVER['REQUEST_URI']) ) {
			$this->_rootPath = preg_replace('/(index\.php)?(\?.*)?$/', '', $_SERVER['REQUEST_URI']);

			if ( !empty($_GET['q']) ) {
				$this->_rootPath = preg_replace('/' . preg_quote($_GET['q'], '/') . '$/', '', $this->_rootPath);
			}
		}

		// Extract controller name, view name, action name and arguments from URL
		$controllerName = 'Index';

		if ( !empty($_GET['q']) ) {
			$this->_args = explode('/', $_GET['q']);

			if ( $this->_args ) {
				$controllerName = str_replace(' ', '/', ucwords(str_replace('_', ' ', array_shift($this->_args))));
			}

			if ( $action = $this->_args ? array_shift($this->_args) : '' ) {
				$this->_action = $action;
			}
		}

		if ( !is_file('Swiftlet/Controllers/' . $controllerName . '.php') ) {
			$controllerName = 'Error404';
		}

		$viewName = strtolower($controllerName);

		$this->_view = new View($this, $viewName);

		// Instantiate the controller
		$controllerName = 'Swiftlet\Controllers\\' . basename($controllerName);

		$this->_controller = new $controllerName($this, $this->_view);

		// Load plugins
		if ( $handle = opendir('Swiftlet/Plugins') ) {
			while ( ( $file = readdir($handle) ) !== FALSE ) {
				if ( is_file('Swiftlet/Plugins/' . $file) && preg_match('/^(.+)\.php$/', $file, $match) ) {
					$pluginName = 'Swiftlet\Plugins\\' . $match[1];

					$this->_plugins[$pluginName] = array();

					foreach ( get_class_methods($pluginName) as $methodName ) {
						$method = new \ReflectionMethod($pluginName, $methodName);

						if ( $method->isPublic() && !$method->isFinal() ) {
							$this->_plugins[$pluginName][] = $methodName;
						}
					}
				}
			}

			ksort($this->_plugins);

			closedir($handle);
		}

		// Call the controller action
		$this->registerHook('actionBefore');

		if ( method_exists($this->_controller, $this->_action) ) {
			$method = new \ReflectionMethod($this->_controller, $this->_action);

			if ( $method->isPublic() && !$method->isFinal() ) {
				$this->_controller->{$this->_action}();
			} else {
				$this->_controller->notImplemented();
			}
		} else {
			$this->_controller->notImplemented();
		}

		$this->registerHook('actionAfter');
	}

	/**
	 * Serve the page
	 */
	public function serve()
	{
		$this->_view->render();
	}

	/**
	 * Set a configuration value
	 * @param string $variable
	 * @param mixed $value
	 */
	public function setConfig($variable, $value)
   	{
		$this->_config[$variable] = $value;
	}

	/**
	 * Get a configuration value
	 * @param string $variabl
	 * @return mixed
	 */
	public function getConfig($variable)
   	{
		if ( isset($this->_config[$variable]) ) {
			return $this->_config[$variable];
		}
	}

	/**
	 * Get the action name
	 * @return string
	 */
	public function getAction()
   	{
		return $this->_action;
	}

	/**
	 * Get the arguments
	 * @return array
	 */
	public function getArgs()
   	{
		return $this->_args;
	}

	/**
	 * Get a model
	 * @param string $modelName
	 * @return object
	 */
	public function getModel($modelName)
   	{
		$modelName = 'Swiftlet\Models\\' . ucfirst($modelName);

		// Instantiate the model
		return new $modelName();
	}

	/**
	 * Get a model singleton
	 * @param string $modelName
	 * @return object
	 */
	public function getSingleton($modelName)
	{
		if ( isset($this->_singletons[$modelName]) ) {
			return $this->_singletons[$modelName];
		}

		$model = $this->getModel($modelName);

		$this->_singletons[$modelName] = $model;

		return $model;
	}

	/**
	 * Get the client-side path to root
	 * @return string
	 */
	public function getRootPath()
	{
		return $this->_rootPath;
	}

	/**
	 * Register a new hook for plugins to implement
	 * @param string $hookName
	 * @param array $params
	 */
	public function registerHook($hookName, array $params = array())
	{
		$this->_hooks[] = $hookName;

		foreach ( $this->_plugins as $pluginName => $hooks ) {
			if ( in_array($hookName, $hooks) ) {
				$plugin = new $pluginName($this, $this->_view, $this->_controller);

				$plugin->{$hookName}($params);

				unset($plugin);
			}
		}
	}

	/**
	 * Class autoloader
	 * @see https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-0.md
	 */
	public function autoload($className)
	{
		preg_match('/(^.+\\\)?([^\\\]+)$/', ltrim($className, '\\'), $match);

		$file = str_replace('\\', '/', $match[1]) . str_replace('_', '/', $match[2]) . '.php';

		require $file;
	}

	/**
	 * Error handler
	 * @param int $number
	 * @param string $string
	 * @param string $file
	 * @param int $line
	 */
	public function error($number, $string, $file, $line)
	{
		throw new \Exception('Error #' . $number . ': ' . $string . ' in ' . $file . ' on line ' . $line);
	}
}
