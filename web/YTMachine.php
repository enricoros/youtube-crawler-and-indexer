<?php
/* Copyright 2015, Enrico Ros

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License. */

define('YT_VIOLENT', true);
define('YT_VERBOSE', false);
define('YT_LANG_FILTER', 'en');

class YTMachine
{
    // cloud console data
    private $oauth_email_file = '../../thats-what-he-said-2040b1e37d61.email.txt';
    private $oauth_P12_file = '../../thats-what-he-said-2040b1e37d61.p12';

    // list of permissions we need
    private $scopes = ['https://www.googleapis.com/auth/youtube.force-ssl'];

    function __construct()
    {
        self::$googleClient = new Google_Client();
        $credentials = new Google_Auth_AssertionCredentials(
            trim(file_get_contents($this->oauth_email_file)),
            $this->scopes,
            file_get_contents($this->oauth_P12_file)
        );
        self::$googleClient->setAssertionCredentials($credentials);
    }

    /**
     * @param $criteria YTSearchCriteria
     * @param $maxCount integer We'll do the searches in small batches, but there may be endless videos.
     * Limit the search to 200 for example.
     * @return YTVideo[]
     */
    function searchVideos($criteria, $maxCount)
    {
        $allVideos = [];
        $nextPageToken = null;
        do {
            // when looping, set the ID of the next page
            $criteria->setPage($nextPageToken);
            // try to go with the right size (will be paginated automatically)
            $criteria->setResultsPageSize($maxCount - sizeof($allVideos));

            $listResults = self::getYoutube()->search->listSearch('id,snippet', $criteria->getArray());

            if (YT_VERBOSE && $nextPageToken == null) {
                $totalResults = $listResults->getPageInfo()->totalResults;
                $perPageResults = $listResults->getPageInfo()->resultsPerPage;
                echo 'Loading ' . $maxCount . ' results from ' . $totalResults . ' in batches of '
                    . $perPageResults . ' for query ' . $criteria->getQuery() . "\n";
            }

            // parse the list of results for per-video information
            /* @var $listItems Google_Service_YouTube_SearchResult[] */
            $listItems = $listResults->getItems();
            foreach ($listItems as $item) {
                /* @var $id Google_Service_YouTube_ResourceId */
                $id = $item->getId();
                /* @var $snippet Google_Service_YouTube_SearchResultSnippet */
                $snippet = $item->getSnippet();
                /* @var $thumbs Google_Service_YouTube_ThumbnailDetails */
                $thumbs = $snippet->getThumbnails();
                /* @var $thumb Google_Service_YouTube_Thumbnail */
                //$thumb = $thumbs->getMaxres();
                //if ($thumb == null)
                $thumb = $thumbs->getHigh();
                if ($thumb == null)
                    $thumb = $thumbs->getStandard();

                // create an entry with all the info we have so far
                $video = new YTVideo(
                    $id->getVideoId(), $snippet->getTitle(), $snippet->getDescription(),
                    $snippet->getPublishedAt(), ($thumb != null) ? $thumb->getUrl() : '',
                    $criteria->getLanguage()
                );
                array_push($allVideos, $video);
            }

            // check if it's possible to advance to the next page
            $nextPageToken = $listResults->getNextPageToken();
        } while (!empty($nextPageToken) && sizeof($allVideos) < $maxCount);
        return $allVideos;
    }

    /**
     * Merges the second array into the first, excluding any duplicate video (by unique videoIds)
     * @param $into YTVideo[] the destination array, where items are appended
     * @param $from YTVideo[] to insert form
     */
    public function mergeUniqueVideos(&$into, &$from)
    {
        $existingIds = array_map(function ($video) {
            return $video->videoId;
        }, $into);
        foreach ($from as $video) {
            if (!in_array($video->videoId, $existingIds)) {
                array_push($into, $video);
                array_push($existingIds, $video->videoId);
            }
        }
    }

    /**
     * Sorts an array of YTVideos in place.
     * @param $videosArray YTVideo[]
     * @param $order String sorting criteria:
     */
    public function sortVideos(&$videosArray, $order)
    {
        switch ($order) {
            case 'views':
                $sortingFunction = function ($a, $b) {
                    return $b->countViews - $a->countViews;
                };
                break;
            case 'liked':
                $sortingFunction = function ($a, $b) {
                    return $a->countDislikesPct - $b->countDislikesPct;
                };
                break;
            case 'disliked':
                $sortingFunction = function ($a, $b) {
                    return $b->countDislikesPct - $a->countDislikesPct;
                };
                break;
            default:
                die('unsupported sorting order');
        }
        usort($videosArray, $sortingFunction);
    }

