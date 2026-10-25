<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Transfer;

use Molin\Http\Request;
use Molin\Option\Options;
use Molin\Security\Csrf;
use Molin\User\Auth;

/**
 * 导入与导出两侧共用的小零件:状态根、体积文案、扩展名、导入字节上限、CSRF 闸、导出文件名形状。
 * 都不带状态,故一律静态 —— 做成对象只是多传一层依赖。
 */
final class Toolkit
{
    /** 落地的导出文件名,下载令牌与删除都据此校验(防穿越) */
    public const EXPORT_FILENAME = '#\Aexport-molin-\d{14}\.(json|xml)\z#';

    /** 站点没配「上传大小上限」时,导入文件按这个数当顶(与《27》的默认值同口径) */
    private const DEFAULT_UPLOAD_MB = 10;

    /**
     * 状态根:导出目录与下载令牌都挂在它下面。测试或隔离运行用 MOLIN_STATE_ROOT 指到仓库外。
     * 每次现读环境变量 —— 用例是在构造控制器之后才换根的,缓存住就指不上了。
     */
    public static function stateRoot(): string
    {
        $state = getenv('MOLIN_STATE_ROOT');

        return is_string($state) && $state !== '' ? rtrim($state, '/\\') : MOLIN_ROOT;
    }

    public static function sizeText(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : round($bytes / 1024, 1) . ' KB';
    }

    public static function extensionOf(string $name): string
    {
        $lower = strtolower($name);

        if (str_ends_with($lower, '.tar.gz')) {
            return 'tar.gz';
        }

        $dot = strrpos($lower, '.');

        return $dot === false ? '' : substr($lower, $dot + 1);
    }

    /**
     * 导入文件的字节上限:与站点设置里的「上传大小上限」同源(`max_upload_mb`)。
     * 另开一个常量只会让两处数字各自跑偏,管理改一处时另一处还是老样子。
     */
    public static function importLimitBytes(Options $options): int
    {
        $mb = (int) $options->get('max_upload_mb', (string) self::DEFAULT_UPLOAD_MB);

        return ($mb > 0 ? $mb : self::DEFAULT_UPLOAD_MB) * 1048576;
    }

    /** 后台写动作的 CSRF 闸 */
    public static function csrfOk(Request $request): bool
    {
        $session = Auth::session();

        return $session !== null && Csrf::check($session, $request->input(Csrf::FIELD));
    }
}
