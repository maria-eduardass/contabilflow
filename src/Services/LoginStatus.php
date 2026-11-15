<?php

declare(strict_types=1);

namespace ContabilFlow\Services;

enum LoginStatus
{
    case Success;
    case InvalidCredentials;
    case Locked;
}
