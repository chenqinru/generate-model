<?php

declare(strict_types=1);

namespace Generate;

/**
 * @note   Service
 * @author CQR
 * @date   2021/4/6 17:04
 */
class Service extends \think\Service
{
    /**
     * @inheritDoc
     */
    public function boot(): void
    {
        $this->commands(['model' => Model::class]);
    }
}
