<?php

use cvweiss\redistools\RedisTimeQueue;

$pid = 1;
$max = 25;
$threadNum = 0;
for ($i = 0; $i < $max; ++$i) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        exit();
    }
    if ($pid == 0) {
        break;
    }
    ++$threadNum;
}

require_once '../init.php';

$collection = $threadNum < 5 ? 'Corporation' : 'Character';
$type = substr(strtolower($collection), 0, 4);
$field = strtolower($collection).'ID';
$collection = 'api'.$collection;

$minute = date('Hi');
$timeQueue = new RedisTimeQueue("zkb:{$type}s", 3600);

if (date('i') % 15 == 0 && ($threadNum == 4 || $threadNum == 5)) {
    $ids = $mdb->getCollection($collection)->distinct($field);
    foreach ($ids as $id) {
        $timeQueue->add($id);
    }
}

while ($minute == date('Hi')) {
    $id = (int) $timeQueue->next();
    if ($id > 0) {
        $api = $mdb->findDoc($collection, [$field => $id], ['lastFetched' => 1]);
        if ($api === null) {
            $timeQueue->remove($id);
            continue;
        }
        $count = $mdb->count("apis", ['keyID' => $api['keyID']]);
        if ($count == 0) {
            $mdb->remove($collection, $api);
            $timeQueue->remove($id);
            continue;
        }
        try {
            $result = KillmailParser::processCharApi($mdb, $apiServer, $type, $api);
            $cachedUntil = $result['cachedUntil'];
            $cachedTime = strtotime($cachedUntil);
            $mdb->set($collection, $api, ['lastFetched' => time()]);
            KillmailParser::updateApiRow($mdb, $collection, $api, 0);
            KillmailParser::extendApiTime($mdb, $timeQueue, $api, $type, $cachedTime);
        } catch (Exception $ex) {
            KillmailParser::updateApiRow($mdb, $collection, $api, $ex->getCode());
            $mdb->remove($collection, $api);
            $timeQueue->remove($id);
        }
        sleep(1);
    }
}