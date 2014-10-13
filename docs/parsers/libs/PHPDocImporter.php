<?php

/**
 * @todo
 * \this в коде методов менять на текущий класс или заменять на ссылку
 * опции в описании клиентов
 */

spl_autoload_register(function($class_name) {
	$filepath = __DIR__ . '/' . str_replace('\\', '/', $class_name) . '.php';
	if(!file_exists($filepath)) {
		return false;
	}

	require $filepath;
});

/**
 * Рекурсивный вариант функции glob
 */
if(!function_exists('glob_recursive')) {
	// Does not support flag GLOB_BRACE
	function glob_recursive($pattern, $flags = 0) {
		$files = glob($pattern, $flags);
		foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) as $dir) {
			$files = array_merge($files, glob_recursive($dir.'/'.basename($pattern), $flags));
		}
		return $files;
	}
}

class PHPDocImporter {
	protected $sourcePath;

	protected $flagFileChanged = false;

	protected $tags = [
		'namespace' => [
			'open'    => '<!-- include-namespace',
			'openEnd' => '-->',
			'close'   => '<!--/ include-namespace -->'
		]
	];

	protected $tagsRegex = [];

	public function __construct() {
		foreach ($this->tags as $tagKey => $tagType) {
			foreach ($tagType as $tagTypeKey => $tagTypeVal) {
				$this->tagsRegex[$tagKey][$tagTypeKey] = preg_quote($tagTypeVal, '/');
			}
		}
	}

	/**
	 * Основной метод парсинга
	 * @param  string $doc_path Путь до доки
	 * @param  string $phd_path Путь до файлов проекта
	 */
	public function parse($params) {
		$doc_path = $params[0];
		$this->sourcePath = $params[1];
		$this->ignoreNamespaces = explode(':', $params[2]);
		$this->autoloadRegister();

		// @todo test => prod
		$mdfiles = glob_recursive($doc_path . '/*.md', GLOB_NOSORT);
		// $mdfiles = glob_recursive($doc_path . '/structures/object-storage.md', GLOB_NOSORT);
		// $mdfiles = glob_recursive($doc_path . '/libraries/fs.md', GLOB_NOSORT);
		// $mdfiles = glob_recursive($doc_path . '/libraries/complexjob.md', GLOB_NOSORT);

		foreach ($mdfiles as $mdpath) {
			$this->parseFile($mdpath);
		}
	}

	protected function autoloadRegister() {
		spl_autoload_register(function($class_name) {
			$filepath = $this->sourcePath . '/' . str_replace('\\', '/', trim($class_name, '/')) . '.php';
			if(!file_exists($filepath)) {
				return false;
			}

			require $filepath;
		});
	}

	/**
	 * Парсинг каждогоо файла доки отдельно
	 * @param  string $mdpath
	 */
	public function parseFile($mdpath) {
		$this->flagFileChanged = false;
		$content = file_get_contents($mdpath);

		// последовательный парсинг тегов
		$this->tagNamespace($content);

		if($this->flagFileChanged) {
			file_put_contents($mdpath, $content, LOCK_EX);
		}
	}

	/**
	 * Парсинг тега "namespace"
	 * @param  string $content
	 */
	protected function tagNamespace(&$content) {
		$regex = '/(?P<start>^|\s+)'
			. $this->tagsRegex['namespace']['open']
			. ' (.+?)'
			. $this->tagsRegex['namespace']['openEnd']
			. '(?:(.+?)'
			. $this->tagsRegex['namespace']['close']
			. ')?/ims';

		$content = preg_replace_callback($regex, [$this, 'tagNamespaceCallback'], $content);
	}

