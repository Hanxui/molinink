<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Exception;

/**
 * 安装写到一半失败。比 ConfigException 多带三样给向导失败屏用:
 * 已完成步数、失败原因原文、回滚结果说明。CLI 不关心这些,照旧读 message 即可。
 */
final class InstallFailedException extends ConfigException
{
    public function __construct(
        private readonly string $reason,
        private readonly int $completedSteps,
        private readonly string $rollback
    ) {
        parent::__construct('安装失败:' . $reason . '。已回滚' . $rollback);
    }

    /** 七项执行清单里已完成了几项(0–7);失败发生在下一项 */
    public function completedSteps(): int
    {
        return $this->completedSteps;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function rollback(): string
    {
        return $this->rollback;
    }

    /**
     * 回滚没清干净时(比如 config.php 被占用删不掉)给出的一句警告;干净收场返回空串。
     * 失败屏拿它在「已一并回滚」的标准文案下补一行实情 —— 不能让页面替没说准的事打包票。
     */
    public function rollbackTrouble(): string
    {
        $at = strpos($this->rollback, '但有项没回滚干净');

        return $at === false ? '' : substr($this->rollback, $at);
    }
}
