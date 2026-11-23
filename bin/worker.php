<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
try { $result=(new Publisher(app()))->run(); if ($result['handled']>0) echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL; }
catch (Throwable $e) { fwrite(STDERR,'Publiceringsjobbet stoppades: '.$e->getMessage().PHP_EOL); exit(1); }
