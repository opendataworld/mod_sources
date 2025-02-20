<?php
use Core2\Mod\Sources;
use Core2\Mod\Sources\Model;

require_once DOC_ROOT . 'core2/inc/classes/Common.php';
require_once "classes/autoload.php";


/**
 * @property ModSourcesController $modSources
 * @property ModProxyController   $modProxy
 * @property ModCronController    $modCron
 * @property ModMetricsApi        $apiMetrics
 */
class ModSourcesCli extends Common {

    /**
     * Сайты: Получение страниц
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function loadSources(): void {

        $configs = (new Sources\Sites\Model())->getConfigs();
        $test_sources = [

        ];

        if ($configs) {
            $transform = new Sources\Sites\Etl\Transform();
            $loader    = new Sources\Sites\Etl\Loader();

            foreach ($configs as $name => $config) {

                if ( ! empty($test_sources) && ! in_array($name, $test_sources)) {
                    continue;
                }

                if ( ! $config?->source || ! $config?->source?->title) {
                    continue;
                }

                $source_data = $transform->getSource($config->source->title, $config->source->tags, $config->source->region);
                $source_id   = $loader->saveSource($source_data);


                $source_sections = $config->toArray();

                foreach ($source_sections as $section_name_raw => $section) {
                    if (mb_strpos($section_name_raw, 'data__') === 0) {

                        if ( ! ($section['active'] ?? true)) {
                            continue;
                        }

                        $section_name = mb_substr($section_name_raw, 6);
                        $content_type = $section['type'] ?? 'html';


                        try {
                            switch ($content_type) {
                                case 'html':
                                    if (empty($section['start_url']) || empty($section['list'])) {
                                        continue 2;
                                    }

                                    $empty_list = true;
                                    foreach ($section['list'] as $list) {
                                        if ( ! empty($list)) {
                                            $empty_list = false;
                                            break;
                                        }
                                    }

                                    if ($empty_list) {
                                        continue 2;
                                    }


                                    $site = new Sources\Sites\Site($section);

                                    $pages_list = $site->loadList();
                                    $pages_url  = [];

                                    foreach ($pages_list as $page_list) {
                                        if ( ! empty($page_list['url'])) {
                                            $pages_url[] = $page_list['url'];
                                        }
                                    }

                                    if (empty($pages_url)) {
                                        throw new \Exception(sprintf('На ресурсе %s не найдены страницы ведущие по какому-либо адресу. Проверьте правила поиска', $config->start_url));
                                    }

                                    $pages_item = $site->loadPages($pages_url);

                                    foreach ($pages_item as $page_item) {
                                        $options = [];

                                        foreach ($pages_list as $page_list) {
                                            if ($page_list['url'] == $page_item['url']) {
                                                $options['date_publish'] = $page_list['date_publish'];
                                                $options['title']        = $page_list['title'];
                                                $options['count_views']  = $page_list['count_views'];
                                                $options['tags']         = $page_list['tags'];
                                                $options['categories']   = $page_list['categories'];
                                                break;
                                            }
                                        }

                                        $content = [
                                            'content_type' => $content_type,
                                            'section_name' => $section_name,
                                            'content'      => $page_item['content'],
                                        ];

                                        $loader->saveSourceContent($source_id, $page_item['url'], $content, $options);


                                        $this->apiMetrics->incPrometheus('core2_sources_site_load', 1, [
                                            'labels'   => ['action' => 'site_page'],
                                            'job'      => 'core2',
                                            'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                                        ]);
                                    }
                                    break;

                                case 'json': break;
                                case 'text': break;
                            }


                            $this->apiMetrics->incPrometheus('core2_sources_site_load', 1, [
                                'labels'   => ['action' => 'site'],
                                'job'      => 'core2',
                                'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                            ]);

                        } catch (\Exception $e) {
                            echo $e->getMessage() . PHP_EOL;

                            $this->apiMetrics->incPrometheus('core2_sources_site_load', 1, [
                                'labels'   => ['action' => 'site_empty'],
                                'job'      => 'core2',
                                'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                            ]);
                        }
                    }
                }
            }
        }
    }


    /**
     * Сайты: Обработка страниц
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function parseSources(): void {

        $configs      = (new Sources\Sites\Model())->getConfigs();
        $test_sources = [
            //'relax.by'
        ];

        if ($configs) {
            $loader = new Sources\Sites\Etl\Loader();
            $model  = new Sources\Model();

            $this->modSources->dataSourcesSitesContentsRaw->refreshStatusRows();

            foreach ($configs as $name => $config) {

                if ( ! empty($test_sources) && ! in_array($name, $test_sources)) {
                    continue;
                }

                if ( ! $config?->source || ! $config->source?->title) {
                    continue;
                }

                $source = $this->modSources->dataSourcesSites->getRowByTitle($config->source->title);

                if (empty($source)) {
                    continue;
                }

                $pages_raw    = $this->modSources->dataSourcesSitesContentsRaw->getRowsPendingBySourceId($source->id);
                $count_errors = 0;

                foreach ($pages_raw as $page_raw) {
                    try {
                        $section_name    = $page_raw->section_name ?: 'main';
                        $source_sections = $config->toArray();
                        $section         = $source_sections["data__{$section_name}"] ?? [];

                        if (empty($section)) {
                            throw new \Exception("В конфигурации {$name} не найден раздел [data__{$section_name}]");
                        }

                        $page_options = $page_raw->options ? json_decode($page_raw->options, true) : [];

                        switch ($page_raw->content_type) {
                            case 'html':
                                if (empty($section['page']) ||
                                    empty($section['page']['title']) ||
                                    empty($section['page']['content']) ||
                                    empty($section['page']['date_publish'])
                                ) {
                                    continue 2;
                                }


                                $page_raw->status = 'process';
                                $page_raw->save();

                                $file_content = $model->getSourceFile('sites', new \DateTime($page_raw->date_created), $page_raw->file_name);
                                $content      = gzuncompress(base64_decode($file_content['content']));

                                $site = new Sources\Sites\Site($section);
                                $page = $site->parsePage($page_raw->url, $content);


                                if (empty($page['title']) && ! empty($page_options['title']))               { $page['title'] = $page_options['title']; }
                                if (empty($page['count_views']) && ! empty($page_options['count_views']))   { $page['count_views'] = $page_options['count_views']; }
                                if (empty($page['date_publish']) && ! empty($page_options['date_publish'])) { $page['date_publish'] = $page_options['date_publish']; }
                                if (empty($page['tags']) && ! empty($page_options['tags']))                 { $page['tags'] = $page_options['tags']; }
                                if (empty($page['categories']) && ! empty($page_options['categories']))     { $page['categories'] = $page_options['categories']; }
                                if (empty($page['region']) && ! empty($page_options['region']))             { $page['region'] = $page_options['region']; }
                                if (empty($page['image']) && ! empty($page_options['image']))               { $page['image'] = $page_options['image']; }

                                $loader->savePage($page_raw->source_id, $page);

                                $page_raw->status = 'complete';
                                $page_raw->note   = null;
                                $page_raw->save();


                                $this->apiMetrics->incPrometheus('core2_sources_site_process', 1, [
                                    'labels'   => ['action' => 'site_page'],
                                    'job'      => 'core2',
                                    'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                                ]);
                                break;

                            case 'json': break;
                            case 'text': break;

                            default:
                                throw new \Exception("Неизвестный тип данных {$page_raw->content_type}");
                        }

                    } catch (\Exception $e) {
                        $page_raw->status = "error";
                        $page_raw->note   = mb_substr($e->getMessage(), 0, 250);
                        $page_raw->save();

                        echo $e->getMessage() . PHP_EOL;
                        $count_errors++;

                        if ($count_errors >= 3) {
                            break;
                        }
                    }
                }
            }
        }
    }


    /**
     * Телеграм: Получение истории сообщений из чатов
     * @return void
     * @throws \danog\MadelineProto\Exception
     * @throws Exception
     */
    public function loadTgHistory(): void {

        $tg          = new Sources\Chats\Telegram();
        $tg_accounts = $tg->getAccounts( ['history'] );

        foreach ($tg_accounts as $tg_account) {
            if ( ! $tg_account->isActiveMethod('getHistory')) {
                echo "Метод неактивен у аккаунта {$tg_account->getApiId()}" .PHP_EOL;
                continue;
            }

            $chat = $this->modSources->dataSourcesChats->fetchRow(
                $this->modSources->dataSourcesChats->select()
                    ->where("messenger_type = 'tg'")
                    ->where("type = 'channel'")
                    ->where("is_connect_sw = 'Y'")
                    ->where("date_history_load IS NULL OR date_old_message IS NULL OR date_old_message > date_history_load")
                    ->where("old_message_id IS NULL OR old_message_id > 1")
                    ->order("subscribers_count DESC")
                    ->limit(1)
            );

            if (empty($chat)) {
                return;
            }

            try {
                $date_history        = '2020-01-01';
                $chat_message_old_id = $chat->old_message_id ?: null;
                $chat_message_new_id = $chat->new_message_id ?: null;
                $chat_date_old       = $chat->date_old_message ? new \DateTime($chat->date_old_message) : null;
                $chat_date_new       = $chat->date_new_message ? new \DateTime($chat->date_new_message) : null;

                $peer = $chat->peer_name ? "@{$chat->peer_name}" : $chat->peer_id;

                $result = $tg_account->messages->getHistory($peer, [
                    'offset_id' => (int)$chat_message_old_id,
                ]);

                if ( ! empty($result) && ! empty($result['messages'])) {
                    $content      = json_encode($result, JSON_UNESCAPED_UNICODE);
                    $content_hash = md5($content);
                    $chat_content = $this->modSources->dataSourcesChatsContent->getRowByTypeHash('tg_history_channel', $content_hash);

                    if (empty($chat_content)) {
                        $request_old_date = null;
                        $request_new_date = null;
                        $request_old_id   = null;
                        $request_new_id   = null;
                        $count_messages   = count($result['messages']);

                        foreach($result['messages'] as $message) {
                            if (empty($message['date']) || empty($message['id'])) {
                                continue;
                            }

                            $message_date = (new \DateTime())->setTimestamp($message['date']);

                            if (is_null($request_old_date) || $request_old_date > $message_date) {
                                $request_old_date = $message_date;
                            }

                            if (is_null($request_new_date) || $request_new_date < $message_date) {
                                $request_new_date = $message_date;
                            }

                            if (is_null($request_old_id) || $request_old_id > $message['id']) {
                                $request_old_id = $message['id'];
                            }

                            if (is_null($request_new_id) || $request_new_id < $message['id']) {
                                $request_new_id = $message['id'];
                            }
                        }


                        if ($request_new_id) {
                            $chat_date_old = min($chat_date_old, $request_old_date);
                            $chat_date_new = max($chat_date_new, $request_new_date);

                            $chat_message_old_id = ! is_null($chat_message_old_id) ? min($chat_message_old_id, $request_old_id) : $request_old_id;
                            $chat_message_new_id = ! is_null($chat_message_new_id) ? max($chat_message_new_id, $request_new_id) : $request_new_id;


                            if (empty($chat_date_old) || $chat_date_old > $message_date) {
                                $chat_date_old = $message_date;
                            }

                            if (empty($chat_date_new) || $chat_date_new < $message_date) {
                                $chat_date_new = $message_date;
                            }

                            $this->modSources->dataSourcesChatsContent->saveContent('tg_history_channel', $result, [
                                'peer_name'         => $chat->peer_name,
                                'count_messages'    => $count_messages,
                                'date_old_messages' => $request_old_date->format('Y-m-d H:i:s'),
                                'date_new_messages' => $request_new_date->format('Y-m-d H:i:s'),
                                'old_messages_id'   => $request_old_id,
                                'new_messages_id'   => $request_new_id,
                            ]);

                            if ( ! $chat->date_history_load) {
                                $chat->date_history_load = $date_history;
                            }

                            $chat->old_message_id   = $chat_message_old_id;
                            $chat->new_message_id   = $chat_message_new_id;
                            $chat->date_old_message = $chat_date_old->format('Y-m-d H:i:s');
                            $chat->date_new_message = $chat_date_new->format('Y-m-d H:i:s');
                            $chat->save();


                            $this->apiMetrics->incPrometheus('core2_sources_tg_load', $count_messages, [
                                'labels'   => ['action' => 'channel_history'],
                                'job'      => 'core2',
                                'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                            ]);
                        }
                    }
                }

            } catch (\Exception $e) {
                echo "Account: {$tg_account->getApiId()}" .PHP_EOL;
                echo "Chat: {$peer}" .PHP_EOL;
                echo $e->getMessage() .PHP_EOL;
                echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;

                if ($e->getMessage() == 'The channel was already closed!') {
                    $tg_account->service->restart();

                } elseif ($e->getMessage() == 'This peer is not present in the internal peer database' && $chat->peer_name) {
                    // Если не удастся получить канал из телеги, то выключать его
                    try {
                        $tg_account->dialogs->getDialogInfo($chat->peer_name);
                    } catch (\Exception $e) {
                        $chat->is_connect_sw = 'N';
                        $chat->save();

                        echo "Account: {$tg_account->getApiId()}" .PHP_EOL;
                        echo "Chat: {$peer}" .PHP_EOL;
                        echo $e->getMessage() .PHP_EOL;
                        echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;
                    }

                } elseif (strpos($e->getMessage(), 'FLOOD_WAIT_') === 0) {
                    $ban_seconds = substr($e->getMessage(), strlen('FLOOD_WAIT_'));

                    if (is_numeric($ban_seconds)) {
                        $tg_account->inactiveMethod('getHistory', $ban_seconds);
                    }

                    $this->sendErrorMessage("Tg аккаунт {$tg_account->getApiId()} неактивен на {$ban_seconds} секунд: loadTgHistory");
                }
            }
        }
    }


    /**
     * Телеграм: Получение новых обновлений
     * @return void
     * @throws \danog\MadelineProto\Exception
     * @throws Exception
     */
    public function loadTgUpdates(): void {

        $setting = $this->modAdmin->dataSettings->fetchRow(
            $this->modAdmin->dataSettings->select()
                ->where("code = 'tg_update_id'")
        );

        if (empty($setting)) {
            $seq = 1 + (int)$this->db->fetchOne("
                SELECT MAX(seq)
                FROM core_settings 
            ");

            $setting = $this->modAdmin->dataSettings->createRow([
                'system_name'    => 'ID обновления в телеграмм',
                'value'          => null,
                'code'           => 'tg_update_id',
                'visible'        => 'Y',
                'is_custom_sw'   => 'Y',
                'is_personal_sw' => 'N',
                'type'           => 'text',
                'seq'            => $seq,
            ]);
            $setting->save();
        }

        $updates_id = $setting && $setting->value
            ? @json_decode($setting->value, true)
            : [];

        if ( ! is_array($updates_id)) {
            $updates_id = [];
        }


        $tg          = new Sources\Chats\Telegram();
        $tg_accounts = $tg->getAccounts( ['updates'] );

        foreach ($tg_accounts as $tg_account) {
            if ( ! $tg_account->isActiveMethod('getUpdate')) {
                echo "Метод неактивен у аккаунта {$tg_account->getApiId()}" .PHP_EOL;
                continue;
            }

            try {
                $api_id    = $tg_account->getApiId();
                $update_id = (int)($updates_id[$api_id] ?? 0);

                $updates = $tg_account->updates->get($update_id + 1);

                if ( ! empty($updates)) {
                    foreach ($updates as $key => $update) {
                        if ( ! empty($update['update_id']) && $update['update_id'] > $update_id) {
                            $update_id           = (int)$update['update_id'];
                            $updates_id[$api_id] = $update_id;
                        }

                        if ( ! empty($update['update']) &&
                             ! empty($update['update']['_']) &&
                            in_array($update['update']['_'], [
                                'updateChannelUserTyping',
                                'updateUserStatus',
                                'updateMessagePoll',
                                'updateGroupCall'
                            ])
                        ) {
                            unset($updates[$key]);
                        }
                    }

                    if ( ! empty($updates)) {
                        $this->modSources->dataSourcesChatsContent->saveContent('tg_updates', $updates, [
                            'count_updates' => count($updates),
                        ]);

                        $this->apiMetrics->incPrometheus('core2_sources_tg_load', count($updates), [
                            'labels'   => ['action' => 'channel_update'],
                            'job'      => 'core2',
                            'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                        ]);
                    }
                }

            } catch (\Exception $e) {
                echo "Account: {$tg_account->getApiId()}" .PHP_EOL;
                echo $e->getMessage() .PHP_EOL;
                echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;

                if ($e->getMessage() == 'The channel was already closed!') {
                    $tg_account->service->restart();

                } elseif (strpos($e->getMessage(), 'FLOOD_WAIT_') === 0) {
                    $ban_seconds = substr($e->getMessage(), strlen('FLOOD_WAIT_'));

                    if (is_numeric($ban_seconds)) {
                        $tg_account->inactiveMethod('getUpdate', $ban_seconds);
                    }

                    $this->sendErrorMessage("Tg аккаунт {$tg_account->getApiId()} неактивен на {$ban_seconds} секунд: loadTgUpdates");
                }
            }
        }

        $setting->value = json_encode($updates_id);
        $setting->save();
    }


    /**
     * Телеграм: Получение информации о каналах и группах
     * @return void
     * @throws \danog\MadelineProto\Exception
     * @throws Exception
     */
    public function loadTgPeersInfo(): void {

        $tg          = new Sources\Chats\Telegram();
        $tg_accounts = $tg->getAccounts( ['chat_info'] );

        foreach ($tg_accounts as $tg_account) {
            if ( ! $tg_account->isActiveMethod('getDialog')) {
                echo "Метод неактивен у аккаунта {$tg_account->getApiId()}" .PHP_EOL;
                continue;
            }

            $chat = $this->modSources->dataSourcesChats->fetchRow(
                $this->modSources->dataSourcesChats->select()
                    ->where("messenger_type = 'tg'")
                    ->where("is_connect_sw = 'Y' OR peer_name IS NULL OR date_state_info IS NULL")
                    ->where("date_state_info IS NULL OR date_state_info < ?", date('Y-m-d'))
                    ->order(new Zend_Db_Expr("is_connect_sw = 'Y' DESC"))
                    ->order("date_state_info ASC")
                    ->limit(1)
            );

            if (empty($chat)) {
                continue;
            }

            try {
                $peer = $chat->peer_name ? "@{$chat->peer_name}" : $chat->peer_id;

                try {
                    $dialog = $tg_account->dialogs->getDialogPwr($peer);
                } catch (\Exception $e) {
                    if ($e->getMessage() == 'CHANNEL_PRIVATE') {
                        $dialog = $tg_account->dialogs->getDialogPwr($peer, false);
                    } else {
                        throw $e;
                    }
                }

                if ($dialog) {
                    if ( ! $chat->peer_id && ! empty($dialog['id'])) {
                        $chat->peer_id = $dialog['id'];
                    }
                    if ( ! empty($dialog['title'])) {
                        $chat->title = $dialog['title'];
                    }
                    if ( ! empty($dialog['username'])) {
                        $chat->peer_name = $dialog['username'];
                    }
                    if ( ! empty($dialog['participants_count'])) {
                        $chat->subscribers_count = $dialog['participants_count'];
                    }
                    if ( ! empty($dialog['about'])) {
                        $chat->description = $dialog['about'];
                    }

                    $this->modSources->dataSourcesChatsContent->saveContent('tg_dialogs_info', $dialog, [
                        'peer_id'   => $dialog['id'] ?? null,
                        'peer_name' => $dialog['username'] ?? null,
                    ]);

                    $this->apiMetrics->incPrometheus('core2_sources_tg_load', 1, [
                        'labels'   => ['action' => 'channel_info'],
                        'job'      => 'core2',
                        'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                    ]);
                }

            } catch (\Exception $e) {
                echo "Account: {$tg_account->getApiId()}" .PHP_EOL;
                echo "Chat: {$peer}" .PHP_EOL;
                echo $e->getMessage() .PHP_EOL;
                echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;

                if ($e->getMessage() == 'The channel was already closed!') {
                    $tg_account->service->restart();

                } elseif (strpos($e->getMessage(), 'FLOOD_WAIT_') === 0) {
                    $ban_seconds = substr($e->getMessage(), strlen('FLOOD_WAIT_'));

                    if (is_numeric($ban_seconds)) {
                        $tg_account->inactiveMethod('getDialog', $ban_seconds);
                    }

                    $this->sendErrorMessage("Tg аккаунт {$tg_account->getApiId()} неактивен на {$ban_seconds} секунд: loadTgPeersInfo");
                }
            }

            $chat->date_state_info = new \Zend_Db_Expr('NOW()');
            $chat->save();
        }
    }


    /**
     * Телеграм: Загрузка списка топовых каналов и групп
     * @return void
     * @throws Exception
     */
    public function loadTgTopChannels(): void {

        $tg_stat = new Sources\Chats\TgStat([
            'debug_requests' => true,
            'cache_dir'      => realpath(DOC_ROOT . "../tmp/tgstat")
        ]);

        $domains      = $tg_stat->getDomains() ?: $tg_stat->getDomainsDefault();
        $top_domains  = [];
        $top_channels = [
            'members',
            // 'members_t',
            // 'members_y',
            // 'members_7d',
            'members_30d',
            'reach',
            'ci',
        ];
        $top_groups = [
            'members',
            // 'members_t',
            // 'members_y',
            // 'members_7d',
            'members_30d',
            'msgs',
            'mau',
        ];

        // Получение данных
        foreach ($domains as $domain => $domain_title) {
            $top_domains[$domain]['channels'] = $tg_stat->getTopChannels($domain, [ 'top_list' => $top_channels ]);
            $top_domains[$domain]['groups']   = $tg_stat->getTopGroups($domain, [ 'top_list' => $top_groups ]);
        }


        // Сохранение
        foreach ($top_domains as $domain => $top_domain) {

            // Каналы
            foreach ($top_domain['channels'] as $category) {

                foreach ($category['tops'] as $top => $channels) {

                    foreach ($channels as $channel) {
                        if (empty($channel['name'])) {
                            continue;
                        }

                        $chat = $this->modSources->dataSourcesChats->getRowByTgChannelPeer($channel['name']);

                        if (empty($chat)) {
                            $tgstat = [
                                $top => [
                                    'subscribers_count' => $channel['subscribers_count'] ?? null,
                                    'reach_count'       => $channel['reach_count'] ?? null,
                                    'index_citation'    => $channel['index_citation'] ?? null,
                                    'date_update'       => date('Y-m-d'),
                                ],
                            ];

                            $subscribers_count = strpos($top, 'members_') === false && ! empty($channel['subscribers_count'])
                                ? (int)$channel['subscribers_count']
                                : null;

                            $chat = $this->modSources->dataSourcesChats->createRow([
                                'messenger_type'    => 'tg',
                                'type'              => 'channel',
                                'peer_name'         => $channel['name'],
                                'title'             => $channel['title'],
                                'subscribers_count' => $subscribers_count,
                                'geolocation'       => $domains[$domain],
                                'tgstat'            => json_encode($tgstat),
                            ]);
                            $chat->save();

                        } else {
                            $tgstat = $chat->tgstat ? json_decode($chat->tgstat, true) : null;
                            $tgstat = is_array($tgstat) ? $tgstat : [];

                            $tgstat[$top]['subscribers_count'] = $channel['subscribers_count'] ?? null;
                            $tgstat[$top]['reach_count']       = $channel['reach_count'] ?? null;
                            $tgstat[$top]['index_citation']    = $channel['index_citation'] ?? null;
                            $tgstat[$top]['date_update']       = date('Y-m-d');

                            $chat->tgstat = json_encode($tgstat);
                            $chat->save();
                        }


                        $category_row = $this->modSources->dataSourcesChatsCategories->getRowByTitle($channel['category_title']);
                        if (empty($category_row)) {
                            $category_row = $this->modSources->dataSourcesChatsCategories->createRow([
                                'title' => $channel['category_title'],
                            ]);
                            $category_row->save();
                        }

                        $link_category = $this->modSources->dataSourcesChatsCategoriesLink->getRowByChatCategory($chat->id, $category_row->id);
                        if ( ! $link_category) {
                            $link_category = $this->modSources->dataSourcesChatsCategoriesLink->createRow([
                                'chat_id'     => $chat->id,
                                'category_id' => $category_row->id,
                            ]);
                            $link_category->save();
                        }
                    }
                }
            }


            // Группы
            foreach ($top_domain['groups'] as $category) {

                foreach ($category['tops'] as $top => $groups) {

                    foreach ($groups as $group) {
                        if (empty($group['name'])) {
                            continue;
                        }

                        $chat = $this->modSources->dataSourcesChats->getRowByTgGroupPeer($group['name']);

                        if (empty($chat)) {
                            $tgstat = [
                                $top => [
                                    'subscribers_count' => $channel['subscribers_count'] ?? null,
                                    'messages_7d_count' => $channel['messages_7d_count'] ?? null,
                                    'mau_count'         => $channel['mau_count'] ?? null,
                                    'date_update'       => date('Y-m-d'),
                                ],
                            ];

                            $subscribers_count = strpos($top, 'members_') === false && ! empty($channel['subscribers_count'])
                                ? (int)$channel['subscribers_count']
                                : null;

                            $chat = $this->modSources->dataSourcesChats->createRow([
                                'messenger_type'    => 'tg',
                                'type'              => 'group',
                                'peer_name'         => $group['name'],
                                'title'             => $group['title'],
                                'subscribers_count' => $subscribers_count,
                                'geolocation'       => $domains[$domain],
                                'tgstat'            => json_encode($tgstat),
                            ]);
                            $chat->save();

                        } else {
                            $tgstat = $chat->tgstat ? json_decode($chat->tgstat, true) : null;
                            $tgstat = is_array($tgstat) ? $tgstat : [];

                            $tgstat[$top]['subscribers_count'] = $channel['subscribers_count'] ?? null;
                            $tgstat[$top]['messages_7d_count'] = $channel['messages_7d_count'] ?? null;
                            $tgstat[$top]['mau_count']         = $channel['mau_count'] ?? null;
                            $tgstat[$top]['date_update']       = date('Y-m-d');

                            $chat->tgstat = json_encode($tgstat);
                            $chat->save();
                        }


                        $category_row = $this->modSources->dataSourcesChatsCategories->getRowByTitle($group['category_title']);
                        if (empty($category_row)) {
                            $category_row = $this->modSources->dataSourcesChatsCategories->createRow([
                                'title' => $group['category_title'],
                            ]);
                            $category_row->save();
                        }

                        $link_category = $this->modSources->dataSourcesChatsCategoriesLink->getRowByChatCategory($chat->id, $category_row->id);
                        if ( ! $link_category) {
                            $link_category = $this->modSources->dataSourcesChatsCategoriesLink->createRow([
                                'chat_id'     => $chat->id,
                                'category_id' => $category_row->id,
                            ]);
                            $link_category->save();
                        }
                    }
                }
            }


            $this->apiMetrics->incPrometheus('core2_sources_tg_load', count($top_domain['channels']), [
                'labels'   => ['action' => 'tgstat_channels'],
                'job'      => 'core2',
                'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
            ]);

            $this->apiMetrics->incPrometheus('core2_sources_tg_load', count($top_domain['groups']), [
                'labels'   => ['action' => 'tgstat_groups'],
                'job'      => 'core2',
                'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
            ]);
        }
    }


    /**
     * Телеграм: Управление подписками на каналы
     * @return void
     * @throws Exception
     */
    public function subscribeTgChannels(): void {

        $tg          = new Sources\Chats\Telegram();
        $tg_accounts = $tg->getAccounts( ['updates'] );

        foreach ($tg_accounts as $tg_account) {
            if ( ! $tg_account->isActiveMethod('subscribe')) {
                echo "Метод неактивен у аккаунта {$tg_account->getApiId()}" .PHP_EOL;
                continue;
            }

            $api_id          = $tg_account->getApiId();
            $subscribe_chats = $this->db->fetchAll("
                SELECT scas.id,
                       sc.peer_id,
                       sc.peer_name,
                       scas.chat_id,
                       scas.is_subscribe_need_sw
                FROM mod_sources_chats_accounts AS sca
                    JOIN mod_sources_chats_accounts_subscribes AS scas ON sca.id = scas.account_id
                    JOIN mod_sources_chats AS sc ON scas.chat_id = sc.id
                WHERE sca.account_key = ?
                  AND scas.is_subscribe_need_sw IS NOT NULL
                LIMIT 3
            ", $api_id);


            foreach ($subscribe_chats as $subscribe_chat) {

                $subscribe = $this->modSources->dataSourcesChatsAccountsSubscribes->find($subscribe_chat['id'])->current();

                try {
                    $peer = $subscribe_chat['peer_id'] ?: $subscribe_chat['peer_name'];

                    if ($subscribe_chat['is_subscribe_need_sw'] == 'Y') {
                        $tg_account->dialogs->joinChannel($peer);
                    } else {
                        $tg_account->dialogs->leaveChannel($peer);
                    }

                    $subscribe->is_subscribe_sw      = $subscribe_chat['is_subscribe_need_sw'];
                    $subscribe->is_subscribe_need_sw = null;
                    $subscribe->save();

                } catch (\Exception $e) {
                    echo "Account: {$tg_account->getApiId()}" .PHP_EOL;
                    echo $e->getMessage() .PHP_EOL;
                    echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;

                    if ($e->getMessage() == 'The channel was already closed!') {
                        $tg_account->service->restart();

                    } elseif ($e->getMessage() == 'This peer is not present in the internal peer database' && $subscribe_chat['peer_name']) {
                        // Если не удастся получить канал из телеги, то выключать его
                        try {
                            $tg_account->dialogs->getDialogInfo($subscribe_chat['peer_name']);
                        } catch (\Exception $e) {
                            $subscribe->is_subscribe_need_sw = null;
                            $subscribe->save();

                            echo "Chat: {$subscribe_chat['peer_name']}" .PHP_EOL;
                        }

                    } elseif (strpos($e->getMessage(), 'FLOOD_WAIT_') === 0) {
                        $ban_seconds = substr($e->getMessage(), strlen('FLOOD_WAIT_'));

                        if (is_numeric($ban_seconds)) {
                            $tg_account->inactiveMethod('subscribe', $ban_seconds);
                        }

                        $this->sendErrorMessage("Аккаунт {$tg_account->getApiId()} неактивен на {$ban_seconds} секунд");
                    }
                }
            }
        }
    }


    /**
     * Телеграм: Обработка загруженных данных
     * @return void
     * @throws Exception
     */
    public function parseTgContent(): void {

        $tg_parser = new Sources\Chats\TgParser();

        $tg_parser->processDialogInfo(100);
        $tg_parser->processHistory(100);
        $tg_parser->processUpdates(100);
    }


    /**
     * Телеграм: Запуск сервиса
     * @return void
     * @throws Exception
     */
    public function startTgService(): void {

        (new Sources\Chats\Telegram())->start();
    }


    /**
     * Телеграм: Остановка сервиса
     * @param string|null $force
     * @return void
     */
    public function stopTgService(string $force = null): void {

        (new Sources\Chats\Telegram())->stop((bool)$force);
    }


    /**
     * Телеграм: Перезапуск сервиса
     * @return void
     * @throws Exception
     */
    public function restartTgService(): void {

        (new Sources\Chats\Telegram())->restart();
    }


    /**
     * Телеграм: Показ содержимого записи из загруженных данных
     * @param int $content_id
     * @return void
     * @throws Exception
     * @no_cron
     */
    public function viewTgContent(int $content_id): void {

        $row = $this->modSources->dataSourcesChatsContent->find($content_id)->current();

        if (empty($row)) {
            throw new \Exception('Данные с таким id не найдены');
        }

        echo '<pre>';
        print_r(json_decode(gzuncompress($row->content_bin), true));
        echo '</pre>';
    }


    /**
     * Телеграм: логин в клиенте. Отправка кода на телефон
     * @param string $phone
     * @return void
     * @throws Zend_Config_Exception
     * @throws Exception
     * @no_cron
     */
    public function loginTgSendCode(string $phone): void {

        $tg = new Sources\Chats\Telegram();

        $tg_account = $tg->getAccountByPhone($phone);

        if (empty($tg_account)) {
            throw new \Exception("В конфигурации модуля не найден аккаунт с таким телефоном: {$phone}");
        }

        $tg_account->account->loginPhone();
    }


    /**
     * Телеграм: логин в клиенте. Установка ранее отправленного кода
     * @param string $phone
     * @param string $code
     * @return void
     * @throws Exception
     * @no_cron
     */
    public function loginTgSetCode(string $phone, string $code): void {

        $tg = new Sources\Chats\Telegram();

        $tg_account = $tg->getAccountByPhone($phone);

        if (empty($tg_account)) {
            throw new \Exception("В конфигурации модуля не найден аккаунт с таким телефоном: {$phone}");
        }

        $tg_account->account->completePhone($code);
    }


    /**
     * Телеграм: логин в клиенте. Установка ранее отправленного кода и пароля
     * @param string $phone
     * @param string $code
     * @param string $password
     * @return void
     * @throws Exception
     * @no_cron
     */
    public function loginTgSetPassword(string $phone, string $code, string $password): void {

        $tg = new Sources\Chats\Telegram();

        $tg_account = $tg->getAccountByPhone($phone);

        if (empty($tg_account)) {
            throw new \Exception("В конфигурации модуля не найден аккаунт с таким телефоном: {$phone}");
        }

        $tg_account->account->complete2faLogin($code, $password);
    }


    /**
     * @return void
     * @throws Exception
     * @no_cron
     */
    public function tgTest(): void {

        $tg = new Core2\Mod\Sources\Chats\Telegram();
        $account = $tg->getAccountByApiId(25983009);

        echo '<pre>';
        print_r($account->updates->get(1));
        echo '</pre>';
    }


    /**
     * @return void
     * @throws Exception
     * @no_cron
     */
    public function ytTest(): void {

        $yt         = new Sources\Video\YouTube();
        $yt_account = $yt->getAccountByNmbr(2);

        echo '<pre>';
        print_r($yt_account->getChannelInfoById('UCSVR88l2gs4_RB3jmNyVhzA'));
        echo '</pre>';
    }


    /**
     * Видео: Показ содержимого записи из загруженных данных
     * @param int $content_id
     * @return void
     * @throws Exception
     * @no_cron
     */
    public function viewVideoContent(int $content_id): void {

        $row = $this->modSources->dataSourcesVideosRaw->find($content_id)->current();

        if (empty($row)) {
            throw new \Exception('Данные с таким id не найдены');
        }

        echo '<pre>';
        print_r(json_decode(gzuncompress($row->content), true));
        echo '</pre>';
    }


    /**
     * Видео: Поиск в загруженных данных
     * @param string $type
     * @param string $query
     * @param int    $limit
     * @return void
     * @throws Exception
     * @no_cron
     */
    public function searchVideoContent(string $type = '', string $query = '', int $limit = 50): void {

        if (empty($type) || empty($query)) {
            throw new \Exception('Укажите тип и поисковую строку');
        }

        $rows = $this->db->fetchAll("
            SELECT svr.id, svr.content
            FROM mod_sources_videos_raw AS svr
            WHERE type = ?
              AND UNCOMPRESS(svr.content) LIKE ?
            LIMIT ?
        ", [
            $type,
            "%{$query}%",
            $limit
        ]);

        if ( ! empty($rows)) {
            foreach ($rows as $row) {
                echo '<pre>';
                print_r(json_decode(gzuncompress($row['content']), true));
                echo '</pre>';
            }
        }
    }


    /**
     * YouTube: Получение информации о каналах
     * @return void
     * @throws Zend_Config_Exception
     */
    public function loadYtChannelsInfo(): void {

        $yt          = new Sources\Video\YouTube();
        $yt_accounts = $yt->getAccounts( ['channel_info'] );

        foreach ($yt_accounts as $yt_account) {
            if ( ! $yt_account->isActiveMethod('yt_account')) {
                echo "Метод неактивен у аккаунта {$yt_account->getNmbr()}" . PHP_EOL;
                continue;
            }

            $channel = $this->modSources->dataSourcesVideos->fetchRow(
                $this->modSources->dataSourcesVideos->select()
                    ->where("type = 'yt'")
                    ->where("channel_id IS NOT NULL OR name IS NOT NULL")
                    ->where("is_load_info_sw = 'N'")
                    ->order(new Zend_Db_Expr("is_connect_sw = 'Y' DESC"))
                    ->order(new Zend_Db_Expr("name IS NULL DESC"))
                    ->order("subscribers_count DESC")
                    ->limit(1)
            );

            if (empty($channel)) {
                continue;
            }

            try {
                $channel_info = $channel->channel_id
                    ? $yt_account->getChannelInfoById($channel->channel_id)
                    : $yt_account->getChannelInfoByName($channel->name);

                if ( ! empty($channel_info)) {
                    $this->modSources->dataSourcesVideosRaw->saveContent('yt_channel_info', $channel_info, [
                        'channel_id' => $channel->channel_id,
                        'name'       => $channel->name,
                    ]);
                }

                $channel->date_state_info = new \Zend_Db_Expr('NOW()');
                $channel->is_load_info_sw = 'Y';
                $channel->save();

                $this->apiMetrics->incPrometheus('core2_sources_yt_load', 1, [
                    'labels'   => ['action' => 'channel_info'],
                    'job'      => 'core2',
                    'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                ]);

            } catch (\Exception $e) {
                echo "Account: {$yt_account->getNmbr()}" .PHP_EOL;
                echo $e->getMessage() .PHP_EOL;
                echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;

                if (str_contains($e->getMessage(), 'quotaExceeded') ||
                    str_contains($e->getMessage(), 'Error 403 Permission denied')
                ) {
                    $yt_account->inactiveMethod('yt_account');

                } elseif (empty($e->getMessage()) ||
                    str_contains($e->getMessage(), 'Empty reply from server') ||
                    str_contains($e->getMessage(), 'SERVICE_UNAVAILABLE') ||
                    str_contains($e->getMessage(), '500 Internal Server Error') ||
                    str_contains($e->getMessage(), '500 Internal error encountered') ||
                    str_contains($e->getMessage(), '503 Service Unavailable') ||
                    str_contains($e->getMessage(), '503 The service is currently unavailable') ||
                    str_contains($e->getMessage(), '502 Bad Gateway')
                ) {
                    continue;

                } else {
                    $this->sendErrorMessage('Неизвестная ошибка при получении полного описания о канале', $e);
                }
            }
        }
    }


    /**
     * YouTube: Получение статистики о каналах
     * @return void
     * @throws Zend_Config_Exception
     */
    public function loadYtChannelsStats(): void {

        $yt          = new Sources\Video\YouTube();
        $yt_accounts = $yt->getAccounts( ['channel_stat'] );

        foreach ($yt_accounts as $yt_account) {
            if ( ! $yt_account->isActiveMethod('yt_account')) {
                echo "Метод неактивен у аккаунта {$yt_account->getNmbr()}" . PHP_EOL;
                continue;
            }


            $channels_id = $this->db->fetchPairs("
                SELECT sv.id,
                       sv.channel_id  
                FROM mod_sources_videos AS sv
                WHERE sv.type = 'yt'
                  AND sv.is_connect_sw = 'Y'
                  AND sv.channel_id IS NOT NULL
                  AND (sv.date_state_info IS NULL OR sv.date_state_info < NOW())
                ORDER BY sv.date_state_info ASC
                LIMIT 50
            ");

            if (empty($channels_id)) {
                continue;
            }

            try {
                $channels_info = $yt_account->getChannelsInfoById(array_values($channels_id), [ 'id', 'statistics' ]);

                if ( ! empty($channels_info)) {
                    $this->modSources->dataSourcesVideosRaw->saveContent('yt_channels_stats', $channels_info, [
                        'count' => count($channels_info),
                    ]);

                    foreach ($channels_info as $channel_info) {
                        if (($channel_id = array_search($channel_info['id'], $channels_id)) !== false) {
                            $channel = $this->modSources->dataSourcesVideos->find($channel_id)->current();
                            $channel->date_state_info = new \Zend_Db_Expr('NOW()');
                            $channel->save();
                        }
                    }

                    $this->apiMetrics->incPrometheus('core2_sources_yt_load', count($channels_info), [
                        'labels'   => ['action' => 'channels_stat'],
                        'job'      => 'core2',
                        'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                    ]);
                }

            } catch (\Exception $e) {
                echo "Account: {$yt_account->getNmbr()}" .PHP_EOL;
                echo $e->getMessage() .PHP_EOL;
                echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;

                if (str_contains($e->getMessage(), 'quotaExceeded') ||
                    str_contains($e->getMessage(), 'Error 403 Permission denied')
                ) {
                    $yt_account->inactiveMethod('yt_account');

                } elseif (empty($e->getMessage()) ||
                    str_contains($e->getMessage(), 'Empty reply from server') ||
                    str_contains($e->getMessage(), 'SERVICE_UNAVAILABLE') ||
                    str_contains($e->getMessage(), '500 Internal Server Error') ||
                    str_contains($e->getMessage(), '500 Internal error encountered') ||
                    str_contains($e->getMessage(), '503 Service Unavailable') ||
                    str_contains($e->getMessage(), '503 The service is currently unavailable') ||
                    str_contains($e->getMessage(), '502 Bad Gateway')
                ) {
                    continue;

                } else {
                    $this->sendErrorMessage('Неизвестная ошибка при получении статистики из канала', $e);
                }
            }
        }
    }


    /**
     * YouTube: Получение истории видео из каналов
     * @return void
     * @throws Zend_Config_Exception
     */
    public function loadYtChannelsVideoHistory(): void {

        $yt          = new Sources\Video\YouTube();
        $yt_accounts = $yt->getAccounts( ['channel_videos'] );

        foreach ($yt_accounts as $yt_account) {
            if ( ! $yt_account->isActiveMethod('yt_account')) {
                echo "Метод неактивен у аккаунта {$yt_account->getNmbr()}" . PHP_EOL;
                continue;
            }


            $channel = $this->modSources->dataSourcesVideos->fetchRow(
                $this->modSources->dataSourcesVideos->select()
                    ->where("type = 'yt'")
                    ->where("is_connect_sw = 'Y'")
                    ->where("is_load_history_sw = 'N'")
                    ->where("channel_id IS NOT NULL")
                    ->order("subscribers_count DESC")
                    ->limit(1)
            );

            if (empty($channel)) {
                continue;
            }

            try {
                $date_published_before = $channel->date_load_old_clip
                    ? new \DateTime(date('Y-m-d H:i:s', strtotime($channel->date_load_old_clip) - 1))
                    : null;

                $channel_videos = $yt_account->getChannelVideos($channel->channel_id, [ 'published_before' => $date_published_before ]);


                if ( ! empty($channel_videos['results'])) {
                    $date_last_created = $channel->date_load_last_clip;
                    $date_old_created  = $channel->date_load_old_clip;

                    foreach ($channel_videos['results'] as $video) {
                        if ( ! empty($video['snippet']) &&
                             ! empty($video['snippet']['publishTime'])
                        ) {
                            $publish_time = date('Y-m-d H:i:s', strtotime($video['snippet']['publishTime']));

                            if (empty($date_last_created) ||
                                $date_last_created < $publish_time
                            ) {
                                $date_last_created = $publish_time;
                            }

                            if (empty($date_old_created) ||
                                $date_old_created > $publish_time
                            ) {
                                $date_old_created = $publish_time;
                            }
                        }
                    }

                    $this->modSources->dataSourcesVideosRaw->saveContent('yt_channel_videos', $channel_videos['results'], [
                        'count'      => count($channel_videos['results']),
                        'channel_id' => $channel->channel_id,
                    ]);

                    $channel->date_load_last_clip = $date_last_created;
                    $channel->date_load_old_clip  = $date_old_created;
                    $channel->save();

                    $this->apiMetrics->incPrometheus('core2_sources_yt_load', count($channel_videos['results']), [
                        'labels'   => ['action' => 'video_history'],
                        'job'      => 'core2',
                        'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                    ]);

                } else {
                    $channel->is_load_history_sw = 'Y';
                    $channel->save();
                }

            } catch (\Exception $e) {
                echo "Account: {$yt_account->getNmbr()}" .PHP_EOL;
                echo $e->getMessage() .PHP_EOL;
                echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;


                if (str_contains($e->getMessage(), 'quotaExceeded') ||
                    str_contains($e->getMessage(), 'Error 403 Permission denied')
                ) {
                    $yt_account->inactiveMethod('yt_account');

                } elseif (empty($e->getMessage()) ||
                    str_contains($e->getMessage(), 'Empty reply from server') ||
                    str_contains($e->getMessage(), 'SERVICE_UNAVAILABLE') ||
                    str_contains($e->getMessage(), '500 Internal Server Error') ||
                    str_contains($e->getMessage(), '500 Internal error encountered') ||
                    str_contains($e->getMessage(), '503 Service Unavailable') ||
                    str_contains($e->getMessage(), '503 The service is currently unavailable') ||
                    str_contains($e->getMessage(), '502 Bad Gateway')
                ) {
                    continue;

                } else {
                    $this->sendErrorMessage('Неизвестная ошибка при получении истории видео из канала', $e);
                }
            }
        }
    }


    /**
     * YouTube: Получение новых видео из каналов
     * @return void
     * @throws Zend_Config_Exception
     */
    public function loadYtChannelsVideo(): void {

        $yt          = new Sources\Video\YouTube();
        $yt_accounts = $yt->getAccounts( ['channel_videos'] );

        foreach ($yt_accounts as $yt_account) {
            if ( ! $yt_account->isActiveMethod('yt_account')) {
                echo "Метод неактивен у аккаунта {$yt_account->getNmbr()}" . PHP_EOL;
                continue;
            }


            $channel = $this->modSources->dataSourcesVideos->fetchRow(
                $this->modSources->dataSourcesVideos->select()
                    ->where("type = 'yt'")
                    ->where("is_connect_sw = 'Y'")
                    ->where("channel_id IS NOT NULL")
                    ->where("date_update_videos IS NULL OR date_update_videos != NOW()")
                    ->order("date_update_videos ASC")
                    ->limit(1)
            );

            if (empty($channel)) {
                continue;
            }

            try {
                $date_published_after = $channel->date_load_last_clip
                    ? new \DateTime(date('Y-m-d H:i:s', strtotime($channel->date_load_last_clip) + 1))
                    : null;

                $channel_videos = $yt_account->getChannelVideos($channel->channel_id, [ 'published_after' => $date_published_after ]);

                if ( ! empty($channel_videos['results'])) {
                    $date_last_created = $channel->date_load_last_clip;
                    $date_old_created  = $channel->date_load_old_clip;

                    foreach ($channel_videos['results'] as $video) {
                        if ( ! empty($video['snippet']) && ! empty($video['snippet']['publishTime'])) {
                            $publish_time = date('Y-m-d H:i:s', strtotime($video['snippet']['publishTime']));

                            if (empty($date_last_created) ||
                                $date_last_created < $publish_time
                            ) {
                                $date_last_created = $publish_time;
                            }

                            if (empty($date_old_created) ||
                                $date_old_created > $publish_time
                            ) {
                                $date_old_created = $publish_time;
                            }
                        }
                    }

                    $this->modSources->dataSourcesVideosRaw->saveContent('yt_channel_videos', $channel_videos['results'], [
                        'count'      => count($channel_videos['results']),
                        'channel_id' => $channel->channel_id,
                    ]);

                    $this->apiMetrics->incPrometheus('core2_sources_yt_load', count($channel_videos['results']), [
                        'labels'   => ['action' => 'video_new'],
                        'job'      => 'core2',
                        'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                    ]);

                    $channel->date_load_last_clip = $date_last_created;
                    $channel->date_load_old_clip  = $date_old_created;
                }


                $channel->date_update_videos = new \Zend_Db_Expr('NOW()');
                $channel->save();

            } catch (\Exception $e) {
                echo "Account: {$yt_account->getNmbr()}" .PHP_EOL;
                echo $e->getMessage() .PHP_EOL;
                echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;

                if (str_contains($e->getMessage(), 'quotaExceeded') ||
                    str_contains($e->getMessage(), 'Error 403 Permission denied')
                ) {
                    $yt_account->inactiveMethod('yt_account');

                } elseif (empty($e->getMessage()) ||
                    str_contains($e->getMessage(), 'Empty reply from server') ||
                    str_contains($e->getMessage(), 'SERVICE_UNAVAILABLE') ||
                    str_contains($e->getMessage(), '500 Internal Server Error') ||
                    str_contains($e->getMessage(), '500 Internal error encountered') ||
                    str_contains($e->getMessage(), '503 Service Unavailable') ||
                    str_contains($e->getMessage(), '503 The service is currently unavailable') ||
                    str_contains($e->getMessage(), '502 Bad Gateway')
                ) {
                    continue;

                } else {
                    $this->sendErrorMessage('Неизвестная ошибка при получении новых видео из канала', $e);
                }
            }
        }
    }


    /**
     * YouTube: Получение информации о видео
     * @return void
     * @throws Zend_Config_Exception
     */
    public function loadYtVideoInfo(): void {

        $yt          = new Sources\Video\YouTube();
        $yt_accounts = $yt->getAccounts( ['video_info'] );

        foreach ($yt_accounts as $yt_account) {
            if ( ! $yt_account->isActiveMethod('yt_account')) {
                echo "Метод неактивен у аккаунта {$yt_account->getNmbr()}" . PHP_EOL;
                continue;
            }

            $videos_id = $this->db->fetchPairs("
                SELECT svc.id,
                       svc.platform_id
                FROM mod_sources_videos_clips AS svc
                WHERE svc.type = 'yt'
                  AND svc.is_load_info_sw = 'N'
                  AND (SELECT 1
                       FROM mod_sources_videos AS sv
                       WHERE sv.id = svc.channel_id
                         AND sv.is_connect_sw = 'Y'
                       LIMIT 1) IS NOT NULL 
                LIMIT 50
            ");

            if (empty($videos_id)) {
                continue;
            }

            try {
                $videos = $yt_account->getVideosInfo(array_values($videos_id));

                if ( ! empty($videos)) {
                    $this->modSources->dataSourcesVideosRaw->saveContent('yt_videos_info', $videos, [
                        'count' => count($videos),
                    ]);

                    foreach ($videos as $video) {
                        if (($video_id = array_search($video['id'], $videos_id)) !== false) {
                            $video = $this->modSources->dataSourcesVideosClips->find($video_id)->current();
                            $video->is_load_info_sw = 'Y';
                            $video->save();
                        }
                    }


                    $this->apiMetrics->incPrometheus('core2_sources_yt_load', count($videos), [
                        'labels'   => ['action' => 'video_info'],
                        'job'      => 'core2',
                        'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                    ]);
                }

            } catch (\Exception $e) {
                echo "Account: {$yt_account->getNmbr()}" .PHP_EOL;
                echo $e->getMessage() .PHP_EOL;
                echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;

                if (str_contains($e->getMessage(), 'quotaExceeded') ||
                    str_contains($e->getMessage(), 'Error 403 Permission denied')
                ) {
                    $yt_account->inactiveMethod('yt_account');

                } elseif (empty($e->getMessage()) ||
                          str_contains($e->getMessage(), 'Empty reply from server') ||
                          str_contains($e->getMessage(), 'SERVICE_UNAVAILABLE') ||
                          str_contains($e->getMessage(), '500 Internal Server Error') ||
                          str_contains($e->getMessage(), '500 Internal error encountered') ||
                          str_contains($e->getMessage(), '503 Service Unavailable') ||
                          str_contains($e->getMessage(), '503 The service is currently unavailable') ||
                          str_contains($e->getMessage(), '502 Bad Gateway')
                ) {
                    continue;

                } else {
                    $this->sendErrorMessage('Неизвестная ошибка при получении полного описания о видео', $e);
                }
            }
        }
    }


    /**
     * YouTube: Получение трендов видео
     * @return void
     * @throws Zend_Config_Exception
     */
    public function loadYtVideosPopular(): void {

        $yt          = new Sources\Video\YouTube();
        $yt_accounts = $yt->getAccounts( ['video_popular'] );

        $config  = $this->getModuleConfig('sources');
        $regions = $config?->yt?->regions ? explode(',', $config->yt->regions) : [];
        $regions = array_map('trim', $regions);

        // Получить список видео без региона
        $regions[] = '';


        foreach ($regions as $region) {
            foreach ($yt_accounts as $yt_account) {
                try {
                    if ( ! $yt_account->isActiveMethod('yt_account')) {
                        echo "Метод неактивен у аккаунта {$yt_account->getNmbr()}" . PHP_EOL;
                        continue;
                    }

                    $videos = $yt_account->getVideosPopular($region);

                    if ( ! empty($videos)) {
                        $this->modSources->dataSourcesVideosRaw->saveContent('yt_videos_popular', $videos, [
                            'region' => $region,
                            'count'  => count($videos),
                        ]);


                        $this->apiMetrics->incPrometheus('core2_sources_yt_load', count($videos), [
                            'labels'   => ['action' => 'videos_popular'],
                            'job'      => 'core2',
                            'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                        ]);
                    }

                    break;

                } catch (\Exception $e) {
                    echo "Account: {$yt_account->getNmbr()}" .PHP_EOL;
                    echo $e->getMessage() .PHP_EOL;
                    echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;

                    if (str_contains($e->getMessage(), 'quotaExceeded') ||
                        str_contains($e->getMessage(), 'Error 403 Permission denied')
                    ) {
                        $yt_account->inactiveMethod('yt_account');

                    } elseif (
                        empty($e->getMessage()) ||
                        str_contains($e->getMessage(), 'Empty reply from server') ||
                        str_contains($e->getMessage(), 'SERVICE_UNAVAILABLE') ||
                        str_contains($e->getMessage(), '500 Internal Server Error') ||
                        str_contains($e->getMessage(), '500 Internal error encountered') ||
                        str_contains($e->getMessage(), '503 Service Unavailable') ||
                        str_contains($e->getMessage(), '503 The service is currently unavailable') ||
                        str_contains($e->getMessage(), '502 Bad Gateway')
                    ) {
                        continue;

                    } else {
                        $this->sendErrorMessage('Неизвестная ошибка при получении трендов видео', $e);
                    }
                }
            }
        }
    }


    /**
     * YouTube: Получение комментариев из видео
     * @return void
     * @throws Zend_Config_Exception
     */
    public function loadYtVideoComments(): void {

        $yt          = new Sources\Video\YouTube();
        $yt_accounts = $yt->getAccounts( ['video_comments'] );

        foreach ($yt_accounts as $yt_account) {
            if ( ! $yt_account->isActiveMethod('yt_account')) {
                echo "Метод неактивен у аккаунта {$yt_account->getNmbr()}" . PHP_EOL;
                continue;
            }


            $video_id = $this->db->fetchOne("
                SELECT svc.id
                FROM mod_sources_videos_clips AS svc
                WHERE svc.type = 'yt'
                  AND svc.is_load_comments_sw = 'N'
                  AND (DATE_ADD(svc.date_platform_created, INTERVAL 7 DAY) < NOW() OR 
                       DATE_ADD(svc.date_created, INTERVAL 7 DAY) < NOW())
                  AND (SELECT 1
                       FROM mod_sources_videos AS sv
                       WHERE sv.id = svc.channel_id
                         AND sv.is_connect_sw = 'Y'
                       LIMIT 1) IS NOT NULL 
                ORDER BY svc.viewed_count DESC
                LIMIT 1
            ");

            if (empty($video_id)) {
                continue;
            }

            try {
                $video          = $this->modSources->dataSourcesVideosClips->find($video_id)->current();
                $video_comments = $yt_account->getVideosComments($video->platform_id, $video->comments_page_token);

                if ( ! empty($video_comments['results'])) {
                    $this->modSources->dataSourcesVideosRaw->saveContent('yt_video_comments', $video_comments['results'], [
                        'count'    => count($video_comments['results']),
                        'video_id' => $video->platform_id,
                    ]);


                    $this->apiMetrics->incPrometheus('core2_sources_yt_load', count($video_comments['results']), [
                        'labels'   => ['action' => 'video_comments'],
                        'job'      => 'core2',
                        'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                    ]);
                }

                if ( ! empty($video_comments['info'])) {
                    if ( ! empty($video_comments['info']['nextPageToken'])) {
                        $video->comments_page_token = $video_comments['info']['nextPageToken'];
                    } else {
                        $video->comments_page_token = null;
                        $video->is_load_comments_sw = 'Y';
                    }
                }

                $video->save();

            } catch (\Exception $e) {
                echo "Account: {$yt_account->getNmbr()}" .PHP_EOL;
                echo "Video ID: {$video->id}" .PHP_EOL;
                echo $e->getMessage() .PHP_EOL;
                echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;

                if (str_contains($e->getMessage(), 'commentsDisabled') ||
                    str_contains($e->getMessage(), 'Error 404 The video identified')
                ) {
                    $video->is_load_comments_sw = 'Y';
                    $video->save();

                } elseif (str_contains($e->getMessage(), 'quotaExceeded') ||
                          str_contains($e->getMessage(), 'Error 403 Permission denied')
                ) {
                    $yt_account->inactiveMethod('yt_account');

                }  elseif (
                    empty($e->getMessage()) ||
                    str_contains($e->getMessage(), 'processingFailure') ||
                    str_contains($e->getMessage(), 'Empty reply from server') ||
                    str_contains($e->getMessage(), 'SERVICE_UNAVAILABLE') ||
                    str_contains($e->getMessage(), '500 Internal Server Error') ||
                    str_contains($e->getMessage(), '500 Internal error encountered') ||
                    str_contains($e->getMessage(), '503 Service Unavailable') ||
                    str_contains($e->getMessage(), '503 The service is currently unavailable') ||
                    str_contains($e->getMessage(), '502 Bad Gateway')
                ) {
                    continue;

                } else {
                    $this->sendErrorMessage('Неизвестная ошибка при получении комментариев к видео', $e);
                }
            }
        }
    }


    /**
     * YouTube: Получение субтитров из видео
     * @return void
     * @throws Zend_Config_Exception
     */
    public function loadYtVideoSubtitles(): void {

        $yt          = new Sources\Video\YouTube();
        $yt_accounts = $yt->getAccounts( ['video_subtitles'] );


        foreach ($yt_accounts as $yt_account) {

            $clips = $this->modSources->dataSourcesVideosClips->fetchAll(
                $this->modSources->dataSourcesVideosClips->select()
                    ->where("is_load_subtitles_sw = 'N'")
                    ->where("type = 'yt'")
                    ->where("date_load_deferred IS NULL OR date_load_deferred < NOW()")
                   // ->order("viewed_count DESC")
                    ->limit(5)
            );

            if (empty($clips)) {
                return;
            }

            $errors = [];

            foreach ($clips as $clip) {

                try {
                    $subtitles = $yt_account->getVideoSubtitles($clip->platform_id);

                    if ( ! empty($subtitles)) {
                        $this->modSources->dataSourcesVideosRaw->saveContent('yt_video_subtitles_2', $subtitles, [
                            'video_id' => $clip->platform_id,
                        ]);

                        $this->apiMetrics->incPrometheus('core2_sources_yt_load', 1, [
                            'labels'   => ['action' => 'video_subtitles'],
                            'job'      => 'core2',
                            'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                        ]);
                    }

                    $clip->is_load_subtitles_sw = 'Y';
                    $clip->save();

                } catch (\Exception $e) {
                    echo "Video ID: {$clip->id}" .PHP_EOL;
                    echo $e->getMessage() .PHP_EOL;
                    echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;

                    if (str_contains($e->getMessage(), 'quotaExceeded') ||
                        str_contains($e->getMessage(), 'Error 403 Permission denied')
                    ) {
                        $yt_account->inactiveMethod('yt_account');

                    } elseif (
                        empty($e->getMessage()) ||
                        str_contains($e->getMessage(), 'Empty reply from server') ||
                        str_contains($e->getMessage(), 'SERVICE_UNAVAILABLE') ||
                        str_contains($e->getMessage(), '500 Internal Server Error') ||
                        str_contains($e->getMessage(), '500 Internal error encountered') ||
                        str_contains($e->getMessage(), '503 Service Unavailable') ||
                        str_contains($e->getMessage(), '503 The service is currently unavailable') ||
                        str_contains($e->getMessage(), '502 Bad Gateway')

                    ) {
                        $clip->date_load_deferred = new \Zend_Db_Expr('DATE_ADD(NOW(), INTERVAL 1 HOUR)');
                        $clip->save();
                        continue;

                    } elseif ( ! str_contains($e->getMessage(), '404 Not Found') &&
                               ! str_contains($e->getMessage(), '403 Forbidden')
                    ) {
                        $errors[] = [
                            'clip_id' => $clip->id,
                            'message' => $e->getMessage(),
                            'trace'   => explode("\n", $e->getTraceAsString()),
                        ];
                    }
                }
            }

            if ( ! empty($errors)) {
                $this->sendErrorMessage('Неизвестная ошибка при получении субтитров к видео', $errors);
            }
        }
    }


    /**
     * YouTube: Получение картинок из видео
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException|Zend_Db_Table_Exception
     */
    public function loadYtVideoImages(): void {

        $video_files = $this->modSources->dataSourcesVideosClipsFiles->fetchAll(
            $this->modSources->dataSourcesVideosClipsFiles->select()
                ->where("is_load_sw = 'N'")
                ->where("meta_data IS NOT NULL")
                ->limit(150)
        );

        if (empty($video_files)) {
            return;
        }

        $client = new GuzzleHttp\Client();
        $model  = new Sources\Model();

        foreach ($video_files as $video_file) {

            $clip = $this->modSources->dataSourcesVideosClips->find($video_file->refid)->current();

            try {
                $meta_data = json_decode($video_file->meta_data, true);

                $image_url = null;

                if ( ! empty($meta_data['high']) && ! empty($meta_data['high']['url'])) {
                    $image_url = $meta_data['high']['url'];

                } elseif ( ! empty($meta_data['default']) && ! empty($meta_data['default']['url'])) {
                    $image_url = $meta_data['default']['url'];
                }

                if ( ! empty($image_url) && filter_var($image_url, FILTER_VALIDATE_URL)) {
                    $response = $client->request('GET', $image_url);

                    if ($response->getStatusCode() == 200) {
                        $file_content = $response->getBody()->getContents();
                        $hash         = md5($file_content);

                        $file_name = "yt-{$clip->platform_id}-{$hash}.jpg";
                        $file_path = $model->saveSourceFile('videos_clips_images', new \DateTime(), $file_name, $file_content);


                        $video_file->filename = $file_name;
                        $video_file->filesize = filesize($file_path);
                        $video_file->hash     = $hash;
                        $video_file->type     = 'image/jpg';
                        $video_file->fieldid  = 'thumb';


                        $this->apiMetrics->incPrometheus('core2_sources_yt_load', 1, [
                            'labels'   => ['action' => 'video_image'],
                            'job'      => 'core2',
                            'instance' => $_SERVER['SERVER_NAME'] ?? 'production',
                        ]);
                    }
                }

            } catch (\Exception $e) {
                echo "File ID: {$video_file->id}" .PHP_EOL;
                echo $e->getMessage() .PHP_EOL;
                echo $e->getTraceAsString() . PHP_EOL . PHP_EOL;
            }

            $video_file->is_load_sw = 'Y';
            $video_file->save();
        }
    }


    /**
     * YouTube: Обработка загруженных данных
     * @return void
     * @throws Zend_Exception
     * @throws Exception
     */
    public function parseYtContent(): void {

        $yt_parser = new Sources\Video\YtParser();

        $yt_parser->processChannelInfo(1000);
        $yt_parser->processChannelStats(1000);
        $yt_parser->processVideos(1000);
        $yt_parser->processVideosSubtitles(1000);
        $yt_parser->processVideosComments(1000);
    }


    /**
     * YouTube: Показ загруженных данных
     * @param int $content_id
     * @return void
     * @throws Exception
     * @no_cron
     */
    public function viewYtContent(int $content_id): void {

        $row = $this->modSources->dataSourcesVideosRaw->find($content_id)->current();

        if (empty($row)) {
            throw new \Exception('Данные с таким id не найдены');
        }

        $model           = new Model();
        $date_day     = new \DateTime($row->date_created);
        $file_content = $model->getSourceFile('videos', $date_day, $row->file_name);

        echo '<pre>';
        print_r($file_content['meta']);
        print_r(json_decode(gzuncompress(base64_decode($file_content['content'])), true));
        echo '</pre>';;
    }


    /**
     * Копирование данных из одной таблицы в другую (Сервисный метод)
     * @param string $table_from
     * @param string $table_to
     * @return void
     */
    public function copyTable(string $table_from = 'mod_sources_videos_users', string $table_to = 'mod_sources_videos_users2'): void {

        $table_from_quote = $this->db->quoteIdentifier($table_from, true);
        $table_to_quote   = $this->db->quoteIdentifier($table_to, true);
        $count_rows       = 100000;
        $success_insert   = 0;

        $max_id = $this->db->fetchOne("SELECT MAX(id) FROM {$table_from_quote}");


        for ($start_id = 1; $start_id <= $max_id; $start_id += $count_rows) {

            $end_id = $start_id + $count_rows;

            try {
                $stmt = $this->db->query("
                    insert ignore into {$table_to_quote} 
                    select * from {$table_from_quote} WHERE id >= ? and id <= ?
                ", [
                    $start_id,
                    $end_id
                ]);

                $success_insert += $stmt->rowCount();

                echo "Range id {$start_id} - {$end_id} / Copy rows - {$success_insert}\n";

            } catch (\Exception $e) {
                $this->db->closeConnection();
                echo "Error range id {$start_id} - {$end_id}: " . $e->getMessage() . "\n";
                sleep(5);

                // Если не удалось, то копируем построчно
                for ($id = $start_id; $id <= $end_id; $id++) {
                    if ($id >= 3483168 && $id <= 3483422) {
                        continue;
                    }
                    try {
                        $row = $this->db->fetchRow("SELECT * FROM {$table_from_quote} WHERE id = ?", $id);

                        if ($row) {
                            $success_insert += $this->insertIgnore($table_to, $row);
                        }

                    } catch (\Exception $e) {
                        $this->db->closeConnection();
                        echo "Error row id - {$id}: " . $e->getMessage() . "\n";
                        sleep(5);
                    }
                }
            }
        }

        echo "Copy rows - {$success_insert}";
    }


    /**
     * @param string $table
     * @param array  $bind
     * @return int
     * @throws Zend_Db_Adapter_Exception
     * @throws Zend_Db_Statement_Exception
     */
    private function insertIgnore(string $table, array $bind) {

        // extract and quote col names from the array keys
        $cols = [];
        $vals = [];
        $i    = 0;
        foreach ($bind as $col => $val) {
            $cols[] = $this->db->quoteIdentifier($col, true);
            if ($val instanceof Zend_Db_Expr) {
                $vals[] = $val->__toString();
                unset($bind[$col]);
            } else {
                if ($this->db->supportsParameters('positional')) {
                    $vals[] = '?';
                } else {
                    if ($this->db->supportsParameters('named')) {
                        unset($bind[$col]);
                        $bind[':col' . $i] = $val;
                        $vals[]            = ':col' . $i;
                        $i++;
                    } else {
                        /** @see Zend_Db_Adapter_Exception */
                        // require_once 'Zend/Db/Adapter/Exception.php';
                        throw new Zend_Db_Adapter_Exception(get_class($this) . " doesn't support positional or named binding");
                    }
                }
            }
        }

        // build the statement
        $sql = "INSERT IGNORE INTO "
               . $this->db->quoteIdentifier($table, true)
               . ' (' . implode(', ', $cols) . ') '
               . 'VALUES (' . implode(', ', $vals) . ')';

        // execute the statement and return the number of affected rows
        if ($this->db->supportsParameters('positional')) {
            $bind = array_values($bind);
        }

        $stmt   = $this->db->query($sql, $bind);
        $result = $stmt->rowCount();
        return $result;
    }
}
