<?php

use Clue\React\Quassel\Factory;
use Clue\React\Quassel\Client;
use Clue\React\Quassel\Io\Protocol;

require __DIR__ . '/../vendor/autoload.php';

$host = '127.0.0.1';
$user = array();
if (isset($argv[1])) { $host = $argv[1]; }

echo 'Server: ' . $host . PHP_EOL;

echo 'User name: ';
$user['name'] = trim(fgets(STDIN));

echo 'Password: ';
$user['password'] = trim(fgets(STDIN));

$loop = \React\EventLoop\Factory::create();
$factory = new Factory($loop);

echo '[1/5] Connecting' . PHP_EOL;
$factory->createClient($host)->then(function (Client $client) use ($loop, $user) {
    echo '[2/5] Connected, now initializing' . PHP_EOL;
    $client->writeClientInit();

    $client->on('data', function ($message) use ($client, $user, $loop) {
        if (isset($message[3]['IrcUsersAndChannels'])) {
            // print network information except for huge users/channels list
            $debug = $message;
            unset($debug[3]['IrcUsersAndChannels']);
            echo 'Debug (shortened): ' . json_encode($debug, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } else {
            echo 'Debug: ' . json_encode($message, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }

        $type = null;
        if (is_array($message) && isset($message['MsgType'])) {
            $type = $message['MsgType'];
        }

        if ($type === 'ClientInitAck') {
            if (!$message['Configured']) {
                echo '[3/5] Initialization done, but core is not configured yet, you may want to issue a setup call manually' . PHP_EOL;
                print_r($message['StorageBackends']);

                echo 'Hit enter to set-up server with defaults, otherwise cancel program now';
                fgets(STDIN);

                $client->writeCoreSetupData($user['name'], $user['password']);

                return;
            }
            echo '[3/5] Initialized, now logging in' . PHP_EOL;
            $client->writeClientLogin($user['name'], $user['password']);

            return;
        }
        if ($type === 'CoreSetupAck') {
            echo '[3/5] Core successfully configured, now logging in' . PHP_EOL;
            $client->writeClientLogin($user['name'], $user['password']);

            return;
        }
        if ($type === 'CoreSetupReject') {
            echo '[3/5] Failed to set up core! ' . $message['Error'] . PHP_EOL;
            $client->close();

            return;
        }
        if ($type === 'ClientLoginReject') {
            echo '[4/5] Failed to log in! ' . $message['Error'] . PHP_EOL;
            $client->close();

            return;
        }
        if ($type === 'ClientLoginAck') {
            echo '[4/5] Logged in, now waiting for session' . PHP_EOL;

            return;
        }
        if ($type === 'SessionInit') {
            echo '[5/5] Session initialized, we are ready to go!' . PHP_EOL;

            foreach ($message['SessionState']['NetworkIds'] as $nid) {
                var_dump('requesting Network for ' . $nid . ', this may take a few seconds');
                $client->writeInitRequest("Network", $nid);
            }

            foreach ($message['SessionState']['BufferInfos'] as $buffer) {
                if ($buffer['type'] === 2) { // type == 4 for user
                    var_dump('requesting IrcChannel for ' . $buffer['name']);
                    $client->writeInitRequest('IrcChannel', $buffer['network'] . '/' . $buffer['id']);
                }
            }

            // send heartbeat message every 30s to check dropped connection
            $timer = $loop->addPeriodicTimer(30.0, function () use ($client) {
                $client->writeHeartBeatRequest();
            });

            // stop heartbeat timer once connection closes
            $client->on('close', function () use ($loop, $timer) {
                $loop->cancelTimer($timer);
            });

            return;
        }

        // reply to heartbeat messages to avoid timing out
        if (isset($message[0]) && $message[0] === Protocol::REQUEST_HEARTBEAT) {
            //var_dump('heartbeat', $message[1]);
            $client->writeHeartBeatReply($message[1]);

            return;
        }
    });

    $client->on('error', 'printf');
    $client->on('close', function () {
        echo 'Connection closed' . PHP_EOL;
    });
})->then(null, function ($e) {
    echo $e;
});

$loop->run();