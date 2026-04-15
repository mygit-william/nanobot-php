<?php

namespace App\Hook;

/**
 * 宽松权限 Hook - 自动模式，只读操作自动通过
 */
class LenientPermissionHook extends PermissionCheckHook
{
    public function __construct(array $rules = [], ?string $configFile = null)
    {
        parent::__construct($rules, 'auto', $configFile);
    }
}