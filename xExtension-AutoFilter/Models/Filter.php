<?php

class AutoFilter_Filter
{
    public static function filterEntries(array $entries, string $filterType = 'none'): array
    {
        if ($filterType === 'hide_advertisement') {
            return array_filter($entries, fn($entry) => !in_array('advertisement', $entry->tags()));
        }

        if ($filterType === 'hide_all_ads') {
            return array_filter($entries, fn($entry) => 
                !in_array('advertisement', $entry->tags()) && 
                !in_array('possible_advertisement', $entry->tags())
            );
        }

        return $entries;
    }

    public static function sortEntries(array $entries, string $sortType = 'none'): array
    {
        if ($sortType === 'lower_possible') {
            usort($entries, function($a, $b) {
                $aTags = $a->tags();
                $bTags = $b->tags();
                
                $aIsPossible = in_array('possible_advertisement', $aTags);
                $bIsPossible = in_array('possible_advertisement', $bTags);
                $aIsAd = in_array('advertisement', $aTags);
                $bIsAd = in_array('advertisement', $bTags);
                
                $aScore = ($aIsAd ? 2 : 0) + ($aIsPossible ? 1 : 0);
                $bScore = ($bIsAd ? 2 : 0) + ($bIsPossible ? 1 : 0);
                
                return $aScore - $bScore;
            });
        }

        if ($sortType === 'ads_first') {
            usort($entries, function($a, $b) {
                $aTags = $a->tags();
                $bTags = $b->tags();
                
                $aIsAd = in_array('advertisement', $aTags);
                $bIsAd = in_array('advertisement', $bTags);
                
                return $bIsAd - $aIsAd;
            });
        }

        return $entries;
    }

    public static function getEntriesByLabel(array $entries, string $label): array
    {
        return array_filter($entries, fn($entry) => in_array($label, $entry->tags()));
    }

    public static function getStatistics(array $entries): array
    {
        $total = count($entries);
        $advertisement = 0;
        $possibleAdvertisement = 0;

        foreach ($entries as $entry) {
            $tags = $entry->tags();
            if (in_array('advertisement', $tags)) {
                $advertisement++;
            } elseif (in_array('possible_advertisement', $tags)) {
                $possibleAdvertisement++;
            }
        }

        return [
            'total' => $total,
            'advertisement' => $advertisement,
            'possible_advertisement' => $possibleAdvertisement,
            'clean' => $total - $advertisement - $possibleAdvertisement,
            'advertisement_percent' => $total > 0 ? round(($advertisement / $total) * 100, 2) : 0,
        ];
    }
}
