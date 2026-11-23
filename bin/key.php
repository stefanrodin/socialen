<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
echo base64_encode(random_bytes(32)),PHP_EOL;
