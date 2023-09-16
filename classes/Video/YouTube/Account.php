<?php
namespace Core2\Mod\Sources\Video\YouTube;
use Alaouy\Youtube\Youtube;


/**
 * @property \ModSourcesController $modSources
 */
class Account extends \Common {

    private Youtube $connection;
    private array   $options = [];


    /**
     * @param Youtube    $connection
     * @param array|null $options
     */
    public function __construct(Youtube $connection, array $options = null) {

        parent::__construct();
        $this->connection = $connection;

        if ($options) {
            $this->options = $options;
        }
    }


    /**
     * @return int|null
     */
    public function getNmbr():? int {

        return isset($this->options['nmbr']) ? (int)$this->options['nmbr'] : null;
    }


    /**
     * Проверка активности метода
     * @param string $method
     * @return bool
     */
    public function isActiveMethod(string $method): bool {

        $account = $this->getAccountRow();

        $inactive_methods = $account->inactive_methods ? @json_decode($account->inactive_methods, true) : [];
        $inactive_methods = is_array($inactive_methods) ? $inactive_methods : [];

        return empty($inactive_methods[$method]) || $inactive_methods[$method] < date('Y-m-d H:i:s');
    }


    /**
     * Временное выключение метода
     * @param string $method
     */
    public function inactiveMethod(string $method): void {

        $account = $this->getAccountRow();

        $inactive_methods = $account->inactive_methods ? @json_decode($account->inactive_methods, true) : [];
        $inactive_methods = is_array($inactive_methods) ? $inactive_methods : [];

        $inactive_methods[$method] = date('Y-m-d 03:00:00', strtotime("+1 day"));

        $account->inactive_methods = json_encode($inactive_methods);
        $account->save();
    }


    /**
     * Получение действий над аккаунтом
     * @return array
     */
    public function getActions(): array {

        $actions = $this->options['actions'] ?? '';

        $actions_explode = $actions ? explode(',', $actions) : [];
        $actions_explode = array_map('trim', $actions_explode);

        return array_map('strtolower', $actions_explode);
    }


    /**
     * Получение популярных видео
     * @param array $videos_id
     * @return array|null
     * @throws \Exception
     */
    public function getVideosInfo(array $videos_id):? array {

        $parts  = [ 'id', 'snippet', 'contentDetails', 'statistics', ];
        $result = $this->connection->getVideoInfo($videos_id, $parts);

        if ($result) {
            $result = json_decode(json_encode($result), true);
        }

        return is_array($result) ? $result : null;
    }


    /**
     * Получение популярных видео
     * @param string $region
     * @return array|null
     */
    public function getVideosPopular(string $region = ''):? array {

        $parts     = ['id', 'snippet', 'contentDetails', 'statistics'];
        $maxResult = 50; // max 50

        $result = $this->connection->getPopularVideos($region, $maxResult, $parts);

        if ($result) {
            $result = json_decode(json_encode($result), true);
        }

        return is_array($result) ? $result : null;
    }


    /**
     * Получение комментариев из под видео
     * @param string      $video_id
     * @param string|null $page_token
     * @return array|null
     * @throws \Exception
     */
    public function getVideosComments(string $video_id, string $page_token = null):? array {

        $part   = ['id', 'replies', 'snippet'];
        $params = array_filter([
            'videoId'    => $video_id,
            'maxResults' => 100,  // max 100
            'part'       => implode(',', $part),
            'order'      => 'time',
            'textFormat' => 'plainText',
        ]);

        if ($page_token) {
            $params['pageToken'] = $page_token;
        }

        $api_url = $this->connection->getApi('commentThreads.list');
        $api_data = $this->connection->api_get($api_url, $params);

        $result = [
            'results' => $this->connection->decodeList($api_data),
            'info'    => $this->connection->page_info,
        ];

        $result = json_decode(json_encode($result), true);

        return is_array($result) ? $result : null;
    }


    /**
     * Получение информации о канале по ID
     * @param string $channel_id
     * @param array  $part
     * @return array|null
     * @throws \Exception
     */
    public function getChannelInfoById(string $channel_id, array $part = ['id', 'snippet', 'statistics']):? array {

        $result = $this->connection->getChannelById($channel_id, [], $part);

        if ($result) {
            $result = json_decode(json_encode($result), true);
        }

        return is_array($result) ? $result : null;
    }


    /**
     * Получение информации о канале по названию
     * @param string $name
     * @param array  $part
     * @return array|null
     * @throws \Exception
     */
    public function getChannelInfoByName(string $name, array $part = ['id', 'snippet', 'statistics']):? array {

        $result = $this->connection->getChannelByName($name, [], $part);

        if ($result) {
            $result = json_decode(json_encode($result), true);
        }

        return is_array($result) ? $result : null;
    }


    /**
     * Получение информации о канале по ID
     * @param array $channels_id
     * @param array $part
     * @return array|null
     * @throws \Exception
     */
    public function getChannelsInfoById(array $channels_id, array $part = ['id', 'snippet', 'statistics']):? array {

        $result = $this->connection->getChannelById($channels_id, [], $part);

        if ($result) {
            $result = json_decode(json_encode($result), true);
        }

        return is_array($result) ? $result : null;
    }


    /**
     * Получение роликов из канала
     * @param string     $channel_id
     * @param array|null $options
     * @return array|null
     * @throws \Exception
     */
    public function getChannelVideos(string $channel_id, array $options = null):? array {

        $part   = ['id', 'snippet'];
        $params = [
            'type'       => 'video',
            'channelId'  => $channel_id,
            'part'       => implode(',', $part),
            'maxResults' => 50, // max 50
            'order'      => 'date',
        ];

        if ( ! empty($options['published_after']) && $options['published_after'] instanceof \DateTime) {
            $options['published_after']->setTimezone(new \DateTimeZone("UTC"));
            $params['publishedAfter'] = $options['published_after']->format('Y-m-d\TH:i:s\Z');
        }

        if ( ! empty($options['published_before']) && $options['published_before'] instanceof \DateTime) {
            $options['published_before']->setTimezone(new \DateTimeZone("UTC"));
            $params['publishedBefore'] = $options['published_before']->format('Y-m-d\TH:i:s\Z');
        }

        $result = $this->connection->searchAdvanced($params, true);

        if ($result) {
            $result = json_decode(json_encode($result), true);
        }

        return is_array($result) ? $result : null;
    }


    /**
     * @return \Zend_Db_Table_Row_Abstract
     */
    private function getAccountRow(): \Zend_Db_Table_Row_Abstract {

        $account_key = "yt_" . $this->getNmbr();
        $account     = $this->modSources->dataSourcesVideosAccounts->getRowByAccountKey($account_key);

        if ( ! $account) {
            $account = $this->modSources->dataSourcesVideosAccounts->createRow([
                'account_key' => $account_key,
            ]);
            $account->save();
        }

        return $account;
    }
}