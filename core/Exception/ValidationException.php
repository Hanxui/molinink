<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Exception;

/**
 * 用户输入不合规格 → 422。段位 1xxx(参数 / 校验)。
 * 字段级错误随 details 交出,外壳格式照既有规范。
 */
final class ValidationException extends MolinException implements HttpMappable
{
    private const CODE = 1001;

    /**
     * @param array<string, list<string>> $errors 字段名 => 该字段的错误消息列表
     * @param string $rule 不合格的那一条规矩的机器名(如 `double_extension`)。
     *        界面要把理由对上既有规范那种钉死的文案,不能靠猜消息字符串;不参与 API 错误体。
     */
    public function __construct(
        string $message = '提交的内容未通过校验',
        private readonly array $errors = [],
        private readonly string $rule = ''
    ) {
        parent::__construct($message, self::CODE);
    }

    public function rule(): string
    {
        return $this->rule;
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'validation_failed';
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
