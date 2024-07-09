<?php

use cvweiss\redistools\RedisCache;

class Price
{
    public static function getItemPrice($typeID, $kmDate, $fetch = false, $recalc = false)
    {
        global $mdb, $redis;
        $typeID = (int) $typeID;

        $categoryID = Info::getInfoField("typeID", $typeID, "categoryID");
        if ($categoryID == 91) return 0.01; // Skins are worth nothing

        if ($kmDate == null) {
            $kmDate = date('Y-m-d H:i');
        }

        $price = static::getFixedPrice($typeID, $kmDate);
        if ($price !== null) {
            return $price;
        }
        $price = static::getCalculatedPrice($typeID, $kmDate);
        if ($price !== null) {
            return $price;
        }

        if ($categoryID == 66) { // "Build" all rigs
            $price = Build::getItemPrice($typeID, $kmDate, true, true);
            if ($price > 0.01) return $price;
        }

        // Have we fetched prices for this typeID today?
        $today = date('Ymd', time() - 3601); // Back one hour because of CREST cache
        $fetchedKey = "RC:tq:pricesFetched:$today";
        if ($fetch === true) {
            if ($redis->hGet($fetchedKey, $typeID) != true) {
                static::getCrestPrices($typeID);
            }
            $redis->hSet($fetchedKey, $typeID, true);
            $redis->expire($fetchedKey, 86400);
        }

        // Have we already determined the price for this item at this date?
        $date = date('Y-m-d', strtotime($kmDate) - 3601); // Back one hour because of CREST cache
        $priceKey = "tq:prices:$date";
        $price = $redis->hGet($priceKey, $typeID);
        if ($price != null && $recalc == false) {
            return $price;
        }

        $marketHistory = $mdb->findDoc('prices', ['typeID' => $typeID]);
        $mHistory = $marketHistory;
        unset($marketHistory['_id']);
        unset($marketHistory['typeID']);
        if ($marketHistory == null) {
            $marketHistory = [];
        }
        krsort($marketHistory);

        $maxSize = 34;
        $useTime = strtotime($date);
        $iterations = 0;
        $priceList = [];
        do {
            $useDate = date('Y-m-d', $useTime);
            $price = @$marketHistory[$useDate];
            if ($price != null) {
                $priceList[] = $price;
            }
            $useTime = $useTime - 86400;
            ++$iterations;
        } while (sizeof($priceList) < $maxSize && $iterations < sizeof($marketHistory));

        asort($priceList);
        if (sizeof($priceList) == $maxSize) {
            // remove 2 endpoints from each end, helps fight against wild prices from market speculation and scams
            $priceList = array_splice($priceList, 2, $maxSize - 2);
            $priceList = array_splice($priceList, 0, $maxSize - 4);
        } elseif (sizeof($priceList) > 6) {
            $priceList = array_splice($priceList, 0, sizeof($priceList) - 2);
        }
        if (sizeof($priceList) == 0) {
            $priceList[] = 0.01;
        }

        $total = 0;
        foreach ($priceList as $price) {
            $total += $price;
        }
        $avgPrice = round($total / sizeof($priceList), 2);
        
        // Don't have a decent price? Let's try to build it!
        if ($avgPrice <= 0.01) $avgPrice = Build::getItemPrice($typeID, $date, true);
        $datePrice = isset($mHistory[$date]) ? $mHistory[$date] : 0;
        if ($datePrice > 0 && $datePrice < $avgPrice) $avgPrice = $datePrice;

        $redis->hSet($priceKey, $typeID, $avgPrice);
        $redis->expire($priceKey, 86400);

        return $avgPrice;
    }

