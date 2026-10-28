<?php

declare(strict_types=1);

namespace SuperKernel\Pool\Constants;

enum HealthCheckMode
{
    case OnBorrow;
    case OnReturn;
    case OnBorrowAndReturn;
    case Manual;

    public function onBorrow(): bool
    {
        return self::OnBorrow === $this || self::OnBorrowAndReturn === $this;
    }

    public function onReturn(): bool
    {
        return self::OnReturn === $this || self::OnBorrowAndReturn === $this;
    }
}
