<?php

/**
 * EDAS Lite (EasyDomainAPI System Lite)
 *
 * Copyright (C) 2026 华阳互联
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Edas\Lite\Exceptions;

use RuntimeException;

/**
 * 适配器尚未实现该能力时抛出。
 */
final class NotImplementedException extends RuntimeException
{
    public static function forAdapter(string $adapter, string $method): self
    {
        return new self(sprintf('%s::%s 尚未实现（待官方 API 文档确认后实现）', $adapter, $method));
    }
}
