<?php
declare(strict_types=1);
class WorldController
{
    public static function __callStatic(string $method, array $args): void
    {
        http_response_code(501);
        echo json_encode(['error' => 'Not yet implemented.', 'code' => 'NOT_IMPLEMENTED']);
    }
}
