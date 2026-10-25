<?php

declare(strict_types=1);

namespace EasyLog\Services\Query;

/**
 * SQL 词法分析器：输出 token 流（ident / string / number / op）。
 *
 * 支持：单引号字符串（'' 转义）、双引号标识符、数字（含小数/负号）、
 * 运算符 >= <= != <> = > < ( ) , * ;
 */
final class SqlTokenizer
{
    private const OPERATORS = ['>=', '<=', '!=', '<>', '=', '>', '<', '(', ')', ',', '*', ';'];

    public function __construct(private string $sql)
    {
    }

    /**
     * @return array<int,array{type:string,value:string}>
     * @throws SqlParseException
     */
    public function tokenize(): array
    {
        $tokens = [];
        $len = strlen($this->sql);
        $i = 0;

        while ($i < $len) {
            $ch = $this->sql[$i];

            if (ctype_space($ch)) {
                $i++;
                continue;
            }

            // 字符串字面量（'' 转义）
            if ($ch === "'") {
                $i++;
                $buf = '';
                while ($i < $len) {
                    if ($this->sql[$i] === "'") {
                        if ($i + 1 < $len && $this->sql[$i + 1] === "'") {
                            $buf .= "'";
                            $i += 2;
                            continue;
                        }
                        break;
                    }
                    $buf .= $this->sql[$i];
                    $i++;
                }
                if ($i >= $len) {
                    throw new SqlParseException('字符串缺少闭合引号');
                }
                $i++; // 闭合引号
                $tokens[] = ['type' => 'string', 'value' => $buf];
                continue;
            }

            // 双引号标识符
            if ($ch === '"') {
                $end = strpos($this->sql, '"', $i + 1);
                if ($end === false) {
                    throw new SqlParseException('标识符缺少闭合双引号');
                }
                $tokens[] = ['type' => 'ident', 'value' => substr($this->sql, $i + 1, $end - $i - 1)];
                $i = $end + 1;
                continue;
            }

            // 数字（含小数；负号并入数字以支持 range 负值）
            if (ctype_digit($ch) || ($ch === '-' && $i + 1 < $len && (ctype_digit($this->sql[$i + 1]) || $this->sql[$i + 1] === '.'))) {
                $j = $i + 1;
                while ($j < $len && (ctype_digit($this->sql[$j]) || $this->sql[$j] === '.')) {
                    $j++;
                }
                $tokens[] = ['type' => 'number', 'value' => substr($this->sql, $i, $j - $i)];
                $i = $j;
                continue;
            }

            // 标识符 / 关键字
            if (ctype_alpha($ch) || $ch === '_') {
                $j = $i + 1;
                while ($j < $len && (ctype_alnum($this->sql[$j]) || $this->sql[$j] === '_')) {
                    $j++;
                }
                $tokens[] = ['type' => 'ident', 'value' => substr($this->sql, $i, $j - $i)];
                $i = $j;
                continue;
            }

            // 运算符（先长后短）
            $matched = null;
            foreach (self::OPERATORS as $op) {
                if (substr($this->sql, $i, strlen($op)) === $op) {
                    $matched = $op;
                    break;
                }
            }
            if ($matched === null) {
                throw new SqlParseException("无法识别的字符 '{$ch}'");
            }
            $tokens[] = ['type' => 'op', 'value' => $matched];
            $i += strlen($matched);
        }

        return $tokens;
    }
}