	/**
	 * Обработчик парсера тега "namespace"
	 * @param  [type] $matches [description]
	 * @return [type]          [description]
	 */
	protected function tagNamespaceCallback($matches) {
		$params = $this->parseTagParams($matches[2]);

		$commit   = '';
		$rootpath = $this->getSourceRootPath($params['path']);

		if(!$rootpath) {
			$content = 'ERROR! Source root path not found';
		}
		else {
			$isActual = $this->isCommitActual($rootpath, $params['commit'], $commit);
			
			// commit прежний
			if($isActual) {
				return $matches[0];
			}

			// ставим флаг что контент будет изменен
			$this->flagFileChanged = true;

			$classes = $this->getSourceClasses($params['path']);

			$content = '';
			$is_header = count($classes) > 1;

			foreach ($classes as $class_path => $class_name) {
				if(in_array($class_name, $this->ignoreNamespaces)) {
					continue;
				}

				if(!class_exists($class_name)) {
					// @todo выбрасывать ошибку?
					continue;
				}

				// try {
					$ReflectionClass = new ReflectionClass($class_name);
				// } catch (LogicException $Exception) {
				// 	die('Not gonna make it in here...');
				// } catch (ReflectionException $Exception) {
				// 	die('Your class does not exist!');
				// }

				if($is_header) {
					$content .= $this->getClassHeader($ReflectionClass, $params, $class_path, $class_name);
				}

				$content .= $this->getClassConstants($ReflectionClass, $params, $class_path, $class_name);
				$content .= $this->getClassProperties($ReflectionClass, $params, $class_path, $class_name);
				$content .= $this->getClassMethods($ReflectionClass, $params, $class_path, $class_name);
			}

			$params['commit'] = $commit;
		}

		return $matches['start']
			. $this->tags['namespace']['open']
			. ' '
			. $this->paramsToStr($params)
			. ' '
			. $this->tags['namespace']['openEnd']
			. "\n"
			. $content
			. "\n"
			. $this->tags['namespace']['close'];
	}

	protected function getSourceRootPath($path) {
		$filepath = trim(str_replace('\\', '/', $path), '/');
		$fullpath = $this->sourcePath .'/'. $filepath;

		if(substr($fullpath, -4, 4) === '.php' && file_exists($fullpath)) {
			return $filepath;
		}
		else
		if(!is_dir($fullpath) && file_exists($fullpath.'.php')) {
			return $filepath.'.php';
		}
		else
		if(is_dir($fullpath)) {
			return $filepath;
		}

		return false;
	}

	/**
	 * Парсинг параметров тега. Возврашает ассоциативный массив параметров
	 * @param  string $str
	 * @return array
	 */
	protected function parseTagParams($str) {
		$params = [
			'path'   => false,
			'commit' => false,
			'level'  => 0,
			'access' => false
		];

		preg_match_all('/([a-z]+)\=\"(.*?)\"/i', $str, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			$params[$match[1]] = $match[2];
		}
		return $params;
	}

	/**
	 * Проверяем изменился ли коммит
	 * @param  [type]  $sourcepath [description]
	 * @param  [type]  $commit     [description]
	 * @return boolean             [description]
	 */
	protected function isCommitActual($sourcepath, $commit, &$result) {
		return false;
		
		$filepath = trim(str_replace('\\', '/', $sourcepath), '/');
		$cmd = "cd $this->sourcePath;git log --pretty=format:\"%H\" -1 $this->sourcePath/$filepath";
		$result = exec($cmd);

		if(strlen($result) === 40 && $result === $commit) {
			return true;
		}

		return false;
	}

	/**
	 * Преобразованеи массива параметров в строку
	 * @param  array  $params
	 * @return string
	 */
	protected function paramsToStr($params) {
		$out = [];
		foreach ($params as $key => $value) {
			$out[] = $key . '="' . (is_string($value) ? $value : '') . '"';
		}
		return implode(' ', $out);
	}

	/**
	 * Возвращает ассоциативный массив исходных файлов [fullpath => namespace]
	 * @param  string $path Путь до класса/классов
	 * @return array
	 */
	protected function getSourceClasses($path) {
		$filepath = trim(str_replace('\\', '/', $path), '/');
		$fullpath = $this->sourcePath .'/'. $filepath;
		$result = [];

		if(substr($fullpath, -4, 4) === '.php' && file_exists($fullpath)) {
			$result[$filepath] = substr($path, 0, -4);
		}
		else
		if(!is_dir($fullpath) && file_exists($fullpath.'.php')) {
			$result[$filepath.'.php'] = $path;
		}
		else
		if(is_dir($fullpath)) {
			$preflen = strlen($this->sourcePath);
			$files = glob_recursive($fullpath . '/*.php', GLOB_NOSORT);
			foreach ($files as $filepath) {
				$fp = substr($filepath, $preflen + 1);
				$ns = '\\' . str_replace('/', '\\', substr($fp, 0, -4));
				$result[$fp] = $ns;
			}
		}

		return $result;
	}


	protected function getClassHeader($ReflectionClass, &$params, $class_path, $class_name) {
		$ns = $ReflectionClass->getNamespaceName();
		$name = $ReflectionClass->getName();

		if($ns) {
			$name = substr($ReflectionClass->getName(), strlen($ns) + 1);
		}

		$parent = $ReflectionClass->getParentClass();
		$extra_parent = '';
		if($parent instanceof ReflectionClass) {
			$parent_ns = $parent->getNamespaceName();
			$parent_name = '\\' . $parent->getName();
			$extra_parent = ' extends '.$parent_name;
		}

		$altname = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $name));
		$type = 'Class';

		if($ReflectionClass->isTrait()) {
			$type = 'Trait';
		}

		$typeLower = strtolower($type);

		if(!isset($params['level']) || !$params['level']) {
			$params['level'] = 3;
		}
		$level = str_repeat('#', $params['level'] + 1);

		$result = <<<TPL
