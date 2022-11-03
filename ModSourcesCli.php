<?php
use Core2\Mod\Sources;

require_once DOC_ROOT . 'core2/inc/classes/Common.php';
require_once "classes/autoload.php";


/**
 * @property ModSourcesController $modSources
 * @property ModProxyController   $modProxy
 */
class ModSourcesCli extends Common {

    /**
     * Получение информации
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function loadSources() {

        $configs = (new Sources\Index\Model())->getConfigs();
        $test_sources = [

        ];

        if ($configs) {
            $transform = new Sources\Etl\Transform();
            $loader    = new Sources\Etl\Loader();

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


                                    $site = new Sources\Index\Site($section);

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
                                    }
                                    break;

                                case 'json': break;
                                case 'text': break;
                            }

                        } catch (\Exception $e) {
                            echo $e->getMessage() . PHP_EOL;
                        }
                    }
                }
            }
        }
    }


    /**
     * Обработка информации
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function parseSources() {

        $configs      = (new Sources\Index\Model())->getConfigs();
        $test_sources = [
            //'relax.by'
        ];

        if ($configs) {
            $loader = new Sources\Etl\Loader();

            $this->modSources->dataSourcesContentsRaw->refreshStatusRows();

            foreach ($configs as $name => $config) {

                if ( ! empty($test_sources) && ! in_array($name, $test_sources)) {
                    continue;
                }

                if ( ! $config?->source || ! $config->source?->title) {
                    continue;
                }

                $source = $this->modSources->dataSources->getRowByTitle($config->source->title);

                if (empty($source)) {
                    continue;
                }

                $pages_raw    = $this->modSources->dataSourcesContentsRaw->getRowsPendingBySourceId($source->id);
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

                                $site = new Sources\Index\Site($section);
                                $page = $site->parsePage($page_raw->url, gzuncompress($page_raw->content));


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
                                break;

                            case 'json': break;
                            case 'text': break;

                            default:
                                throw new \Exception("Неизвестный тип данных {$page_raw->content_type}");
                        }

                    } catch (\Exception $e) {
                        $page_raw->status = "error";
                        $page_raw->note   = $e->getMessage();
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


    public function zipContent() {

        $pages_id = $this->db->fetchCol("
            SELECT id
            FROM mod_sources_contents_raw
            WHERE is_zip_sw IS NULL
        ");


        foreach ($pages_id as $page_id) {

            $page = $this->modSources->dataSourcesContentsRaw->find($page_id)->current();
            $page->content   = gzcompress($page->content, 9);
            $page->is_zip_sw = 1;
            $page->save();
        }
    }
}