    protected static function getFixedPrice($typeID, $date)
    {
        // Some typeID's have hardcoded prices
        switch ($typeID) {
            case 12478: // Khumaak
            case 44265: // Victory Firework
            case 44264: // Pulsar Flare Firework

            case 34558: // Drifter elements
            case 34556:
            case 34560:
            case 36902:
            case 34557:
                return 0.01;
		
            // Items that have been determined to be obnoxiously market
            // manipulated will go here
            case 55511: // Vorton Arc Extension
                return 30_000_000;
            
            // Faction Capitals
                // Guristas
            case 45645: // Loggerhead
                return 35_000_000_000; // 35b
            case 45647: // Caiman
                return 60_000_000_000; // 60b 
            case 45649: // Komodo
                return 200_000_000_000; // 200b
            
            // Blood Raiders
            case 42243: // Chemosh
                return 70_000_000_000; // 70b
            case 42242: // Dagon
                return 35_000_000_000; // 35b
            case 42241: // Molok
                if ($date <= "2019-07-01") return 350_000_000_000; // 350b 
                return 650_000_000_000;
            
            // Serpentis
            case 42124: // Vehement
                return 55_000_000_000; // 55b
            case 42125: // Vendetta
                if ($date <= "2024-07-09") return 120_000_000_000; // 120b
                return 210_000_000_000; // 210b
            case 42126: // Vanquisher
                return 650_000_000_000; // 650b
            
            // Angel
            case 78576: // Azariel
                return 750_000_000_000; // 750b
            
            // Sanshas
            case 3514: // Revenant
                if ($date <= "2023-12-01") return 100_000_000_000; // 100b
                return 250_000_000_000; // 250b

            // CCP Ships
            case 9860: // Polaris
            case 11019: // Cockroach
                return 1_000_000_000_000; // 1t

            // Rare Structures
            case 47512: // 'Moreau' Fortizar
            case 47514: // 'Horizon' Fortizar
                return 60_000_000_000; // 60b 
                
            // AT Ships
            case 2834: // Utu
            case 3516: // Malice
            case 11375: // Freki
                return 80_000_000_000; // 80b
            case 3518: // Vangel
            case 32788: // Cambion
            case 32790: // Etana
            case 32209: // Mimir
            case 33673: // Whiptail
                return 100_000_000_000; // 100b
            case 35779: // Imp
            case 42246: // Caedes
            case 74141: // Geri
                return 120_000_000_000; // 120b
            case 2836: // Adrestia
            case 33675: // Chameleon
            case 35781: // Fiend
            case 45530: // Virtuoso
            case 48636: // Hydra
            case 60765: // Raiju
            case 74316: // Bestla
            case 78414: // Shapash
                return 150_000_000_000; // 150b
            case 33397: // Chremoas
            case 42245: // Rabisu
                return 200_000_000_000; // 200b
            case 45531: // Victor
            case 48635: // Tiamat
            case 60764: // Laelaps
            case 77726: // Cybele
                return 230_000_000_000; // 230b

            // Rare ships
            case 11942: // Silver Magnate
                return 100_000_000_000; // 100b
            case 11940: // Gold Magnate
                if ($date <= "2020-01-25") return 500_000_000_000; // 500b
                return 3_400_000_000_000;	// 3.4t
            case 635: // Opux Luxury Yacht
            case 11011: // Guardian-Vexor
            case 25560: // Opux Dragoon Yacht
            case 33395: // Moracha
                return 500_000_000_000; // 500b
            
            // Rare battleships
            case 13202: // Megathron Federate Issue
            case 11936: // Apocalypse Imperial Issue
            case 11938: // Armageddon Imperial Issue
            case 26842: // Tempest Tribal Issue
                return 750_000_000_000; // 750b
            case 26840: // Raven State Issue
                return 2_500_000_000_000; // 2.5t
        }

        // Some groupIDs have prices based on their group
        $groupID = Info::getGroupID($typeID);
        switch ($groupID) {
            case 30: // Titans
            case 659: // Supercarriers
                $p = Build::getItemPrice($typeID, $date);
                if ($p > 1) return $p; 
                return;
            case 29: // Capsules
                return 10000; // 10k
        }

        return;
    }

    public static function getCalculatedPrice($typeID, $date)
    {
        switch ($typeID) {
            case 2233: // Gantry
                $gantry = self::getItemPrice(3962, $date, true);
                $nodes = self::getItemPrice(2867, $date, true);
                $modules = self::getItemPrice(2871, $date, true);
                $mainframes = self::getItemPrice(2876, $date, true);
                $cores = self::getItemPrice(2872, $date, true);
                $total = $gantry + (($nodes + $modules + $mainframes + $cores) * 8);

                return $total;
        }

        return;
    }

    public static function getCrestPrices($typeID)
    {
        global $mdb, $esiServer;

        $marketHistory = $mdb->findDoc('prices', ['typeID' => $typeID]);
        if ($marketHistory === null) {
            $marketHistory = ['typeID' => $typeID];
            $mdb->save('prices', $marketHistory);
        }

        $url = "$esiServer/v1/markets/10000002/history/?type_id=$typeID";
        $sso = ZKillSSO::getSSO();
        $json = json_decode($sso->doCall($url), true);
        usleep(250000);

        foreach ($json as $row) {
            $avgPrice = $row['average'];
            $date = substr($row['date'], 0, 10);
            if (isset($marketHistory[$date])) {
                continue;
            }
            $mdb->set('prices', ['typeID' => $typeID], [$date => $avgPrice]);
        }
        if (sizeof($json) == 0) {
            $key = "zkb:market:" . date('H');
            $market = RedisCache::get($key);
            if ($market == null) {
                $market = json_decode($sso->doCall("$esiServer/v1/markets/prices/"), true);
                RedisCache::set($key, $market, 3600);
            }
            $date = date('Y-m-d');
            foreach ($market as $item) {
                if (@$item['type']['id'] == $typeID) {
                    $price = @$item['average_price'];
                    if ($price > 0) $mdb->set('prices', ['typeID' => $typeID], [$date => $price]);
                }
            }
        }
    }
}
