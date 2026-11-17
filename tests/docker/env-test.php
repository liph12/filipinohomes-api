<?php
// Checks that the entrypoint expanded FILIPINOHOMES_ENV_JSON into environment variables and that
// Laravel's config cache, built at start, uses them. Run inside the image by
// tests/docker/smoke-test.sh, which sets the JSON and LOG_CHANNEL=stderr; exits non-zero on failure.

function check(bool $ok, string $what): void
{
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$what}\n");
        exit(1);
    }
}

$expect = [
    'APP_NAME' => 'Filipino "Homes" $HOME ${APP_ENV} #x \\n',
    'MULTI' => "line1\nline2",
    'EMPTY' => '',
    'UNICODE' => 'Señor ★',
    'LOG_CHANNEL' => 'stderr', // set with docker run -e: the environment wins over the secret
    'AWS_DEFAULT_REGION' => 'us-east-1', // also set with -e, like the ECS agent does: the secret wins
];
foreach ($expect as $name => $value) {
    check(getenv($name) === $value, "{$name} from the secret");
}
check(getenv('NOVALUE') === false, 'a null value sets nothing');
check(getenv('FILIPINOHOMES_ENV_JSON') === false, 'FILIPINOHOMES_ENV_JSON is removed');

$config = require '/app/bootstrap/cache/config.php';
check($config['app']['name'] === $expect['APP_NAME'], 'the config cache has the secret\'s APP_NAME');
check($config['logging']['default'] === 'stderr', 'the config cache keeps the environment\'s LOG_CHANNEL');

echo "ok settings from FILIPINOHOMES_ENV_JSON, environment wins, config cache built from them\n";
