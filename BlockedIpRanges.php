<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\TrackingSpamPrevention;

use Matomo\Network\IP;
use Piwik\Cache as PiwikCache;
use Piwik\Common;
use Piwik\Http;
use Piwik\Option;
use Piwik\Plugins\TrackingSpamPrevention\BlockedIpRanges\Aws;
use Piwik\Plugins\TrackingSpamPrevention\BlockedIpRanges\Azure;
use Piwik\Plugins\TrackingSpamPrevention\BlockedIpRanges\Oracle;
use Piwik\SettingsPiwik;
use Piwik\Tracker\Cache;

class BlockedIpRanges
{
    const OPTION_KEY = 'TrackingSpamBlockedIpRanges';

    /**
     * will return the array indexed by ip start
     * @return array
     */
    public function getBlockedRanges()
    {
        $ranges = Option::get(self::OPTION_KEY);
        if (empty($ranges)) {
            return [];
        }
        return json_decode($ranges, true);
    }

    /**
     * An array of blocked ranges
     * @param string[] $ranges
     */
    public function setBlockedRanges($ranges)
    {
        // we index them by first character for performance reasons see the excluded method
        Option::set(self::OPTION_KEY, json_encode($ranges));
    }

    private function getIndexForIpOrRange($ipOrRange)
    {
        if (empty($ipOrRange)) {
            return;
        }
        // when IP is 10.11.12.13 then we return "10."
        // when IP is f::0 then we return "f:"
        foreach (['.', ':'] as $searchChar) {
            $posSearchChar = strpos($ipOrRange, $searchChar);
            if ($posSearchChar !== false) {
                return Common::mb_substr($ipOrRange, 0, $posSearchChar) . $searchChar;
            }
        }

        // fallback, should not happen
        return Common::mb_substr($ipOrRange, 0, 1);
    }

    public function isExcluded($ip)
    {
        if (empty($ip)) {
            return false;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // for performance reasons we index ranges by first character of ip. assuming this works in most cases.
        // so we compare less ranges as it is slow to compare many ranges
        $indexed = $this->getIndexForIpOrRange($ip);

        if (empty($indexed)) {
            return false;
        }

        $trackerCache = Cache::getCacheGeneral();
        if (empty($trackerCache[self::OPTION_KEY][$indexed])) {
            return;
        }

        $ip  = IP::fromStringIP($ip);
        $key = 'TrackingSpamPreventionIsIpInRange' . $ip->toString();

        $cache = PiwikCache::getTransientCache();
        if ($cache->contains($key)) {
            $isInRanges = $cache->fetch($key);
        } else {
            $isInRanges = $ip->isInRanges($trackerCache[self::OPTION_KEY][$indexed]);

            $cache->save($key, $isInRanges);
        }

        return $isInRanges;
    }

    public function banIp($ip)
    {
        $ranges = $this->getBlockedRanges();
        $index = $this->getIndexForIpOrRange($ip);
        if (empty($ranges)) {
            $ranges[$index] = [];
        }

        $ranges[$index][] = $ip;
        $this->setBlockedRanges($ranges);
        return $ranges;
    }

    public function unsetAllIpRanges() {
        $this->setBlockedRanges([]);
    }

    public function updateBlockedIpRanges()
    {
        if (!SettingsPiwik::isInternetEnabled()) {
            $this->unsetAllIpRanges();
            return;
        }

        $gcloud = new BlockedIpRanges\Gcloud();
        $ranges = $gcloud->getRanges();

        $aws = new Aws();
        $ranges = array_merge($ranges, $aws->getRanges());

        $azure = new Azure();
        $ranges = array_merge($ranges, $azure->getRanges());

        $oracle = new Oracle();
        $ranges = array_merge($ranges, $oracle->getRanges());

        $indexedRange = [];
        if (!empty($ranges)) {
            foreach ($ranges as $range) {
                $indexed = $this->getIndexForIpOrRange($range);
                if (empty($indexedRange[$indexed])) {
                    $indexedRange[$indexed] = [];
                }
                $indexedRange[$indexed][] = $range;
            }
        }
        $this->setBlockedRanges($indexedRange);
    }

}