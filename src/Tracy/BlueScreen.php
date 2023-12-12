<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Tracy;


/**
 * Red BlueScreen.
 */
class BlueScreen
{
	/** @var string[] */
	public $info = [];

	/** @var string[] paths to be collapsed in stack trace (e.g. core libraries) */
	public $collapsePaths = [];

	/** @var int  */
	public $maxDepth = 3;

	/** @var int  */
	public $maxLength = 150;

	/** @var string[] */
	public $keysToHide = ['password', 'passwd', 'pass', 'pwd', 'creditcard', 'credit card', 'cc', 'pin'];

	/** @var callable[] */
	private $panels = [];

	/** @var callable[] functions that returns action for exceptions */
	private $actions = [];


	public function __construct()
	{
		$this->collapsePaths[] = preg_match('#(.+/vendor)/tracy/tracy/src/Tracy$#', strtr(__DIR__, '\\', '/'), $m)
			? $m[1]
			: __DIR__;
	}


	/**
	 * Add custom panel.
	 * @param  callable  $panel
	 * @return static
	 */
	public function addPanel($panel)
	{
		if (!in_array($panel, $this->panels, true)) {
			$this->panels[] = $panel;
		}
		return $this;
	}


	/**
	 * Add action.
	 * @param  callable  $action
	 * @return static
	 */
	public function addAction($action)
	{
		$this->actions[] = $action;
		return $this;
	}


	/**
	 * Renders blue screen.
	 * @param  \Exception|\Throwable  $exception
	 * @return void
	 */
	public function render($exception)
	{
		if (Helpers::isAjax() && session_status() === PHP_SESSION_ACTIVE) {
			ob_start(function () {});
			$this->renderTemplate($exception, __DIR__ . '/assets/BlueScreen/content.phtml');
			$contentId = $_SERVER['HTTP_X_TRACY_AJAX'];
			$_SESSION['_tracy']['bluescreen'][$contentId] = ['content' => ob_get_clean(), 'dumps' => Dumper::fetchLiveData(), 'time' => time()];

		} else {
			$this->renderTemplate($exception, __DIR__ . '/assets/BlueScreen/page.phtml');
		}
	}


	/**
	 * Renders blue screen to file (if file exists, it will not be overwritten).
	 * @param  \Exception|\Throwable  $exception
	 * @param  string  $file file path
	 * @return void
	 */
	public function renderToFile($exception, $file)
	{
		if (!file_exists($file) && $handle = @fopen($file, 'x')) {
			ob_start(); // double buffer prevents sending HTTP headers in some PHP
			ob_start(function ($buffer) use ($handle) { fwrite($handle, $buffer); }, 4096);
			$this->renderTemplate($exception, __DIR__ . '/assets/BlueScreen/page.phtml', false);
			ob_end_flush();
			ob_end_clean();
			fclose($handle);
		}
	}


	private function renderTemplate($exception, $template, $toScreen = true)
	{
		$messageHtml = preg_replace(
			'#\'\S[^\']*\S\'|"\S[^"]*\S"#U',
			'<i>$0</i>',
			htmlspecialchars((string) $exception->getMessage(), ENT_SUBSTITUTE, 'UTF-8')
		);
		$info = array_filter($this->info);
		$source = Helpers::getSource();
		$sourceIsUrl = preg_match('#^https?://#', $source);
		$title = $exception instanceof \ErrorException
			? Helpers::errorTypeToString($exception->getSeverity())
			: Helpers::getClass($exception);
		$lastError = $exception instanceof \ErrorException || $exception instanceof \Error ? null : error_get_last();

		$keysToHide = array_flip(array_map('strtolower', $this->keysToHide));
		$dump = function ($v, $k = null) use ($keysToHide) {
			if (is_string($k) && isset($keysToHide[strtolower($k)])) {
				$v = Dumper::HIDDEN_VALUE;
			}
			return Dumper::toHtml($v, [
				Dumper::DEPTH => $this->maxDepth,
				Dumper::TRUNCATE => $this->maxLength,
				Dumper::LIVE => true,
				Dumper::LOCATION => Dumper::LOCATION_CLASS,
				Dumper::KEYS_TO_HIDE => $this->keysToHide,
			]);
		};
		$css = array_map('file_get_contents', array_merge([
			__DIR__ . '/assets/BlueScreen/bluescreen.css',
		], Debugger::$customCssFiles));
		$css = preg_replace('#\s+#u', ' ', implode($css));

		$nonce = $toScreen ? Helpers::getNonce() : null;
		$actions = $toScreen ? $this->renderActions($exception) : [];

		require $template;
	}