$level $altname # $type $name {tpl-git $class_path}

```php
namespace $ns;
$typeLower {$name}{$extra_parent};
```


TPL;

		return $result;
	}

	protected function getClassConstants($ReflectionClass, $params, $class_path, $class_name) {
		$level = $params['level'] ? $params['level'] + 2 : 4;
		$level = str_repeat('#', $level);
		$result = '';

		$ConstDoc = new ConstDoc($class_name);
		$constants = $ConstDoc->getDocComments();

		foreach ($constants as $constant => $value) {
			$code = $this->getConstantCode($class_path, $constant);

			$result .= "<md:const>\n";
			$result .= $code."\n";
			$result .= $value."\n";
			$result .= "</md:const>\n\n";
		}

		if($result === '') {
			return '';
		}

		return "$level consts # Constants\n\n" . $result;
	}

	protected function getConstantCode($path, $constant) {
		static $cache = [];

		if(!isset($cache[$path])) {
			$cache[$path] = file_get_contents($this->sourcePath . '/' . $path);
		}

		if(preg_match("/^\s*(const[ \t]*{$constant}[ \t]*\=[ \t]*(.+?)\;)/im", $cache[$path], $matches)) {
			return "const $constant = {$matches[2]};";
		}

		return false;
	}

	protected function getClassProperties($ReflectionClass, $params, $class_path, $class_name) {
		$access = ReflectionProperty::IS_PUBLIC;

		if($params['access'] == 1) {
			$access = $access | ReflectionProperty::IS_PROTECTED;
		}

		if($params['access'] == 2) {
			$access = $access | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE;
		}

		$level = $params['level'] ? $params['level'] + 2 : 4;
		$level = str_repeat('#', $level);
		$result = '';
		$properties = $ReflectionClass->getProperties($access);

		foreach ($properties as $ReflectionProperty) {
			$name = $ReflectionProperty->getName();
			$comment = $ReflectionProperty->getDocComment();
			$code = $this->getPropertyCode((string) $ReflectionProperty);
			
			$result .= "<md:prop>\n";
			$result .= $comment."\n";
			$result .= $code."\n";
			$result .= "</md:prop>\n\n";
		}

		if($result === '') {
			return '';
		}

		return "$level properties # Properties\n\n" . $result;
	}

	protected function getPropertyCode($str) {
		preg_match('/\[ (.+?) \]/', $str, $matches);
		return trim(str_replace('<default>', '', $matches[1])) . ';';
	}

	protected function getClassMethods($ReflectionClass, $params, $class_path, $class_name) {
		$access = ReflectionMethod::IS_PUBLIC;

		if($params['access'] == 1) {
			$access = $access | ReflectionMethod::IS_PROTECTED;
		}

		if($params['access'] == 2) {
			$access = $access | ReflectionMethod::IS_PROTECTED | ReflectionMethod::IS_PRIVATE;
		}

		$level = $params['level'] ? $params['level'] + 2 : 4;
		$level = str_repeat('#', $level);
		$result = '';
		$methods = $ReflectionClass->getMethods($access);

		foreach ($methods as $ReflectionMethod) {
			$name = $ReflectionMethod->getName();
			$comment = $ReflectionMethod->getDocComment();
			$code = $this->getCodeLine($class_path, $ReflectionMethod->getStartLine() - 1);
			$code = trim(rtrim(rtrim($code), '{'));

			if(strpos($code, "function $name") === false) {
				continue;
			}

			// $code = str_replace(array("function $name", '(  )'), array("$name", '( )'), $code);
			$code = str_replace('(  )', '( )', $code);

			$result .= "<md:method>\n";
			$result .= $comment."\n";
			$result .= $code."\n";
			$result .= "</md:method>\n\n";
		}

		if($result === '') {
			return '';
		}

		return "$level methods # Methods\n\n" . $result;
	}

	protected function getCodeLine($path, $line) {
		static $cache = [];

		if(!isset($cache[$path])) {
			$cache[$path] = file($this->sourcePath . '/' . $path);
		}

		if(isset($cache[$path][$line])) {
			return $cache[$path][$line];
		}

		return null;
	}
}