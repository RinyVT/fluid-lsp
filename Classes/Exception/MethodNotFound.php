<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Exception;

use LogicException;

final class MethodNotFound extends LogicException implements FluidLspException
{
}
