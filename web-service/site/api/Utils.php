<?php
/**
 * Created by PhpStorm.
 * User: manosetro
 * Date: 11/23/15
 * Time: 12:40 PM
 */

class Utils {

    public function formulateLogicalQuery($keywords) {
        $queryParts = array();
        foreach($keywords as $keyword) {
            trim($keyword);
            $keyword = preg_split("/\s+/", $keyword);
            if(count($keyword) > 1) {
                $queryParts[] = '(' . implode(' AND ', $keyword) . ')';
            }
            else {
                $queryParts[] = implode(' AND ', $keyword);
            }
        }
        $query = implode(' OR ', $queryParts);
        return $query;
    }

    public function formulateCollectionQuery($collection) {
        $keywords = $collection['keywords'];
        $keywords = array_map(function ($keyword) {
            return  trim($keyword['keyword']);
        }, $keywords);

        // hash tags
        $tags = array_filter($keywords, function ($keyword) {
            return preg_match('/^#/', $keyword);
        });

        $tags = array_map(function ($tag) {
            return  str_replace("#", "", $tag);
        }, $tags);

        // keywords for free text search
        $keywords = array_filter($keywords, function ($keyword) {
            return !preg_match('/^#/', $keyword);
        });

        //$keywords = array_keys($keywords);
        $textQuery = $this->formulateLogicalQuery($keywords);
        $tagsQuery = $this->formulateLogicalQuery($tags);

        // excluded keywords
        if(isset($collection['keywordsToExclude'])) {
            $keywordsToExclude = $collection['keywordsToExclude'];
            if ($keywordsToExclude != null && count($keywordsToExclude) > 0) {
                $excludedTermsQuery = $this->formulateLogicalQuery($keywordsToExclude);

                $textQuery = $textQuery . " NOT (" . $excludedTermsQuery . ")";
                $tagsQuery = $tagsQuery . " NOT (" . $excludedTermsQuery . ")";
            }
        }

        // user accounts to follow
        $accounts = $collection['accounts'];
        $users = array_map(function ($account) {
            return $account['source']."#".$account['id'];
        }, $accounts);

        $query = array();
        if($textQuery != null && $textQuery !== '') {
            $query[] = "title:($textQuery) OR description:($textQuery)";
        }

        if($tagsQuery != null && $tagsQuery !== '') {
            $query[] = "tags:($tagsQuery)^2";
        }

        if($users != null && count($users) > 0) {
            $usersQuery = implode(' OR ', $users);
            $query[] = "uid:($usersQuery)";
        }

        if(isset($collection['itemsToExclude'])) {
            $itemsToExclude = $collection('itemsToExclude');
            if ($itemsToExclude != null && count($itemsToExclude) > 0) {
                $idsToExclude = implode(' OR ', $itemsToExclude);
                $query[] = "-id:($idsToExclude)";
            }
        }

        return implode(' OR ', $query);
    }

    public function getFilters($since, $until, $source, $original, $type, $language, $query) {
        // Add filters if available
        $filters = array();

        // filter by query
        if ($query != null && $query != '') {
            $query = urldecode($query);
            $keywords = explode(',', $query);

            $filterTextQuery = $this->formulateLogicalQuery($keywords);
            $filters['allText'] = $filterTextQuery;
        }

        //filter by source
        if($source != null) {
            if($source == 'all') {
                $filters['source'] = "*";
            }
            else {
                $sources = explode(',', $source);
                $sources = implode(' OR ', $sources);
                $filters['source'] = "$sources";
            }
        }
        else {
            $filters['-source'] = "*";
        }

        if($original != null) {
            if($original === 'original') {
                $filters['original'] = 'true';
            }
            if($original === 'share') {
                $filters['original'] = 'false';
            }
        }

        //filter by type
        if($type != null) {
            if($type === 'media') {
                $filters['mediaIds'] = "*";
            }
            if($type === 'text') {
                $filters['-mediaIds'] = "*";
            }
        }

        //filter by language
        if($language != null && $language !== '' && $language !== 'all' && $language !== 'All') {
            $languages = explode(',', $language);
            $languages = implode(' OR ', $languages);
            $filters['language'] = "($languages)";
        }

        // filter by publication time
        if($since !== '*' && $until !== '*') {
            $filters['publicationTime'] = "[$since TO $until]";
        }

        return $filters;
    }

    public static function expandQuery($judgements, $query, $index) {
        $positive = array_filter($judgements, function($rj) { return ($rj['relevence'] > 3); });
        $negative = array_filter($judgements, function($rj) { return ($rj['relevence'] < 3); });

        $positiveIds = array_map(function($entry) { return $entry['iid']; }, $positive);
        $negativeIds = array_map(function($entry) { return $entry['iid']; }, $negative);

        // get scores of positive items for the collection and the corresponding term vectors
        $positiveQuery = "id:(".implode(' OR', $positiveIds).")";
        $positiveTVs = $index->getTermVectors($positiveQuery, 1, count($positiveIds));
        $positiveItems = $index->searchItems($query, 1, count($positiveIds), array(0 => $positiveQuery));

        // get scores of negative items for the collection and the corresponding term vectors
        $negativeQuery = "id:(".implode(' OR', $negativeIds).")";
        $negativeTVs = $index->getTermVectors($negativeQuery, 1, count($negativeIds));
        $negativeItems = $index->searchItems($query, 1, count($negativeIds), array(0 => $negativeQuery));

        // estimate positive and negative relevance models aggregated by each of the relevance judgements
        $positiveFeatureVector = Utils::estimateRelevanceModel($positiveTVs, $positiveItems);
        $negativeFeatureVector = Utils::estimateRelevanceModel($negativeTVs, $negativeItems);

        // todo: prune feature vectors

        // todo: expanded create query from feature vectors
        $query = urldecode($query);
        $queryTerms = explode(',', $query);

        $query = Utils::interpolate($queryTerms, $positiveFeatureVector, 0.8);

        // For negative boosting: boost documents that do not contain term zzz with a factor w3
        // q = xxx^w1 yyy^w2 (*:* -zzz)^w3  w3 >> w1, w2

    }

    public function estimateRelevanceModel($tvs, $items) {

        $featureVector = array();

        $items = array_map(function($item) { return array($item['id'] => $item['score']); }, $items);

        $vocabulary = array();
        foreach($tvs as $iid => $terms) {
            $vocabulary = array_merge($vocabulary, array_keys($terms));
        }
        $vocabulary = array_unique($vocabulary);

        foreach($vocabulary as $term) {
            $weight = .0;
            foreach($tvs as $iid => $terms) {
                if(isset($terms['vector'][$term]) && isset($items[$iid])) {
                    // doc_score * (tf_idf) / sum(tf_idf)
                    $weight = $weight + ($items[$iid]*($terms['vector'][$term] / $terms['norm']));
                }
            }
            $featureVector[$term] = $weight;
        }

        return $featureVector;
    }

    public static function interpolate($x, $y, $xWeight) {

        $z = array();

        $vocabulary = array();
        $vocabulary = array_merge($vocabulary, array_keys($x));
        $vocabulary = array_merge($vocabulary, array_keys($y));
        $vocabulary = array_unique($vocabulary);

        foreach($vocabulary as $term) {
            $xw = isset($x[$term]) ? $x[$term] : .0;
            $yw = isset($y[$term]) ? $y[$term] : .0;

            $weight = ($xWeight*$xw + (1.0-$xWeight)*$yw);
            $z[$term] = $weight;
        }

        return $z;
    }
}