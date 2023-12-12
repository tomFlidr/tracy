<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Tracy;


/** @internal */
final class CodeHighlighter
{
	const DisplayLines = 15;

	/**
	 * Extract a snippet from the code, highlights the row and column, and adds line numbers.
	 * @param  string $html
	 * @param  int    $line
	 * @param  int    $column 0
	 * @return string
	 */
	public static function highlightLine($html, $line, $column = 0) {
		$html = str_replace("\r\n", "\n", $html);
		$lines = explode("\n", "\n" . $html);
		$startLine = max(1, min($line, count($lines) - 1) - (int) floor(self::DisplayLines * 2 / 3));
		$endLine = min($startLine + self::DisplayLines - 1, count($lines) - 1);
		$numWidth = strlen((string) $endLine);
		$openTags = $closeTags = [];
		$out = '';

		for ($n = 1; $n <= $endLine; $n++) {
			if ($n === $startLine) {
				$out = implode('', $openTags);
			}
			if ($n === $line) {
				$out .= implode('', $closeTags);
			}

			preg_replace_callback('#</?(\w+)[^>]*>#', function ($m) use (&$openTags, &$closeTags) {
				if ($m[0][1] === '/') {
					array_pop($openTags);
					array_shift($closeTags);
				} else {
					$openTags[] = $m[0];
					array_unshift($closeTags, "</$m[1]>");
				}
			}, $lines[$n]);

			if ($n === $line) {
				$s = strip_tags($lines[$n]);
				if ($column) {
					$s = preg_replace(
						'#((?:&.*?;|[^&]){' . ($column - 1) . '})(&.*?;|.)#u',
						'\1<span class="tracy-column-highlight">\2</span>',
						$s . ' ',
						1,
					);
				}
				$out .= sprintf("<span class='tracy-line-highlight'>%{$numWidth}s:    %s</span>\n%s", $n, $s, implode('', $openTags));
			} else {
				$out .= sprintf("<span class='tracy-line'>%{$numWidth}s:</span>    %s\n", $n, $lines[$n]);
			}
		}

		$out .= implode('', $closeTags);
		return $out;
	}


	/**
	 * Returns syntax highlighted source code.
	 * @param  string $code
	 * @param  int    $line
	 * @param  int    $column 0
	 * @return string
	 */
	public static function highlightPhp ($code, $line, $column = 0) {
		$html = self::highlightPhpCode($code);
		$html = self::highlightLine($html, $line, $column);
		return "<pre class='tracy-code'><div><code>{$html}</code></div></pre>";
	}


	/**
	 * @param  string $code
	 * @return string
	 */
	private static function highlightPhpCode ($code) {
		$code = str_replace("\r\n", "\n", $code);
		$code = preg_replace('#(__halt_compiler\s*\(\)\s*;).*#is', '$1', $code);
		$code = rtrim($code);
		$code = preg_replace('#/\*sensitive\{\*/.*?/\*\}\*/#s', '*****', $code);

		$last = $out = '';
		$tokens = [];
		if (PHP_VERSION_ID >= 80000) {
			$tokens = \PhpToken::tokenize($code);
		} else {
			$rawTokens = token_get_all($code);
			foreach ($rawTokens as $rawToken) {
				if (is_string($rawToken)) {
					$tokens[] = (object) [
						'id'	=> 0,
						'text'	=> $rawToken,
					];
				} else {
					$tokens[] = (object) [
						'id'	=> $rawToken[0],
						'text'	=> $rawToken[1],
					];
				}
			}
			$constants = [
				'T_COMMENT',
				'T_DOC_COMMENT',
				'T_INLINE_HTML',
				'T_OPEN_TAG',
				'T_OPEN_TAG_WITH_ECHO',
				'T_CLOSE_TAG',
				'T_LINE',
				'T_FILE',
				'T_DIR',
				'T_TRAIT_C',
				'T_METHOD_C',
				'T_FUNC_C',
				'T_NS_C',
				'T_CLASS_C',
				'T_STRING',
				'T_NAME_FULLY_QUALIFIED',
				'T_NAME_QUALIFIED',
				'T_NAME_RELATIVE',
				'T_LNUMBER',
				'T_DNUMBER',
				'T_VARIABLE',
				'T_ENCAPSED_AND_WHITESPACE',
				'T_CONSTANT_ENCAPSED_STRING',
				'T_WHITESPACE'
			];
			foreach ($constants as $constant) {
				if (!defined($constant))
					define($constant, 0);
			}
		}
		foreach ($tokens as $token) {
			switch ($token->id) {
				case T_COMMENT:
				case T_DOC_COMMENT:
				case T_INLINE_HTML:
					$next = 'tracy-code-comment';
					break;
				case T_OPEN_TAG:
				case T_OPEN_TAG_WITH_ECHO:
				case T_CLOSE_TAG:
				case T_LINE:
				case T_FILE:
				case T_DIR:
				case T_TRAIT_C:
				case T_METHOD_C:
				case T_FUNC_C:
				case T_NS_C:
				case T_CLASS_C:
				case T_STRING:
				case T_NAME_FULLY_QUALIFIED:
				case T_NAME_QUALIFIED:
				case T_NAME_RELATIVE:
					$next = '';
					break;
				case T_LNUMBER:
				case T_DNUMBER:
					$next = 'tracy-dump-number';
					break;
				case T_VARIABLE:
					$next = 'tracy-code-var';
					break;
				case T_ENCAPSED_AND_WHITESPACE:
				case T_CONSTANT_ENCAPSED_STRING:
					$next = 'tracy-dump-string';
					break;
				case T_WHITESPACE:
					$next = $last;
					break;
				default:
					$next = 'tracy-code-keyword';
					break;
			}
			/*$next = match ($token->id) {
				T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML => 'tracy-code-comment',
				T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_LINE, T_FILE, T_DIR, T_TRAIT_C, T_METHOD_C, T_FUNC_C, T_NS_C, T_CLASS_C,
				T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE => '',
				T_LNUMBER, T_DNUMBER => 'tracy-dump-number',
				T_VARIABLE => 'tracy-code-var',
				T_ENCAPSED_AND_WHITESPACE, T_CONSTANT_ENCAPSED_STRING => 'tracy-dump-string',
				T_WHITESPACE => $last,
				default => 'tracy-code-keyword',
			};*/

			if ($last !== $next) {
				if ($last !== '') {
					$out .= '</span>';
				}
				$last = $next;
				if ($last !== '') {
					$out .= "<span class='$last'>";
				}
			}

			$out .= strtr($token->text, ['<' => '&lt;', '>' => '&gt;', '&' => '&amp;', "\t" => '    ']);
		}
		if ($last !== '') {
			$out .= '</span>';
		}
		return $out;
	}


	/**
	 * Returns syntax highlighted source code to Terminal.
	 * @param  string $code
	 * @param  int    $line
	 * @param  int    $column 0
	 * @return string
	 */
	public static function highlightPhpCli ($code, $line, $column = 0) {
		return Helpers::htmlToAnsi(
			self::highlightPhp($code, $line, $column),
			[
				'string' => '1;32',
				'number' => '1;32',
				'code-comment' => '1;30',
				'code-keyword' => '1;37',
				'code-var' => '1;36',
				'line' => '1;30',
				'line-highlight' => "1;37m\e[41",
			]
		);
	}
}