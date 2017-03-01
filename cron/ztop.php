#!/usr/bin/php5
<?php

set_error_handler(function($errno, $errstr, $errfile, $errline, array $errcontext) {
    // error was suppressed with the @-operator
    if (0 === error_reporting()) {
        return false;
    }

    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});


use cvweiss\redistools\RedisTtlCounter;
use cvweiss\redistools\RedisTimeQueue;

require_once '../init.php';

$redisQueues = [];
$priorKillLog = 0;

$deltaArray = [];

$hour = date('H');
while ($hour == date('H')) {
    ob_start();
    $infoArray = [];

    $isHardened = $redis->ttl('zkb:isHardened');
    if ($isHardened > 0) {
        addInfo('seconds remaining in Cached/Hardened Mode', $isHardened);
        addInfo('', 0);
    }

    $queues = $redis->sMembers('queues');
    foreach ($queues as $queue) {
        $redisQueues[$queue] = true;
    }
    ksort($redisQueues);

    foreach ($redisQueues as $queue => $v) {
        addInfo($queue, $redis->lLen($queue));
    }

    addInfo('', 0);

    addInfo('Kills remaining to be fetched.', $mdb->count('crestmails', ['processed' => false]));
    $killsLastHour = new RedisTtlCounter('killsLastHour', 3600);
    addInfo('Kills added last hour', $killsLastHour->count());
    $totalKills = $redis->get('zkb:totalKills');
    $topKillID = $mdb->findField('killmails', 'killID', ['cacheTime' => 60], ['killID' => -1]);
    addInfo('Total Kills (' . number_format(($totalKills / $topKillID) * 100, 1) . '%)', $totalKills);
    addInfo('Top killID', $topKillID);

    addInfo('', 0);
    $nonApiR = new RedisTtlCounter('ttlc:nonApiRequests', 300);
    addInfo('User requests in last 5 minutes', $nonApiR->count());
    $uniqueUsers = new RedisTtlCounter('ttlc:unique_visitors', 300);
    addInfo("Unique user IP's in last 5 minutes", $uniqueUsers->count());

    addInfo('', 0);
    $apiR = new RedisTtlCounter('ttlc:apiRequests', 300);
    addInfo('API requests in last 5 minutes', $apiR->count());
    $visitors = new RedisTtlCounter('ttlc:visitors', 300);
    addInfo('Unique IPs in last 5 minutes', $visitors->count());
    $requests = new RedisTtlCounter('ttlc:requests', 300);
    addInfo('Requests in last 5 minutes', $requests->count());

    $crestSuccess = new RedisTtlCounter('ttlc:CrestSuccess', 300);
    addInfo('Successful CREST calls in last 5 minutes', $crestSuccess->count(), false);
    $crestFailure = new RedisTtlCounter('ttlc:CrestFailure', 300);
    addInfo('Failed CREST calls in last 5 minutes', $crestFailure->count(), false);
    $esiSuccess = new RedisTtlCounter('ttlc:esiSuccess', 300);
    addInfo('Successful ESI calls in last 5 minutes', $esiSuccess->count(), false);
    $esiFailure = new RedisTtlCounter('ttlc:esiFailure', 300);
    addInfo('Failed ESI calls in last 5 minutes', $esiFailure->count(), false);
    $authSuccess = new RedisTtlCounter('ttlc:AuthSuccess', 300);
    addInfo('Successful SSO calls in last 5 minutes', $authSuccess->count(), false);
    $authFailure = new RedisTtlCounter('ttlc:AuthFailure', 300);
    addInfo('Failed SSO calls in last 5 minutes', $authFailure->count(), false);
    $xmlSuccess = new RedisTtlCounter('ttlc:XmlSuccess', 300);
    addInfo('Successful XML calls in last 5 minutes', $xmlSuccess->count(), false);
    $xmlFailure = new RedisTtlCounter('ttlc:XmlFailure', 300);
    addInfo('Failed XML calls in last 5 minutes', $xmlFailure->count(), false);

    $esiChars = new RedisTimeQueue("tqApiESI", 3600);
    $ssoCorps = new RedisTimeQueue("zkb:ssoCorps", 3600);
    addInfo('', 0, false);
    addInfo('Character ESI/SSO KillLogs to check', $esiChars->pending(), false);
    addInfo('Character ESI/SSO RefreshTokens', $esiChars->size(), false);
    addInfo('Corporation XML/SSO to check', $ssoCorps->pending(), false);
    addInfo('Corporation XML/SSO RefreshTokens', $ssoCorps->size(), false);

    addInfo('', 0, false);
    addInfo('Total Characters', $redis->get("zkb:totalChars"), false);
    addInfo('Total Corporations', $redis->get("zkb:totalCorps"), false);
    addInfo('Total Alliances', $redis->get("zkb:totalAllis"), false);

    addInfo('', 0, false);
    $sponsored = Mdb::group("sponsored", [], ['entryTime' => ['$gte' => $mdb->now(86400 * -7)]], [], 'isk', ['iskSum' => -1]);
    $sponsored = array_shift($sponsored);
    $sponsored = Util::formatIsk($sponsored['iskSum']);
    $balance = Util::formatIsk((double) $mdb->findField("payments", "balance", ['refTypeID' => "10"], ['_id' => -1]));
    addInfo('Sponsored Killmails (inflated)', $sponsored, false, false);
    addInfo('Wallet Balance', $balance, false, false);

    addInfo('', 0, true);
    addInfo('Load Counter', $redis->get("zkb:load"));
    addinfo("Reinforced Mode", (int) $redis->get("zkb:reinforced"));

    $info = $redis->info();
    $mem = $info['used_memory_human'];

    $stats = $mdb->getDb()->command(['dbstats' => 1]);
    $dataSize = number_format(($stats['dataSize'] + $stats['indexSize']) / (1024 * 1024 * 1024), 2);
    $storageSize = number_format(($stats['storageSize'] + $stats['indexStorageSize']) / (1024 * 1024 * 1024), 2);

    $memory = getSystemMemInfo();
    $memTotal = number_format($memory['MemTotal'] / (1024 * 1024), 2);
    $memUsed = number_format(($memory['MemTotal'] - $memory['MemFree'] - $memory['Cached']) / (1024 * 1024), 2);

    $maxLen = 0;
    foreach ($infoArray as $i) {
        foreach ($i as $key => $value) {
            $maxLen = max($maxLen, strlen("$value"));
        }
    }

    $cpu = exec("top -d 0.5 -b -n2 | grep \"Cpu(s)\"| tail -n 1 | awk '{print $2 + $4}'");
    $output = [];
    $output[] = exec('date')." CPU: $cpu% Load: ".Load::getLoad()."  Memory: ${memUsed}G/${memTotal}G  Redis: $mem  TokuDB: ${storageSize}G / ${dataSize}G\n";

    $leftCount = 1;
    $rightCount = 1;
    $line = "                                                                                                               ";
    $line = str_repeat(" ", 80);
    foreach ($infoArray as $i) {
        $num = trim($i['num']);
        $text = trim($i['text']);
        $lr = $i['lr'];
        $start = $lr == true ? 15 : 70;
        $leftCount = $lr == true ? $leftCount + 1 : $leftCount;
        $rightCount = $lr == false ? $rightCount + 1 : $rightCount;

        $lineIndex = $lr == true ? $leftCount : $rightCount;
        $nextLine = isset($output[$lineIndex]) ? $output[$lineIndex] : $line;

        if (strlen($text) != '') {
            $nextLine = substr_replace($nextLine, $num, ($start - strlen($num)), strlen($num));
            $nextLine = substr_replace($nextLine, $text, $start + 2, strlen($text));
        }
        $output[$lineIndex] = $nextLine;
    }
    foreach($output as $line) echo "$line\n";
    $output = ob_get_clean();
    file_put_contents("${baseDir}/public/ztop.txt", $output);
    sleep(3);
}

function addInfo($text, $number, $left = true, $format = true)
{
    global $infoArray, $deltaArray;
    $prevNumber = (double) @$deltaArray[$text];
    $delta = $number - $prevNumber;
    $deltaArray[$text] = $number;

    if ($delta > 0) {
        $delta = "+$delta";
    }
    $dtext = $delta == 0 ? '' : "($delta)";
    $num = $format ? number_format($number, 0) : $number;
    $infoArray[] = ['text' => "$text $dtext", 'num' => $num, 'lr' => $left];
}

function getSystemMemInfo()
{
    $data = explode("\n", file_get_contents('/proc/meminfo'));
    $meminfo = array();
    foreach ($data as $line) {
        if ($line == '') {
            continue;
        }
        list($key, $val) = explode(':', $line);
        $meminfo[$key] = trim($val);
    }

    return $meminfo;
}
