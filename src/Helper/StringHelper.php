<?php

namespace Unlooped\Helper;

use Exception;
use Ramsey\Uuid\Uuid;
use Stringy\StaticStringy;
use voku\helper\UTF8;

class StringHelper extends StaticStringy
{

    public static function randomString($length = 16): string
    {
        return UTF8::get_random_string($length);
    }

    /**
     * @throws Exception
     */
    public static function getUuid(): string
    {
        return Uuid::uuid4()->toString();
    }

}
