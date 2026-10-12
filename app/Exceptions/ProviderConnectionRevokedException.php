<?php

namespace App\Exceptions;

use InvalidArgumentException;

/** The authenticated provider grant changed before activity commit. */
final class ProviderConnectionRevokedException extends InvalidArgumentException
{
}