    /* @var $guzzle \GuzzleHttp\Client() */
    /* @var $youtube Google_Service_YouTube */
    /* @var $youtubeAnalytics Google_Service_YouTubeAnalytics */
    private static $googleClient;
    private static $guzzle;
    private static $youtube;
    private static $youtubeAnalytics;

    static function getGuzzle()
    {
        // create on demand
        if (self::$guzzle == null)
            self::$guzzle = new \GuzzleHttp\Client();
        return self::$guzzle;
    }

    static function getYoutube()
    {
        self::ensureOAuthenticated();
        // create on demand
        if (self::$youtube == null)
            self::$youtube = new Google_Service_YouTube(self::$googleClient);
        return self::$youtube;
    }

    static function getYoutubeAnalytics()
    {
        self::ensureOAuthenticated();
        // create on demand
        if (self::$youtubeAnalytics == null)
            self::$youtubeAnalytics = new Google_Service_YouTubeAnalytics(self::$googleClient);
        return self::$youtubeAnalytics;
    }

    private static function ensureOAuthenticated()
    {
        if (self::$googleClient->getAuth()->isAccessTokenExpired())
            self::$googleClient->getAuth()->refreshTokenWithAssertion();
    }
}


class YTVideo
{
    // how large should a SRT be to be considered OK
    const SUB_OK_THRESHOLD = 500;

    // first discovered properties
    public $videoId;
    public $title;
    public $description;
    public $publishedAt;
    public $thumbUrl;
    public $language;
    public $desktopUrl;

    // after resolveCaptions()
    /* @var $ytCC YTCC */
    private $resolvedCaptions = false;
    public $ytCC = null;

    // after resolveDetails()
    private $resolvedDetails = false;
    public $countViews;
    public $countComments;
    public $countLikes;
    public $countDislikes;
    public $countDislikesPct;
    public $countFavorites;
    public $channelId;
    public $channelTitle;
    public $durationS;
    public $tags;


    function __construct($videoId, $title, $description, $publishedAt, $thumbUrl, $language)
    {
        $this->videoId = $videoId;
        $this->title = $title;
        $this->description = $description;
        $this->publishedAt = $publishedAt;
        $this->thumbUrl = $thumbUrl;
        $this->language = $language;
        $this->desktopUrl = 'https://www.youtube.com/watch?v=' . $videoId;
    }

    public function resolveDetails()
    {
        // do it at most once
        if ($this->resolvedDetails)
            return;
        $this->resolvedDetails = true;

        /* @var $item Google_Service_YouTube_Video
         * @var $snip Google_Service_YouTube_VideoSnippet
         * @var $stat Google_Service_YouTube_VideoStatistics
         * @var $cDet Google_Service_YouTube_VideoContentDetails
         */
        // NOTE: could add topicDetails in the future
        $details = YTMachine::getYoutube()->videos->listVideos('statistics,snippet,contentDetails', ['id' => $this->videoId]);
        $items = $details->getItems();
        if (YT_VIOLENT && sizeof($items) != 1)
            die('Expecting 1 item, got ' . sizeof($items));
        $item = $items[0];

        // counters
        $stat = $item->getStatistics();
        $this->countViews = $stat->getViewCount();
        $this->countComments = $stat->getCommentCount();
        $this->countLikes = $stat->getLikeCount();
        $this->countDislikes = $stat->getDislikeCount();
        $this->countFavorites = $stat->getFavoriteCount();
        $this->countDislikesPct = $this->countViews > 0 ? (100 * $this->countDislikes / $this->countViews) : 0;

        // additional info (tags, channel)
        $snip = $item->getSnippet();
        $this->channelId = $snip->getChannelId();
        $this->channelTitle = $snip->getChannelTitle();
        $this->tags = $snip->getTags();

        // duration (ignoring: definition(hd), dimension(2d), caption(true), contentRating, countryRestriction, regionRestriction)
        $cDet = $item->getContentDetails();
        $dt = new DateInterval($cDet->getDuration());
        $this->durationS = $dt->h * 3600 + $dt->i * 60 + $dt->s;
    }

