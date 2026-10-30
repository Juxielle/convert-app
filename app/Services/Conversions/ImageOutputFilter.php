<?php

namespace App\Services\Conversions;

final class ImageOutputFilter extends \php_user_filter
{
    public static bool $exceeded = false;

    private int $written = 0;

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->written += $bucket->datalen;
            if ($this->written > $this->params) {
                self::$exceeded = true;

                return PSFS_ERR_FATAL;
            }
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
