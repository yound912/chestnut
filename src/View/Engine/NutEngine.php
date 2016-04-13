<?php
namespace Chestnut\View\Engine;

use Chestnut\View\Filter\Filter;

/**
 * @author Liyang Zhang <zhangliyang@zhangliyang.name>
 */
class NutEngine extends Engine {
	protected $contentTag = ['{{', '}}'];
	protected $methodTag = ['{@', '}'];
	protected $commitTag = ['{{--', '--}}'];

	protected $compileQueue = [
		"compileCommit",
		"compileMethod",
		"compileEcho",
	];

	protected $hasLayout = false;

	protected $switchStack = [];

	public function render($content) {
		return $this->compile($content);
	}

	private function compile($content) {
		foreach ($this->compileQueue as $compiler) {
			$content = $this->$compiler($content);
		}

		if ($this->hasLayout) {
			$content .= "\n\n<?php \$this->renderLayout(\$this->data); ?>";
		}

		return $content;
	}

	private function compileCommit($content) {
		return preg_replace("/{$this->commitTag[0]}\s*.*?\s*{$this->commitTag[1]}/s", '<?php /*$1*/?>', $content);
	}

	private function compileMethod($content) {
		$callback = function ($m) {
			return $this->{'compile' . ucfirst($m[1])}(array_slice($m, 2));
		};

		return preg_replace_callback("/{$this->methodTag[0]}(.+?)(?:\:(.+?))?{$this->methodTag[1]}/s", $callback, $content);
	}

	private function compileEcho($content) {
		$callback = function ($m) {
			return $this->compileContent($m);
		};

		return preg_replace_callback("/{$this->contentTag[0]}\s*(.+?)\s*{$this->contentTag[1]}/s", $callback, $content);
	}

	private function compileLayout($value) {
		$this->hasLayout = true;

		return "<?php \$this->layout('{$value[0]}'); ?>";
	}

	private function compileSection($value) {
		$this->currentSection = $value[0];

		return "<?php \$this->sectionStart('{$value[0]}'); ?>";
	}

	private function compileEndsection($value) {
		return "<?php \$this->sectionEnd(); ?>";
	}

	private function compileSet($value) {
		$value = explode(' = ', $value[0]);

		$set = $this->convertParameter($value[0]);
		$target = $this->convertParameter($value[1]);

		return "<?php if(!isset({$set})) { {$set} = {$target}; } ?>";
	}

	private function compileReset($value) {
		$value = explode(' = ', $value[0]);

		$set = $this->convertParameter($value[0]);
		$target = $this->convertParameter($value[1]);

		return "<?php {$set} = {$target}; ?>";
	}

	private function compileShow() {
		return "<?php \$this->showSection(); ?>";
	}

	private function compileContent($m) {
		list($type, $match, $filter) = $this->analysisContent($m[1]);

		$result = $this->{$type}($match);

		if ($filter) {
			$filter = explode(' ', trim($filter));

			$result = $this->processFilter($filter, $result);
		}

		return "<?php echo {$result} ?>";
	}

	private function compileExpression($match) {
		$result = '';

		foreach ($match as $expression) {
			$result .= $this->convertParameter($expression);
		}

		return $result;
	}

	private function compileFunction($match) {
		list(, $function, $parameters) = $match;
		$parameter = [];

		foreach ($parameters as $param) {
			$parameter[] = $this->analysisAndCompileParameter(trim($param));
		}

		return $function . "(" . join($parameter, ', ') . ")";
	}

	private function compileTernary($match) {
		$type = $match[2];

		$operation = $this->analysisAndCompileParameter($match[1]);
		$true = $this->analysisAndCompileParameter($match[3]);
		$false = $this->analysisAndCompileParameter($match[4]);

		switch ($type) {
		case '&&':
		case 'and':
			return "{$operation} ? {$true} : {$false}";
		case '?':
			if ($true != "''") {
				return "{$operation} ? {$true} : {$false}";
			}

			list($true, $false) = [$false, $true];
		case 'or':
		case '||':
			return "isset({$operation}) ? {$true} : {$false}";
		}
	}

	private function compileParameter($match) {
		$result = '';

		foreach ($match as $parameter) {
			$result .= $this->convertParameter($parameter);
		}

		return $result;
	}