	/**
	 * @return \stdClass[]
	 */
	private function renderPanels($ex)
	{
		$obLevel = ob_get_level();
		$res = [];
		foreach ($this->panels as $callback) {
			try {
				$panel = call_user_func($callback, $ex);
				if (empty($panel['tab']) || empty($panel['panel'])) {
					continue;
				}
				$res[] = (object) $panel;
				continue;
			} catch (\Exception $e) {
			} catch (\Throwable $e) {
			}
			while (ob_get_level() > $obLevel) { // restore ob-level if broken
				ob_end_clean();
			}
			is_callable($callback, true, $name);
			$res[] = (object) [
				'tab' => "Error in panel $name",
				'panel' => nl2br(Helpers::escapeHtml($e)),
			];
		}
		return $res;
	}


	/**
	 * @return array[]
	 */
	private function renderActions($ex)
	{
		$actions = [];
		foreach ($this->actions as $callback) {
			$action = call_user_func($callback, $ex);
			if (!empty($action['link']) && !empty($action['label'])) {
				$actions[] = $action;
			}
		}

		if (property_exists($ex, 'tracyAction') && !empty($ex->tracyAction['link']) && !empty($ex->tracyAction['label'])) {
			$actions[] = $ex->tracyAction;
		}

		if (preg_match('# ([\'"])(\w{3,}(?:\\\\\w{3,})+)\\1#i', $ex->getMessage(), $m)) {
			$class = $m[2];
			if (
				!class_exists($class) && !interface_exists($class) && !trait_exists($class)
				&& ($file = Helpers::guessClassFile($class)) && !is_file($file)
			) {
				$actions[] = [
					'link' => Helpers::editorUri($file, 1, 'create'),
					'label' => 'create class',
				];
			}
		}

		if (preg_match('# ([\'"])((?:/|[a-z]:[/\\\\])\w[^\'"]+\.\w{2,5})\\1#i', $ex->getMessage(), $m)) {
			$file = $m[2];
			$actions[] = [
				'link' => Helpers::editorUri($file, 1, $label = is_file($file) ? 'open' : 'create'),
				'label' => $label . ' file',
			];
		}

		$query = ($ex instanceof \ErrorException ? '' : Helpers::getClass($ex) . ' ')
			. preg_replace('#\'.*\'|".*"#Us', '', $ex->getMessage());
		$actions[] = [
			'link' => 'https://www.google.com/search?sourceid=tracy&q=' . urlencode($query),
			'label' => 'search',
			'external' => true,
		];

		if (
			$ex instanceof \ErrorException
			&& !empty($ex->skippable)
			&& preg_match('#^https?://#', $source = Helpers::getSource())
		) {
			$actions[] = [
				'link' => $source . (strpos($source, '?') ? '&' : '?') . '_tracy_skip_error',
				'label' => 'skip error',
			];
		}
		return $actions;
	}


	/**
	 * Returns syntax highlighted source code.
	 * @param  string $file
	 * @param  int    $line
	 * @param  int    $lines
	 * @param  bool   $php    true
	 * @param  int    $column 0
	 * @return string|null
	 */
	public static function highlightFile ($file, $line, $lines = 15, $php = true, $column = 0)
	{
		$source = @file_get_contents($file); // @ file may not exist
		if ($source) {
			$source = static::highlightPhp($source, $line, $lines, $column);
			if ($editor = Helpers::editorUri($file, $line)) {
				$source = substr_replace($source, ' data-tracy-href="' . Helpers::escapeHtml($editor) . '"', 4, 0);
			}
			return $source;
		}
	}


	/**
	 * Returns syntax highlighted source code.
	 * @param  string  $source
	 * @param  int  $line
	 * @param  int  $lines
	 * @return string
	 */
	public static function highlightPhp ($source, $line, $lines = 15, $column = 0)
	{
		return CodeHighlighter::highlightPhp($source, $line, $column);
	}


	/**
	 * Returns highlighted line in HTML code.
	 * @return string
	 */
	public static function highlightLine($html, $line, $lines = 15)
	{
		return CodeHighlighter::highlightLine($html, $line, $column);
	}


	/**
	 * Should a file be collapsed in stack trace?
	 * @param  string  $file
	 * @return bool
	 */
	public function isCollapsed($file)
	{
		$file = strtr($file, '\\', '/') . '/';
		foreach ($this->collapsePaths as $path) {
			$path = strtr($path, '\\', '/') . '/';
			if (strncmp($file, $path, strlen($path)) === 0) {
				return true;
			}
		}
		return false;
	}
}