    /**
     * @return bool True if successful
     */
    public function resolveCaptions()
    {
        // do it at most once
        if ($this->resolvedCaptions)
            return $this->ytCC != null;
        $this->resolvedCaptions = true;

        // perform the resolution
        // FIXME: SPEED BOTTLENECK (1/second)
        $captionsList = YTMachine::getYoutube()->captions->listCaptions('snippet', $this->videoId);

        // get all the SRT from this track that match the language
        $ytCCs = [];
        foreach ($captionsList->getItems() as $item) {
            /* @var $cc Google_Service_YouTube_CaptionSnippet */
            $cc = $item->getSnippet();
            $ccId = $item->getId();

            // Sanity: abort if the returned CC is for a different video
            $ccVideoId = $cc->getVideoId();
            if ($ccVideoId != $this->videoId) {
                if (YT_VIOLENT)
                    die('wrong video id, got ' . $ccVideoId . ' expecting ' . $this->videoId);
                continue;
            }

            // Sanity: check constant attributes, or stop if unexpected
            $ccStatus = $cc->getStatus();
            if ($ccStatus != 'serving')
                if (YT_VIOLENT)
                    die('wrong status. expected serving, got ' . $ccStatus);

            // base fetching query
            $fetchQuery = 'v=' . $ccVideoId;

            // add kind
            $ccKind = $cc->getTrackKind();
            if ($ccKind == 'standard') {
                // nothing to do here
            } else if ($ccKind == 'ASR') {
                // FILTER: TODO: we don't support the ASR format yet, at all. Always fails.
                if (YT_VERBOSE)
                    echo $ccVideoId . ',  skipping ASR tracks (unsupported yet)' . "\n";
                continue;
            } else {
                if (YT_VIOLENT)
                    die('unknown track type ' . $ccKind);
                continue;
            }

            // add language
            $ccLang = $cc->getLanguage();
            if (!empty($ccLang)) {
                // FILTER: skip if the language is not what we asked for
                if ($ccLang != $this->language) {
                    // NOTE: in the future we could also stash other languages for later
                    if (YT_VERBOSE)
                        echo $ccVideoId . ',  skipping CC for different language: ' . $ccLang . "\n";
                    continue;
                }
                $fetchQuery .= '&lang=' . $ccLang;
            }

            // add 'name'
            $ccTrackName = $cc->getName();
            if (!empty($ccTrackName))
                $fetchQuery .= '&name=' . $ccTrackName;

            // customize the output format. available formats:
            // srv1: <text start="2.501" dur="3.671">
            // srv2: <timedtext><window t="0" id="1" op="define" rc="15" cc="32" ap="7" ah="50" av="95"/><text w="2" t="5538" d="2536">RE</text>
            // srv3: <timedtext format="3"><p t="2501" d="3671" w="2"><s>TH</s><s t="33">E</s>
            // sbv:  [SubViewer] 0:00:02.501,0:00:06.172 \n THE WASHINGTON CORRESPONDENT
            // srt:  [SubRip   ] 1 \n 00:00:02,501 --> 00:00:06,172 \n THE WASHINGTON CORRESPONDENT
            // ttml: [TTML     ] <p begin="00:00:02.501" end="00:00:06.172" region="r3" style="s2"><span begin="00:00:00.000">TH</span>
            // vtt:  [WebVTT   ] 00:00:02.501 --> 00:00:06.172 align:start position:0% line:7% \n THE WASHINGTON CORRESPONDENT
            $fetchQuery .= '&fmt=srv1';

            // Fetch the Caption from cache
            $ccString = Cacher::retrieveValue('cc_' . $fetchQuery);

            // Fetch the Caption (and expect a 200:OK code)
            if ($ccString == null) {
                try {
                    $response = YTMachine::getGuzzle()->get('https://www.youtube.com/api/timedtext?' . $fetchQuery);
                    if ($response->getStatusCode() != 200) {
                        if (YT_VIOLENT)
                            die('wrong status code ' . $response->getStatusCode() . ' on ' . $fetchQuery);
                        continue;
                    }
                    $ccString = $response->getBody()->getContents();
                    Cacher::storeValue('cc_' . $fetchQuery, $ccString, null);
                } catch (\GuzzleHttp\Exception\ClientException $exception) {
                    if (YT_VIOLENT)
                        die('HTTP request failed: ' . $exception);
                    continue;
                }
            }

            // FILTER: Size Heuristic (FIXME): reject semi-empty captions (usually with not much more than the title)
            $ccStringSize = $ccString != null ? strlen($ccString) : 0;
            if ($ccStringSize < self::SUB_OK_THRESHOLD) {
                if (YT_VERBOSE)
                    echo $ccVideoId . ',  skipping for small size: ' . $ccStringSize . "\n";
                continue;
            }

            // FILTER: parse and validate XML?
            $ccXml = new SimpleXMLElement($ccString);
            if (YT_VIOLENT && $ccXml->getName() != 'transcript')
                die('expected a transcript, got a ' . $ccXml->getName() . ' instead ');
            foreach ($ccXml->text AS $text) {
                $line = strval($text);
                $attributes = $text->attributes();
                $start = $attributes['start'];
                $duration = $attributes['dur'];

                // FILTER: videos that have start but not duration are usually 1-liners
                if (empty($start) || empty($duration)) {
                    if (YT_VERBOSE)
                        echo 'start or duration empty on ' . $ccVideoId . " body: " . $ccString . "\n";
                    continue;
                }
            }

            // save the fully-fetched caption
            array_push($ytCCs,
                new YTCC($ccId, $ccVideoId, $ccStringSize, $ccString, $ccXml, $ccTrackName, $cc->getLastUpdated())
            );
        }

        // use just best caption amongst those available, chosen by size
        usort($ytCCs, function ($a, $b) {
            return $b->ccSize - $a->ccSize;
        });

        // pick the best (if any), or null
        $this->ytCC = empty($ytCCs) ? null : $ytCCs[0];
        return $this->ytCC != null;
    }
}

