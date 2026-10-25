<?php

declare(strict_types=1);

namespace EasyLog\Services\Query;

/**
 * SQL token 流游标（解析辅助）
 */
final class SqlTokenCursor
{
    private int $pos = 0;

    public function __construct(private array $tokens)
    {
    }

    public function eof(): bool
    {
        return $this->pos >= count($this->tokens);
    }

    /** @return array{type:string,value:string}|null */
    public function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    /** @return array{type:string,value:string} */
    public function next(): array
    {
        $token = $this->peek();
        if ($token === null) {
            throw new SqlParseException('SQL 意外结束');
        }
        $this->pos++;
        return $token;
    }

    public function peekKeyword(string $keyword): bool
    {
        $t = $this->peek();
        return $t !== null
            && $t['type'] === 'ident'
            && strcasecmp($t['value'], $keyword) === 0;
    }

    public function expectKeyword(string $keyword): void
    {
        if (!$this->peekKeyword($keyword)) {
            $actual = $this->peek()['value'] ?? 'EOF';
            throw new SqlParseException("期望关键字 {$keyword}，实际 '{$actual}'");
        }
        $this->next();
    }

    public function peekOp(string $op): bool
    {
        $t = $this->peek();
        return $t !== null && $t['type'] === 'op' && $t['value'] === $op;
    }

    /** @param string|array<int,string> $ops */
    public function expectOp(string|array $ops): string
    {
        $allowed = (array) $ops;
        $t = $this->peek();
        if ($t === null || $t['type'] !== 'op' || !in_array($t['value'], $allowed, true)) {
            $actual = $t['value'] ?? 'EOF';
            throw new SqlParseException("期望操作符 " . implode('/', $allowed) . "，实际 '{$actual}'");
        }
        $this->next();
        return $t['value'];
    }

    public function expectIdent(): string
    {
        $t = $this->peek();
        if ($t === null || $t['type'] !== 'ident') {
            $actual = $t['value'] ?? 'EOF';
            throw new SqlParseException("期望字段名，实际 '{$actual}'");
        }
        $this->next();
        return $t['value'];
    }

    public function expectString(): string
    {
        $t = $this->peek();
        if ($t === null || $t['type'] !== 'string') {
            $actual = $t['value'] ?? 'EOF';
            throw new SqlParseException("期望字符串字面量，实际 '{$actual}'");
        }
        $this->next();
        return $t['value'];
    }

    public function expectValue(): string
    {
        $t = $this->peek();
        if ($t === null || !in_array($t['type'], ['string', 'number'], true)) {
            $actual = $t['value'] ?? 'EOF';
            throw new SqlParseException("期望值（字符串/数字），实际 '{$actual}'");
        }
        $this->next();
        return $t['value'];
    }

    public function expectNumber(): string
    {
        $t = $this->peek();
        if ($t === null || $t['type'] !== 'number') {
            $actual = $t['value'] ?? 'EOF';
            throw new SqlParseException("期望数字，实际 '{$actual}'");
        }
        $this->next();
        return $t['value'];
    }
}
