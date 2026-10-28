<?php

declare(strict_types=1);

namespace RinyVT\FluidLsp\Document;

enum ContextKind
{
    case TagName;
    case InlineName;
    case TagAttribute;
    case InlineArgument;
}