	private function analysisAndCompileParameter($string) {
		list(, $match) = $this->analysisContent($string);

		if ($match) {
			return $this->compileParameter($match);
		}

		return "''";
	}

	private function convertParameter($parameter) {
		if (is_numeric(trim($parameter[3])) || empty(trim($parameter[3])) || preg_match('/^\$/', trim($parameter[3]))) {
			return !empty($parameter[1])
			? $parameter[0]
			: $parameter[3];
		}

		if (empty($parameter[1]) && preg_match('/^\[.+\]$/', $parameter[0])) {
			return "[" . $this->analysisAndCompileParameter($parameter[0]) . "]";
		}

		if ($parameter[1] === '.' && !preg_match('/^[\'\"]|[\'\"]$/', $parameter[3])) {
			return "->" . $parameter[2];
		}

		if (in_array($parameter[1], ['-', '+', '*', '/', '%'])) {
			return $parameter[1] . ' ' . $this->analysisAndCompileParameter($parameter[3]);
		}

		return !preg_match('/^[\'\"]|[\'\"]$/', $parameter[3])
		? "\$" . $parameter[3]
		: $parameter[0];

	}

	private function analysisContent($string) {
		if (strpos($string, '|')) {
			list($string, $filter) = explode('|', $string);
		}

		if (!isset($filter)) {
			$filter = '';
		}

		if (preg_match('/(\w+?)\((.*)\)/', $string, $match)) {
			$match[2] = explode(',', $match[2]);

			return ['compileFunction', $match, $filter];
		}

		if (preg_match('/([^?]*)(\?| or | and |\|{2}|&{2})([^:]*)(?::)?([^;]*)/', $string, $match)) {
			return ['compileTernary', $match, $filter];
		}

		if (preg_match_all('/([.]?)(\[?([^.]+)\]?)/', $string, $match, PREG_SET_ORDER)) {
			return ['compileParameter', $match, $filter];
		}

		if (preg_match_all('/([-\+\/\*\%]?)(\[?([^-\+\/\*\%]+)\]?)/', $string, $match, PREG_SET_ORDER)) {
			return ['compileExpression', $match, $filter];
		}

		return ['', false, $filter];
	}

	private function processFilter($filters, $object) {
		$filter = new Filter($filters);

		return $filter->compile($object);
	}

	private function compileFor($m) {

		if (!list($key, $targer) = explode(' in ', $m[0])) {
			return '<?php /* Cannot compile for loop: ' . $m[0] . ' /* ?>';
		}

		$target = $this->analysisAndCompileParameter($targer);

		if (strpos($key, ',')) {
			list($key, $value) = explode(',', $key);

			$value = $this->analysisAndCompileParameter($value);
		}

		$key = $this->analysisAndCompileParameter($key);

		return isset($value)
		? '<?php if(' . $target . ' && !empty(' . $target . ')) foreach(' . $target . ' as ' . $key . $value . ') { ?>'
		: '<?php if(' . $target . ' && !empty(' . $target . ')) foreach(' . $target . ' as ' . $key . ') { ?>';
	}

	private function compileEndfor($value) {
		return "<?php } ?>";
	}

	private function compileInclude($value) {
		return "<?php echo \$this->factory->make('{$value[0]}')->render(); ?>";
	}

	private function compileIf($value) {
		return "<?php if({$value[0]}) { ?>";
	}

	private function compileElseif($value) {
		return "<?php } elseif({$value[0]}) { ?>";
	}

	private function compileElse() {
		return "<?php } else { ?>";
	}

	private function compileEndif() {
		return '<?php } ?>';
	}

	private function compileSwitch($value) {

		$switch = $this->convertParameter($value[0]);

		$this->switchStack['object'] = $switch;
	}

	private function compileCase($value) {
		if (isset($this->switchStack['length']) && isset($this->switchStack['object'])) {

			return "<?php } if({$this->switchStack['object']} == {$value[0]}) {?>";
		}

		if (!isset($this->switchStack['length']) && isset($this->switchStack['object'])) {
			$this->switchStack['length'] = 1;

			return "<?php if({$this->switchStack['object']} == {$value[0]}) {?>";
		}
	}
	private function compileEndswitch() {
		return "<?php } ?>";
	}

	private function compileEnd($m) {
		return "<?php } ?>";
	}

}
