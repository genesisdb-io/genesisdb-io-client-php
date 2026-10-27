<?php

declare(strict_types=1);

namespace GenesisDB;

/** The token was missing or wrong (401), or valid but not permitted for this operation (403). */
final class AuthenticationException extends GenesisDBException
{
}