class YTCC
{
    private $id;
    private $videoId;
    public $ccSize;
    //public $ccString;
    public $xml;
    private $trackName;
    private $lastUpdated;

    function __construct($id, $videoId, $ccSize, $ccString, $xml, $trackName, $lastUpdated)
    {
        $this->id = $id;
        $this->videoId = $videoId;
        $this->ccSize = $ccSize;
        //$this->ccString = $ccString;
        $this->xml = $xml;
        $this->trackName = $trackName;
        $this->lastUpdated = $lastUpdated;

        if (YT_VERBOSE)
            echo $videoId . ',' . $ccSize . ',' . $trackName . ',' . $lastUpdated . ',' . $id . "\n";
    }
}

class YTSearchCriteria
{
    private $criteria = [];

    function __construct($query)
    {
        $this->criteria['q'] = $query;
        $this->criteria['type'] = 'video';
        $this->criteria['videoCaption'] = 'closedCaption';
        $this->criteria['safeSearch'] = 'none';
        $this->criteria['videoEmbeddable'] = 'true';
        $this->setLanguage(YT_LANG_FILTER);
        $this->setResultsPageSize(40);
        $this->setHD(true);
    }

    /**
     * @param $lang String from http://www.loc.gov/standards/iso639-2/php/code_list.php, e.g. 'en'
     * @return YTSearchCriteria
     */
    function setLanguage($lang)
    {
        $this->criteria['relevanceLanguage'] = $lang;
        return $this;
    }

    /**
     * @param $on bool to enable/disable HD videos (default: disabled)
     * @return YTSearchCriteria
     */
    function setHD($on)
    {
        if ($on)
            $this->criteria['videoDefinition'] = $on ? 'high' : 'any';  /* or standard? */
        else
            unset($this->criteria['videoDefinition']);
        return $this;
    }

    /**
     * @param $pageToken String that identifies the page to load (returned by prev/next page on other calls)
     * @return YTSearchCriteria
     */
    function setPage($pageToken)
    {
        if (!empty($pageToken))
            $this->criteria['pageToken'] = $pageToken;
        else
            unset($this->criteria['pageToken']);
        return $this;
    }

    /**
     * @param $order String one of 'date', 'rating', 'relevance' (default), 'title', 'videoCount', 'viewCount'
     * @return YTSearchCriteria
     */
    function setOrder($order)
    {
        $valid = ['date', 'rating', 'relevance' /* def. */, 'title', 'videoCount', 'viewCount'];
        if (!array_key_exists($order, $valid))
            die('wrong order: ' . $order);
        $this->criteria['order'] = $order;
        return $this;
    }

    /**
     * @param $from String RFC 3339 (older)
     * @param $to String RFC 3339 (newer)
     * @return YTSearchCriteria
     */
    function setTimeInterval($from, $to)
    {
        if ($from != null)
            $this->criteria['publishedAfter'] = $from;
        if ($to != null)
            $this->criteria['publishedBefore'] = $to;
        return $this;
    }

    /**
     * @param $num integer the page size (int the 1..50 interval)
     * @return YTSearchCriteria
     */
    function setResultsPageSize($num)
    {
        $this->criteria['maxResults'] = max(1, min(50, $num));
        return $this;
    }

    // used by YTMachine
    function getArray()
    {
        return $this->criteria;
    }

    function getQuery()
    {
        return $this->criteria['q'];
    }

    function getLanguage()
    {
        return $this->criteria['relevanceLanguage'];
    }
}
